-- ============================================================================
--  Blue Mogul Suite — DEMO/TRAINING database scrub
-- ============================================================================
--  Turns a fresh copy of the PRODUCTION database into a dataset safe to hand to
--  OUTSIDE agents, dealers and future staff.
--
--  Defends against:
--   1. Live vendor credentials reachable from a demo (a trainer clicking
--      "Sync" or "Provision" must not place a real order or spend real money).
--   2. Real customer PII (names, emails, phones, addresses, job titles).
--   3. Real client infrastructure (domains, IPs, hostnames, device serials).
--   4. Real financials (revenue, wallet balances, MRR) and the accounting
--      mirrors that carry customer names alongside money.
--   5. Live sessions/tokens that could be replayed against production.
--
--  Order matters:  capture real strings  ->  mask  ->  sweep the captured
--  strings out of prose  ->  verify. Running it twice is harmless (it is a full
--  overwrite), but ALWAYS restore a fresh dump first so the demo is complete.
--  :demo_hash is supplied by scripts/seed-demo.sh; never stored in this file.
-- ============================================================================

\set ON_ERROR_STOP on

BEGIN;

-- ── 1. HARD WIPES ───────────────────────────────────────────────────────────
--  Tables that exist only to hold secrets, live sessions, real correspondence or
--  accounting mirrors. Nothing here is needed to demonstrate the product.
--  (messages -> conversations is the only FK inside this set, so both are listed
--   and no CASCADE is required; TRUNCATE fails loudly if that ever changes.)
TRUNCATE
  provider_settings,                     -- 41 rows: live vendor API keys
  credentials, credential_tags,          -- credential vault (ciphertext)
  network_credentials,                   -- per-device passwords
  dealer_smtp_settings, user_email_settings,
  session, user_presence,                -- 8k+ live session rows
  settings,                              -- key/value store, secrets possible
  staff_signatures,                      -- personal signature images
  activity_log,                          -- audit rows carrying real IPs
  jc_users, jc_systems, jc_events, jc_organizations,
  action1_endpoints, action1_alerts, action1_vulnerabilities,
  itarian_endpoints, itarian_alerts,
  vultr_instances,
  mail_messages, mail_group_members, messages, conversations,
  email_log, ai_conversations,
  documents, lead_documents,
  crm_communications, lead_messages, lead_activities, chat_messages,
  xero_accounts, xero_contacts, xero_invoices, xero_payments, xero_organisation, xero_sync_runs,
  wave_accounts, wave_businesses, wave_customers, wave_invoices, wave_payments, wave_vendors, wave_sync_runs,
  frontier_orders, frontier_logs,
  hostwinds_orders, hostwinds_services,
  transaction_ledger, transactions, payments, reconciliation_sessions,
  provider_fund_log, notifications
RESTART IDENTITY;

-- ── 2. CAPTURE the real identifiers BEFORE masking ──────────────────────────
--  These are the strings most likely to survive inside free-text prose. They are
--  collected now so section 4 can hunt them out of notes and descriptions.
--  Our own company name is deliberately NOT collected — it is not a customer's
--  personal data and blanking it would make the demo nonsensical.
CREATE TEMP TABLE _sens(v text) ON COMMIT DROP;
INSERT INTO _sens SELECT name        FROM clients       WHERE coalesce(name,'')<>'';
INSERT INTO _sens SELECT company     FROM clients       WHERE coalesce(company,'')<>'';
INSERT INTO _sens SELECT first_name  FROM clients       WHERE coalesce(first_name,'')<>'';
INSERT INTO _sens SELECT last_name   FROM clients       WHERE coalesce(last_name,'')<>'';
INSERT INTO _sens SELECT name        FROM users         WHERE coalesce(name,'')<>'';
INSERT INTO _sens SELECT email       FROM users         WHERE coalesce(email,'')<>'';
INSERT INTO _sens SELECT full_name   FROM dealers       WHERE coalesce(full_name,'')<>'';
INSERT INTO _sens SELECT company_name FROM dealers      WHERE coalesce(company_name,'')<>'';
INSERT INTO _sens SELECT name        FROM crm_companies WHERE coalesce(name,'')<>'';
INSERT INTO _sens SELECT name        FROM contacts      WHERE coalesce(name,'')<>'';
INSERT INTO _sens SELECT name        FROM vendors       WHERE coalesce(name,'')<>'';
DELETE FROM _sens WHERE length(v) < 4
                     OR v ~* 'blue ?mogul'
                     OR lower(v) IN ('demo','test','admin','user','none','null','n/a','customer','client');

