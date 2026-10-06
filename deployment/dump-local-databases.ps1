# Dumps the local core, portal, tracker and estimator databases to deployment/out/db for the Test Server; see deployment/README.md.
param(
    [string]$MysqldumpPath,
    # Dump only these, e.g. -Only estimator; all four by default.
    [string[]]$Only
)

$ErrorActionPreference = 'Stop'
$root    = Split-Path $PSScriptRoot -Parent
$envFile = Join-Path $root '.env'
$out     = Join-Path $PSScriptRoot 'out/db'

function Read-EnvValue([string]$Key) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^\s*$Key\s*=" } | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line -split '=', 2)[1].Trim().Trim('"').Trim("'")
}

# Same fallback order as config/products.php, so the dump is the data the app actually reads.
function First([string[]]$Keys, [string]$Default) {
    foreach ($key in $Keys) { $value = Read-EnvValue $key; if ($value) { return $value } }
    return $Default
}

if (-not $MysqldumpPath) {
    $found = Get-Command mysqldump -ErrorAction SilentlyContinue
    $MysqldumpPath = if ($found) { $found.Source } else { 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe' }
}
if (-not (Test-Path $MysqldumpPath)) { throw "mysqldump not found. Pass -MysqldumpPath." }

$dbHost = First @('DB_HOST') '127.0.0.1'
$port   = First @('DB_PORT') '3306'
$user   = First @('DB_USERNAME') 'root'
$pass   = First @('DB_PASSWORD') ''

$databases = [ordered]@{
    core      = First @('CORE_DB_DATABASE', 'DB_DATABASE') 'ikaika_platform'
    portal    = First @('PORTAL_DB_DATABASE') 'test_portal_database'
    tracker   = First @('TRACKER_DB_DATABASE') 'test_tracker_database'
    estimator = First @('ESTIMATOR_DB_DATABASE') 'test_estimator_database'
}

New-Item -ItemType Directory -Force $out | Out-Null
$env:MYSQL_PWD = $pass
try {
    foreach ($name in @($databases.Keys)) {
        if ($Only -and $Only -notcontains $name) { continue }
        $db   = $databases[$name]
        $file = Join-Path $out "$name.sql"
        Write-Host "== $name ($db) -> $file"
        # One database without --databases: no CREATE DATABASE or USE, so it loads into whatever name Bluehost gave it.
        & $MysqldumpPath -h $dbHost -P $port -u $user --single-transaction --no-tablespaces --set-gtid-purged=OFF `
            --default-character-set=utf8mb4 --result-file=$file $db
        if ($LASTEXITCODE -ne 0) { throw "mysqldump failed for $db" }

        # Bluehost may run MariaDB, which lacks MySQL 8's default collation.
        $text = [IO.File]::ReadAllText($file).Replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci')
        [IO.File]::WriteAllText($file, $text)
    }
}
finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}

Write-Host "Ready: $out"
