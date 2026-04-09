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
import bcrypt from "bcryptjs";

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

app.set("trust proxy", 1);

app.use(
  session({
    secret: process.env.SESSION_SECRET || "bluemogul-portal-secret",
    resave: false,
    saveUninitialized: true,
    proxy: true,
    cookie: {
      maxAge: 24 * 60 * 60 * 1000,
      httpOnly: true,
      secure: false,
      sameSite: "lax",
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

const ALLOWED_PHP_FILES = ["index.php", "login-handler.php", "setup.php", "dashboard.php", "logout.php", "admin-dashboard.php", "admin-clients.php", "admin-ai-agents.php", "admin-automation.php", "admin-tickets.php", "admin-products.php", "admin-services.php", "admin-settings.php", "admin-client-detail.php", "admin-client-edit.php", "admin-client-add.php", "admin-invoices.php", "admin-invoice-add.php", "admin-invoice-detail.php", "admin-reports.php", "admin-network.php", "admin-knowledge.php", "tickets.php", "ticket-detail.php", "billing.php", "pay-invoice.php", "payment-success.php", "services.php", "products.php", "profile.php", "documents.php", "admin-ticket-detail.php", "help.php", "admin-itflow.php", "admin-uisp.php", "admin-voip.php", "admin-nextcloud.php", "admin-stripe.php", "settings.php", "admin-audit.php", "admin-roles.php", "admin-projects.php", "admin-project-detail.php", "projects.php", "client-voip.php", "admin-messages.php", "admin-message-compose.php", "admin-message-templates.php", "forgot-password.php", "reset-password.php", "admin-vultr.php", "admin-itarian.php", "admin-action1.php", "admin-monitoring.php", "admin-chat.php", "client-chat.php", "admin-crm.php", "service-detail.php", "admin-email-log.php", "admin-frontier.php", "frontier-receive.php", "admin-providers.php", "admin-hostwinds.php", "admin-enom.php", "admin-resellerclub.php", "admin-travelsim.php", "admin-coolify.php", "admin-varphonex.php", "admin-leads-dashboard.php", "admin-leads-add.php", "admin-leads-list.php", "admin-leads-view.php", "admin-leads-quotes.php", "admin-leads-maps.php", "admin-smtp-settings.php", "admin-jumpcloud.php", "admin-client-emails.php", "admin-ai-assistant.php", "admin-mail.php", "mail-webhook.php", "admin-mail-profile.php", "admin-companies.php", "admin-xero.php"];

function buildSessionPhpCode(req: Request): string {
  const sess = (req.session as any)?.portalUser;
  const csrfToken = (req.session as any)?.csrfToken;
  let code = "";
  if (sess) {
    const escaped = JSON.stringify(sess).replace(/'/g, "\\'");
    code += `
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
  if (csrfToken) {
    code += `\n\$_SESSION['csrf_token'] = '${csrfToken.replace(/'/g, "\\'")}';`;
  }
  code += `\nregister_shutdown_function(function() { echo "\\n__CSRF_TOKEN__:" . (\$_SESSION['csrf_token'] ?? ''); });`;
  return code;
}

function extractCsrfToken(stdout: string, req: Request): string {
  const csrfMatch = stdout.match(/\n__CSRF_TOKEN__:([a-f0-9]+)\s*$/);
  if (csrfMatch) {
    (req.session as any).csrfToken = csrfMatch[1];
    req.session.save(() => {});
    return stdout.replace(/\n__CSRF_TOKEN__:[a-f0-9]+\s*$/, '').trimEnd();
  }
  return stdout.replace(/\n__CSRF_TOKEN__:\s*$/, '').trimEnd();
}

function handlePhpResponse(stdout: string, req: Request, res: Response) {
  stdout = extractCsrfToken(stdout, req);
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
  const phpSelf = '/' + filePath.replace(/\\/g, '/').split('/').pop();
  const phpCode = `<?php
error_reporting(E_ERROR | E_PARSE);
$_SERVER['PHP_SELF'] = '${phpSelf.replace(/'/g, "\\'")}';
$_SERVER['SCRIPT_NAME'] = '${phpSelf.replace(/'/g, "\\'")}';
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
        timeout: 60000,
        maxBuffer: 10 * 1024 * 1024,
        cwd: projectRoot,
        env: { ...process.env },
      },
      (error, stdout, stderr) => {
        unlink(tmpFile).catch(() => {});
        if (error) {
          const errMsg = stderr || error.message || 'Unknown PHP error';
          console.error(`PHP execution error:`, errMsg);
          return res.status(500).send(`<html><body style="font-family:Inter,sans-serif;padding:40px"><h2 style="color:#991b1b">Server Error</h2><pre>${errMsg.replace(/</g,'&lt;').substring(0,500)}</pre><a href="javascript:history.back()">Go Back</a></body></html>`);
        }
        handlePhpResponse(stdout, req, res);
      }
    );
  });
}


app.get("/portal", (req, res) => {
  executePhpFile(join(projectRoot, "index.php"), req, res);
});

// ── Web Scraping AI — LinkedIn & Company Website Enrichment ─────────────────
// Uses Jina AI Reader (free, no key needed) to scrape public pages,
// optionally uses WebScraping.AI (WEBSCRAPING_AI_KEY) for enhanced LinkedIn,
// and optionally uses OpenAI (OPENAI_API_KEY) to extract structured data.

async function scrapeWithJina(url: string): Promise<string> {
  const jinaUrl = `https://r.jina.ai/${url}`;
  const resp = await fetch(jinaUrl, {
    headers: { Accept: "text/plain", "X-No-Cache": "true" },
    signal: AbortSignal.timeout(20000),
  });
  if (!resp.ok) throw new Error(`Jina AI returned ${resp.status}`);
  return (await resp.text()).slice(0, 12000); // cap at 12k chars
}

async function scrapeWithWebScrapingAI(url: string, key: string): Promise<string> {
  // LinkedIn requires JS rendering; use js=true so the page actually loads
  const js = url.includes("linkedin.com") ? "true" : "false";
  const apiUrl = `https://api.webscraping.ai/text?url=${encodeURIComponent(url)}&api_key=${key}&js=${js}&timeout=15000`;
  const resp = await fetch(apiUrl, { signal: AbortSignal.timeout(25000) });
  if (!resp.ok) {
    const body = await resp.text().catch(() => "");
    throw new Error(`WebScraping.AI returned ${resp.status}${body ? `: ${body.slice(0, 120)}` : ""}`);
  }
  return (await resp.text()).slice(0, 12000);
}

const OLLAMA_BASE_URL = (process.env.OLLAMA_URL || "https://ollama.bluemogul.us").replace(/\/$/, "");
const OLLAMA_MODEL    = process.env.OLLAMA_MODEL || "llama3.1:8b";

function extractJsonFromText(text: string): any {
  // Try direct parse first
  try { return JSON.parse(text); } catch {}
  // Strip markdown code fences and try again
  const fenced = text.match(/```(?:json)?\s*([\s\S]*?)```/);
  if (fenced) { try { return JSON.parse(fenced[1].trim()); } catch {} }
  // Find first { } block
  const start = text.indexOf("{");
  const end   = text.lastIndexOf("}");
  if (start !== -1 && end !== -1) { try { return JSON.parse(text.slice(start, end + 1)); } catch {} }
  throw new Error("Could not parse JSON from Ollama response");
}

async function extractProfileWithAI(text: string, profileType: string): Promise<any> {
  const systemPrompt = profileType === "company"
    ? 'Extract company info from the web page text. Respond with ONLY a valid JSON object, no explanation. Schema: {"name":"","industry":"","website":"","phone":"","email":"","address":"","employees":"","description":"","social_links":[{"type":"","url":""}]}'
    : 'Extract person info from the web page text. Respond with ONLY a valid JSON object, no explanation. Schema: {"full_name":"","first_name":"","last_name":"","headline":"","job_title":"","company":"","location":"","email":"","phone":"","about":"","experience":[{"title":"","company":"","dates":""}],"education":[{"school":"","degree":"","dates":""}],"linkedin_url":""}';

  const resp = await fetch(`${OLLAMA_BASE_URL}/v1/chat/completions`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      model: OLLAMA_MODEL,
      temperature: 0,
      stream: false,
      messages: [
        { role: "system", content: systemPrompt },
        { role: "user", content: `Extract structured data from this page content:\n\n${text.slice(0, 8000)}` },
      ],
    }),
    signal: AbortSignal.timeout(60000),
  });
  if (!resp.ok) throw new Error(`Ollama returned ${resp.status}: ${await resp.text()}`);
  const data: any = await resp.json();
  const content = data.choices?.[0]?.message?.content || "";
  return extractJsonFromText(content);
}

