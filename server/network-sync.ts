import pg from "pg";

// ── Types ─────────────────────────────────────────────────────────────────

export interface SyncResult {
  source: string;
  synced: number;
  matched: number;
  errors: string[];
}

interface AssetRow {
  client_id: number | null;
  source: string;
  asset_type: string;
  external_id: string;
  name: string;
  ip: string;
  status: string;
  last_seen: string | null;
  raw: Record<string, unknown>;
}

interface ClientRecord {
  id: number;
  name: string;
  email: string;
  company: string | null;
  domain?: string;
}

// ── Helpers ────────────────────────────────────────────────────────────────

/** Extract email domain from a client email, lowercased. */
function emailDomain(email: string): string | null {
  const m = email.toLowerCase().match(/@(.+)$/);
  return m ? m[1] : null;
}

/** Get all active clients from the DB. */
async function getClients(pool: pg.Pool): Promise<ClientRecord[]> {
  const { rows } = await pool.query(
    `SELECT id, name, email, COALESCE(company, '') AS company FROM clients ORDER BY id`
  );
  return rows.map((r: any) => ({
    ...r,
    domain: emailDomain(r.email),
  }));
}

/** Load system_settings key-value pairs. */
async function getSettings(pool: pg.Pool): Promise<Record<string, string>> {
  const { rows } = await pool.query(
    `SELECT setting_key, setting_value FROM system_settings`
  );
  const map: Record<string, string> = {};
  for (const r of rows) map[r.setting_key] = r.setting_value;
  return map;
}

/**
 * Match a candidate (name, domain) against clients.
 * Priority: exact email domain match > company name match > client name substring.
 */
function matchClient(
  candidateName: string,
  candidateDomain: string | null,
  clients: ClientRecord[]
): { clientId: number; method: string } | null {
  if (!candidateName && !candidateDomain) return null;

  // 1. Exact email domain match
  if (candidateDomain) {
    for (const c of clients) {
      if (c.domain && c.domain.toLowerCase() === candidateDomain.toLowerCase()) {
        return { clientId: c.id, method: "email_domain" };
      }
    }
  }

  const lowerName = candidateName.toLowerCase().trim();

  // 2. Company name exact match
  for (const c of clients) {
    if (c.company && c.company.toLowerCase().trim() === lowerName) {
      return { clientId: c.id, method: "company_name" };
    }
  }

  // 3. Client name (the `name` field) — check if candidate contains client name or vice versa
  for (const c of clients) {
    const lowerClient = c.name.toLowerCase().trim();
    if (lowerName.includes(lowerClient) || lowerClient.includes(lowerName)) {
      return { clientId: c.id, method: "name_substring" };
    }
  }

  return null;
}

/**
 * Upsert an asset into network_assets and return the row.
 * Uses UNIQUE(source, external_id) for idempotency.
 */
async function upsertAsset(
  pool: pg.Pool,
  asset: AssetRow
): Promise<void> {
  await pool.query(
    `INSERT INTO network_assets (client_id, source, asset_type, external_id, name, ip, status, last_seen, raw, updated_at)
     VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, NOW())
     ON CONFLICT (source, external_id) DO UPDATE SET
       client_id = COALESCE(EXCLUDED.client_id, network_assets.client_id),
       name = EXCLUDED.name,
       ip = EXCLUDED.ip,
       status = EXCLUDED.status,
       last_seen = EXCLUDED.last_seen,
       raw = EXCLUDED.raw,
       updated_at = NOW()`,
    [
      asset.client_id,
      asset.source,
      asset.asset_type,
      asset.external_id,
      asset.name,
      asset.ip,
      asset.status,
      asset.last_seen,
      JSON.stringify(asset.raw),
    ]
  );
}

/**
 * Upsert a client-source mapping.
 */
