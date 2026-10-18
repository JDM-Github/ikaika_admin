<#
.SYNOPSIS
  Checks that each base's export still matches live Airtable (record counts).

.DESCRIPTION
  Wraps livecheck.py: for every base of an environment it counts the records in
  Airtable right now and compares them to the export in
  migrations/outputs/<folder>/export. A table whose live count is higher means the
  export is STALE -- the fetch missed records that were added since -- so re-run
  the fetch before generating or importing.

  This is the guard for "did the fetch really get everything?". It is cheap: it
  reads one field per record.

.PARAMETER Env
  test | prod -- which set of exports to check against their base.

.PARAMETER Token
  Airtable Personal Access Token. Falls back to .token.private.txt, then a prompt.

.EXAMPLE
  .\check_live.ps1 -Env prod
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateSet('test', 'prod')]
    [string]$Env,

    [string]$Token
)

$ErrorActionPreference = 'Stop'
$scriptDir = $PSScriptRoot
$repoRoot = Split-Path -Parent $scriptDir

. (Join-Path $scriptDir 'lib/targets.ps1')

if (-not $Token) {
    $tokenFile = Join-Path $scriptDir '.token.private.txt'
    if (Test-Path $tokenFile) {
        $Token = (Get-Content -Raw -Path $tokenFile).Trim()
        Write-Host 'Using the token in .token.private.txt.' -ForegroundColor DarkGray
    }
}
if (-not $Token) {
    $secure = Read-Host 'Airtable Personal Access Token' -AsSecureString
    $Token = [System.Net.NetworkCredential]::new('', $secure).Password
}
if (-not $Token) {
    Write-Host 'A token is required to read the live bases.' -ForegroundColor Red
    exit 1
}

$targets = Get-MigrationTargets -Env $Env
$results = @()

$env:AIRTABLE_API_KEY = $Token
try {
    foreach ($t in $targets) {
        $exportDir = Join-Path $repoRoot ("migrations/outputs/{0}/export" -f $t.Folder)
        if (-not (Test-Path $exportDir)) {
            Write-Host "  skip  $($t.Label): no export under outputs/$($t.Folder)" -ForegroundColor DarkYellow
            $results += [pscustomobject]@{ Label = $t.Label; Code = 2 }
            continue
        }

        Write-Host ''
        Write-Host "====> $($t.Label)  [outputs/$($t.Folder)]" -ForegroundColor White
        python (Join-Path $scriptDir 'livecheck.py') --base-id $t.BaseId --export-dir $exportDir
        $results += [pscustomobject]@{ Label = $t.Label; Code = $LASTEXITCODE }
    }
}
finally {
    Remove-Item Env:\AIRTABLE_API_KEY -ErrorAction SilentlyContinue
}

Write-Host ''
Write-Host '-- check_live summary -------------------------------------------------' -ForegroundColor Cyan
foreach ($r in $results) {
    if ($r.Code -eq 0) { $mark = 'ok  '; $colour = 'Green' }
    elseif ($r.Code -eq 2) { $mark = 'skip'; $colour = 'DarkYellow' }
    else { $mark = 'STALE'; $colour = 'Red' }
    Write-Host ("  {0}  {1}" -f $mark, $r.Label) -ForegroundColor $colour
}

if (@($results | Where-Object { $_.Code -eq 1 }).Count -gt 0) {
    Write-Host ''
    Write-Host 'check_live: at least one export is stale. Run Migrate all for that env to re-fetch.' -ForegroundColor Red
    exit 1
}
Write-Host 'check_live: every export matches live Airtable.' -ForegroundColor Green
