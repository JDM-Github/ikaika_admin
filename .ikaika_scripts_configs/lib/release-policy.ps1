# Which builds and uploads are refused before anything is built, and what the server already runs (2026-10-21, user: production is
# always a tagged release, and any tag can be rebuilt to test on staging). One source for build-release, its dialog and the tests, so
# the three cannot disagree.
#
# A rebuild from a tag takes the version the tag carries: it never bumps, has nothing new to tag, and goes to staging only. Production
# only moves forward - its database has had every newer migration run against it, and migrations only go forward. A production upload
# is a release, tagged v<version>-released by tag-version, so every build production runs is one a tag names; a dry run sends nothing.
#
# An older release reaching a server that runs a newer one is allowed on staging and said loudly, with the migrations it does not know;
# deploy-admin.sh refuses it on production unless ALLOW_OLDER=1. What a server runs is its /health's version, which every answer also
# names in X-Api-Version.

function Get-BuildReleaseRefusal {
    param([bool]$Debug, [string]$Bump = '', [bool]$Tag, [bool]$Deploy, [bool]$DryRun, [string]$FromTag = '')
    $bumping = $Bump -and $Bump -ne 'same'
    if ($FromTag) {
        if ($bumping) { return 'A rebuild from a tag never bumps: leave -Bump off, or drop -FromTag.' }
        if ($Tag) { return "$FromTag is tagged already, so a rebuild of it has nothing to tag: drop -Tag." }
        if (-not $Debug) { return "Production only moves forward: $FromTag can be rebuilt for staging (-Debug), and production takes a new release, -Bump keep -Tag." }
        return ''
    }
    if (-not $Debug -and $Deploy -and -not $DryRun -and -not ($bumping -and $Tag)) {
        return 'A production upload is a release, tagged v<version>-released: add -Bump keep -Tag (or patch, minor or major), or -DryRun to see what would go up.'
    }
    return ''
}

# The version a server runs, from its health check: the X-Api-Version header, else the body's version; '' when it does not say.
function Get-ServerReleaseVersion([string]$HealthUrl) {
    if (-not $HealthUrl) { return '' }
    try {
        $answer = Invoke-WebRequest -Uri $HealthUrl -TimeoutSec 15 -SkipHttpErrorCheck -UseBasicParsing -ErrorAction Stop
        $header = "$(@($answer.Headers['X-Api-Version']) | Select-Object -First 1)".Trim()
        if ($header -match '^\d+\.\d+\.\d+(\+\d+)?$') { return $header }
        $body = "$((($answer.Content | ConvertFrom-Json -ErrorAction Stop).version))".Trim()
        if ($body -match '^\d+\.\d+\.\d+(\+\d+)?$') { return $body }
    } catch {
        return ''
    }
    return ''
}

# A repository's version file, as its ikaika_scripts.env names it: version.manifest, else app.versionFile, else VERSION.
function Get-ReleaseManifestPath([string]$Root) {
    $config = @(Get-Content -LiteralPath (Join-Path $Root 'ikaika_scripts.env') -ErrorAction SilentlyContinue)
    foreach ($key in 'version.manifest', 'app.versionFile') {
        $value = "$(($config | Where-Object { $_ -match "^$([regex]::Escape($key))=" } | Select-Object -First 1) -replace '^[^=]*=', '')".Trim()
        if ($value) { return (Join-Path $Root $value) }
    }
    return (Join-Path $Root 'VERSION')
}

# What a release would make a repository's version: what it holds now, and the next one -Bump takes over the tags it has. The
# arithmetic is the toolkit's (app-version.ps1, toolkit-version.ps1), dot-sourced by the caller.
function Get-ReleasePreview {
    param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Bump)
    $held = Get-ManifestVersion (Get-ReleaseManifestPath $Root)
    $current = Split-AppVersion $held
    $name = if ($Bump -eq 'keep') { $current.Version } else { Get-BumpedVersion -Current $current.Version -Bump $Bump }
    $spent = @((Invoke-TagGit $Root @('tag', '--list', 'v*')).Lines | ForEach-Object { $_.Trim() })
    return [pscustomobject]@{ Current = $held; Next = Format-AppVersion $name (Get-NextBuildNumber -CurrentBuild $current.Build -TagNames $spent) }
}

