# Release tags and Build from a tag (2026-10-21, user), checked against a repository made for the purpose and its own origin, so
# nothing here reaches this checkout's tags or remote: the tags in version order, what can be rebuilt and why not, a worktree that
# leaves nothing behind, the migrations a release lacks; which build-release runs are refused; the FromTag providers, lifted by AST
# and run alone in a child as the form runs them; and deploy-admin.sh's older-release guard in Git Bash, skipped without it.
# Plain asserts, so it runs wherever pwsh and git do: .\script.ps1 check-all runs it (checks.8).
#
#     pwsh -NoProfile -File .ikaika_scripts_configs/tests/release-tags.tests.ps1
$ErrorActionPreference = 'Stop'
$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent
. (Join-Path $configsDir 'lib\tag-checkout.ps1')
. (Join-Path $configsDir 'lib\release-policy.ps1')

$failures = [Collections.Generic.List[string]]::new()
function Assert-Equal($Actual, $Expected, [string]$What) {
    if ("$Actual" -cne "$Expected") { $failures.Add("$What`n      expected: $Expected`n      actual:   $Actual") }
}
function Assert-True([bool]$Condition, [string]$What) { if (-not $Condition) { $failures.Add($What) } }

# A setup step that fails is fatal: a test read against a repository that is not what it seems is a confident wrong answer.
function Invoke-SetupGit([string]$Dir, [string[]]$GitArgs) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & git -C $Dir @GitArgs 2>&1
        if ($LASTEXITCODE -ne 0) { throw "setup: git $($GitArgs -join ' ') failed in ${Dir}: $out" }
    } finally { $ErrorActionPreference = $previous }
}