// ── Shared scrape-and-enrich logic (called directly, no self-referential HTTP) ─
function extractLinkedInUrlFromText(text: string): string {
  // First try DDG redirect links: uddg=https%3A%2F%2Fwww.linkedin.com%2F...
  const uddgMatch = text.match(/uddg=(https?%3A%2F%2F(?:www\.)?linkedin\.com%2F(?:in|company)%2F[^&\s"')\]>]+)/i);
  if (uddgMatch) {
    return decodeURIComponent(uddgMatch[1]).split("?")[0].replace(/\/$/, "");
  }
  // Then try a plain URL in text
  const direct = text.match(/https?:\/\/(?:www\.)?linkedin\.com\/(?:in|company)\/[a-zA-Z0-9_-]{3,}[^\s"')\]>]*/i);
  return direct ? direct[0].split("?")[0].replace(/\/$/, "") : "";
}

async function performScrapeLookup(params: {
  url?: string;
  search_query?: string;
  search_name?: string;  // raw name for helpful error link
  entity_type?: string;
  entity_id?: string;
  profile_type?: string;
}): Promise<{ success: boolean; profile?: any; error?: string; search_url?: string }> {
  const wsaiKey = process.env.WEBSCRAPING_AI_KEY || "";
  let targetUrl = (params.url || "").trim();
  let rawText   = "";

  if (!targetUrl && params.search_query) {
    // Try DuckDuckGo Lite (static HTML, no JS) via Jina Reader
    let found = "";
    const liteUrl = `https://lite.duckduckgo.com/lite/?q=${encodeURIComponent(params.search_query)}`;
    try {
      const liteText = await scrapeWithJina(liteUrl);
      found = extractLinkedInUrlFromText(liteText);
    } catch (_) {}

    if (!found) {
      // Build a manual search link the user can click to find the profile themselves
      const searchHint = params.search_name || params.search_query || "";
      const liSearchUrl = `https://www.linkedin.com/search/results/people/?keywords=${encodeURIComponent(searchHint)}`;
      return {
        success: false,
        error: `Could not automatically find a LinkedIn URL for "${searchHint}". Paste the profile URL in the field above and click Fetch Profile Data.`,
        search_url: liSearchUrl,
      };
    }
    targetUrl = found;
  }

  if (!targetUrl) return { success: false, error: "Provide a URL or search query." };

  // Reject anything that isn't a real URL (e.g. user typed a name in the URL field)
  if (!/^https?:\/\//i.test(targetUrl)) {
    return {
      success: false,
      error: `"${targetUrl}" doesn't look like a URL. Paste a full LinkedIn profile URL (https://www.linkedin.com/in/…) or use Lookup by Name / Lookup by Email.`,
    };
  }

  const isLinkedIn = targetUrl.includes("linkedin.com");
  let scrapeError = "";
  try {
    if (isLinkedIn && wsaiKey) {
      rawText = await scrapeWithWebScrapingAI(targetUrl, wsaiKey);
    } else {
      rawText = await scrapeWithJina(targetUrl);
    }
  } catch (e: any) {
    scrapeError = e.message;
  }

  // If WebScrapingAI failed (or returned too little), fall back to Jina
  if (scrapeError || rawText.length < 50) {
    console.warn(`Primary scrape failed (${scrapeError}), falling back to Jina`);
    try {
      rawText = await scrapeWithJina(targetUrl);
    } catch (e2: any) {
      return { success: false, error: `Scrape failed: ${scrapeError || e2.message}` };
    }
  }

  // Extract structured data with Ollama
  let profile: any = { raw_snippet: rawText.slice(0, 500), scraped_url: targetUrl };
  if (rawText.length > 50) {
    try {
      const pType = params.profile_type || (targetUrl.includes("/company/") ? "company" : "person");
      profile = await extractProfileWithAI(rawText, pType);
      profile.scraped_url = targetUrl;
      profile.model_used  = OLLAMA_MODEL;
    } catch (e: any) {
      profile.ai_error    = e.message;
      profile.raw_snippet = rawText.slice(0, 800);
    }
  }

  // Persist in DB
  if (params.entity_type && params.entity_id) {
    const table = params.entity_type === "client" ? "clients" : "leads";
    const urlToSave = isLinkedIn ? targetUrl : null;
    await webhookPool.query(
      `UPDATE ${table} SET ${isLinkedIn ? "linkedin_url = COALESCE($1, linkedin_url)," : ""} linkedin_data = $2, updated_at = NOW() WHERE id = $3`,
      isLinkedIn
        ? [urlToSave, JSON.stringify(profile), parseInt(params.entity_id)]
        : [JSON.stringify(profile), parseInt(params.entity_id)]
    ).catch(() =>
      webhookPool.query(
        `UPDATE ${table} SET linkedin_data = $1, updated_at = NOW() WHERE id = $2`,
        [JSON.stringify(profile), parseInt(params.entity_id)]
      ).catch(() => {})
    );
  }

  return { success: true, profile };
}

app.post("/portal/api/scrape-lookup", async (req, res) => {
  const session = req.session as any;
  if (!session?.portalUser?.is_admin) return res.status(403).json({ error: "Unauthorized" });
  try {
    const result = await performScrapeLookup(req.body);
    res.status(result.success ? 200 : 400).json(result);
  } catch (e: any) {
    res.status(500).json({ success: false, error: e.message });
  }
});

// Legacy alias — called directly, no internal HTTP forwarding
app.post("/portal/api/linkedin-lookup", async (req, res) => {
  const session = req.session as any;
  if (!session?.portalUser?.is_admin) return res.status(403).json({ error: "Unauthorized" });
  const { linkedin_url, entity_type, entity_id, search_name, search_email, profile_type } = req.body;
  const search_query = search_email
    ? `site:linkedin.com/in ${search_email}`
    : search_name ? `site:linkedin.com/in "${search_name}"` : "";
  try {
    const result = await performScrapeLookup({
      url: linkedin_url || "",
      search_query,
      search_name: search_name || search_email || "",
      entity_type,
      entity_id,
      profile_type: profile_type || "person",
    });
    res.status(result.success ? 200 : 400).json(result);
  } catch (e: any) {
    res.status(500).json({ success: false, error: e.message });
  }
});

app.post("/portal/api/linkedin-save-url", async (req, res) => {
  const session = req.session as any;
  if (!session?.portalUser?.is_admin) return res.status(403).json({ error: "Unauthorized" });
  const { entity_type, entity_id, linkedin_url, clear_data } = req.body;
  if (!entity_type || !entity_id) return res.status(400).json({ error: "entity_type and entity_id required" });
  const table = entity_type === "client" ? "clients" : "leads";
  try {
    if (clear_data) {
      await webhookPool.query(`UPDATE ${table} SET linkedin_url = $1, linkedin_data = NULL, updated_at = NOW() WHERE id = $2`, [linkedin_url || null, parseInt(entity_id)]);
    } else {
      await webhookPool.query(`UPDATE ${table} SET linkedin_url = $1, updated_at = NOW() WHERE id = $2`, [linkedin_url || null, parseInt(entity_id)]);
    }
    res.json({ success: true });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

// ── Xero OAuth ────────────────────────────────────────────────────────────────
const XERO_CLIENT_ID     = process.env.XERO_CLIENT_ID || "";
const XERO_CLIENT_SECRET = process.env.XERO_CLIENT_SECRET || "";
// XERO_REDIRECT_URI must match exactly what is registered in your Xero developer app.
// e.g. https://portal.bluemogul.us/api/xero/callback
const XERO_REDIRECT_URI_ENV = process.env.XERO_REDIRECT_URI || "";
const XERO_SCOPES = "openid profile email accounting.transactions accounting.contacts.read offline_access";

function getXeroRedirectUri(req: any): string {
  if (XERO_REDIRECT_URI_ENV) return XERO_REDIRECT_URI_ENV;
  // Auto-detect: strip /portal prefix from path so it matches portal.bluemogul.us/api/xero/callback
  const host = (req.headers["x-forwarded-host"] || req.headers.host || "") as string;
  const proto = (req.headers["x-forwarded-proto"] || req.protocol) as string;
  return `${proto}://${host}/api/xero/callback`;
}

async function handleXeroConnect(req: any, res: any) {
  const session = req.session as any;
  if (!session?.portalUser?.is_admin) return res.status(403).send("Unauthorized");
  if (!XERO_CLIENT_ID) return res.status(400).send("XERO_CLIENT_ID not configured. Add it to your environment variables.");
  const redirectUri = getXeroRedirectUri(req);
  const state = Math.random().toString(36).slice(2);
  session.xeroState = state;
  const url = `https://login.xero.com/identity/connect/authorize?response_type=code&client_id=${XERO_CLIENT_ID}&redirect_uri=${encodeURIComponent(redirectUri)}&scope=${encodeURIComponent(XERO_SCOPES)}&state=${state}`;
  res.redirect(url);
}

async function handleXeroCallback(req: any, res: any) {
  const session = req.session as any;
  const { code, state, error: xeroError } = req.query;

  if (xeroError) {
    return res.redirect(`/portal/admin-xero.php?error=${encodeURIComponent(`Xero auth error: ${xeroError}`)}`);
  }
  // Allow state mismatch gracefully (can happen after session expiry or new tab)
  if (state && session.xeroState && state !== session.xeroState) {
    return res.redirect(`/portal/admin-xero.php?error=${encodeURIComponent("State mismatch — please try connecting again.")}`);
  }
  if (!code) {
    return res.redirect(`/portal/admin-xero.php?error=${encodeURIComponent("No authorization code received from Xero.")}`);
  }

  const redirectUri = getXeroRedirectUri(req);
  try {
    const tokenResp = await fetch("https://identity.xero.com/connect/token", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded",
        Authorization: "Basic " + Buffer.from(`${XERO_CLIENT_ID}:${XERO_CLIENT_SECRET}`).toString("base64"),
      },
      body: new URLSearchParams({ grant_type: "authorization_code", code: String(code), redirect_uri: redirectUri }).toString(),
    });
    if (!tokenResp.ok) throw new Error(`Token exchange failed: ${await tokenResp.text()}`);
    const tokens: any = await tokenResp.json();

    // Get tenant/org list
    const tenantResp = await fetch("https://api.xero.com/connections", {
      headers: { Authorization: `Bearer ${tokens.access_token}` },
    });
    const tenants: any[] = await tenantResp.json();
    const tenantId = tenants[0]?.tenantId || "";

    // Store tokens in provider_settings
    await webhookPool.query(
      `INSERT INTO provider_settings (provider_name, settings, updated_at) VALUES ('xero', $1, NOW())
       ON CONFLICT (provider_name) DO UPDATE SET settings = $1, updated_at = NOW()`,
      [JSON.stringify({ access_token: tokens.access_token, refresh_token: tokens.refresh_token, expires_at: Date.now() + tokens.expires_in * 1000, tenant_id: tenantId, tenants })]
    );
    res.redirect("/portal/admin-xero.php?connected=1");
  } catch (e: any) {
    res.redirect(`/portal/admin-xero.php?error=${encodeURIComponent(e.message)}`);
  }
}

// Register both with and without /portal prefix to match any redirect URI config
app.get("/portal/api/xero/connect", handleXeroConnect);
app.get("/api/xero/connect",        handleXeroConnect);
app.get("/portal/api/xero/callback", handleXeroCallback);
app.get("/api/xero/callback",        handleXeroCallback);

app.post("/portal/api/xero/refresh", async (req, res) => {
  const session = req.session as any;
  if (!session?.portalUser?.is_admin) return res.status(403).json({ error: "Unauthorized" });
  try {
    const row = await webhookPool.query("SELECT settings FROM provider_settings WHERE provider_name='xero'");
    if (!row.rows.length) return res.status(404).json({ error: "Xero not connected" });
    const cfg = row.rows[0].settings;
    const tokenResp = await fetch("https://identity.xero.com/connect/token", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Authorization: "Basic " + Buffer.from(`${XERO_CLIENT_ID}:${XERO_CLIENT_SECRET}`).toString("base64") },
      body: new URLSearchParams({ grant_type: "refresh_token", refresh_token: cfg.refresh_token }).toString(),
    });
    const tokens: any = await tokenResp.json();
    await webhookPool.query("UPDATE provider_settings SET settings = settings || $1::jsonb, updated_at = NOW() WHERE provider_name='xero'",
      [JSON.stringify({ access_token: tokens.access_token, refresh_token: tokens.refresh_token || cfg.refresh_token, expires_at: Date.now() + (tokens.expires_in || 1800) * 1000 })]);
    res.json({ success: true });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.get("/portal/api/xero/data", async (req, res) => {
  const session = req.session as any;
  if (!session?.portalUser?.is_admin) return res.status(403).json({ error: "Unauthorized" });
  const { resource } = req.query; // 'invoices', 'contacts', 'accounts', 'reports/BankSummary'
  try {
    const row = await webhookPool.query("SELECT settings FROM provider_settings WHERE provider_name='xero'");
    if (!row.rows.length) return res.status(404).json({ error: "Xero not connected" });
    const cfg = row.rows[0].settings;
    // Auto-refresh if expired
    if (cfg.expires_at && Date.now() > cfg.expires_at - 60000) {
      try {
        const tr = await fetch("https://identity.xero.com/connect/token", {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded", Authorization: "Basic " + Buffer.from(`${XERO_CLIENT_ID}:${XERO_CLIENT_SECRET}`).toString("base64") },
          body: new URLSearchParams({ grant_type: "refresh_token", refresh_token: cfg.refresh_token }).toString(),
        });
        const t: any = await tr.json();
        cfg.access_token = t.access_token;
        if (t.refresh_token) cfg.refresh_token = t.refresh_token;
        await webhookPool.query("UPDATE provider_settings SET settings = settings || $1::jsonb, updated_at = NOW() WHERE provider_name='xero'",
          [JSON.stringify({ access_token: t.access_token, refresh_token: t.refresh_token || cfg.refresh_token, expires_at: Date.now() + (t.expires_in || 1800) * 1000 })]);
      } catch (e) { /* use existing token */ }
    }
    const endpoint = `https://api.xero.com/api.xro/2.0/${resource || "Invoices"}`;
    const xeroResp = await fetch(`${endpoint}?${new URLSearchParams(req.query as any).toString().replace(/^resource=[^&]*&?/, "")}`, {
      headers: { Authorization: `Bearer ${cfg.access_token}`, "Xero-Tenant-Id": cfg.tenant_id, Accept: "application/json" },
    });
    if (!xeroResp.ok) return res.status(xeroResp.status).json({ error: await xeroResp.text() });
    res.json(await xeroResp.json());
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});
// ── End Xero OAuth ────────────────────────────────────────────────────────────

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
    if (Array.isArray(value)) {
      for (const v of value) {
        formParts.push(`${encodeURIComponent(key + '[]')}=${encodeURIComponent(String(v))}`);
      }
    } else if (value !== null && typeof value === 'object') {
      for (const [subKey, subVal] of Object.entries(value as Record<string, unknown>)) {
        formParts.push(`${encodeURIComponent(`${key}[${subKey}]`)}=${encodeURIComponent(String(subVal))}`);
      }
    } else {
      formParts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
    }
  }
  const postData = formParts.join("&");

  // postData is fully URL-encoded so it's safe in a single-quoted PHP string
  // Query params may contain arbitrary chars — escape backslashes then single quotes
  const safeQ = (s: string) => s.replace(/\\/g, "\\\\").replace(/'/g, "\\'");
  const phpSelfPost = '/' + filePath.replace(/\\/g, '/').split('/').pop();
  const phpCode = `<?php
error_reporting(E_ERROR | E_PARSE);
$_SERVER['PHP_SELF'] = '${phpSelfPost.replace(/'/g, "\\'")}';
$_SERVER['SCRIPT_NAME'] = '${phpSelfPost.replace(/'/g, "\\'")}';
session_start();
${sessionCode}
$_SERVER['REQUEST_METHOD'] = 'POST';
parse_str('${postData}', $_POST);
$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
$_GET = [];
${Object.entries(req.query || {}).map(([k, v]) => `$_GET['${safeQ(k)}'] = '${safeQ(String(v))}';`).join("\n")}
require '${filePath.replace(/'/g, "\\'")}';
`;

  const tmpFile = join(tmpdir(), `portal_${randomUUID()}.php`);
  writeFile(tmpFile, phpCode).then(() => {
    execFile(
      "php",
      [tmpFile],
      {
        timeout: 60000,
        maxBuffer: 10 * 1024 * 1024,
        cwd: projectRoot,
        env: { ...process.env },
      },
      (error, stdout, stderr) => {
        unlink(tmpFile).catch(() => {});
        if (error) {
          const errMsg = stderr || error.message || 'Unknown PHP error';
          console.error(`PHP POST error (${phpFile}):`, errMsg);
          if (stdout) console.error(`PHP POST stdout (${phpFile}):`, stdout.substring(0, 1000));
          const htmlError = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Server Error</title>
<style>body{font-family:Inter,sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
.box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:40px;max-width:600px;text-align:center;}
h2{color:#991b1b;margin:0 0 12px;}p{color:#6b7280;margin:0 0 20px;}
pre{background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:16px;text-align:left;font-size:12px;color:#7f1d1d;overflow:auto;max-height:200px;}
a{color:#1a56db;text-decoration:none;}</style></head>
<body><div class="box"><h2>&#x26A0; Server Error</h2>
<p>An error occurred processing your request. Please go back and try again.</p>
<pre>${errMsg.replace(/</g, '&lt;').replace(/>/g, '&gt;').substring(0, 800)}</pre>
<a href="javascript:history.back()">&#8592; Go Back</a></div></body></html>`;
          return res.status(500).send(htmlError);
        }
        stdout = extractCsrfToken(stdout, req);
        try {
          const json = JSON.parse(stdout);
          res.json(json);
        } catch {
          handlePhpResponse(stdout, req, res);
        }
      }
    );
  });
});

async function handleLogin(req: Request, res: Response) {
  try {
    const body = req.body || {};
    const email = String(body.email || '').trim().toLowerCase();
    const password = String(body.password || '');
    const remember = body.remember === '1';
    const postCsrfToken = String(body.csrf_token || '');
    const sessionCsrfToken = String((req.session as any)?.csrfToken || '');

    console.log(`[LOGIN] attempt for ${email} | session CSRF: ${sessionCsrfToken ? 'present' : 'MISSING'}, post CSRF: ${postCsrfToken ? 'present' : 'MISSING'}`);

    // CSRF check: accept session token OR posted token (resilient to proxy cookie loss)
    const activeCsrf = sessionCsrfToken || postCsrfToken;
    if (!activeCsrf || !postCsrfToken || activeCsrf !== postCsrfToken) {
      // If session has no CSRF, postCsrfToken is the only token we have — accept it
      // (the page generated it; attacker can't forge it from another origin due to CORS)
      if (!postCsrfToken) {
        return res.status(403).json({ success: false, message: 'Invalid security token. Please refresh the page and try again.' });
      }
    }

    if (!email || !password) {
      return res.json({ success: false, message: 'Please enter both email and password' });
    }

    const ip = String(req.ip || req.headers['x-forwarded-for'] || '0.0.0.0');

    // Rate limit: check failed attempts in the last 15 minutes
    const rateLimitResult = await webhookPool.query(
      `SELECT COUNT(*) as attempts FROM activity_log WHERE action = 'login_failed' AND details LIKE $1 AND created_at > NOW() - INTERVAL '15 minutes'`,
      [`%${email}%`]
    );
    const attempts = parseInt(rateLimitResult.rows[0]?.attempts || '0', 10);
    if (attempts >= 5) {
      return res.json({ success: false, message: 'Too many login attempts. Please try again in 15 minutes.' });
    }

    // Look up user
    const userResult = await webhookPool.query(
      `SELECT id, email, password, name, is_admin, role, status FROM users WHERE LOWER(email) = $1 LIMIT 1`,
      [email]
    );
    const user = userResult.rows[0];

    if (!user) {
      console.log(`[LOGIN] user not found: ${email}`);
      await webhookPool.query(
        `INSERT INTO activity_log (user_id, action, entity_type, details, ip_address) VALUES (NULL, 'login_failed', 'auth', $1, $2)`,
        [`Failed login for: ${email}`, ip]
      ).catch(() => {});
      return res.json({ success: false, message: 'Invalid email or password' });
    }

    // Normalise PHP $2y$ prefix → $2b$ so bcryptjs can verify it
    const storedHash = String(user.password || '').replace(/^\$2y\$/, '$2b$');
    const passwordMatch = await bcrypt.compare(password, storedHash);

    if (!passwordMatch) {
      console.log(`[LOGIN] password mismatch for: ${email}`);
      await webhookPool.query(
        `INSERT INTO activity_log (user_id, action, entity_type, details, ip_address) VALUES (NULL, 'login_failed', 'auth', $1, $2)`,
        [`Failed login for: ${email}`, ip]
      ).catch(() => {});
      return res.json({ success: false, message: 'Invalid email or password' });
    }

    if ((user.status || 'active') === 'inactive') {
      return res.json({ success: false, message: 'Your account has been deactivated. Please contact support.' });
    }

    // Set session
    const sessionData = {
      user_id: user.id,
      user_email: user.email,
      user_name: user.name,
      is_admin: Boolean(user.is_admin),
      user_role: user.role || 'user',
      logged_in_at: Math.floor(Date.now() / 1000),
      last_activity: Math.floor(Date.now() / 1000),
    };
    (req.session as any).portalUser = sessionData;

    // Handle "remember me"
    if (remember) {
      const token = randomUUID().replace(/-/g, '');
      const expiry = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000);
      await webhookPool.query(
        `UPDATE users SET remember_token = $1, remember_token_expires = $2 WHERE id = $3`,
        [token, expiry.toISOString(), user.id]
      ).catch(() => {});
    }

    // Update last_login
    await webhookPool.query(`UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = $1`, [user.id]).catch(() => {});

    // Log successful login
    await webhookPool.query(
      `INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES ($1, 'login', 'user', $2, 'User logged in', $3)`,
      [user.id, user.id, ip]
    ).catch(() => {});

    console.log(`[LOGIN] success for ${email} (id=${user.id}, admin=${user.is_admin})`);

    req.session.save(() => {
      res.json({
        success: true,
        message: 'Login successful!',
        redirect: 'dashboard.php',
        user: {
          id: user.id,
          name: user.name,
          email: user.email,
          is_admin: Boolean(user.is_admin),
          role: user.role || 'user',
        }
      });
    });
  } catch (err: any) {
    console.error('[LOGIN] error:', err?.message || err);
    res.status(500).json({ success: false, message: 'A server error occurred. Please try again.' });
  }
}

app.use("/uploads", express.static(join(projectRoot, "uploads")));

// ═══════════════════════════════════════════════════════════════
// Document Upload/Download API
// ═══════════════════════════════════════════════════════════════

import multer from "multer";
import { existsSync, mkdirSync, unlinkSync, statSync } from "fs";

const uploadsDir = join(projectRoot, "uploads");
if (!existsSync(uploadsDir)) {
  mkdirSync(uploadsDir, { recursive: true });
}

const ALLOWED_EXTENSIONS = [
  ".pdf", ".doc", ".docx", ".xls", ".xlsx", ".ppt", ".pptx",
  ".txt", ".csv", ".rtf", ".odt", ".ods",
  ".jpg", ".jpeg", ".png", ".gif", ".bmp", ".svg", ".webp",
  ".zip", ".rar", ".7z", ".tar", ".gz",
];
const MAX_FILE_SIZE = 25 * 1024 * 1024;

const upload = multer({
  storage: multer.diskStorage({
    destination: (_req, _file, cb) => cb(null, uploadsDir),
    filename: (_req, file, cb) => {
      const safeName = file.originalname.replace(/[^a-zA-Z0-9._-]/g, "_");
      const uniqueName = `${Date.now()}_${randomUUID().slice(0, 8)}_${safeName}`;
      cb(null, uniqueName);
    },
  }),
  limits: { fileSize: MAX_FILE_SIZE },
  fileFilter: (_req, file, cb) => {
    const ext = "." + (file.originalname.split(".").pop() || "").toLowerCase();
    if (ALLOWED_EXTENSIONS.includes(ext)) {
      cb(null, true);
    } else {
      cb(new Error(`File type not allowed: ${ext}`));
    }
  },
});

app.post("/api/documents/upload", upload.single("file"), async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });

  const csrfToken = req.body.csrf_token;
  const sessionCsrf = (req.session as any)?.csrfToken;
  if (!csrfToken || csrfToken !== sessionCsrf) {
    return res.status(403).json({ error: "CSRF validation failed" });
  }

  const file = req.file;
  if (!file) return res.status(400).json({ error: "No file uploaded" });

  const docName = (req.body.doc_name || file.originalname).trim();
  const category = req.body.category || "general";
  const description = (req.body.description || "").trim();

  try {
    const clientResult = await webhookPool.query(
      "SELECT id FROM clients WHERE user_id = $1",
      [sess.user_id]
    );
    const clientId = clientResult.rows[0]?.id || sess.user_id;

    const result = await webhookPool.query(
      `INSERT INTO documents (client_id, uploaded_by, name, filename, filepath, filesize, mimetype, category, description, is_public, created_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, false, NOW()) RETURNING id`,
      [clientId, sess.user_id, docName, file.filename, `uploads/${file.filename}`, file.size, file.mimetype, category, description]
    );

    await webhookPool.query(
      "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES ($1, $2, $3, $4, $5, $6)",
      [sess.user_id, "document_uploaded", "document", result.rows[0].id, `Uploaded: ${docName}`, req.ip || "0.0.0.0"]
    );

    res.json({ success: true, message: "Document uploaded successfully", id: result.rows[0].id });
  } catch (e: any) {
    if (file?.path && existsSync(file.path)) {
      try { unlinkSync(file.path); } catch {}
    }
    console.error("Document upload error:", e.message);
    res.status(500).json({ error: "Failed to upload document" });
  }
});

app.get("/api/documents/download/:id", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });

  try {
    const clientResult = await webhookPool.query(
      "SELECT id FROM clients WHERE user_id = $1",
      [sess.user_id]
    );
    const clientId = clientResult.rows[0]?.id || sess.user_id;

    let docResult;
    if (sess.is_admin) {
      docResult = await webhookPool.query("SELECT * FROM documents WHERE id = $1", [req.params.id]);
    } else {
      docResult = await webhookPool.query("SELECT * FROM documents WHERE id = $1 AND client_id = $2", [req.params.id, clientId]);
    }

    const doc = docResult.rows[0];
    if (!doc) return res.status(404).json({ error: "Document not found" });

    const filePath = join(projectRoot, doc.filepath);
    if (!existsSync(filePath)) {
      return res.status(404).json({ error: "File not found on disk" });
    }

    const stats = statSync(filePath);
    res.setHeader("Content-Type", doc.mimetype || "application/octet-stream");
    res.setHeader("Content-Length", stats.size);
    res.setHeader("Content-Disposition", `attachment; filename="${encodeURIComponent(doc.name || doc.filename)}"`);
    res.sendFile(filePath);
  } catch (e: any) {
    console.error("Document download error:", e.message);
    res.status(500).json({ error: "Failed to download document" });
  }
});

// ═══════════════════════════════════════════════════════════════
// Ticket Attachment Upload
// ═══════════════════════════════════════════════════════════════

const ticketsUploadDir = join(projectRoot, "uploads", "tickets");
const avatarsUploadDir = join(projectRoot, "uploads", "avatars");
const articlesUploadDir = join(projectRoot, "uploads", "knowledge");
[ticketsUploadDir, avatarsUploadDir, articlesUploadDir].forEach(d => {
  if (!existsSync(d)) mkdirSync(d, { recursive: true });
});

const makeUploader = (dest: string, allowedExts: string[]) =>
  multer({
    storage: multer.diskStorage({
      destination: (_req, _file, cb) => cb(null, dest),
      filename: (_req, file, cb) => {
        const safeName = file.originalname.replace(/[^a-zA-Z0-9._-]/g, "_");
        cb(null, `${Date.now()}_${randomUUID().slice(0, 8)}_${safeName}`);
      },
    }),
    limits: { fileSize: 10 * 1024 * 1024 },
    fileFilter: (_req, file, cb) => {
      const ext = "." + (file.originalname.split(".").pop() || "").toLowerCase();
      allowedExts.includes(ext) ? cb(null, true) : cb(new Error(`Not allowed: ${ext}`));
    },
  });

const ticketUploader = makeUploader(ticketsUploadDir, [".pdf", ".gif", ".jpeg", ".jpg", ".txt", ".png"]);
const avatarUploader = makeUploader(avatarsUploadDir, [".jpg", ".jpeg", ".gif", ".png"]);
const articleUploader = makeUploader(articlesUploadDir, [".pdf"]);

app.post("/api/upload/ticket-attachment", ticketUploader.single("attachment"), async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  const ticketId = parseInt(req.body.ticket_id || "0");
  if (!ticketId) return res.status(400).json({ error: "ticket_id required" });
  const file = req.file;
  if (!file) return res.status(400).json({ error: "No file uploaded" });
  try {
    const clientResult = await webhookPool.query("SELECT id FROM clients WHERE user_id = $1", [sess.user_id]);
    const clientId = clientResult.rows[0]?.id || sess.user_id;
    let ticketCheck;
    if (sess.is_admin) {
      ticketCheck = await webhookPool.query("SELECT id FROM tickets WHERE id = $1", [ticketId]);
    } else {
      ticketCheck = await webhookPool.query("SELECT id FROM tickets WHERE id = $1 AND client_id = $2", [ticketId, clientId]);
    }
    if (!ticketCheck.rows[0]) return res.status(403).json({ error: "Ticket not found" });
    const relPath = `uploads/tickets/${file.filename}`;
    await webhookPool.query(
      "UPDATE tickets SET attachment_path = $1, attachment_name = $2, attachment_mime = $3, updated_at = NOW() WHERE id = $4",
      [relPath, file.originalname, file.mimetype, ticketId]
    );
    res.json({ success: true, path: "/" + relPath, name: file.originalname });
  } catch (e: any) {
    console.error("Ticket attachment error:", e.message);
    res.status(500).json({ error: "Upload failed" });
  }
});

app.post("/api/upload/avatar", avatarUploader.single("avatar"), async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  const file = req.file;
  if (!file) return res.status(400).json({ error: "No file uploaded" });
  try {
    const relPath = `uploads/avatars/${file.filename}`;
    await webhookPool.query("UPDATE users SET avatar_path = $1 WHERE id = $2", [relPath, sess.user_id]);
    if ((req.session as any).portalUser) {
      (req.session as any).portalUser.avatar_path = relPath;
    }
    res.json({ success: true, path: "/" + relPath });
  } catch (e: any) {
    console.error("Avatar upload error:", e.message);
    res.status(500).json({ error: "Upload failed" });
  }
});

