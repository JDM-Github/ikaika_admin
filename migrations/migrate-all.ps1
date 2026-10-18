<#
.SYNOPSIS
  Runs the whole Airtable -> SQL pull for every base of an environment.

.DESCRIPTION
  For each product (portal, estimator, tracker) it calls migrate.ps1 with
  -Produce both, which fetches Airtable, uploads attachments, generates data.sql,
  verifies it and assembles schema.sql, all inside migrations/outputs/<folder>/.

  It stops at the first base that fails, exactly like a single migrate.ps1 run,
  and prints a per-base result at the end. Nothing touches MySQL.

.PARAMETER Env
  test  the sandbox bases (users-projects-test, transaction-tracker-test)
  prod  the live bases (users-projects-prod, transaction-tracker-prod)
  The estimator has one base, so it is included in both.

.PARAMETER Step
  all | fetch | upload | generate -- resume a pull part-way, forwarded to migrate.ps1.

.PARAMETER Token
  Airtable Personal Access Token. Falls back to .token.private.txt, then a prompt.

.EXAMPLE
  .\migrate-all.ps1 -Env test
  .\migrate-all.ps1 -Env prod -Step generate
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateSet('test', 'prod')]
    [string]$Env,

    [ValidateSet('all', 'fetch', 'upload', 'generate')]
    [string]$Step = 'all',

    [string]$Token
)

$ErrorActionPreference = 'Stop'
$scriptDir = $PSScriptRoot
$repoRoot = Split-Path -Parent $scriptDir
$migrate = Join-Path $scriptDir 'migrate.ps1'

. (Join-Path $scriptDir 'lib/targets.ps1')

function Stop-WithMessage {
    param([string]$Message, [int]$Code = 1)
    Write-Host ''
    Write-Host $Message -ForegroundColor Red
    exit $Code
}

if (-not (Test-Path $migrate)) {
    Stop-WithMessage "migrate.ps1 not found at $migrate"
}

$targets = Get-MigrationTargets -Env $Env
$results = @()

Write-Host ''
Write-Host "migrate-all: env '$Env' -- $($targets.Count) base(s)" -ForegroundColor Cyan

foreach ($t in $targets) {
    Write-Host ''
    Write-Host "====> $($t.Label)  [$($t.Target) -> outputs/$($t.Folder)]" -ForegroundColor White

    $childArgs = @('-NoProfile', '-File', $migrate, '-Target', $t.Target, '-Produce', 'both', '-Step', $Step)
    if ($Token) { $childArgs += @('-Token', $Token) }

    & pwsh @childArgs
    $code = $LASTEXITCODE
    $results += [pscustomobject]@{ Label = $t.Label; Client = $t.Target; Code = $code }

    if ($code -ne 0) {
        Write-Host ''
        Write-Host "== $($t.Label) failed (exit $code). Stopping." -ForegroundColor Red
        break
    }
}

Write-Host ''
Write-Host '-- migrate-all summary ------------------------------------------------' -ForegroundColor Cyan
foreach ($r in $results) {
    $mark = if ($r.Code -eq 0) { 'ok  ' } else { 'FAIL' }
    $colour = if ($r.Code -eq 0) { 'Green' } else { 'Red' }
    Write-Host ("  {0}  {1} ({2})" -f $mark, $r.Label, $r.Client) -ForegroundColor $colour
}

$failed = @($results | Where-Object { $_.Code -ne 0 })
if ($failed.Count -gt 0) {
    Stop-WithMessage "migrate-all: $($failed.Count) base(s) failed."
}
Write-Host "migrate-all: all $($results.Count) base(s) done." -ForegroundColor Green
