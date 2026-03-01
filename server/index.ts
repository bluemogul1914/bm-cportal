import express, { type Request, Response, NextFunction } from "express";
import { registerRoutes } from "./routes";
import { serveStatic } from "./static";
import { createServer } from "http";
import { seed } from "./seed";
import { runMigrations } from "stripe-replit-sync";
import { getStripeSync } from "./stripeClient";
import { WebhookHandlers } from "./webhookHandlers";
import { execFile } from "child_process";
import { writeFile, unlink } from "fs/promises";
import { tmpdir } from "os";
import { join, resolve } from "path";
import { randomUUID } from "crypto";
import session from "express-session";
import pg from "pg";

const webhookPool = new pg.Pool({ connectionString: process.env.DATABASE_URL });

const app = express();
const httpServer = createServer(app);

declare module "http" {
  interface IncomingMessage {
    rawBody: unknown;
  }
}

async function initStripe() {
  const databaseUrl = process.env.DATABASE_URL;
  if (!databaseUrl) {
    console.warn("DATABASE_URL not set, skipping Stripe initialization");
    return;
  }

  try {
    console.log("Initializing Stripe schema...");
    await runMigrations({ databaseUrl, schema: "stripe" });
    console.log("Stripe schema ready");

    const stripeSync = await getStripeSync();

    const domain = process.env.REPLIT_DOMAINS?.split(",")[0];
    if (domain) {
      const webhookBaseUrl = `https://${domain}`;
      try {
        const { webhook } = await stripeSync.findOrCreateManagedWebhook(
          `${webhookBaseUrl}/api/stripe/webhook`
        );
        console.log(`Webhook configured: ${webhook.url}`);
      } catch (webhookErr: any) {
        console.warn("Webhook setup skipped:", webhookErr.message);
      }
    } else {
      console.warn("REPLIT_DOMAINS not set, skipping webhook setup");
    }

    stripeSync
      .syncBackfill()
      .then(() => console.log("Stripe data synced"))
      .catch((err: any) => console.error("Error syncing Stripe data:", err));
  } catch (error) {
    console.error("Failed to initialize Stripe:", error);
  }
}

app.post(
  "/api/stripe/webhook",
  express.raw({ type: "application/json" }),
  async (req, res) => {
    const signature = req.headers["stripe-signature"];
    if (!signature) {
      return res.status(400).json({ error: "Missing stripe-signature" });
    }

    try {
      const sig = Array.isArray(signature) ? signature[0] : signature;

      if (!Buffer.isBuffer(req.body)) {
        console.error("STRIPE WEBHOOK ERROR: req.body is not a Buffer.");
        return res.status(500).json({ error: "Webhook processing error" });
      }

      await WebhookHandlers.processWebhook(req.body as Buffer, sig);
      res.status(200).json({ received: true });
    } catch (error: any) {
      console.error("Webhook error:", error.message);
      res.status(400).json({ error: "Webhook processing error" });
    }
  }
);

app.use(
  express.json({
    verify: (req, _res, buf) => {
      req.rawBody = buf;
    },
  }),
);

app.use(express.urlencoded({ extended: false }));

app.use(
  session({
    secret: process.env.SESSION_SECRET || "bluemogul-portal-secret",
    resave: false,
    saveUninitialized: false,
    cookie: {
      maxAge: 24 * 60 * 60 * 1000,
      httpOnly: true,
    },
  })
);

export function log(message: string, source = "express") {
  const formattedTime = new Date().toLocaleTimeString("en-US", {
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
    hour12: true,
  });

  console.log(`${formattedTime} [${source}] ${message}`);
}

app.use((req, res, next) => {
  const start = Date.now();
  const path = req.path;
  let capturedJsonResponse: Record<string, any> | undefined = undefined;

  const originalResJson = res.json;
  res.json = function (bodyJson, ...args) {
    capturedJsonResponse = bodyJson;
    return originalResJson.apply(res, [bodyJson, ...args]);
  };

  res.on("finish", () => {
    const duration = Date.now() - start;
    if (path.startsWith("/api")) {
      let logLine = `${req.method} ${path} ${res.statusCode} in ${duration}ms`;
      if (capturedJsonResponse) {
        logLine += ` :: ${JSON.stringify(capturedJsonResponse)}`;
      }

      log(logLine);
    }
  });

  next();
});

const projectRoot = resolve(process.cwd());
app.use("/assets", express.static(join(projectRoot, "assets")));