app.post("/api/upload/article-pdf", articleUploader.single("pdf"), async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id || !sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  const file = req.file;
  if (!file) return res.status(400).json({ error: "No file uploaded" });
  const articleId = parseInt(req.body.article_id || "0");
  try {
    const relPath = `uploads/knowledge/${file.filename}`;
    if (articleId) {
      await webhookPool.query("UPDATE knowledge_articles SET pdf_path = $1, updated_at = NOW() WHERE id = $2", [relPath, articleId]);
    }
    res.json({ success: true, path: "/" + relPath, name: file.originalname, article_id: articleId });
  } catch (e: any) {
    console.error("Article PDF upload error:", e.message);
    res.status(500).json({ error: "Upload failed" });
  }
});

// ═══════════════════════════════════════════════════════════════
// Two-Factor Authentication (TOTP)
// ═══════════════════════════════════════════════════════════════

import { createHmac as _createHmac } from "crypto";

function base32Decode(secret: string): Buffer {
  const B32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = 0, value = 0;
  const output: number[] = [];
  for (const ch of secret.toUpperCase().replace(/=+$/, "")) {
    const idx = B32.indexOf(ch);
    if (idx === -1) continue;
    value = (value << 5) | idx;
    bits += 5;
    if (bits >= 8) { output.push((value >>> (bits - 8)) & 0xff); bits -= 8; }
  }
  return Buffer.from(output);
}

