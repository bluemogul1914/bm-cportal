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
    await db.execute(sql`ALTER TABLE invoices ADD COLUMN IF NOT EXISTS subscription_id INTEGER REFERENCES subscriptions(id)`);
    await db.execute(sql`ALTER TABLE invoices ADD COLUMN IF NOT EXISTS stripe_payment_id VARCHAR(200)`);
    // Provider-agnostic pre-qual: which footprint provider a lead targets (Frontier today, Cox later).
    await db.execute(sql`ALTER TABLE frontier_orders ADD COLUMN IF NOT EXISTS provider TEXT NOT NULL DEFAULT 'Frontier'`);

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

  // ── Phase 7: Network Docs Integrations ───────────────────────────────────
  try {
    // Client-to-source mapping table (stable, persistent matching)
    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS client_source_mappings (
        id SERIAL PRIMARY KEY,
        client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
        source VARCHAR(50) NOT NULL,
        external_id VARCHAR(255) NOT NULL,
        external_name VARCHAR(255) DEFAULT '',
        matched_by VARCHAR(50) DEFAULT 'auto',
        created_at TIMESTAMP DEFAULT NOW(),
        UNIQUE(source, external_id)
      )
    `);

    // Network assets table — normalized inventory from all 5 sources
    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS network_assets (
        id SERIAL PRIMARY KEY,
        client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
        source VARCHAR(50) NOT NULL,
        asset_type VARCHAR(100) NOT NULL DEFAULT 'device',
        external_id VARCHAR(255) NOT NULL,
        name VARCHAR(255) DEFAULT '',
        ip VARCHAR(50) DEFAULT '',
        status VARCHAR(50) DEFAULT 'unknown',
        last_seen TIMESTAMPTZ,
        raw JSONB DEFAULT '{}',
        created_at TIMESTAMP DEFAULT NOW(),
        updated_at TIMESTAMP DEFAULT NOW(),
        UNIQUE(source, external_id)
      )
    `);

    // Index for fast lookups by client
    await db.execute(sql`
      CREATE INDEX IF NOT EXISTS idx_network_assets_client_id ON network_assets(client_id)
    `);
    await db.execute(sql`
      CREATE INDEX IF NOT EXISTS idx_network_assets_source ON network_assets(source)
    `);
    await db.execute(sql`
      CREATE INDEX IF NOT EXISTS idx_client_source_mappings_client ON client_source_mappings(client_id)
    `);

    console.log("[migrations] Phase 7 network docs integrations applied");
  } catch (err: any) {
    console.error("[migrations] Phase 7 migration error:", err.message);
  }

  // ── Prepaid Balance Ledger ─────────────────────────────────────────────────
  try {
    // Add prepaid columns to clients table (idempotent via IF NOT EXISTS)
    await db.execute(sql`ALTER TABLE clients ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'`);
    await db.execute(sql`ALTER TABLE clients ADD COLUMN IF NOT EXISTS low_balance_warned BOOLEAN DEFAULT false`);
    await db.execute(sql`ALTER TABLE clients ADD COLUMN IF NOT EXISTS low_balance_threshold DECIMAL(10,2) DEFAULT 10.00`);
    await db.execute(sql`ALTER TABLE clients ADD COLUMN IF NOT EXISTS last_charged_at TIMESTAMPTZ`);

    // Transaction ledger — every top-up, charge, or adjustment
    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS transaction_ledger (
        id SERIAL PRIMARY KEY,
        client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
        type VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        balance_before DECIMAL(10,2) NOT NULL,
        balance_after DECIMAL(10,2) NOT NULL,
        description TEXT,
        invoice_id INTEGER REFERENCES invoices(id),
        stripe_payment_id VARCHAR(255),
        stripe_session_id VARCHAR(255),
        metadata JSONB DEFAULT '{}',
        created_at TIMESTAMP DEFAULT NOW() NOT NULL
      )
    `);

    // Invoice sequences table for sequential INV-XXXXX number generation
    await db.execute(sql`
      CREATE TABLE IF NOT EXISTS invoice_sequences (
        id SERIAL PRIMARY KEY,
        client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
        seq INTEGER NOT NULL DEFAULT 0,
        UNIQUE(client_id)
      )
    `);

    // Indexes for fast client ledger queries
    await db.execute(sql`
      CREATE INDEX IF NOT EXISTS idx_transaction_ledger_client_id ON transaction_ledger(client_id)
    `);
    await db.execute(sql`
      CREATE INDEX IF NOT EXISTS idx_transaction_ledger_created_at ON transaction_ledger(client_id, created_at DESC)
    `);
    // Idempotency: one ledger entry per Stripe session (prevents double-credit on race)
        await db.execute(sql`
          CREATE UNIQUE INDEX IF NOT EXISTS idx_transaction_ledger_stripe_session
          ON transaction_ledger(stripe_session_id)
          WHERE stripe_session_id IS NOT NULL
        `);

        // ── Field work orders (P1 #3) ────────────────────────────────────────────
        await db.execute(sql`
          CREATE TABLE IF NOT EXISTS work_orders (
                  id SERIAL PRIMARY KEY,
                  client_id INTEGER REFERENCES clients(id),
                  ticket_id INTEGER REFERENCES tickets(id),
                  project_id INTEGER REFERENCES projects(id),
                  site_name TEXT,
                  address TEXT,
                  scheduled_date DATE,
                  assignee TEXT,
                  status TEXT NOT NULL DEFAULT 'open',
                  checklist_template TEXT,
                  checklist JSONB NOT NULL DEFAULT '[]',
                  notes TEXT,
                  created_at TIMESTAMPTZ DEFAULT NOW(),
                  updated_at TIMESTAMPTZ DEFAULT NOW()
                )
              `);
              await db.execute(sql`
                ALTER TABLE work_orders ADD COLUMN IF NOT EXISTS scheduled_time TIME
              `);
        await db.execute(sql`
          CREATE TABLE IF NOT EXISTS work_order_checklist_templates (
            id SERIAL PRIMARY KEY,
            name TEXT NOT NULL UNIQUE,
            items JSONB NOT NULL DEFAULT '[]',
            created_at TIMESTAMPTZ DEFAULT NOW()
          )
        `);
        await db.execute(sql`
          INSERT INTO work_order_checklist_templates (name, items) VALUES
            ('install', '["Confirm site access","Mount CPE/radio","Run cable + terminate","Power + verify link","Speed test","Label equipment","Document in portal"]'),
            ('survey', '["Site photos","Line-of-sight check","Mounting points","Power availability","GPS coordinates","Obstruction notes"]'),
            ('decommission', '["Notify client","Remove equipment","Recover hardware","Update inventory","Close ticket"]')
          ON CONFLICT (name) DO NOTHING
        `);
        await db.execute(sql`
              CREATE INDEX IF NOT EXISTS idx_work_orders_scheduled_date ON work_orders(scheduled_date)
            `);

            // ── Inventory with deployed-status (P1 #4) ──────────────────────────────
            await db.execute(sql`
              CREATE TABLE IF NOT EXISTS network_sites (
                id SERIAL PRIMARY KEY,
                client_id INTEGER REFERENCES clients(id),
                name TEXT NOT NULL,
                address TEXT,
                created_at TIMESTAMPTZ DEFAULT NOW()
              )
            `);
            await db.execute(sql`
              ALTER TABLE assets ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'received'
            `);
            await db.execute(sql`
              ALTER TABLE assets ADD COLUMN IF NOT EXISTS network_site_id INTEGER REFERENCES network_sites(id)
            `);
            await db.execute(sql`
              ALTER TABLE assets ADD COLUMN IF NOT EXISTS supplier TEXT
            `);
            await db.execute(sql`
              ALTER TABLE assets ADD COLUMN IF NOT EXISTS po_reference TEXT
            `);
            await db.execute(sql`
              CREATE INDEX IF NOT EXISTS idx_assets_status ON assets(status)
            `);
            await db.execute(sql`
              CREATE INDEX IF NOT EXISTS idx_assets_network_site_id ON assets(network_site_id)
            `);
            console.log("[migrations] Inventory deployed-status migrations applied");
              } catch (err: any) {
                console.error("[migrations] Prepaid balance ledger migration error:", err.message);
              }

              // ── Service contracts + SLA terms (P2 #6) ─────────────────────────────
              try {
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS service_contracts (
                    id SERIAL PRIMARY KEY,
                    client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
                    name VARCHAR(255) NOT NULL,
                    start_date DATE,
                    end_date DATE,
                    status VARCHAR(20) DEFAULT 'active',
                    monthly_value NUMERIC(10,2) DEFAULT 0,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS sla_terms (
                    id SERIAL PRIMARY KEY,
                    contract_id INTEGER REFERENCES service_contracts(id) ON DELETE CASCADE,
                    priority VARCHAR(20) NOT NULL,
                    response_hours NUMERIC(6,2) DEFAULT 0,
                    resolution_hours NUMERIC(6,2) DEFAULT 0
                  )
                `);
                await db.execute(sql`
                  ALTER TABLE tickets ADD COLUMN IF NOT EXISTS sla_due_at TIMESTAMP
                `);
                console.log("[migrations] Service contracts + SLA migrations applied");
              } catch (err: any) {
                console.error("[migrations] Service contracts + SLA migration error:", err.message);
              }

              // ── Purchase orders + approval flow (P2 #7) ─────────────────────────
              try {
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS purchase_orders (
                    id SERIAL PRIMARY KEY,
                    client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    supplier VARCHAR(255),
                    po_number VARCHAR(100),
                    total NUMERIC(10,2) DEFAULT 0,
                    status VARCHAR(20) DEFAULT 'draft',
                    requested_by VARCHAR(200),
                    approved_by VARCHAR(200),
                    approved_at TIMESTAMP,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS po_line_items (
                    id SERIAL PRIMARY KEY,
                    po_id INTEGER REFERENCES purchase_orders(id) ON DELETE CASCADE,
                    description TEXT,
                    qty INTEGER DEFAULT 1,
                    unit_price NUMERIC(10,2) DEFAULT 0
                  )
                `);
                console.log("[migrations] Purchase order migrations applied");
              } catch (err: any) {
                console.error("[migrations] Purchase order migration error:", err.message);
              }

              // ── IP pools + allocations (P3 #8) ──────────────────────────────────
              try {
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS ip_pools (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    cidr VARCHAR(64) NOT NULL,
                    gateway VARCHAR(45),
                    vlan INTEGER,
                    site_id INTEGER REFERENCES network_sites(id) ON DELETE SET NULL,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS ip_allocations (
                    id SERIAL PRIMARY KEY,
                    pool_id INTEGER REFERENCES ip_pools(id) ON DELETE CASCADE,
                    ip_address VARCHAR(45) NOT NULL,
                    client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    device_id INTEGER REFERENCES network_devices(id) ON DELETE SET NULL,
                    status VARCHAR(20) DEFAULT 'assigned',
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                console.log("[migrations] IP pool migrations applied");
              } catch (err: any) {
                console.error("[migrations] IP pool migration error:", err.message);
              }

              // ── Network site geo (P3 #8b) ───────────────────────────────────────
              try {
                await db.execute(sql`ALTER TABLE network_sites ADD COLUMN IF NOT EXISTS latitude NUMERIC(10,7)`);
                await db.execute(sql`ALTER TABLE network_sites ADD COLUMN IF NOT EXISTS longitude NUMERIC(10,7)`);
                await db.execute(sql`ALTER TABLE network_sites ADD COLUMN IF NOT EXISTS site_type VARCHAR(40)`);
                await db.execute(sql`ALTER TABLE network_sites ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'`);
                console.log("[migrations] Network site geo migrations applied");
              } catch (err: any) {
                console.error("[migrations] Network site geo migration error:", err.message);
              }

              // ── Network device provenance (network-sync mirror) ─────────────────
              // The 6 sync sources write network_assets; Network Docs reads
              // network_devices. These columns give the mirror a stable upsert key
              // so repeated syncs update in place instead of duplicating rows.
              try {
                await db.execute(sql`ALTER TABLE network_devices ADD COLUMN IF NOT EXISTS source VARCHAR(40)`);
                await db.execute(sql`ALTER TABLE network_devices ADD COLUMN IF NOT EXISTS external_id VARCHAR(120)`);
                await db.execute(sql`
                  CREATE UNIQUE INDEX IF NOT EXISTS network_devices_source_external_id_key
                  ON network_devices (source, external_id)
                `);
                console.log("[migrations] Network device provenance migrations applied");
              } catch (err: any) {
                console.error("[migrations] Network device provenance migration error:", err.message);
              }

              // ── Wave Accounting mirror (ITFlow module_financial parity) ────────
              // Local read-model of the Wave account: businesses, accounts,
              // customers, invoices, invoice payments, vendors, plus a sync-run log.
              // See server/wave-api.ts and docs/itflow-parity-build-plan.md.
              try {
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS wave_businesses (
                    id SERIAL PRIMARY KEY,
                    wave_id TEXT UNIQUE NOT NULL,
                    name TEXT,
                    currency VARCHAR(8),
                    is_personal BOOLEAN DEFAULT false,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS wave_accounts (
                    id SERIAL PRIMARY KEY,
                    wave_id TEXT UNIQUE NOT NULL,
                    business_wave_id TEXT,
                    name TEXT,
                    display_id VARCHAR(60),
                    description TEXT,
                    type_name VARCHAR(60),
                    type_value VARCHAR(30),
                    subtype_name VARCHAR(80),
                    currency VARCHAR(8),
                    balance NUMERIC(16,2),
                    balance_business_currency NUMERIC(16,2),
                    is_archived BOOLEAN DEFAULT false,
                    sequence INTEGER,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS wave_customers (
                    id SERIAL PRIMARY KEY,
                    wave_id TEXT UNIQUE NOT NULL,
                    business_wave_id TEXT,
                    name TEXT,
                    email VARCHAR(200),
                    display_id VARCHAR(60),
                    phone VARCHAR(60),
                    currency VARCHAR(8),
                    outstanding NUMERIC(16,2),
                    overdue NUMERIC(16,2),
                    is_archived BOOLEAN DEFAULT false,
                    client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS wave_invoices (
                    id SERIAL PRIMARY KEY,
                    wave_id TEXT UNIQUE NOT NULL,
                    business_wave_id TEXT,
                    invoice_number VARCHAR(60),
                    status VARCHAR(20),
                    title TEXT,
                    po_number VARCHAR(60),
                    invoice_date DATE,
                    due_date DATE,
                    customer_wave_id TEXT,
                    customer_name TEXT,
                    customer_email VARCHAR(200),
                    client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    total NUMERIC(16,2),
                    amount_paid NUMERIC(16,2),
                    amount_due NUMERIC(16,2),
                    tax_total NUMERIC(16,2),
                    subtotal NUMERIC(16,2),
                    currency VARCHAR(8),
                    pdf_url TEXT,
                    view_url TEXT,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS wave_payments (
                    id SERIAL PRIMARY KEY,
                    wave_id TEXT UNIQUE NOT NULL,
                    business_wave_id TEXT,
                    invoice_wave_id TEXT,
                    invoice_number VARCHAR(60),
                    customer_name TEXT,
                    client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    payment_date DATE,
                    amount NUMERIC(16,2),
                    currency VARCHAR(8),
                    payment_method VARCHAR(80),
                    memo TEXT,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS wave_vendors (
                    id SERIAL PRIMARY KEY,
                    wave_id TEXT UNIQUE NOT NULL,
                    business_wave_id TEXT,
                    name TEXT,
                    email VARCHAR(200),
                    display_id VARCHAR(60),
                    phone VARCHAR(60),
                    is_archived BOOLEAN DEFAULT false,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS wave_sync_runs (
                    id SERIAL PRIMARY KEY,
                    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    finished_at TIMESTAMP,
                    status VARCHAR(20) DEFAULT 'running',
                    businesses INTEGER DEFAULT 0,
                    accounts INTEGER DEFAULT 0,
                    customers INTEGER DEFAULT 0,
                    invoices INTEGER DEFAULT 0,
                    payments INTEGER DEFAULT 0,
                    vendors INTEGER DEFAULT 0,
                    error TEXT
                  )
                `);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS wave_invoices_client_idx ON wave_invoices (client_id)`);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS wave_invoices_status_idx ON wave_invoices (status)`);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS wave_invoices_date_idx ON wave_invoices (invoice_date)`);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS wave_payments_client_idx ON wave_payments (client_id)`);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS wave_customers_client_idx ON wave_customers (client_id)`);
                console.log("[migrations] Wave accounting mirror migrations applied");
              } catch (err: any) {
                console.error("[migrations] Wave accounting mirror migration error:", err.message);
              }

              // ── Xero Accounting mirror (ITFlow module_financial parity) ───────
              // Custom-connection client-credentials app: invoices, contacts,
              // payments, chart of accounts + organisation. See server/xero-api.ts.
              try {
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS xero_organisation (
                    id SERIAL PRIMARY KEY,
                    organisation_id TEXT UNIQUE NOT NULL,
                    name TEXT,
                    legal_name TEXT,
                    base_currency VARCHAR(8),
                    country_code VARCHAR(8),
                    org_type VARCHAR(40),
                    tax_number TEXT,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS xero_contacts (
                    id SERIAL PRIMARY KEY,
                    contact_id TEXT UNIQUE NOT NULL,
                    name TEXT,
                    email VARCHAR(200),
                    first_name VARCHAR(120),
                    last_name VARCHAR(120),
                    phone VARCHAR(60),
                    is_customer BOOLEAN DEFAULT false,
                    is_supplier BOOLEAN DEFAULT false,
                    status VARCHAR(40),
                    client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS xero_invoices (
                    id SERIAL PRIMARY KEY,
                    invoice_id TEXT UNIQUE NOT NULL,
                    invoice_number VARCHAR(80),
                    type VARCHAR(20),
                    contact_id TEXT,
                    contact_name TEXT,
                    client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    status VARCHAR(30),
                    invoice_date DATE,
                    due_date DATE,
                    subtotal NUMERIC(16,2),
                    total_tax NUMERIC(16,2),
                    total NUMERIC(16,2),
                    amount_paid NUMERIC(16,2),
                    amount_due NUMERIC(16,2),
                    amount_credited NUMERIC(16,2),
                    currency VARCHAR(8),
                    reference TEXT,
                    line_item_count INTEGER DEFAULT 0,
                    updated_utc TEXT,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS xero_payments (
                    id SERIAL PRIMARY KEY,
                    payment_id TEXT UNIQUE NOT NULL,
                    invoice_id TEXT,
                    invoice_number VARCHAR(80),
                    contact_name TEXT,
                    client_id INTEGER REFERENCES clients(id) ON DELETE SET NULL,
                    payment_date DATE,
                    amount NUMERIC(16,2),
                    currency VARCHAR(8),
                    account_name TEXT,
                    payment_type VARCHAR(40),
                    status VARCHAR(30),
                    reference TEXT,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS xero_accounts (
                    id SERIAL PRIMARY KEY,
                    account_id TEXT UNIQUE NOT NULL,
                    code VARCHAR(40),
                    name TEXT,
                    type VARCHAR(60),
                    tax_type VARCHAR(60),
                    status VARCHAR(30),
                    description TEXT,
                    currency VARCHAR(8),
                    enable_payments BOOLEAN DEFAULT false,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                  )
                `);
                await db.execute(sql`
                  CREATE TABLE IF NOT EXISTS xero_sync_runs (
                    id SERIAL PRIMARY KEY,
                    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    finished_at TIMESTAMP,
                    status VARCHAR(20) DEFAULT 'running',
                    organisation TEXT,
                    contacts INTEGER DEFAULT 0,
                    invoices INTEGER DEFAULT 0,
                    payments INTEGER DEFAULT 0,
                    accounts INTEGER DEFAULT 0,
                    error TEXT
                  )
                `);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS xero_invoices_client_idx ON xero_invoices (client_id)`);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS xero_invoices_status_idx ON xero_invoices (status)`);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS xero_invoices_date_idx ON xero_invoices (invoice_date)`);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS xero_payments_client_idx ON xero_payments (client_id)`);
                await db.execute(sql`CREATE INDEX IF NOT EXISTS xero_contacts_client_idx ON xero_contacts (client_id)`);
                console.log("[migrations] Xero accounting mirror migrations applied");
              } catch (err: any) {
                console.error("[migrations] Xero accounting mirror migration error:", err.message);
              }
            }
