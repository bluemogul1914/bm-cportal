import { db } from "./db";
import { sql } from "drizzle-orm";

export async function runPortalMigrations() {
  try {
    // Phase 1 — extend tickets table
    await db.execute(sql`ALTER TABLE tickets ADD COLUMN IF NOT EXISTS resolution_notes TEXT`);
    await db.execute(sql`ALTER TABLE tickets ADD COLUMN IF NOT EXISTS closed_at TIMESTAMP`);
    await db.execute(sql`ALTER TABLE tickets ADD COLUMN IF NOT EXISTS sla_due_at TIMESTAMP`);
    await db.execute(sql`ALTER TABLE tickets ADD COLUMN IF NOT EXISTS ai_draft_response TEXT`);

    // Phase 1 — extend invoices table
    await db.execute(sql`ALTER TABLE invoices ADD COLUMN IF NOT EXISTS line_items JSONB`);
    await db.execute(sql`ALTER TABLE invoices ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP`);
    await db.execute(sql`ALTER TABLE invoices ADD COLUMN IF NOT EXISTS stripe_payment_id VARCHAR(200)`);

    // Phase 1 — create contacts table
    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS contacts (
        id SERIAL PRIMARY KEY,
        client_id INTEGER REFERENCES clients(id),
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255),
        phone VARCHAR(50),
        role VARCHAR(100),
        is_primary BOOLEAN DEFAULT false,
        notes TEXT,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    // Phase 1 — create assets table
    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS assets (
        id SERIAL PRIMARY KEY,
        client_id INTEGER REFERENCES clients(id),
        name VARCHAR(255) NOT NULL,
        type VARCHAR(100),
        serial_number VARCHAR(100),
        os VARCHAR(100),
        ip_address VARCHAR(50),
        managed BOOLEAN DEFAULT false,
        rmm_agent_id VARCHAR(255),
        plan_tier VARCHAR(50),
        notes TEXT,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    console.log("[migrations] Phase 1 portal migrations applied successfully");
  } catch (err: any) {
    console.error("[migrations] Phase 1 migration error:", err.message);
  }

  // ── Phase 5: Email Marketing ──────────────────────────────────────────────
  try {
    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS email_sequences (
        id SERIAL PRIMARY KEY,
        lead_id INTEGER,
        client_id INTEGER REFERENCES clients(id),
        sequence_name VARCHAR(255) NOT NULL,
        step_number INTEGER DEFAULT 1,
        sent_at TIMESTAMP DEFAULT NOW(),
        opened BOOLEAN DEFAULT false,
        clicked BOOLEAN DEFAULT false,
        replied BOOLEAN DEFAULT false,
        bounced BOOLEAN DEFAULT false,
        tracking_id VARCHAR(255),
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS email_templates (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        subject VARCHAR(500) NOT NULL,
        body_html TEXT,
        body_text TEXT,
        category VARCHAR(100) DEFAULT 'general',
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS email_sequence_definitions (
        id SERIAL PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        steps JSONB DEFAULT '[]',
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    // Seed 9 email templates (idempotent — skip if already have >= 9)
    const tplCount = await db.execute(sql`SELECT COUNT(*) FROM email_templates`);
    if (parseInt((tplCount.rows[0] as any).count, 10) < 9) {
      const templates = [
        { name: "Welcome", subject: "Welcome to Blue Mogul!", category: "onboarding", body_text: "Hi {{name}}, welcome aboard! We're excited to have you." },
        { name: "Onboarding Step 1", subject: "Getting started with your services", category: "onboarding", body_text: "Hi {{name}}, here's how to get the most out of your plan." },
        { name: "Onboarding Step 2", subject: "Your portal is ready", category: "onboarding", body_text: "Hi {{name}}, your client portal is set up and ready to use." },
        { name: "Demo Follow-up", subject: "Following up on our demo", category: "sales", body_text: "Hi {{name}}, thank you for the demo. Any questions?" },
        { name: "Re-engagement", subject: "We miss you — let's reconnect", category: "re-engagement", body_text: "Hi {{name}}, it's been a while. We'd love to catch up." },
        { name: "Renewal Reminder", subject: "Your service renewal is coming up", category: "renewal", body_text: "Hi {{name}}, your plan renews on {{renewal_date}}. Any changes?" },
        { name: "Renewal Final Notice", subject: "Action required: service renewal", category: "renewal", body_text: "Hi {{name}}, this is your final reminder before renewal." },
        { name: "Invoice Due", subject: "Invoice #{{invoice_number}} due soon", category: "billing", body_text: "Hi {{name}}, invoice #{{invoice_number}} is due on {{due_date}}." },
        { name: "Support Follow-up", subject: "How did we do? Ticket #{{ticket_id}}", category: "support", body_text: "Hi {{name}}, we hope your issue is resolved. Any feedback?" },
      ];
      for (const t of templates) {
        await db.execute(sql`
          INSERT INTO email_templates (name, subject, body_html, body_text, category)
          VALUES (${t.name}, ${t.subject}, ${t.body_text}, ${t.body_text}, ${t.category})
          ON CONFLICT DO NOTHING
        `);
      }
    }

    // Seed 5 sequence definitions (idempotent)
    const seqCount = await db.execute(sql`SELECT COUNT(*) FROM email_sequence_definitions`);
    if (parseInt((seqCount.rows[0] as any).count, 10) < 5) {
      const seqDefs = [
        { name: "Welcome Sequence", description: "New client onboarding", steps: [{ step: 1, template: "Welcome", delay_days: 0 }, { step: 2, template: "Onboarding Step 1", delay_days: 1 }, { step: 3, template: "Onboarding Step 2", delay_days: 3 }] },
        { name: "Re-engagement", description: "Inactive contact re-engagement", steps: [{ step: 1, template: "Re-engagement", delay_days: 0 }, { step: 2, template: "Demo Follow-up", delay_days: 3 }] },
        { name: "Demo Follow-up", description: "Post-demo nurture", steps: [{ step: 1, template: "Demo Follow-up", delay_days: 0 }, { step: 2, template: "Re-engagement", delay_days: 5 }] },
        { name: "Renewal Sequence", description: "Service renewal reminders", steps: [{ step: 1, template: "Renewal Reminder", delay_days: 14 }, { step: 2, template: "Renewal Final Notice", delay_days: 3 }] },
        { name: "Onboarding Sequence", description: "Full onboarding flow", steps: [{ step: 1, template: "Welcome", delay_days: 0 }, { step: 2, template: "Onboarding Step 1", delay_days: 1 }, { step: 3, template: "Onboarding Step 2", delay_days: 3 }, { step: 4, template: "Support Follow-up", delay_days: 7 }] },
      ];
      for (const s of seqDefs) {
        await db.execute(sql`
          INSERT INTO email_sequence_definitions (name, description, steps)
          VALUES (${s.name}, ${s.description}, ${JSON.stringify(s.steps)})
          ON CONFLICT DO NOTHING
        `);
      }
    }

    console.log("[migrations] Phase 5 email marketing migrations applied");
  } catch (err: any) {
    console.error("[migrations] Phase 5 migration error:", err.message);
  }

  // ── Phase 6: Social Media & Blog ─────────────────────────────────────────
  try {
    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS social_posts (
        id SERIAL PRIMARY KEY,
        platform VARCHAR(100) NOT NULL,
        content_preview TEXT,
        post_url VARCHAR(500),
        posted_at TIMESTAMP DEFAULT NOW(),
        likes INTEGER DEFAULT 0,
        comments INTEGER DEFAULT 0,
        shares INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS blog_posts (
        id SERIAL PRIMARY KEY,
        title VARCHAR(500) NOT NULL,
        platform VARCHAR(100) DEFAULT 'website',
        post_url VARCHAR(500),
        published_at TIMESTAMP DEFAULT NOW(),
        views INTEGER DEFAULT 0,
        engagement_score INTEGER DEFAULT 0,
        created_at TIMESTAMP DEFAULT NOW()
      )
    `);

    console.log("[migrations] Phase 6 social/blog migrations applied");
  } catch (err: any) {
    console.error("[migrations] Phase 6 migration error:", err.message);
  }
}
