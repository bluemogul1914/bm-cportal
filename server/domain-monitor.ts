/**
 * Domain & certificate expiry monitor (ITFlow parity — Phase 1c).
 *
 * Two independent probes, both read-only against the public internet:
 *   - RDAP (`https://rdap.org/domain/<name>`, which bootstraps to the registry's
 *     authoritative RDAP server) for the registration expiration date, registrar
 *     and registry status flags.
 *   - A real TLS handshake for the served certificate's validity window, issuer
 *     and serial.
 *
 * Rules that matter:
 *  1. A probe failure is recorded as a per-item error, never fatal — one dead
 *     registrar must not blank the page or abandon the rest of the run.
 *  2. Every observed change to an expiration date / registrar / issuer appends a
 *     history row. History is append-only: it is the audit trail for "when did
 *     this date move", which is how a silent registrar transfer gets noticed.
 *  3. Times are stored as TIMESTAMPs in UTC; expiry buckets are computed in SQL
 *     so the page, the dashboard and any future report always agree.
 */
import tls from "tls";
import type pg from "pg";

export type ProbeError = { item: string; error: string };
export type RunResult = { ok: number; changed: number; errors: ProbeError[]; checked_at: string };

const UA = "BlueMogulPortal/1.0 (+https://portal.bluemogul.us)";

/**
 * Per-TLD RDAP base URLs, consulted BEFORE the IANA bootstrap at rdap.org.
 *
 * Why this exists: the IANA bootstrap only publishes gTLD + a few ccTLD
 * endpoints — `.us` is not in it, so rdap.org answers 404 "No RDAP service is
 * available for this resource" for every .us domain, including our own. The
 * registry's own endpoint works. Any TLD we hold that is missing from the
 * bootstrap gets an entry here; without one, the row is flagged for manual
 * entry rather than silently left blank.
 */
const TLD_RDAP: Record<string, string[]> = {
  us: ["https://rdap.nic.us/domain/"],
};

function rdapBases(name: string): string[] {
  const tld = name.split(".").pop() || "";
  return [...(TLD_RDAP[tld] || []), "https://rdap.org/domain/"];
}

/** Strip a URL/protocol/path down to a bare hostname. */
export function normaliseHost(input: string): string {
  return String(input || "")
    .trim()
    .toLowerCase()
    .replace(/^[a-z]+:\/\//, "")
    .replace(/\/.*$/, "")
    .replace(/\.$/, "");
}

async function fetchJson(url: string, timeoutMs = 15000): Promise<any> {
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), timeoutMs);
  try {
    const res = await fetch(url, {
      redirect: "follow",
      signal: ctl.signal,
      headers: { Accept: "application/rdap+json, application/json", "User-Agent": UA },
    });
    if (!res.ok) {
      // rdap.org answers 404 with a body that says whether the TLD has no RDAP
      // service at all (a config gap for us) or the domain simply isn't
      // registered (a fact about the customer). Never conflate the two.
      let detail = "";
      try { detail = String((await res.json())?.title || ""); } catch { /* non-JSON body */ }
      const err: any = new Error(
        detail.toLowerCase().includes("no rdap service")
          ? "no RDAP service published for this TLD"
          : `RDAP responded ${res.status}`,
      );
      err.noRdapService = detail.toLowerCase().includes("no rdap service");
      err.status = res.status;
      throw err;
    }
    return await res.json();
  } finally {
    clearTimeout(t);
  }
}

/** Pull a `fn` value out of an RDAP jCard. */
function vcardFn(entity: any): string | null {
  const card = entity?.vcardArray?.[1];
  if (!Array.isArray(card)) return null;
  const fn = card.find((row: any) => Array.isArray(row) && row[0] === "fn");
  return fn && typeof fn[3] === "string" ? fn[3] : null;
}

export type DomainProbe = {
  name: string;
  registrar: string | null;
  expires_at: string | null;
  registered_at: string | null;
  last_changed_at: string | null;
  statuses: string[];
  raw_event_count: number;
};

