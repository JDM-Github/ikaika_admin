# Brings this GitHub repo up to date from Bitbucket, where the code actually lives, then moves the job's checkout to the commit it will ship.
# Every branch and tag is mirrored and Bitbucket always wins: new ones are added, moved ones are forced, deleted ones are removed.
# BITBUCKET_REPO_TOKEN reads Bitbucket and GH_MIRROR_TOKEN pushes here, both through GIT_CONFIG_*, so neither is ever on a command line.
# GH_ rather than GITHUB_, because GitHub refuses secret names that start with GITHUB_.
# It refuses to run outside CI, because it replaces the working tree.
param(
    # The Bitbucket repo to mirror; defaults to mirror.source in ikaika_scripts.env.
    [string]$Source = '',
    # This repo on GitHub; defaults to the one the job runs in.
    [string]$Target = '',
    # The branch or tag to ship; defaults to the one the run was started from.
    [string]$Ref = $env:GITHUB_REF,
    # GitHub's default branch, which a mirror must never try to delete.
    [string]$DefaultBranch = $env:DEFAULT_BRANCH
)

$ErrorActionPreference = 'Stop'

function Stop-Sync([string]$Title, [string]$Message) {
    Write-Output "::error title=$Title::$Message"
    exit 1
}

if ($env:GITHUB_ACTIONS -ne 'true' -and $env:CI -ne 'true') {
    Write-Output 'sync-from-bitbucket.ps1 replaces the working tree, so it runs only on a CI runner.'
    exit 1
}

$repoRoot = Split-Path $PSScriptRoot -Parent
if (-not $Source) {
    $line = Get-Content -LiteralPath (Join-Path $repoRoot 'ikaika_scripts.env') | Where-Object { $_ -match '^mirror\.source=' } | Select-Object -First 1
    $Source = "$($line -replace '^mirror\.source=', '')".Trim()
}
# A username left in the URL would be sent instead of the token's own.
$Source = $Source -replace '^(https://)[^@/]+@', '$1'
if (-not $Target -and $env:GITHUB_REPOSITORY) { $Target = "$($env:GITHUB_SERVER_URL)/$($env:GITHUB_REPOSITORY).git" }
if (-not $Source) { Stop-Sync 'No mirror source' 'Set mirror.source in ikaika_scripts.env to this repo''s Bitbucket URL.' }
if (-not $Target) { Stop-Sync 'No mirror target' 'GITHUB_REPOSITORY is not set, so there is no GitHub repo to update.' }
if (-not $Ref) { Stop-Sync 'No ref' 'GITHUB_REF is not set, so there is nothing to ship.' }

