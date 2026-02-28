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

const ALLOWED_PHP_FILES = ["index.php", "login-handler.php", "setup.php", "dashboard.php", "logout.php", "admin-dashboard.php", "admin-clients.php", "admin-ai-agents.php", "admin-automation.php", "admin-tickets.php", "admin-products.php", "admin-services.php", "admin-settings.php", "admin-client-detail.php", "admin-client-edit.php", "admin-invoices.php", "admin-invoice-add.php", "admin-invoice-detail.php", "admin-reports.php", "admin-network.php", "admin-knowledge.php", "tickets.php", "ticket-detail.php", "billing.php", "pay-invoice.php", "payment-success.php", "services.php", "products.php", "profile.php", "documents.php", "admin-ticket-detail.php", "help.php", "admin-itflow.php", "admin-uisp.php", "admin-voip.php", "admin-nextcloud.php", "admin-stripe.php", "settings.php", "admin-audit.php", "admin-roles.php"];

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

function executePhpFile(filePath: string, req: Request, res: Response) {
  const sessionCode = buildSessionPhpCode(req);
  const queryParams = Object.entries(req.query || {}).map(([k, v]) => `$_GET['${k.replace(/'/g, "\\'")}'] = '${String(v).replace(/'/g, "\\'")}';`).join("\n");
  const phpCode = `<?php
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
        res.setHeader("Content-Type", "text/html; charset=utf-8");
        res.send(stdout);
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
          res.setHeader("Content-Type", "text/html; charset=utf-8");
          res.send(stdout);
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

app.post("/api/webhook/agent-log", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { agent_name, action, status, message, details, execution_time } = req.body;
    if (!agent_name || !action || !status) {
      return res.status(400).json({ error: "agent_name, action, and status are required" });
    }
    await webhookPool.query(
      "INSERT INTO agent_logs (agent_name, action, status, message, details, execution_time, executed_at) VALUES ($1, $2, $3, $4, $5, $6, NOW())",
      [agent_name, action, status, message || null, details ? JSON.stringify(details) : null, execution_time || null]
    );
    res.json({ success: true });
  } catch (err: any) {
    console.error("Webhook agent-log error:", err);
    res.status(500).json({ error: err.message });
  }
});

app.post("/api/webhook/create-ticket", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { client_id, subject, description, priority, source } = req.body;
    if (!subject) {
      return res.status(400).json({ error: "subject is required" });
    }
    const result = await webhookPool.query(
      "INSERT INTO tickets (client_id, subject, description, priority, source, status) VALUES ($1, $2, $3, $4, $5, 'open') RETURNING id",
      [client_id || null, subject, description || null, priority || 'medium', source || 'agent']
    );
    res.json({ success: true, ticket_id: result.rows[0].id });
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