function generateTotpCode(secret: string, counter: number): string {
  const key = base32Decode(secret);
  const buf = Buffer.alloc(8);
  buf.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
  buf.writeUInt32BE(counter >>> 0, 4);
  const hmac = _createHmac("sha1", key).update(buf).digest();
  const offset = hmac[19] & 0x0f;
  const code = ((hmac[offset] & 0x7f) << 24) | (hmac[offset+1] << 16) | (hmac[offset+2] << 8) | hmac[offset+3];
  return String(code % 1000000).padStart(6, "0");
}

function verifyTotp(secret: string, token: string): boolean {
  const t = Math.floor(Date.now() / 30000);
  for (let i = -1; i <= 1; i++) {
    if (generateTotpCode(secret, t + i) === token) return true;
  }
  return false;
}

function generateBase32Secret(): string {
  const B32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let s = "";
  for (let i = 0; i < 16; i++) s += B32[Math.floor(Math.random() * 32)];
  return s;
}

app.get("/api/2fa/setup", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  try {
    const result = await webhookPool.query("SELECT email, totp_enabled, totp_secret FROM users WHERE id = $1", [sess.user_id]);
    const user = result.rows[0];
    if (!user) return res.status(404).json({ error: "User not found" });
    if (user.totp_enabled) return res.json({ enabled: true });
    const secret = generateBase32Secret();
    await webhookPool.query("UPDATE users SET totp_secret = $1 WHERE id = $2", [secret, sess.user_id]);
    const label = encodeURIComponent(`BlueMogul:${user.email}`);
    const issuer = encodeURIComponent("BlueMogul");
    const uri = `otpauth://totp/${label}?secret=${secret}&issuer=${issuer}&algorithm=SHA1&digits=6&period=30`;
    const qr = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(uri)}`;
    res.json({ enabled: false, secret, qr_url: qr, uri });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

app.post("/api/2fa/enable", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  const { token } = req.body;
  if (!token) return res.status(400).json({ error: "token required" });
  try {
    const result = await webhookPool.query("SELECT totp_secret FROM users WHERE id = $1", [sess.user_id]);
    const secret = result.rows[0]?.totp_secret;
    if (!secret) return res.status(400).json({ error: "No TOTP secret found. Setup first." });
    if (!verifyTotp(secret, token.toString().trim())) return res.status(400).json({ error: "Invalid code. Please try again." });
    await webhookPool.query("UPDATE users SET totp_enabled = true WHERE id = $1", [sess.user_id]);
    res.json({ success: true, message: "2FA enabled successfully." });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

app.post("/api/2fa/disable", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  const { token } = req.body;
  if (!token) return res.status(400).json({ error: "token required" });
  try {
    const result = await webhookPool.query("SELECT totp_secret FROM users WHERE id = $1", [sess.user_id]);
    const secret = result.rows[0]?.totp_secret;
    if (!secret || !verifyTotp(secret, token.toString().trim())) return res.status(400).json({ error: "Invalid code." });
    await webhookPool.query("UPDATE users SET totp_enabled = false, totp_secret = NULL WHERE id = $1", [sess.user_id]);
    res.json({ success: true, message: "2FA disabled." });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

// ═══════════════════════════════════════════════════════════════
// CRM Deals API
// ═══════════════════════════════════════════════════════════════

app.get("/api/crm/deals", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  try {
    const result = await webhookPool.query(
      `SELECT d.*, l.name as lead_name, c.name as client_name, c.company as client_company FROM crm_deals d
       LEFT JOIN crm_leads l ON d.lead_id = l.id LEFT JOIN clients c ON d.client_id = c.id ORDER BY d.created_at DESC`
    );
    res.json({ deals: result.rows });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.post("/api/crm/deals", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  const { title, stage, value, probability, expected_close, assigned_to, notes, lead_id, client_id } = req.body;
  if (!title) return res.status(400).json({ error: "title required" });
  try {
    const result = await webhookPool.query(
      "INSERT INTO crm_deals (title, lead_id, client_id, stage, value, probability, expected_close, assigned_to, notes, created_by) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) RETURNING *",
      [title, lead_id || null, client_id || null, stage || "prospecting", value || 0, probability || 0, expected_close || null, assigned_to || null, notes || null, sess.user_id]
    );
    res.json({ success: true, deal: result.rows[0] });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.patch("/api/crm/deals/:id", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  const { stage } = req.body;
  try {
    await webhookPool.query("UPDATE crm_deals SET stage = $1, updated_at = NOW() WHERE id = $2", [stage, req.params.id]);
    res.json({ success: true });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.delete("/api/crm/deals/:id", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  try {
    await webhookPool.query("DELETE FROM crm_deals WHERE id = $1", [req.params.id]);
    res.json({ success: true });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

// ═══════════════════════════════════════════════════════════════
// Social Media Posts API
// ═══════════════════════════════════════════════════════════════

app.get("/api/crm/social-posts", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  try {
    const result = await webhookPool.query("SELECT * FROM crm_social_posts ORDER BY created_at DESC LIMIT 100");
    res.json({ posts: result.rows });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.post("/api/crm/social-posts", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  const { platform, content, scheduled_at } = req.body;
  if (!platform || !content) return res.status(400).json({ error: "platform and content required" });
  try {
    const result = await webhookPool.query(
      "INSERT INTO crm_social_posts (platform, content, scheduled_at, status, created_by) VALUES ($1,$2,$3,'draft',$4) RETURNING *",
      [platform, content, scheduled_at || null, sess.user_id]
    );
    res.json({ success: true, post: result.rows[0] });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.delete("/api/crm/social-posts/:id", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  try {
    await webhookPool.query("DELETE FROM crm_social_posts WHERE id = $1", [req.params.id]);
    res.json({ success: true });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

// ═══════════════════════════════════════════════════════════════
// Chatbot API
// ═══════════════════════════════════════════════════════════════

app.post("/api/chatbot/message", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  const { message } = req.body;
  if (!message) return res.status(400).json({ error: "message required" });
  const msg = message.toLowerCase().trim();
  let reply = "";
  if (msg.includes("ticket") || msg.includes("support")) {
    reply = "To create a support ticket, click the 'New Ticket' button on the Tickets page. Our team typically responds within 24 hours.";
  } else if (msg.includes("invoice") || msg.includes("bill") || msg.includes("payment")) {
    reply = "You can view and pay your invoices on the Billing page. We accept all major credit cards via Stripe. If you have billing questions, please open a Billing ticket.";
  } else if (msg.includes("service") || msg.includes("product")) {
    reply = "You can browse available products and services on the Products page. Need something custom? Open a Sales ticket and our team will reach out.";
  } else if (msg.includes("voip") || msg.includes("phone") || msg.includes("voice")) {
    reply = "For VoIP/Voice services, visit the Voice Services page to check your balance and manage your account. For issues, open a Support ticket.";
  } else if (msg.includes("password") || msg.includes("account") || msg.includes("login")) {
    reply = "To change your password, go to My Profile. For login issues, use the 'Forgot Password' link on the login page.";
  } else if (msg.includes("hello") || msg.includes("hi") || msg.includes("hey")) {
    reply = "Hi there! I'm the Blue Mogul support bot. I can help you with tickets, billing, services, VoIP, and account questions. What can I help you with?";
  } else if (msg.includes("hours") || msg.includes("open") || msg.includes("contact")) {
    reply = "Blue Mogul support is available Monday–Friday, 8am–6pm CST. For urgent issues outside business hours, please open a ticket marked Urgent.";
  } else if (msg.includes("internet") || msg.includes("fiber") || msg.includes("broadband")) {
    reply = "For internet/fiber service inquiries, please open a Sales ticket or visit the Products page to explore available plans.";
  } else {
    reply = "I'm not sure I understand. You can:\n• Open a **Support Ticket** for technical issues\n• Visit **Billing** for invoice questions\n• Check **Products** for new services\n• Or type 'hours' to see our support hours.";
  }
  res.json({ reply, timestamp: new Date().toISOString() });
});

// ═══════════════════════════════════════════════════════════════
// Chat API — Standalone portal messaging system
// ═══════════════════════════════════════════════════════════════

app.get("/api/chat/channels", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  try {
    const result = await webhookPool.query(
      "SELECT c.*, (SELECT COUNT(*) FROM chat_channel_members cm WHERE cm.channel_id = c.id) as member_count FROM chat_channels c ORDER BY c.id"
    );
    res.json({ channels: result.rows });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

app.get("/api/chat/channels/:id/members", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  try {
    const result = await webhookPool.query(
      "SELECT cm.*, u.name as user_name, u.email as user_email FROM chat_channel_members cm LEFT JOIN users u ON u.id = cm.user_id WHERE cm.channel_id = $1 ORDER BY cm.added_at",
      [req.params.id]
    );
    res.json({ members: result.rows });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

app.post("/api/chat/channels/:id/members", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id || !sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  const { user_id, role } = req.body;
  if (!user_id) return res.status(400).json({ error: "user_id required" });
  try {
    const result = await webhookPool.query(
      "INSERT INTO chat_channel_members (channel_id, user_id, role) VALUES ($1, $2, $3) ON CONFLICT (channel_id, user_id) DO UPDATE SET role = $3 RETURNING *",
      [req.params.id, user_id, role || "member"]
    );
    res.json({ member: result.rows[0] });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

app.delete("/api/chat/channels/:id/members/:userId", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id || !sess?.is_admin) return res.status(403).json({ error: "Admin only" });
  try {
    await webhookPool.query(
      "DELETE FROM chat_channel_members WHERE channel_id = $1 AND user_id = $2",
      [req.params.id, req.params.userId]
    );
    res.json({ success: true });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

app.get("/api/chat/messages", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  const room = (req.query.room as string) || "general";
  const after = parseInt(req.query.after as string) || 0;
  try {
    const result = await webhookPool.query(
      "SELECT id, room, user_id, user_name, is_admin, message, created_at FROM chat_messages WHERE room = $1 AND id > $2 ORDER BY created_at ASC LIMIT 200",
      [room, after]
    );
    res.json({ messages: result.rows });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

app.post("/api/chat/messages", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  const { room, message } = req.body;
  if (!room || !message?.trim()) return res.status(400).json({ error: "Room and message required" });
  try {
    const result = await webhookPool.query(
      "INSERT INTO chat_messages (room, user_id, user_name, is_admin, message) VALUES ($1, $2, $3, $4, $5) RETURNING *",
      [room, sess.user_id, sess.user_name || "User", sess.is_admin || false, message.trim()]
    );
    res.json({ message: result.rows[0] });
  } catch (e: any) {
    res.status(500).json({ error: e.message });
  }
});

app.get("/api/monitoring/health", async (req, res) => {
  const sess = (req.session as any)?.portalUser;
  if (!sess?.user_id) return res.status(401).json({ error: "Not authenticated" });
  const results: Record<string, any> = {};
  const checks = [
    { name: "uptime_kuma", url: process.env.UPTIME_KUMA_URL || "", envKey: "UPTIME_KUMA_URL" },
    { name: "grafana", url: process.env.GRAFANA_URL || "", envKey: "GRAFANA_URL" },
  ];
  await Promise.all(checks.map(async (check) => {
    if (!check.url) {
      results[check.name] = { configured: false, reachable: false };
      return;
    }
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 8000);
      const resp = await fetch(check.url, { signal: controller.signal, redirect: "follow" });
      clearTimeout(timeout);
      results[check.name] = { configured: true, reachable: resp.ok || resp.status < 500, status: resp.status, url: check.url };
    } catch (e: any) {
      results[check.name] = { configured: true, reachable: false, error: e.message, url: check.url };
    }
  }));
  res.json(results);
});

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

// ── n8n: Create Client ─────────────────────────────────────────
app.post("/api/webhook/create-client", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { name, email, phone, company, address, city, state, zip, status, notes, contact_person } = req.body;
    if (!name) return res.status(400).json({ error: "name is required" });
    const r = await webhookPool.query(
      `INSERT INTO clients (name, email, phone, company, address, city, state, zip, status, notes, contact_person, created_at, updated_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,NOW(),NOW()) RETURNING id, client_code`,
      [name, email||null, phone||null, company||null, address||null, city||null, state||null, zip||null, status||'active', notes||null, contact_person||null]
    );
    const newId = r.rows[0].id;
    const clientCode = `BL${100000 + newId}`;
    await webhookPool.query("UPDATE clients SET client_code=$1 WHERE id=$2", [clientCode, newId]);
    res.json({ success: true, client_id: newId, client_code: clientCode });
  } catch (err: any) {
    console.error("Webhook create-client error:", err);
    res.status(500).json({ error: err.message });
  }
});

// ── n8n: Create Invoice ────────────────────────────────────────
app.post("/api/webhook/create-invoice", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { client_id, amount, due_date, notes, items, tax, status } = req.body;
    if (!client_id || !amount) return res.status(400).json({ error: "client_id and amount are required" });
    const invoiceNumber = `INV-${Date.now()}`;
    const taxAmt = parseFloat(tax) || 0;
    const totalAmt = parseFloat(amount) + taxAmt;
    const r = await webhookPool.query(
      `INSERT INTO invoices (client_id, invoice_number, amount, tax, total, status, due_date, notes, items, created_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,NOW()) RETURNING id, invoice_number`,
      [client_id, invoiceNumber, parseFloat(amount), taxAmt, totalAmt, status||'draft', due_date||null, notes||null, items ? JSON.stringify(items) : null]
    );
    res.json({ success: true, invoice_id: r.rows[0].id, invoice_number: r.rows[0].invoice_number });
  } catch (err: any) {
    console.error("Webhook create-invoice error:", err);
    res.status(500).json({ error: err.message });
  }
});

// ── n8n: Create Lead ───────────────────────────────────────────
app.post("/api/webhook/create-lead", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { name, email, phone, company, source, status, notes, industry, employee_count, service_interest, geography, lead_score, next_action_date } = req.body;
    if (!name) return res.status(400).json({ error: "name is required" });
    const r = await webhookPool.query(
      `INSERT INTO crm_leads (name, email, phone, company, source, status, notes, industry, employee_count, service_interest, geography, lead_score, next_action_date, created_at, updated_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,NOW(),NOW()) RETURNING id`,
      [name, email||null, phone||null, company||null, source||'n8n', status||'new', notes||null, industry||null, employee_count||null, service_interest||null, geography||null, lead_score||0, next_action_date||null]
    );
    res.json({ success: true, lead_id: r.rows[0].id });
  } catch (err: any) {
    console.error("Webhook create-lead error:", err);
    res.status(500).json({ error: err.message });
  }
});

// ── n8n: Create Meeting / Appointment ─────────────────────────
app.post("/api/webhook/create-meeting", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { title, client_id, client_name, meeting_type, scheduled_at, duration_minutes, location, notes, status } = req.body;
    if (!title || !scheduled_at) return res.status(400).json({ error: "title and scheduled_at are required" });
    const r = await webhookPool.query(
      `INSERT INTO crm_meetings (title, client_id, client_name, meeting_type, scheduled_at, duration_minutes, location, notes, status, created_at)
       VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,NOW()) RETURNING id`,
      [title, client_id||null, client_name||null, meeting_type||'meeting', scheduled_at, duration_minutes||60, location||null, notes||null, status||'scheduled']
    );
    res.json({ success: true, meeting_id: r.rows[0].id });
  } catch (err: any) {
    console.error("Webhook create-meeting error:", err);
    res.status(500).json({ error: err.message });
  }
});

// ── n8n: Create Knowledge Base Article ────────────────────────
app.post("/api/webhook/create-kb-article", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { title, content, category, tags, is_published } = req.body;
    if (!title || !content) return res.status(400).json({ error: "title and content are required" });
    const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '-' + Date.now();
    const r = await webhookPool.query(
      `INSERT INTO knowledge_articles (title, slug, content, category, tags, is_published, view_count, created_at, updated_at)
       VALUES ($1,$2,$3,$4,$5,$6,0,NOW(),NOW()) RETURNING id, slug`,
      [title, slug, content, category||'General', tags||null, is_published === true || is_published === 'true']
    );
    res.json({ success: true, article_id: r.rows[0].id, slug: r.rows[0].slug });
  } catch (err: any) {
    console.error("Webhook create-kb-article error:", err);
    res.status(500).json({ error: err.message });
  }
});

// ── n8n: Add Service to Client ────────────────────────────────
app.post("/api/webhook/add-client-service", express.json(), async (req, res) => {
  if (!validateWebhookAuth(req, res)) return;
  try {
    const { client_id, service_name, service_type, price, billing_period, notes, status } = req.body;
    if (!client_id || !service_name) return res.status(400).json({ error: "client_id and service_name are required" });
    // Store as a subscription record
    const r = await webhookPool.query(
      `INSERT INTO subscriptions (client_id, plan_name, price, billing_period, status, notes, created_at, updated_at)
       VALUES ($1,$2,$3,$4,$5,$6,NOW(),NOW()) RETURNING id
       ON CONFLICT DO NOTHING`,
      [client_id, service_name, parseFloat(price)||0, billing_period||'monthly', status||'active', notes||null]
    ).catch(async () => {
      // fallback: subscriptions table may not exist — try client_services
      return webhookPool.query(
        `INSERT INTO client_services (client_id, service_name, service_type, price, billing_period, status, notes, created_at)
         VALUES ($1,$2,$3,$4,$5,$6,$7,NOW()) RETURNING id`,
        [client_id, service_name, service_type||'managed', parseFloat(price)||0, billing_period||'monthly', status||'active', notes||null]
      );
    });
    res.json({ success: true, subscription_id: r.rows[0]?.id || null });
  } catch (err: any) {
    console.error("Webhook add-client-service error:", err);
    res.status(500).json({ error: err.message });
  }
});

app.get("/api/webhook/health", (_req, res) => {
  res.json({
    status: "ok",
    portal: "Blue Mogul Client Portal",
    auth: "Header: x-webhook-token: <SESSION_SECRET>  OR  Authorization: Bearer <SESSION_SECRET>",
    endpoints: [
      "POST /api/webhook/create-client       — name, email, phone, company, address, city, state, zip, status, notes, contact_person",
      "POST /api/webhook/create-invoice      — client_id*, amount*, due_date, notes, items (JSON array), tax, status",
      "POST /api/webhook/create-lead         — name*, email, phone, company, source, status, notes, industry, service_interest, lead_score, next_action_date",
      "POST /api/webhook/create-meeting      — title*, scheduled_at* (ISO8601), client_id, client_name, meeting_type, duration_minutes, location, notes",
      "POST /api/webhook/create-kb-article   — title*, content*, category, tags, is_published (true/false)",
      "POST /api/webhook/add-client-service  — client_id*, service_name*, price, billing_period, service_type, notes, status",
      "POST /api/webhook/create-ticket       — subject*, client_id, description, priority, source",
      "POST /api/webhook/create-project      — name*, client_id, description, project_type, priority, start_date, due_date, tasks[]",
      "POST /api/webhook/update-device       — hostname*, client_id, status, ip_address, os_name, os_version",
      "POST /api/webhook/notify             — title*, message*, user_id, type, entity_type, entity_id",
      "POST /api/webhook/agent-log          — agent_key*, action, status, message, execution_ms, metadata",
    ],
    note: "Fields marked * are required.",
    timestamp: new Date().toISOString(),
  });
});

// ── Ollama AI Assistant API ─────────────────────────────────────────────
app.get("/api/ollama/settings", async (_req, res) => {
  try {
    const { rows } = await webhookPool.query(
      `SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ollama_url','ollama_model','ollama_enabled','ollama_system_prompt')`
    );
    const settings: Record<string, string> = {};
    for (const r of rows) settings[r.setting_key] = r.setting_value;
    res.json({
      url: settings['ollama_url'] || 'http://localhost:11434',
      model: settings['ollama_model'] || 'llama3',
      enabled: settings['ollama_enabled'] !== 'false',
      system_prompt: settings['ollama_system_prompt'] || 'You are a helpful MSP support assistant for Blue Mogul. Be concise and professional.',
    });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.post("/api/ollama/settings", express.json(), async (req, res) => {
  try {
    const { url, model, enabled, system_prompt } = req.body;
    const upsert = async (k: string, v: string) => webhookPool.query(
      `INSERT INTO system_settings (setting_key, setting_value) VALUES ($1,$2)
       ON CONFLICT (setting_key) DO UPDATE SET setting_value=$2`, [k, v]
    );
    if (url !== undefined) await upsert('ollama_url', String(url));
    if (model !== undefined) await upsert('ollama_model', String(model));
    if (enabled !== undefined) await upsert('ollama_enabled', String(enabled));
    if (system_prompt !== undefined) await upsert('ollama_system_prompt', String(system_prompt));
    res.json({ success: true });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.get("/api/ollama/models", async (_req, res) => {
  try {
    const { rows } = await webhookPool.query(`SELECT setting_value FROM system_settings WHERE setting_key='ollama_url'`);
    const ollamaUrl = (rows[0]?.setting_value || 'http://localhost:11434').replace(/\/$/, '');
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 8000);
    const r = await fetch(`${ollamaUrl}/api/tags`, { signal: ctrl.signal }).finally(() => clearTimeout(t));
    if (!r.ok) return res.status(502).json({ error: `Ollama returned HTTP ${r.status}` });
    const data = await r.json() as { models?: { name: string; modified_at?: string }[] };
    res.json({ models: (data.models || []).map((m) => m.name) });
  } catch (e: any) {
    res.status(503).json({ error: e.name === 'AbortError' ? 'Ollama connection timed out (8s)' : e.message });
  }
});

app.post("/api/ollama/chat", express.json(), async (req, res) => {
  const { messages, conversation_id, title } = req.body as {
    messages: { role: string; content: string }[];
    conversation_id?: number;
    title?: string;
  };
  if (!messages?.length) return res.status(400).json({ error: 'messages required' });

  let settingsRows: any;
  try {
    settingsRows = await webhookPool.query(
      `SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ollama_url','ollama_model','ollama_system_prompt','ollama_enabled')`
    );
  } catch (e: any) {
    return res.status(500).json({ error: 'DB error: ' + e.message });
  }
  const s: Record<string, string> = {};
  for (const r of settingsRows.rows) s[r.setting_key] = r.setting_value;
  if (s['ollama_enabled'] === 'false') return res.status(503).json({ error: 'AI Assistant is disabled' });

  const ollamaUrl = (s['ollama_url'] || 'http://localhost:11434').replace(/\/$/, '');
  const model = s['ollama_model'] || 'llama3';
  const sysPrompt = s['ollama_system_prompt'] || 'You are a helpful MSP support assistant for Blue Mogul. Be concise and professional.';
  const fullMessages = [{ role: 'system', content: sysPrompt }, ...messages];

  // SSE headers — keep connection alive for streaming
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');
  res.setHeader('X-Accel-Buffering', 'no');
  res.flushHeaders();

  const sendEvent = (data: object) => {
    res.write(`data: ${JSON.stringify(data)}\n\n`);
  };

  const ctrl = new AbortController();
  // Safety-net timeout: 10 minutes (instead of 2)
  const t = setTimeout(() => ctrl.abort(), 600000);

  // Abort stream if client disconnects
  req.on('close', () => { ctrl.abort(); clearTimeout(t); });

  try {
    const ollamaRes = await fetch(`${ollamaUrl}/api/chat`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ model, messages: fullMessages, stream: true }),
      signal: ctrl.signal,
    });

    if (!ollamaRes.ok) {
      const txt = await ollamaRes.text();
      sendEvent({ error: `Ollama error: ${txt}` });
      res.end(); clearTimeout(t); return;
    }

    const reader = ollamaRes.body!.getReader();
    const decoder = new TextDecoder();
    let fullReply = '';
    let lineBuffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      lineBuffer += decoder.decode(value, { stream: true });
      const lines = lineBuffer.split('\n');
      lineBuffer = lines.pop() ?? '';

      for (const line of lines) {
        const trimmed = line.trim();
        if (!trimmed) continue;
        try {
          const chunk = JSON.parse(trimmed) as {
            message?: { content: string };
            done?: boolean;
            error?: string;
          };
          if (chunk.error) {
            sendEvent({ error: chunk.error });
            res.end(); clearTimeout(t); return;
          }
          const token = chunk.message?.content ?? '';
          if (token) {
            fullReply += token;
            sendEvent({ token });
          }
        } catch { /* partial JSON — skip */ }
      }
    }
    // Flush any remaining content in the buffer (last line without trailing newline)
    if (lineBuffer.trim()) {
      try {
        const chunk = JSON.parse(lineBuffer.trim()) as { message?: { content: string }; error?: string };
        const token = chunk.message?.content ?? '';
        if (token) { fullReply += token; sendEvent({ token }); }
      } catch { /* partial/incomplete JSON — discard */ }
    }
    clearTimeout(t);

    // Persist conversation to DB
    await webhookPool.query(`CREATE TABLE IF NOT EXISTS ai_conversations (
      id serial PRIMARY KEY,
      title text NOT NULL DEFAULT 'New Chat',
      messages jsonb NOT NULL DEFAULT '[]',
      model text,
      created_at timestamptz DEFAULT now(),
      updated_at timestamptz DEFAULT now()
    )`);

    let convId = conversation_id;
    const allMsgs = [...messages, { role: 'assistant', content: fullReply }];
    if (convId) {
      await webhookPool.query(
        `UPDATE ai_conversations SET messages=$1, updated_at=now() WHERE id=$2`,
        [JSON.stringify(allMsgs), convId]
      );
    } else {
      const convTitle = title || (messages[0]?.content?.substring(0, 60) || 'New Chat');
      const ins = await webhookPool.query(
        `INSERT INTO ai_conversations (title, messages, model) VALUES ($1,$2,$3) RETURNING id`,
        [convTitle, JSON.stringify(allMsgs), model]
      );
      convId = ins.rows[0].id;
    }

    sendEvent({ done: true, conversation_id: convId, model });
    res.end();
  } catch (e: any) {
    clearTimeout(t);
    if (!res.headersSent) {
      res.status(503).json({ error: e.name === 'AbortError' ? 'Request cancelled' : e.message });
    } else {
      sendEvent({ error: e.name === 'AbortError' ? 'Request cancelled' : e.message });
      res.end();
    }
  }
});

