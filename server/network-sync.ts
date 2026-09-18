import pg from "pg";
import dns from "dns";

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

/** Action1 timestamps look like "2026-09-18_15-02-26" (UTC) — convert to ISO. */
function action1Timestamp(v: any): string | null {
  if (!v) return null;
  const m = String(v).match(/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-(\d{2})$/);
  if (!m) return null;
  return `${m[1]}T${m[2]}:${m[3]}:${m[4]}Z`;
}

/**
 * Load system_settings key-value pairs, keyed by LOWERCASED setting_key.
 *
 * Keys in `system_settings` are stored inconsistently (HOSTWINDS_API_KEY vs
 * action1_api_key vs voip_ms_username). Lookups used to be exact-case, so any
 * key whose stored casing differed from the code read as "" and the source
 * silently reported "not configured" / 401 / 404. Normalising to lowercase here
 * makes every lookup case-insensitive — always read via `pick()`.
 */
async function getSettings(pool: pg.Pool): Promise<Record<string, string>> {
  const { rows } = await pool.query(
    `SELECT setting_key, setting_value FROM system_settings`
  );
  const map: Record<string, string> = {};
  for (const r of rows) map[String(r.setting_key).toLowerCase()] = r.setting_value;
  return map;
}

/**
 * Resolve a credential: first non-empty `system_settings` key (case-insensitive),
 * then the matching env var. Returns "" when nothing is configured.
 */
function pick(
  settings: Record<string, string>,
  settingKeys: string[],
  envKey?: string
): string {
  for (const k of settingKeys) {
    const v = settings[k.toLowerCase()];
    if (v) return String(v);
  }
  if (envKey && process.env[envKey]) return String(process.env[envKey] as string);
  return "";
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

  // 3. Client name or company — check if candidate contains one or vice versa.
  //    (Action1 orgs are company-shaped: "S2S Couture" must match the client
  //    whose company is "S2S Couture Hair".)
  if (lowerName) {
    for (const c of clients) {
      for (const [field, raw] of [["name", c.name], ["company", c.company]] as const) {
        const hay = (raw || "").toLowerCase().trim();
        if (!hay) continue;
        if (lowerName.includes(hay) || hay.includes(lowerName)) {
          return { clientId: c.id, method: `${field}_substring` };
        }
      }
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
      JSON.stringify(asset.raw ?? {}),
    ]
  );

  await mirrorToNetworkDevices(pool, asset);
}

/** Map a source-specific status onto the values Network Docs counts. */
function normalizeDeviceStatus(status: string): string {
  const s = (status || "").toLowerCase();
  if (["online", "active", "running", "connected", "enabled", "up"].includes(s)) return "online";
  if (["offline", "inactive", "stopped", "disabled", "down", "terminated", "disconnected"].includes(s)) return "offline";
  if (["warning", "degraded", "alert"].includes(s)) return "warning";
  return s || "unknown";
}

/**
 * Mirror a synced asset into `network_devices` — the table Network Docs
 * (`admin-network.php`) actually reads.
 *
 * ROOT CAUSE of "Network Docs is empty even though the sync runs": the six
 * sync sources only ever wrote `network_assets`, while the admin UI reads
 * `network_devices`. Both tables existed, so nothing errored — the data simply
 * landed somewhere the UI never looks. Every upsert now writes both.
 *
 * Keyed on (source, external_id) so repeat syncs update in place; rows created
 * by hand in the admin UI have a NULL source and never collide.
 */
