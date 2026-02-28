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

const ALLOWED_PHP_FILES = ["index.php", "login-handler.php", "setup.php"];

function executePhpFile(filePath: string, res: Response) {
  execFile(
    "php",
    [filePath],
    {
      timeout: 15000,
      maxBuffer: 2 * 1024 * 1024,
      cwd: projectRoot,
      env: { ...process.env },
    },
    (error, stdout, stderr) => {
      if (error) {
        console.error(`PHP execution error:`, stderr || error.message);
        return res.status(500).send("Server error");
      }
      res.setHeader("Content-Type", "text/html; charset=utf-8");
      res.send(stdout);
    }
  );
}

app.get("/portal", (_req, res) => {
  executePhpFile(join(projectRoot, "index.php"), res);
});

app.get("/portal/:file", (req, res) => {
  const file = req.params.file;
  const phpFile = file.endsWith(".php") ? file : `${file}.php`;

  if (!ALLOWED_PHP_FILES.includes(phpFile)) {
    return res.status(404).send("Not found");
  }

  executePhpFile(join(projectRoot, phpFile), res);
});

app.post("/portal/login-handler.php", (req, res) => {
  const filePath = join(projectRoot, "login-handler.php");

  const formParts: string[] = [];
  for (const [key, value] of Object.entries(req.body || {})) {
    formParts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
  }
  const postData = formParts.join("&");

  const phpCode = `<?php
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
          res.json(json);
        } catch {
          res.setHeader("Content-Type", "text/html; charset=utf-8");
          res.send(stdout);
        }
      }
    );
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
