# Builds the API's release archive, deployment/out/central-platform.zip, for staging or production: deployment/build.ps1 with the public folder the target serves it from, which Vite bakes into every font URL.
# The archive is what server/deploy-release uploads and deployment/server/deploy-admin.sh installs. On its own this only builds, so a pipeline can show
# building and uploading as two steps; -Tag, -Deploy and -Clean add the rest in the project estimator's order, as its build-release does: build, then
# tag-version files the changelog, commits and tags, then server/deploy-release uploads - each only once the one before it is good.
# The target's folder is <kind>.assetUrl in ikaika_scripts.env, and production's serverEnv lines name its site (lib/api-target.ps1); a target
# missing either goes to check-targets, which derives them from the deploy config and the server - its report, then its fix with
# -FixTarget or a yes at the prompt - and is refused if they are still missing, rather than built for the wrong folder or installed as staging.
# -Bump bumps VERSION before the build, as the estimator's build-release bumps pubspec.yaml: uncommitted, and put back if the build fails, so
# the archive carries the version it is built as; tag-version then files the changelog, commits and tags it once the build is good.
# <archive>.deploy.json beside the archive names the target it was built for, so server/deploy-release refuses it for the other one, and -Clean
# there asks deploy-admin.sh to remove the older release backups and a failed deploy's leftovers once the new release is in.
# A versioned copy, <artifact.prefix>-<version>[-debug].zip with its .sha256, goes in dist/ for the GitHub release, as the estimator's builds do.
# -Gui opens the estimator backend's Build release dialog (.ikaika_scripts_configs/ui/build-backend.xaml) instead: a named command runs without asking.
#
#     .\script.ps1 build-release -Debug                          the staging archive
#     .\script.ps1 build-release -Debug -Deploy -DryRun          and what the upload would send, nothing sent
#     .\script.ps1 build-release -Debug -Bump patch -Tag -Deploy  bumped, tagged on Bitbucket, installed on staging
#     .\script.ps1 build-release -Gui                            the dialog
#     .\script.ps1 build-release -FixTarget                      production's, with what check-targets can derive filled in first