async function upsertMapping(
  pool: pg.Pool,
  clientId: number,
  source: string,
  externalId: string,
  externalName: string,
  matchedBy: string
): Promise<void> {
  await pool.query(
    `INSERT INTO client_source_mappings (client_id, source, external_id, external_name, matched_by)
     VALUES ($1, $2, $3, $4, $5)
     ON CONFLICT (source, external_id) DO UPDATE SET
       client_id = EXCLUDED.client_id,
       external_name = EXCLUDED.external_name,
       matched_by = EXCLUDED.matched_by`,
    [clientId, source, externalId, externalName, matchedBy]
  );
}

// ── Source 1: Action1 ──────────────────────────────────────────────────────

async function syncAction1(
  pool: pg.Pool,
  clients: ClientRecord[],
  settings: Record<string, string>
): Promise<SyncResult> {
  const result: SyncResult = { source: "action1", synced: 0, matched: 0, errors: [] };

  const clientId = settings["action1_client_id"] || process.env.ACTION1_CLIENT_ID || "";
  const apiKey = settings["action1_api_key"] || process.env.ACTION1_API_KEY || "";
  const apiUrl = settings["action1_api_url"] || process.env.ACTION1_API_URL || "https://app.action1.com/api/3.0";

  if (!clientId || !apiKey) {
    result.errors.push("Action1 not configured (missing client_id or api_key)");
    return result;
  }

  try {
    // Get OAuth2 token
    const tokenResp = await fetch(`${apiUrl}/oauth2/token`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
      body: new URLSearchParams({
        grant_type: "client_credentials",
        client_id: clientId,
        client_secret: apiKey,
      }),
      signal: AbortSignal.timeout(15000),
    });
    if (!tokenResp.ok) {
      result.errors.push(`Action1 auth failed: HTTP ${tokenResp.status}`);
      return result;
    }
    const auth: any = await tokenResp.json();
    const token = auth.access_token;
    if (!token) {
      result.errors.push("Action1 auth returned no access_token");
      return result;
    }

    // Fetch endpoints
    const epResp = await fetch(`${apiUrl}/endpoints`, {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      signal: AbortSignal.timeout(30000),
    });
    if (!epResp.ok) {
      result.errors.push(`Action1 endpoints fetch failed: HTTP ${epResp.status}`);
      return result;
    }
    const epData: any = await epResp.json();
    const endpoints = epData.items || epData.data || epData.endpoints || [];

    for (const ep of endpoints) {
      const externalId = String(ep.id || ep.endpoint_id || "");
      if (!externalId) continue;

      const epName = ep.name || ep.hostname || ep.display_name || "";
      const ip = ep.ip_address || ep.ip || "";
      const status = ep.status || ep.connection_status || "unknown";
      const lastSeen = ep.last_seen || ep.last_contact || null;

      // Match to client by endpoint name/group
      const match = matchClient(epName, null, clients);
      const clientId = match?.clientId ?? null;
      if (clientId) result.matched++;

      await upsertAsset(pool, {
        client_id: clientId,
        source: "action1",
        asset_type: "endpoint",
        external_id: externalId,
        name: epName,
        ip,
        status,
        last_seen: lastSeen,
        raw: ep,
      });

      if (clientId) {
        await upsertMapping(pool, clientId, "action1", externalId, epName, match?.method ?? "auto");
      }
      result.synced++;
    }
  } catch (e: any) {
    result.errors.push(`Action1 sync error: ${e.message}`);
  }

  return result;
}

// ── Source 2: JumpCloud ────────────────────────────────────────────────────