const ALLOWED_PHP_FILES = ["index.php", "login-handler.php", "setup.php", "dashboard.php", "logout.php", "admin-dashboard.php", "admin-clients.php", "admin-ai-agents.php", "admin-automation.php", "admin-tickets.php", "admin-products.php", "admin-services.php", "admin-settings.php", "admin-client-detail.php", "admin-client-edit.php", "admin-invoices.php", "admin-invoice-add.php", "admin-invoice-detail.php", "admin-reports.php", "admin-network.php", "admin-knowledge.php", "tickets.php", "ticket-detail.php", "billing.php", "pay-invoice.php", "payment-success.php", "services.php", "products.php", "profile.php", "documents.php", "admin-ticket-detail.php", "help.php", "admin-itflow.php", "admin-uisp.php", "admin-voip.php", "admin-nextcloud.php", "admin-stripe.php", "settings.php", "admin-audit.php", "admin-roles.php", "admin-projects.php", "admin-project-detail.php", "projects.php"];

function buildSessionPhpCode(req: Request): string {
  const sess = (req.session as any)?.portalUser;
  if (!sess) return "";
  const escaped = JSON.stringify(sess).replace(/'/g, "\\'");
  return `
$_sessionData = json_decode('${escaped}', true);
if ($_sessionData) {
    $_SESSION['user_id'] = $_sessionData['user_id'];
    $_SESSION['user_email'] = $_sessionData['user_email'];
    $_SESSION['user_name'] = $_sessionData['user_name'];
    $_SESSION['is_admin'] = $_sessionData['is_admin'];
    $_SESSION['user_role'] = $_sessionData['user_role'] ?? 'user';
    $_SESSION['logged_in_at'] = $_sessionData['logged_in_at'];
    $_SESSION['last_login'] = $_sessionData['last_login'];
    $_SESSION['last_activity'] = $_sessionData['last_activity'];
}
`;
}

function handlePhpResponse(stdout: string, res: Response) {
  const redirectMatch = stdout.match(/^__REDIRECT__:(.+)$/m);
  if (redirectMatch) {
    let location = redirectMatch[1].trim();
    if (!location.startsWith("http") && !location.startsWith("/")) {
      location = "/portal/" + location;
    }
    return res.redirect(302, location);
  }
  res.setHeader("Content-Type", "text/html; charset=utf-8");
  res.send(stdout);
}

function executePhpFile(filePath: string, req: Request, res: Response) {
  const sessionCode = buildSessionPhpCode(req);
  const queryParams = Object.entries(req.query || {}).map(([k, v]) => `$_GET['${k.replace(/'/g, "\\'")}'] = '${String(v).replace(/'/g, "\\'")}';`).join("\n");
  const phpCode = `<?php
error_reporting(E_ERROR | E_PARSE);
session_start();
${sessionCode}
${queryParams}
require '${filePath.replace(/'/g, "\\'")}';
`;
  const tmpFile = join(tmpdir(), `portal_${randomUUID()}.php`);
  writeFile(tmpFile, phpCode).then(() => {
    execFile(
      "php",
      [tmpFile],
      {
        timeout: 15000,
        maxBuffer: 2 * 1024 * 1024,
        cwd: projectRoot,
        env: { ...process.env },
      },
      (error, stdout, stderr) => {
        unlink(tmpFile).catch(() => {});
        if (error) {
          console.error(`PHP execution error:`, stderr || error.message);
          return res.status(500).send("Server error");
        }
        handlePhpResponse(stdout, res);
      }
    );
  });
}

app.get("/portal", (req, res) => {
  executePhpFile(join(projectRoot, "index.php"), req, res);
});

app.get("/portal/:file", (req, res) => {
  const file = req.params.file;
  const phpFile = file.endsWith(".php") ? file : `${file}.php`;

  if (phpFile === "logout.php") {
    if ((req.session as any)?.portalUser) {
      delete (req.session as any).portalUser;
    }
    req.session.destroy(() => {});
    return res.redirect("/portal/index.php?logout=1");
  }

  if (!ALLOWED_PHP_FILES.includes(phpFile)) {
    return res.status(404).send("Not found");
  }

  executePhpFile(join(projectRoot, phpFile), req, res);
});

app.post("/portal/:file", (req, res) => {
  const file = req.params.file;
  const phpFile = file.endsWith(".php") ? file : `${file}.php`;

  if (phpFile === "login-handler.php") {
    return handleLogin(req, res);
  }

  if (!ALLOWED_PHP_FILES.includes(phpFile)) {
    return res.status(404).send("Not found");
  }

  const filePath = join(projectRoot, phpFile);
  const sessionCode = buildSessionPhpCode(req);

  const formParts: string[] = [];
  for (const [key, value] of Object.entries(req.body || {})) {
    formParts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  }
  const postData = formParts.join("&");

  const phpCode = `<?php
error_reporting(E_ERROR | E_PARSE);
session_start();
${sessionCode}
$_SERVER['REQUEST_METHOD'] = 'POST';
parse_str('${postData.replace(/'/g, "\\'")}', $_POST);
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_GET = [];
${Object.entries(req.query || {}).map(([k, v]) => `$_GET['${k}'] = '${String(v).replace(/'/g, "\\'")}';`).join("\n")}
require '${filePath.replace(/'/g, "\\'")}';
`;

  const tmpFile = join(tmpdir(), `portal_${randomUUID()}.php`);
  writeFile(tmpFile, phpCode).then(() => {
    execFile(
      "php",
      [tmpFile],
      {
        timeout: 15000,
        maxBuffer: 2 * 1024 * 1024,
        cwd: projectRoot,
        env: { ...process.env },
      },
      (error, stdout, stderr) => {
        unlink(tmpFile).catch(() => {});
        if (error) {
          console.error(`PHP POST error (${phpFile}):`, stderr || error.message);
          return res.status(500).send("Server error");
        }
        try {
          const json = JSON.parse(stdout);
          res.json(json);
        } catch {
          handlePhpResponse(stdout, res);
        }
      }
    );
  });
});

function handleLogin(req: Request, res: Response) {
  const filePath = join(projectRoot, "login-handler.php");

  const formParts: string[] = [];
  for (const [key, value] of Object.entries(req.body || {})) {
    formParts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  }
  const postData = formParts.join("&");

  const phpCode = `<?php
