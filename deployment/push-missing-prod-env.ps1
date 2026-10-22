# Pushes the keys you added locally but that production does not have yet. Existing production keys are
# never overwritten, and local-only/runtime keys (APP_KEY, DB_*, APP_ENV, MAIL_MAILER, ...) are skipped.
# Only key names print; values are sent over SSH by push-env.ps1.
#
#   .\deployment\push-missing-prod-env.ps1
#   .\deployment\push-missing-prod-env.ps1 -Yes
param(
    [string]$EnvFile = (Join-Path (Split-Path $PSScriptRoot -Parent) '.env'),
    [string]$SshHost = 'bluehost',
    [switch]$Yes
)

$ErrorActionPreference = 'Stop'

$resolved = (Resolve-Path $EnvFile).Path
$values = @{}
foreach ($line in Get-Content -LiteralPath $resolved) {
    if ($line -match '^([A-Z0-9_]+)=(.+)$') {
        $values[$Matches[1]] = $Matches[2].Trim()
    }
}

# Never copy these: production's own database/app identity and deployment-owned addresses.
$deny = '^(APP_KEY|APP_ENV|APP_DEBUG|APP_URL|ASSET_URL|DB_.*|CORE_DB_.*|PORTAL_DB_.*|TRACKER_DB_.*|CACHE_.*|SESSION_.*|QUEUE_.*|REDIS_.*|BROADCAST_.*|FILESYSTEM_.*|AWS_.*|PUSHER_.*|VITE_.*|MAIL_MAILER|PORTAL_PUBLIC_URL|PORTAL_AZURE_REDIRECT_URI|API_PATH.*|API_CHANNEL|AIRTABLE_.*)$'

$remoteKeys = @(ssh $SshHost "grep -oE '^[A-Za-z0-9_]+=' ~/apps/portal-prod/.env | tr -d '='")
if ($LASTEXITCODE -ne 0) {
    throw 'Could not read the production .env key names.'
}
$remoteKeys = $remoteKeys | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' }

$missing = @($values.Keys |
    Where-Object { $_ -notmatch $deny -and $remoteKeys -notcontains $_ } |
    Sort-Object)

if ($missing.Count -eq 0) {
    Write-Host 'No new non-local keys are missing on production.'
    return
}

Write-Host 'Missing on production (values hidden):'
foreach ($key in $missing) {
    Write-Host "  $key"
}
if (-not $Yes) {
    $answer = Read-Host 'Push these keys to production? (y/N)'
    if ($answer -notmatch '^[yY]') {
        Write-Host 'Nothing sent.'
        return
    }
}

& (Join-Path $PSScriptRoot 'push-env.ps1') -Prod -EnvFile $resolved -Keys $missing -SshHost $SshHost
