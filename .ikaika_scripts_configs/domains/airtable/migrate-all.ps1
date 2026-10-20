# Pulls every Airtable base of an environment into SQL: fetch, attachments, data.sql, checks and schema.sql.
#
# A forwarder over migrations/migrate-all.ps1, which still runs on its own and touches no database: everything lands in
# migrations/outputs/<folder>/, which holds real people's data and stays on this PC. -Step resumes a pull part-way. The
# token is read from migrations/.token.private.txt or IP_AIRTABLE_TOKEN; unattended with neither, it stops.
#
#     .\script.ps1 airtable/migrate-all                       the test bases
#     .\script.ps1 airtable/migrate-all -Env prod -Step generate
param(
    # The bases to pull: the sandbox ones, or the live ones.
    [ValidateSet('test', 'prod')]
    [string]$Env = 'test',
    # Where to start: the whole pull, or one part of it again.
    [ValidateSet('all', 'fetch', 'upload', 'generate')]
    [string]$Step = 'all',
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  airtable/migrate-all - every Airtable base of an environment, into SQL.

  Usage
    .\script.ps1 airtable/migrate-all [-Env test|prod] [-Step all|fetch|upload|generate]

  Runs migrations\migrate-all.ps1. Writes migrations\outputs\ only; no
  database is touched.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
Invoke-Forwarded 'migrations\migrate-all.ps1' (@{ Env = $Env; Step = $Step } + (Get-AirtableTokenArguments))
