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
}
