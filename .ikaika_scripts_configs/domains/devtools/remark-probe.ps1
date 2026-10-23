# Asks an Ollama model to format a remark and checks the answer against the shape the portal stores.
#
# A forwarder over devtools/remark_formatter_probe.py, which still runs on its own (python, standard library only). It
# reads OLLAMA_API_KEY from the environment or this repo's .env. -SelfTest checks the checker with no model at all.
#
#     .\script.ps1 devtools/remark-probe                       the built-in samples
#     .\script.ps1 devtools/remark-probe -Remark "fixed the export, 2h"
#     .\script.ps1 devtools/remark-probe -SelfTest
param(
    # Your own remark to format; the built-in samples when left out.
    [string]$Remark,
    # Check the checker only; no model is asked.
    [switch]$SelfTest,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  devtools/remark-probe - an Ollama model, on the remark formatter.

  Usage
    .\script.ps1 devtools/remark-probe [-Remark <text>] [-SelfTest]

  Runs devtools\remark_formatter_probe.py with the default model and host.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
$python = @('python', 'py', 'python3') | Where-Object { Get-Command $_ -ErrorAction SilentlyContinue } | Select-Object -First 1
if (-not $python) {
    Write-Host 'No python on PATH.' -ForegroundColor Red
    exit 1
}
$probeArgs = @(Get-ForwardPath 'devtools\remark_formatter_probe.py')
if ($SelfTest) { $probeArgs += '--selftest' }
if ($Remark) { $probeArgs += @('--remark', $Remark) }
& $python @probeArgs
exit $LASTEXITCODE

# A remark is the person's own words; nothing could list them.
function Get-DialogFreeText { return , @('Remark') }
