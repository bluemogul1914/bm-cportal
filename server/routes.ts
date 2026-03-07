import type { Express } from "express";
import { createServer, type Server } from "http";
import { storage } from "./storage";
import { insertSnippetSchema } from "@shared/schema";
import { execFile } from "child_process";
import { writeFile, unlink } from "fs/promises";
import { tmpdir } from "os";
import { join } from "path";
import { randomUUID } from "crypto";
import { getUncachableStripeClient, getStripePublishableKey } from "./stripeClient";
import { db } from "./db";
import { sql } from "drizzle-orm";

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

  return httpServer;
}
