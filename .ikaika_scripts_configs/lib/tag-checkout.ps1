# Checking a release tag out to build it as it was (2026-10-21, user: "choose which version to test"): build-release -FromTag, its
# dialog's Build from list, ci-local -BuildFrom and the workflow's build_from share it. The same file in the portal's backend and its
# frontend, so the two rebuild a tag alike.
#
# A release tag is the toolkit's: v<x.y.z>+<build> for staging and prereleases, with -released for production. Tags are listed in
# version order, as Get-TagsInVersionOrder keeps it, never by creation date, which a tag added late scrambles; anything else a person
# tagged is not a release and is left out. A tag's tree is asked whether it has what a build needs, in one git cat-file, so one from
# before VERSION - v0.0.0+0 - is never offered and is refused before a worktree exists.
#
# The checkout is git's own worktree, detached under %TEMP% and pruned before and after, rather than the toolkit's New-TagWorktree,
# which copies the secrets beside it and nests .ikaika_scripts_configs inside itself: a rebuild takes the tag's sources and today's
# scripts. git writes progress to stderr, which 'Stop' would turn into a throw, so each call runs under 'Continue' (Invoke-TagGit).

. (Join-Path $PSScriptRoot '..\..\decoupled-script\lib\release-git.ps1')

$script:ReleaseTagPattern = '^v\d+\.\d+\.\d+\+\d+(-released)?$'

function Invoke-TagGit([string]$Root, [string[]]$GitArgs, [string[]]$InputLines = @()) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = if ($InputLines.Count) { $InputLines | & git -C $Root @GitArgs 2>$null } else { & git -C $Root @GitArgs 2>$null }
        $code = $LASTEXITCODE
        # $null piped on would come back as one empty line.
        $lines = if ($null -eq $out) { @() } else { @($out | ForEach-Object { "$_" }) }
        return [pscustomobject]@{ Lines = $lines; Code = $code }
    } finally {
        $ErrorActionPreference = $previous
    }
}

function Test-ReleaseTagName([string]$Name) {
    return [regex]::IsMatch("$Name", $script:ReleaseTagPattern)
}

# The release tags here, newest version first; -Needs leaves out a tag whose tree lacks any of those paths.
function Get-VersionTags {
    param([Parameter(Mandatory)][string]$Root, [string[]]$Needs = @())
    $listed = Invoke-TagGit $Root @('tag', '--list', 'v*')
    $names = @($listed.Lines | ForEach-Object { $_.Trim() } | Where-Object { Test-ReleaseTagName $_ })
    if ($names.Count -eq 0) { return , @() }
    $ordered = Get-TagsInVersionOrder $names
    if ($Needs.Count -eq 0) { return , $ordered }
    $queries = @(foreach ($tag in $ordered) { foreach ($path in $Needs) { "refs/tags/$($tag):$path" } })
    $answers = (Invoke-TagGit $Root @('cat-file', '--batch-check') $queries).Lines
    if ($answers.Count -ne $queries.Count) { return , @() }
    $kept = [Collections.Generic.List[string]]::new()
    $index = 0
    foreach ($tag in $ordered) {
        $complete = $true
        foreach ($path in $Needs) {
            if ($answers[$index] -match ' missing$') { $complete = $false }
            $index++
        }
        if ($complete) { $kept.Add($tag) }
    }
    return , @($kept)
}

# Whether the tag is here, after fetching that one tag from the remote when it is not; a missing remote is not an error here.
function Resolve-LocalTag {
    param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Tag, [string]$Remote = 'origin')
    $verify = @('rev-parse', '-q', '--verify', "refs/tags/$Tag^{commit}")
    if ((Invoke-TagGit $Root $verify).Code -eq 0) { return $true }
    $prompt = $env:GIT_TERMINAL_PROMPT
    $env:GIT_TERMINAL_PROMPT = '0'
    try { [void](Invoke-TagGit $Root @('fetch', '--quiet', '--no-tags', $Remote, "refs/tags/$($Tag):refs/tags/$Tag")) }
    finally { $env:GIT_TERMINAL_PROMPT = $prompt }
    return (Invoke-TagGit $Root $verify).Code -eq 0
}

