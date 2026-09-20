/**
 * Credential vault (ITFlow parity — Phase 1a).
 *
 * Design rules, in order of importance:
 *  1. A secret is ONLY ever stored as AES-256-GCM ciphertext (`v1:` + base64(iv|tag|ct)).
 *     There is no code path that writes plaintext into a column.
 *  2. The key is derived (HKDF-SHA256) from PORTAL_SECRET. It is never stored in the
 *     database, so a database dump alone does not disclose any secret.
 *  3. Listing NEVER returns a secret — only a boolean "has one". Revealing is a separate,
 *     audited action that writes an `activity_log` row before the plaintext is returned.
 *  4. Key versioning: every row records `key_version`, so a future key change can be
 *     rolled with a re-encrypt migration instead of losing data.
 */
import crypto from "crypto";
import type pg from "pg";

const V1 = "v1:";

/** Derive the 32-byte vault key. Throws rather than degrading to plaintext. */
export function vaultKey(): Buffer {
  const secret = process.env.PORTAL_SECRET || process.env.SESSION_SECRET || "";
  if (secret.length < 16) {
    throw new Error("Credential vault unavailable: PORTAL_SECRET is not configured");
  }
  // hkdfSync returns an ArrayBuffer — wrap it in a Buffer.
  return Buffer.from(
    crypto.hkdfSync(
      "sha256",
      Buffer.from(secret, "utf8"),
      Buffer.from("bm-portal-vault-salt-v1", "utf8"),
      Buffer.from("bm-portal-credential-vault-v1", "utf8"),
      32,
    ),
  );
}

export function hasVaultKey(): boolean {
  try { vaultKey(); return true; } catch { return false; }
}

export function encryptSecret(plain: string): string {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv("aes-256-gcm", vaultKey(), iv);
  const ct = Buffer.concat([cipher.update(plain, "utf8"), cipher.final()]);
  return V1 + Buffer.concat([iv, cipher.getAuthTag(), ct]).toString("base64");
}

export function decryptSecret(stored: string): string {
  if (!stored || !stored.startsWith(V1)) throw new Error("Unsupported credential encoding");
  const raw = Buffer.from(stored.slice(V1.length), "base64");
  if (raw.length < 29) throw new Error("Corrupt credential payload");
  const iv = raw.subarray(0, 12), tag = raw.subarray(12, 28), ct = raw.subarray(28);
  const decipher = crypto.createDecipheriv("aes-256-gcm", vaultKey(), iv);
  decipher.setAuthTag(tag);
  return Buffer.concat([decipher.update(ct), decipher.final()]).toString("utf8");
}

/** Mask a secret for display: enough to recognise, never enough to use. */
export function maskSecret(value: string): string {
  if (!value) return "";
  if (value.length <= 4) return "••••";
  return "•".repeat(Math.min(12, value.length - 2)) + value.slice(-2);
}

export type VaultActor = { userId?: number | null; email?: string | null; ip?: string | null };

async function audit(pool: pg.Pool, actor: VaultActor, action: string, credentialId: number, details: string) {
  try {
    await pool.query(
      `INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address, created_at)
       VALUES ($1, $2, 'credential', $3, $4, $5, NOW())`,
      [actor.userId ?? null, action, credentialId, details, actor.ip ?? null],
    );
  } catch {
    /* auditing must never block a legitimate read */
  }
}

/**
 * Rotation state for a credential: "ok" | "due" | "none".
 * `last_rotated_at` falls back to `created_at` so a new secret isn't instantly overdue.
 */
function rotationState(row: any): { state: string; days: number | null } {
  const days = row.rotation_days === null || row.rotation_days === undefined ? null : Number(row.rotation_days);
  if (!days || days <= 0) return { state: "none", days: null };
  const from = new Date(row.last_rotated_at || row.created_at || Date.now());
  const ageDays = Math.floor((Date.now() - from.getTime()) / 86400000);
  return { state: ageDays >= days ? "due" : "ok", days: ageDays };
}

