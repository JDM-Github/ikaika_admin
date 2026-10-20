# Deploys BOTH ends to production (portal.ikaikabim.com/v2) the ikaika_scripts way: build-release then
# deploy-release in each repo, admin first (the portal calls the API). The Test Server keeps deploy-all.ps1.
#
# Production's settings are the repos' own: ikaika_scripts.env release.* keys and
# .ikaika_scripts_configs/release.env (SSHUsername, SSHPASSPHRASE, the paths, AZURE_REDIRECT_URI).
# Nothing is sent from here, so the two repos can never disagree about where production is.
#
#     .\deployment\deploy-all-prod.ps1              build + deploy both ends (asks before each deploy)
#     .\deployment\deploy-all-prod.ps1 -SkipBuild   deploy the archives as they stand
#     .\deployment\deploy-all-prod.ps1 -Force       no production confirmation (CI)
param(
    [switch]$SkipBuild,
    [switch]$Force,
    [string]$PortalRepo = (Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'ikaika-portal')
)

$ErrorActionPreference = 'Stop'
$adminRoot = Split-Path $PSScriptRoot -Parent
$portalScript = Join-Path $PortalRepo 'script.ps1'
if (-not (Test-Path $portalScript)) { throw "Portal toolkit entry not found at $portalScript; pass -PortalRepo" }

# The toolkit's entry point in a repo, forwarded verbatim; -Debug is left off, so it is production.
function Invoke-Toolkit([string]$RepoRoot, [string]$Command, [string[]]$Extra = @(), [switch]$NoConfirm) {
    Write-Host "== $Command  ($RepoRoot)"
    $toolkitArgs = @('-NoProfile', '-File', (Join-Path $RepoRoot 'script.ps1'), $Command) + $Extra
    if ($NoConfirm) { $toolkitArgs += '-Force' }
    & pwsh @toolkitArgs
    if ($LASTEXITCODE -ne 0) { throw "$Command failed in $RepoRoot" }
}

# A bare build-release opens its Build release dialog; any named option (each its own default here) builds straight away.
if (-not $SkipBuild) { Invoke-Toolkit $adminRoot 'build-release' -Extra @('-Keep', '10') }
Invoke-Toolkit $adminRoot 'deploy-release' -NoConfirm:$Force

if (-not $SkipBuild) { Invoke-Toolkit $PortalRepo 'build-release' -Extra @('-Platform', 'web') }
Invoke-Toolkit $PortalRepo 'deploy-release' -NoConfirm:$Force

Write-Host 'Deployed: admin API and portal to production (portal.ikaikabim.com/v2).'