-- ── 3. GENERIC FIELD MASKING ────────────────────────────────────────────────
--  Walks every text column of every remaining table and masks it according to
--  what the column NAME says it holds. Uniqueness is derived from ctid, so
--  columns with a UNIQUE index (users.email, crm_companies.name) stay valid.
--  Values are never NULL: several columns are NOT NULL, and an empty string is
--  also the app's own encoding for "no secret stored".
DO $scrub$
DECLARE
  r record; c record; expr text;
  skip_tables text[] := ARRAY[
    'products','hostwinds_products','email_templates','message_templates',
    'invoice_footer_templates','snippets','_migrations','invoice_sequences',
    'sla_terms','spiff_schedule','blog_posts','social_posts','tax_items',
    'email_sequences','email_sequence_definitions','work_order_checklist_templates'];
  keep_columns text := '^(service_name|service_type|category|industry|geography|employee_count|'
                    || 'lifecycle_stage|lead_status|billing_period|currency|status|type|source|slug|'
                    || 'os|os_name|os_version|manufacturer|model|device_type|registrar|tier|'
                    || 'payout_method|encryption|referral_code|dealer_code|client_code|invoice_number|'
                    || 'plan_tier|ticket_group|priority|country|provider|key_name|setting_key|port|role)$';
BEGIN
  FOR r IN
    SELECT table_name FROM information_schema.tables
     WHERE table_schema='public' AND table_type='BASE TABLE'
       AND NOT (table_name = ANY(skip_tables))
  LOOP
    FOR c IN
      SELECT column_name FROM information_schema.columns
       WHERE table_schema='public' AND table_name = r.table_name
         AND data_type IN ('text','character varying','character')
    LOOP
      CONTINUE WHEN c.column_name ~* keep_columns;
      expr := NULL;

      -- 3a. Secrets and financial identifiers → empty ("nothing stored").
      IF c.column_name ~* '(password|passwd|secret|cipher|token|api_key|apikey|access_key|private_key|^key$|salt|otp|credential|stripe_account_id|ach_routing|ach_account|bank_name|iban|swift|bic|routing_number|account_number|ssn|^ein$|tax_id|^vat$)' THEN
        expr := '''''';

      -- 3b. Email addresses.
      ELSIF c.column_name ~* 'email' THEN
        expr := '(''demo''||substr(md5(ctid::text),1,6)||''@bluemogul-demo.test'')';

      -- 3c. Telephones (RFC 555 reserved range).
      ELSIF c.column_name ~* '(phone|fax|mobile)' THEN
        expr := '(''555-01''||lpad((abs(hashtext(ctid::text))%100)::text,2,''0''))';

      -- 3d. People.
      ELSIF c.column_name ~* '^(first_name|firstname)$' THEN expr := '''Demo''';
      ELSIF c.column_name ~* '(last_name|lastname|surname)'  THEN expr := '(''User''||upper(substr(md5(ctid::text),1,3)))';
      ELSIF c.column_name ~* '^username$' THEN expr := '(''demo''||substr(md5(ctid::text),1,4))';

      -- 3e. Organisation / contact / person names.
      ELSIF c.column_name ~* '(^name$|_name$|^company$|^contact_person$|^contact$|^full_name$|^display_name$|^company_owner$|^title$|^job_title$|^assigned_to$|^owner$|^assignee$|^requested_by$|^approved_by$|^user_name$)' THEN
        expr := '(''Demo ''||upper(substr(md5(ctid::text),1,4)))';

      -- 3f. Postal addresses.
      ELSIF c.column_name ~* '^(address|address_line1|line1|street|street_address)$' THEN expr := '''1 Demo Way''';
      ELSIF c.column_name ~* '^(address_line2|line2|suite)$' THEN expr := '''Suite 100''';
      ELSIF c.column_name ~* '^city$' THEN expr := '''Demo City''';
      ELSIF c.column_name ~* '^(state|province|region)$' THEN expr := '''TX''';
      ELSIF c.column_name ~* '^(zip|zip_code|postal_code|postcode)$' THEN expr := '''00000''';

      -- 3g. Network and asset identity.
      ELSIF c.column_name ~* '^(ip_address|ipv4)$' THEN expr := '(''10.99.''||(abs(hashtext(ctid::text))%250+1)::text||''.''||(abs(hashtext(ctid::text))%250+1)::text)';
      ELSIF c.column_name ~* 'ipv6' THEN expr := '''fd00:dead:beef::1''';
      ELSIF c.column_name ~* 'mac_address' THEN expr := '''02:00:00:00:00:01''';
      ELSIF c.column_name ~* '(hostname|fqdn|^domain$|^sans$)' THEN expr := '(''demo''||substr(md5(ctid::text),1,4)||''.example.com'')';
      ELSIF c.column_name ~* '(serial|imei|^esn$|rmm_agent_id|external_id)' THEN expr := '(''DEMO''||upper(substr(md5(ctid::text),1,8)))';
      ELSIF c.column_name ~* '(^url$|website|linkedin_url|avatar_url|image_url)' THEN expr := '''https://example.com/demo''';

      END IF;

      IF expr IS NOT NULL THEN
        IF r.table_name = 'users' AND c.column_name IN ('email','name') THEN
          -- The app re-creates its own bootstrap admin (admin@bluemogul.biz) on
          -- every boot whenever that address is missing. Masking it would make
          -- the app insert a fresh unmasked row on each restart, so that one
          -- address is deliberately left stable. It is Blue Mogul's own
          -- address, not customer data, and its password is reset in section 7.
          EXECUTE format('UPDATE users SET %I = %s WHERE email <> %L',
                         c.column_name, expr, 'admin@bluemogul.biz');
        ELSE
          EXECUTE format('UPDATE %I SET %I = %s', r.table_name, c.column_name, expr);
        END IF;
      END IF;
    END LOOP;
  END LOOP;
