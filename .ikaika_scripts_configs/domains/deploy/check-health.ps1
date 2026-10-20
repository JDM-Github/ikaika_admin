# Checks a server's API: no migration left pending, and its health URL answering.
#
# A forwarder over deployment/check-health.ps1, which still runs on its own and exits 1 on either failure, so a
# workflow can stop on it. Unattended, SSH must already answer without a passphrase.
#
#     .\script.ps1 deploy/check-health                       the Test Server
#     .\script.ps1 deploy/check-health -Target production    production
param(
    # The server to check.
    [ValidateSet('staging', 'production')]
    [string]$Target = 'staging',
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/check-health - pending migrations and the health URL, on a server.

  Usage
    .\script.ps1 deploy/check-health [-Target staging|production]

  Runs deployment\check-health.ps1, which exits 1 when a migration is pending
  or the health URL does not answer.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
Assert-SshReady
Invoke-Forwarded 'deployment\check-health.ps1' @{ Target = $(if ($Target -eq 'production') { 'prod' } else { 'test' }) }
