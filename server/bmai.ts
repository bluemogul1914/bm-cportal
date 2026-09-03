// BM AI (LibreChat) integration for the portal's AI Assistant.
//
// The portal AI can run on three engines:
//   1. bmai      — route chat through BM AI (LibreChat) → OpenRouter.
//                  AnythingLLM is available to BM AI as an MCP tool on port 3122.
//   2. anythingllm — direct AnythingLLM OpenAI-compatible endpoint (legacy).
//   3. ollama    — direct Ollama endpoint (legacy fallback).
//
// This module handles the "bmai" engine: it logs into BM AI as the
// portal service account (portal-bot@bluemogul.biz), caches the JWT, and
// streams completions through LibreChat's agents API:
//
//   1. POST /api/agents/chat      → { streamId, conversationId, status: "started" }
//   2. GET  /api/agents/chat/stream/:streamId  → SSE deltas
//
// Role-scoped agents (created in BM AI, owned by portal-bot):
//   staff  → agent_wcptmmWmxRW4cBISh5y9X  (BM Suite Assistant)
//   dealer → agent_uvtaSHZ8WXNDO5Tpxsh97  (BM Dealer Assistant)
//   client → agent_WZsS3o4X_NHle9bw6pp8u  (BM Client Support)
//
// Settings (system_settings table):
//   bmai_url        — e.g. https://bmai.bluemogul.us
//   bmai_email      — service account email
//   bmai_password   — service account password
//   bmai_enabled    — "true" | "false"
//   bmai_model      — model id (used by agents; kept for display)

import type { Response as ExpressResponse } from "express";
import pg from "pg";

const TOKEN_TTL_MS = 25 * 60 * 1000; // LibreChat JWTs last ~30min; refresh at 25
let cachedToken: { value: string; expiresAt: number; url: string } | null = null;
let loginInFlight: Promise<string> | null = null;

export interface BmaiSettings {
  url: string;
  email: string;
  password: string;
  enabled: boolean;
  model: string;
}

// Role → BM AI agent id. Agent ids are set at creation; update here if
// they are recreated in BM AI.
export const BMAI_AGENTS: Record<'staff' | 'dealer' | 'client', string> = {
  staff: 'agent_wcptmmWmxRW4cBISh5y9X',
  dealer: 'agent_uvtaSHZ8WXNDO5Tpxsh97',
  client: 'agent_WZsS3o4X_NHle9bw6pp8u',
};

// LibreChat's uaParser middleware rejects non-browser User-Agents ("Illegal request").
const BROWSER_UA =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

export async function getBmaiSettings(pool: pg.Pool): Promise<BmaiSettings> {
  const { rows } = await pool.query(
    `SELECT setting_key, setting_value FROM system_settings
     WHERE setting_key IN ('bmai_url','bmai_email','bmai_password','bmai_enabled','bmai_model')`
  );
  const s: Record<string, string> = {};
  for (const r of rows) s[r.setting_key] = r.setting_value;
  return {
    url: (s['bmai_url'] || 'https://bmai.bluemogul.us').replace(/\/$/, ''),
    email: s['bmai_email'] || '',
    password: s['bmai_password'] || '',
    enabled: s['bmai_enabled'] === 'true',
    model: s['bmai_model'] || 'deepseek/deepseek-v4-flash',
  };
}

export async function bmaiConfigured(s: BmaiSettings): Promise<boolean> {
  return s.enabled && !!s.url && !!s.email && !!s.password;
}

