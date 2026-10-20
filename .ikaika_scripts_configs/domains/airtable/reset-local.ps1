# Drops and recreates this PC's portal, estimator and tracker databases, and loads them from sql/.
#
# A forwarder over migrations/reset.ps1, which still runs on its own and reads the connection from this repo's .env.
# It asks for yes here unless -Yes - and says so when sql/status.txt marks the snapshot as production data - then hands
# -Force on, so reset.ps1 does not ask a second time.
#
#     .\script.ps1 airtable/reset-local          after yes
#     .\script.ps1 airtable/reset-local -Yes     unasked
param(
    # Skip the question. For a scripted run, where a question is a hang.
    [switch]$Yes,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  airtable/reset-local - this PC's databases, dropped and loaded from sql\.

  Usage
    .\script.ps1 airtable/reset-local [-Yes]

  Runs migrations\reset.ps1 -Force after asking for yes, unless -Yes.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
$status = Get-ForwardPath 'sql\status.txt'
$snapshot = if (Test-Path -LiteralPath $status) { "$(Get-Content -LiteralPath $status | Where-Object { $_ -match '^env=' } | Select-Object -First 1)".Split('=', 2)[-1].Trim() } else { '' }
$warning = if ($snapshot -eq 'prod') { ' sql\ holds PRODUCTION data: your local data becomes live records.' } else { '' }
Confirm-Deliberate -Word 'yes' -Question "This DROPS and recreates your local portal, estimator and tracker databases and reloads them from sql\.$warning" -Yes:$Yes
Invoke-Forwarded 'migrations\reset.ps1' @{ Force = $true }
