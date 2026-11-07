#!/bin/sh
# ============================================================================
#  demo-origin-firewall.sh — LAN backstop for the demo portal origin.
#
#  The demo app is published on 10.0.0.4:3200 because the Cloudflare tunnel's
#  connector runs on a DIFFERENT host (h00) and must be able to dial it over the
#  private network. That means every other node on the private network could
#  also dial it. This rule narrows it to the tunnel host only.
#
#  Matches the CONTAINER port (3000), NOT the published port (3200): DNAT
#  rewrites the destination before DOCKER-USER is consulted, so a --dport 3200
#  rule would silently match nothing.
#
#  Scoped by the app network's bridge interface and dport 3000, so no other
#  container on this host is affected — including Coolify's published 80/443.
#  Host-originated traffic (docker-proxy) does not traverse FORWARD and is
#  therefore unaffected, which is why the app still works locally.
# ============================================================================
set -eu

NET=portal-demo_portal
APP_PORT=3000
ALLOW_FROM=10.0.0.2          # h00 — where cloudflared runs
CHAIN=DOCKER-USER

BR=$(docker network inspect "$NET" -f '{{.Id}}' 2>/dev/null | cut -c1-12)
[ -n "$BR" ] || { echo "FATAL: docker network $NET not found"; exit 1; }
BR="br-$BR"

# Idempotent: drop any previous copy of our two rules first.
while iptables -C "$CHAIN" -o "$BR" -p tcp --dport "$APP_PORT" -s "$ALLOW_FROM" -j RETURN 2>/dev/null; do
  iptables -D "$CHAIN" -o "$BR" -p tcp --dport "$APP_PORT" -s "$ALLOW_FROM" -j RETURN
done
while iptables -C "$CHAIN" -o "$BR" -p tcp --dport "$APP_PORT" -j DROP 2>/dev/null; do
  iptables -D "$CHAIN" -o "$BR" -p tcp --dport "$APP_PORT" -j DROP
done

if [ "${1:-}" = "--remove" ]; then
  echo "demo origin firewall: rules removed"
  exit 0
fi

# Insert order matters: -I puts each rule at the TOP of the chain. Insert the
# DROP first, then the RETURN, so the final order is  RETURN -> DROP.
iptables -I "$CHAIN" -o "$BR" -p tcp --dport "$APP_PORT" -j DROP
iptables -I "$CHAIN" -o "$BR" -p tcp --dport "$APP_PORT" -s "$ALLOW_FROM" -j RETURN

echo "demo origin firewall: iface=$BR dport=$APP_PORT allowed_src=$ALLOW_FROM (all others dropped)"