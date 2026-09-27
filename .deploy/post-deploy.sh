#!/usr/bin/env bash
set -euo pipefail

php bin/neo analytics:install
php bin/neo docs:sync

CURRENT="$(dirname "$(dirname "$PWD")")/current"
JOB="*/15 * * * * cd ${CURRENT} && php bin/neo docs:sync >/dev/null 2>&1"

{ crontab -l 2>/dev/null | grep -v 'bin/neo docs:sync' || true; echo "$JOB"; } | crontab -