async function syncJumpCloud(
  pool: pg.Pool,
  clients: ClientRecord[],
  settings: Record<string, string>
): Promise<SyncResult> {
  const result: SyncResult = { source: "jumpcloud", synced: 0, matched: 0, errors: [] };

  const apiKey = settings["jumpcloud_api_key"] || process.env.JUMPCLOUD_API_KEY || "";
  const apiV2 = "https://console.jumpcloud.com/api/v2";

  if (!apiKey) {
    result.errors.push("JumpCloud not configured (missing api_key)");
    return result;
  }

  const headers = { "x-api-key": apiKey, "Content-Type": "application/json", Accept: "application/json" };
  const fetchJc = async (path: string) => {
    const resp = await fetch(`${apiV2}${path}`, { headers, signal: AbortSignal.timeout(30000) });
    if (!resp.ok) throw new Error(`JumpCloud HTTP ${resp.status}`);
    return resp.json();
  };

  try {
    // Fetch systems
    const systems: any[] = await fetchJc("/systems?limit=200");
    const sysList = systems || [];

    for (const sys of sysList) {
      const externalId = String(sys.id || "");
      if (!externalId) continue;

      const sysName = sys.displayName || sys.hostname || sys.os || "";
      const ip = sys.remoteIP || sys.networkInterfaces?.[0]?.address || "";
      const status = sys.active ? "active" : "inactive";
      const lastSeen = sys.lastContact || sys.created || null;

      const match = matchClient(sysName, null, clients);
      const clientId = match?.clientId ?? null;
      if (clientId) result.matched++;

      await upsertAsset(pool, {
        client_id: clientId,
        source: "jumpcloud",
        asset_type: "system",
        external_id: externalId,
        name: sysName,
        ip,
        status,
        last_seen: lastSeen,
        raw: sys,
      });

      if (clientId) {
        await upsertMapping(pool, clientId, "jumpcloud", externalId, sysName, match?.method ?? "auto");
      }
      result.synced++;
    }

    // Fetch users (match by email domain)
    const users: any[] = await fetchJc("/users?limit=200");
    const userList = users || [];

    for (const u of userList) {
      const externalId = String(u.id || "");
      if (!externalId) continue;

      const userName = `${u.firstname || ""} ${u.lastname || ""}`.trim() || u.username || "";
      const email = u.email || "";
      const emailDom = emailDomain(email);
      const status = u.state || "STAGED";
      const lastSeen = u.created || null;

      const match = emailDom ? matchClient(userName, emailDom, clients) : null;
      const clientId = match?.clientId ?? null;
      if (clientId) result.matched++;

      await upsertAsset(pool, {
        client_id: clientId,
        source: "jumpcloud",
        asset_type: "user",
        external_id: externalId,
        name: userName,
        ip: "",
        status,
        last_seen: lastSeen,
        raw: u,
      });

      if (clientId) {
        await upsertMapping(pool, clientId, "jumpcloud", externalId, userName, match?.method ?? "auto");
      }
      result.synced++;
    }
  } catch (e: any) {
    result.errors.push(`JumpCloud sync error: ${e.message}`);
  }

  return result;
}

// ── Source 3: VoIP.ms ──────────────────────────────────────────────────────

