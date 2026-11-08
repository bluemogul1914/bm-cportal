import pg from "pg";
import crypto from "crypto";

const CONTABO_AUTH_URL = "https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token";
const CONTABO_API_BASE = "https://api.contabo.com/v1";

interface ContaboCreds {
  clientId: string;
  clientSecret: string;
  username: string;
  password: string;
}

async function getContaboCreds(pool: pg.Pool): Promise<ContaboCreds | null> {
  try {
    const { rows } = await pool.query(
      "SELECT username, secret_cipher, otp_secret_cipher, notes FROM credentials WHERE label = 'Contabo API' LIMIT 1"
    );
    if (!rows.length) return null;
    const { decryptSecret } = await import("./credential-vault.js");
    const secret = decryptSecret(rows[0].secret_cipher);
    const password = rows[0].otp_secret_cipher ? decryptSecret(rows[0].otp_secret_cipher) : "";
    const clientIdMatch = rows[0].notes?.match(/Client ID: (\S+?)(?:\.|$)/);
    const clientId = clientIdMatch ? clientIdMatch[1] : "INT-15467787";
    return { clientId, clientSecret: secret, username: rows[0].username, password };
  } catch { return null; }
}

async function getToken(pool: pg.Pool): Promise<string | null> {
  const creds = await getContaboCreds(pool);
  if (!creds) return null;
  const params = new URLSearchParams();
  params.append("grant_type", "password");
  params.append("client_id", creds.clientId);
  params.append("client_secret", creds.clientSecret);
  params.append("username", creds.username);
  params.append("password", creds.password);
  try {
    const resp = await fetch(CONTABO_AUTH_URL, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString(),
    });
    if (!resp.ok) return null;
    const data = await resp.json();
    return data.access_token || null;
  } catch { return null; }
}

export async function contaboTestConnection(pool: pg.Pool): Promise<{ ok: boolean; message: string }> {
  const token = await getToken(pool);
  if (!token) return { ok: false, message: "Authentication failed" };
  return { ok: true, message: "Connected to Contabo API" };
}

export async function contaboListInstances(pool: pg.Pool): Promise<any[]> {
  const token = await getToken(pool);
  if (!token) throw new Error("Not authenticated with Contabo");
  const resp = await fetch(`${CONTABO_API_BASE}/compute/instances`, {
    headers: { Authorization: `Bearer ${token}`, "x-request-id": crypto.randomUUID() },
  });
  if (!resp.ok) throw new Error(`Contabo API error: ${resp.status}`);
  const data = await resp.json();
  return data.data || [];
}

export async function contaboGetInstance(pool: pg.Pool, instanceId: string): Promise<any> {
  const token = await getToken(pool);
  if (!token) throw new Error("Not authenticated with Contabo");
  const resp = await fetch(`${CONTABO_API_BASE}/compute/instances/${instanceId}`, {
    headers: { Authorization: `Bearer ${token}`, "x-request-id": crypto.randomUUID() },
  });
  if (!resp.ok) throw new Error(`Contabo API error: ${resp.status}`);
  const data = await resp.json();
  return data.data || null;
}

export async function contaboListImages(pool: pg.Pool): Promise<any[]> {
  const token = await getToken(pool);
  if (!token) throw new Error("Not authenticated with Contabo");
  const resp = await fetch(`${CONTABO_API_BASE}/compute/images?size=100`, {
    headers: { Authorization: `Bearer ${token}`, "x-request-id": crypto.randomUUID() },
  });
  if (!resp.ok) throw new Error(`Contabo API error: ${resp.status}`);
  const data = await resp.json();
  return data.data || [];
}

export async function contaboListProducts(pool: pg.Pool): Promise<any[]> {
  const token = await getToken(pool);
  if (!token) throw new Error("Not authenticated with Contabo");

  const resp = await fetch(`${CONTABO_API_BASE}/products?size=200`, {
    headers: { Authorization: `Bearer ${token}`, "x-request-id": crypto.randomUUID() },
  });
  if (!resp.ok) throw new Error(`Contabo API error: ${resp.status}`);
  const data = await resp.json();
  return (data.data || []).map((p: any) => {
    const pi = p.priceItem || {};
    const prices = pi.price || [];
    const usdPrice = prices.find((pr: any) => pr.currency === "USD")?.amount;
    return {
      productId: pi.itemId || p.productId || p.id,
      name: pi.name || p.name || p.productName,
      priceUsd: usdPrice || null,
      outOfStock: pi.outOfStock?.isOutOfStock || false,
    };
  }).filter((p: any) => p.productId && p.name && !p.outOfStock && /VPS/i.test(p.name));
}

export async function contaboCreateInstance(pool: pg.Pool, spec: {
  region: string;
  imageId: string;
  productId: string;
  displayName?: string;
  sshKeys?: string[];
}): Promise<any> {
  const token = await getToken(pool);
  if (!token) throw new Error("Not authenticated with Contabo");
  const body: any = { region: spec.region, imageId: spec.imageId, productId: spec.productId, period: 1 };
  if (spec.displayName) body.displayName = spec.displayName;
  if (spec.sshKeys?.length) body.sshKeys = spec.sshKeys;
  const resp = await fetch(`${CONTABO_API_BASE}/compute/instances`, {
    method: "POST",
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json", "x-request-id": crypto.randomUUID() },
    body: JSON.stringify(body),
  });
  if (!resp.ok) {
    const err = await resp.text();
    throw new Error(`Contabo API error: ${resp.status} - ${err}`);
  }
  const data = await resp.json();
  return data.data || data;
}
