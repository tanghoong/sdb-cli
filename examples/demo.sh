#!/usr/bin/env bash
# sdb demo (macOS / Linux / Git Bash) — runs the full command set against a throwaway store.
# Usage:  bash examples/demo.sh
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(dirname "$here")"
bin="$root/bin/sdb"
export SDB_DATA_DIR="$(mktemp -d)"

sdb() { php "$bin" "$@"; }

echo "== store: $SDB_DATA_DIR =="
echo "== import seed (5 products) =="; sdb import products --from "$here/products.ndjson"
echo "== count =="                    ; sdb count products
echo "== list ids =="                 ; sdb list products --raw
echo "== get p1 =="                    ; sdb get products p1
echo "== find price < 500, cheapest first =="; sdb find products --where price:lt:500 --order price:asc --raw
echo "== count in stock =="           ; sdb count products --where stock:gt:0
echo "== export =="                    ; sdb export products

rm -rf "$SDB_DATA_DIR"
echo "== done (throwaway store cleaned up) =="
