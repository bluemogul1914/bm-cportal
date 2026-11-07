#!/bin/sh
# ============================================================================
#  reset-demo.sh — put the demo back to a pristine, freshly-scrubbed state.
#
#  Use before each training session, or any time a trainee has "broken" the
#  demo with junk data. It restores the clean snapshot saved by seed-demo.sh,
#  so no production data and no training debris survive.
#
#  Does NOT touch production. Does NOT touch any other service on this host.
# ============================================================================
set -eu
cd /opt/portal-demo

DB=portal-demo-db-1
CLEAN=/opt/portal-demo/dump/demo-clean.dump

[ -f "$CLEAN" ] || {
  echo "FATAL: $CLEAN not found."
  echo "Run scripts/seed-demo.sh once (it creates the snapshot) — that requires"
  echo "restoring a production dump first. See DEMO.md."
  exit 1
}

echo "==> stopping the app so nothing writes during the swap"
sudo docker compose stop app >/dev/null 2>&1 || true

echo "==> recreating the demo database"
sudo docker exec -i "$DB" psql -U portal -d postgres -v ON_ERROR_STOP=1 \
  -c "DROP DATABASE IF EXISTS bluemogul_portal WITH (FORCE);"
sudo docker exec -i "$DB" psql -U portal -d postgres -v ON_ERROR_STOP=1 \
  -c "CREATE DATABASE bluemogul_portal OWNER portal;"

echo "==> restoring the clean snapshot"
sudo docker exec -i "$DB" pg_restore -U portal -d bluemogul_portal \
  --no-owner --no-acl --single-transaction < "$CLEAN"

echo "==> starting the app again"
sudo docker compose up -d app >/dev/null

sleep 8
sudo docker exec -i "$DB" psql -U portal -d bluemogul_portal -tAf /dev/stdin <<'SQL'
SELECT 'restored: clients='||count(*)||'  leads='||(SELECT count(*) FROM crm_leads)
     ||'  provider_settings='||(SELECT count(*) FROM provider_settings)
     ||'  sessions='||(SELECT count(*) FROM session) FROM clients;
SQL
echo "==> DONE. Demo is pristine."