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
# Releases (2026-10-21, user): a version names one build - v<version> for staging, v<version>-released for production - so a bump refuses
# uncommitted work (-AllowDirty takes it anyway), a version a tag already holds here or on origin, and, with -Tag, a detached HEAD or a
# branch origin has moved past. A production upload must be a release (lib/release-policy.ps1). -FromTag rebuilds a tag as it was, for
# staging: its sources in a worktree of their own (lib/tag-checkout.ps1) with their own npm ci, packaged by today's build.ps1; composer
# runs on the server, so nothing else is installed. Uploading a build older than the server's says so, with the migrations it lacks.
#
#     .\script.ps1 build-release -Debug                          the staging archive
#     .\script.ps1 build-release -Debug -Deploy -DryRun          and what the upload would send, nothing sent
#     .\script.ps1 build-release -Debug -Bump patch -Tag -Deploy  bumped, tagged on Bitbucket, installed on staging
#     .\script.ps1 build-release -Debug -FromTag v1.0.0+3 -Deploy that tag, rebuilt and installed on staging
#     .\script.ps1 build-release -Bump keep -Tag -Deploy          a production release, tagged v<version>-released
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
    # Rebuild this tag as it was, for staging: its sources in a worktree of their own. The version tags git has here are offered,
    # newest version first; Update fetches the ones pushed since.
    [string]$FromTag = '',
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
    # With a -Bump, build over uncommitted changes, which the version's tag would then not hold.
    [switch]$AllowDirty,
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
    .\script.ps1 build-release [-Debug] [-Bump keep|patch|minor|major|x.y.z [-Tag] [-AllowDirty]] [-Deploy [-DryRun] [-Clean]] [-Keep <n>] [-FixTarget]
    .\script.ps1 build-release -Debug -FromTag <tag> [-Deploy [-DryRun] [-Clean]]
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

    .\script.ps1 build-release -Debug -FromTag v1.0.0+3 -Deploy
        That tag's sources, rebuilt for staging in a worktree of their own and
        installed on the Test Server, to test the version it names. Nothing is
        bumped or tagged; an older one than the server runs is said loudly.

    .\script.ps1 build-release -Bump keep -Tag -Deploy
        A production release: the next build number, tagged
        v<version>-released once the build is good, then installed. Production
        takes nothing else - a rebuild from a tag is staging's.

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

  A bump refuses uncommitted changes (-AllowDirty builds over them) and a
  version a tag already holds; with -Tag, also a detached HEAD and a branch
  origin has commits this checkout lacks.

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
    . (Join-Path $configsDir 'lib\tag-checkout.ps1')
    $current = "$(Get-Content -LiteralPath (Join-Path $repoRoot 'VERSION') -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -and -not $_.StartsWith('#') } | Select-Object -First 1)".Trim()
    $tags = Get-VersionTags -Root $repoRoot -Needs 'VERSION'
    $picked = Show-BuildReleaseDialog -ProjectName "$(Get-ProjectValue 'app.displayName' 'IkaIka Portal Backend')" -Version $current -RepoRoot $repoRoot -BuildRelease $PSCommandPath -Tags $tags
    if ($null -eq $picked) {
        Write-Host ''
        Write-Host '  Nothing was built - the dialog closed without Build running.' -ForegroundColor DarkGray
        Write-Host ''
        exit 0
    }
    if ($picked.AlreadyRan) { exit $picked.ExitCode }
    # The dialog's own Get-BuildBackendArguments, so this run and its Will run line are one and the same.
    $arguments = (Get-BuildBackendArguments $picked).Splat
    & $PSCommandPath @arguments
    exit $LASTEXITCODE
}