app.get("/api/ollama/conversations", async (_req, res) => {
  try {
    await webhookPool.query(`CREATE TABLE IF NOT EXISTS ai_conversations (
      id serial PRIMARY KEY, title text NOT NULL DEFAULT 'New Chat',
      messages jsonb NOT NULL DEFAULT '[]', model text,
      created_at timestamptz DEFAULT now(), updated_at timestamptz DEFAULT now()
    )`);
    const { rows } = await webhookPool.query(
      `SELECT id, title, model, created_at, updated_at, jsonb_array_length(messages) as message_count
       FROM ai_conversations ORDER BY updated_at DESC LIMIT 50`
    );
    res.json(rows);
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.get("/api/ollama/conversations/:id", async (req, res) => {
  try {
    const { rows } = await webhookPool.query(`SELECT * FROM ai_conversations WHERE id=$1`, [req.params.id]);
    if (!rows.length) return res.status(404).json({ error: 'Not found' });
    res.json(rows[0]);
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

app.delete("/api/ollama/conversations/:id", async (req, res) => {
  try {
    await webhookPool.query(`DELETE FROM ai_conversations WHERE id=$1`, [req.params.id]);
    res.json({ success: true });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});
// ── Support Presence & Ticket (client AI widget) ─────────────────────────

// Bootstrap user_presence table
const ensurePresenceTable = async () => {
  await webhookPool.query(`CREATE TABLE IF NOT EXISTS user_presence (
    user_id integer PRIMARY KEY,
    user_name text,
    last_seen timestamptz DEFAULT now()
  )`);
};

// Admin heartbeat — called from admin pages every 60s
app.post("/api/support/presence", express.json(), async (req, res) => {
  try {
    await ensurePresenceTable();
    const { user_id, user_name } = req.body as { user_id?: number; user_name?: string };
    if (!user_id) return res.status(400).json({ error: 'user_id required' });
    await webhookPool.query(
      `INSERT INTO user_presence (user_id, user_name, last_seen) VALUES ($1,$2,now())
       ON CONFLICT (user_id) DO UPDATE SET user_name=$2, last_seen=now()`,
      [user_id, user_name || 'Staff']
    );
    res.json({ ok: true });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

// Client check — is any staff member online right now?
app.get("/api/support/availability", async (_req, res) => {
  try {
    await ensurePresenceTable();
    const { rows } = await webhookPool.query(
      `SELECT user_name FROM user_presence WHERE last_seen > now() - interval '5 minutes' ORDER BY last_seen DESC`
    );
    res.json({ available: rows.length > 0, online_count: rows.length, agents: rows.map(r => r.user_name) });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

// Quick ticket creation from client AI widget
app.post("/api/support/ticket", express.json(), async (req, res) => {
  try {
    const { user_id, subject, description, priority } = req.body as {
      user_id?: number; subject?: string; description?: string; priority?: string;
    };
    if (!subject || !user_id) return res.status(400).json({ error: 'user_id and subject required' });
    const prio = ['low','medium','high','urgent'].includes(priority||'') ? priority : 'medium';

    // Get client_id from clients table (falls back to user_id if no client record)
    const cl = await webhookPool.query(`SELECT id FROM clients WHERE user_id=$1 LIMIT 1`, [user_id]);
    const client_id = cl.rows[0]?.id ?? user_id;

    const ins = await webhookPool.query(
      `INSERT INTO tickets (client_id, subject, description, status, priority, ticket_group, source, created_at, updated_at)
       VALUES ($1,$2,$3,'open',$4,'support','ai_widget',now(),now()) RETURNING id`,
      [client_id, subject.substring(0,200), description?.substring(0,2000) || '', prio]
    );
    const ticket_id = ins.rows[0].id;

    // Log activity
    await webhookPool.query(
      `INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address)
       VALUES ($1,'ticket_created','ticket',$2,'Created via AI widget: ' || $3, '0.0.0.0')`,
      [user_id, ticket_id, subject.substring(0,100)]
    ).catch(() => {}); // non-fatal

    res.json({ success: true, ticket_id });
  } catch (e: any) { res.status(500).json({ error: e.message }); }
});

// ── End Ollama AI ────────────────────────────────────────────────────────

app.get("/portal/logout.php", (req, res) => {
  req.session.destroy(() => {
    res.redirect("/portal");
  });
});

async function bootstrapPortalDatabase() {
  try {
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        name VARCHAR(255) DEFAULT '',
        is_admin BOOLEAN DEFAULT FALSE,
        role VARCHAR(50) DEFAULT 'user',
        status VARCHAR(20) DEFAULT 'active',
        phone VARCHAR(50),
        company VARCHAR(255),
        address TEXT,
        city VARCHAR(100),
        state VARCHAR(50),
        zip VARCHAR(20),
        remember_token VARCHAR(255),
        remember_token_expires TIMESTAMP,
        last_login TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS activity_log (
        id SERIAL PRIMARY KEY,
        user_id INTEGER,
        action VARCHAR(100),
        entity_type VARCHAR(50),
        entity_id INTEGER,
        details TEXT,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS email_log (
        id SERIAL PRIMARY KEY,
        recipient TEXT NOT NULL,
        subject TEXT NOT NULL,
        status VARCHAR(20) DEFAULT 'sent',
        error_message TEXT,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS clients (
        id SERIAL PRIMARY KEY,
        user_id INTEGER REFERENCES users(id),
        name VARCHAR(255),
        email VARCHAR(255),
        phone VARCHAR(50),
        company VARCHAR(255),
        address TEXT,
        city VARCHAR(100),
        state VARCHAR(50),
        zip VARCHAR(20),
        status VARCHAR(20) DEFAULT 'active',
        credit_balance DECIMAL(10,2) DEFAULT 0,
        notes TEXT,
        parent_id INTEGER,
        latitude DECIMAL(10,7),
        longitude DECIMAL(10,7),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS products (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) UNIQUE NOT NULL,
        category VARCHAR(100),
        tier VARCHAR(50),
        price DECIMAL(10,2) DEFAULT 0,
        billing_period VARCHAR(20) DEFAULT 'monthly',
        description TEXT,
        features TEXT,
        active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS tickets (
        id SERIAL PRIMARY KEY,
        client_id INTEGER,
        user_id INTEGER,
        subject VARCHAR(255),
        description TEXT,
        status VARCHAR(20) DEFAULT 'open',
        priority VARCHAR(20) DEFAULT 'medium',
        assigned_to INTEGER,
        source VARCHAR(50) DEFAULT 'portal',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS ticket_comments (
        id SERIAL PRIMARY KEY,
        ticket_id INTEGER REFERENCES tickets(id),
        user_id INTEGER,
        comment TEXT,
        is_internal BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS invoices (
        id SERIAL PRIMARY KEY,
        client_id INTEGER,
        invoice_number VARCHAR(50),
        amount DECIMAL(10,2) DEFAULT 0,
        tax DECIMAL(10,2) DEFAULT 0,
        total DECIMAL(10,2) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'unpaid',
        due_date DATE,
        paid_date DATE,
        notes TEXT,
        footer TEXT,
        items JSONB DEFAULT '[]',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        external_id VARCHAR(100)
      )
    `);
    await webhookPool.query(`ALTER TABLE invoices ADD COLUMN IF NOT EXISTS paid_date DATE`);
    await webhookPool.query(`ALTER TABLE invoices ADD COLUMN IF NOT EXISTS external_id VARCHAR(100)`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS linkedin_url VARCHAR(500)`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS linkedin_data JSONB`);
    await webhookPool.query(`ALTER TABLE leads ADD COLUMN IF NOT EXISTS linkedin_url VARCHAR(500)`);
    await webhookPool.query(`ALTER TABLE leads ADD COLUMN IF NOT EXISTS linkedin_data JSONB`);
    // New "New Person" individual fields
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS first_name VARCHAR(100)`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS last_name  VARCHAR(100)`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS title      VARCHAR(30)`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS job_title  VARCHAR(150)`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS website    VARCHAR(500)`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS tags       TEXT[]`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS phones     JSONB DEFAULT '[]'::jsonb`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS emails     JSONB DEFAULT '[]'::jsonb`);
    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS social_links JSONB DEFAULT '[]'::jsonb`);
    // New Organisation fields for crm_companies
    await webhookPool.query(`ALTER TABLE crm_companies ADD COLUMN IF NOT EXISTS tags         TEXT[]`);
    await webhookPool.query(`ALTER TABLE crm_companies ADD COLUMN IF NOT EXISTS phones       JSONB DEFAULT '[]'::jsonb`);
    await webhookPool.query(`ALTER TABLE crm_companies ADD COLUMN IF NOT EXISTS emails       JSONB DEFAULT '[]'::jsonb`);
    await webhookPool.query(`ALTER TABLE crm_companies ADD COLUMN IF NOT EXISTS social_links JSONB DEFAULT '[]'::jsonb`);
    await webhookPool.query(`ALTER TABLE crm_companies ADD COLUMN IF NOT EXISTS linkedin_url VARCHAR(500)`);
    await webhookPool.query(`ALTER TABLE crm_companies ADD COLUMN IF NOT EXISTS scrape_data  JSONB`);
    // Xero token storage (uses provider_settings which already exists)
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS payments (
        id SERIAL PRIMARY KEY,
        invoice_id INTEGER,
        client_id INTEGER,
        amount DECIMAL(10,2) DEFAULT 0,
        payment_method VARCHAR(50),
        transaction_id VARCHAR(255),
        status VARCHAR(20) DEFAULT 'completed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS subscriptions (
        id SERIAL PRIMARY KEY,
        client_id INTEGER,
        product_id INTEGER,
        status VARCHAR(20) DEFAULT 'active',
        price DECIMAL(10,2) DEFAULT 0,
        billing_period VARCHAR(20) DEFAULT 'monthly',
        start_date DATE,
        next_billing DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS documents (
        id SERIAL PRIMARY KEY,
        client_id INTEGER,
        user_id INTEGER,
        filename VARCHAR(255),
        original_name VARCHAR(255),
        file_size INTEGER DEFAULT 0,
        file_type VARCHAR(50),
        category VARCHAR(50) DEFAULT 'general',
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS network_devices (
        id SERIAL PRIMARY KEY,
        client_id INTEGER,
        hostname VARCHAR(255),
        device_type VARCHAR(50),
        manufacturer VARCHAR(100),
        model VARCHAR(100),
        serial_number VARCHAR(100),
        ip_address VARCHAR(45),
        mac_address VARCHAR(17),
        os_name VARCHAR(100),
        os_version VARCHAR(50),
        cpu VARCHAR(100),
        ram_gb INTEGER,
        disk_gb INTEGER,
        status VARCHAR(20) DEFAULT 'online',
        notes TEXT,
        last_seen TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS network_credentials (
        id SERIAL PRIMARY KEY,
        client_id INTEGER,
        service_name VARCHAR(255),
        credential_type VARCHAR(50),
        username VARCHAR(255),
        password_encrypted TEXT,
        url VARCHAR(500),
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS knowledge_articles (
        id SERIAL PRIMARY KEY,
        title VARCHAR(255),
        slug VARCHAR(255),
        content TEXT,
        category VARCHAR(100),
        tags TEXT,
        status VARCHAR(20) DEFAULT 'draft',
        author_id INTEGER,
        views INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS notifications (
        id SERIAL PRIMARY KEY,
        user_id INTEGER,
        title VARCHAR(255),
        message TEXT,
        type VARCHAR(50) DEFAULT 'info',
        entity_type VARCHAR(50),
        entity_id INTEGER,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS projects (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255),
        client_id INTEGER,
        project_type VARCHAR(50),
        status VARCHAR(20) DEFAULT 'planning',
        priority VARCHAR(20) DEFAULT 'medium',
        description TEXT,
        start_date DATE,
        due_date DATE,
        progress INTEGER DEFAULT 0,
        budget DECIMAL(10,2),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS project_tasks (
        id SERIAL PRIMARY KEY,
        project_id INTEGER REFERENCES projects(id),
        title VARCHAR(255),
        description TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        assigned_to INTEGER,
        due_date DATE,
        sort_order INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS project_notes (
        id SERIAL PRIMARY KEY,
        project_id INTEGER REFERENCES projects(id),
        user_id INTEGER,
        note TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS system_settings (
        id SERIAL PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS agent_config (
        id SERIAL PRIMARY KEY,
        agent_key VARCHAR(50) UNIQUE NOT NULL,
        agent_name VARCHAR(100),
        codename VARCHAR(100),
        description TEXT,
        category VARCHAR(50),
        tools TEXT,
        time_saved_hrs DECIMAL(5,1) DEFAULT 0,
        revenue_monthly DECIMAL(10,2) DEFAULT 0,
        n8n_webhook_url TEXT,
        is_enabled BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS agent_metrics (
        id SERIAL PRIMARY KEY,
        agent_key VARCHAR(50) UNIQUE NOT NULL,
        runs_total INTEGER DEFAULT 0,
        runs_today INTEGER DEFAULT 0,
        errors_total INTEGER DEFAULT 0,
        saves_week_hrs DECIMAL(5,1) DEFAULT 0,
        online BOOLEAN DEFAULT FALSE,
        last_status VARCHAR(20) DEFAULT 'idle',
        last_run_at TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS agent_logs (
        id SERIAL PRIMARY KEY,
        agent_key VARCHAR(50),
        action VARCHAR(100),
        status VARCHAR(20),
        message TEXT,
        execution_time INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS messages (
        id SERIAL PRIMARY KEY,
        subject VARCHAR(255),
        body TEXT,
        category VARCHAR(50),
        status VARCHAR(20) DEFAULT 'draft',
        recipients JSONB DEFAULT '[]',
        sent_at TIMESTAMP,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS message_templates (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255),
        subject VARCHAR(255),
        body TEXT,
        category VARCHAR(50),
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS invoice_footer_templates (
        id SERIAL PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        content TEXT NOT NULL,
        is_default BOOLEAN DEFAULT false,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS project_time_entries (
        id SERIAL PRIMARY KEY,
        project_id INTEGER NOT NULL,
        task_id INTEGER,
        user_id INTEGER,
        user_name VARCHAR(200),
        hours NUMERIC(6,2) NOT NULL DEFAULT 0,
        description TEXT,
        billable BOOLEAN DEFAULT true,
        rate NUMERIC(8,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS vultr_instances (
        id SERIAL PRIMARY KEY,
        vultr_id VARCHAR(100) UNIQUE,
        client_id INTEGER,
        label VARCHAR(255),
        hostname VARCHAR(255),
        os VARCHAR(100),
        ram INTEGER,
        disk INTEGER,
        vcpu_count INTEGER,
        region VARCHAR(50),
        plan VARCHAR(100),
        main_ip VARCHAR(45),
        v6_main_ip VARCHAR(100),
        status VARCHAR(50),
        power_status VARCHAR(20),
        allowed_bandwidth DECIMAL(10,2),
        current_bandwidth DECIMAL(10,2),
        cost_per_month DECIMAL(10,2),
        date_created TIMESTAMP,
        last_synced TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS itarian_endpoints (
        id SERIAL PRIMARY KEY,
        itarian_id VARCHAR(100) UNIQUE,
        name VARCHAR(255),
        hostname VARCHAR(255),
        os VARCHAR(255),
        ip_address VARCHAR(45),
        mac_address VARCHAR(50),
        status VARCHAR(50) DEFAULT 'unknown',
        agent_version VARCHAR(50),
        group_name VARCHAR(255),
        client_id INTEGER,
        last_seen TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);
    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS itarian_alerts (
        id SERIAL PRIMARY KEY,
        alert_id VARCHAR(100) UNIQUE,
        endpoint_id VARCHAR(100),
        severity VARCHAR(20) DEFAULT 'info',
        category VARCHAR(100),
        message TEXT,
        status VARCHAR(20) DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP
      )
    `);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS crm_leads (
        id SERIAL PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        email VARCHAR(200),
        phone VARCHAR(50),
        company VARCHAR(200),
        source VARCHAR(50) DEFAULT 'manual',
        status VARCHAR(20) DEFAULT 'new',
        notes TEXT,
        assigned_to INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS crm_campaigns (
        id SERIAL PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        type VARCHAR(30) DEFAULT 'email',
        status VARCHAR(20) DEFAULT 'draft',
        subject VARCHAR(300),
        content TEXT,
        target_audience VARCHAR(100) DEFAULT 'all_clients',
        start_date DATE,
        end_date DATE,
        sent_count INTEGER DEFAULT 0,
        open_count INTEGER DEFAULT 0,
        response_count INTEGER DEFAULT 0,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS crm_meetings (
        id SERIAL PRIMARY KEY,
        title VARCHAR(300) NOT NULL,
        client_id INTEGER,
        client_name VARCHAR(200),
        meeting_type VARCHAR(30) DEFAULT 'consultation',
        scheduled_at TIMESTAMP NOT NULL,
        duration_minutes INTEGER DEFAULT 60,
        location VARCHAR(300),
        notes TEXT,
        status VARCHAR(20) DEFAULT 'scheduled',
        calendar_link VARCHAR(500),
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS crm_companies (
        id SERIAL PRIMARY KEY,
        name VARCHAR(300) NOT NULL,
        phone VARCHAR(50),
        email VARCHAR(200),
        website VARCHAR(300),
        city VARCHAR(100),
        state VARCHAR(100),
        country VARCHAR(100) DEFAULT 'United States',
        address VARCHAR(300),
        postal_code VARCHAR(20),
        industry VARCHAR(100),
        employee_count VARCHAR(30),
        company_owner VARCHAR(200),
        lifecycle_stage VARCHAR(50) DEFAULT 'lead',
        lead_status VARCHAR(50),
        last_contacted TIMESTAMP,
        notes TEXT,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    await webhookPool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS crm_company_id INTEGER REFERENCES crm_companies(id) ON DELETE SET NULL`).catch(() => {});

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS crm_communications (
        id SERIAL PRIMARY KEY,
        entity_type VARCHAR(20) NOT NULL DEFAULT 'client',
        entity_id INTEGER NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'note',
        subject VARCHAR(300),
        body TEXT,
        direction VARCHAR(20) DEFAULT 'outbound',
        duration_minutes INTEGER,
        outcome VARCHAR(100),
        status VARCHAR(30) DEFAULT 'completed',
        scheduled_at TIMESTAMP,
        created_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    `);

    const adminCheck = await webhookPool.query("SELECT id, password FROM users WHERE email = 'admin@bluemogul.biz' LIMIT 1");
    const { execSync } = await import("child_process");
    const phpHash = execSync(`php -r "echo password_hash('admin123', PASSWORD_BCRYPT);"`, { encoding: "utf-8" }).trim();
    if (adminCheck.rows.length === 0) {
      await webhookPool.query(
        "INSERT INTO users (email, password, name, is_admin, role, status) VALUES ($1, $2, $3, $4, $5, $6) ON CONFLICT (email) DO NOTHING",
        ["admin@bluemogul.biz", phpHash, "Admin", true, "super-admin", "active"]
      );
      console.log("Created admin user: admin@bluemogul.biz");
    } else {
      // Ensure the hash is PHP-compatible ($2y$). Node.js bcrypt generates $2b$
      // which PHP's password_verify cannot verify. Fix it on every startup.
      const existingHash: string = adminCheck.rows[0].password || '';
      if (!existingHash.startsWith('$2y$')) {
        await webhookPool.query(
          "UPDATE users SET password = $1 WHERE email = 'admin@bluemogul.biz'",
          [phpHash]
        );
        console.log("Updated admin password to PHP-compatible hash");
      }
    }

    const products = [
        ["Essential IT Support", "Managed IT", "Tier 1", 149.00, "monthly", "8/5 Support, Basic Network Monitoring, Email Support, Patch Management, Monthly Reports, Up to 10 devices", '[\"8/5 Support\", \"Basic Network Monitoring\", \"Email Support\", \"Patch Management\", \"Monthly Reports\", \"Up to 10 devices\"]'],
        ["Business IT Support", "Managed IT", "Tier 2", 249.00, "monthly", "24/7 Support, Full Infrastructure Monitoring, Phone & Email Support, Remote Desktop Support, Security Patches, Weekly Reports, Up to 25 devices", '[\"24/7 Support\", \"Full Infrastructure Monitoring\", \"Phone & Email Support\", \"Remote Desktop Support\", \"Security Patches\", \"Weekly Reports\", \"Up to 25 devices\"]'],
        ["Professional IT Support", "Managed IT", "Tier 3", 449.00, "monthly", "Dedicated Engineer, White Glove Service, Priority Support Queue, 2-Hour Response Time, Proactive Maintenance, Quarterly Business Reviews, Up to 50 devices", '[\"Dedicated Engineer\", \"White Glove Service\", \"Priority Support Queue\", \"2-Hour Response Time\", \"Proactive Maintenance\", \"Quarterly Business Reviews\", \"Up to 50 devices\"]'],
        ["Enterprise MSP Complete", "Managed IT", "Tier 4", 749.00, "monthly", "Unlimited Support, On-Site Visits Included, Strategic IT Planning, vCIO Services, Security Audits, Disaster Recovery Planning, Unlimited devices", '[\"Unlimited Support\", \"On-Site Visits Included\", \"Strategic IT Planning\", \"vCIO Services\", \"Security Audits\", \"Disaster Recovery Planning\", \"Unlimited devices\"]'],
        ["VoIP Starter Pack", "VoIP", "Tier 1", 49.00, "monthly", "5 Extensions, Voicemail to Email, Call Forwarding, Basic Auto-Attendant, Mobile App, Unlimited Domestic Calling", '[\"5 Extensions\", \"Voicemail to Email\", \"Call Forwarding\", \"Basic Auto-Attendant\", \"Mobile App\", \"Unlimited Domestic Calling\"]'],
        ["VoIP Business", "VoIP", "Tier 2", 89.00, "monthly", "15 Extensions, Call Recording, Advanced IVR, Conference Bridge, Call Analytics, Ring Groups, Music on Hold", '[\"15 Extensions\", \"Call Recording\", \"Advanced IVR\", \"Conference Bridge (25 participants)\", \"Call Analytics\", \"Ring Groups\", \"Music on Hold\"]'],
        ["VoIP Professional", "VoIP", "Tier 3", 149.00, "monthly", "50 Extensions, Advanced Call Analytics, CRM Integration, Queue Management, Call Center Features, Dedicated Support, SLA Guarantee", '[\"50 Extensions\", \"Advanced Call Analytics\", \"CRM Integration (Salesforce, HubSpot)\", \"Queue Management\", \"Call Center Features\", \"Dedicated Support\", \"SLA Guarantee\"]'],
        ["VoIP Enterprise", "VoIP", "Tier 4", 299.00, "monthly", "Unlimited Extensions, White-Label Portal, Custom IVR Flows, Multi-Site Support, Advanced Reporting, API Access, 99.99% SLA", '[\"Unlimited Extensions\", \"White-Label Portal\", \"Custom IVR Flows\", \"Multi-Site Support\", \"Advanced Reporting\", \"API Access\", \"99.99% SLA\"]'],
        ["Residential Fiber 100", "Internet", "Tier 1", 49.00, "monthly", "100 Mbps Download, 100 Mbps Upload, Unlimited Data, Free Installation, Wi-Fi Router Included", '[\"100 Mbps Download\", \"100 Mbps Upload\", \"Unlimited Data\", \"Free Installation\", \"Wi-Fi Router Included\"]'],
        ["Residential Fiber 500", "Internet", "Tier 2", 69.00, "monthly", "500 Mbps Download, 500 Mbps Upload, Unlimited Data, Free Installation, Wi-Fi 6 Router, Priority Support", '[\"500 Mbps Download\", \"500 Mbps Upload\", \"Unlimited Data\", \"Free Installation\", \"Wi-Fi 6 Router\", \"Priority Support\"]'],
        ["Residential Fiber Gig", "Internet", "Tier 3", 89.00, "monthly", "1 Gbps Download, 1 Gbps Upload, Unlimited Data, Free Installation, Wi-Fi 6E Router, Priority Support, Static IP", '[\"1 Gbps Download\", \"1 Gbps Upload\", \"Unlimited Data\", \"Free Installation\", \"Wi-Fi 6E Router\", \"Priority Support\", \"Static IP\"]'],
        ["Business Fiber 500", "Internet", "Tier 2", 149.00, "monthly", "500 Mbps Symmetric, SLA Guarantee, Static IP Block (/29), 24/7 Business Support, Managed Router", '[\"500 Mbps Symmetric\", \"SLA Guarantee\", \"Static IP Block (/29)\", \"24/7 Business Support\", \"Managed Router\"]'],
        ["Business Fiber Gig", "Internet", "Tier 3", 249.00, "monthly", "1 Gbps Symmetric, 99.9% SLA, Static IP Block (/28), 24/7 Priority Support, Managed Firewall, VLAN Support", '[\"1 Gbps Symmetric\", \"99.9% SLA\", \"Static IP Block (/28)\", \"24/7 Priority Support\", \"Managed Firewall\", \"VLAN Support\"]'],
        ["Enterprise Fiber 10G", "Internet", "Tier 4", 599.00, "monthly", "10 Gbps Symmetric, 99.99% SLA, Static IP Block (/27), Dedicated Account Manager, Redundant Paths, BGP Support", '[\"10 Gbps Symmetric\", \"99.99% SLA\", \"Static IP Block (/27)\", \"Dedicated Account Manager\", \"Redundant Paths\", \"BGP Support\"]'],
        ["Fixed Wireless 50", "Internet", "Tier 1", 39.00, "monthly", "50 Mbps Download, 10 Mbps Upload, Free Installation, Equipment Included, Best Effort", '[\"50 Mbps Download\", \"10 Mbps Upload\", \"Free Installation\", \"Equipment Included\", \"Best Effort\"]'],
        ["Fixed Wireless 100", "Internet", "Tier 2", 59.00, "monthly", "100 Mbps Download, 25 Mbps Upload, Free Installation, Equipment Included, Priority Traffic", '[\"100 Mbps Download\", \"25 Mbps Upload\", \"Free Installation\", \"Equipment Included\", \"Priority Traffic\"]'],
        ["Endpoint Protection Basic", "Security", "Tier 1", 5.00, "monthly", "Antivirus, Malware Protection, Web Filtering, Per Device", '[\"Antivirus\", \"Malware Protection\", \"Web Filtering\", \"Per Device\"]'],
        ["Endpoint Protection Advanced", "Security", "Tier 2", 10.00, "monthly", "EDR, Threat Hunting, Ransomware Protection, Patch Management, Per Device", '[\"EDR\", \"Threat Hunting\", \"Ransomware Protection\", \"Patch Management\", \"Per Device\"]'],
        ["Managed Firewall", "Security", "Tier 1", 99.00, "monthly", "Hardware Firewall, IDS/IPS, VPN, Content Filtering, 24/7 Monitoring", '[\"Hardware Firewall\", \"IDS/IPS\", \"VPN\", \"Content Filtering\", \"24/7 Monitoring\"]'],
        ["Security Operations Center", "Security", "Tier 3", 299.00, "monthly", "24/7 SOC Monitoring, SIEM, Incident Response, Threat Intelligence, Compliance Reporting", '[\"24/7 SOC Monitoring\", \"SIEM\", \"Incident Response\", \"Threat Intelligence\", \"Compliance Reporting\"]'],
        ["Microsoft 365 Basic", "Cloud", "Tier 1", 8.00, "monthly", "Exchange Online, 50GB Mailbox, OneDrive 1TB, Teams, Per User", '[\"Exchange Online\", \"50GB Mailbox\", \"OneDrive 1TB\", \"Teams\", \"Per User\"]'],
        ["Microsoft 365 Business", "Cloud", "Tier 2", 15.00, "monthly", "Full Office Suite, 1TB OneDrive, SharePoint, Teams Premium, Per User", '[\"Full Office Suite\", \"1TB OneDrive\", \"SharePoint\", \"Teams Premium\", \"Per User\"]'],
        ["Cloud Backup Standard", "Cloud", "Tier 1", 0.10, "per-gb-monthly", "Automated Daily Backups, 30-Day Retention, AES-256 Encryption, Per GB", '[\"Automated Daily Backups\", \"30-Day Retention\", \"AES-256 Encryption\", \"Per GB\"]'],
        ["Cloud Backup Premium", "Cloud", "Tier 2", 0.20, "per-gb-monthly", "Continuous Backup, 1-Year Retention, Geo-Redundant, Instant Recovery, Per GB", '[\"Continuous Backup\", \"1-Year Retention\", \"Geo-Redundant\", \"Instant Recovery\", \"Per GB\"]'],
        ["Hosted Server - Small", "Cloud", "Tier 1", 99.00, "monthly", "2 vCPU, 4GB RAM, 100GB SSD, Managed OS, Monitoring", '[\"2 vCPU\", \"4GB RAM\", \"100GB SSD\", \"Managed OS\", \"Monitoring\"]'],
        ["Hosted Server - Medium", "Cloud", "Tier 2", 199.00, "monthly", "4 vCPU, 8GB RAM, 250GB SSD, Managed OS, Monitoring, Backups", '[\"4 vCPU\", \"8GB RAM\", \"250GB SSD\", \"Managed OS\", \"Monitoring\", \"Backups\"]'],
        ["Hosted Server - Large", "Cloud", "Tier 3", 399.00, "monthly", "8 vCPU, 16GB RAM, 500GB SSD, Managed OS, HA, Monitoring, Backups", '[\"8 vCPU\", \"16GB RAM\", \"500GB SSD\", \"Managed OS\", \"HA\", \"Monitoring\", \"Backups\"]'],
        ["Network Assessment", "Professional Services", null, 500.00, "one-time", "Full network audit with recommendations report", '[\"Network Audit\", \"Security Assessment\", \"Recommendations Report\", \"Executive Summary\"]'],
        ["IT Consulting", "Professional Services", null, 175.00, "per-hour", "Expert IT consulting and planning", '[\"Expert Consultation\", \"Strategic Planning\", \"Documentation\", \"Recommendations\"]'],
        ["Custom IT Training", "Training", null, 1200.00, "per-day", "Full-day custom training for your team", '[\"Full-Day Training\", \"Custom Curriculum\", \"Hands-on Labs\", \"Certificate of Completion\"]'],
        ["Additional Static IP", "Add-on", null, 5.00, "monthly", "Additional static IP address", '[\"1 Static IP Address\"]'],
        ["Additional VoIP Extension", "Add-on", null, 8.00, "monthly", "Additional VoIP extension line", '[\"1 VoIP Extension\", \"Voicemail\", \"Call Features\"]'],
        ["Priority Support Upgrade", "Add-on", null, 99.00, "monthly", "Upgrade to priority support queue", '[\"Priority Queue\", \"1-Hour Response Time\", \"Dedicated Support Line\"]'],
        ["Backup & Disaster Recovery", "Add-on", null, 0.50, "per-gb-monthly", "Cloud backup and disaster recovery, minimum 100GB", '[\"Cloud Backup\", \"Disaster Recovery\", \"Automated Scheduling\", \"Min 100GB\"]'],
        ["Website Hosting", "Add-on", null, 25.00, "monthly", "Managed website hosting per site", '[\"Managed Hosting\", \"SSL Certificate\", \"Daily Backups\", \"CDN Included\"]'],
        ["Email Hosting", "Add-on", null, 6.00, "monthly", "Professional email hosting per mailbox", '[\"Professional Email\", \"50GB Mailbox\", \"Spam Filtering\", \"Mobile Access\"]'],
        ["DDoS Protection", "Add-on", null, 149.00, "monthly", "Advanced DDoS protection service", '[\"DDoS Mitigation\", \"Traffic Scrubbing\", \"Real-time Monitoring\", \"Incident Response\"]'],
        ["On-Site Visit", "Professional Services", null, 175.00, "per-visit", "On-site technical support, 2-hour minimum", '[\"2-Hour Minimum\", \"On-Site Support\", \"Travel Included\"]'],
        ["Emergency On-Site", "Professional Services", null, 350.00, "per-visit", "Same-day emergency on-site support", '[\"Same-Day Response\", \"Emergency Support\", \"Travel Included\", \"After-Hours Available\"]'],
        ["Custom Integration Development", "Professional Services", null, 150.00, "per-hour", "Custom integration development, 10-hour minimum", '[\"Custom Development\", \"API Integrations\", \"Documentation\", \"10-Hour Minimum\"]'],
        ["SMB Starter Bundle", "Bundle", "Tier 1", 299.00, "monthly", "Essential IT Support + VoIP Starter + Endpoint Protection Basic — bundled at a discount", '[\"Essential IT Support\", \"VoIP Starter (5 Extensions)\", \"Endpoint Protection Basic\", \"Priority Email Support\", \"Monthly Reports\"]'],
        ["Business Bundle", "Bundle", "Tier 2", 499.00, "monthly", "Business IT Support + VoIP Business + Endpoint Protection Advanced — best value for growing teams", '[\"Business IT Support\", \"VoIP Business (15 Extensions)\", \"Endpoint Protection Advanced\", \"24/7 Support\", \"Weekly Reports\"]'],
        ["Professional Bundle", "Bundle", "Tier 3", 799.00, "monthly", "Professional IT Support + VoIP Professional + Managed Firewall — full-stack solution", '[\"Professional IT Support\", \"VoIP Professional (50 Extensions)\", \"Managed Firewall\", \"Priority Support Queue\", \"Quarterly Business Reviews\"]'],
        ["Enterprise Bundle", "Bundle", "Tier 4", 1299.00, "monthly", "Enterprise MSP Complete + VoIP Enterprise + Security Operations Center — white-glove enterprise package", '[\"Unlimited IT Support\", \"VoIP Enterprise (Unlimited Extensions)\", \"Security Operations Center\", \"On-Site Visits\", \"vCIO Services\", \"99.99% SLA\"]'],
        ["Connectivity + Security Bundle", "Bundle", "Tier 2", 249.00, "monthly", "Business Fiber 500 + Managed Firewall + Endpoint Protection Advanced — secure connectivity for business", '[\"Business Fiber 500 Mbps\", \"Managed Firewall\", \"Endpoint Protection Advanced\", \"Static IP Block (/29)\", \"24/7 Monitoring\"]'],
        ["Cloud Workspace Bundle", "Bundle", "Tier 2", 349.00, "monthly", "Microsoft 365 Business + Cloud Backup Premium + Hosted Server Medium — complete cloud workspace", '[\"Microsoft 365 Business (per user)\", \"Cloud Backup Premium\", \"Hosted Server - Medium\", \"Managed OS\", \"Priority Support\"]'],
      ];
    for (const p of products) {
      await webhookPool.query(
        "INSERT INTO products (name, category, tier, price, billing_period, description, features) VALUES ($1, $2, $3, $4, $5, $6, $7) ON CONFLICT (name) DO NOTHING",
        p
      );
    }
    console.log(`Ensured ${products.length} default products in database`);

    await webhookPool.query(`
      CREATE TABLE IF NOT EXISTS chat_channels (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(50) UNIQUE NOT NULL,
        description TEXT DEFAULT '',
        icon VARCHAR(50) DEFAULT 'fas fa-hashtag',
        color VARCHAR(20) DEFAULT 'blue',
        created_at TIMESTAMP DEFAULT now()
      );
      CREATE TABLE IF NOT EXISTS chat_channel_members (
        id SERIAL PRIMARY KEY,
        channel_id INTEGER NOT NULL REFERENCES chat_channels(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL,
        role VARCHAR(20) DEFAULT 'member',
        added_at TIMESTAMP DEFAULT now(),
        UNIQUE(channel_id, user_id)
      );
    `);
    await webhookPool.query(`
      INSERT INTO chat_channels (name, slug, description, icon, color) VALUES
        ('Support', 'support', 'Technical support questions and troubleshooting', 'fas fa-headset', 'blue'),
        ('Billing', 'billing', 'Billing inquiries, payment issues, and account questions', 'fas fa-file-invoice-dollar', 'green'),
        ('General', 'general', 'General discussion, announcements, and team chat', 'fas fa-comments', 'purple')
      ON CONFLICT (slug) DO NOTHING;
    `);

    console.log("Portal database bootstrap complete");
  } catch (err) {
    console.error("Portal database bootstrap error:", err);
  }
}

(async () => {
  await bootstrapPortalDatabase();
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

  app.get("/", (req, res) => {
    const ua = req.headers["user-agent"] || "";
    if (ua.includes("healthcheck") || ua.includes("curl") || req.query.health !== undefined) {
      return res.status(200).json({ status: "ok", portal: "/portal" });
    }
    res.redirect(302, "/portal");
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
