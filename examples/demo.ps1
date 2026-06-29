#!/usr/bin/env pwsh
# sdb demo (Windows / PowerShell) — runs the full command set against a throwaway store.
# Usage:  pwsh examples/demo.ps1
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$bin  = Join-Path $root 'bin/sdb'
$seed = Join-Path $PSScriptRoot 'products.ndjson'
$env:SDB_DATA_DIR = Join-Path ([IO.Path]::GetTempPath()) ('sdb-demo-' + [guid]::NewGuid().ToString('N').Substring(0, 8))

function sdb { & php $bin @args }

Write-Host "== store: $env:SDB_DATA_DIR =="
Write-Host "`n== import seed (5 products) =="; sdb import products --from $seed
Write-Host "`n== count =="                    ; sdb count products
Write-Host "`n== list ids =="                 ; sdb list products --raw
Write-Host "`n== get p1 =="                    ; sdb get products p1
Write-Host "`n== find price < 500, cheapest first =="; sdb find products --where price:lt:500 --order price:asc --raw
Write-Host "`n== count in stock =="           ; sdb count products --where stock:gt:0
Write-Host "`n== export =="                    ; sdb export products

Remove-Item -Recurse -Force $env:SDB_DATA_DIR
Write-Host "`n== done (throwaway store cleaned up) =="
