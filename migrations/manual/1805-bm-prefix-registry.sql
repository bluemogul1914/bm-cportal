-- #1805 dry-run v2 (owner-approved). One transaction; ends in ROLLBACK. Nothing persists.
\set ON_ERROR_STOP 1
BEGIN;

CREATE TABLE bm_prefix_registry (
  prefix     text PRIMARY KEY CHECK (prefix ~ '^BM[A-Za-z]{1,3}$'),
  created_at timestamptz NOT NULL DEFAULT now()
);
-- case-insensitive uniqueness guard: 'bmp' must collide with 'BMP'
CREATE UNIQUE INDEX bm_prefix_registry_lower_uq ON bm_prefix_registry (lower(prefix));

INSERT INTO bm_prefix_registry (prefix) VALUES
('BMA'),('BML'),('BMP'),('BMPO'),('BMI'),('BMT'),('BMB'),('BMPr'),
('BMS'),('BMC'),('BMN'),('BMD'),('BMDL'),('BMM'),('BMET');

CREATE TABLE bm_prefix_counters (
  prefix text NOT NULL REFERENCES bm_prefix_registry(prefix),
  day    date NOT NULL,
  seq    integer NOT NULL DEFAULT 0,
  PRIMARY KEY (prefix, day)
);

CREATE FUNCTION next_bm_code(p text) RETURNS text
LANGUAGE plpgsql AS $$
DECLARE d date := (now() AT TIME ZONE 'America/Chicago')::date; n integer;
BEGIN
  INSERT INTO bm_prefix_counters (prefix, day, seq) VALUES (p, d, 1)
  ON CONFLICT (prefix, day) DO UPDATE SET seq = bm_prefix_counters.seq + 1
  RETURNING seq INTO n;
  RETURN p || '-' || to_char(d, 'YYYYMMDD') || '-' || lpad(n::text, 4, '0');
END $$;

-- AFTER counts (inside txn; live tables are read-only here)
SELECT 'registry_rows' AS m, count(*) FROM bm_prefix_registry;
SELECT 'existing_clients' AS m, count(*) FROM clients;
SELECT 'existing_tickets' AS m, count(*) FROM tickets;
SELECT 'existing_invoices' AS m, count(*) FROM invoices;

-- sample codes (BMPr and BMDL are distinct from BMP and BMD)
SELECT next_bm_code('BMA') AS sample_a1, next_bm_code('BMA') AS sample_a2, next_bm_code('BMPr') AS sample_products, next_bm_code('BMDL') AS sample_dealers;

-- ASSERTION 1a: case-insensitive collision: 'BMp' (valid format) must collide with 'BMP'
DO $$
BEGIN
  BEGIN
    INSERT INTO bm_prefix_registry (prefix) VALUES ('BMp');
    RAISE EXCEPTION 'ASSERT FAILED: BMp accepted despite BMP existing';
  EXCEPTION WHEN unique_violation THEN
    RAISE NOTICE 'ASSERT OK: case collision BMp vs BMP rejected by lower() index (unique_violation)';
  END;
END $$;

-- ASSERTION 1b: owner's literal case 'bmp' must be rejected (format CHECK, since prefixes must start with uppercase BM)
DO $$
BEGIN
  BEGIN
    INSERT INTO bm_prefix_registry (prefix) VALUES ('bmp');
    RAISE EXCEPTION 'ASSERT FAILED: bmp accepted';
  EXCEPTION WHEN check_violation THEN
    RAISE NOTICE 'ASSERT OK: bmp rejected by format CHECK (check_violation)';
  END;
END $$;

-- ASSERTION 2: case-distinct but non-colliding prefixes are accepted (BMPr vs BMP, BMDL vs BMD) — already inserted above; confirm presence
SELECT 'distinct_ok' AS m, count(*) FROM bm_prefix_registry WHERE prefix IN ('BMPr','BMDL','BMP','BMD');

-- ASSERTION 3: malformed prefix rejected by CHECK
DO $$
BEGIN
  BEGIN
    INSERT INTO bm_prefix_registry (prefix) VALUES ('XX');
    RAISE EXCEPTION 'ASSERT FAILED: malformed prefix accepted';
  EXCEPTION WHEN check_violation THEN
    RAISE NOTICE 'ASSERT OK: malformed prefix XX rejected (check_violation)';
  END;
END $$;

COMMIT;

-- EVIDENCE (applied to Neon ep-twilight-union-a6wqiyu6, PG 18.6, 2026-11-06, psql exit 0):
--   before: registry=none counters=none next_bm_code=0  tickets=6 clients=9 projects=1 invoices=12
--   after:  registry_rows=15 counters=present next_bm_code=1  tickets=6 clients=9 projects=1 invoices=12
--   side effect: sample next_bm_code() calls in this script wrote counter rows BMA:2, BMPr:1, BMDL:1
--   NOT applied: any DELETE of the 5 sample rows (explicitly out of scope).