/** Ask the registry (over RDAP) when a domain expires and who holds it. */
export async function probeDomain(name: string): Promise<DomainProbe> {
  const host = normaliseHost(name);
  if (!host || !host.includes(".")) throw new Error("not a domain name");

  // Try the registry endpoint for this TLD first, then the IANA bootstrap.
  let data: any = null;
  const bases = rdapBases(host);
  const bootstrap = "https://rdap.org/domain/";
  const attempts: { base: string; status?: number; noService?: boolean; message: string }[] = [];
  for (const base of bases) {
    try {
      data = await fetchJson(`${base}${encodeURIComponent(host)}`);
      break;
    } catch (e: any) {
      attempts.push({ base, status: e?.status, noService: !!e?.noRdapService, message: e?.message || "failed" });
    }
  }
  if (!data) {
    const registry = attempts.find((a) => a.base !== bootstrap);
    const tld = host.split(".").pop();
    const detail = attempts.map((a) => `${new URL(a.base).host}: ${a.message}`).join("; ");
    if (registry?.status === 404) {
      // The registry itself answered "no such registration" — the name is not
      // registered, or it is a subdomain of one that is. That is a fact about
      // the name, not a gap in our configuration.
      throw new Error(`no registration record for ${host} (registry answered 404) — track the registered parent domain, or set the expiry by hand`);
    }
    if (attempts.length && attempts.every((a) => a.noService)) {
      throw new Error(`no RDAP service published for .${tld} — add its registry URL to TLD_RDAP, or set the expiry by hand (${detail})`);
    }
    throw new Error(`RDAP lookup failed (${detail})`);
  }

  const events: any[] = Array.isArray(data?.events) ? data.events : [];
  const eventDate = (action: string): string | null => {
    const e = events.find((x) => String(x?.eventAction || "").toLowerCase() === action);
    if (!e?.eventDate) return null;
    const d = new Date(e.eventDate);
    return isNaN(d.getTime()) ? null : d.toISOString();
  };

  const entities: any[] = Array.isArray(data?.entities) ? data.entities : [];
  const registrarEntity = entities.find(
    (e) => Array.isArray(e?.roles) && e.roles.map((r: string) => String(r).toLowerCase()).includes("registrar"),
  );
  const registrar = registrarEntity ? vcardFn(registrarEntity) : null;

  return {
    name: host,
    registrar,
    expires_at: eventDate("expiration"),
    registered_at: eventDate("registration"),
    last_changed_at: eventDate("last changed") ?? eventDate("last update of rdap database"),
    statuses: Array.isArray(data?.status) ? data.status.map((s: any) => String(s)) : [],
    raw_event_count: events.length,
  };
}

export type CertProbe = {
  hostname: string;
  expires_at: string | null;
  not_before: string | null;
  issuer: string | null;
  subject: string | null;
  serial: string | null;
  sans: string[];
};

/** Complete a TLS handshake and read the served certificate's validity window. */
export function probeCertificate(hostname: string, port = 443): Promise<CertProbe> {
  const host = normaliseHost(hostname);
  return new Promise((resolve, reject) => {
    const socket = tls.connect(
      {
        host,
        port,
        servername: host,
        // The point of the probe is the validity window, not trust — an expired
        // or self-signed cert is exactly what we want to be able to record.
        rejectUnauthorized: false,
        timeout: 15000,
      },
      () => {
        try {
          const cert = socket.getPeerCertificate();
          socket.end();
          if (!cert || !Object.keys(cert).length) return reject(new Error("no certificate presented"));
          const d = (v: any) => {
            if (!v) return null;
            const dt = new Date(v);
            return isNaN(dt.getTime()) ? null : dt.toISOString();
          };
          const issuer = [cert.issuer?.O, cert.issuer?.CN].filter(Boolean).join(" / ") || null;
          const subject = [cert.subject?.CN, cert.subject?.O].filter(Boolean).join(" / ") || null;
          const sans = String(cert.subjectaltname || "")
            .split(",")
            .map((s) => s.trim().replace(/^DNS:/i, ""))
            .filter(Boolean);
          resolve({
            hostname: host,
            expires_at: d(cert.valid_to),
            not_before: d(cert.valid_from),
            issuer,
            subject,
            serial: cert.serialNumber ? String(cert.serialNumber) : null,
            sans,
          });
        } catch (e: any) {
          socket.destroy();
          reject(e);
        }
      },
    );
    socket.on("timeout", () => { socket.destroy(); reject(new Error("TLS handshake timed out")); });
    socket.on("error", (e: any) => reject(new Error(e?.code ? `${e.code}` : e?.message || "TLS error")));
  });
}

