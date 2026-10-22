# Pushes only APP_ENV and AIRTABLE_* keys from a production env file to production, then runs the
# server's apply-env.sh. The values never leave the file or reach the console; only key names print.
#
#   .\deployment\push-prod-airtable.ps1 -EnvFile C:\IKAIKA\ikaika_admin\.env.production
#   .\deployment\push-prod-airtable.ps1 -EnvFile ... -CheckOnly
#
# The file must be production's own, not the repo's local .env: the local one holds TEST Airtable ids,
# a TEST token and local database settings.
param(
    [Parameter(Mandatory)]
    [string]$EnvFile,
    [string]$SshHost = 'bluehost',
    [switch]$CheckOnly
)

$ErrorActionPreference = 'Stop'

$localEnv = (Resolve-Path (Join-Path (Split-Path $PSScriptRoot -Parent) '.env')).Path
$resolved = (Resolve-Path $EnvFile).Path
if ($resolved -eq $localEnv) {
    throw 'Refusing the local .env: it has TEST Airtable ids and local database settings. Use a production-only file.'
}

$lines = Get-Content -LiteralPath $resolved
$values = @{}
foreach ($line in $lines) {
    if ($line -match '^([A-Z0-9_]+)=(.+)$') {
        $values[$Matches[1]] = $Matches[2].Trim()
    }
}

$keys = @($values.Keys | Where-Object { $_ -eq 'APP_ENV' -or $_ -match '^AIRTABLE_' } | Sort-Object)
if ($keys.Count -eq 0) {
    throw 'No APP_ENV or AIRTABLE_* keys found in that file.'
}
foreach ($required in @('APP_ENV', 'AIRTABLE_ENV', 'AIRTABLE_TOKEN', 'AIRTABLE_BASE_ID_PROD')) {
    if (-not $values.ContainsKey($required) -or $values[$required] -eq '') {
        throw "$required is missing or empty in $resolved"
    }
}

if ($values['APP_ENV'] -ne 'production') {
    throw "APP_ENV must be production, not $($values['APP_ENV'])."
}
if ($values['AIRTABLE_ENV'] -ne 'prod') {
    throw "AIRTABLE_ENV must be prod, not $($values['AIRTABLE_ENV'])."
}
if ($values['AIRTABLE_BASE_ID_PROD'] -ne 'app8jGTzRDQt4yPIa') {
    throw "AIRTABLE_BASE_ID_PROD must be the production Users & Projects base app8jGTzRDQt4yPIa."
}
if (-not $values['AIRTABLE_TOKEN'].StartsWith('pat')) {
    throw 'AIRTABLE_TOKEN does not look like an Airtable personal access token.'
}

$testIds = @(
    'app8DvnFZErPZT5Az',
    'appCoObE52yP1guFP',
    'appSNMGACG48i3XFX',
    'appYwnzEVZVXwH1hB'
)
foreach ($key in $keys) {
    foreach ($testId in $testIds) {
        if ($values[$key].Contains($testId)) {
            throw "$key contains a TEST base id. Refusing to send it to production."
        }
    }
}

$prodBaseKeys = @(
    'AIRTABLE_CALENDAR_PROD',
    'AIRTABLE_CLIENT_INFORMATION_PROD',
    'AIRTABLE_TRANSACTION_TRACKER_PROD'
)
$prodIds = @(
    'appxjrrZc99jJlpli',
    'appLBcQ7leQQnJ7Pj',
    'appp5MogesHN2eVvG'
)
foreach ($key in $prodBaseKeys) {
    if ($values.ContainsKey($key) -and $values[$key] -ne '' -and $values[$key] -notin $prodIds) {
        throw "$key must be one of the production base ids, not $($values[$key])."
    }
}
if ($values.ContainsKey('AIRTABLE_SYNC_SCHEDULED') -and $values['AIRTABLE_SYNC_SCHEDULED'] -notin @('true', 'false')) {
    throw 'AIRTABLE_SYNC_SCHEDULED must be true or false.'
}

Write-Host "Production keys: $($keys -join ', ')"
if ($CheckOnly) {
    Write-Host 'Check only; nothing sent.'
    return
}

& (Join-Path $PSScriptRoot 'push-env.ps1') -Prod -EnvFile $resolved -Keys $keys -SshHost $SshHost
