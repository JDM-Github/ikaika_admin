# Replaces production's portal, estimator and tracker databases with the Airtable export.
#
# A forwarder over deployment/import-airtable-to-prod.ps1, which still runs on its own: it generates the SQL from
# migrations/outputs (unless -SkipGenerate), uploads it, and the server backs each database up, loads it and migrates.
# The data is the AIRTABLE EXPORT, not this PC's databases. It asks for PROD here unless -Yes.
#
#     .\script.ps1 deploy/import-airtable                  generate, then load, after PROD
#     .\script.ps1 deploy/import-airtable -SkipGenerate    load what was generated last
param(
    # Load the SQL generated last time instead of generating it again.
    [switch]$SkipGenerate,
    # Skip the question. For a scripted run, where a question is a hang.
    [switch]$Yes,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/import-airtable - the Airtable export, into production's databases.

  Usage
    .\script.ps1 deploy/import-airtable [-SkipGenerate] [-Yes]

  Runs deployment\import-airtable-to-prod.ps1. Each database is backed up on
  the server first, then dropped, recreated and migrated. Asks for PROD
  unless -Yes.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
Confirm-Deliberate -Word 'PROD' -Question 'PRODUCTION. This DROPS and RECREATES the live portal, estimator and tracker databases from the Airtable export in migrations/outputs, not from your local databases. Each is backed up first.' -Yes:$Yes
Assert-SshReady
Invoke-Forwarded 'deployment\import-airtable-to-prod.ps1' @{ SkipGenerate = $SkipGenerate; Yes = $true }