foreach ($lib in 'shared-fetch.ps1', 'changelog.ps1', 'app-version.ps1', 'toolkit-version.ps1') { . (Join-Path $repoRoot "decoupled-script\lib\$lib") }
foreach ($lib in 'tag-checkout.ps1', 'release-policy.ps1') { . (Join-Path $configsDir "lib\$lib") }
# release-git.ps1's helpers read the repository from $Root.
$script:Root = $repoRoot
function Stop-Build([string]$Message) {
    Write-Host ''
    Write-Host "  $Message" -ForegroundColor Red
    Write-Host ''
    exit 1
}
$bumping = $Bump -and $Bump -ne 'same'
if ($bumping -and $Bump -notmatch '^(keep|patch|minor|major|\d+\.\d+\.\d+)$') { Stop-Build "-Bump takes same, keep, patch, minor, major or x.y.z; it is '$Bump'." }
$refusal = Get-BuildReleaseRefusal -Debug ([bool]$Debug) -Bump $Bump -Tag ([bool]$Tag) -Deploy ([bool]$Deploy) -DryRun ([bool]$DryRun) -FromTag $FromTag
if ($refusal) { Stop-Build $refusal }
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
if ($FromTag) {
    # What a build of it needs: a version to build as, Laravel, and the Vite build's packages.
    $why = Test-TagBuildable -Root $repoRoot -Tag $FromTag -Needs 'VERSION', 'artisan', 'package.json'
    if ($why) { Stop-Build $why }
} elseif (-not (Test-Path -LiteralPath (Join-Path $repoRoot 'node_modules'))) {
    Stop-Build 'There is no node_modules here for the Vite build; run npm ci first.'
}

$manifestBefore = ''
if ($bumping) {
    # A version names one build, so its tag must hold what was built: nothing uncommitted but the notes tag-version files.
    if (-not $AllowDirty) {
        # Asked here rather than through the toolkit's Get-UncommittedChanges, which reads a git that cannot answer as a clean tree:
        # a version is trusted to name what was built, so not knowing is a refusal.
        $status = Invoke-TagGit $repoRoot @('status', '--porcelain', '--untracked-files=normal')
        if ($status.Code -ne 0) { Stop-Build 'git could not say whether this checkout has uncommitted changes, so the version could not be trusted to name this build: pass -AllowDirty to build anyway.' }
        $notes = @(Get-ChangelogFileNames)
        $dirty = @($status.Lines | Where-Object { $_ -match '^[ MADRCU?!]{2}\s' -and $_.Substring(3).Trim('"') -notin $notes })
        if ($dirty.Count) { Stop-Build "$($dirty.Count) uncommitted change(s), which the version's tag would not hold: commit or stash them, or pass -AllowDirty. First: $($dirty[0].Substring(3))" }
    }
    if ($Tag) {
        $branch = "$(& git -C $repoRoot rev-parse --abbrev-ref HEAD 2>$null)".Trim()
        if (-not $branch -or $branch -eq 'HEAD') { Stop-Build 'This checkout is on no branch (a detached HEAD), so a version commit would belong to nothing: check a branch out first.' }
        $env:GIT_TERMINAL_PROMPT = '0'
        [void](Invoke-TagGit $repoRoot @('fetch', '--quiet', '--tags', 'origin'))
        [void](Invoke-TagGit $repoRoot @('fetch', '--quiet', 'origin', $branch))
        $behind = "$((Invoke-TagGit $repoRoot @('rev-list', '--count', "HEAD..refs/remotes/origin/$branch")).Lines | Select-Object -First 1)".Trim()
        if ($behind -match '^\d+$' -and [int]$behind -gt 0) { Stop-Build "origin/$branch has $behind commit(s) this checkout lacks, so its version commit could not be pushed: pull first." }
    }
    $manifestPath = Join-Path $repoRoot 'VERSION'
    $manifestBefore = [IO.File]::ReadAllText($manifestPath)
    $held = Get-ManifestVersion $manifestPath
    $current = Split-AppVersion $held
    $name = if ($Bump -eq 'keep') { $current.Version } else { Get-BumpedVersion -Current $current.Version -Bump $Bump }
    if ((Compare-ChangelogVersion $name $current.Version) -lt 0) { Stop-Build "$name is older than $($current.Version); a version only moves forward." }
    $spent = @(& git -C $repoRoot tag --list 'v*' 2>$null | ForEach-Object { "$_".Trim() })
    $next = Format-AppVersion $name (Get-NextBuildNumber -CurrentBuild $current.Build -TagNames $spent)
    # Asked before anything is written: a number a tag holds here or on origin is spent, and handing it out twice is what breaks an upgrade.
    $taken = Test-VersionTagConflict $next
    if ($taken.Conflicts.Count) { Stop-Build "$next is taken - $($taken.Conflicts -join ', '). git fetch --tags, then build again for the next number." }
    if ($taken.Unreachable) { Write-Host "  origin did not answer, so only this checkout's tags were asked whether $next is free." -ForegroundColor Yellow }
    Set-ManifestVersion $manifestPath $next
    Write-Host "  VERSION $held -> $next, uncommitted: tag-version files the changelog, commits and tags it once the build is good."
}
$sourceRoot = $repoRoot
$tree = ''
$version = ''
$built = $false
# A build that throws - npm missing, say - ends here like one that fails, so a bumped VERSION is always put back and a worktree removed.
try {
    if ($FromTag) {
        $tree = New-TagCheckout -Root $repoRoot -Tag $FromTag -Prefix 'portal-backend'
        $sourceRoot = $tree
        Write-Host "  $FromTag checked out in $tree; installing its packages..."
        Push-Location $tree
        try { npm ci --no-audit --no-fund } finally { Pop-Location }
        if ($LASTEXITCODE -ne 0) { throw "npm ci failed for $FromTag." }
    }
    $version = "$(Get-Content -LiteralPath (Join-Path $sourceRoot 'VERSION') -ErrorAction SilentlyContinue | Where-Object { $_.Trim() -and -not $_.StartsWith('#') } | Select-Object -First 1)".Trim()
    Write-Host ''
    Write-Host "  Building $(if ($version) { $version } else { 'the API' })$(if ($FromTag) { " ($FromTag, as it was)" }) for $(if ($Debug) { 'staging' } else { 'PRODUCTION' }), served from $assetUrl"
    Write-Host ''
    $package = @{ AssetUrl = $assetUrl }
    if ($FromTag) { $package.Root = $tree }
    & (Join-Path $repoRoot 'deployment\build.ps1') @package
    $built = $?
} catch {
    Write-Host "  $($_.Exception.Message)" -ForegroundColor Red
} finally {
    if ($tree) { Remove-TagCheckout -Root $repoRoot -Path $tree }
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
# What was built travels with it: the target deploy-release checks, the version, and the tag a rebuild came from.
$manifest = [ordered]@{ target = $kind; version = $version }
if ($FromTag) { $manifest.fromTag = $FromTag }
$manifest.serverEnv = $serverEnv
$manifest | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath "$archive.deploy.json" -Encoding utf8
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
    $where = if ($Debug) { 'the Test Server' } else { 'production' }
    # Said, not refused, on staging: testing an older version is what a rebuild is for. deploy-admin.sh holds production's line.
    $serverVersion = Get-ServerReleaseVersion (Get-ProjectValue "$kind.healthUrl" '')
    if ($version -and $serverVersion -and (Test-OlderRelease $version $serverVersion)) {
        Write-OlderReleaseNote -Root $repoRoot -Version $version -ServerVersion $serverVersion -Where $where -FromRef $(if ($FromTag) { "refs/tags/$FromTag" } else { 'HEAD' })
    }
    $named = @("v$version", "v$version-released" | Where-Object { (Invoke-TagGit $repoRoot @('rev-parse', '-q', '--verify', "refs/tags/$_")).Code -eq 0 })
    if ($version -and -not $named.Count -and -not ($Tag -and $bumping)) {
        Write-Host "  Installing $version, which no tag names: a later build can take its number, so pick a version (-Bump keep -Tag) to keep this one." -ForegroundColor DarkGray
    }
    Write-Host ''
    $upload = @{ Debug = [bool]$Debug; Force = $true; DryRun = [bool]$DryRun }
    & $dispatcher server/deploy-release @upload
    exit $LASTEXITCODE
}
exit 0

