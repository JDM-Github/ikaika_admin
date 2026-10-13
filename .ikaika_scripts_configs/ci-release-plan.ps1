# Turns the Build release form's answers into what the job runs, checking them before anything is fetched or built.
# The answers arrive as one JSON object in INPUTS_JSON, so a workstation can try the same choices by setting it; ci-local does.
# The secrets list goes to GITHUB_ENV, build-release's arguments to a JSON file the package step splats, and server/deploy-release's to DEPLOY_PLAN.
# Build, version and upload are steps of their own, in the project estimator's order - build-release with -Bump, then tag-version once the build
# is good, then server/deploy-release once the tag is - so a run shows which one it is in, and a build that fails spends no version.
# A production upload needs its box ticked and a production to go to: release.assetUrl in ikaika_scripts.env is blank until there is one.
param(
    # Where build-release's arguments are written.
    [string]$PlanFile = (Join-Path $(if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [IO.Path]::GetTempPath() }) 'build-release.json')
)

$ErrorActionPreference = 'Stop'

$answers = if ($env:INPUTS_JSON) { $env:INPUTS_JSON | ConvertFrom-Json -AsHashtable } else { @{} }
function Get-Answer([string]$Name, $Default) {
    $value = $answers[$Name]
    if ($null -eq $value -or "$value" -eq '') { return $Default }
    if ($value -is [string]) { return $value.Trim() }
    return $value
}
function Test-Answer([string]$Name, [bool]$Default = $false) { return [bool](Get-Answer $Name $Default) -and "$(Get-Answer $Name $Default)" -ne 'false' }

function Set-JobVariable([string]$Name, [string]$Value) {
    Set-Item -LiteralPath "env:$Name" -Value $Value
    if ($env:GITHUB_ENV) { Add-Content -LiteralPath $env:GITHUB_ENV -Value "$Name=$Value" }
}

$repoRoot = Split-Path $PSScriptRoot -Parent
$config = @(Get-Content -LiteralPath (Join-Path $repoRoot 'ikaika_scripts.env'))
function Get-ConfigValue([string]$Key) {
    return "$(($config | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1) -replace '^[^=]*=', '')".Trim()
}

$target = Get-Answer 'target' 'staging'
$version = Get-Answer 'version' 'same'
$customVersion = Get-Answer 'custom_version' ''
$upload = Test-Answer 'upload'
$dryRun = Test-Answer 'dry_run'
$githubRelease = Test-Answer 'github_release' $true
$refType = "$env:GITHUB_REF_TYPE"
$refName = "$env:GITHUB_REF_NAME"

$problems = @()
if (@('staging', 'production') -cnotcontains $target) { $problems += "target is '$target'; it takes staging, production." }
if (@('same', 'patch', 'minor', 'major', 'keep', 'custom') -cnotcontains $version) { $problems += "version is '$version'; it takes same, patch, minor, major, keep, custom." }

$bump = $version
if ($version -eq 'custom') {
    if ($customVersion -notmatch '^\d+\.\d+\.\d+$') { $problems += "custom_version must be x.y.z, like 1.2.0; it is '$customVersion'." }
    $bump = $customVersion
} elseif ($customVersion) {
    Write-Output "::warning title=custom_version ignored::It applies only when version is custom, and version is $version."
}
$tagging = $version -ne 'same'
if ($tagging -and $refType -ne 'branch') { $problems += "A version bump is committed to a branch, and this run is on the $refType $refName - pick a branch under 'Use workflow from'." }
$kind = if ($target -eq 'staging') { 'debug' } else { 'release' }
if (-not (Get-ConfigValue "$kind.assetUrl")) { $problems += "There is no $target to build for yet: $kind.assetUrl is blank in ikaika_scripts.env." }
# As the project estimator's: a dry run sends nothing, so it needs no production box, and without an upload there is nothing to try.
if (-not $upload -and $dryRun) {
    Write-Output '::warning title=Upload options ignored::Dry run applies only with "Upload to the server" ticked.'
    $dryRun = $false
}
if ($upload -and -not $dryRun -and $target -eq 'production' -and -not (Test-Answer 'confirm_production')) { $problems += 'Tick "Yes, upload to PRODUCTION" to upload to production.' }