END
$scrub$;

-- ── 4. PROSE SWEEP ──────────────────────────────────────────────────────────
--  Free text keeps its shape (so tickets and project notes still read like real
--  work) but any captured real identifier inside it is replaced. Also collapses
--  any email-shaped substring, which catches addresses the capture missed.
DO $sweep$
DECLARE t text; c record; s record; v_variant text;
  prose_tables text[] := ARRAY['tickets','ticket_comments','projects','project_notes',
    'project_tasks','work_orders','service_contracts','crm_leads','clients','dealers','crm_companies',
    'crm_deals','budgets','purchase_orders','po_line_items','lead_quotes','invoices','contacts','vendors',
    'knowledge_articles','invoice_footer_templates','email_templates','message_templates','products',
    'sla_terms','subscriptions','client_services','assets','network_devices','domains','certificates',
    'blog_posts','social_posts','email_sequences'];
BEGIN
  FOR t IN SELECT unnest(prose_tables) LOOP
    FOR c IN SELECT column_name FROM information_schema.columns
              WHERE table_schema='public' AND table_name=t
                AND data_type IN ('text','character varying') LOOP
      -- 4a. any email-shaped substring
      EXECUTE format('UPDATE %I SET %I = regexp_replace(%I, %L, %L, ''gi'')',
        t, c.column_name, c.column_name,
        '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}', 'demo@bluemogul-demo.test');
      -- 4b. every captured real identifier, in the three case variants that
      --     actually occur. Plain replace() (not regexp) so that a name
      --     containing regex metacharacters cannot break the sweep.
      FOR s IN SELECT DISTINCT v FROM _sens LOOP
        FOR v_variant IN SELECT unnest(ARRAY[s.v, initcap(lower(s.v)), upper(s.v)]) LOOP
          EXECUTE format('UPDATE %I SET %I = replace(%I, %L, %L) WHERE %I LIKE %L',
            t, c.column_name, c.column_name, v_variant, 'Demo Client', c.column_name, '%'||v_variant||'%');
        END LOOP;
      END LOOP;
    END LOOP;
  END LOOP;
