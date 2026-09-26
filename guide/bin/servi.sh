#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
source guide/bin/demo-env.sh
exec php artisan serve --port=8123 --no-reload