if ($problems.Count) {
    foreach ($p in $problems) { Write-Output "::error title=Build release::$p" }
    exit 1
}

$arguments = [ordered]@{ Debug = $target -eq 'staging' }
# build-release bumps the version as it builds, as the estimator's does; tag-version commits and tags it after.
if ($tagging) { $arguments.Bump = "$bump" }
$arguments | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath $PlanFile -Encoding utf8
$deploy = $null
if ($upload) {
    $deploy = [ordered]@{ Debug = $arguments.Debug; Force = $true }
    if ($dryRun) { $deploy.DryRun = $true }
}

Set-JobVariable 'BUILD_PLAN' $PlanFile
Set-JobVariable 'BUILD_DEBUG' "$($arguments.Debug)".ToLower()
Set-JobVariable 'VERSION_BUMP' $(if ($tagging) { "$bump" } else { '' })
Set-JobVariable 'UPLOADING' $(if ($upload) { 'true' } else { 'false' })
Set-JobVariable 'DEPLOY_PLAN' $(if ($deploy) { $deploy | ConvertTo-Json -Depth 3 -Compress } else { '' })
Set-JobVariable 'PURPOSES' $(if ($upload) { "deploy-$target" } else { '' })
Set-JobVariable 'TAGGING' $(if ($tagging) { 'true' } else { 'false' })
# A release hangs on the tag a bump pushes, so a build that keeps its version stays an artifact.
$releasing = $githubRelease -and $tagging
if ($githubRelease -and -not $tagging) { Write-Output '::notice title=No GitHub release::A release hangs on a new version tag, and version is same, so the build is kept as an artifact instead.' }
Set-JobVariable 'RELEASING' $(if ($releasing) { 'true' } else { 'false' })
# A day when the GitHub release keeps the files, three days otherwise, as the estimator's artifacts.
Set-JobVariable 'RETENTION' $(if ($releasing) { '1' } else { '3' })
Set-JobVariable 'ARTIFACT_NAME' ("backend-$target-$refName" -replace '[^A-Za-z0-9._-]', '-')

$stages = @('| 1 - Check | These choices, then GitHub synced from Bitbucket and the toolkit fetched |')
$stages += "| $($stages.Count + 1) - Build | $(if ($tagging) { "VERSION bumped $bump, uncommitted, then " })the $target archive, its fonts served from $(Get-ConfigValue "$kind.assetUrl") |"
if ($tagging) { $stages += "| $($stages.Count + 1) - Tag | Once the build is good: the changelog filed under the new version, committed and tagged $(if ($target -eq 'staging') { 'as a staging build' } else { 'as a production release' }) by github-actions[bot] on Bitbucket |" }
if ($upload) { $stages += "| $($stages.Count + 1) - Upload | $(if ($tagging) { 'Once the tag is on Bitbucket: ' })to $target with deploy-admin.sh, composer, the swap, migrations, then the health check |" }
if ($tagging) { $stages += "| $($stages.Count + 1) - GitHub | The version commit and tag pushed to GitHub as well |" }
if ($releasing) { $stages += "| $($stages.Count + 1) - GitHub release | $(if ($target -eq 'staging') { 'A prerelease' } else { 'The latest release' }) keeps the build and this version's changelog, so no artifact takes Actions storage |" }
$summary = @('### Build release', '', '| Stage | What it does |', '| --- | --- |') + $stages + @('', '| Choice | Value |', '| --- | --- |')
$summary += "| Version | $(if ($tagging) { $bump } else { 'same, nothing committed or tagged' }) |"
$summary += "| Kept as | $(if ($releasing) { 'a GitHub release, and an artifact for a day' } else { 'an artifact, 3 days' }) |"
foreach ($key in $arguments.Keys) { $summary += "| $key | $($arguments[$key]) |" }
if ($deploy) { $summary += "| Upload | $(@($deploy.Keys | ForEach-Object { "$_ $($deploy[$_])" }) -join ', ') |" }
$summary += "| Secrets for | $(if ($upload) { "deploy-$target" } else { 'nothing' }) |", ''
if ($env:GITHUB_STEP_SUMMARY) { Add-Content -LiteralPath $env:GITHUB_STEP_SUMMARY -Value $summary }
$summary | Select-Object -Skip 2 | ForEach-Object { Write-Output $_ }
