#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
exec php ./node_sync_agent.php --once
