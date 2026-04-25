import type { Express } from "express";
import { createServer, type Server } from "http";
import { storage } from "./storage";
import { insertSnippetSchema, insertContactSchema, insertAssetSchema, contacts, assets, tickets, invoices, clients } from "@shared/schema";
import { execFile } from "child_process";
import { writeFile, unlink } from "fs/promises";
import { tmpdir } from "os";
import { join } from "path";
import { randomUUID } from "crypto";
import { getUncachableStripeClient, getStripePublishableKey } from "./stripeClient";
import { db } from "./db";
import { sql, eq, and } from "drizzle-orm";

const DISABLED_FUNCTIONS = [
  "exec", "shell_exec", "system", "passthru", "popen", "proc_open",
  "pcntl_exec", "pcntl_fork", "pcntl_signal", "pcntl_waitpid",
  "curl_init", "curl_exec", "curl_multi_init",
  "file_get_contents", "file_put_contents", "fopen", "fwrite", "fread",
  "readfile", "file", "glob", "scandir", "opendir",
  "unlink", "rmdir", "mkdir", "rename", "copy", "chmod", "chown",
  "symlink", "link", "tempnam", "tmpfile",
  "phpinfo", "getenv", "putenv", "getmypid", "getmyuid",
  "dl", "ini_set", "ini_get", "ini_restore",
  "socket_create", "socket_connect", "socket_write", "socket_read",
  "fsockopen", "pfsockopen", "stream_socket_client", "stream_socket_server",
  "mail", "header",
].join(",");

const rateLimitMap = new Map<string, number[]>();
const RATE_LIMIT = 20;
const RATE_WINDOW = 60000;

function checkRateLimit(ip: string): boolean {
  const now = Date.now();
  const timestamps = rateLimitMap.get(ip) || [];
  const recent = timestamps.filter((t) => now - t < RATE_WINDOW);
  if (recent.length >= RATE_LIMIT) return false;
  recent.push(now);
  rateLimitMap.set(ip, recent);
  return true;
}