/** Append a history row when a tracked field actually moved. */
async function recordChange(
  pool: pg.Pool,
  table: "domain_history" | "certificate_history",
  idColumn: "domain_id" | "certificate_id",
  id: number,
  field: string,
  oldValue: any,
  newValue: any,
) {
  const norm = (v: any) => (v === null || v === undefined ? null : v instanceof Date ? v.toISOString() : String(v));
  const before = norm(oldValue);
  const after = norm(newValue);
  if (before === after) return false;
  await pool.query(
    `INSERT INTO ${table} (${idColumn}, field, old_value, new_value) VALUES ($1,$2,$3,$4)`,
    [id, field, before, after],
  );
  return true;
}

const iso = (v: any): string | null => {
  if (v === null || v === undefined) return null;
  const d = v instanceof Date ? v : new Date(v);
  return isNaN(d.getTime()) ? null : d.toISOString().slice(0, 10);
};

/**
 * Probe every due domain and managed certificate. Sequential per kind so a
 * registry that rate-limits us cannot turn one run into a burst of failures.
 */
export async function runDomainCertCheck(
  pool: pg.Pool,
  opts: { domainId?: number; certificateId?: number; limit?: number } = {},
): Promise<RunResult> {
  const errors: ProbeError[] = [];
  let ok = 0;
  let changed = 0;

  const domSql = opts.domainId
    ? `SELECT id, name, registrar, expires_at FROM domains WHERE id = $1`
    : `SELECT id, name, registrar, expires_at FROM domains
       ORDER BY last_checked_at NULLS FIRST LIMIT ${Number(opts.limit) || 200}`;
  const doms = await pool.query(domSql, opts.domainId ? [opts.domainId] : []);

  for (const row of doms.rows) {
    try {
      const p = await probeDomain(row.name);
      const changedRegistrar = await recordChange(pool, "domain_history", "domain_id", row.id, "registrar", row.registrar, p.registrar);
      const changedExpiry = await recordChange(pool, "domain_history", "domain_id", row.id, "expires_at", iso(row.expires_at), iso(p.expires_at));
      if (changedRegistrar || changedExpiry) changed++;
      await pool.query(
        `UPDATE domains
            SET registrar = COALESCE($2, registrar),
                expires_at = COALESCE($3::timestamptz, expires_at),
                registered_at = COALESCE($4::timestamptz, registered_at),
                registry_status = $5,
                last_checked_at = NOW(),
                last_check_error = NULL,
                status = CASE WHEN $3::timestamptz IS NULL THEN status ELSE 'active' END,
                updated_at = NOW()
          WHERE id = $1`,
        [row.id, p.registrar, p.expires_at, p.registered_at, p.statuses.join(", ") || null],
      );
      ok++;
    } catch (e: any) {
      errors.push({ item: String(row.name), error: e?.message || "probe failed" });
      await pool.query(`UPDATE domains SET last_checked_at = NOW(), last_check_error = $2 WHERE id = $1`, [
        row.id,
        String(e?.message || "probe failed").slice(0, 400),
      ]).catch(() => {});
    }
  }

  const certSql = opts.certificateId
    ? `SELECT id, hostname, issuer, expires_at, port FROM certificates WHERE id = $1`
    : `SELECT id, hostname, issuer, expires_at, port FROM certificates
       ORDER BY last_checked_at NULLS FIRST LIMIT ${Number(opts.limit) || 200}`;
  const certs = await pool.query(certSql, opts.certificateId ? [opts.certificateId] : []);

  for (const row of certs.rows) {
    try {
      const p = await probeCertificate(row.hostname, Number(row.port) || 443);
      const changedIssuer = await recordChange(pool, "certificate_history", "certificate_id", row.id, "issuer", row.issuer, p.issuer);
      const changedExpiry = await recordChange(pool, "certificate_history", "certificate_id", row.id, "expires_at", iso(row.expires_at), iso(p.expires_at));
      if (changedIssuer || changedExpiry) changed++;
      await pool.query(
        `UPDATE certificates
            SET issuer = COALESCE($2, issuer),
                subject = COALESCE($3, subject),
                serial = COALESCE($4, serial),
                not_before = COALESCE($5::timestamptz, not_before),
                expires_at = COALESCE($6::timestamptz, expires_at),
                sans = COALESCE($7, sans),
                last_checked_at = NOW(),
                last_check_error = NULL,
                status = CASE WHEN $6::timestamptz IS NULL THEN status ELSE 'active' END,
                updated_at = NOW()
          WHERE id = $1`,
        [row.id, p.issuer, p.subject, p.serial, p.not_before, p.expires_at, p.sans.join(", ") || null],
      );
      ok++;
    } catch (e: any) {
      errors.push({ item: String(row.hostname), error: e?.message || "probe failed" });
      await pool.query(`UPDATE certificates SET last_checked_at = NOW(), last_check_error = $2 WHERE id = $1`, [
        row.id,
        String(e?.message || "probe failed").slice(0, 400),
      ]).catch(() => {});
    }
  }

  try {
    await pool.query(
      `INSERT INTO domain_sync_runs (ok_count, changed_count, error_count, errors) VALUES ($1,$2,$3,$4)`,
      [ok, changed, errors.length, errors.length ? JSON.stringify(errors).slice(0, 4000) : null],
    );
  } catch { /* the run log is best-effort; never fail the check on it */ }

  return { ok, changed, errors, checked_at: new Date().toISOString() };
}