# Read by the manifest generator and run alone, so it finds the repo from the working directory itself: the version tags to rebuild,
# newest version first, each with a VERSION to build as.
function Get-DialogChoiceParameters { return , @('FromTag') }

function Get-DialogChoices([string]$Parameter) {
    if ($Parameter -ne 'FromTag') { return , @() }
    $dir = (Get-Location).Path
    while ($dir -and -not (Test-Path -LiteralPath (Join-Path $dir 'ikaika_scripts.env'))) { $dir = Split-Path $dir -Parent }
    if (-not $dir) { return , @() }
    . (Join-Path $dir '.ikaika_scripts_configs\lib\tag-checkout.ps1')
    $tags = Get-VersionTags -Root $dir -Needs 'VERSION'
    return , @($tags | Select-Object -First 30)
}

# Update beside the list fetches the tags pushed since, and never prunes one: a tag gone upstream can still be rebuilt here.
function Get-DialogRefresh([string]$Parameter, [switch]$Preview) {
    if ($Parameter -ne 'FromTag' -or $Preview) { return '' }
    $before = @(& git tag 2>$null).Count
    & git fetch --tags --quiet 2>$null | Out-Null
    $after = @(& git tag 2>$null).Count
    if ($after -gt $before) { return "fetched, $($after - $before) new tag(s)" }
    return "fetched, $after tag(s)"
}