END
$sweep$;

-- ── 5. STRUCTURED VALUES THE FIELD SCANNER CANNOT REACH ─────────────────────
UPDATE clients       SET latitude=NULL, longitude=NULL, linkedin_data=NULL,
                         emails=NULL, phones=NULL, social_links=NULL, tags=NULL,
                         low_balance_warned=false;
UPDATE crm_companies SET phones=NULL, emails=NULL, social_links=NULL, scrape_data=NULL, tags=NULL;
UPDATE users         SET avatar_data=NULL, remember_token=NULL, remember_token_expires=NULL;
UPDATE assets        SET po_reference=NULL, rmm_agent_id=NULL;
UPDATE certificates  SET subject='demo.example.com', notes='[demo data]';
UPDATE domains       SET notes='[demo data]';
UPDATE assets        SET notes='[demo data]';
UPDATE network_devices SET notes='[demo data]';
UPDATE tickets       SET ai_draft_response=NULL, resolution_notes='[demo data]';
UPDATE work_orders   SET notes='[demo data]';

-- 5c. Raw vendor payloads. These are untyped JSON blobs straight from provider
--     APIs and CRM exports: they carry names, emails and, in some cases, keys.
--     The field scanner cannot classify a blob, so they are dropped outright.
UPDATE hostwinds_products SET raw = NULL WHERE raw IS NOT NULL;
UPDATE network_assets     SET raw = NULL WHERE raw IS NOT NULL;
UPDATE leads              SET geo_data = NULL, linkedin_data = NULL;
UPDATE dealer_orders      SET details = NULL WHERE details IS NOT NULL;
UPDATE dealer_payouts     SET provider_response = NULL WHERE provider_response IS NOT NULL;
UPDATE crm_campaigns      SET name = 'Demo Campaign '||upper(substr(md5(ctid::text),1,4)) WHERE name IS NOT NULL;

-- 5b. system_settings — keep harmless config, drop anything that looks like a
--     credential. Values are free text, so the field scanner cannot classify them.
DELETE FROM system_settings
 WHERE setting_key ~* '(key|token|secret|password|passwd|credential|client_id|client_secret|api|webhook|auth|smtp|imap|sendgrid|twilio|stripe|paypal|openrouter|xero|wave|hostwinds|jumpcloud|action1|itarian|voip|hubspot|twenty|itflow|mc_|grafana|kuma)';

-- ── 6. MONEY ────────────────────────────────────────────────────────────────
--  Randomised so no real revenue figure, wallet balance or MRR survives, while
--  the UI still renders plausible amounts (needed for dealer/commission demos).
UPDATE clients          SET credit_balance = round((random()*400+10)::numeric,2),
                            low_balance_threshold = 25;
UPDATE invoices         SET amount = round((random()*600+50)::numeric,2),
                            tax    = round((random()*40+2)::numeric,2);
UPDATE invoices         SET total  = round((amount + tax)::numeric,2);
UPDATE subscriptions    SET price  = round((random()*150+25)::numeric,2),
                            mrr    = round((random()*150+25)::numeric,2);