async function syncVoipMs(
  pool: pg.Pool,
  clients: ClientRecord[],
  settings: Record<string, string>
): Promise<SyncResult> {
  const result: SyncResult = { source: "voipms", synced: 0, matched: 0, errors: [] };

  const username = settings["voip_api_username"] || process.env.VOIP_USERNAME || "";
  const password = settings["voip_api_password"] || process.env.VOIP_PASSWORD || "";
  const token = settings["voip_api_token"] || process.env.VOIP_API_TOKEN || "";
  const apiUrl = "https://voip.ms/api/v1/rest.php";

  if (!username || (!password && !token)) {
    result.errors.push("VoIP.ms not configured (missing username or password/token)");
    return result;
  }

  const effectivePassword = password || token;

  try {
    // Fetch DIDs
    const params = new URLSearchParams({
      api_username: username,
      api_password: effectivePassword,
      method: "getDIDsInfo",
    });
    const resp = await fetch(`${apiUrl}?${params.toString()}`, {
      signal: AbortSignal.timeout(30000),
    });
    if (!resp.ok) {
      result.errors.push(`VoIP.ms API error: HTTP ${resp.status}`);
      return result;
    }
    const data: any = await resp.json();

    if (data.status === "ip_not_enabled") {
      result.errors.push("VoIP.ms: IP not enabled for API access. Whitelist this server IP in your VoIP.ms account.");
      return result;
    }
    if (data.status !== "success") {
      result.errors.push(`VoIP.ms API error: ${data.message || data.status}`);
      return result;
    }

    const dids = data.dids || data.entries || [];

    for (const did of dids) {
      const externalId = String(did.did || did.id || "");
      if (!externalId) continue;

      const didName = did.description || did.did || "";
      const ip = ""; // VoIP.ms DIDs don't have IPs
      const status = did.status || "active";
      const lastSeen = null;

      // Match by DID description (often contains client name)
      const match = matchClient(didName, null, clients);
      const clientId = match?.clientId ?? null;
      if (clientId) result.matched++;

      await upsertAsset(pool, {
        client_id: clientId,
        source: "voipms",
        asset_type: "did",
        external_id: externalId,
        name: didName,
        ip,
        status,
        last_seen: lastSeen,
        raw: did,
      });

      if (clientId) {
        await upsertMapping(pool, clientId, "voipms", externalId, didName, match?.method ?? "auto");
      }
      result.synced++;
    }
  } catch (e: any) {
    result.errors.push(`VoIP.ms sync error: ${e.message}`);
  }

  return result;
}

// ── Source 4: Hetzner Cloud ────────────────────────────────────────────────

async function syncHetzner(
  pool: pg.Pool,
  clients: ClientRecord[],
  settings: Record<string, string>
): Promise<SyncResult> {
  const result: SyncResult = { source: "hetzner", synced: 0, matched: 0, errors: [] };

  const token = settings["hetzner_api_token"] || process.env.HETZNER_API_TOKEN || "";
  const apiUrl = "https://api.hetzner.cloud/v1";

  if (!token) {
    result.errors.push("Hetzner not configured (missing api_token)");
    return result;
  }

  try {
    const resp = await fetch(`${apiUrl}/servers`, {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      signal: AbortSignal.timeout(30000),
    });
    if (!resp.ok) {
      result.errors.push(`Hetzner API error: HTTP ${resp.status}`);
      return result;
    }
    const data: any = await resp.json();
    const servers = data.servers || [];

    for (const srv of servers) {
      const externalId = String(srv.id || "");
      if (!externalId) continue;

      const srvName = srv.name || "";
      const ip = srv.public_net?.ipv4?.ip || srv.private_net?.[0]?.ip || "";
      const status = srv.status || "unknown";
      const lastSeen = null;

      // Match by Hetzner labels (client name) or server name
      const labelName = srv.labels?.client || srv.labels?.customer || "";
      const matchName = labelName || srvName;
      const match = matchClient(matchName, null, clients);
      const clientId = match?.clientId ?? null;
      if (clientId) result.matched++;

      await upsertAsset(pool, {
        client_id: clientId,
        source: "hetzner",
        asset_type: "server",
        external_id: externalId,
        name: srvName,
        ip,
        status,
        last_seen: lastSeen,
        raw: srv,
      });

      if (clientId) {
        await upsertMapping(pool, clientId, "hetzner", externalId, srvName, match?.method ?? "auto");
      }
      result.synced++;
    }
  } catch (e: any) {
    result.errors.push(`Hetzner sync error: ${e.message}`);
  }

  return result;
}

// ── Source 5: Hostwinds Cloud ──────────────────────────────────────────────

