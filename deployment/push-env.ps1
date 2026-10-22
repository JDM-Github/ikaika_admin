# Copies the server's secret settings from this repo's local .env to a target's .env and applies them;
# see deployment/README.md. Values travel over SSH only and are never printed; a key empty here is skipped, so it
# never blanks the server's. MAIL_MAILER is left out on purpose: which mailer a server uses is its own choice.
# -RemoteDir points it at another target, e.g. production's ~/apps/portal-prod. -EnvFile reads another file than .env,
# for a target whose values differ: Jira's project is a sandbox on the Test Server and the real one on production, so
# production's JIRA_* come from their own file (see deployment/README.md, Jira).
param(
    [string[]]$Keys = @(
        'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET',
        'BITBUCKET_TOKEN', 'BITBUCKET_WEBHOOK_SECRET',
        'JIRA_BASE_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN', 'JIRA_PROJECT_KEY', 'JIRA_CLOUD_ID', 'JIRA_WEBHOOK_SECRET',
        'JIRA_ISSUE_TYPE_BUG', 'JIRA_ISSUE_TYPE_FEATURE', 'JIRA_ISSUE_TYPE_FEEDBACK',
        'MAIL_RELAY_URL', 'MAIL_RELAY_KEY',
        'OLLAMA_API_KEY',
        'PORTAL_ID_LOGIN_PASSWORD',
        # Test Server keeps these TEST ids; -Prod still needs -Keys, so this default never reaches production.
        'AIRTABLE_ENV', 'AIRTABLE_TOKEN', 'AIRTABLE_BASE_ID',
        'AIRTABLE_TRANSACTION_TRACKER', 'AIRTABLE_CALENDAR', 'AIRTABLE_CLIENT_INFORMATION',
        'AIRTABLE_SYNC_SCHEDULED'
    ),
    [string]$SshHost = 'bluehost',
    # The target's home-relative work folder (its .env and scripts/). Test Server by default.
    [string]$RemoteDir = '~/apps/test-server',
    # The settings file to read; this repo's .env when left out.
    [string]$EnvFile = '',
    # Production (~/apps/portal-prod). Needs -Keys: the default list carries the Test Server's Jira sandbox.
    [switch]$Prod
)

$ErrorActionPreference = 'Stop'
if ($Prod) {
    if (-not $PSBoundParameters.ContainsKey('Keys')) { throw '-Prod needs -Keys, e.g. -Keys JIRA_ISSUE_TYPE_FEATURE' }
    $RemoteDir = '~/apps/portal-prod'
}
$remote  = $RemoteDir
$envFile = if ($EnvFile) { $EnvFile } else { Join-Path (Split-Path $PSScriptRoot -Parent) '.env' }
if (-not (Test-Path $envFile)) { throw "No .env at $envFile" }

$lines   = @()
$skipped = @()
foreach ($key in $Keys) {
    $line = Get-Content $envFile | Where-Object { $_ -match "^$key=." } | Select-Object -Last 1
    if ($null -eq $line) { $skipped += $key } else { $lines += $line }
}
if ($skipped.Count -gt 0) { Write-Host "Empty or missing here, left as they are on the server: $($skipped -join ', ')" }
if ($lines.Count -eq 0) { Write-Host 'Nothing to send.'; return }

# The scripts travel first, so a settings push works even before the next deploy brings them.
$scripts = (Join-Path $PSScriptRoot 'server/merge-env.sh'), (Join-Path $PSScriptRoot 'server/apply-env.sh')
scp @scripts "${SshHost}:$remote/upload/"
if ($LASTEXITCODE -ne 0) { throw 'Upload failed' }

# ROOT is what merge-env.sh writes into and applies; without it, it defaults to the Test Server.
$lines | ssh $SshHost "mkdir -p $remote/scripts && mv -f $remote/upload/merge-env.sh $remote/upload/apply-env.sh $remote/scripts/ && sed -i 's/\r$//' $remote/scripts/*.sh && ROOT=$remote bash $remote/scripts/merge-env.sh"
if ($LASTEXITCODE -ne 0) { throw 'Applying the settings failed on the server; see the output above' }
