#!/usr/bin/env bash
# Build the installable plugin zip: dist/bdaci-portal-access.zip (folder at the zip's top level).
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p dist
rm -f dist/bdaci-portal-access.zip
(cd plugin && zip -qr ../dist/bdaci-portal-access.zip bdaci-portal-access -x '*/.*')
echo "Built dist/bdaci-portal-access.zip"
