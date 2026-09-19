#!/bin/sh
set -e
DATA_DIR="${CATALOG_DB_DIR:-/var/lib/catalog}"
mkdir -p "$DATA_DIR"
# Bind mount may be root-owned; try chmod when we can write.
if [ -w "$DATA_DIR" ]; then
  chmod 770 "$DATA_DIR" 2>/dev/null || true
fi
exec httpd -DFOREGROUND
