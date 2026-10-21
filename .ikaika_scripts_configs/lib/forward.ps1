# What the deploy, airtable and devtools commands share (2026-10-20, user: adopt the deployment and dev tools without
# rewriting them). Each of those commands is a thin forwarder over a script that keeps working on its own -
# deployment/*.ps1, migrations/*.ps1, devtools/ - so this holds only what a forwarder adds: the repo's paths, a
# deliberate act asked for where the script would not ask, SSH checked before a script reaches for it, and the
# hand-over itself.
#
# Nobody at the keyboard - <prefix>_SCRIPTS_NO_PROMPT, CI, or input redirected - is never left with a question: a
# deliberate act then needs -Yes, and SSH needs a key the agent already holds, so a run fails at once instead of
# hanging at a prompt nobody will answer. The hand-over is in-process with a hashtable, never pwsh -File, which
# flattens an array argument into one string (docs/pitfalls.md).

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\..\decoupled-script\lib\shared-fetch.ps1')
# What a release would make each repo's version, for the question production asks (2026-10-21, user): Get-ReleasePreview.
foreach ($lib in 'app-version.ps1', 'toolkit-version.ps1') { . (Join-Path $PSScriptRoot "..\..\decoupled-script\lib\$lib") }
foreach ($lib in 'tag-checkout.ps1', 'release-policy.ps1') { . (Join-Path $PSScriptRoot $lib) }

$script:ForwardRoot = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent

function Get-ForwardPath([string]$Relative) { return (Join-Path $script:ForwardRoot $Relative) }

function Test-Unattended {
    return [bool](Get-ProjectEnvValue 'SCRIPTS_NO_PROMPT') -or $env:CI -eq 'true' -or [Console]::IsInputRedirected
}

# A window is refused where none may open, as the toolkit's own forms are.
function Test-WindowRefused {
    return [bool](Get-ProjectEnvValue 'SCRIPTS_NO_GUI') -or $env:CI -eq 'true'
}

# -Yes, or the word typed; unattended without -Yes it says what to pass and stops, and anything else typed changes nothing.
function Confirm-Deliberate {
    param([Parameter(Mandatory)][string]$Word, [Parameter(Mandatory)][string]$Question, [switch]$Yes)
    if ($Yes) { return }
    if (Test-Unattended) {
        Write-Host "Nobody is here to answer: $Question Pass -Yes to go on without asking." -ForegroundColor Red
        exit 1
    }
    $answer = Read-Host "$Question Type $Word to go on"
    if ($answer -cne $Word) {
        Write-Host 'Nothing was changed.' -ForegroundColor Yellow
        exit 1
    }
}

# The SSH alias the deployment scripts use. Unattended, it must answer without a passphrase - the agent holds the key -
# or the run stops here, naming the command that loads it.
function Assert-SshReady([string]$SshHost = 'bluehost') {
    if (-not (Test-Unattended)) { return }
    & ssh -o BatchMode=yes -o ConnectTimeout=20 $SshHost true 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "SSH to $SshHost needs a key the agent already holds, and nobody is here to type a passphrase: run .\script.ps1 deploy/ssh-key first." -ForegroundColor Red
        exit 1
    }
}

# The portal frontend's checkout beside this one: the old name first, then the one it has now.
function Get-PortalRepo {
    $parent = Split-Path $script:ForwardRoot -Parent
    foreach ($name in 'ikaika-portal', 'ikaika_portal_frontend_rewrite') {
        $candidate = Join-Path $parent $name
        if (Test-Path (Join-Path $candidate 'script.ps1')) { return $candidate }
    }
    Write-Host "No portal frontend checkout beside this one ($parent\ikaika_portal_frontend_rewrite)." -ForegroundColor Red
    exit 1
}

# The Airtable token for a migrations script, which falls back to migrations/.token.private.txt and then asks. From
# <prefix>_AIRTABLE_TOKEN when that is set; unattended with neither, it stops rather than leave the question waiting.
function Get-AirtableTokenArguments {
    $fromEnv = Get-ProjectEnvValue 'AIRTABLE_TOKEN'
    if ($fromEnv) { return @{ Token = $fromEnv } }
    if (Test-Path -LiteralPath (Get-ForwardPath 'migrations\.token.private.txt')) { return @{} }
    if (Test-Unattended) {
        Write-Host "No Airtable token: put it in migrations\.token.private.txt, or set $(Get-ProjectEnvVar 'AIRTABLE_TOKEN')." -ForegroundColor Red
        exit 1
    }
    return @{}
}

# Runs the script with the arguments by name and leaves with its exit code; a throw is reported, not a stack trace.
function Invoke-Forwarded {
    param([Parameter(Mandatory)][string]$Relative, [hashtable]$Arguments = @{})
    $path = Get-ForwardPath $Relative
    $global:LASTEXITCODE = 0
    try {
        & $path @Arguments
        $code = $LASTEXITCODE
    } catch {
        Write-Host $_.Exception.Message -ForegroundColor Red
        $code = 1
    }
    exit $(if ($null -eq $code) { 0 } else { $code })
}
