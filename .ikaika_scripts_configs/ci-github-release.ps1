# Keeps a tagged build as a GitHub release - a prerelease for staging, the latest release for production - with the build's files attached and this version's changelog as its notes.
# The Build release workflow's github_release choice: release files do not count toward the account's Actions storage, as workflow artifacts do, so a release is where a tagged build is kept.
# The notes are this version's section of CHANGELOG.md - which a production build files there in its bump commit - or, for a staging build, the drafts it took, the planned "## x.y.z" and Unreleased; then GitHub's own generated notes.
# Every file at the top of DistDir is attached; a rerun for the same tag replaces the files and notes instead of failing.
# The same file in every repo with the Build release workflow. It needs RELEASE_TAG already on GitHub, gh on PATH, GH_TOKEN allowed to write contents and GITHUB_REPOSITORY; BUILD_DEBUG says which kind of release it is.
param(
    # Where the built files are, relative to the repo or absolute.
    [string]$DistDir = 'dist',
    # Print the notes and the gh command instead of running it, for a run on a workstation.
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent

# gh with 'Continue' for the one call, so a progress line on stderr is output to show rather than an error that stops the script.
function Invoke-Gh {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& gh @args 2>&1 | ForEach-Object { "$_" })
        return @{ Code = $LASTEXITCODE; Text = ($output -join "`n") }
    } finally {
        $ErrorActionPreference = $previous
    }
}

$tag = "$env:RELEASE_TAG".Trim()
if ($tag -notmatch '^v(\d+\.\d+\.\d+(?:\+\d+)?)(?:-released)?$') {
    Write-Output "::error title=No tag to release::RELEASE_TAG is '$tag'; a GitHub release hangs on the version tag this run pushed, like v1.2.0+8 or v1.2.0+8-released."
    exit 1
}
$version = $Matches[1]
$staging = $env:BUILD_DEBUG -eq 'true'
$appName = "$((Get-Content -LiteralPath (Join-Path $repoRoot 'ikaika_scripts.env') | Where-Object { $_ -match '^app\.displayName=' } | Select-Object -First 1) -replace '^app\.displayName=', '')".Trim()
$title = "$appName $version$(if ($staging) { ' (staging)' })"

$section = ''
$changelog = Join-Path $repoRoot 'CHANGELOG.md'
if (Test-Path -LiteralPath $changelog) {
    $text = [IO.File]::ReadAllText($changelog) -replace "`r`n", "`n"
    $match = [regex]::Match($text, "(?ms)^## $([regex]::Escape($version))(?: |$).*?(?=^## |\z)")
    $found = if ($match.Success) { @($match) } else {
        foreach ($heading in [regex]::Escape(($version -split '\+')[0]), 'Unreleased') { [regex]::Match($text, "(?ms)^## $heading[ \t]*$.*?(?=^## |\z)") }
    }
    $section = (@($found | Where-Object Success | ForEach-Object { ($_.Value -replace '^## [^\n]*\n', '').Trim() } | Where-Object { $_ }) -join "`n`n")
}
if (-not $section) { $section = '_CHANGELOG.md has no entries for this version._' }

$generated = ''
if (-not $DryRun) {
    $answer = Invoke-Gh api "repos/$env:GITHUB_REPOSITORY/releases/generate-notes" -f "tag_name=$tag"
    if ($answer.Code -eq 0) { $generated = ($answer.Text | ConvertFrom-Json).body }
    else { Write-Output "::warning title=No generated notes::GitHub could not generate notes for $tag, so the release carries the changelog alone: $($answer.Text)" }
}
$notes = @("## What changed in $version", '', $section)
if ($generated) { $notes += @('', '---', '', $generated) }
$notesFile = Join-Path $(if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [IO.Path]::GetTempPath() }) 'release-notes.md'
[IO.File]::WriteAllText($notesFile, ($notes -join "`n") + "`n", [Text.UTF8Encoding]::new($false))

$assets = @(Get-ChildItem -LiteralPath $(if ([IO.Path]::IsPathRooted($DistDir)) { $DistDir } else { Join-Path $repoRoot $DistDir }) -File -ErrorAction SilentlyContinue | ForEach-Object FullName)
if (-not $assets.Count) {
    Write-Output "::error title=Nothing to release::$DistDir holds no files, so there is nothing to attach to $tag."
    exit 1
}
$kind = if ($staging) { @('--prerelease', '--latest=false') } else { @('--latest') }
# Named outright: gh otherwise looks for the repository among the checkout's remotes, and the job has pointed origin at Bitbucket.
$repo = @('--repo', $env:GITHUB_REPOSITORY)

if ($DryRun) {
    Get-Content -LiteralPath $notesFile
    Write-Output "gh release create $tag $($assets.Count) file(s) --verify-tag --title '$title' $($kind -join ' ')"
    exit 0
}

# A list, not an if's output: a single step returned from an if is unrolled into its words.
$steps = [Collections.Generic.List[object]]::new()
if ((Invoke-Gh release view $tag --json url @repo).Code -eq 0) {
    $steps.Add(@('release', 'upload', $tag) + $assets + @('--clobber') + $repo)
    $steps.Add(@('release', 'edit', $tag, '--title', $title, '--notes-file', $notesFile) + $kind + $repo)
} else {
    $steps.Add(@('release', 'create', $tag) + $assets + @('--verify-tag', '--title', $title, '--notes-file', $notesFile) + $kind + $repo)
}
foreach ($step in $steps) {
    $result = Invoke-Gh @step
    if ($result.Text) { Write-Output $result.Text }
    if ($result.Code -ne 0) {
        Write-Output "::error title=GitHub release failed::gh $($step[0..1] -join ' ') $tag failed; the deploy and the tag are unaffected."
        exit $result.Code
    }
}

$url = (Invoke-Gh release view $tag --json url --jq .url @repo).Text.Trim()
Write-Output "Released $tag with $($assets.Count) file(s): $url"
if ($env:GITHUB_OUTPUT) { Add-Content -LiteralPath $env:GITHUB_OUTPUT -Value "url=$url" }
