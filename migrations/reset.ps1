<#
.SYNOPSIS
  Resets every local database from the committed sql/ folder.

.DESCRIPTION
  Runs, in order:
    1. sql/core/schema.sql            creates ikaika_platform if missing (no drop)
    2. sql/portal/schema.sql          DROPS and recreates test_portal_database
       sql/portal/indexes.sql         index retrofits (duplicates ignored)
       sql/portal/data.sql            the committed Airtable snapshot
       sql/portal/separate.sql        portal-native tables, portal-only columns, grants
    3. sql/project_estimator/schema.sql, indexes.sql, data.sql
    4. sql/transaction_tracker/schema.sql, indexes.sql, data.sql
    5. deletes product='portal' rows from the core actions/recycle tables, because
       they describe rows this reset just dropped

  SQL files only -- it does not run php artisan migrate. The database names and
  connection details come from ikaika_admin\.env, with the same fallbacks
  config/products.php uses, so it resets exactly the databases the app reads.

  sql/status.txt (written by copy-to-sql.ps1) says whether sql/ currently holds
  test or prod data; if it says prod, that is called out before anything runs.

.PARAMETER Force
  Skip the confirmation prompt.

.PARAMETER DbHost / -DbPort / -DbUser / -DbPassword
  Override the connection details read from .env.

.PARAMETER MysqlPath
  Path to mysql.exe. Defaults to PATH, then the standard MySQL 8.0 install.

.EXAMPLE
  .\reset.ps1
  .\reset.ps1 -Force
#>