session_start();
$_SERVER['REQUEST_METHOD'] = 'POST';
parse_str('${postData.replace(/'/g, "\\'")}', $_POST);
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
require '${filePath.replace(/'/g, "\\'")}';
`;

  const tmpFile = join(tmpdir(), `portal_${randomUUID()}.php`);
  writeFile(tmpFile, phpCode).then(() => {
    execFile(
      "php",
      [tmpFile],
      {
        timeout: 15000,
        maxBuffer: 2 * 1024 * 1024,
        cwd: projectRoot,
        env: { ...process.env },
      },
      (error, stdout, stderr) => {
        unlink(tmpFile).catch(() => {});
        if (error) {
          console.error("PHP login handler error:", stderr || error.message);
          return res.status(500).json({ success: false, message: "Server error" });
        }
        try {
          const json = JSON.parse(stdout);
          if (json.success && json.user) {
            (req.session as any).portalUser = {
              user_id: json.user.id,
              user_email: json.user.email,
              user_name: json.user.name,
              is_admin: json.user.is_admin,
              user_role: json.user.role || "user",
              logged_in_at: Math.floor(Date.now() / 1000),
              last_login: new Date().toISOString(),
              last_activity: Math.floor(Date.now() / 1000),
            };
            req.session.save(() => {
              res.json(json);
            });
          } else {
            res.json(json);
          }
        } catch {
          res.setHeader("Content-Type", "text/html; charset=utf-8");
          res.send(stdout);
        }
      }
    );
  });
}

app.use("/uploads", express.static(join(projectRoot, "uploads")));

function validateWebhookAuth(req: Request, res: Response): boolean {
  const token = req.headers["x-webhook-token"] || req.headers["authorization"]?.replace("Bearer ", "");
  const expectedToken = process.env.SESSION_SECRET || "";
  if (!token || token !== expectedToken) {
    res.status(401).json({ error: "Unauthorized: invalid or missing webhook token" });
    return false;
  }
  return true;
}

// ═══════════════════════════════════════════════════════════════
// AI Agent Army — REST API
// ═══════════════════════════════════════════════════════════════

function parsePgArray(val: string | null): string[] {
  if (!val || val === '{}') return [];
  return val.replace(/^\{|\}$/g, '').split(',').map(v => v.replace(/^"|"$/g, '').trim()).filter(Boolean);
}

app.get("/api/agents", async (_req, res) => {
  try {
    const result = await webhookPool.query(`
      SELECT ac.*, COALESCE(am.runs_total, 0) AS runs_total, COALESCE(am.runs_today, 0) AS runs_today,
        COALESCE(am.errors_total, 0) AS errors_total, COALESCE(am.saves_week_hrs, ac.time_saved_hrs::DECIMAL) AS saves_week_hrs,
        COALESCE(am.online, FALSE) AS online, COALESCE(am.last_status, 'idle') AS last_status, am.last_run_at
      FROM agent_config ac LEFT JOIN agent_metrics am ON am.agent_key = ac.agent_key
      WHERE ac.is_enabled = TRUE ORDER BY ac.id ASC
    `);
    const agents = result.rows.map(r => ({ ...r, tools: parsePgArray(r.tools) }));
    res.json({ agents, count: agents.length });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

app.get("/api/agents/dashboard", async (_req, res) => {
  try {
    const totals = (await webhookPool.query(`
      SELECT COUNT(*) AS total_agents, SUM(CASE WHEN am.online THEN 1 ELSE 0 END) AS online_count,
        SUM(ac.time_saved_hrs) AS total_hrs_saved, SUM(COALESCE(am.runs_total, 0)) AS total_runs,
        SUM(CASE WHEN am.last_status = 'error' THEN 1 ELSE 0 END) AS error_count
      FROM agent_config ac LEFT JOIN agent_metrics am ON am.agent_key = ac.agent_key WHERE ac.is_enabled = TRUE
    `)).rows[0];
    const revenue = (await webhookPool.query(
      "SELECT SUM(revenue_monthly) AS total FROM agent_config WHERE is_enabled = TRUE AND agent_key != 'orchestrator'"
    )).rows[0];
    const rate = (await webhookPool.query(
      "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successes FROM (SELECT status FROM agent_logs ORDER BY created_at DESC LIMIT 500) t"
    )).rows[0];
    const successRate = rate.total > 0 ? Math.round((rate.successes / rate.total) * 100) : 100;
    const monthly = parseFloat(revenue.total || '15800');
    res.json({
      total_agents: parseInt(totals.total_agents), online_count: parseInt(totals.online_count || '0'),
      total_hrs_saved: parseInt(totals.total_hrs_saved || '0'), total_runs: parseInt(totals.total_runs || '0'),
      success_rate: successRate, monthly_revenue: monthly, annual_revenue: monthly * 12,
      fte_equivalent: Math.round(parseInt(totals.total_hrs_saved || '0') / 40 * 10) / 10
    });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

app.get("/api/agents/activity", async (req, res) => {
  try {
    const limit = Math.min(parseInt(String(req.query.limit || '20')), 100);
    const result = await webhookPool.query(`
      SELECT al.id, al.agent_key, al.action, al.status, al.message, al.created_at, ac.agent_name, ac.icon
      FROM agent_logs al LEFT JOIN agent_config ac ON ac.agent_key = al.agent_key
      ORDER BY al.created_at DESC LIMIT $1
    `, [limit]);
    res.json({ activity: result.rows });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

app.get("/api/agents/roi", async (_req, res) => {
  try {
    const agents = (await webhookPool.query(
      "SELECT agent_key, agent_name, icon, time_saved_hrs, revenue_label FROM agent_config WHERE is_enabled = TRUE ORDER BY time_saved_hrs DESC"
    )).rows;
    const totalHrs = agents.reduce((s, a) => s + (parseInt(a.time_saved_hrs) || 0), 0);
    res.json({
      agents, total_hrs_week: totalHrs, annual_value: 189600, monthly_enabled: 15800,
      fte_equivalent: Math.round(totalHrs / 40 * 10) / 10, salary_lo: 135000, salary_hi: 180000,
      infra_stack: [
        { tool: 'N8N (Coolify)', function: 'Workflow automation', your_cost: '$0', saas_alt: 'Zapier', saas_cost: '$49-599/mo' },
        { tool: 'AnythingLLM', function: 'AI knowledge base', your_cost: '$0', saas_alt: 'ChatGPT API', saas_cost: '$20-200/mo' },
        { tool: 'Ollama', function: 'Local LLM inference', your_cost: '$0', saas_alt: 'OpenAI API', saas_cost: '$50-500/mo' },
        { tool: 'Flowise (Coolify)', function: 'AI agent builder', your_cost: '$0', saas_alt: 'Relevance AI', saas_cost: '$19-199/mo' },
        { tool: 'ITarian', function: 'RMM + patching + AV', your_cost: '$0', saas_alt: 'NinjaOne', saas_cost: '$3-7/device/mo' },
        { tool: 'ITFlow (Coolify)', function: 'PSA + ticketing', your_cost: '$0', saas_alt: 'ConnectWise', saas_cost: '$100-500/mo' },
        { tool: 'HubSpot CRM', function: 'Sales pipeline', your_cost: '$0 (free)', saas_alt: 'Salesforce', saas_cost: '$25-300/user' },
        { tool: 'Nextcloud (Vultr)', function: 'Private cloud', your_cost: '$6-24/inst', saas_alt: 'Google Workspace', saas_cost: '$6-18/user' },
        { tool: 'Uptime-Kuma', function: 'Monitoring', your_cost: '$0', saas_alt: 'Pingdom', saas_cost: '$10-100/mo' },
        { tool: 'Matrix (Coolify)', function: 'Team messaging', your_cost: '$0', saas_alt: 'Slack', saas_cost: '$7.25/user/mo' },
      ]
    });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

app.get("/api/agents/:key", async (req, res) => {
  try {
    const { key } = req.params;
    const agent = (await webhookPool.query(`
      SELECT ac.*, am.runs_total, am.runs_today, am.errors_total, am.saves_week_hrs, am.online, am.last_status, am.last_run_at
      FROM agent_config ac LEFT JOIN agent_metrics am ON am.agent_key = ac.agent_key WHERE ac.agent_key = $1
    `, [key])).rows[0];
    if (!agent) return res.status(404).json({ error: 'Agent not found' });
    agent.tools = parsePgArray(agent.tools);
    const logs = (await webhookPool.query(
      "SELECT id, action, status, message, execution_ms, created_at FROM agent_logs WHERE agent_key = $1 ORDER BY created_at DESC LIMIT 20", [key]
    )).rows;
    res.json({ ...agent, recent_logs: logs });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

app.post("/api/agents/:key/run", express.json(), async (req, res) => {
  try {
    const { key } = req.params;
    const agent = (await webhookPool.query(
      "SELECT * FROM agent_config WHERE agent_key = $1 AND is_enabled = TRUE", [key]
    )).rows[0];
    if (!agent) return res.status(404).json({ error: 'Agent not found or disabled' });

    let webhookResult = { success: true, message: 'No webhook configured — logged only' };
    if (agent.n8n_webhook_url) {
      try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        const resp = await fetch(agent.n8n_webhook_url, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ agent_key: key, agent_name: agent.agent_name, timestamp: new Date().toISOString(), source: 'blue_mogul_portal', ...req.body }),
          signal: controller.signal
        });
        clearTimeout(timeout);
        webhookResult = { success: resp.ok, message: resp.ok ? 'Webhook delivered' : `HTTP ${resp.status}` };
      } catch (e: any) { webhookResult = { success: false, message: e.message }; }
    }

    await webhookPool.query(
      "INSERT INTO agent_logs (agent_key, action, status, message, metadata) VALUES ($1, 'Manual trigger', $2, $3, $4)",
      [key, webhookResult.success ? 'success' : 'error', webhookResult.message, JSON.stringify({ triggered_by: 'dashboard' })]
    );
    await webhookPool.query(`
      INSERT INTO agent_metrics (agent_key, runs_total, runs_today, errors_total, last_status, last_run_at, online, updated_at)
      VALUES ($1, 1, 1, $2, $3, NOW(), TRUE, NOW())
      ON CONFLICT (agent_key) DO UPDATE SET runs_total = agent_metrics.runs_total + 1, runs_today = agent_metrics.runs_today + 1,
        errors_total = agent_metrics.errors_total + (CASE WHEN $3 = 'error' THEN 1 ELSE 0 END),
        last_status = $3, last_run_at = NOW(), online = TRUE, updated_at = NOW()
    `, [key, webhookResult.success ? 0 : 1, webhookResult.success ? 'success' : 'error']);

    res.json({ success: webhookResult.success, agent_key: key, agent_name: agent.agent_name, webhook: agent.n8n_webhook_url ? 'sent' : 'no_webhook_configured', message: webhookResult.message });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

app.post("/api/agents/run-all", express.json(), async (req, res) => {
  try {
    const agents = (await webhookPool.query("SELECT * FROM agent_config WHERE is_enabled = TRUE ORDER BY id ASC")).rows;
    const results: Record<string, boolean> = {};
    for (const agent of agents) {
      let success = true;
      if (agent.n8n_webhook_url) {
        try {
          const controller = new AbortController();
          const timeout = setTimeout(() => controller.abort(), 10000);
          const resp = await fetch(agent.n8n_webhook_url, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ agent_key: agent.agent_key, agent_name: agent.agent_name, triggered_by: 'orchestrator', timestamp: new Date().toISOString() }),
            signal: controller.signal
          });
          clearTimeout(timeout);
          success = resp.ok;
        } catch { success = false; }
      }
      results[agent.agent_key] = success;
      await webhookPool.query(
        "INSERT INTO agent_logs (agent_key, action, status, message) VALUES ($1, 'Orchestrator trigger', $2, $3)",
        [agent.agent_key, success ? 'success' : 'error', success ? 'Triggered by orchestrator' : 'Failed']
      );
      await webhookPool.query(`
        INSERT INTO agent_metrics (agent_key, runs_total, runs_today, last_status, last_run_at, online, updated_at)
        VALUES ($1, 1, 1, $2, NOW(), TRUE, NOW())
        ON CONFLICT (agent_key) DO UPDATE SET runs_total = agent_metrics.runs_total + 1, runs_today = agent_metrics.runs_today + 1,
          last_status = $2, last_run_at = NOW(), online = TRUE, updated_at = NOW()
      `, [agent.agent_key, success ? 'success' : 'error']);
    }
    res.json({ success: true, triggered: agents.length, results });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

app.post("/api/agents/update-webhook", express.json(), async (req, res) => {
  try {
    const { agent_key, webhook_url } = req.body;
    if (!agent_key || !webhook_url) return res.status(400).json({ error: 'agent_key and webhook_url required' });
    await webhookPool.query("UPDATE agent_config SET n8n_webhook_url = $1, updated_at = NOW() WHERE agent_key = $2", [webhook_url, agent_key]);
    res.json({ success: true, agent_key, webhook_url });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

app.post("/api/cron/daily-reset", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const snapshot = (await webhookPool.query(
      "SELECT SUM(runs_today) AS total_runs, SUM(errors_total) AS total_errors, COUNT(*) AS agents_active FROM agent_metrics WHERE online = TRUE"
    )).rows[0];
    await webhookPool.query("UPDATE agent_metrics SET runs_today = 0, updated_at = NOW()");
    res.json({ success: true, reset_at: new Date().toISOString(), yesterday: snapshot });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

// ═══════════════════════════════════════════════════════════════
// Project Management API (COMMANDER agent webhooks)
// ═══════════════════════════════════════════════════════════════

app.post("/api/webhook/create-project", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { agent_key, client_id, name, description, project_type, priority, start_date, due_date, assigned_to, tasks } = req.body;
    if (!name) return res.status(400).json({ error: "name is required" });
    const result = await webhookPool.query(
      `INSERT INTO projects (client_id, name, description, project_type, priority, assigned_to, start_date, due_date, status, created_by)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, 'planning', NULL) RETURNING id`,
      [client_id || null, name, description || null, project_type || 'general', priority || 'medium', assigned_to || null, start_date || null, due_date || null]
    );
    const projectId = result.rows[0].id;
    if (tasks && Array.isArray(tasks)) {
      for (let i = 0; i < tasks.length; i++) {
        const t = tasks[i];
        await webhookPool.query(
          "INSERT INTO project_tasks (project_id, title, description, priority, assigned_to, due_date, sort_order) VALUES ($1, $2, $3, $4, $5, $6, $7)",
          [projectId, t.title, t.description || null, t.priority || 'medium', t.assigned_to || null, t.due_date || null, i + 1]
        );
      }
    }
    if (agent_key) {
      await webhookPool.query(
        "INSERT INTO agent_logs (agent_key, action, status, message, metadata) VALUES ($1, 'Create project', 'success', $2, $3)",
        [agent_key, `Created project: ${name}`, JSON.stringify({ project_id: projectId, client_id, tasks_count: tasks?.length || 0 })]
      );
      await webhookPool.query(`
        INSERT INTO agent_metrics (agent_key, runs_total, runs_today, last_status, last_run_at, online, updated_at)
        VALUES ($1, 1, 1, 'success', NOW(), TRUE, NOW())
        ON CONFLICT (agent_key) DO UPDATE SET runs_total = agent_metrics.runs_total + 1, runs_today = agent_metrics.runs_today + 1,
          last_status = 'success', last_run_at = NOW(), online = TRUE, updated_at = NOW()
      `, [agent_key]);
    }
    res.json({ success: true, project_id: projectId, name, tasks_created: tasks?.length || 0 });
  } catch (err: any) {
    console.error("Webhook create-project error:", err);
    res.status(500).json({ error: err.message });
  }
});

app.post("/api/webhook/update-project", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { project_id, agent_key, status, progress, assigned_to, add_tasks, add_note } = req.body;
    if (!project_id) return res.status(400).json({ error: "project_id is required" });
    const updates: string[] = [];
    const params: any[] = [];
    let idx = 1;
    if (status) { updates.push(`status = $${idx++}`); params.push(status); if (status === 'completed') { updates.push(`completed_at = NOW()`); } }
    if (progress !== undefined) { updates.push(`progress = $${idx++}`); params.push(progress); }
    if (assigned_to) { updates.push(`assigned_to = $${idx++}`); params.push(assigned_to); }
    if (updates.length > 0) {
      updates.push('updated_at = NOW()');
      params.push(project_id);
      await webhookPool.query(`UPDATE projects SET ${updates.join(', ')} WHERE id = $${idx}`, params);
    }
    if (add_tasks && Array.isArray(add_tasks)) {
      for (const t of add_tasks) {
        await webhookPool.query(
          "INSERT INTO project_tasks (project_id, title, description, priority, assigned_to, due_date) VALUES ($1, $2, $3, $4, $5, $6)",
          [project_id, t.title, t.description || null, t.priority || 'medium', t.assigned_to || null, t.due_date || null]
        );
      }
    }
    if (add_note) {
      await webhookPool.query("INSERT INTO project_notes (project_id, note) VALUES ($1, $2)", [project_id, add_note]);
    }
    if (agent_key) {
      await webhookPool.query(
        "INSERT INTO agent_logs (agent_key, action, status, message, metadata) VALUES ($1, 'Update project', 'success', $2, $3)",
        [agent_key, `Updated project #${project_id}`, JSON.stringify(req.body)]
      );
    }
    res.json({ success: true, project_id });
  } catch (err: any) {
    console.error("Webhook update-project error:", err);
    res.status(500).json({ error: err.message });
  }
});