export async function vaultStatus(pool: pg.Pool) {
  const ready = hasVaultKey();
  let total = 0, clients = 0, due = 0, otp = 0;
  if (ready) {
    const { rows } = await pool.query(
      `SELECT COUNT(*)::int AS total,
              COUNT(DISTINCT client_id)::int AS clients,
              COUNT(*) FILTER (WHERE otp_secret_cipher IS NOT NULL AND otp_secret_cipher <> '')::int AS otp,
              COUNT(*) FILTER (
                WHERE rotation_days IS NOT NULL AND rotation_days > 0
                  AND COALESCE(last_rotated_at, created_at) < NOW() - (rotation_days || ' days')::interval
              )::int AS due
         FROM credentials`,
    );
    total = rows[0].total; clients = rows[0].clients; otp = rows[0].otp; due = rows[0].due;
  }
  return { vault_ready: ready, total, clients, due_for_rotation: due, with_otp: otp };
}

/** List credentials as METADATA ONLY — no ciphertext, no plaintext, ever. */
export async function listCredentials(pool: pg.Pool, opts: { clientId?: number; category?: string; q?: string } = {}) {
  const where: string[] = [];
  const params: any[] = [];
  if (opts.clientId) { params.push(opts.clientId); where.push(`c.client_id = $${params.length}`); }
  if (opts.category) { params.push(opts.category); where.push(`c.category = $${params.length}`); }
  if (opts.q) {
    params.push(`%${opts.q.toLowerCase()}%`);
    where.push(`(LOWER(c.label) LIKE $${params.length} OR LOWER(COALESCE(c.username,'')) LIKE $${params.length} OR LOWER(COALESCE(cl.name,'')) LIKE $${params.length})`);
  }
  const { rows } = await pool.query(
    `SELECT c.id, c.client_id, c.asset_id, c.service_id, c.label, c.username, c.url, c.notes,
            c.category, c.rotation_days, c.last_rotated_at, c.created_at, c.updated_at,
            (c.secret_cipher IS NOT NULL AND c.secret_cipher <> '')     AS has_secret,
            (c.otp_secret_cipher IS NOT NULL AND c.otp_secret_cipher <> '') AS has_otp,
            cl.name AS client_name
       FROM credentials c
       LEFT JOIN clients cl ON cl.id = c.client_id
       ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY COALESCE(cl.name, ''), c.label`,
    params,
  );
  return rows.map((r) => ({ ...r, rotation: rotationState(r), secret_cipher: undefined, otp_secret_cipher: undefined }));
}

export async function createCredential(pool: pg.Pool, args: {
  clientId?: number | null; assetId?: number | null; serviceId?: number | null;
  label: string; username?: string | null; secret?: string | null; otpSecret?: string | null;
  url?: string | null; notes?: string | null; category?: string | null; rotationDays?: number | null;
  tags?: string[];
}, actor: VaultActor): Promise<number> {
  const label = (args.label || "").trim();
  if (!label) throw new Error("label is required");
  const secret = (args.secret || "").trim();
  const otp = (args.otpSecret || "").trim();
  const { rows } = await pool.query(
    `INSERT INTO credentials
        (client_id, asset_id, service_id, label, username, secret_cipher, otp_secret_cipher,
         url, notes, category, rotation_days, last_rotated_at, created_by, created_at, updated_at)
     VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11, NOW(), $12, NOW(), NOW())
     RETURNING id`,
    [
      args.clientId ?? null, args.assetId ?? null, args.serviceId ?? null, label,
      (args.username || "").trim() || null,
      secret ? encryptSecret(secret) : null,
      otp ? encryptSecret(otp) : null,
      (args.url || "").trim() || null,
      (args.notes || "").trim() || null,
      (args.category || "general").trim() || "general",
      args.rotationDays && args.rotationDays > 0 ? Math.floor(args.rotationDays) : null,
      actor.userId ?? null,
    ],
  );
  const id = rows[0].id;
  for (const tag of (args.tags || []).map((t) => t.trim()).filter(Boolean)) {
    await pool.query(`INSERT INTO credential_tags (credential_id, tag) VALUES ($1, $2)`, [id, tag.slice(0, 60)]);
  }
  await audit(pool, actor, "credential.create", id, `Created "${label}"${secret ? " with secret" : " (no secret)"}${otp ? " + OTP" : ""}`);
  return id;
}

/**
 * Reveal a secret. This is the only path that returns plaintext, and it is audited
 * BEFORE the value leaves the process.
 */
