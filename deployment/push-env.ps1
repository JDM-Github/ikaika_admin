# Copies the Test Server's secret settings from this repo's local .env to ~/apps/test-server/.env and applies them;
# see deployment/README.md. Values travel over SSH only and are never printed; a key empty here is skipped, so it
# never blanks the server's. MAIL_MAILER is left out on purpose: which mailer a server uses is its own choice.
param(
    [string[]]$Keys = @(
        'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET',
        'BITBUCKET_TOKEN', 'BITBUCKET_WEBHOOK_SECRET',
        'MAIL_RELAY_URL', 'MAIL_RELAY_KEY'
    ),
    [string]$SshHost = 'bluehost'
)

$ErrorActionPreference = 'Stop'
$remote  = '~/apps/test-server'
$envFile = Join-Path (Split-Path $PSScriptRoot -Parent) '.env'
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

$lines | ssh $SshHost "mkdir -p $remote/scripts && mv -f $remote/upload/merge-env.sh $remote/upload/apply-env.sh $remote/scripts/ && sed -i 's/\r$//' $remote/scripts/*.sh && bash $remote/scripts/merge-env.sh"
if ($LASTEXITCODE -ne 0) { throw 'Applying the settings failed on the server; see the output above' }