app.get("/api/projects", async (_req, res) => {
  try {
    const result = await webhookPool.query(`
      SELECT p.*, c.name as client_name, c.company as client_company,
        (SELECT COUNT(*) FROM project_tasks pt WHERE pt.project_id = p.id) as task_count,
        (SELECT COUNT(*) FROM project_tasks pt WHERE pt.project_id = p.id AND pt.status = 'done') as tasks_done
      FROM projects p LEFT JOIN clients c ON p.client_id = c.id
      ORDER BY p.updated_at DESC LIMIT 100
    `);
    res.json({ projects: result.rows, count: result.rows.length });
  } catch (err: any) { res.status(500).json({ error: err.message }); }
});

// ═══════════════════════════════════════════════════════════════
// N8N Webhook Endpoints (enhanced with agent_key + metrics)
// ═══════════════════════════════════════════════════════════════

app.post("/api/webhook/agent-log", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { agent_key, agent_name, action, status, message, execution_ms, metadata } = req.body;
    const key = agent_key || agent_name;
    if (!key || !action || !status) {
      return res.status(400).json({ error: "agent_key, action, and status are required" });
    }
    await webhookPool.query(
      "INSERT INTO agent_logs (agent_key, agent_name, action, status, message, execution_ms, metadata, created_at) VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())",
      [agent_key || null, agent_name || agent_key, action, status, message || null, execution_ms || null, metadata ? JSON.stringify(metadata) : '{}']
    );
    await webhookPool.query(`
      INSERT INTO agent_metrics (agent_key, runs_total, runs_today, errors_total, last_status, last_run_at, online, updated_at)
      VALUES ($1, 1, 1, $2, $3, NOW(), TRUE, NOW())
      ON CONFLICT (agent_key) DO UPDATE SET runs_total = agent_metrics.runs_total + 1, runs_today = agent_metrics.runs_today + 1,
        errors_total = agent_metrics.errors_total + (CASE WHEN $3 = 'error' THEN 1 ELSE 0 END),
        last_status = $3, last_run_at = NOW(), online = TRUE, updated_at = NOW()
    `, [key, status === 'error' ? 1 : 0, status]);
    const agentRow = (await webhookPool.query("SELECT agent_name FROM agent_config WHERE agent_key = $1", [key])).rows[0];
    res.json({ success: true, logged: true, agent_key: key, agent_name: agentRow?.agent_name || key, status, timestamp: new Date().toISOString() });
  } catch (err: any) {
    console.error("Webhook agent-log error:", err);
    res.status(500).json({ error: err.message });
  }
});

