<#
.SYNOPSIS
  Adopts a generated data.sql into the committed sql/ folder, and records which
  environment that snapshot came from.

.DESCRIPTION
  The generator writes data.sql into migrations/outputs/<folder>/, which is
  gitignored. This copies each product's generated data.sql to sql/<product>/data.sql
  so the committed "example" snapshot matches the latest pull.

  Two things it does to the file on the way through:
    - strips the portal-only DDL migrate.ps1 appends to the end ("(after the data)",
      i.e. separate.sql), because sql/portal/separate.sql is loaded on its own by
      reset.ps1 -- keeping it in both places would double it;
    - rewrites the `USE` back to the product's local database name, so a prod build
      never leaves a production database name in the committed folder.

  Only data.sql is touched. sql/<product>/schema.sql stays the hand-edited source
  the generator reads from.

  Writes sql/status.txt: which env the committed snapshot now holds, and when.

.PARAMETER Env
  test | prod -- which outputs to adopt.

.EXAMPLE
  .\copy-to-sql.ps1 -Env test
  .\copy-to-sql.ps1 -Env prod
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateSet('test', 'prod')]
    [string]$Env
)

$ErrorActionPreference = 'Stop'
$scriptDir = $PSScriptRoot
$repoRoot = Split-Path -Parent $scriptDir

. (Join-Path $scriptDir 'lib/targets.ps1')

function Stop-WithMessage {
    param([string]$Message, [int]$Code = 1)
    Write-Host ''
    Write-Host $Message -ForegroundColor Red
    exit $Code
}

# Cuts everything from the migrate.ps1 after-data block onward.
function Remove-AfterData {
    param([string]$Text)
    $m = [regex]::Match($Text, "(?m)^-- -+\r?\n-- .*\(after the data\).*$")
    if ($m.Success) { return $Text.Substring(0, $m.Index) }
    return $Text
}

# Points the file's USE and its header note at the local database name.
function Set-DevDatabase {
    param([string]$Text, [string]$DevDb)
    $Text = [regex]::Replace($Text, '(?im)^(\s*USE\s+)(`?\w+`?)', '${1}' + $DevDb)
    $Text = [regex]::Replace($Text, '(?i)(created the ``)(\w+)(`` database)', '${1}' + $DevDb + '${3}')
    return $Text
}

$targets = Get-MigrationTargets -Env $Env

# Fail before touching anything if a base has not been generated yet.
$missing = @()
foreach ($t in $targets) {
    $src = Join-Path $repoRoot ("migrations/outputs/{0}/data.sql" -f $t.Folder)
    if (-not (Test-Path $src)) { $missing += "$($t.Label) (outputs/$($t.Folder)/data.sql)" }
}
if ($missing.Count -gt 0) {
    Stop-WithMessage ("Nothing copied. Missing generated output for:`n  " + ($missing -join "`n  ") + "`nRun: .\migrate-all.ps1 -Env $Env")
}

$stamp = Get-Date -Format 'yyyy-MM-ddTHH:mm:sszzz'
$statusLines = @("env=$Env", "copied=$stamp")

Write-Host ''
Write-Host "copy-to-sql: env '$Env'" -ForegroundColor Cyan

foreach ($t in $targets) {
    $src = Join-Path $repoRoot ("migrations/outputs/{0}/data.sql" -f $t.Folder)
    $dstDir = Join-Path $repoRoot $t.SqlDir
    $dst = Join-Path $dstDir 'data.sql'

    $raw = [IO.File]::ReadAllText($src)
    $clean = Set-DevDatabase -Text (Remove-AfterData -Text $raw) -DevDb $t.DevDb
    $clean = $clean.TrimEnd() + "`n"

    [IO.File]::WriteAllText($dst, $clean, (New-Object System.Text.UTF8Encoding($false)))
    Write-Host "  $($t.Label): outputs/$($t.Folder)/data.sql -> $($t.SqlDir)/data.sql" -ForegroundColor Green

    $statusLines += ("{0}={1}" -f $t.Product, $t.Folder)
}

$statusPath = Join-Path $repoRoot 'sql/status.txt'
[IO.File]::WriteAllText($statusPath, (($statusLines -join "`n") + "`n"), (New-Object System.Text.UTF8Encoding($false)))
Write-Host ''
Write-Host "Wrote $statusPath (env=$Env)." -ForegroundColor Green
if ($Env -eq 'prod') {
    Write-Host 'sql/ now holds PRODUCTION data. reset.ps1 will load it into the local databases.' -ForegroundColor Yellow
}
