#!/usr/bin/env bash
set -euo pipefail

php bin/neo analytics:install
php bin/neo docs:sync
php bin/neo marketplace:sync || echo "marketplace:sync failed, the marketplace keeps its last version"

CURRENT="$(dirname "$(dirname "$PWD")")/current"
JOB="*/15 * * * * cd ${CURRENT} && php bin/neo docs:sync >/dev/null 2>&1; php bin/neo marketplace:sync >/dev/null 2>&1"

{ crontab -l 2>/dev/null | grep -v 'bin/neo docs:sync' || true; echo "$JOB"; } | crontab -