$work = Join-Path ([IO.Path]::GetTempPath()) "release-tags-$([guid]::NewGuid().ToString('N').Substring(0, 8))"
$upstream = Join-Path $work 'upstream'
$clone = Join-Path $work 'clone'
try {
    New-Item -ItemType Directory -Force -Path $upstream | Out-Null
    Invoke-SetupGit $upstream @('init', '--quiet', '--initial-branch=main')
    foreach ($pair in @('user.email=tests@example.com', 'user.name=Release tags test', 'commit.gpgsign=false', 'tag.gpgsign=false')) {
        $key, $value = $pair -split '=', 2
        Invoke-SetupGit $upstream @('config', $key, $value)
    }
    function Write-UpstreamFile([string]$Path, [string]$Text) {
        $full = Join-Path $upstream $Path
        New-Item -ItemType Directory -Force -Path (Split-Path $full -Parent) | Out-Null
        [IO.File]::WriteAllText($full, $Text)
    }
    function Save-Upstream([string]$Message, [string[]]$Tags) {
        Invoke-SetupGit $upstream @('add', '-A')
        Invoke-SetupGit $upstream @('commit', '--quiet', '-m', $Message)
        foreach ($tag in $Tags) { Invoke-SetupGit $upstream @('tag', '-a', $tag, '-m', $tag) }
    }
    Write-UpstreamFile 'README.md' "from before VERSION`n"
    Save-Upstream 'first' @('v0.0.0+0')
    Write-UpstreamFile 'VERSION' "1.0.0+9`n"
    Write-UpstreamFile 'database/migrations/2026_01_01_000000_first.php' "<?php`n"
    Save-Upstream 'v1.0.0+9' @('v1.0.0+9')
    Write-UpstreamFile 'VERSION' "1.0.0+10`n"
    Save-Upstream 'v1.0.0+10' @('v1.0.0+10', 'v1.0.0+10-released')
    Write-UpstreamFile 'VERSION' "# the version`n1.2.0+11`n"
    Write-UpstreamFile 'database/migrations/2026_02_01_000000_second.php' "<?php`n"
    Save-Upstream 'v1.2.0+11' @('v1.2.0+11')
    Invoke-SetupGit $upstream @('tag', 'demo')
    # An old version tagged afterwards, dated after every other, as early tags here were: date order would put it first.
    $env:GIT_COMMITTER_DATE = '2030-01-01T00:00:00Z'
    try { Invoke-SetupGit $upstream @('tag', '-a', 'v1.0.0+8', 'refs/tags/v1.0.0+9^{commit}', '-m', 'v1.0.0+8') } finally { Remove-Item Env:GIT_COMMITTER_DATE }

    # The clone has every tag but the newest, which only origin has.
    Invoke-SetupGit $work @('clone', '--quiet', '--no-tags', $upstream, $clone)
    foreach ($tag in 'v0.0.0+0', 'v1.0.0+8', 'v1.0.0+9', 'v1.0.0+10', 'v1.0.0+10-released', 'demo') {
        Invoke-SetupGit $clone @('fetch', '--quiet', 'origin', "refs/tags/$($tag):refs/tags/$tag")
    }
    $present = @(& git -C $clone tag --list | Sort-Object)
    if (($present -join ',') -cne 'demo,v0.0.0+0,v1.0.0+10,v1.0.0+10-released,v1.0.0+8,v1.0.0+9') { throw "setup: the clone's tags are $($present -join ',')" }

    # ---------------------------------------------------------------- the tags, and what can be rebuilt
    Assert-Equal ((Get-VersionTags -Root $clone) -join ',') 'v1.0.0+10,v1.0.0+10-released,v1.0.0+9,v1.0.0+8,v0.0.0+0' 'release tags newest version first, a -released after its twin, demo left out'
    Assert-True (Resolve-LocalTag -Root $clone -Tag 'v1.2.0+11') 'a tag only origin has is fetched'
    Assert-Equal ((Get-VersionTags -Root $clone -Needs 'VERSION') -join ',') 'v1.2.0+11,v1.0.0+10,v1.0.0+10-released,v1.0.0+9,v1.0.0+8' 'with -Needs VERSION, the tag from before VERSION is not offered'
    Assert-Equal (Test-TagBuildable -Root $clone -Tag 'v0.0.0+0' -Needs 'VERSION', 'README.md') 'v0.0.0+0 predates VERSION, so it has no version to build as.' 'a tag from before VERSION is refused for it'
    Assert-Equal (Test-TagBuildable -Root $clone -Tag 'v1.0.0+9' -Needs 'VERSION', 'artisan') 'v1.0.0+9 has no artisan, which a build needs.' 'a tag without a later path names it'
    Assert-Equal (Test-TagBuildable -Root $clone -Tag 'demo') 'demo is not a release tag: one is v<x.y.z>+<build>, with -released for production''s.' 'a tag a person made is not a release'
    Assert-Equal (Test-TagBuildable -Root $clone -Tag 'v9.9.9+99') 'There is no tag v9.9.9+99 here or on origin - git fetch --tags, or pick one git tag lists.' 'a tag nobody has'
    Assert-Equal (Test-TagBuildable -Root $clone -Tag 'v1.2.0+11' -Needs 'VERSION') '' 'a release tag with a VERSION can be rebuilt'
    Assert-Equal (Get-TagManifestVersion -Root $clone -Tag 'v1.2.0+11') '1.2.0+11' 'the version a tag carries, past a comment'

    $tree = New-TagCheckout -Root $clone -Tag 'v1.0.0+9' -Prefix 'release-tags-test'
    Assert-Equal ([IO.File]::ReadAllText((Join-Path $tree 'VERSION')).Trim()) '1.0.0+9' 'the worktree holds the tag as it was'
    Remove-TagCheckout -Root $clone -Path $tree
    Assert-True (-not (Test-Path -LiteralPath $tree)) 'the worktree is gone afterwards'
    Assert-Equal @(& git -C $clone worktree list --porcelain | Where-Object { $_ -like 'worktree *' }).Count 1 'and git keeps no record of it'
    Assert-Equal ((Get-PathsAddedSince -Root $clone -From 'refs/tags/v1.0.0+9' -To 'refs/tags/v1.2.0+11' -Under 'database/migrations') -join ',') 'database/migrations/2026_02_01_000000_second.php' 'the migrations a release lacks'
    Assert-Equal (Get-VersionBuildNumber '1.0.0+10') '10' 'a version''s build number'
    Assert-True ($null -eq (Get-VersionBuildNumber '1.0.0')) 'a version without one'

    # ---------------------------------------------------------------- the FromTag providers, run alone as the form runs them
    foreach ($relative in '.ikaika_scripts_configs\lib\tag-checkout.ps1', 'decoupled-script\lib\release-git.ps1') {
        $copy = Join-Path $clone $relative
        New-Item -ItemType Directory -Force -Path (Split-Path $copy -Parent) | Out-Null
        Copy-Item -LiteralPath (Join-Path $repoRoot $relative) -Destination $copy
    }
    [IO.File]::WriteAllText((Join-Path $clone 'ikaika_scripts.env'), "app.name=release-tags-test`n")
    $command = [Management.Automation.Language.Parser]::ParseFile((Join-Path $configsDir 'commands\build-release.ps1'), [ref]$null, [ref]$null)
    $lifted = @($command.FindAll({ param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -like 'Get-Dialog*' }, $true) | ForEach-Object { $_.Extent.Text })
    Assert-Equal $lifted.Count 3 'build-release names its choice parameters, its choices and its refresh'
    $alone = Join-Path $work 'provider.ps1'
    [IO.File]::WriteAllText($alone, (@("Set-Location -LiteralPath '$clone'") + $lifted + @(
        "(Get-DialogChoiceParameters) -join ','",
        "(Get-DialogChoices 'FromTag') -join ','",
        "(Get-DialogChoices 'Keep').Count"
    )) -join "`n")
    $answers = @(& (Get-Process -Id $PID).Path -NoProfile -File $alone 2>&1 | ForEach-Object { "$_" })
    Assert-Equal ($answers -join ' | ') 'FromTag | v1.2.0+11,v1.0.0+10,v1.0.0+10-released,v1.0.0+9,v1.0.0+8 | 0' 'the providers, alone: FromTag, its tags newest first with a VERSION each, and nothing for another parameter'

    # ---------------------------------------------------------------- deploy-admin.sh's older-release guard
    $bash = $null
    $gitCommand = Get-Command git -ErrorAction SilentlyContinue
    if ($gitCommand) {
        $gitRoot = Split-Path $gitCommand.Source -Parent
        while ($gitRoot -and -not (Test-Path -LiteralPath (Join-Path $gitRoot 'bin\bash.exe'))) { $gitRoot = Split-Path $gitRoot -Parent }
        if ($gitRoot) { $bash = Join-Path $gitRoot 'bin\bash.exe' }
    }
    $script = [IO.File]::ReadAllText((Join-Path $repoRoot 'deployment\server\deploy-admin.sh'))
    $block = [regex]::Match($script, '(?ms)^# >>> older-release\r?\n(.*?)^# <<< older-release')
    Assert-True $block.Success 'deploy-admin.sh marks its older-release block'
    if (-not $bash) {
        Write-Host '  SKIP  the older-release guard in bash: no Git Bash here.'
    } elseif ($block.Success) {
        & $bash -n (Join-Path $repoRoot 'deployment\server\deploy-admin.sh')
        Assert-Equal $LASTEXITCODE 0 'deploy-admin.sh parses'
        $guard = Join-Path $work 'older-release.sh'
        [IO.File]::WriteAllText($guard, "set -euo pipefail`n" + ($block.Groups[1].Value -replace "`r", '') + "echo carried-on`n")
        function Invoke-Guard([string]$Running, [string]$Incoming, [string]$Channel, [string]$AllowOlder = '') {
            $root = Join-Path $work "server-$([guid]::NewGuid().ToString('N').Substring(0, 6))"
            foreach ($side in @(@{ Dir = 'app'; Version = $Running; Migrations = @('2026_01_01_000000_first.php', '2026_02_01_000000_second.php') }, @{ Dir = 'app-new'; Version = $Incoming; Migrations = @('2026_01_01_000000_first.php') })) {
                $migrations = Join-Path $root "$($side.Dir)\database\migrations"
                New-Item -ItemType Directory -Force -Path $migrations | Out-Null
                foreach ($name in $side.Migrations) { [IO.File]::WriteAllText((Join-Path $migrations $name), '') }
                if ($side.Version) { [IO.File]::WriteAllText((Join-Path $root "$($side.Dir)\VERSION"), "$($side.Version)`n") }
            }
            $unix = { param($Path) ($Path -replace '\\', '/') -replace '^([A-Za-z]):', { "/$($_.Groups[1].Value.ToLower())" } }
            $env:APP_DIR = & $unix (Join-Path $root 'app'); $env:NEW = & $unix (Join-Path $root 'app-new')
            $env:API_CHANNEL = $Channel; $env:ALLOW_OLDER = $AllowOlder
            $out = @(& $bash (& $unix $guard) 2>&1 | ForEach-Object { "$_" })
            return [pscustomobject]@{ Code = $LASTEXITCODE; Out = $out -join "`n"; NewKept = Test-Path -LiteralPath (Join-Path $root 'app-new') }
        }
        $staging = Invoke-Guard '1.0.1+5' '1.0.0+2' 'staging'
        Assert-Equal $staging.Code 0 'staging installs an older release'
        Assert-True ($staging.Out -match '1\.0\.0\+2 is older than 1\.0\.1\+5' -and $staging.Out -match '2026_02_01_000000_second\.php' -and $staging.Out -match 'carried-on') 'and says so, naming the migration it lacks'
        $production = Invoke-Guard '1.0.1+5' '1.0.0+2' 'production'
        Assert-True ($production.Code -ne 0 -and $production.Out -match 'Production only moves forward' -and -not $production.NewKept) 'production refuses it, and removes what it unpacked'
        $allowed = Invoke-Guard '1.0.1+5' '1.0.0+2' 'production' '1'
        Assert-True ($allowed.Code -eq 0 -and $allowed.Out -match 'carried-on') 'production takes it with ALLOW_OLDER=1'
        $newer = Invoke-Guard '1.0.0+2' '1.0.1+5' 'production'
        Assert-True ($newer.Code -eq 0 -and $newer.Out -notmatch 'Older release') 'a newer release goes up without a word'
        $first = Invoke-Guard '' '1.0.0+2' 'production'
        Assert-True ($first.Code -eq 0 -and $first.Out -match 'carried-on') 'a server running no versioned release says nothing'
        foreach ($name in 'APP_DIR', 'NEW', 'API_CHANNEL', 'ALLOW_OLDER') { Remove-Item -LiteralPath "env:$name" -ErrorAction SilentlyContinue }
    }
} finally {
    if (Test-Path -LiteralPath $work) {
        & git -C $clone worktree prune 2>$null
        Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# ---------------------------------------------------------------- what build-release refuses before building
$rebuild = @{ Debug = $true; FromTag = 'v1.0.0+9' }
Assert-Equal (Get-BuildReleaseRefusal @rebuild -Deploy $true) '' 'a tag rebuilt and installed on staging'
Assert-Equal (Get-BuildReleaseRefusal @rebuild -Bump 'patch') 'A rebuild from a tag never bumps: leave -Bump off, or drop -FromTag.' 'a rebuild never bumps'
Assert-Equal (Get-BuildReleaseRefusal @rebuild -Bump 'same') '' 'same is no bump'
Assert-Equal (Get-BuildReleaseRefusal @rebuild -Tag $true) 'v1.0.0+9 is tagged already, so a rebuild of it has nothing to tag: drop -Tag.' 'a rebuild has nothing to tag'
Assert-True ((Get-BuildReleaseRefusal -Debug $false -FromTag 'v1.0.0+9') -like 'Production only moves forward*') 'a tag is never rebuilt for production'
Assert-True ((Get-BuildReleaseRefusal -Debug $false -Deploy $true) -like 'A production upload is a release*') 'production takes no untagged upload'
Assert-True ((Get-BuildReleaseRefusal -Debug $false -Deploy $true -Bump 'keep') -like 'A production upload is a release*') 'nor a bump left untagged'
Assert-Equal (Get-BuildReleaseRefusal -Debug $false -Deploy $true -Bump 'keep' -Tag $true) '' 'a production release, bumped and tagged'
Assert-Equal (Get-BuildReleaseRefusal -Debug $false -Deploy $true -DryRun $true) '' 'a production dry run sends nothing'
Assert-Equal (Get-BuildReleaseRefusal -Debug $false) '' 'a production build that stays here'
Assert-True (Test-OlderRelease '1.0.0+2' '1.0.1+5') 'an older build number is older'
Assert-True (-not (Test-OlderRelease '1.2.0+6' '1.0.1+5')) 'a newer one is not'
Assert-True (-not (Test-OlderRelease '1.0.0' '1.0.1+5')) 'one without a build number is not said to be'

# ---------------------------------------------------------------- the Build release dialog's arguments and markup
. (Join-Path $configsDir 'ui\build-backend.ps1')
$picked = { param([hashtable]$Overrides) $base = @{ Keep = 10; Debug = $true; FixTarget = $false; FromTag = ''; BumpNeeded = $false; Version = 'same'; Tag = $false; Deploy = $false; DryRun = $false; Clean = $false }; foreach ($key in $Overrides.Keys) { $base[$key] = $Overrides[$key] }; $base }
Assert-Equal (Get-BuildBackendArguments (& $picked @{})).Line '-Keep 10 -Debug' 'the dialog: a staging build'
Assert-Equal (Get-BuildBackendArguments (& $picked @{ FromTag = 'v1.0.0+3'; BumpNeeded = $true; Version = 'patch'; Tag = $true; Deploy = $true })).Line '-Keep 10 -Debug -FromTag v1.0.0+3 -Deploy' 'a picked tag drops any version and tag'
$release = Get-BuildBackendArguments (& $picked @{ Debug = $false; BumpNeeded = $true; Version = 'keep'; Tag = $true; Deploy = $true; Clean = $true })
Assert-Equal $release.Line '-Keep 10 -Bump keep -Tag -Deploy -Clean' 'a production release'
Assert-Equal (($release.Splat.Keys | ForEach-Object { "$_=$($release.Splat[$_])" }) -join ',') 'Keep=10,Bump=keep,Tag=True,Deploy=True,Clean=True' 'and the same as the splat the caller runs'
$markup = [xml][IO.File]::ReadAllText((Join-Path $configsDir 'ui\build-backend.xaml'))
$named = @($markup.SelectNodes('//*[@*[local-name()="Name"]]') | ForEach-Object { $_.GetAttribute('Name', 'http://schemas.microsoft.com/winfx/2006/xaml') })
$unnamed = @(Get-BuildReleaseUiContract | Where-Object { $_ -notin $named })
Assert-Equal ($unnamed -join ',') '' 'every control the dialog reaches for is in its markup'

if ($failures.Count) {
    Write-Host "$($failures.Count) failed:" -ForegroundColor Red
    foreach ($failure in $failures) { Write-Host "  - $failure" -ForegroundColor Red }
    exit 1
}
Write-Host 'release-tags: every check passed.' -ForegroundColor Green
exit 0