# Each https remote gets its token for this process only, and no credential helper is asked or told anything.
# core.longpaths because a bare clone's object paths can pass Windows' 260-character limit under a deep temp folder.
$gitConfig = [ordered]@{ 'credential.helper' = ''; 'core.longpaths' = 'true' }
if ($Source -like 'https://*') {
    if (-not $env:BITBUCKET_REPO_TOKEN) { Stop-Sync 'Missing secret BITBUCKET_REPO_TOKEN' 'A Bitbucket token that can read this repo on Bitbucket. Add it under Settings > Secrets and variables > Actions.' }
    $user = if ($env:BITBUCKET_REPO_USER) { $env:BITBUCKET_REPO_USER } else { 'x-token-auth' }
    $sourceBase = ([uri]$Source).GetLeftPart('Authority') + '/'
    $gitConfig["url.$($sourceBase.Replace('://', "://$($user):$($env:BITBUCKET_REPO_TOKEN)@")).insteadOf"] = $sourceBase
}
if ($Target -like 'https://*') {
    if (-not $env:GH_MIRROR_TOKEN) { Stop-Sync 'Missing secret GH_MIRROR_TOKEN' 'A fine-grained GitHub token with Contents and Workflows write on this repo. Add it under Settings > Secrets and variables > Actions.' }
    $targetBase = ([uri]$Target).GetLeftPart('Authority') + '/'
    $gitConfig["url.$($targetBase.Replace('://', "://x-access-token:$($env:GH_MIRROR_TOKEN)@")).insteadOf"] = $targetBase
}
$index = 0
foreach ($key in $gitConfig.Keys) {
    Set-Item -LiteralPath "env:GIT_CONFIG_KEY_$index" -Value $key
    Set-Item -LiteralPath "env:GIT_CONFIG_VALUE_$index" -Value $gitConfig[$key]
    $index++
}
$env:GIT_CONFIG_COUNT = "$index"
$env:GIT_TERMINAL_PROMPT = '0'

$tempRoot = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [IO.Path]::GetTempPath() }
$mirror = Join-Path $tempRoot "bitbucket-mirror-$([Guid]::NewGuid().ToString('N').Substring(0, 8)).git"
try {
    git clone --bare --quiet $Source $mirror
    if ($LASTEXITCODE -ne 0) { Stop-Sync 'Bitbucket fetch failed' "git could not read $Source. Check that BITBUCKET_REPO_TOKEN can read it." }

    $heads = @(git -C $mirror for-each-ref --format='%(refname:strip=2)' refs/heads)
    $tags = @(git -C $mirror for-each-ref --format='%(refname:strip=2)' refs/tags)
    if ($DefaultBranch -and $heads -notcontains $DefaultBranch) {
        Stop-Sync 'Default branch missing on Bitbucket' "Bitbucket has no '$DefaultBranch', GitHub's default branch, and mirroring would try to delete it. Set GitHub's default branch to one that Bitbucket has."
    }

    # Atomic, so GitHub ends up either matching Bitbucket or exactly as it was, never half of each.
    git -C $mirror push --atomic --force --prune $Target 'refs/heads/*:refs/heads/*' 'refs/tags/*:refs/tags/*'
    if ($LASTEXITCODE -ne 0) { Stop-Sync 'GitHub update failed' "git could not update $Target. Check that GH_MIRROR_TOKEN has Contents and Workflows write on this repo, and that no branch rule forbids force pushes." }
    Write-Output "Mirrored $($heads.Count) branch(es) and $($tags.Count) tag(s) from Bitbucket."

    $sha = "$(git -C $mirror rev-parse --verify --quiet "$Ref^{commit}")".Trim()
    if (-not $sha) { Stop-Sync 'Not on Bitbucket' "$Ref is not on Bitbucket, so there is nothing to ship from it; the mirror has removed it from GitHub as well." }

    # The steps after this one run the shipped commit's own scripts, so a commit without them cannot be deployed from here.
    foreach ($file in @('.ikaika_scripts_configs/fetch-toolkit.ps1', '.ikaika_scripts_configs/commands/secrets.ps1', '.ikaika_scripts_configs/secrets.json')) {
        git -C $mirror cat-file -e "$($sha):$file" 2>$null
        if ($LASTEXITCODE -ne 0) { Stop-Sync 'Commit predates the deploy tooling' "$Ref at $($sha.Substring(0, 7)) has no $file. Merge the default branch into it on Bitbucket first." }
    }

    git -C $repoRoot fetch --quiet --force $mirror "+refs/tags/*:refs/tags/*" $Ref
    if ($LASTEXITCODE -ne 0) { Stop-Sync 'Checkout failed' "Could not bring $Ref from the Bitbucket mirror into the job." }
    $previous = "$(git -C $repoRoot rev-parse HEAD)".Trim()
    git -C $repoRoot checkout --quiet --force --detach $sha
    if ($LASTEXITCODE -ne 0) { Stop-Sync 'Checkout failed' "Could not check out $sha." }

    # This run already started from the workflow as GitHub had it, so a workflow change from Bitbucket applies from the next run.
    git -C $repoRoot diff --quiet $previous $sha -- .github/workflows
    if ($LASTEXITCODE -ne 0) { Write-Output '::warning title=Workflow changed on Bitbucket::This run used the workflow as GitHub had it before the update; the new version applies from the next run.' }

    $short = $sha.Substring(0, 7)
    Write-Output "Shipping $Ref at $short, as Bitbucket has it."
    if ($env:GITHUB_ENV) { Add-Content -LiteralPath $env:GITHUB_ENV -Value "SHIPPED_SHA=$sha" }
    if ($env:GITHUB_STEP_SUMMARY) { Add-Content -LiteralPath $env:GITHUB_STEP_SUMMARY -Value "Shipped ``$Ref`` at ``$short`` from Bitbucket, after mirroring $($heads.Count) branch(es) and $($tags.Count) tag(s)." }
} finally {
    Remove-Item -LiteralPath $mirror -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath env:GIT_CONFIG_COUNT -ErrorAction SilentlyContinue
    for ($i = 0; $i -lt $index; $i++) {
        Remove-Item -LiteralPath "env:GIT_CONFIG_KEY_$i", "env:GIT_CONFIG_VALUE_$i" -ErrorAction SilentlyContinue
    }
}
