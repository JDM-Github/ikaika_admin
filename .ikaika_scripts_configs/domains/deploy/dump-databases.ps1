# Dumps this PC's databases to deployment/out/db, one .sql each, ready for the Test Server.
#
# A forwarder over deployment/dump-local-databases.ps1, which still runs on its own: it reads the database names from
# this repo's .env and rewrites MySQL 8's utf8mb4_0900_ai_ci collation to one the server's MariaDB knows. The dumps
# hold real people's data, so they stay on this PC and never go in a shared folder.
#
#     .\script.ps1 deploy/dump-databases                  all four
#     .\script.ps1 deploy/dump-databases -Only estimator  one of them
param(
    # The databases to dump; all four when left out.
    [ValidateSet('core', 'portal', 'tracker', 'estimator')]
    [string[]]$Only,
    # The mysqldump to use; the one WampServer serves when left out.
    [string]$MysqldumpPath,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/dump-databases - this PC's databases, to deployment\out\db.

  Usage
    .\script.ps1 deploy/dump-databases [-Only core,portal,tracker,estimator] [-MysqldumpPath <mysqldump.exe>]

  Runs deployment\dump-local-databases.ps1. The dumps hold personal data:
  they are for the Test Server, never for a shared folder.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
$arguments = @{}
if ($Only) { $arguments.Only = $Only }
if ($MysqldumpPath) { $arguments.MysqldumpPath = $MysqldumpPath }
Invoke-Forwarded 'deployment\dump-local-databases.ps1' $arguments