app.post("/api/webhook/create-ticket", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { client_id, agent_key, subject, description, priority, source, client_name, category, device_name } = req.body;
    if (!subject) {
      return res.status(400).json({ error: "subject is required" });
    }
    const result = await webhookPool.query(
      "INSERT INTO tickets (client_id, subject, description, priority, source, status) VALUES ($1, $2, $3, $4, $5, 'open') RETURNING id",
      [client_id || null, subject, description || null, priority || 'medium', source || agent_key || 'agent']
    );
    if (agent_key) {
      await webhookPool.query(
        "INSERT INTO agent_logs (agent_key, action, status, message, metadata) VALUES ($1, 'Create ticket', 'success', $2, $3)",
        [agent_key, `Created ticket: ${subject}`, JSON.stringify({ client: client_name, priority, device: device_name, category })]
      );
      await webhookPool.query(`
        INSERT INTO agent_metrics (agent_key, runs_total, runs_today, last_status, last_run_at, online, updated_at)
        VALUES ($1, 1, 1, 'success', NOW(), TRUE, NOW())
        ON CONFLICT (agent_key) DO UPDATE SET runs_total = agent_metrics.runs_total + 1, runs_today = agent_metrics.runs_today + 1,
          last_status = 'success', last_run_at = NOW(), online = TRUE, updated_at = NOW()
      `, [agent_key]);
    }
    res.json({ success: true, ticket_id: result.rows[0].id, ticket_created: true });
  } catch (err: any) {
    console.error("Webhook create-ticket error:", err);
    res.status(500).json({ error: err.message });
  }
});

