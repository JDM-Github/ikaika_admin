# Writes the run's result into its summary, as the project estimator's run does: the version, each step from build to upload, the tag on
# Bitbucket and GitHub, the release, and every file with its artifact. The same file in both portal repos: the version file is
# version.manifest, else VERSION, and an upload went to the target's web address, else to the health URL it was checked at.
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
$staging = $env:BUILD_DEBUG -eq 'true'
$kind = if ($staging) { 'debug' } else { 'release' }
$tag = if ($staging) { "v$version" } else { "v$version-released" }
$state = @{ success = 'done'; failure = 'failed'; skipped = 'skipped'; cancelled = 'cancelled' }
function Get-State([string]$Outcome) { if ($state.ContainsKey($Outcome)) { return $state[$Outcome] } return 'did not run' }

$rows = @("| Version | $version$(if ($env:BUMP) { " (bump: $env:BUMP)" } else { ' (unchanged)' }) |")
$rows += "| Build | $(Get-State $env:BUILD_OUTCOME) |"
if ($env:TAGGING -eq 'true') { $rows += "| Changelog filed, version tagged | $(Get-State $env:TAG_OUTCOME) |" }
if ($env:DEPLOYS -eq 'true') {
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

$files = @()
foreach ($file in @(Get-ChildItem -LiteralPath $(if ([IO.Path]::IsPathRooted($DistDir)) { $DistDir } else { Join-Path $repoRoot $DistDir }) -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -notmatch '\.sha256$' })) {
    $files += "| $($file.Name) | $('{0:N1} MB' -f ($file.Length / 1MB)) | $(if ($env:ARTIFACT) { $env:ARTIFACT } else { '-' }) |"
}

$summary = @('### Result', '', '| | |', '| --- | --- |') + $rows
if ($files.Count) {
    $summary += @('', "### Files - artifacts kept $env:RETENTION day(s)", '', '| File | Size | Artifact |', '| --- | --- | --- |') + $files
}
$summary += ''
if ($env:GITHUB_STEP_SUMMARY) { Add-Content -LiteralPath $env:GITHUB_STEP_SUMMARY -Value $summary }
$summary | ForEach-Object { Write-Output $_ }
