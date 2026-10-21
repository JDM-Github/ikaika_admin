# Writes the run's result into its summary, as the project estimator's run does: the version, each step from build to upload, the tag on
# Bitbucket and GitHub, the release, and every file with its artifact. The same file in both portal repos: the version file is
# version.manifest, else VERSION, and an upload went to the target's web address, else to the health URL it was checked at.
# A file is kept in the artifact of its platform - <artifact>-web, -windows, -android or -linux - and anything else in <artifact> itself.
# It reads the checkout, dist and the outcomes of the steps before it, handed over as environment variables, so it reports a failed run as well as a finished one.
param(
    # Where the built files are, relative to the repo or absolute.
    [string]$DistDir = 'dist'
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
$config = @(Get-Content -LiteralPath (Join-Path $repoRoot 'ikaika_scripts.env'))
function Get-ConfigValue([string]$Key) {
    return "$(($config | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1) -replace '^[^=]*=', '')".Trim()
}

$manifest = Get-ConfigValue 'version.manifest'
if (-not $manifest) { $manifest = 'VERSION' }
$manifestPath = Join-Path $repoRoot $manifest
$version = if ($manifest -like '*.json') { "$((Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json).version)" }
else { "$(Get-Content -LiteralPath $manifestPath | Where-Object { $_.Trim() -and -not $_.StartsWith('#') } | Select-Object -First 1)".Trim() }
# A rebuild from a tag (2026-10-21, user) is the version the tag carries, not the checkout's.
if ($env:BUILD_FROM) {
    $shown = @(& git -C $repoRoot show "refs/tags/$($env:BUILD_FROM):$manifest" 2>$null)
    $tagged = if ($manifest -like '*.json') { try { "$((($shown -join "`n") | ConvertFrom-Json).version)" } catch { '' } }
    else { "$($shown | Where-Object { $_.Trim() -and -not $_.StartsWith('#') } | Select-Object -First 1)".Trim() }
    if ($tagged) { $version = $tagged }
}
$staging = $env:BUILD_DEBUG -eq 'true'
# The plan's job variables, which GITHUB_ENV hands every later step; the step's own env wins where it names one.
$deploys = if ($env:DEPLOYS) { $env:DEPLOYS } else { $env:UPLOADING }
$bump = if ($env:BUMP) { $env:BUMP } else { $env:VERSION_BUMP }
$artifactName = if ($env:ARTIFACT) { $env:ARTIFACT } else { $env:ARTIFACT_NAME }
$retention = if ($env:RETENTION) { $env:RETENTION } else { '?' }
$kind = if ($staging) { 'debug' } else { 'release' }
$tag = if ($staging) { "v$version" } else { "v$version-released" }
$state = @{ success = 'done'; failure = 'failed'; skipped = 'skipped'; cancelled = 'cancelled' }
function Get-State([string]$Outcome) { if ($state.ContainsKey($Outcome)) { return $state[$Outcome] } return 'did not run' }

$rows = @("| Version | $version$(if ($env:BUILD_FROM) { ' (as its tag has it)' } elseif ($bump) { " (bump: $bump)" } else { ' (unchanged)' }) |")
if ($env:BUILD_FROM) { $rows += "| Built from | $env:BUILD_FROM, rebuilt as it was |" }
if ($env:WINDOWS_OUTCOME -and $env:WINDOWS_OUTCOME -ne 'skipped') { $rows += "| Windows, built first | $(Get-State $env:WINDOWS_OUTCOME) |" }
if ($env:UBUNTU_OUTCOME -and $env:UBUNTU_OUTCOME -ne 'skipped') { $rows += "| Ubuntu's builds | $(Get-State $env:UBUNTU_OUTCOME) |" }
$rows += "| Build | $(Get-State $env:BUILD_OUTCOME) |"
if ($env:TAGGING -eq 'true') { $rows += "| Changelog filed, version tagged | $(Get-State $env:TAG_OUTCOME) |" }
if ($deploys -eq 'true') {
    $origin = Get-ConfigValue "$kind.webOrigin"
    $sitePath = (Get-ConfigValue "$kind.webSitePath").Trim('/')
    $site = if ($origin) { "$origin$(if ($sitePath) { "/$sitePath" })/$((Get-ConfigValue "$kind.webBaseHref").Trim('/'))/" } else { Get-ConfigValue "$kind.healthUrl" }
    $where = switch ($env:UPLOAD_OUTCOME) {
        'success' { if ($site) { $site } else { 'yes' } }
        'failure' { 'no - the upload failed' }
        default { "no - the upload $(Get-State $env:UPLOAD_OUTCOME), after the build or the tag stopped" }
    }
    $rows += "| Uploaded to $env:TARGET | $where |"
}
if ($env:TAGGING -eq 'true') {
    $rows += "| Tag $tag | Bitbucket: $(Get-State $env:BITBUCKET_OUTCOME), GitHub: $(Get-State $env:GITHUB_OUTCOME) |"
    if ($env:BITBUCKET_OUTCOME -eq 'success') {
        $rows += "| Version commit | $(git -C $repoRoot log -1 --format='%h by %an' HEAD) |"
    }
}
if ($env:RELEASE_URL) { $rows += "| GitHub release | $env:RELEASE_URL ($(if ($staging) { 'prerelease' } else { 'latest' })) |" }

$artifactFor = [ordered]@{ '-web\.zip$' = 'web'; '-windows(-setup\.exe(\.blockmap)?|\.zip|-latest\.yml)$' = 'windows'; '\.(apk|aab)$' = 'android'; '-linux(\.AppImage|\.tar\.gz|-latest\.yml)$' = 'linux' }
$files = @()
foreach ($file in @(Get-ChildItem -LiteralPath $(if ([IO.Path]::IsPathRooted($DistDir)) { $DistDir } else { Join-Path $repoRoot $DistDir }) -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -notmatch '\.sha256$' })) {
    $pattern = $artifactFor.Keys | Where-Object { $file.Name -match $_ } | Select-Object -First 1
    $artifact = if (-not $artifactName) { '-' } elseif ($pattern) { "$artifactName-$($artifactFor[$pattern])" } else { $artifactName }
    $files += "| $($file.Name) | $('{0:N1} MB' -f ($file.Length / 1MB)) | $artifact |"
}

$summary = @('### Result', '', '| | |', '| --- | --- |') + $rows
if ($files.Count) {
    $summary += @('', "### Files - artifacts kept $retention day(s)", '', '| File | Size | Artifact |', '| --- | --- | --- |') + $files
}
$summary += ''
if ($env:GITHUB_STEP_SUMMARY) { Add-Content -LiteralPath $env:GITHUB_STEP_SUMMARY -Value $summary }
$summary | ForEach-Object { Write-Output $_ }