app.post("/api/webhook/update-device", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { hostname, client_id, status, ip_address, os_name, os_version, cpu, ram_gb, disk_gb, itarian_agent_id } = req.body;
    if (!hostname) {
      return res.status(400).json({ error: "hostname is required" });
    }
    await webhookPool.query(
      `INSERT INTO network_devices (client_id, hostname, device_type, ip_address, os_name, os_version, cpu, ram_gb, disk_gb, status, itarian_agent_id, last_seen, updated_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, NOW(), NOW())
       ON CONFLICT (client_id, hostname) DO UPDATE SET
         status = COALESCE(EXCLUDED.status, network_devices.status),
         ip_address = COALESCE(EXCLUDED.ip_address, network_devices.ip_address),
         os_name = COALESCE(EXCLUDED.os_name, network_devices.os_name),
         os_version = COALESCE(EXCLUDED.os_version, network_devices.os_version),
         cpu = COALESCE(EXCLUDED.cpu, network_devices.cpu),
         ram_gb = COALESCE(EXCLUDED.ram_gb, network_devices.ram_gb),
         disk_gb = COALESCE(EXCLUDED.disk_gb, network_devices.disk_gb),
         itarian_agent_id = COALESCE(EXCLUDED.itarian_agent_id, network_devices.itarian_agent_id),
         last_seen = NOW(),
         updated_at = NOW()`,
      [client_id || null, hostname, req.body.device_type || 'Workstation', ip_address || null, os_name || null, os_version || null, cpu || null, ram_gb || null, disk_gb || null, status || 'online', itarian_agent_id || null]
    );
    res.json({ success: true });
  } catch (err: any) {
    console.error("Webhook update-device error:", err);
    res.status(500).json({ error: err.message });
  }
});

