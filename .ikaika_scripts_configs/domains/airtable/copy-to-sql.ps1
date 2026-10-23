# Adopts each generated data.sql into the committed sql/ folder, and records which environment it came from.
#
# A forwarder over migrations/copy-to-sql.ps1, which still runs on its own: it strips the portal-only DDL the generator
# appends and writes sql/status.txt, so a later local reset knows whether it is loading test or production data.
#
#     .\script.ps1 airtable/copy-to-sql              the test outputs
#     .\script.ps1 airtable/copy-to-sql -Env prod
param(
    # Which set of outputs to adopt.
    [ValidateSet('test', 'prod')]
    [string]$Env = 'test',
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  airtable/copy-to-sql - the generated data.sql files, into sql\.

  Usage
    .\script.ps1 airtable/copy-to-sql [-Env test|prod]

  Runs migrations\copy-to-sql.ps1 and records the environment in
  sql\status.txt.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
Invoke-Forwarded 'migrations\copy-to-sql.ps1' @{ Env = $Env }
