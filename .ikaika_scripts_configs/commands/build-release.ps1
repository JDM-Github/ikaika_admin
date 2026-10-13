# Builds the API's release archive, deployment/out/central-platform.zip, for staging or production: deployment/build.ps1 with the public folder the target serves it from, which Vite bakes into every font URL.
# The archive is what server/deploy-release uploads and deployment/server/deploy-admin.sh installs; this only builds it, so a pipeline can show building and uploading as two steps.
# The target's folder is <kind>.assetUrl in ikaika_scripts.env, and a target without one is refused rather than built for the wrong folder - production has none until it is set up.
# -Bump bumps VERSION before the build, as the estimator's build-release bumps pubspec.yaml: uncommitted, and put back if the build fails, so
# the archive carries the version it is built as; tag-version then files the changelog, commits and tags it once the build is good.
# A versioned copy, <artifact.prefix>-<version>[-debug].zip with its .sha256, goes in dist/ for the GitHub release, as the estimator's builds do.
#
#     .\script.ps1 build-release -Debug       the staging archive
#     .\script.ps1 build-release              production's, once release.assetUrl is set

param(
    # Staging: debug.assetUrl rather than production's release.assetUrl.
    [switch]$Debug,
    # The version to build as, as the estimator's build-release takes it: keep, patch, minor, major or an explicit x.y.z, bumped in
    # VERSION uncommitted and put back if the build fails. Same, or left out, builds the version as it stands.
    [ArgumentCompletions('same', 'keep', 'patch', 'minor', 'major')]
    [string]$Bump = '',
    # How many release copies dist/ keeps; older ones are pruned.
    [ArgumentCompletions('5', '10', '20', '50')]
    [int]$Keep = 10,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent

if ($Help) {
    Write-Host @'

  build-release - build the API's release archive for staging or production.

  Usage
    .\script.ps1 build-release [-Debug] [-Bump keep|patch|minor|major|x.y.z] [-Keep <n>]

  Examples
    .\script.ps1 build-release -Debug
        deployment/out/central-platform.zip for the Test Server: the Vite build
        with debug.assetUrl baked into its font URLs, the app's code, VERSION.

    .\script.ps1 build-release
        The same for production, from release.assetUrl - refused while that is
        blank, since production is not set up yet.

    .\script.ps1 build-release -Debug -Bump patch
        The next patch, in VERSION uncommitted, built for staging; tag-version
        files the changelog, commits and tags it. A failed build puts VERSION
        back as it was.

  Every build also writes dist/<artifact.prefix>-<version>[-debug].zip with
  its .sha256, for the GitHub release, keeping the newest -Keep.

  Then .\script.ps1 server/deploy-release -Debug uploads and installs it.

'@
    exit 0
}

foreach ($lib in 'shared-fetch.ps1', 'changelog.ps1', 'app-version.ps1', 'toolkit-version.ps1') { . (Join-Path $repoRoot "decoupled-script\lib\$lib") }
$bumping = $Bump -and $Bump -ne 'same'
if ($bumping -and $Bump -notmatch '^(keep|patch|minor|major|\d+\.\d+\.\d+)$') {
    Write-Host "  -Bump takes same, keep, patch, minor, major or x.y.z; it is '$Bump'." -ForegroundColor Red
    exit 1
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

$manifestBefore = ''
if ($bumping) {
    $manifestPath = Join-Path $repoRoot 'VERSION'
    $manifestBefore = [IO.File]::ReadAllText($manifestPath)
    $held = Get-ManifestVersion $manifestPath
    $current = Split-AppVersion $held
    $name = if ($Bump -eq 'keep') { $current.Version } else { Get-BumpedVersion -Current $current.Version -Bump $Bump }
    if ((Compare-ChangelogVersion $name $current.Version) -lt 0) { throw "$name is older than $($current.Version); a version only moves forward." }
    $spent = @(& git -C $repoRoot tag --list 'v*' 2>$null | ForEach-Object { "$_".Trim() })
    $next = Format-AppVersion $name (Get-NextBuildNumber -CurrentBuild $current.Build -TagNames $spent)
    Set-ManifestVersion $manifestPath $next
    Write-Host "  VERSION $held -> $next, uncommitted: tag-version files the changelog, commits and tags it once the build is good."
}
$version = "$(Get-Content -LiteralPath (Join-Path $repoRoot 'VERSION') -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -and -not $_.StartsWith('#') } | Select-Object -First 1)".Trim()
Write-Host ''
Write-Host "  Building $(if ($version) { $version } else { 'the API' }) for $(if ($Debug) { 'staging' } else { 'PRODUCTION' }), served from $assetUrl"
Write-Host ''
# A build that throws - npm missing, say - ends here like one that fails, so a bumped VERSION is always put back.
$built = $false
try {
    & (Join-Path $repoRoot 'deployment\build.ps1') -AssetUrl $assetUrl
    $built = $?
} catch {
    Write-Host "  $($_.Exception.Message)" -ForegroundColor Red
}
if (-not $built) {
    if ($manifestBefore) {
        [IO.File]::WriteAllText((Join-Path $repoRoot 'VERSION'), $manifestBefore, [Text.UTF8Encoding]::new($false))
        Write-Host '  VERSION is back as it was, since the build failed.' -ForegroundColor Yellow
    }
    exit 1
}

$archive = Join-Path $repoRoot 'deployment\out\central-platform.zip'
if (-not (Test-Path -LiteralPath $archive)) {
    Write-Host "  The build finished without writing $archive." -ForegroundColor Red
    exit 1
}
$hash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLower()
Write-Host ''
Write-Host "  Built $archive - $([Math]::Round((Get-Item -LiteralPath $archive).Length / 1MB, 2)) MB, sha256 $($hash.Substring(0, 12))..." -ForegroundColor Green

# The release copy, named as the estimator's are - <artifact.prefix>-<version>[-debug].zip with its .sha256 - in dist/,
# which the GitHub release and the run's artifact take; server/deploy-release keeps the fixed name above. The newest -Keep stay.
$prefix = "$(($config | Where-Object { $_ -match '^artifact\.prefix=' } | Select-Object -First 1) -replace '^[^=]*=', '')".Trim()
if ($prefix -and $version) {
    $dist = Join-Path $repoRoot 'dist'
    New-Item -ItemType Directory -Force -Path $dist | Out-Null
    $named = Join-Path $dist "$prefix-$version$(if ($Debug) { '-debug' }).zip"
    Copy-Item -LiteralPath $archive -Destination $named -Force
    [IO.File]::WriteAllLines("$named.sha256", @("$((Get-FileHash -LiteralPath $named -Algorithm SHA256).Hash.ToLower())  $(Split-Path -Leaf $named)"))
    Get-ChildItem -LiteralPath $dist -Filter "$prefix-*.zip" -File | Sort-Object LastWriteTime -Descending | Select-Object -Skip $Keep |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName, "$($_.FullName).sha256" -Force -ErrorAction SilentlyContinue }
    Write-Host "  Release copy: $named" -ForegroundColor Green
}
exit 0