# Why the tag cannot be rebuilt, or '' when it can: it must be a release tag that is here, and carry every path -Needs names.
function Test-TagBuildable {
    param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Tag, [string[]]$Needs = @())
    if (-not (Test-ReleaseTagName $Tag)) { return "$Tag is not a release tag: one is v<x.y.z>+<build>, with -released for production's." }
    if (-not (Resolve-LocalTag -Root $Root -Tag $Tag)) { return "There is no tag $Tag here or on origin - git fetch --tags, or pick one git tag lists." }
    if ($Needs.Count -eq 0) { return '' }
    $queries = @($Needs | ForEach-Object { "refs/tags/$($Tag):$_" })
    $answers = (Invoke-TagGit $Root @('cat-file', '--batch-check') $queries).Lines
    for ($index = 0; $index -lt $Needs.Count; $index++) {
        if ($index -ge $answers.Count -or $answers[$index] -match ' missing$') {
            if ($index -eq 0) { return "$Tag predates $($Needs[0]), so it has no version to build as." }
            return "$Tag has no $($Needs[$index]), which a build needs."
        }
    }
    return ''
}

# The version a tag carries: the first line of a VERSION file that is one, or the version a JSON manifest names.
function Get-TagManifestVersion {
    param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Tag, [string]$Path = 'VERSION')
    $shown = Invoke-TagGit $Root @('show', "refs/tags/$($Tag):$Path")
    if ($shown.Code -ne 0) { return '' }
    if ($Path -like '*.json') {
        try { return "$((($shown.Lines -join "`n") | ConvertFrom-Json).version)".Trim() } catch { return '' }
    }
    return "$($shown.Lines | ForEach-Object { $_.Trim().TrimStart([char]0xFEFF) } | Where-Object { $_ -and -not $_.StartsWith('#') } | Select-Object -First 1)"
}

# The tag's sources in a detached worktree of their own under %TEMP%; throws when git cannot make it.
function New-TagCheckout {
    param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$Tag, [string]$Prefix = 'release')
    [void](Invoke-TagGit $Root @('worktree', 'prune'))
    $name = "$Prefix-$($Tag -replace '[^A-Za-z0-9.-]', '-')-$([guid]::NewGuid().ToString('N').Substring(0, 8))"
    $path = Join-Path ([IO.Path]::GetTempPath()) $name
    $added = Invoke-TagGit $Root @('worktree', 'add', '--detach', '--quiet', $path, "refs/tags/$Tag")
    if ($added.Code -ne 0 -or -not (Test-Path -LiteralPath $path)) { throw "git could not check $Tag out into a worktree." }
    return $path
}

# The worktree gone, and git's record of it with it; a folder git would not remove is removed by hand.
function Remove-TagCheckout {
    param([Parameter(Mandatory)][string]$Root, [string]$Path)
    if (-not $Path) { return }
    [void](Invoke-TagGit $Root @('worktree', 'remove', '--force', $Path))
    if (Test-Path -LiteralPath $Path) { Remove-Item -LiteralPath $Path -Recurse -Force -ErrorAction SilentlyContinue }
    [void](Invoke-TagGit $Root @('worktree', 'prune'))
}

# The files added between two refs, under one folder when it is named: the migrations a release lacks, say.
function Get-PathsAddedSince {
    param([Parameter(Mandatory)][string]$Root, [Parameter(Mandatory)][string]$From, [string]$To = 'HEAD', [string]$Under = '')
    $arguments = @('diff', '--name-only', '--diff-filter=A', $From, $To)
    if ($Under) { $arguments += @('--', $Under) }
    $diff = Invoke-TagGit $Root $arguments
    if ($diff.Code -ne 0) { return , @() }
    return , @($diff.Lines | ForEach-Object { $_.Trim() } | Where-Object { $_ })
}

# The +<build> of a version, which rises across every version name; $null for one that has none.
function Get-VersionBuildNumber([string]$Version) {
    if ("$Version" -match '\+(\d+)') { return [int]$Matches[1] }
    return $null
}
