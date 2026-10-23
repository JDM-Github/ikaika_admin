# Opens the Ikaika Console: one window of buttons for the Airtable pull, the Test Server and production.
#
# A forwarder over devtools/ikaika-console.ps1, which still opens on its own (devtools/Ikaika Console.bat). Every button
# there runs one script as a child process and streams its output; the same scripts are commands here too - deploy/*
# and airtable/* - for a terminal, a pipeline or an agent. It is a window, so it refuses where none may open.
#
#     .\script.ps1 devtools/console
param(
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  devtools/console - the Ikaika Console window.

  Usage
    .\script.ps1 devtools/console

  Opens devtools\ikaika-console.ps1. Without a window, use the same scripts as
  deploy/* and airtable/* commands.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
if (Test-WindowRefused) {
    Write-Host "The console is a window, and $(Get-ProjectEnvVar 'SCRIPTS_NO_GUI') or CI says none may open: use the deploy/* and airtable/* commands." -ForegroundColor Red
    exit 1
}
Invoke-Forwarded 'devtools\ikaika-console.ps1'