[CmdletBinding()]
param(
    [string]$DbHost,
    [int]$DbPort,
    [string]$DbUser,
    [string]$DbPassword,
    [string]$MysqlPath,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'
$scriptDir = $PSScriptRoot
$repoRoot = Split-Path -Parent $scriptDir
$envFile = Join-Path $repoRoot '.env'

function Read-EnvValue {
    param([string]$Key)
    if (-not (Test-Path $envFile)) { return $null }
    $line = Get-Content $envFile | Where-Object { $_ -match "^\s*$Key\s*=" } | Select-Object -First 1
    if (-not $line) { return $null }
    return (($line -split '=', 2)[1]).Trim().Trim('"').Trim("'")
}

# Same PORTAL_DB_* -> DB_* -> default fallback todo the app uses.
if (-not $DbHost)     { $DbHost     = Read-EnvValue 'PORTAL_DB_HOST' }
if (-not $DbHost)     { $DbHost     = Read-EnvValue 'DB_HOST' }
if (-not $DbHost)     { $DbHost     = '127.0.0.1' }
if (-not $DbPort)     { $DbPort     = Read-EnvValue 'PORTAL_DB_PORT' }
if (-not $DbPort)     { $DbPort     = Read-EnvValue 'DB_PORT' }
if (-not $DbPort)     { $DbPort     = 3306 }
if (-not $DbUser)     { $DbUser     = Read-EnvValue 'PORTAL_DB_USERNAME' }
if (-not $DbUser)     { $DbUser     = Read-EnvValue 'DB_USERNAME' }
if (-not $DbUser)     { $DbUser     = 'root' }
if (-not $DbPassword) { $DbPassword = Read-EnvValue 'PORTAL_DB_PASSWORD' }
if (-not $DbPassword) { $DbPassword = Read-EnvValue 'DB_PASSWORD' }

$coreDb      = Read-EnvValue 'CORE_DB_DATABASE'
if (-not $coreDb) { $coreDb = Read-EnvValue 'DB_DATABASE' }
if (-not $coreDb) { $coreDb = 'ikaika_platform' }
$portalDb    = Read-EnvValue 'PORTAL_DB_DATABASE';    if (-not $portalDb)    { $portalDb    = 'test_portal_database' }
$estimatorDb = Read-EnvValue 'ESTIMATOR_DB_DATABASE'; if (-not $estimatorDb) { $estimatorDb = 'test_estimator_database' }
$trackerDb   = Read-EnvValue 'TRACKER_DB_DATABASE';   if (-not $trackerDb)   { $trackerDb   = 'test_tracker_database' }

foreach ($name in @($coreDb, $portalDb, $estimatorDb, $trackerDb)) {
    if ($name -notmatch '^[A-Za-z0-9_]+$') {
        Write-Host "Database name '$name' is not a plain identifier." -ForegroundColor Red
        exit 1
    }
}

if (-not $MysqlPath) {
    $found = Get-Command mysql -ErrorAction SilentlyContinue
    if ($found) { $MysqlPath = $found.Source }
    else {
        $fallback = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe'
        if (Test-Path $fallback) { $MysqlPath = $fallback }
    }
}
if (-not $MysqlPath -or -not (Test-Path $MysqlPath)) {
    Write-Host 'mysql.exe not found. Pass -MysqlPath, or add MySQL''s bin directory to PATH.' -ForegroundColor Red
    exit 1
}

# Which snapshot sql/ holds, if copy-to-sql has run.
$statusPath = Join-Path $repoRoot 'sql/status.txt'
$sqlEnv = $null
if (Test-Path $statusPath) {
    $statusLine = (Get-Content $statusPath | Where-Object { $_ -match '^env=' } | Select-Object -First 1)
    if ($statusLine) { $sqlEnv = $statusLine.Split('=', 2)[1].Trim() }
}

if (-not $Force) {
    Write-Host ''
    Write-Host "This DROPS and recreates the local databases on ${DbHost}:${DbPort}:" -ForegroundColor Yellow
    Write-Host "  $portalDb, $estimatorDb, $trackerDb   (and clears product='portal' rows from $coreDb)"
    if ($sqlEnv -eq 'prod') {
        Write-Host ''
        Write-Host 'WARNING: sql/status.txt says the committed snapshot is PRODUCTION data.' -ForegroundColor Red
        Write-Host 'Loading it will replace your local data with live production records.' -ForegroundColor Red
    }
    elseif ($sqlEnv) {
        Write-Host "Snapshot: sql/ holds '$sqlEnv' data." -ForegroundColor DarkGray
    }
    $answer = Read-Host "Type 'yes' to continue"
    if ($answer -ne 'yes') { Write-Host 'Aborted.'; exit 1 }
}

function Invoke-SqlFile {
    param(
        [string]$Path,
        [Parameter(Mandatory)] [string]$Label,
        [switch]$SelectDatabase,
        [string]$Database,
        [string[]]$IgnorableErrors = @(),
        [string]$Sql
    )

    if (-not $Sql -and -not (Test-Path $Path)) {
        Write-Host "$Label -- file not found: $Path" -ForegroundColor Red
        exit 1
    }

    Write-Host "==> $Label" -ForegroundColor Cyan
    $mysqlArgs = @('-h', $DbHost, '-P', $DbPort, '-u', $DbUser, '--default-character-set=utf8mb4')
    if ($SelectDatabase) { $mysqlArgs += @('-D', $Database) }
    if ($IgnorableErrors.Count -gt 0) { $mysqlArgs += '--force' }

    $env:MYSQL_PWD = $DbPassword
    try {
        $content = if ($Sql) { $Sql } else { Get-Content -Raw -Encoding UTF8 -Path $Path }
        $output = $content | & $MysqlPath @mysqlArgs 2>&1
    }
    finally {
        Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
    }
    $exitCode = $LASTEXITCODE
    $outputText = ($output | Out-String).Trim()

    $errorLines = @($outputText -split "`r?`n" | Where-Object { $_ -match '^ERROR' })
    if ($exitCode -ne 0 -or $errorLines.Count -gt 0) {
        $unexpected = @($errorLines | Where-Object { $line = $_; -not ($IgnorableErrors | Where-Object { $line -match [regex]::Escape($_) }) })
        if ($errorLines.Count -gt 0 -and $unexpected.Count -eq 0) {
            Write-Host '    expected on a fresh install -- continuing' -ForegroundColor DarkYellow
            return
        }
        if ($outputText) { Write-Host $outputText -ForegroundColor Red }
        Write-Host "$Label failed (exit $exitCode). Stopping." -ForegroundColor Red
        exit ([Math]::Max($exitCode, 1))
    }
    Write-Host '    done' -ForegroundColor Green
}

$sql = Join-Path $repoRoot 'sql'
$ignorableIndex = @('Duplicate key name', 'Duplicate column name')

Invoke-SqlFile -Path (Join-Path $sql 'core/schema.sql') -Label "core: schema ($coreDb)"

Invoke-SqlFile -Path (Join-Path $sql 'portal/schema.sql')   -Label "portal: schema ($portalDb)"
Invoke-SqlFile -Path (Join-Path $sql 'portal/indexes.sql')  -Label 'portal: indexes' -SelectDatabase -Database $portalDb -IgnorableErrors $ignorableIndex
Invoke-SqlFile -Path (Join-Path $sql 'portal/data.sql')     -Label 'portal: data'    -SelectDatabase -Database $portalDb
Invoke-SqlFile -Path (Join-Path $sql 'portal/separate.sql') -Label 'portal: separate' -SelectDatabase -Database $portalDb

Invoke-SqlFile -Path (Join-Path $sql 'project_estimator/schema.sql')  -Label "estimator: schema ($estimatorDb)"
Invoke-SqlFile -Path (Join-Path $sql 'project_estimator/indexes.sql') -Label 'estimator: indexes' -SelectDatabase -Database $estimatorDb -IgnorableErrors $ignorableIndex
Invoke-SqlFile -Path (Join-Path $sql 'project_estimator/data.sql')    -Label 'estimator: data'    -SelectDatabase -Database $estimatorDb

Invoke-SqlFile -Path (Join-Path $sql 'transaction_tracker/schema.sql')  -Label "tracker: schema ($trackerDb)"
Invoke-SqlFile -Path (Join-Path $sql 'transaction_tracker/indexes.sql') -Label 'tracker: indexes' -SelectDatabase -Database $trackerDb -IgnorableErrors $ignorableIndex
Invoke-SqlFile -Path (Join-Path $sql 'transaction_tracker/data.sql')    -Label 'tracker: data'    -SelectDatabase -Database $trackerDb

Invoke-SqlFile -Label "core: clear product='portal' rows from $coreDb" -Sql @"
DELETE FROM ``$coreDb``.actions WHERE product = 'portal';
DELETE FROM ``$coreDb``.recycle WHERE product = 'portal';
"@

Write-Host ''
Write-Host "Local databases reset from sql/ ($($sqlEnv ?? 'unknown') data)." -ForegroundColor Green
