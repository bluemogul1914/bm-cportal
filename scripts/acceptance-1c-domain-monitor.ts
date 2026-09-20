/**
 * Slice 1c acceptance test — runs the REAL Phase-1c DDL from server/migrations.ts
 * against a scratch Postgres, then exercises the module end to end against live
 * RDAP/TLS endpoints. Prints PASS/FAIL per assertion and exits non-zero on failure.
 */
import fs from "fs";
import { join } from "path";
import pg from "pg";
import {
  createDomain, updateDomain, createCertificate, listDomains, listCertificates,
  runDomainCertCheck, expiryBuckets, domainHistory,
} from "../server/domain-monitor";

const pool = new pg.Pool({ connectionString: process.env.TEST_DATABASE_URL, max: 3 });
let pass = 0, fail = 0;
const check = (name: string, ok: boolean, extra = "") => {
  console.log(`${ok ? "PASS" : "FAIL"}  ${name}${extra ? "  — " + extra : ""}`);
  ok ? pass++ : fail++;
};

(async () => {
  // ── 1. Apply the real DDL lifted out of the migration ─────────────────────
  const src = fs.readFileSync(join(process.cwd(), "server", "migrations.ts"), "utf8");
  const start = src.indexOf("Phase 1c: domains, certificates");
  const end = src.indexOf("Domain/certificate monitor migrations applied");
  const block = src.slice(start, end);
  const stmts = [...block.matchAll(/sql`([\s\S]*?)`/g)].map((m) => m[1]);
  console.log(`--- applying ${stmts.length} DDL statements from migrations.ts ---`);
  for (const s of stmts) await pool.query(s);
  const t = await pool.query(
    `SELECT table_name FROM information_schema.tables WHERE table_schema='public' AND table_name IN
     ('domains','domain_history','certificates','certificate_history','domain_sync_runs') ORDER BY 1`,
  );
  check("DDL created all five tables", t.rows.length === 5, t.rows.map((r: any) => r.table_name).join(","));

  await pool.query(`CREATE TABLE IF NOT EXISTS clients (id SERIAL PRIMARY KEY, name VARCHAR(255), email VARCHAR(255))`);
  await pool.query(`INSERT INTO clients (name, email) VALUES ('Blue Mogul Enterprise','ops@bluemogul.us')`);
  await pool.query(`TRUNCATE domains, domain_history, certificates, certificate_history, domain_sync_runs RESTART IDENTITY`);

  // ── 2. Create a real domain + certificate ────────────────────────────────
  const domId = await createDomain(pool, { name: "https://BlueMogul.US/path", clientId: 1, autoRenew: true });
  check("createDomain normalises the URL to a bare host", (await listDomains(pool))[0].name === "bluemogul.us");
  const certId = await createCertificate(pool, { hostname: "bluemogul.us", clientId: 1 });
  check("createCertificate stores the host", (await listCertificates(pool))[0].hostname === "bluemogul.us");

  // Duplicate must be rejected by the unique index (not silently duplicated).
  let dupRejected = false;
  try { await createDomain(pool, { name: "bluemogul.us" }); } catch { dupRejected = true; }
  check("duplicate domain is rejected by the unique index", dupRejected);

  // ── 3. First probe: fills the live registry/TLS facts ────────────────────
  const run1 = await runDomainCertCheck(pool);
  const d1 = (await listDomains(pool))[0];
  const c1 = (await listCertificates(pool))[0];
  check("probe succeeded for both records", run1.ok === 2 && run1.errors.length === 0, JSON.stringify({ ok: run1.ok, errors: run1.errors }));
  check("registry expiry read from RDAP (.us registry, not the bootstrap)", !!d1.expires_at, String(d1.expires_at));
  check("registrar read from RDAP", !!d1.registrar, String(d1.registrar));
  check("registry status flags stored", !!d1.registry_status, String(d1.registry_status).slice(0, 60));
  check("TLS issuer + expiry read from a real handshake", !!c1.issuer && !!c1.expires_at, `${c1.issuer} / ${c1.expires_at}`);
  check("history recorded the first observed expiry", (await domainHistory(pool, domId)).some((h: any) => h.field === "expires_at"));

  // ── 4. Second probe must NOT fabricate a change ──────────────────────────
  const hBefore = (await domainHistory(pool, domId)).length;
  await runDomainCertCheck(pool);
  const hAfter = (await domainHistory(pool, domId)).length;
  check("re-probing an unchanged domain appends no history", hBefore === hAfter, `${hBefore} -> ${hAfter}`);

  // ── 5. A moved date IS recorded (this is the point of the history) ───────
  await pool.query(`UPDATE domains SET expires_at = NOW() + INTERVAL '3 days' WHERE id = $1`, [domId]);
  await runDomainCertCheck(pool);
  const hist = await domainHistory(pool, domId);
  check("a relocated expiry is appended to history", hist.length > hAfter, JSON.stringify(hist[0]?.new_value));
  const d2 = (await listDomains(pool))[0];
  check("probe restores the true registry date over a manual edit", new Date(d2.expires_at).getUTCFullYear() === 2027, String(d2.expires_at));
  check("the displaced manual value is retained in history", hist.some((h: any) => h.field === "expires_at" && String(h.old_value).length > 0));

  // ── 6. Buckets ───────────────────────────────────────────────────────────
  const run3 = await runDomainCertCheck(pool);
  check("live re-probe after the manual edits cleanly succeeds", run3.ok === 2, JSON.stringify(run3.errors));
  await pool.query(`UPDATE domains SET expires_at = NOW() + INTERVAL '2 days' WHERE id = $1`, [domId]);
  await pool.query(`UPDATE certificates SET expires_at = NOW() - INTERVAL '1 day' WHERE id = $1`, [certId]);
  const b = await expiryBuckets(pool);
  check("bucket: domain inside 7 days", b.domains.d7 === 1, JSON.stringify(b.domains));
  check("bucket: certificate already expired", b.certificates.expired === 1, JSON.stringify(b.certificates));

  // ── 7. A failing probe is recorded per row, never fatal ──────────────────
  const badId = await createDomain(pool, { name: "no-such-host-xyz123.us" });
  const run2 = await runDomainCertCheck(pool);
  const badRow = await pool.query(`SELECT last_check_error, expires_at FROM domains WHERE id = $1`, [badId]);
  check("unresolvable domain: error recorded on the row", !!badRow.rows[0].last_check_error, String(badRow.rows[0].last_check_error).slice(0, 80));
  check("unresolvable domain: no invented expiry", badRow.rows[0].expires_at === null);
  check("a failing item did not abort the run", run2.ok >= 2 && run2.errors.length === 1, JSON.stringify({ ok: run2.ok, errs: run2.errors }));

  // ── 8. Filters ───────────────────────────────────────────────────────────
  // (The probe above restored the true registry date, so put a near date back
  // before exercising the bucket filter — the filter reads the stored value.)
  await pool.query(`UPDATE domains SET expires_at = NOW() + INTERVAL '2 days' WHERE id = $1`, [domId]);
  const byBucket = await listDomains(pool, { bucket: "d7" });
  check("bucket filter returns only the ≤7-day domain", byBucket.length === 1 && byBucket[0].name === "bluemogul.us", JSON.stringify(byBucket.map((r: any) => r.name)));
  const byClient = await listDomains(pool, { clientId: 1 });
  check("client filter joins the client name", byClient[0]?.client_name === "Blue Mogul Enterprise");
  const searched = await listDomains(pool, { q: "bluemogul" });
  check("text search matches by substring", searched.length === 1);

  // ── 9. updateDomain records history ──────────────────────────────────────
  await updateDomain(pool, domId, { registrar: "NameCheap (manual override)" });
  check("manual registrar edit lands in history", (await domainHistory(pool, domId)).some((h: any) => h.field === "registrar"));
  check("run log written", (await pool.query(`SELECT COUNT(*)::int c FROM domain_sync_runs`)).rows[0].c >= 2);

  console.log(`\n${pass} passed, ${fail} failed`);
  await pool.end();
  process.exit(fail ? 1 : 0);
})().catch(async (e) => { console.error("HARNESS ERROR:", e); await pool.end(); process.exit(2); });