# Blue Mogul Client Portal (bm-cportal) — dev/debug/deploy

## 2026-08-28 — Portal ↔ TwentyCRM sync + Portal-leads MCP for BM AI
Tracey-requested two integrations. Both verified end-to-end then test data cleaned up.

### 1. Portal → TwentyCRM lead auto-sync (real-time) — WORKING, verified
- Hooked `POST /api/webhook/create-lead` (server/index.ts): after `crm_leads` insert, creates a
  Twenty **Company → Person → Opportunity** (stage NEW) via the public Twenty MCP.
- **Critical protocol detail:** Twenty's public `https://twenty.bluemogul.us/mcp` exposes CRUD only
  through the `execute_tool` MCP tool (param `toolName` + `arguments`) — NOT direct
  `create_one_company` etc. (which returns "Unknown tool"). The 7 top-level tools are
  get_tool_catalog/execute_tool/learn_tools/search_help_center/etc.
- Portal env added: `TWENTY_CRM_API_KEY` (the dev `MCP_TWENTY_CRM_API_KEY`, 444-char), `TWENTY_API_URL`,
  `SESSION_SECRET` (= `PORTAL_SECRET`; was absent so webhook auth was broken). Commits `968531f`,
  `e891a23` pushed to main → auto-deploy.
- **Verified live:** webhook → lead #515 + Twenty company/person/opportunity with real UUIDs;
  found the opportunity in Twenty; then deleted all test records + portal test leads.
- The `127.0.0.1:3132` twenty-mcp-proxy and `3105` twenty-crm-mcp are internal; the public /mcp via
  `execute_tool` is the portal's path.

### 2. portal-leads MCP → BM AI (read-only) — WORKING, verified
- `/opt/portal-mcp/index.js`: Node MCP server reading the portal Neon DB (`crm_leads`, `clients`,
  `tickets`, `invoices`) with READ-ONLY tools: `portal_list_leads`, `portal_search_leads`,
  `portal_list_clients`, `portal_search_clients`, `portal_list_tickets`, `portal_list_invoices`,
  `portal_dashboard`.
- DB creds: `/etc/portal-mcp.env` (mode 600). Bridge unit `portal-mcp-bridge.service`
  (supergateway → streamable-http on **3150**), running.
- Wired into BM AI: added `portal-mcp` to `/opt/librechat/librechat.yaml` (mcpServers +
  allowedAddresses 3150), restarted LibreChat. Verified `[MCP][portal-mcp] Initialized` (7 tools);
  LibreChat now **18 servers / 546 tools**.
- Verified live: `portal_dashboard` → 509 leads, 7 clients, 2 open tickets, 3 unpaid invoices.

### Files
- portal: `/home/bluemogul/bm-cportal/server/index.ts`
- MCP: `/opt/portal-mcp/index.js`, package.json
- units/env: `/etc/systemd/system/portal-mcp-bridge.service`, `/etc/portal-mcp.env`
- librechat: `/opt/librechat/librechat.yaml` (+ `.bak-portal-mcp`)
- CHANGES full record: `/home/bluemogul/configs/CHANGES.md`