# Why a repository cannot release from where it stands, or '': on a branch, nothing uncommitted but the notes tag-version files,
# and nothing on origin's branch it lacks - so the version commit and its tag can be pushed and hold what was built.
function Test-ReleaseReady([string]$Root) {
    $name = Split-Path $Root -Leaf
    $branch = "$((Invoke-TagGit $Root @('rev-parse', '--abbrev-ref', 'HEAD')).Lines | Select-Object -First 1)".Trim()
    if (-not $branch -or $branch -eq 'HEAD') { return "$name is on no branch (a detached HEAD): check one out first." }
    $notes = @('CHANGELOG.md', 'CHANGELOG.json')
    $status = Invoke-TagGit $Root @('status', '--porcelain', '--untracked-files=normal')
    if ($status.Code -ne 0) { return "git could not say whether $name has uncommitted changes, so its release could not be trusted to hold what is built." }
    $dirty = @($status.Lines | Where-Object { $_ -match '^[ MADRCU?!]{2}\s' -and $_.Substring(3).Trim('"') -notin $notes })
    if ($dirty.Count) { return "$name has $($dirty.Count) uncommitted change(s), which its release tag would not hold - first $($dirty[0].Substring(3)): commit or stash them." }
    $prompt = $env:GIT_TERMINAL_PROMPT
    $env:GIT_TERMINAL_PROMPT = '0'
    try { [void](Invoke-TagGit $Root @('fetch', '--quiet', 'origin', $branch)) } finally { $env:GIT_TERMINAL_PROMPT = $prompt }
    $behind = "$((Invoke-TagGit $Root @('rev-list', '--count', "HEAD..refs/remotes/origin/$branch")).Lines | Select-Object -First 1)".Trim()
    if ($behind -match '^\d+$' -and [int]$behind -gt 0) { return "$name's origin/$branch has $behind commit(s) it lacks: pull first, so the release commit can be pushed." }
    return ''
}

# Whether $Version is older than $ServerVersion: the build number rises across every version name, so it alone decides.
function Test-OlderRelease([string]$Version, [string]$ServerVersion) {
    $mine = Get-VersionBuildNumber $Version
    $theirs = Get-VersionBuildNumber $ServerVersion
    return $null -ne $mine -and $null -ne $theirs -and $mine -lt $theirs
}

# Said before an older release goes up: what the server runs, and the migrations its database has that this release lacks.
function Write-OlderReleaseNote {
    param([string]$Root, [string]$Version, [string]$ServerVersion, [string]$Where, [string]$FromRef = 'HEAD')
    $to = foreach ($name in "v$ServerVersion", "v$ServerVersion-released") {
        if ((Invoke-TagGit $Root @('rev-parse', '-q', '--verify', "refs/tags/$name^{commit}")).Code -eq 0) { "refs/tags/$name"; break }
    }
    $missing = if ($FromRef -ne 'HEAD' -or $to) { Get-PathsAddedSince -Root $Root -From $FromRef -To $(if ($to) { $to } else { 'HEAD' }) -Under 'database/migrations' } else { @() }
    $line = "WARNING: v$Version is older than $ServerVersion, which $Where runs. Its database keeps every newer migration; migrations only go forward."
    Write-Host ''
    Write-Host "  $line" -ForegroundColor Yellow
    if ($missing.Count) {
        Write-Host "  The migrations $Where has run that this release does not know:" -ForegroundColor Yellow
        foreach ($path in $missing) { Write-Host "    $path" -ForegroundColor Yellow }
    }
    if ($env:GITHUB_ACTIONS -eq 'true') { Write-Host "::warning title=Older release::$line" }
}