param(
    # Staging: debug.assetUrl rather than production's release.assetUrl.
    [switch]$Debug,
    # The version to build as, as the estimator's build-release takes it: keep, patch, minor, major or an explicit x.y.z, bumped in
    # VERSION uncommitted and put back if the build fails. Same, or left out, builds the version as it stands.
    [ArgumentCompletions('same', 'keep', 'patch', 'minor', 'major')]
    [string]$Bump = '',
    # With a -Bump, file the changelog under the new version, commit and tag it on Bitbucket once the build is good - tag-version.
    [switch]$Tag,
    # Upload to the server once the build (and the tag) is good: server/deploy-release, which installs it with deploy-admin.sh.
    [switch]$Deploy,
    # With -Deploy, name what would go up and run, and send nothing.
    [switch]$DryRun,
    # With -Deploy, remove the server's older release backups and a failed deploy's leftovers once the new release is in.
    [switch]$Clean,
    # How many release copies dist/ keeps; older ones are pruned.
    [ArgumentCompletions('5', '10', '20', '50')]
    [int]$Keep = 10,
    # When the target is not set up, fill in what check-targets can derive from its deploy config and the server, then build.
    [switch]$FixTarget,
    # The estimator backend's Build release dialog, instead of running with these switches.
    [switch]$Gui,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent

if ($Help) {
    Write-Host @'

  build-release - build the API's release archive for staging or production, and tag and upload it when asked.

  Usage
    .\script.ps1 build-release [-Debug] [-Bump keep|patch|minor|major|x.y.z [-Tag]] [-Deploy [-DryRun] [-Clean]] [-Keep <n>] [-FixTarget]
    .\script.ps1 build-release -Gui

  Examples
    .\script.ps1 build-release -Debug
        deployment/out/central-platform.zip for the Test Server: the Vite build
        with debug.assetUrl baked into its font URLs, the app's code, VERSION.

    .\script.ps1 build-release -Debug -Deploy -DryRun
        The same, then what the upload would send and run. Nothing is sent.

    .\script.ps1 build-release -Debug -Bump patch -Tag -Deploy
        The next patch built for staging, the changelog filed under it,
        committed and tagged on Bitbucket, then installed on the Test Server.
        A failed build puts VERSION back as it was; a failed tag uploads nothing.

    .\script.ps1 build-release -Debug -Deploy -Clean
        Installed on staging, then the older release backups and a failed
        deploy's leftovers removed from the server.

    .\script.ps1 build-release -Gui
        The Build release dialog the project estimator's backend has.

    .\script.ps1 build-release -FixTarget
        The same for production, from release.assetUrl, with what is missing
        filled in first by check-targets.

  A target that is not set up - no assetUrl, or for production no SITE_DIR,
  SITE_URL or API_CHANNEL among its serverEnv lines - is handed to
  check-targets, which derives them from the deploy config and the server.
  Run here, it shows its report and offers the fix; -FixTarget applies it.

  Every build also writes dist/<artifact.prefix>-<version>[-debug].zip with
  its .sha256, for the GitHub release, keeping the newest -Keep.

'@
    exit 0
}

# Run with nothing at all where a window can open - bare, or from the launcher - it is the dialog, as the estimator's
# build-release is: the toolkit shows no form of its own for a command that owns a dialog. Anything named runs as named.
$libDir = Join-Path $repoRoot 'decoupled-script\lib'
. (Join-Path $libDir 'load-env.ps1')
$windowed = $PSBoundParameters.Count -eq 0 -and [Environment]::UserInteractive -and
    "$(Get-ProjectValue 'scripts.gui' 'auto')".Trim().ToLower() -ne 'off' -and "$(Get-ProjectEnvValue 'SCRIPTS_NO_GUI')" -ne '1'
if ($Gui -or $windowed) {
    foreach ($lib in 'ui-host.ps1', 'toolkit-version.ps1', 'app-version.ps1') { . (Join-Path $libDir $lib) }
    . (Get-UiAsset 'build-backend.ps1')
    . (Join-Path $configsDir 'lib\api-target.ps1')
    $current = "$(Get-Content -LiteralPath (Join-Path $repoRoot 'VERSION') -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -and -not $_.StartsWith('#') } | Select-Object -First 1)".Trim()
    $picked = Show-BuildReleaseDialog -ProjectName "$(Get-ProjectValue 'app.displayName' 'IkaIka Portal Backend')" -Version $current -RepoRoot $repoRoot -BuildRelease $PSCommandPath
    if ($null -eq $picked) {
        Write-Host ''
        Write-Host '  Nothing was built - the dialog closed without Build running.' -ForegroundColor DarkGray
        Write-Host ''
        exit 0
    }
    if ($picked.AlreadyRan) { exit $picked.ExitCode }
    $arguments = @{ Debug = $picked.Debug; Keep = $picked.Keep; Deploy = $picked.Deploy; DryRun = $picked.DryRun; Clean = $picked.Clean; FixTarget = $picked.FixTarget }
    if ($picked.BumpNeeded) { $arguments.Bump = $picked.Version; $arguments.Tag = $picked.Tag }
    & $PSCommandPath @arguments
    exit $LASTEXITCODE
}

foreach ($lib in 'shared-fetch.ps1', 'changelog.ps1', 'app-version.ps1', 'toolkit-version.ps1') { . (Join-Path $repoRoot "decoupled-script\lib\$lib") }
$bumping = $Bump -and $Bump -ne 'same'
if ($bumping -and $Bump -notmatch '^(keep|patch|minor|major|\d+\.\d+\.\d+)$') {
    Write-Host "  -Bump takes same, keep, patch, minor, major or x.y.z; it is '$Bump'." -ForegroundColor Red
    exit 1
}
if ($Tag -and -not $bumping) { Write-Host '  -Tag has nothing to tag without a -Bump that changes the version.' -ForegroundColor DarkGray }
if (($DryRun -or $Clean) -and -not $Deploy) { Write-Host '  -DryRun and -Clean apply to an upload, and there is no -Deploy.' -ForegroundColor DarkGray }

$kind = if ($Debug) { 'debug' } else { 'release' }
. (Join-Path $configsDir 'lib\api-target.ps1')
$target = Resolve-ApiTarget $repoRoot $kind
# Nobody at the keyboard means no, and the refusal says how.
if ($target.Problem) {
    $targetName = if ($Debug) { 'staging' } else { 'production' }
    $checker = Join-Path $PSScriptRoot 'check-targets.ps1'
    $pwsh = (Get-Process -Id $PID).Path
    Write-Host ''
    Write-Host "  $($target.Problem)" -ForegroundColor Yellow
    $fixing = [bool]$FixTarget
    if (-not $fixing) {
        & $pwsh -NoProfile -File $checker -Target $targetName
        . (Join-Path $libDir 'interactive.ps1')
        $rows = @(@{ id = 'fix'; label = 'Fill in what can be derived, then build' }, @{ id = 'stop'; label = 'Stop here' })
        $fixing = (Request-Choice -Rows $rows -Title "$targetName is not set up") -eq 'fix'
    }
    if ($fixing) {
        & $pwsh -NoProfile -File $checker -Target $targetName -Fix
        $target = Resolve-ApiTarget $repoRoot $kind
    }
    if ($target.Problem) {
        Write-Host ''
        Write-Host "  $($target.Problem)" -ForegroundColor Red
        Write-Host "  .\script.ps1 check-targets -Target $targetName -Fix fills in what can be derived, or build-release -FixTarget does it first$(if (-not $Debug) { '; -Debug builds staging' })." -ForegroundColor DarkGray
        Write-Host ''
        exit 1
    }
}
$assetUrl = $target.AssetUrl
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
$serverEnv = [ordered]@{}
if ($Clean) { $serverEnv.CLEAN = '1' }
[ordered]@{ target = $kind; serverEnv = $serverEnv } | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath "$archive.deploy.json" -Encoding utf8
$hash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLower()
Write-Host ''
Write-Host "  Built $archive - $([Math]::Round((Get-Item -LiteralPath $archive).Length / 1MB, 2)) MB, sha256 $($hash.Substring(0, 12))..." -ForegroundColor Green

# The release copy, named as the estimator's are - <artifact.prefix>-<version>[-debug].zip with its .sha256 - in dist/,
# which the GitHub release and the run's artifact take; server/deploy-release keeps the fixed name above. The newest -Keep stay.
$prefix = "$(Get-ProjectValue 'artifact.prefix' '')".Trim()
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

# The estimator's order from here: the version tagged once the build is good, the upload once the tag is on Bitbucket.
$dispatcher = Join-Path $repoRoot 'script.ps1'
if ($Tag -and $bumping) {
    Write-Host ''
    & $dispatcher tag-version $(if ($Debug) { '-Debug' })
    if ($LASTEXITCODE -ne 0) {
        Write-Host '  The version was not tagged, so nothing was uploaded.' -ForegroundColor Red
        exit 1
    }
}
if ($Deploy) {
    Write-Host ''
    $upload = @{ Debug = [bool]$Debug; Force = $true; DryRun = [bool]$DryRun }
    & $dispatcher server/deploy-release @upload
    exit $LASTEXITCODE
}
exit 0
