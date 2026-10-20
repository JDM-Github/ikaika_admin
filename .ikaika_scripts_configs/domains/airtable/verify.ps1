# Checks every generated data.sql against the Airtable export it came from.
#
# A forwarder over migrations/verify_migrations.ps1, which still runs on its own: one coverage report per base, a
# summary, and exit 1 if any base fails. It proves data.sql reproduces the export, not that the export is current -
# airtable/check-live answers that.
#
#     .\script.ps1 airtable/verify                     the test outputs
#     .\script.ps1 airtable/verify -Env prod -Detailed
param(
    # Which set of outputs to check.
    [ValidateSet('test', 'prod')]
    [string]$Env = 'test',
    # Every table's counts, not only the summary.
    [switch]$Detailed,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  airtable/verify - each generated data.sql, against its Airtable export.

  Usage
    .\script.ps1 airtable/verify [-Env test|prod] [-Detailed]

  Runs migrations\verify_migrations.ps1, which exits 1 if any base fails.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
Invoke-Forwarded 'migrations\verify_migrations.ps1' @{ Env = $Env; Detailed = $Detailed }