export async function revealCredential(pool: pg.Pool, id: number, actor: VaultActor, what: "secret" | "otp" = "secret") {
  const { rows } = await pool.query(
    `SELECT id, label, username, secret_cipher, otp_secret_cipher FROM credentials WHERE id = $1`,
    [id],
  );
  if (!rows.length) throw new Error("credential not found");
  const row = rows[0];
  const cipher = what === "otp" ? row.otp_secret_cipher : row.secret_cipher;
  if (!cipher) throw new Error(what === "otp" ? "no OTP secret stored for this credential" : "no secret stored for this credential");
  await audit(pool, actor, "credential.reveal", id, `Revealed ${what === "otp" ? "OTP secret" : "password/secret"} for "${row.label}"`);
  return { id, label: row.label, username: row.username, kind: what, value: decryptSecret(cipher) };
}

/** Rotate the stored secret (and optionally the OTP secret). Audited. */
export async function rotateCredential(pool: pg.Pool, id: number, args: {
  secret?: string | null; otpSecret?: string | null; notes?: string | null; rotationDays?: number | null;
}, actor: VaultActor) {
  const secret = (args.secret || "").trim();
  const otp = (args.otpSecret || "").trim();
  if (!secret && !otp) throw new Error("a new secret or OTP secret is required");
  const sets: string[] = ["last_rotated_at = NOW()", "updated_at = NOW()"];
  const params: any[] = [];
  if (secret) { params.push(encryptSecret(secret)); sets.push(`secret_cipher = $${params.length}`); }
  if (otp) { params.push(encryptSecret(otp)); sets.push(`otp_secret_cipher = $${params.length}`); }
  if (args.notes !== undefined && args.notes !== null) { params.push(String(args.notes)); sets.push(`notes = $${params.length}`); }
  if (args.rotationDays !== undefined && args.rotationDays !== null) {
    params.push(args.rotationDays > 0 ? Math.floor(args.rotationDays) : null);
    sets.push(`rotation_days = $${params.length}`);
  }
  params.push(id);
  const res = await pool.query(`UPDATE credentials SET ${sets.join(", ")} WHERE id = $${params.length} RETURNING label`, params);
  if (!res.rows.length) throw new Error("credential not found");
  await audit(pool, actor, "credential.rotate", id, `Rotated ${secret ? "secret" : ""}${secret && otp ? " + " : ""}${otp ? "OTP secret" : ""} for "${res.rows[0].label}"`);
  return res.rows[0].label;
}

/** Metadata-only edit (never touches secrets). Audited. */
export async function updateCredentialMeta(pool: pg.Pool, id: number, args: {
  label?: string; username?: string | null; url?: string | null; notes?: string | null;
  category?: string | null; clientId?: number | null; rotationDays?: number | null; tags?: string[];
}, actor: VaultActor) {
  const sets: string[] = ["updated_at = NOW()"];
  const params: any[] = [];
  const push = (col: string, val: any) => { params.push(val); sets.push(`${col} = $${params.length}`); };
  if (args.label !== undefined && args.label !== null && String(args.label).trim()) push("label", String(args.label).trim());
  if (args.username !== undefined) push("username", (args.username || "").trim() || null);
  if (args.url !== undefined) push("url", (args.url || "").trim() || null);
  if (args.notes !== undefined) push("notes", (args.notes || "").trim() || null);
  if (args.category !== undefined && args.category) push("category", String(args.category).trim());
  if (args.clientId !== undefined) push("client_id", args.clientId ?? null);
  if (args.rotationDays !== undefined) push("rotation_days", args.rotationDays && args.rotationDays > 0 ? Math.floor(args.rotationDays) : null);
  params.push(id);
  const res = await pool.query(`UPDATE credentials SET ${sets.join(", ")} WHERE id = $${params.length} RETURNING label`, params);
  if (!res.rows.length) throw new Error("credential not found");
  if (Array.isArray(args.tags)) {
    await pool.query(`DELETE FROM credential_tags WHERE credential_id = $1`, [id]);
    for (const tag of args.tags.map((t) => String(t).trim()).filter(Boolean)) {
      await pool.query(`INSERT INTO credential_tags (credential_id, tag) VALUES ($1, $2)`, [id, tag.slice(0, 60)]);
    }
  }
  await audit(pool, actor, "credential.update", id, `Updated metadata for "${res.rows[0].label}"`);
  return res.rows[0].label;
}

/** Credentials for one client, metadata only — used by the client profile panel. */
export async function listClientCredentials(pool: pg.Pool, clientId: number) {
  return listCredentials(pool, { clientId });
}
