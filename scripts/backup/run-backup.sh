#!/bin/sh
# Nightly logical backup of the bundled portal database.
#
# Runs inside the `backup` service in docker-compose.yml. Writes a compressed
# custom-format dump to /backups (the portal_backups volume) and prunes dumps
# older than RETENTION_DAYS. It does NOT copy off-host — do that separately
# (see DEPLOY.md, "Backups"), because a backup that only exists on this host
# does not survive losing this host.

set -eu
: "${RETENTION_DAYS:=14}"
: "${PGHOST:=db}"
: "${PGUSER:=portal}"
: "${PGDATABASE:=bluemogul_portal}"
: "${BACKUP_DIR:=/backups}"
: "${BACKUP_INTERVAL_SECONDS:=86400}"

# Assigning with ${VAR:=default} does NOT export the variable in POSIX sh, so
# pg_dump would not see these and would fall back to a local unix socket
# ("No such file or directory" on /var/run/postgresql/.s.PGSQL.5432).
export PGHOST PGUSER PGDATABASE

echo "[backup] starting; host=$PGHOST db=$PGDATABASE interval=${BACKUP_INTERVAL_SECONDS}s retention=${RETENTION_DAYS}d"
mkdir -p "$BACKUP_DIR"

run_once() {
  ts=$(date -u +%Y%m%dT%H%M%SZ)
  out="$BACKUP_DIR/portal-$ts.dump"
  if pg_dump -Fc -f "$out" 2>/tmp/pg_dump.err; then
    echo "[backup] wrote $out ($(wc -c < "$out") bytes)"
  else
    echo "[backup] FAILED: $(cat /tmp/pg_dump.err)"
    rm -f "$out"
  fi
  # Verify the newest dump is readable before pruning older ones, so a broken
  # run can never leave us with zero restorable backups.
  newest=$(ls -1t "$BACKUP_DIR"/portal-*.dump 2>/dev/null | head -1 || true)
  if [ -n "$newest" ]; then
    if pg_restore --list "$newest" >/dev/null 2>&1; then
      echo "[backup] verified $newest is a readable archive"
    else
      echo "[backup] WARNING: $newest failed verification — skipping prune"
      return 0
    fi
  fi
  find "$BACKUP_DIR" -name 'portal-*.dump' -type f -mtime "+$RETENTION_DAYS" -print -delete
}

while :; do
  run_once
  sleep "$BACKUP_INTERVAL_SECONDS"
done