export type Bucket = { bucket: string; count: number };

/** Expiry buckets for both domains and certificates, computed in one pass. */
export async function expiryBuckets(pool: pg.Pool) {
  const q = (table: string) => `
    SELECT
      COUNT(*) FILTER (WHERE expires_at IS NULL)::int AS unknown,
      COUNT(*) FILTER (WHERE expires_at < NOW())::int AS expired,
      COUNT(*) FILTER (WHERE expires_at >= NOW() AND expires_at < NOW() + INTERVAL '8 days')::int AS d7,
      COUNT(*) FILTER (WHERE expires_at >= NOW() + INTERVAL '8 days' AND expires_at < NOW() + INTERVAL '15 days')::int AS d14,
      COUNT(*) FILTER (WHERE expires_at >= NOW() + INTERVAL '15 days' AND expires_at < NOW() + INTERVAL '31 days')::int AS d30,
      COUNT(*)::int AS total
    FROM ${table}`;
  const [d, c] = await Promise.all([pool.query(q("domains")), pool.query(q("certificates"))]);
  const merge = (rows: any[]) => ({
    unknown: rows[0].unknown, expired: rows[0].expired, d7: rows[0].d7, d14: rows[0].d14, d30: rows[0].d30, total: rows[0].total,
  });
  return { domains: merge(d.rows), certificates: merge(c.rows) };
}

export async function listDomains(
  pool: pg.Pool,
  opts: { clientId?: number; q?: string; bucket?: string } = {},
) {
  const where: string[] = [];
  const args: any[] = [];
  if (opts.clientId) { args.push(opts.clientId); where.push(`d.client_id = $${args.length}`); }
  if (opts.q) { args.push(`%${opts.q}%`); where.push(`d.name ILIKE $${args.length}`); }
  if (opts.bucket === "expired") where.push(`d.expires_at < NOW()`);
  if (opts.bucket === "d7") where.push(`d.expires_at >= NOW() AND d.expires_at < NOW() + INTERVAL '8 days'`);
  if (opts.bucket === "d14") where.push(`d.expires_at >= NOW() + INTERVAL '8 days' AND d.expires_at < NOW() + INTERVAL '15 days'`);
  if (opts.bucket === "d30") where.push(`d.expires_at >= NOW() + INTERVAL '15 days' AND d.expires_at < NOW() + INTERVAL '31 days'`);
  if (opts.bucket === "unknown") where.push(`d.expires_at IS NULL`);

  const r = await pool.query(
    `SELECT d.id, d.client_id, c.name AS client_name, d.name, d.registrar, d.expires_at, d.registered_at,
            d.auto_renew, d.status, d.registry_status, d.notes, d.last_checked_at, d.last_check_error,
            (d.expires_at IS NOT NULL) AS has_expiry,
            CASE WHEN d.expires_at IS NULL THEN NULL
                 ELSE (d.expires_at::date - CURRENT_DATE) END AS days_left
       FROM domains d
       LEFT JOIN clients c ON c.id = d.client_id
       ${where.length ? "WHERE " + where.join(" AND ") : ""}
       ORDER BY d.expires_at NULLS LAST, d.name
       LIMIT 500`,
    args,
  );
  return r.rows;
}

