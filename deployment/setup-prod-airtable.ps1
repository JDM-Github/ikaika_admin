# One-shot production Airtable setup: asks for the PROD PAT, writes a temporary env file, pushes only
# APP_ENV/AIRTABLE_* to ~/apps/portal-prod, applies production's apply-env.sh, then optionally deploys.
# The token is never printed; the temporary file is deleted even on failure.
#
#   .\deployment\setup-prod-airtable.ps1
#   .\deployment\setup-prod-airtable.ps1 -AllBases -Deploy
param(
    [switch]$AllBases,
    # Tracker sync is deliberately off until approved (2026-10-22); -AllBases does not enable it.
    [switch]$Tracker,
    [switch]$Schedule,
    [switch]$Deploy,
    [string]$SshHost = 'bluehost'
)

$ErrorActionPreference = 'Stop'

$token = ''
$localEnv = Join-Path (Split-Path $PSScriptRoot -Parent) '.env'
if (Test-Path -LiteralPath $localEnv) {
    $line = Get-Content -LiteralPath $localEnv | Where-Object { $_ -match '^AIRTABLE_TOKEN_PROD=.' } | Select-Object -Last 1
    if ($null -ne $line) {
        $token = ($line -replace '^AIRTABLE_TOKEN_PROD=', '').Trim()
    }
}
if ($token -eq '') {
    $secure = Read-Host 'Production Airtable PAT (input hidden)' -AsSecureString
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
} else {
    Write-Host 'Using AIRTABLE_TOKEN_PROD from the local .env.'
}
if (-not $token.StartsWith('pat')) {
    throw 'That does not look like an Airtable personal access token.'
}

$envPath = Join-Path $env:TEMP ('prod-airtable-' + [guid]::NewGuid().ToString('N') + '.env')
try {
    $calendar = if ($AllBases) { 'appxjrrZc99jJlpli' } else { '' }
    $clients = if ($AllBases) { 'appLBcQ7leQQnJ7Pj' } else { '' }
    $tracker = if ($Tracker) { 'appp5MogesHN2eVvG' } else { '' }
    $scheduled = if ($Schedule) { 'true' } else { 'false' }

    @(
        'APP_ENV=production',
        'AIRTABLE_ENV=prod',
        "AIRTABLE_TOKEN=$token",
        'AIRTABLE_BASE_ID_PROD=app8jGTzRDQt4yPIa',
        "AIRTABLE_CALENDAR_PROD=$calendar",
        "AIRTABLE_CLIENT_INFORMATION_PROD=$clients",
        "AIRTABLE_TRANSACTION_TRACKER_PROD=$tracker",
        "AIRTABLE_SYNC_SCHEDULED=$scheduled"
    ) | Set-Content -LiteralPath $envPath -Encoding ascii

    & (Join-Path $PSScriptRoot 'push-prod-airtable.ps1') -EnvFile $envPath -SshHost $SshHost

    $answer = if ($Deploy) { 'y' } else { Read-Host 'Deploy the admin and portal code to production now? (y/N)' }
    if ($answer -match '^[yY]') {
        & (Join-Path $PSScriptRoot 'deploy-all-prod.ps1')
    }

    ssh $SshHost "cd ~/apps/portal-prod/app && php artisan config:show app.env && php artisan config:show services.airtable.env && php artisan config:show services.airtable.scheduled"
    Write-Host ''
    Write-Host 'Next, on production:'
    Write-Host '  php artisan portal:airtable-sync --check'
    Write-Host '  php artisan portal:airtable-sync'
    Write-Host '  php artisan portal:airtable-sync --send'
} finally {
    Remove-Item -LiteralPath $envPath -Force -ErrorAction SilentlyContinue
    $token = $null
}
