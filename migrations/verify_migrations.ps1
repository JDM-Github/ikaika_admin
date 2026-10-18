<#
.SYNOPSIS
  Health-checks every generated data.sql against its Airtable export.

.DESCRIPTION
  For each product it runs verify_migration.py against
  migrations/outputs/<folder>/{export,data.sql} and writes a coverage_report.json
  beside the data.sql. A base whose export/ is missing is skipped (run
  migrate-all first). Prints one summary table and exits 1 if any base fails.

  This proves data.sql faithfully reproduces its export; it does not re-read
  Airtable. To prove Airtable == export as of now, re-run migrate-all first.

.PARAMETER Env
  test | prod -- which set of outputs to check.

.PARAMETER Detailed
  Also stream the full verifier output per base (off: only the summary).

.EXAMPLE
  .\verify_migrations.ps1 -Env test
  .\verify_migrations.ps1 -Env prod -Detailed
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateSet('test', 'prod')]
    [string]$Env,

    [switch]$Detailed
)

$ErrorActionPreference = 'Stop'
$scriptDir = $PSScriptRoot
$repoRoot = Split-Path -Parent $scriptDir
$verifier = Join-Path $scriptDir 'verify_migration.py'

. (Join-Path $scriptDir 'lib/targets.ps1')

if (-not (Test-Path $verifier)) {
    Write-Host "verify_migration.py not found at $verifier" -ForegroundColor Red
    exit 1
}

$targets = Get-MigrationTargets -Env $Env
$results = @()

Write-Host ''
Write-Host "verify_migrations: env '$Env'" -ForegroundColor Cyan

foreach ($t in $targets) {
    $outDir = Join-Path $repoRoot ("migrations/outputs/{0}" -f $t.Folder)
    $exportDir = Join-Path $outDir 'export'
    $dataSql = Join-Path $outDir 'data.sql'
    $report = Join-Path $outDir 'coverage_report.json'

    if (-not (Test-Path $exportDir) -or -not (Test-Path $dataSql)) {
        Write-Host "  skip  $($t.Label): no export/data.sql under outputs/$($t.Folder) (run migrate-all -Env $Env)" -ForegroundColor DarkYellow
        $results += [pscustomobject]@{ Label = $t.Label; Code = 2; Note = 'skipped (no output)' }
        continue
    }

    Write-Host ''
    Write-Host "====> $($t.Label)  [outputs/$($t.Folder)]" -ForegroundColor White

    $captured = & python $verifier --export-dir $exportDir --data-sql $dataSql --base $t.Verify --report $report 2>&1
    $code = $LASTEXITCODE
    $text = ($captured | Out-String)

    if ($Detailed) {
        Write-Host $text
    }
    else {
        $last = @($captured | Where-Object { "$_" -match '^(RESULT|  ok |  warn |  info |  FAIL|  KNOWN)' })
        foreach ($line in $last) { Write-Host $line }
    }

    $results += [pscustomobject]@{ Label = $t.Label; Code = $code; Note = (($captured | Where-Object { "$_" -match '^RESULT' } | Select-Object -Last 1) -replace '^RESULT:\s*', '') }
}

Write-Host ''
Write-Host '-- verify_migrations summary ------------------------------------------' -ForegroundColor Cyan
foreach ($r in $results) {
    if ($r.Code -eq 0) { $mark = 'ok  '; $colour = 'Green' }
    elseif ($r.Code -eq 2) { $mark = 'skip'; $colour = 'DarkYellow' }
    else { $mark = 'FAIL'; $colour = 'Red' }
    Write-Host ("  {0}  {1}  {2}" -f $mark, $r.Label.PadRight(20), $r.Note) -ForegroundColor $colour
}

if (@($results | Where-Object { $_.Code -eq 1 }).Count -gt 0) {
    Write-Host ''
    Write-Host "verify_migrations: at least one base FAILED." -ForegroundColor Red
    exit 1
}
Write-Host "verify_migrations: nothing failed." -ForegroundColor Green