UPDATE client_services  SET price  = round((random()*150+25)::numeric,2);
UPDATE lead_quotes      SET total_without_tax = round((random()*900+100)::numeric,2),
                            tax_amount        = round((random()*70+5)::numeric,2);
UPDATE lead_quotes      SET total = round((total_without_tax + tax_amount)::numeric,2);
UPDATE purchase_orders  SET total  = round((random()*2000+200)::numeric,2);
UPDATE crm_deals        SET value  = round((random()*5000+500)::numeric,2);
UPDATE budgets          SET amount = round((random()*5000+500)::numeric,2);
UPDATE project_time_entries SET rate = round((random()*60+40)::numeric,2);
UPDATE dealers          SET commission_rate = 0.15;

-- ── 7. ACCOUNTS ─────────────────────────────────────────────────────────────
--  Every surviving login gets the demo password, so no production password can
--  authenticate here and the training credential is deliberate and known.
--  The hash is parked in a temp table because psql does NOT substitute :'vars'
--  inside a dollar-quoted DO block — section 8 has to read it as a row.
CREATE TEMP TABLE _h(h text) ON COMMIT PRESERVE ROWS;
INSERT INTO _h VALUES (:'demo_hash');
UPDATE users SET password = (SELECT h FROM _h);

COMMIT;

-- ── 8. POST-CONDITIONS: fail loudly rather than ship a dirty demo ───────────
DO $verify$
DECLARE n bigint;
BEGIN
  SELECT count(*) INTO n FROM provider_settings;     IF n <> 0 THEN RAISE EXCEPTION 'provider_settings not empty (% rows)', n; END IF;
  SELECT count(*) INTO n FROM credentials;           IF n <> 0 THEN RAISE EXCEPTION 'credential vault not empty (% rows)', n; END IF;
  SELECT count(*) INTO n FROM network_credentials;   IF n <> 0 THEN RAISE EXCEPTION 'network_credentials not empty (% rows)', n; END IF;
  SELECT count(*) INTO n FROM session;               IF n <> 0 THEN RAISE EXCEPTION 'sessions not empty (% rows)', n; END IF;
  SELECT count(*) INTO n FROM system_settings WHERE setting_key ~* '(key|token|secret|password|api|credential|webhook)';
    IF n <> 0 THEN RAISE EXCEPTION 'system_settings still holds % secret-looking keys', n; END IF;
  SELECT count(*) INTO n FROM clients WHERE email NOT LIKE '%@bluemogul-demo.test';
    IF n <> 0 THEN RAISE EXCEPTION '% client emails unmasked', n; END IF;
  SELECT count(*) INTO n FROM users WHERE email NOT LIKE '%@bluemogul-demo.test' AND email <> 'admin@bluemogul.biz';
    IF n <> 0 THEN RAISE EXCEPTION '% user emails unmasked', n; END IF;
  SELECT count(*) INTO n FROM users WHERE password <> (SELECT h FROM _h);
    IF n <> 0 THEN RAISE EXCEPTION '% users still carry a production password hash', n; END IF;
  SELECT count(*) INTO n FROM hostwinds_products WHERE raw IS NOT NULL;
    IF n <> 0 THEN RAISE EXCEPTION '% hostwinds raw payloads survived', n; END IF;
  SELECT count(*) INTO n FROM network_assets WHERE raw IS NOT NULL;
    IF n <> 0 THEN RAISE EXCEPTION '% network raw payloads survived', n; END IF;
  SELECT count(*) INTO n FROM users WHERE email <> 'admin@bluemogul.biz' AND email NOT LIKE '%@bluemogul-demo.test';
    IF n <> 0 THEN RAISE EXCEPTION '% user emails unmasked', n; END IF;
  RAISE NOTICE 'DEMO SCRUB OK: post-conditions satisfied';
END
$verify$;