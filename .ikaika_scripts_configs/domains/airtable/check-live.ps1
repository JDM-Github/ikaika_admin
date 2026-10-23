# Checks that each base's export still matches live Airtable, by record counts.
#
# A forwarder over migrations/check_live.ps1, which still runs on its own: a table with more records live than in the
# export means the fetch missed some, so pull again before generating or importing. It reads one field per record. The
# token is read from migrations/.token.private.txt or IP_AIRTABLE_TOKEN; unattended with neither, it stops.
#
#     .\script.ps1 airtable/check-live              the test bases
#     .\script.ps1 airtable/check-live -Env prod
param(
    # The bases to count.
    [ValidateSet('test', 'prod')]
    [string]$Env = 'test',
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  airtable/check-live - each export's record counts, against live Airtable.

  Usage
    .\script.ps1 airtable/check-live [-Env test|prod]

  Runs migrations\check_live.ps1. A higher live count means the export is
  stale: pull again with airtable/migrate-all.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
Invoke-Forwarded 'migrations\check_live.ps1' (@{ Env = $Env } + (Get-AirtableTokenArguments))
