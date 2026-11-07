import { pool } from "../server/db";

async function main() {
  // Void demo/test clients and related records
  await pool.query(`UPDATE clients SET status = 'voided' WHERE LOWER(name) LIKE '%demo%' OR LOWER(name) LIKE '%test%'`);
  console.log("Voided demo clients");
  // Archive voided records timestamp
  await pool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS voided_at TIMESTAMP`);
  await pool.query(`ALTER TABLE clients ADD COLUMN IF NOT EXISTS voided_reason TEXT`);
  await pool.query(`UPDATE clients SET voided_at = NOW(), voided_reason = 'demo data cleanup' WHERE status = 'voided' AND voided_at IS NULL`);
  console.log("Done");
  await pool.end();
}
main().catch((e) => { console.error(e); process.exit(1); });
