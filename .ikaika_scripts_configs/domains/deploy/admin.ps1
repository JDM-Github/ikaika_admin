# Builds and deploys the admin API alone to the Test Server.
#
# A forwarder over deployment/deploy.ps1, which still runs on its own: build.ps1 makes deployment/out/central-platform.zip,
# then server/deploy-admin.sh installs it - migrations, tracker patches, the scheduler's cron line and a health check.
# Unattended, SSH must already answer without a passphrase.
#
# -Bump (2026-10-21, user): same, the default, installs this checkout untagged, as before; keep, patch, minor or major release
# it first - bumped, built, tagged v<version> on Bitbucket and installed by build-release -Debug -Bump <b> -Tag -Deploy.
#
#     .\script.ps1 deploy/admin              build and deploy
#     .\script.ps1 deploy/admin -Bump keep   released as the next build number, tagged, then deployed
#     .\script.ps1 deploy/admin -SkipBuild   the last build again
param(
    # same installs this checkout untagged; keep (the next build number only), patch, minor or major release it first.
    [ArgumentCompletions('same', 'keep', 'patch', 'minor', 'major')]
    [string]$Bump = 'same',
    # Deploy the last build instead of building again.
    [switch]$SkipBuild,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/admin - the admin API alone, to the Test Server.

  Usage
    .\script.ps1 deploy/admin [-Bump same|keep|patch|minor|major] [-SkipBuild]

  Runs deployment\deploy.ps1: builds the release zip unless -SkipBuild, then
  installs it with server\deploy-admin.sh, which migrates and checks health.
  -Bump other than same releases it first: bumped, tagged v<version> on
  Bitbucket, then installed through build-release -Deploy.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
if (-not $Bump -or $Bump -eq 'same') { Assert-SshReady }
Invoke-Forwarded 'deployment\deploy.ps1' @{ SkipBuild = $SkipBuild; Bump = $Bump }
