# Deploys the secret settings, the admin API and the portal to the Test Server from this PC.
#
# A forwarder over deployment/deploy-all.ps1, which still runs on its own: the settings (deploy/push-env), the API
# (deploy/admin) and then the portal frontend's own deployment/deploy.ps1, the API always first. -ResetDatabases first
# replaces every Test Server database with this PC's (deploy/copy-databases -Wipe), and asks for RESET unless -Yes.
# The portal is the checkout beside this one. Unattended, SSH must already answer without a passphrase.
# -Bump (2026-10-21, user): same, the default, installs both checkouts untagged, as before; keep, patch, minor or major release
# both first - each bumped, built, tagged v<version> on Bitbucket and installed through its own deploy-release.
#
#     .\script.ps1 deploy/test-server                          settings, API and portal
#     .\script.ps1 deploy/test-server -Bump keep               both released as the next build number, then deployed
#     .\script.ps1 deploy/test-server -SkipBuild               the last builds again
#     .\script.ps1 deploy/test-server -ResetDatabases -Yes     with this PC's databases, unasked
param(
    # same installs both checkouts untagged; keep (the next build number only), patch, minor or major release both first.
    [ArgumentCompletions('same', 'keep', 'patch', 'minor', 'major')]
    [string]$Bump = 'same',
    # Leave the server's secret settings as they are.
    [switch]$SkipSettings,
    # Deploy the last builds instead of building again.
    [switch]$SkipBuild,
    # Replace every Test Server database with this PC's first; the old data is backed up on the server.
    [switch]$ResetDatabases,
    # Skip the question -ResetDatabases asks. For a scripted run, where a question is a hang.
    [switch]$Yes,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/test-server - settings, the admin API and the portal, to the Test Server.

  Usage
    .\script.ps1 deploy/test-server [-Bump same|keep|patch|minor|major] [-SkipSettings] [-SkipBuild] [-ResetDatabases [-Yes]]

  Runs deployment\deploy-all.ps1. -ResetDatabases replaces every Test Server
  database with this PC's first, backed up on the server, and asks for RESET
  unless -Yes. The portal deployed is the checkout beside this one. -Bump
  other than same releases both first: each bumped, tagged v<version> on
  Bitbucket and installed through its deploy-release.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
if ($ResetDatabases) {
    Confirm-Deliberate -Word 'RESET' -Question 'This replaces EVERY Test Server database with your local ones (the old data is backed up on the server).' -Yes:$Yes
}
Assert-SshReady
Invoke-Forwarded 'deployment\deploy-all.ps1' @{
    SkipSettings   = $SkipSettings
    SkipBuild      = $SkipBuild
    ResetDatabases = $ResetDatabases
    Yes            = $true
    Bump           = $Bump
    PortalRepo     = (Get-PortalRepo)
}