async function login(pool: pg.Pool): Promise<string> {
  const s = await getBmaiSettings(pool);
  if (!s.url || !s.email || !s.password) throw new Error('BM AI not configured (set bmai_email / bmai_password in settings)');

  const r = await fetch(`${s.url}/api/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'User-Agent': BROWSER_UA },
    body: JSON.stringify({ email: s.email, password: s.password }),
  });
  if (!r.ok) {
    const t = await r.text();
    throw new Error(`BM AI login failed: HTTP ${r.status} ${t.slice(0, 200)}`);
  }
  const j = await r.json();
  if (!j.token) throw new Error('BM AI login returned no token');
  cachedToken = { value: j.token, expiresAt: Date.now() + TOKEN_TTL_MS, url: s.url };
  return j.token;
}

export async function getBmaiToken(pool: pg.Pool): Promise<string> {
  const s = await getBmaiSettings(pool);
  if (cachedToken && cachedToken.url === s.url && Date.now() < cachedToken.expiresAt) {
    return cachedToken.value;
  }
  if (loginInFlight) return loginInFlight;
  loginInFlight = login(pool).finally(() => { loginInFlight = null; });
  return loginInFlight;
}

/** Simple health check: token → GET /api/endpoints */
export async function bmaiTest(pool: pg.Pool): Promise<{ ok: boolean; message: string; model?: string }> {
  try {
    const s = await getBmaiSettings(pool);
    const token = await getBmaiToken(pool);
    const r = await fetch(`${s.url}/api/endpoints`, {
      headers: { Authorization: `Bearer ${token}`, 'User-Agent': BROWSER_UA },
    });
    if (!r.ok) return { ok: false, message: `BM AI API HTTP ${r.status}` };
    const j: any = await r.json();
    const hasOpenRouter = Object.keys(j || {}).includes('OpenRouter');
    return { ok: true, message: `Connected — endpoint ${hasOpenRouter ? 'OpenRouter' : Object.keys(j || {})[0] || '?'}`, model: s.model };
  } catch (e: any) {
    return { ok: false, message: e.message };
  }
}

/**
 * Stream a chat completion from BM AI (LibreChat agents API) and forward
 * SSE tokens to the portal client.
 *
 * Emits the same { token: string } events as the existing /api/ollama/chat
 * endpoint so the UI needs no changes.
 */
export async function bmaiStreamChat(
  pool: pg.Pool,
  opts: {
    messages: { role: string; content: string }[];
    role: 'staff' | 'dealer' | 'client';
    conversationId?: number;
    res: ExpressResponse;
    onDone?: (fullReply: string) => void;
  }
): Promise<void> {
  const s = await getBmaiSettings(pool);
  const token = await getBmaiToken(pool);
  const agentId = BMAI_AGENTS[opts.role];

  const sendEvent = (data: object) => opts.res.write(`data: ${JSON.stringify(data)}\n\n`);
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), 600000);
  opts.res.on('close', () => { controller.abort(); clearTimeout(t); });

  // Phase 1: start the agent run.
  const start = await fetch(`${s.url}/api/agents/chat`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
      'User-Agent': BROWSER_UA,
    },
    body: JSON.stringify({
      agent_id: agentId,
      endpoint: 'agents',
      messages: opts.messages,
    }),
    signal: controller.signal,
  });
  if (!start.ok) {
    const txt = await start.text();
    throw new Error(`BM AI chat start failed: HTTP ${start.status} ${txt.slice(0, 200)}`);
  }
  const startJson: any = await start.json();
  const streamId = startJson.streamId;
  if (!streamId) throw new Error('BM AI chat returned no streamId');

  // Phase 2: stream the run deltas.
  // Poll briefly for the first chunk (agent run is async; the stream
  // endpoint may 404 for a moment until the run registers).
  let stream: Response | null = null;
  for (let attempt = 0; attempt < 10; attempt++) {
    stream = await fetch(`${s.url}/api/agents/chat/stream/${streamId}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'text/event-stream', 'User-Agent': BROWSER_UA },
      signal: controller.signal,
    });
    if (stream.ok) break;
    if (stream.status === 404 || stream.status === 409) {
      await new Promise((r) => setTimeout(r, 500));
      continue;
    }
    break;
  }
  if (!stream || !stream.ok) {
    throw new Error(`BM AI chat stream failed: HTTP ${stream?.status} ${(await stream?.text() || '').slice(0, 200)}`);
  }
  if (!stream.body) throw new Error('BM AI chat stream returned no body');

  const reader = stream.body.getReader();
  const decoder = new TextDecoder();
  let fullReply = '';
  let buffer = '';

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    const blocks = buffer.split('\n\n');
    buffer = blocks.pop() ?? '';
    for (const block of blocks) {
      // Each SSE block: "event: message\ndata: {...}"
      const dataLine = block.split('\n').find((l) => l.startsWith('data:'));
      if (!dataLine) continue;
      const payload = dataLine.slice(5).trim();
      if (!payload) continue;
      try {
        const chunk = JSON.parse(payload) as any;
        // FreeChat agents stream emits { event: "on_message_delta", data: { delta: { content: [{type:"text",text:"..."}] } } }
        const event = chunk.event;
        const inner = chunk.data ?? chunk;
        if (event === 'on_message_delta' && inner.delta?.content) {
          const parts = Array.isArray(inner.delta.content) ? inner.delta.content : [inner.delta.content];
          for (const p of parts) {
            const text = typeof p === 'string' ? p : (p?.text ?? '');
            if (text) {
              fullReply += text;
              sendEvent({ token: text });
            }
          }
        } else if (event === 'on_run_end' || event === 'done' || chunk.final === true || chunk.done === true) {
          break;
        }
      } catch { /* partial JSON — skip */ }
    }
  }
  clearTimeout(t);
  opts.onDone?.(fullReply);
}