async function syncHostwinds(
  pool: pg.Pool,
  clients: ClientRecord[],
  settings: Record<string, string>
): Promise<SyncResult> {
  const result: SyncResult = { source: "hostwinds", synced: 0, matched: 0, errors: [] };

  const apiEmail = settings["hostwinds_api_email"] || process.env.HOSTWINDS_API_EMAIL || "";
  const apiKey = settings["hostwinds_api_key"] || process.env.HOSTWINDS_API_KEY || "";
  const apiUrl = "https://clients.hostwinds.com/HostwindsResellerAPI/api.php";

  if (!apiEmail || !apiKey) {
    result.errors.push("Hostwinds not configured (missing api_email or api_key)");
    return result;
  }

  const callHwApi = async (action: string, extra: Record<string, string> = {}): Promise<any> => {
    const postFields = new URLSearchParams({
      action,
      email: apiEmail,
      apikey: apiKey,
      ...extra,
    });
    const resp = await fetch(apiUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
      body: postFields.toString(),
      signal: AbortSignal.timeout(30000),
    });
    if (!resp.ok) throw new Error(`Hostwinds HTTP ${resp.status}`);
    return resp.json();
  };

  try {
    // Fetch services list (VMs, hosting)
    const svcData = await callHwApi("getservicelist");
    const services = svcData.services || svcData.data || [];

    for (const svc of services) {
      const externalId = String(svc.id || svc.service_id || "");
      if (!externalId) continue;

      const svcName = svc.name || svc.domain || svc.hostname || svc.service || "";
      const ip = svc.ip || svc.ip_address || svc.dedicated_ip || "";
      const status = svc.status || svc.domainstatus || "active";
      const lastSeen = null;

      // Match by client name / service name
      const match = matchClient(svcName, null, clients);
      const clientId = match?.clientId ?? null;
      if (clientId) result.matched++;

      await upsertAsset(pool, {
        client_id: clientId,
        source: "hostwinds",
        asset_type: "vm",
        external_id: externalId,
        name: svcName,
        ip,
        status,
        last_seen: lastSeen,
        raw: svc,
      });

      if (clientId) {
        await upsertMapping(pool, clientId, "hostwinds", externalId, svcName, match?.method ?? "auto");
      }
      result.synced++;
    }
  } catch (e: any) {
    result.errors.push(`Hostwinds sync error: ${e.message}`);
  }

  return result;
}

// ── Public API ─────────────────────────────────────────────────────────────

const SOURCE_HANDLERS: Record<string, (pool: pg.Pool, clients: ClientRecord[], settings: Record<string, string>) => Promise<SyncResult>> = {
  action1: syncAction1,
  jumpcloud: syncJumpCloud,
  voipms: syncVoipMs,
  hetzner: syncHetzner,
  hostwinds: syncHostwinds,
};

export const VALID_SOURCES = Object.keys(SOURCE_HANDLERS);

/**
 * Sync a single source: fetch remote, match to clients, upsert assets.
 */
export async function syncSource(
  pool: pg.Pool,
  source: string
): Promise<SyncResult> {
  const handler = SOURCE_HANDLERS[source];
  if (!handler) {
    return { source, synced: 0, matched: 0, errors: [`Unknown source: ${source}`] };
  }

  const [clients, settings] = await Promise.all([
    getClients(pool),
    getSettings(pool),
  ]);

  return handler(pool, clients, settings);
}

/**
 * Sync all configured sources. Skips sources with no credentials.
 */
export async function syncAllSources(
  pool: pg.Pool
): Promise<SyncResult[]> {
  const [clients, settings] = await Promise.all([
    getClients(pool),
    getSettings(pool),
  ]);

  const results: SyncResult[] = [];
  for (const [source, handler] of Object.entries(SOURCE_HANDLERS)) {
    try {
      const result = await handler(pool, clients, settings);
      results.push(result);
    } catch (e: any) {
      results.push({ source, synced: 0, matched: 0, errors: [e.message] });
    }
  }
  return results;
}

/**
 * Get assets for a client, grouped by source.
 */
export async function getClientAssets(
  pool: pg.Pool,
  clientId: number
): Promise<any[]> {
  const { rows } = await pool.query(
    `SELECT * FROM network_assets WHERE client_id = $1 ORDER BY source, asset_type, name`,
    [clientId]
  );
  return rows;
}