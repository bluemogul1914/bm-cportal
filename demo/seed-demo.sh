#!/bin/sh
# ============================================================================
#  seed-demo.sh — scrub the demo database, set the demo login, save a clean
#  snapshot for instant resets.
#
#  Run AFTER restoring a production dump into the demo database:
#    1. dump prod (h00) and restore into portal-demo-db-1   (see DEMO.md)
#    2. /opt/portal-demo/demo/seed-demo.sh
#
#  Idempotent: it overwrites every field it touches, so it is safe to re-run.
# ============================================================================
set -eu
cd /opt/portal-demo

DB=portal-demo-db-1
DUMPDIR=/opt/portal-demo/dump
CLEAN="$DUMPDIR/demo-clean.dump"
IMAGE=portal-demo-app:latest

[ -f DEMO-CREDENTIALS.txt ] || { echo "FATAL: DEMO-CREDENTIALS.txt missing"; exit 1; }
PW=$(awk -F': *' '/^Demo admin password/{print $2}' DEMO-CREDENTIALS.txt)
[ -n "${PW:-}" ] || { echo "FATAL: no 'Demo admin password' line in DEMO-CREDENTIALS.txt"; exit 1; }

echo "==> computing bcrypt hash for the demo login (password never leaves this host)"
HASH=$(sudo docker run --rm -e PW="$PW" "$IMAGE" php -r 'echo password_hash(getenv("PW"), PASSWORD_BCRYPT);')
[ -n "$HASH" ] || { echo "FATAL: hash computation failed"; exit 1; }
echo "    hash: ${#HASH} chars"

echo "==> applying scrub (PII masked, credentials stripped, money randomised)"
sudo docker exec -i "$DB" psql -U portal -d bluemogul_portal \
  -v ON_ERROR_STOP=1 -v demo_hash="$HASH" -f - < demo/scrub-demo.sql

echo "==> saving clean snapshot for fast resets -> $CLEAN"
sudo mkdir -p "$DUMPDIR"
sudo docker exec "$DB" pg_dump -U portal -d bluemogul_portal -Fc --no-owner --no-acl | sudo tee "$DUMPDIR/.tmp.dump" >/dev/null
sudo mv "$DUMPDIR/.tmp.dump" "$CLEAN"
sudo chmod 644 "$CLEAN"
ls -lh "$CLEAN"

echo "==> post-seed summary"
sudo docker exec -i "$DB" psql -U portal -d bluemogul_portal -tAf /dev/stdin <<'SQL'
SELECT 'clients='||count(*)||'  leads='||(SELECT count(*) FROM crm_leads)
     ||'  users='||(SELECT count(*) FROM users)
     ||'  invoices='||(SELECT count(*) FROM invoices)
     ||'  tickets='||(SELECT count(*) FROM tickets) FROM clients;
SELECT 'provider_settings='||count(*) FROM provider_settings;
SELECT 'vault_credentials='||count(*) FROM credentials;
SELECT 'sessions='||count(*) FROM session;
SELECT 'sample_client='||name||' / '||coalesce(email,'-')||' / bal='||credit_balance FROM clients LIMIT 3;
SQL
echo "==> DONE. Demo password is in /opt/portal-demo/DEMO-CREDENTIALS.txt"