# Copies this PC's databases to the Test Server: dumped here, uploaded, imported there.
#
# A forwarder over deployment/copy-databases.ps1, which still runs on its own: dump-local-databases.ps1, then the
# server's create-databases.sh and import-databases.sh, which back every database up first. -Wipe empties them before
# the import, and asks for RESET here unless -Yes - the script itself never asks, only the console did.
#
#     .\script.ps1 deploy/copy-databases                       all four
#     .\script.ps1 deploy/copy-databases -Only portal,core     two of them
#     .\script.ps1 deploy/copy-databases -Wipe -Yes            emptied first, unasked
param(
    # The databases to copy; all four when left out.
    [ValidateSet('core', 'portal', 'tracker', 'estimator')]
    [string[]]$Only,
    # Empty the Test Server's databases before importing, rather than importing over them.
    [switch]$Wipe,
    # Skip the question -Wipe asks. For a scripted run, where a question is a hang.
    [switch]$Yes,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/copy-databases - this PC's databases, to the Test Server.

  Usage
    .\script.ps1 deploy/copy-databases [-Only core,portal,tracker,estimator] [-Wipe [-Yes]]

  Runs deployment\copy-databases.ps1. The server backs each database up
  before importing. -Wipe empties them first and asks for RESET unless -Yes.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
if ($Wipe) {
    Confirm-Deliberate -Word 'RESET' -Question "This WIPES $(if ($Only) { 'the ' + ($Only -join ', ') } else { 'every' }) Test Server database and reloads your local ones (the old data is backed up on the server)." -Yes:$Yes
}
$arguments = @{ Wipe = $Wipe }
if ($Only) { $arguments.Only = $Only }
Assert-SshReady
Invoke-Forwarded 'deployment\copy-databases.ps1' $arguments
