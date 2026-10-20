# Builds and deploys the admin API alone to the Test Server.
#
# A forwarder over deployment/deploy.ps1, which still runs on its own: build.ps1 makes deployment/out/central-platform.zip,
# then server/deploy-admin.sh installs it - migrations, tracker patches, the scheduler's cron line and a health check.
# Unattended, SSH must already answer without a passphrase.
#
#     .\script.ps1 deploy/admin              build and deploy
#     .\script.ps1 deploy/admin -SkipBuild   the last build again
param(
    # Deploy the last build instead of building again.
    [switch]$SkipBuild,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/admin - the admin API alone, to the Test Server.

  Usage
    .\script.ps1 deploy/admin [-SkipBuild]

  Runs deployment\deploy.ps1: builds the release zip unless -SkipBuild, then
  installs it with server\deploy-admin.sh, which migrates and checks health.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
Assert-SshReady
Invoke-Forwarded 'deployment\deploy.ps1' @{ SkipBuild = $SkipBuild }