app.post("/api/webhook/notify", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { user_id, title, message, type, entity_type, entity_id } = req.body;
    if (!title || !message) {
      return res.status(400).json({ error: "title and message are required" });
    }
    await webhookPool.query(
      "INSERT INTO notifications (user_id, title, message, type, entity_type, entity_id) VALUES ($1, $2, $3, $4, $5, $6)",
      [user_id || null, title, message, type || 'info', entity_type || null, entity_id || null]
    );
    res.json({ success: true });
  } catch (err: any) {
    console.error("Webhook notify error:", err);
    res.status(500).json({ error: err.message });
  }
});

app.get("/api/webhook/health", (_req, res) => {
  res.json({
    status: "ok",
    portal: "Blue Mogul Client Portal",
    endpoints: [
      "POST /api/webhook/agent-log",
      "POST /api/webhook/create-ticket",
      "POST /api/webhook/update-device",
      "POST /api/webhook/notify",
    ],
    timestamp: new Date().toISOString(),
  });
});

app.get("/portal/logout.php", (req, res) => {
  req.session.destroy(() => {
    res.redirect("/portal");
  });
});

(async () => {
  await seed().catch((err) => console.error("Seed failed:", err));
  await initStripe();
  await registerRoutes(httpServer, app);

  app.use((err: any, _req: Request, res: Response, next: NextFunction) => {
    const status = err.status || err.statusCode || 500;
    const message = err.message || "Internal Server Error";

    console.error("Internal Server Error:", err);

    if (res.headersSent) {
      return next(err);
    }

    return res.status(status).json({ message });
  });

  if (process.env.NODE_ENV === "production") {
    serveStatic(app);
  } else {
    const { setupVite } = await import("./vite");
    await setupVite(httpServer, app);
  }

  const port = parseInt(process.env.PORT || "5000", 10);
  httpServer.listen(
    {
      port,
      host: "0.0.0.0",
      reusePort: true,
    },
    () => {
      log(`serving on port ${port}`);
    },
  );
})();
