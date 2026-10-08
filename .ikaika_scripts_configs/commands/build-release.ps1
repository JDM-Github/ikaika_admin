# Builds the API's release archive, deployment/out/central-platform.zip, for staging or production: deployment/build.ps1 with the public folder the target serves it from, which Vite bakes into every font URL.
# The archive is what server/deploy-release uploads and deployment/server/deploy-admin.sh installs; this only builds it, so a pipeline can show building and uploading as two steps.
# The target's folder is <kind>.assetUrl in ikaika_scripts.env, and a target without one is refused rather than built for the wrong folder - production has none until it is set up.
# Nothing is bumped here: tag-version -Bump moves VERSION in the step before, and the archive carries VERSION as it stands.
#
#     .\script.ps1 build-release -Debug       the staging archive
#     .\script.ps1 build-release              production's, once release.assetUrl is set

param(
    # Staging: debug.assetUrl rather than production's release.assetUrl.
    [switch]$Debug,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent

if ($Help) {
    Write-Host @'

  build-release - build the API's release archive for staging or production.

  Usage
    .\script.ps1 build-release [-Debug]

  Examples
    .\script.ps1 build-release -Debug
        deployment/out/central-platform.zip for the Test Server: the Vite build
        with debug.assetUrl baked into its font URLs, the app's code, VERSION.

    .\script.ps1 build-release
        The same for production, from release.assetUrl - refused while that is
        blank, since production is not set up yet.

  Then .\script.ps1 server/deploy-release -Debug uploads and installs it.

'@
    exit 0
}

$kind = if ($Debug) { 'debug' } else { 'release' }
$config = @(Get-Content -LiteralPath (Join-Path $repoRoot 'ikaika_scripts.env'))
$assetUrl = "$(($config | Where-Object { $_ -match "^$kind\.assetUrl=" } | Select-Object -First 1) -replace '^[^=]*=', '')".Trim()
if (-not $assetUrl) {
    Write-Host ''
    Write-Host "  $kind.assetUrl is blank in ikaika_scripts.env, so there is no folder to build $(if ($Debug) { 'staging' } else { 'production' }) for." -ForegroundColor Red
    Write-Host '  It is the public folder the target serves the API from, e.g. /website_a85fbf18/central-api.' -ForegroundColor DarkGray
    Write-Host ''
    exit 1
}
if (-not (Test-Path -LiteralPath (Join-Path $repoRoot 'node_modules'))) {
    Write-Host '  There is no node_modules here for the Vite build; run npm ci first.' -ForegroundColor Red
    exit 1
}

$version = "$(Get-Content -LiteralPath (Join-Path $repoRoot 'VERSION') -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -and -not $_.StartsWith('#') } | Select-Object -First 1)".Trim()
Write-Host ''
Write-Host "  Building $(if ($version) { $version } else { 'the API' }) for $(if ($Debug) { 'staging' } else { 'PRODUCTION' }), served from $assetUrl"
Write-Host ''
& (Join-Path $repoRoot 'deployment\build.ps1') -AssetUrl $assetUrl
if (-not $?) { exit 1 }

$archive = Join-Path $repoRoot 'deployment\out\central-platform.zip'
if (-not (Test-Path -LiteralPath $archive)) {
    Write-Host "  The build finished without writing $archive." -ForegroundColor Red
    exit 1
}
$hash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLower()
Write-Host ''
Write-Host "  Built $archive - $([Math]::Round((Get-Item -LiteralPath $archive).Length / 1MB, 2)) MB, sha256 $($hash.Substring(0, 12))..." -ForegroundColor Green
exit 0