export async function listCertificates(
  pool: pg.Pool,
  opts: { clientId?: number; q?: string } = {},
) {
  const where: string[] = [];
  const args: any[] = [];
  if (opts.clientId) { args.push(opts.clientId); where.push(`t.client_id = $${args.length}`); }
  if (opts.q) { args.push(`%${opts.q}%`); where.push(`t.hostname ILIKE $${args.length}`); }
  const r = await pool.query(
    `SELECT t.id, t.client_id, c.name AS client_name, t.hostname, t.port, t.issuer, t.subject, t.serial,
            t.not_before, t.expires_at, t.sans, t.status, t.last_checked_at, t.last_check_error,
            CASE WHEN t.expires_at IS NULL THEN NULL
                 ELSE (t.expires_at::date - CURRENT_DATE) END AS days_left
       FROM certificates t
       LEFT JOIN clients c ON c.id = t.client_id
       ${where.length ? "WHERE " + where.join(" AND ") : ""}
       ORDER BY t.expires_at NULLS LAST, t.hostname
       LIMIT 500`,
    args,
  );
  return r.rows;
}

export async function createDomain(
  pool: pg.Pool,
  args: { name: string; clientId?: number | null; registrar?: string | null; expiresAt?: string | null; autoRenew?: boolean; notes?: string | null },
) {
  const name = normaliseHost(args.name);
  if (!name || !name.includes(".")) throw new Error("A domain name like example.com is required");
  const r = await pool.query(
    `INSERT INTO domains (client_id, name, registrar, expires_at, auto_renew, notes)
     VALUES ($1,$2,$3,$4::timestamptz,$5,$6)
     RETURNING id`,
    [args.clientId || null, name, args.registrar || null, args.expiresAt || null, args.autoRenew !== false, args.notes || null],
  );
  return r.rows[0].id as number;
}

export async function updateDomain(
  pool: pg.Pool,
  id: number,
  args: { clientId?: number | null; registrar?: string | null; expiresAt?: string | null; autoRenew?: boolean | null; notes?: string | null; status?: string | null },
) {
  const before = await pool.query(`SELECT registrar, expires_at FROM domains WHERE id = $1`, [id]);
  if (!before.rows.length) throw new Error("Domain not found");
  await pool.query(
    `UPDATE domains
        SET client_id = COALESCE($2, client_id),
            registrar = COALESCE($3, registrar),
            expires_at = COALESCE($4::timestamptz, expires_at),
            auto_renew = COALESCE($5, auto_renew),
            notes = COALESCE($6, notes),
            status = COALESCE($7, status),
            updated_at = NOW()
      WHERE id = $1`,
    [id, args.clientId ?? null, args.registrar ?? null, args.expiresAt ?? null, args.autoRenew ?? null, args.notes ?? null, args.status ?? null],
  );
  await recordChange(pool, "domain_history", "domain_id", id, "expires_at", iso(before.rows[0].expires_at), iso(args.expiresAt));
  await recordChange(pool, "domain_history", "domain_id", id, "registrar", before.rows[0].registrar, args.registrar);
}

export async function createCertificate(
  pool: pg.Pool,
  args: { hostname: string; clientId?: number | null; port?: number | null; notes?: string | null },
) {
  const hostname = normaliseHost(args.hostname);
  if (!hostname) throw new Error("A hostname is required");
  const r = await pool.query(
    `INSERT INTO certificates (client_id, hostname, port, notes) VALUES ($1,$2,$3,$4) RETURNING id`,
    [args.clientId || null, hostname, args.port || 443, args.notes || null],
  );
  return r.rows[0].id as number;
}

export async function domainHistory(pool: pg.Pool, domainId: number) {
  const r = await pool.query(
    `SELECT field, old_value, new_value, at FROM domain_history WHERE domain_id = $1 ORDER BY at DESC LIMIT 50`,
    [domainId],
  );
  return r.rows;
}

export async function lastRunSummary(pool: pg.Pool) {
  try {
    const r = await pool.query(
      `SELECT ok_count, changed_count, error_count, errors, created_at FROM domain_sync_runs ORDER BY id DESC LIMIT 1`,
    );
    return r.rows[0] || null;
  } catch {
    return null;
  }
}