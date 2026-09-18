import pg from "pg";

/**
 * Xero OAuth 2.0 (authorization-code) client — the **Web app** at
 * developer.xero.com, alongside the existing Custom Connection.
 *
 * Why this exists: a Custom Connection uses the client-credentials grant, is
 * one-to-one, and answers `[]` on GET /connections — so its Tenant ID can only
 * be read by hand off the app page. A Web app gets a real consent screen, a
 * refresh token, up to 5 organisations, AND `GET /connections` returns
 * `tenantId` — which is the value the Custom Connection is missing.
 *
 * Config lives in `provider_settings` under provider = 'xero_oauth' so the
 * working custom-connection rows (provider = 'xero') are never overwritten.
 *
 * Endpoints (Xero, unchanged since OAuth 2.0 GA):
 *   authorize  https://login.xero.com/identity/connect/authorize
 *   token      https://identity.xero.com/connect/token
 *   revoke     https://identity.xero.com/connect/revocation
 *   connections https://api.xero.com/connections
 */

export const OAUTH_PROVIDER = "xero_oauth";

export const XERO_AUTHORIZE_URL = "https://login.xero.com/identity/connect/authorize";
export const XERO_TOKEN_URL = "https://identity.xero.com/connect/token";
export const XERO_REVOKE_URL = "https://identity.xero.com/connect/revocation";
export const XERO_CONNECTIONS_URL = "https://api.xero.com/connections";

/** Must be a subset of the scopes ticked on the app's Configuration page. */
export const XERO_SCOPES = [
  "offline_access", // required for a refresh token
  "openid",
  "profile",
  "email",
  "accounting.settings",
  "accounting.settings.read",
  "accounting.contacts",
  "accounting.contacts.read",
  "accounting.invoices",
  "accounting.invoices.read",
  "accounting.payments",
  "accounting.payments.read",
  "accounting.banktransactions.read",
  "accounting.reports.aged.read",
  "accounting.reports.balancesheet.read",
  "accounting.attachments.read",
].join(" ");

const SKEW_MS = 60_000;

export interface XeroOAuthConfig {
  clientId: string;
  clientSecret: string;
  redirectUri: string;
  accessToken: string;
  refreshToken: string;
  expiresAt: number;
  tenantId: string;
  tenantName: string;
}

export async function getOAuthConfig(pool: pg.Pool): Promise<XeroOAuthConfig> {
  const kv: Record<string, string> = {};
  try {
    const { rows } = await pool.query(
      `SELECT key_name, key_value FROM provider_settings WHERE provider = $1`,
      [OAUTH_PROVIDER]
    );
    for (const r of rows) kv[String(r.key_name)] = r.key_value == null ? "" : String(r.key_value);
  } catch {
    /* table shape differs — treat as unconfigured */
  }
  return {
    clientId: kv["client_id"] || process.env.XERO_OAUTH_CLIENT_ID || "",
    clientSecret: kv["client_secret"] || process.env.XERO_OAUTH_CLIENT_SECRET || "",
    redirectUri: kv["redirect_uri"] || process.env.XERO_OAUTH_REDIRECT_URI || "",
    accessToken: kv["access_token"] || "",
    refreshToken: kv["refresh_token"] || "",
    expiresAt: Number(kv["expires_at"] || 0),
    tenantId: kv["tenant_id"] || "",
    tenantName: kv["tenant_name"] || "",
  };
}

export async function saveOAuthConfig(
  pool: pg.Pool,
  patch: Record<string, string | number>
): Promise<void> {
  for (const [key, value] of Object.entries(patch)) {
    await pool.query(
      `INSERT INTO provider_settings (provider, key_name, key_value, updated_at)
       VALUES ($1, $2, $3, NOW())
       ON CONFLICT (provider, key_name) DO UPDATE SET key_value = EXCLUDED.key_value, updated_at = NOW()`,
      [OAUTH_PROVIDER, key, String(value)]
    );
  }
}

export function buildAuthorizeUrl(cfg: XeroOAuthConfig, state: string): string {
  const qs = new URLSearchParams({
    response_type: "code",
    client_id: cfg.clientId,
    redirect_uri: cfg.redirectUri,
    scope: XERO_SCOPES,
    state,
  });
  return `${XERO_AUTHORIZE_URL}?${qs.toString()}`;
}

/** Token endpoint, Basic-auth'd with the app's client id/secret. */
async function tokenRequest(cfg: XeroOAuthConfig, body: Record<string, string>): Promise<any> {
  const resp = await fetch(XERO_TOKEN_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
      Authorization:
        "Basic " + Buffer.from(`${cfg.clientId}:${cfg.clientSecret}`).toString("base64"),
    },
    body: new URLSearchParams(body).toString(),
    signal: AbortSignal.timeout(30000),
  });
  const text = await resp.text();
  if (!resp.ok) throw new Error(`Xero token request failed (HTTP ${resp.status}): ${text.slice(0, 220)}`);
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(`Xero token response was not JSON: ${text.slice(0, 160)}`);
  }
}