async function mirrorToNetworkDevices(pool: pg.Pool, asset: AssetRow): Promise<void> {
  // network_devices.last_seen is a plain timestamp — drop unparseable values
  // rather than letting one bad remote field abort the whole sync.
  let lastSeen: string | null = null;
  if (asset.last_seen) {
    const d = new Date(asset.last_seen);
    if (!isNaN(d.getTime())) lastSeen = d.toISOString();
  }

  // Sources that report hardware detail (Action1 gives OS/MAC/manufacturer/serial)
  // enrich the Network Docs columns; COALESCE keeps a later source without those
  // fields from wiping what an earlier one wrote.
  const raw: any = asset.raw ?? {};
  const osName = typeof raw.OS === "string" ? raw.OS : (typeof raw.os_name === "string" ? raw.os_name : null);
  const macAddress = typeof raw.MAC === "string" && raw.MAC ? raw.MAC : null;
  const manufacturer = typeof raw.manufacturer === "string" && raw.manufacturer ? raw.manufacturer : null;
  const serialNumber = typeof raw.serial === "string" && raw.serial ? raw.serial : null;

  try {
    await pool.query(
      `INSERT INTO network_devices
         (client_id, hostname, device_type, ip_address, status, notes, last_seen, source, external_id,
          os_name, mac_address, manufacturer, serial_number)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13)
       ON CONFLICT (source, external_id) DO UPDATE SET
         client_id = COALESCE(EXCLUDED.client_id, network_devices.client_id),
         hostname = EXCLUDED.hostname,
         device_type = EXCLUDED.device_type,
         ip_address = EXCLUDED.ip_address,
         status = EXCLUDED.status,
         notes = EXCLUDED.notes,
         last_seen = COALESCE(EXCLUDED.last_seen, network_devices.last_seen),
         os_name = COALESCE(EXCLUDED.os_name, network_devices.os_name),
         mac_address = COALESCE(EXCLUDED.mac_address, network_devices.mac_address),
         manufacturer = COALESCE(EXCLUDED.manufacturer, network_devices.manufacturer),
         serial_number = COALESCE(EXCLUDED.serial_number, network_devices.serial_number)`,
      [
        asset.client_id,
        asset.name || asset.external_id,
        asset.asset_type,
        asset.ip || null,
        normalizeDeviceStatus(asset.status),
        `Synced from ${asset.source} (external id ${asset.external_id})`,
        lastSeen,
        asset.source,
        asset.external_id,
        osName,
        macAddress,
        manufacturer,
        serialNumber,
      ]
    );
  } catch (e: any) {
    // A mirror failure must not lose the network_assets row (already written).
    console.error(`[network-sync] mirror to network_devices failed for ${asset.source}:${asset.external_id}: ${e.message}`);
  }
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

  // Action1 client-credentials OAuth: the API KEY is NOT the client secret.
  // `action1_api_key` holds a separate (non-OAuth) key and passing it as the
  // secret returns HTTP 401 "Incorrect authentication data"; the OAuth secret
  // lives in `action1_client_secret`. Verified live 2026-09-18.
  const clientId = pick(settings, ["action1_client_id"], "ACTION1_CLIENT_ID");
  const apiKey =
    pick(settings, ["action1_client_secret"], "ACTION1_CLIENT_SECRET") ||
    pick(settings, ["action1_api_key"], "ACTION1_API_KEY");
  const apiUrl =
    pick(settings, ["action1_api_url"], "ACTION1_API_URL") ||
    "https://app.action1.com/api/3.0";

  if (!clientId || !apiKey) {
    result.errors.push("Action1 not configured (missing client_id or client_secret)");
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

    // Fetch managed endpoints: Action1 has NO collection-root route
    // (`/endpoints` returns an Apache HTML 403 — verified 2026-09-18 that a
    // deliberately bogus path returns the byte-identical page, so that 403
    // means "no such route", NOT "permission denied"). The documented shape is
    // organization-scoped: /endpoints/managed/{organization_id}.
    const orgResp = await fetch(`${apiUrl}/organizations?from=0&limit=50`, {
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      signal: AbortSignal.timeout(30000),
    });
    if (!orgResp.ok) {
      result.errors.push(`Action1 organizations fetch failed: HTTP ${orgResp.status}`);
      return result;
    }
    const orgData: any = await orgResp.json();
    const orgs: any[] = orgData.items || orgData.data || [];

    const endpoints: any[] = [];
    for (const org of orgs) {
      const orgId = String(org.id || "");
      if (!orgId) continue;
      for (let from = 0; from < 1000; from += 50) {
        const epResp = await fetch(
          `${apiUrl}/endpoints/managed/${orgId}?from=${from}&limit=50`,
          { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" }, signal: AbortSignal.timeout(30000) }
        );
        if (!epResp.ok) {
          result.errors.push(`Action1 endpoints fetch failed for org ${orgId}: HTTP ${epResp.status}`);
          break;
        }
        const page: any = await epResp.json();
        const items: any[] = page.items || [];
        for (const ep of items) endpoints.push({ ...ep, _org_name: org.name, _org_id: orgId });
        const total = Number(page.total_items ?? page.totalItems ?? items.length);
        if (items.length === 0 || from + 50 >= total) break;
      }
    }

    for (const ep of endpoints) {
      const externalId = String(ep.id || ep.endpoint_id || "");
      if (!externalId) continue;

      const epName = ep.device_name || ep.name || ep.hostname || ep.display_name || "";
      const ip = ep.address || ep.ip_address || ep.ip || "";
      // Action1 reports "Connected" / "Disconnected"; the network_devices
      // mirror normalises those to online/offline.
      const status = ep.status || ep.connection_status || "unknown";
      const lastSeen = action1Timestamp(ep.last_seen || ep.last_contact || null);

      // Match on the ORGANIZATION name first (Action1 orgs map 1:1 to clients:
      // "Blue Mogul", "GEMCOM", "Mahoney Elite Realty", "S2S Couture"), then
      // fall back to the endpoint name.
      const match = matchClient(String(ep._org_name || epName), null, clients) || matchClient(String(epName), null, clients);
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

  const apiKey = pick(settings, ["jumpcloud_api_key"], "JUMPCLOUD_API_KEY");

  if (!apiKey) {
    result.errors.push("JumpCloud not configured (missing api_key)");
    return result;
  }

  // Do NOT send x-org-id: with the stored key it makes JumpCloud answer
  // 404 "selected organization not found". The key alone selects the tenant.
  const headers = { "x-api-key": apiKey, "Content-Type": "application/json", Accept: "application/json" };

  // The v2 path 404s for this tenant's key; the v1 base (/api) authenticates.
  // Try v2 first (correct for modern tenants) and fall back on 404. Page size
  // must stay <= 100 — the v1 API answers 400 "limit exceeds maximum value of
  // 100" for anything larger.
  let base = "https://console.jumpcloud.com/api/v2";
  const fetchJc = async (path: string): Promise<any> => {
    const attempt = async (b: string) => {
      const resp = await fetch(`${b}${path}`, { headers, signal: AbortSignal.timeout(30000) });
      if (!resp.ok) throw new Error(`JumpCloud HTTP ${resp.status}`);
      return resp.json();
    };
    try {
      return await attempt(base);
    } catch (e: any) {
      if (!/404/.test(e.message) || base.endsWith("/api")) throw e;
      base = "https://console.jumpcloud.com/api";
      return attempt(base);
    }
  };

  /** v1 returns {totalCount, results:[…]}; v2 returns a bare array. */
  const asList = (data: any): any[] =>
    Array.isArray(data) ? data : (data?.results ?? data?.data ?? []);

  try {
    // Fetch systems
    const systems: any[] = asList(await fetchJc("/systems?limit=100"));
    const sysList = systems || [];

    for (const sys of sysList) {
      const externalId = String(sys.id || sys._id || "");
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
    const users: any[] = asList(await fetchJc("/users?limit=100"));
    const userList = users || [];

    for (const u of userList) {
      const externalId = String(u.id || u._id || "");
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

  // Credentials live in system_settings under the `voip_ms_*` names, while this
  // function used to read `voip_api_*` — every lookup missed and the sync sent
  // stale env values, so VoIP.ms always answered "Username or Password is
  // incorrect". Accept both spellings.
  const username = pick(
    settings,
    ["voip_ms_username", "voip_api_username", "voipms_username"],
    "VOIP_USERNAME"
  );
  const password = pick(
    settings,
    ["voip_ms_password", "voip_api_password", "voipms_password"],
    "VOIP_PASSWORD"
  );
  const token = pick(
    settings,
    ["voip_ms_api_key", "voip_api_token", "voipms_api_key"],
    "VOIP_API_TOKEN"
  );
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

  const token = pick(settings, ["hetzner_api_token", "hetzner_token"], "HETZNER_API_TOKEN");
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

  const apiEmail = pick(settings, ["hostwinds_api_email"], "HOSTWINDS_API_EMAIL");
  const apiKey = pick(settings, ["hostwinds_api_key"], "HOSTWINDS_API_KEY");
  const apiUrl =
    pick(settings, ["hostwinds_api_url"], "HOSTWINDS_API_URL") ||
    "https://clients.hostwinds.com/HostwindsResellerAPI/api.php";

  if (!apiEmail || !apiKey) {
    result.errors.push("Hostwinds not configured (missing api_email or api_key)");
    return result;
  }

  // Hostwinds' reseller API reads ONLY $_POST and checks three exact,
  // case-sensitive field names in this order: `action`, `reseller_api_key`,
  // `reseller_email`. Anything else (apikey/api_key/key, headers, cookies,
  // JSON, the query string) returns the masking error
  // {"result":0,"msg":"No API KEY in request"} — verified 2026-09-18 against
  // Hostwinds' own shipped module source, which contains this endpoint.
  const callHwApi = async (action: string, extra: Record<string, string> = {}): Promise<any> => {
    const postFields = new URLSearchParams({
      action,
      reseller_email: apiEmail,
      reseller_api_key: apiKey,
      ...extra,
    });
    const resp = await fetch(apiUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
      body: postFields.toString(),
      signal: AbortSignal.timeout(30000),
    });
    if (!resp.ok) throw new Error(`Hostwinds HTTP ${resp.status}`);
    const data: any = await resp.json();
    const msg = String(data?.msg ?? "");
    if (/api key is invalid/i.test(msg)) {
      throw new Error("Hostwinds rejected the API key — regenerate it at clients.hostwinds.com -> Reseller -> API users");
    }
    if (/no api key|no reseller email/i.test(msg)) {
      throw new Error(`Hostwinds credential fields not accepted: ${msg}`);
    }
    if (/params error/i.test(msg)) {
      throw new Error(`Hostwinds action rejected: ${msg}`);
    }
    return data;
  };

  try {
    // 1) Health check. Hostwinds answers {"result":1,"msg":"OK"} for good
    //    credentials; anything else is a real failure worth surfacing.
    const health = await callHwApi("TestConnection");
    if (Number(health?.result) !== 1) {
      result.errors.push(`Hostwinds TestConnection failed: ${health?.msg ?? "unknown"}`);
      return result;
    }

    // 2) Reseller product catalogue — the only ACCOUNT-WIDE listing this API
    //    exposes. Mirrored into hostwinds_products: these are sellable products,
    //    not customer devices, so they do not belong in network_assets.
    const catalogue = await callHwApi("ProductsList");
    const groups: Record<string, any[]> = catalogue?.products ?? {};
    let mirrored = 0;
    for (const [group, items] of Object.entries(groups)) {
      for (const p of Array.isArray(items) ? items : []) {
        const pid = Number(p?.id);
        if (!Number.isFinite(pid)) continue;
        const price = p?.price ?? {};
        const monthly = price.monthly ?? price.msetupfee ?? null;
        await pool.query(
          `INSERT INTO hostwinds_products (product_id, name, product_group, description, price_paytype, price_monthly, raw, synced_at)
           VALUES ($1,$2,$3,$4,$5,$6,$7,NOW())
           ON CONFLICT (product_id) DO UPDATE SET name = EXCLUDED.name, product_group = EXCLUDED.product_group,
             description = EXCLUDED.description, price_paytype = EXCLUDED.price_paytype,
             price_monthly = EXCLUDED.price_monthly, raw = EXCLUDED.raw, synced_at = NOW()`,
          [
            pid,
            p?.name ?? null,
            group,
            String(p?.description ?? "").slice(0, 4000) || null,
            price.paytype ?? null,
            monthly === null || monthly === undefined || monthly === "" ? null : Number(monthly),
            JSON.stringify(p),
          ]
        );
        mirrored++;
      }
    }
    result.synced = mirrored;
    if (mirrored === 0) result.errors.push("Hostwinds returned an empty product catalogue");

    // 3) Services: this API has NO account-wide service list. GetServicesData
    //    requires hostings_ids and ServicesStatus / ServicesProduct require
    //    hosting_id — those ids live in the reseller module that created the
    //    accounts. Say so instead of reporting a misleading zero.
    result.errors.push(
      `note: Hostwinds exposes no account-wide service list (GetServicesData needs hostings_ids, ServicesStatus needs hosting_id) — ${mirrored} reseller product(s) catalogued instead`
    );
  } catch (e: any) {
    result.errors.push(`Hostwinds sync error: ${e.message}`);
  }

  return result;
}

// ── Source 6: UISP (Ubiquiti ISP) ──────────────────────────────────────────

async function syncUisp(
  pool: pg.Pool,
  clients: ClientRecord[],
  settings: Record<string, string>
): Promise<SyncResult> {
  const result: SyncResult = { source: "uisp", synced: 0, matched: 0, errors: [] };

  const apiKey = pick(settings, ["uisp_api_key"], "UISP_API_KEY");
  const apiUrl = pick(settings, ["uisp_url"], "UISP_URL").replace(/\/+$/, "");

  if (!apiKey || !apiUrl) {
    result.errors.push("UISP not configured (missing uisp_url or uisp_api_key)");
    return result;
  }

  const callUisp = async (path: string): Promise<any> => {
    const resp = await fetch(`${apiUrl}${path}`, {
      headers: { "x-auth-token": apiKey, Accept: "application/json" },
      signal: AbortSignal.timeout(30000),
    });
    if (!resp.ok) throw new Error(`UISP HTTP ${resp.status} on ${path}`);
    return resp.json();
  };

  // 1. Devices → network_assets (shown in Network Docs)
  try {
    const devices = await callUisp("/nms/api/v2.1/devices");
    const list = Array.isArray(devices) ? devices : (devices.data || devices.devices || []);

    for (const d of list) {
      const externalId = String(d.id || d.identification?.id || "");
      if (!externalId) continue;

      const name = d.identification?.name || d.name || d.hostname || `UISP device ${externalId}`;
      const ip = d.ipAddress || d.ip || d.identification?.ipAddress || "";
      const rawStatus = (d.overview?.status || d.status || "").toString().toLowerCase();
      const status = rawStatus === "active" ? "online" : (rawStatus || "unknown");
      const deviceType = String(d.identification?.type || d.type || "uisp_device");
      const lastSeen = d.overview?.lastSeen ? new Date(d.overview.lastSeen).toISOString() : null;

      const match = matchClient(String(name), null, clients);
      const clientId = match?.clientId ?? null;
      if (clientId) result.matched++;

      await upsertAsset(pool, {
        client_id: clientId,
        source: "uisp",
        asset_type: deviceType,
        external_id: externalId,
        name: String(name),
        ip: String(ip),
        status: String(status),
        last_seen: lastSeen,
        raw: d,
      });

      if (clientId) {
        await upsertMapping(pool, clientId, "uisp", externalId, String(name), match?.method ?? "auto");
      }
      result.synced++;
    }

    if (result.synced === 0) {
      // Auth and URL are fine (a bad key gives 401 here) — the instance simply
      // has no devices provisioned yet. Say so instead of reporting silence.
      result.errors.push("UISP authenticated but returned 0 devices (none provisioned in this UISP instance yet)");
    }
  } catch (e: any) {
    result.errors.push(`UISP device sync error: ${e.message}`);
  }

  // 2. Sites → network_sites (insert-if-new, by name)
  try {
    const sites = await callUisp("/nms/api/v2.1/sites");
    const siteList = Array.isArray(sites) ? sites : (sites.data || []);

    for (const s of siteList) {
      const siteName = s.name || s.identification?.name || "";
      if (!siteName) continue;

      const desc = s.description || {};
      const loc = s.location || desc.location || {};
      const lat = s.latitude ?? desc.latitude ?? loc.latitude ?? null;
      const lng = s.longitude ?? desc.longitude ?? loc.longitude ?? null;
      const address = s.address ?? desc.address ?? loc.address ?? null;
      const rawStatus = (s.identification?.status || s.status || "").toString().toLowerCase();
      const siteStatus = rawStatus === "active" ? "active" : "planned";

      await pool.query(
        `INSERT INTO network_sites (name, address, latitude, longitude, site_type, status)
         SELECT $1, $2, $3, $4, 'UISP', $5
         WHERE NOT EXISTS (SELECT 1 FROM network_sites WHERE name = $1)`,
        [siteName, address, lat, lng, siteStatus]
      );
      // Backfill missing geo/address on an existing site (never clobbers user-set values)
      await pool.query(
        `UPDATE network_sites SET latitude = COALESCE(latitude, $2), longitude = COALESCE(longitude, $3), address = COALESCE(address, $4)
         WHERE name = $1`,
        [siteName, lat, lng, address]
      );
    }
  } catch (e: any) {
    result.errors.push(`UISP site sync error: ${e.message}`);
  }

  return result;
}

// ── Source 7: Hostwinds Cloud (instances) ──────────────────────────────────
//
// The Cloud API is NOT the white-label reseller API. It is a POST form API:
//   POST https://clients.hostwinds.com/cloud/api.php   action=<name> & API=<key>
// Docs: https://developers.hostwinds.com/cloud/ (127 actions; read-only ones
// include get_instances, get_instance_ips, get_price_list, get_snapshots).
//
// TWO TRAPS, both verified 2026-09-18:
//  1. The key is IP-allow-listed (IPv4). Our hosts egress over IPv6 first
//     (`2a01:4ff:...`), so the request must be forced to IPv4 or Hostwinds
//     answers {"result":"error","action":"Authorization","message":"Not Authorized!"}.
//  2. The allow-list contains bme-hills-02 (5.78.116.116), NOT the portal host
//     bme-hills-01 (5.78.87.79), so from the portal the API is unauthorized
//     until that address is added at clients.hostwinds.com/cloud/api_keys.php.

async function syncHostwindsCloud(
  pool: pg.Pool,
  clients: ClientRecord[],
  settings: Record<string, string>
): Promise<SyncResult> {
  const result: SyncResult = { source: "hostwinds_cloud", synced: 0, matched: 0, errors: [] };

  const apiKey = pick(settings, ["hostwinds_cloud_api_key", "hostwinds_cloud_key"], "HOSTWINDS_CLOUD_API_KEY");
  const apiUrl = pick(settings, ["hostwinds_cloud_api_url"], "HOSTWINDS_CLOUD_API_URL") ||
    "https://clients.hostwinds.com/cloud/api.php";

  if (!apiKey) {
    result.errors.push("Hostwinds Cloud not configured (missing API key)");
    return result;
  }

  const call = async (action: string, extra: Record<string, string> = {}): Promise<any> => {
    const resp = await fetch(apiUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
      body: new URLSearchParams({ action, API: apiKey, ...extra }).toString(),
      signal: AbortSignal.timeout(30000),
    });
    const text = await resp.text();
    if (!resp.ok) throw new Error(`Hostwinds Cloud HTTP ${resp.status}: ${text.slice(0, 160)}`);
    let json: any;
    try { json = JSON.parse(text); } catch { throw new Error(`Hostwinds Cloud returned non-JSON: ${text.slice(0, 140)}`); }
    // The API reports failures as [{"result":"error","action":"…","message":"…"}]
    const first = Array.isArray(json) ? json[0] : json;
    if (first && first.result === "error") {
      const msg = String(first.message ?? "unknown error");
      if (/not authorized/i.test(msg)) {
        throw new Error(
          "Hostwinds Cloud rejected this host — the API key's IP allow-list must include this server's IPv4 address (portal/h01 = 5.78.87.79). Add it at clients.hostwinds.com/cloud/api_keys.php"
        );
      }
      throw new Error(`Hostwinds Cloud ${first.action ?? action}: ${msg}`);
    }
    return json;
  };

  // Force IPv4 for this source only: the allow-list is IPv4 and our resolver
  // prefers IPv6, which silently fails authorization.
  const previousOrder = dns.getDefaultResultOrder?.();
  try {
    dns.setDefaultResultOrder("ipv4first");

    const instancesRaw = await call("get_instances");
    const instances: any[] = Array.isArray(instancesRaw?.success) ? instancesRaw.success : (Array.isArray(instancesRaw) ? instancesRaw : []);

    for (const inst of instances) {
      // Defensive mapping: the account has no instances yet, so the exact field
      // names could not be confirmed against live data. Take the first plausible
      // key rather than assuming one shape.
      const externalId = String(
        inst?.id ?? inst?.serviceid ?? inst?.instance_id ?? inst?.uuid ?? inst?.name ?? ""
      ).trim();
      if (!externalId) continue;

      const name = String(inst?.name ?? inst?.hostname ?? inst?.label ?? `Hostwinds instance ${externalId}`);
      const ip = String(inst?.ip ?? inst?.ip_address ?? inst?.main_ip ?? inst?.primary_ip ?? "");
      const status = String(inst?.status ?? inst?.state ?? "unknown");

      const match = matchClient(name, null, clients);
      const clientId = match?.clientId ?? null;
      if (clientId) result.matched++;

      await upsertAsset(pool, {
        client_id: clientId,
        source: "hostwinds_cloud",
        asset_type: "cloud_instance",
        external_id: externalId,
        name,
        ip,
        status,
        last_seen: null,
        raw: inst,
      });
      if (clientId) await upsertMapping(pool, clientId, "hostwinds_cloud", externalId, name, match?.method ?? "auto");
      result.synced++;
    }

    if (instances.length === 0) {
      result.errors.push("note: Hostwinds Cloud authenticated — this account has 0 cloud instances");
    }
  } catch (e: any) {
    result.errors.push(`Hostwinds Cloud sync error: ${e.message}`);
  } finally {
    if (previousOrder) dns.setDefaultResultOrder(previousOrder as any);
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
  hostwinds_cloud: syncHostwindsCloud,
  uisp: syncUisp,
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