export async function registerRoutes(
  httpServer: Server,
  app: Express
): Promise<Server> {
  app.post("/api/execute", async (req, res) => {
    try {
      const clientIp = req.ip || req.socket.remoteAddress || "unknown";
      if (!checkRateLimit(clientIp)) {
        return res.status(429).json({ error: "Too many requests. Please wait a moment." });
      }

      const { code } = req.body;
      if (!code || typeof code !== "string") {
        return res.status(400).json({ error: "Code is required" });
      }

      if (code.length > 50000) {
        return res.status(400).json({ error: "Code too long (max 50KB)" });
      }

      const filename = join(tmpdir(), `php_${randomUUID()}.php`);
      await writeFile(filename, code);

      const startTime = Date.now();

      const result = await new Promise<{ output: string; executionTime: number }>((resolve) => {
        const timeout = 10000;
        const child = execFile(
          "php",
          [
            "-d", `disable_functions=${DISABLED_FUNCTIONS}`,
            "-d", `open_basedir=${tmpdir()}`,
            "-d", "allow_url_fopen=0",
            "-d", "allow_url_include=0",
            "-d", "memory_limit=64M",
            "-d", "max_execution_time=10",
            filename,
          ],
          { timeout, maxBuffer: 1024 * 1024 },
          (error, stdout, stderr) => {
            const executionTime = Date.now() - startTime;
            unlink(filename).catch(() => {});

            if (error) {
              if (error.killed) {
                resolve({ output: "Error: Execution timed out (10s limit)", executionTime });
              } else {
                const errorOutput = stderr || error.message;
                resolve({ output: errorOutput, executionTime });
              }
            } else {
              resolve({ output: stdout + (stderr ? "\n" + stderr : ""), executionTime });
            }
          }
        );
      });

      res.json(result);
    } catch (error: any) {
      res.status(500).json({ error: "Execution failed" });
    }
  });

  app.get("/api/snippets", async (_req, res) => {
    const snippets = await storage.getSnippets();
    res.json(snippets);
  });

  app.get("/api/snippets/:id", async (req, res) => {
    const id = parseInt(req.params.id);
    if (isNaN(id)) return res.status(400).json({ error: "Invalid ID" });
    const snippet = await storage.getSnippet(id);
    if (!snippet) return res.status(404).json({ error: "Snippet not found" });
    res.json(snippet);
  });

  app.post("/api/snippets", async (req, res) => {
    const parsed = insertSnippetSchema.safeParse(req.body);
    if (!parsed.success) {
      return res.status(400).json({ error: parsed.error.message });
    }
    const snippet = await storage.createSnippet(parsed.data);
    res.status(201).json(snippet);
  });

  app.delete("/api/snippets/:id", async (req, res) => {
    const id = parseInt(req.params.id);
    if (isNaN(id)) return res.status(400).json({ error: "Invalid ID" });
    await storage.deleteSnippet(id);
    res.status(204).send();
  });

  app.get("/api/stripe/publishable-key", async (_req, res) => {
    try {
      const key = await getStripePublishableKey();
      res.json({ publishableKey: key });
    } catch (error: any) {
      res.status(500).json({ error: "Failed to get Stripe publishable key" });
    }
  });

  app.get("/api/stripe/products", async (_req, res) => {
    try {
      const result = await db.execute(
        sql`SELECT p.id, p.name, p.description, p.metadata, p.active,
            pr.id as price_id, pr.unit_amount, pr.currency, pr.recurring, pr.active as price_active
            FROM stripe.products p
            LEFT JOIN stripe.prices pr ON pr.product = p.id AND pr.active = true
            WHERE p.active = true
            ORDER BY p.id, pr.unit_amount`
      );

      const productsMap = new Map();
      for (const row of result.rows) {
        if (!productsMap.has(row.id)) {
          productsMap.set(row.id, {
            id: row.id,
            name: row.name,
            description: row.description,
            metadata: row.metadata,
            active: row.active,
            prices: [],
          });
        }
        if (row.price_id) {
          productsMap.get(row.id).prices.push({
            id: row.price_id,
            unit_amount: row.unit_amount,
            currency: row.currency,
            recurring: row.recurring,
            active: row.price_active,
          });
        }
      }

      res.json({ data: Array.from(productsMap.values()) });
    } catch (error: any) {
      res.status(500).json({ error: "Failed to fetch products" });
    }
  });

  app.post("/api/stripe/checkout", async (req, res) => {
    try {
      const { priceId } = req.body;
      if (!priceId) {
        return res.status(400).json({ error: "priceId is required" });
      }

      const stripe = await getUncachableStripeClient();
      const session = await stripe.checkout.sessions.create({
        payment_method_types: ["card"],
        line_items: [{ price: priceId, quantity: 1 }],
        mode: "subscription",
        success_url: `${req.protocol}://${req.get("host")}/checkout/success`,
        cancel_url: `${req.protocol}://${req.get("host")}/checkout/cancel`,
      });

      res.json({ url: session.url });
    } catch (error: any) {
      res.status(500).json({ error: "Failed to create checkout session" });
    }
  });

  app.post("/api/create-checkout-session", async (req, res) => {
    try {
      const { invoice_id } = req.body;
      if (!invoice_id) {
        return res.status(400).json({ error: "invoice_id is required" });
      }

      const invoiceResult = await db.execute(
        sql`SELECT id, amount, total, invoice_number, status, client_id FROM invoices WHERE id = ${Number(invoice_id)} AND status = 'unpaid'`
      );
      if (!invoiceResult.rows.length) {
        return res.status(404).json({ error: "Invoice not found or already paid" });
      }
      const invoice = invoiceResult.rows[0] as any;
      const serverAmount = parseFloat(invoice.total || invoice.amount);
      const invoiceNumber = invoice.invoice_number;

      try {
        const stripe = await getUncachableStripeClient();
        const session = await stripe.checkout.sessions.create({
          line_items: [{
            price_data: {
              currency: "usd",
              product_data: {
                name: `Invoice ${invoiceNumber}`,
                description: `Payment for invoice ${invoiceNumber}`,
              },
              unit_amount: Math.round(serverAmount * 100),
            },
            quantity: 1,
          }],
          mode: "payment",
          billing_address_collection: "required",
          metadata: { invoice_id: String(invoice_id) },
          success_url: `${req.protocol}://${req.get("host")}/portal/payment-success.php?id=${invoice_id}&paid=1`,
          cancel_url: `${req.protocol}://${req.get("host")}/portal/billing.php`,
        });

        res.json({ url: session.url });
      } catch (stripeError: any) {
        const msg: string = stripeError.message || '';
        const isNotConfigured = msg.includes('not configured') || msg.includes('STRIPE_');
        if (isNotConfigured) {
          console.log("Stripe not configured, using demo mode:", msg);
          res.json({ demo: true, message: "Demo payment - Stripe not fully configured" });
        } else {
          console.error("Stripe checkout error:", msg);
          res.status(500).json({ error: "Stripe error: " + msg });
        }
      }
    } catch (error: any) {
      console.error("Checkout session error:", error);
      res.status(500).json({ error: "Failed to create checkout session" });
    }
  });

  // ── Webhook auth middleware ───────────────────────────────────────────────
  function requireWebhookToken(req: any, res: any, next: any) {
    const token = req.headers["x-webhook-token"];
    const secret = process.env.SESSION_SECRET || "bluemogul-portal-secret";
    if (!token || token !== secret) {
      return res.status(401).json({ error: "Unauthorized" });
    }
    next();
  }

  // ── SLA helper ────────────────────────────────────────────────────────────
  function computeSlaDueAt(priority: string): Date {
    const now = new Date();
    const hoursMap: Record<string, number> = {
      critical: 1,
      high: 4,
      medium: 24,
      low: 72,
    };
    const hours = hoursMap[priority] ?? 24;
    now.setHours(now.getHours() + hours);
    return now;
  }

  // ── POST /api/webhook/add-asset ───────────────────────────────────────────
  app.post("/api/webhook/add-asset", requireWebhookToken, async (req, res) => {
    const parsed = insertAssetSchema.safeParse(req.body);
    if (!parsed.success) {
      return res.status(400).json({ error: parsed.error.message });
    }
    const [asset] = await db.insert(assets).values(parsed.data).returning();
    res.status(201).json(asset);
  });

  // ── Admin tickets ─────────────────────────────────────────────────────────
  app.get("/api/admin/tickets", async (req, res) => {
    const rows = await db.execute(
      sql`SELECT t.*, c.name as client_name FROM tickets t LEFT JOIN clients c ON t.client_id = c.id ORDER BY t.created_at DESC`
    );
    const byStatus: Record<string, any[]> = { open: [], in_progress: [], resolved: [] };
    for (const row of rows.rows as any[]) {
      const s = row.status === "closed" ? "resolved" : (row.status as string);
      if (!byStatus[s]) byStatus[s] = [];
      byStatus[s].push(row);
    }
    res.json(byStatus);
  });

  app.post("/api/admin/tickets", async (req, res) => {
    const { clientId, subject, description, priority = "medium", assignedTo, source = "admin" } = req.body;
    if (!subject) return res.status(400).json({ error: "subject is required" });
    const slaDueAt = computeSlaDueAt(priority);
    const [ticket] = await db.execute(
      sql`INSERT INTO tickets (client_id, subject, description, status, priority, assigned_to, source, sla_due_at, created_at, updated_at)
          VALUES (${clientId ?? null}, ${subject}, ${description ?? null}, 'open', ${priority}, ${assignedTo ?? null}, ${source}, ${slaDueAt.toISOString()}, NOW(), NOW())
          RETURNING *`
    );
    res.status(201).json((ticket as any).rows?.[0] ?? ticket);
  });

  app.get("/api/admin/tickets/:id", async (req, res) => {
    const id = parseInt(req.params.id);
    if (isNaN(id)) return res.status(400).json({ error: "Invalid ID" });
    const result = await db.execute(
      sql`SELECT t.*, c.name as client_name FROM tickets t LEFT JOIN clients c ON t.client_id = c.id WHERE t.id = ${id}`
    );
    if (!result.rows.length) return res.status(404).json({ error: "Ticket not found" });
    res.json(result.rows[0]);
  });

  // ── Client tickets ────────────────────────────────────────────────────────
  app.get("/api/client/tickets", async (req, res) => {
    const clientId = (req as any).session?.portalUser?.client_id;
    if (!clientId) return res.status(401).json({ error: "Not authenticated" });
    const result = await db.execute(
      sql`SELECT * FROM tickets WHERE client_id = ${clientId} ORDER BY created_at DESC`
    );
    res.json(result.rows);
  });

  app.post("/api/client/tickets", async (req, res) => {
    const clientId = (req as any).session?.portalUser?.client_id;
    if (!clientId) return res.status(401).json({ error: "Not authenticated" });
    const { subject, description, priority = "medium" } = req.body;
    if (!subject) return res.status(400).json({ error: "subject is required" });
    const slaDueAt = computeSlaDueAt(priority);
    const result = await db.execute(
      sql`INSERT INTO tickets (client_id, subject, description, status, priority, source, sla_due_at, created_at, updated_at)
          VALUES (${clientId}, ${subject}, ${description ?? null}, 'open', ${priority}, 'portal', ${slaDueAt.toISOString()}, NOW(), NOW())
          RETURNING *`
    );
    res.status(201).json(result.rows[0]);
  });

  // ── Admin contacts ────────────────────────────────────────────────────────
  app.get("/api/admin/clients/:id/contacts", async (req, res) => {
    const clientId = parseInt(req.params.id);
    if (isNaN(clientId)) return res.status(400).json({ error: "Invalid client ID" });
    const result = await db.select().from(contacts).where(eq(contacts.clientId, clientId));
    res.json(result);
  });

  app.post("/api/admin/clients/:id/contacts", async (req, res) => {
    const clientId = parseInt(req.params.id);
    if (isNaN(clientId)) return res.status(400).json({ error: "Invalid client ID" });
    const parsed = insertContactSchema.safeParse({ ...req.body, clientId });
    if (!parsed.success) return res.status(400).json({ error: parsed.error.message });
    const [contact] = await db.insert(contacts).values(parsed.data).returning();
    res.status(201).json(contact);
  });

  // ── Admin assets ──────────────────────────────────────────────────────────
  app.get("/api/admin/clients/:id/assets", async (req, res) => {
    const clientId = parseInt(req.params.id);
    if (isNaN(clientId)) return res.status(400).json({ error: "Invalid client ID" });
    const result = await db.select().from(assets).where(eq(assets.clientId, clientId));
    res.json(result);
  });

  app.post("/api/admin/clients/:id/assets", async (req, res) => {
    const clientId = parseInt(req.params.id);
    if (isNaN(clientId)) return res.status(400).json({ error: "Invalid client ID" });
    const parsed = insertAssetSchema.safeParse({ ...req.body, clientId });
    if (!parsed.success) return res.status(400).json({ error: parsed.error.message });
    const [asset] = await db.insert(assets).values(parsed.data).returning();
    res.status(201).json(asset);
  });

  // ── Admin invoices ────────────────────────────────────────────────────────
  app.get("/api/admin/invoices", async (_req, res) => {
    const result = await db.execute(
      sql`SELECT i.*, c.name as client_name FROM invoices i LEFT JOIN clients c ON i.client_id = c.id ORDER BY i.created_at DESC`
    );
    res.json(result.rows);
  });

  app.get("/api/admin/invoices/:id", async (req, res) => {
    const id = parseInt(req.params.id);
    if (isNaN(id)) return res.status(400).json({ error: "Invalid ID" });
    const result = await db.execute(
      sql`SELECT i.*, c.name as client_name, c.email as client_email, c.address as client_address FROM invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = ${id}`
    );
    if (!result.rows.length) return res.status(404).json({ error: "Invoice not found" });
    res.json(result.rows[0]);
  });

  // ── Client invoices ───────────────────────────────────────────────────────
  app.get("/api/client/invoices", async (req, res) => {
    const clientId = (req as any).session?.portalUser?.client_id;
    if (!clientId) return res.status(401).json({ error: "Not authenticated" });
    const result = await db.execute(
      sql`SELECT * FROM invoices WHERE client_id = ${clientId} ORDER BY created_at DESC`
    );
    res.json(result.rows);
  });

  // ── Phase 5: Email Marketing ──────────────────────────────────────────────

  // POST /api/webhook/log-email-sequence — HERALD logs each sent step
  app.post("/api/webhook/log-email-sequence", requireWebhookToken, async (req, res) => {
    const { lead_id, client_id, sequence_name, step_number, tracking_id, bounced } = req.body;
    if (!sequence_name) return res.status(400).json({ error: "sequence_name required" });
    const result = await db.execute(sql`
      INSERT INTO email_sequences (lead_id, client_id, sequence_name, step_number, tracking_id, bounced, sent_at, created_at)
      VALUES (${lead_id ?? null}, ${client_id ?? null}, ${sequence_name}, ${step_number ?? 1}, ${tracking_id ?? null}, ${bounced ?? false}, NOW(), NOW())
      RETURNING *
    `);
    res.status(201).json(result.rows[0]);
  });

  // GET /api/webhook/tracking-pixel/:trackingId — 1x1 px open tracking
  app.get("/api/webhook/tracking-pixel/:trackingId", async (req, res) => {
    const { trackingId } = req.params;
    await db.execute(sql`UPDATE email_sequences SET opened = true WHERE tracking_id = ${trackingId}`);
    const pixel = Buffer.from(
      "R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7",
      "base64"
    );
    res.set("Content-Type", "image/gif").set("Cache-Control", "no-store").send(pixel);
  });

  // GET /api/webhook/redirect/:trackingId — click redirect + tracking
  app.get("/api/webhook/redirect/:trackingId", async (req, res) => {
    const { trackingId } = req.params;
    const { url } = req.query;
    await db.execute(sql`UPDATE email_sequences SET clicked = true WHERE tracking_id = ${trackingId}`);
    res.redirect((url as string) || "https://portal.bluemogul.us");
  });

  // POST /unsubscribe/:token — unsubscribe handler
  app.post("/unsubscribe/:token", async (req, res) => {
    const { token } = req.params;
    await db.execute(sql`
      UPDATE email_sequences SET replied = true WHERE tracking_id = ${token}
    `);
    res.json({ success: true, message: "You have been unsubscribed." });
  });

  app.get("/unsubscribe/:token", async (req, res) => {
    const { token } = req.params;
    await db.execute(sql`
      UPDATE email_sequences SET replied = true WHERE tracking_id = ${token}
    `);
    res.send(`<!DOCTYPE html><html><head><title>Unsubscribed</title></head><body style="font-family:sans-serif;padding:40px;text-align:center"><h2>You have been unsubscribed.</h2><p>You will no longer receive emails from Blue Mogul.</p></body></html>`);
  });

  // GET /api/admin/email-templates
  app.get("/api/admin/email-templates", async (_req, res) => {
    const result = await db.execute(sql`SELECT * FROM email_templates ORDER BY category, name`);
    res.json(result.rows);
  });

  // POST /api/admin/email-templates
  app.post("/api/admin/email-templates", async (req, res) => {
    const { name, subject, body_html, body_text, category } = req.body;
    if (!name || !subject) return res.status(400).json({ error: "name and subject required" });
    const result = await db.execute(sql`
      INSERT INTO email_templates (name, subject, body_html, body_text, category, created_at)
      VALUES (${name}, ${subject}, ${body_html ?? null}, ${body_text ?? null}, ${category ?? "general"}, NOW())
      RETURNING *
    `);
    res.status(201).json(result.rows[0]);
  });

  // GET /api/admin/sequences
  app.get("/api/admin/sequences", async (_req, res) => {
    const result = await db.execute(sql`SELECT * FROM email_sequence_definitions ORDER BY name`);
    res.json(result.rows);
  });

  // GET /api/admin/marketing/campaigns — campaign analytics
  app.get("/api/admin/marketing/campaigns", async (_req, res) => {
    const sequences = await db.execute(sql`
      SELECT
        sequence_name,
        COUNT(*) AS total_sent,
        SUM(CASE WHEN opened THEN 1 ELSE 0 END) AS opened_count,
        SUM(CASE WHEN clicked THEN 1 ELSE 0 END) AS clicked_count,
        SUM(CASE WHEN replied THEN 1 ELSE 0 END) AS replied_count,
        SUM(CASE WHEN bounced THEN 1 ELSE 0 END) AS bounced_count
      FROM email_sequences
      GROUP BY sequence_name
      ORDER BY total_sent DESC
    `);
    res.json(sequences.rows);
  });

  // ── Phase 6: Social Media & Blog ─────────────────────────────────────────

  // POST /api/webhook/log-social-post — PUBLISHER logs after each post
  app.post("/api/webhook/log-social-post", requireWebhookToken, async (req, res) => {
    const { platform, content_preview, post_url, likes, comments, shares } = req.body;
    if (!platform) return res.status(400).json({ error: "platform required" });
    const result = await db.execute(sql`
      INSERT INTO social_posts (platform, content_preview, post_url, likes, comments, shares, posted_at, created_at)
      VALUES (${platform}, ${content_preview ?? null}, ${post_url ?? null}, ${likes ?? 0}, ${comments ?? 0}, ${shares ?? 0}, NOW(), NOW())
      RETURNING *
    `);
    res.status(201).json(result.rows[0]);
  });

  // POST /api/webhook/log-blog-post — PUBLISHER logs after each publish
  app.post("/api/webhook/log-blog-post", requireWebhookToken, async (req, res) => {
    const { title, platform, post_url, views, engagement_score } = req.body;
    if (!title) return res.status(400).json({ error: "title required" });
    const result = await db.execute(sql`
      INSERT INTO blog_posts (title, platform, post_url, views, engagement_score, published_at, created_at)
      VALUES (${title}, ${platform ?? "website"}, ${post_url ?? null}, ${views ?? 0}, ${engagement_score ?? 0}, NOW(), NOW())
      RETURNING *
    `);
    res.status(201).json(result.rows[0]);
  });

  return httpServer;
}