/** Exchange the one-time ?code= for tokens. Refresh tokens rotate — save the new one. */
export async function exchangeCode(pool: pg.Pool, code: string): Promise<XeroOAuthConfig> {
  const cfg = await getOAuthConfig(pool);
  if (!cfg.clientId || !cfg.clientSecret) throw new Error("Xero OAuth client_id / client_secret are not saved");
  const json = await tokenRequest(cfg, {
    grant_type: "authorization_code",
    code,
    redirect_uri: cfg.redirectUri,
  });
  if (!json.access_token) throw new Error("Xero token response contained no access_token");
  await saveOAuthConfig(pool, {
    access_token: json.access_token,
    refresh_token: json.refresh_token || cfg.refreshToken,
    expires_at: Date.now() + Number(json.expires_in || 1800) * 1000,
  });
  return getOAuthConfig(pool);
}

/** Cached bearer, refreshed on demand. Xero access tokens live 30 minutes. */
export async function oauthAccessToken(pool: pg.Pool, force = false): Promise<string> {
  const cfg = await getOAuthConfig(pool);
  if (!force && cfg.accessToken && cfg.expiresAt && Date.now() < cfg.expiresAt - SKEW_MS) {
    return cfg.accessToken;
  }
  if (!cfg.refreshToken) {
    throw new Error("No Xero OAuth refresh token — press “Connect with Xero” to authorise the app");
  }
  const json = await tokenRequest(cfg, {
    grant_type: "refresh_token",
    refresh_token: cfg.refreshToken,
  });
  if (!json.access_token) throw new Error("Xero refresh returned no access_token");
  await saveOAuthConfig(pool, {
    access_token: json.access_token,
    refresh_token: json.refresh_token || cfg.refreshToken, // rotation
    expires_at: Date.now() + Number(json.expires_in || 1800) * 1000,
  });
  return json.access_token;
}

export interface XeroConnection {
  id?: string;
  tenantId: string;
  tenantType?: string;
  tenantName?: string;
}

/**
 * GET /connections — the endpoint the Custom Connection cannot use. Returns one
 * entry per authorised organisation, each carrying its `tenantId`.
 */
export async function listConnections(pool: pg.Pool): Promise<XeroConnection[]> {
  const token = await oauthAccessToken(pool);
  const resp = await fetch(XERO_CONNECTIONS_URL, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    signal: AbortSignal.timeout(30000),
  });
  const text = await resp.text();
  if (resp.status === 401) return listConnectionsWith(pool, await oauthAccessToken(pool, true));
  if (!resp.ok) throw new Error(`Xero /connections failed (HTTP ${resp.status}): ${text.slice(0, 220)}`);
  try {
    return JSON.parse(text) as XeroConnection[];
  } catch {
    throw new Error(`Xero /connections returned non-JSON: ${text.slice(0, 160)}`);
  }
}

async function listConnectionsWith(pool: pg.Pool, token: string): Promise<XeroConnection[]> {
  const resp = await fetch(XERO_CONNECTIONS_URL, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    signal: AbortSignal.timeout(30000),
  });
  const text = await resp.text();
  if (!resp.ok) throw new Error(`Xero /connections failed (HTTP ${resp.status}): ${text.slice(0, 220)}`);
  return JSON.parse(text) as XeroConnection[];
}

/** Record the chosen organisation on the OAuth row (and echo it for the UI). */
export async function saveConnection(pool: pg.Pool, tenantId: string, tenantName: string): Promise<void> {
  await saveOAuthConfig(pool, { tenant_id: tenantId, tenant_name: tenantName });
}

/**
 * Convenience bridge: if the Custom Connection has no tenant_id yet, copy the
 * one discovered over OAuth into it, so the existing client-credentials sync
 * starts working without a manual paste.
 */
export async function backfillCustomConnectionTenant(
  pool: pg.Pool,
  tenantId: string
): Promise<boolean> {
  const { rows } = await pool.query(
    `SELECT key_value FROM provider_settings WHERE provider = 'xero' AND key_name = 'tenant_id'`
  );
  const existing = rows[0]?.key_value ? String(rows[0].key_value) : "";
  if (existing) return false;
  await pool.query(
    `INSERT INTO provider_settings (provider, key_name, key_value, updated_at)
     VALUES ('xero', 'tenant_id', $1, NOW())
     ON CONFLICT (provider, key_name) DO UPDATE SET key_value = EXCLUDED.key_value, updated_at = NOW()`,
    [tenantId]
  );
  return true;
}

export async function oauthStatus(pool: pg.Pool): Promise<any> {
  const cfg = await getOAuthConfig(pool);
  let connections: XeroConnection[] = [];
  let error: string | null = null;
  if (cfg.refreshToken) {
    try {
      connections = await listConnections(pool);
    } catch (e: any) {
      error = e.message;
    }
  }
  return {
    configured: !!(cfg.clientId && cfg.clientSecret),
    client_id: cfg.clientId || null,
    redirect_uri: cfg.redirectUri || null,
    authorised: !!cfg.refreshToken,
    token_cached: !!(cfg.accessToken && cfg.expiresAt > Date.now()),
    token_expires_at: cfg.expiresAt || null,
    tenant_id: cfg.tenantId || null,
    tenant_name: cfg.tenantName || null,
    connections,
    error,
        scopes: XERO_SCOPES.split(" "),
  };
}
