# Plays the Build release workflow on this PC, for when GitHub Actions cannot: the same choices, planned by the same ci-release-plan.ps1, then each step's work run here.
# Every secret the workflow reads comes from .local.actions-secret.env, by its GitHub name (.local.actions-secret.env.template lists them); the file must be ignored by git, and no value is ever printed.
# Each phase is its own pwsh process, as a step is on a runner: secrets -Ci writes the sealed archive's deploy config and key where the toolkit reads them, and its values reach later phases through a private GITHUB_ENV file that is deleted afterwards.
# Then, as on the runner: tag-version -Bump bumps VERSION, files the changelog, commits and tags, which is looked for on Bitbucket before build-release packages and server/deploy-release installs it with deploy-admin.sh.
# What is built is what Bitbucket has: an uncommitted change, or a branch that differs from Bitbucket's, stops the run before anything is built.
# GitHub is best effort - the version commit and tag pushed there - since GitHub may be what is down; a failure there warns and changes nothing else.
#
#     .\script.ps1 ci-local -Upload                                a staging upload of what Bitbucket has
#     .\script.ps1 ci-local -Version patch -Upload                 bumped, tagged and installed on the Test Server
#     .\script.ps1 ci-local -Upload -PlanOnly                      the plan and every phase it would run, nothing run

[CmdletBinding()]
param(
    # staging is the Bluehost Test Server; production is refused until ikaika_scripts.env names one.
    [ValidateSet('staging', 'production')]
    [string]$Target = 'staging',
    # Yes, upload to PRODUCTION - a production upload needs it, as the form's box does.
    [switch]$ConfirmProduction,
    # same rebuilds as is, keep takes the next build number, patch/minor/major bump and tag; an explicit x.y.z is a custom version.
    [ArgumentCompletions('same', 'keep', 'patch', 'minor', 'major')]
    [string]$Version = 'same',
    # Upload to the server after packaging: composer, the swap, migrations, then the health check.
    [switch]$Upload,
    # Leave GitHub alone: no version commit or tag pushed there.
    [switch]$NoGitHub,
    # Plan, and print what each phase would run; nothing is run, and no secret is read.
    [switch]$PlanOnly,
    # The secrets file; .local.actions-secret.env in .ikaika_scripts_configs or the repo root when left out.
    [string]$SecretsPath = '',
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent

if ($Help) {
    Write-Host @'

  ci-local - the Build release workflow on this PC, for when GitHub Actions is down.

  Usage
    .\script.ps1 ci-local [-Target staging|production] [-ConfirmProduction]
                          [-Version same|keep|patch|minor|major|x.y.z] [-Upload] [-NoGitHub] [-PlanOnly]

  The same choices as the workflow's form, checked by the same ci-release-plan.ps1,
  then run here phase by phase: the deploy config from the sealed archive, the
  version bumped, filed and tagged on Bitbucket, the package, the upload with
  deployment/server/deploy-admin.sh, then GitHub.
  Secrets come from .local.actions-secret.env, by the names GitHub keeps them under;
  copy .local.actions-secret.env.template and fill it in. It is ignored by git, and
  no value from it is printed.

  Examples
    .\script.ps1 ci-local -Upload -PlanOnly
        The plan for a staging upload, and the command each phase would run.
        Runs nothing and needs no secrets - start here.

    .\script.ps1 ci-local -Upload
        A staging package of what Bitbucket has, uploaded.

    .\script.ps1 ci-local -Version patch -Upload
        Bumped, the changelog filed and the version tagged on Bitbucket,
        packaged, and installed on the Test Server.

  Stops before building when this checkout has uncommitted changes or differs
  from Bitbucket's branch: what is built is what Bitbucket has.

'@
    exit 0
}

$config = @(Get-Content -LiteralPath (Join-Path $repoRoot 'ikaika_scripts.env'))
function Get-ConfigValue([string]$Key) {
    return "$(($config | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1) -replace '^[^=]*=', '')".Trim()
}
$prefix = Get-ConfigValue 'env.prefix'
foreach ($name in 'NO_PROMPT', 'NO_GUI', 'NO_UPDATE') { Set-Item -LiteralPath "env:${prefix}_SCRIPTS_$name" -Value '1' }
$env:GIT_TERMINAL_PROMPT = '0'
$env:GCM_INTERACTIVE = 'never'

$pwshPath = (Get-Process -Id $PID).Path
$work = Join-Path ([IO.Path]::GetTempPath()) ("ci-local-" + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $work | Out-Null
$outcomes = [ordered]@{}

function Write-Phase([string]$Title) {
    Write-Host ''
    Write-Host "== $Title" -ForegroundColor Cyan
}

# git with 'Continue', so a message on stderr is output rather than an error that stops the run.
function Invoke-Git {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& git -C $repoRoot @args 2>&1 | ForEach-Object { "$_" })
        return @{ Code = $LASTEXITCODE; Lines = $output; Text = ($output -join "`n") }
    } finally {
        $ErrorActionPreference = $previous
    }
}

# One phase as a runner runs a step: its script in a pwsh of its own, from the repo root, with these variables on top of this one's.
function Invoke-Step([string]$Name, [string]$Script, [hashtable]$Environment = @{}) {
    $file = Join-Path $work "step-$($outcomes.Count + 1).ps1"
    Set-Content -LiteralPath $file -Value $Script -Encoding utf8
    $saved = @{}
    foreach ($key in $Environment.Keys) {
        $saved[$key] = [Environment]::GetEnvironmentVariable($key)
        [Environment]::SetEnvironmentVariable($key, "$($Environment[$key])")
    }
    Push-Location $repoRoot
    try {
        & $pwshPath -NoProfile -File $file
        $code = $LASTEXITCODE
    } finally {
        Pop-Location
        foreach ($key in $saved.Keys) { [Environment]::SetEnvironmentVariable($key, $saved[$key]) }
    }
    $outcomes[$Name] = if ($code -eq 0) { 'success' } else { 'failure' }
    return $code -eq 0
}

# Variables a step left in GITHUB_ENV, in either of the forms a runner reads.
function Import-StepEnvironment([string]$File) {
    if (-not (Test-Path -LiteralPath $File)) { return }
    $lines = @(Get-Content -LiteralPath $File)
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^([A-Za-z_][A-Za-z0-9_]*)<<(.+)$') {
            $name = $Matches[1]
            $marker = $Matches[2]
            $value = @()
            while (++$i -lt $lines.Count -and $lines[$i] -ne $marker) { $value += $lines[$i] }
            [Environment]::SetEnvironmentVariable($name, ($value -join "`n"))
        } elseif ($lines[$i] -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            [Environment]::SetEnvironmentVariable($Matches[1], $Matches[2])
        }
    }
}

function Stop-Run([string]$Message) {
    Write-Host ''
    Write-Host "  $Message" -ForegroundColor Red
    Write-Host ''
    Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
    exit 1
}

function Get-AppVersion {
    return "$(Get-Content -LiteralPath (Join-Path $repoRoot 'VERSION') | Where-Object { $_.Trim() -and -not $_.StartsWith('#') } | Select-Object -First 1)".Trim()
}

try {
    # ---------------------------------------------------------------- plan
    Write-Phase 'Plan - the choices, checked as the workflow checks them'
    $branch = (Invoke-Git rev-parse --abbrev-ref HEAD).Text.Trim()
    if (-not $branch -or $branch -eq 'HEAD') { Stop-Run 'This checkout is on no branch; check one out, as the workflow''s "Use workflow from" does.' }
    $custom = $Version -match '^\d+\.\d+\.\d+$'
    $answers = [ordered]@{
        version = $(if ($custom) { 'custom' } else { $Version }); custom_version = $(if ($custom) { $Version } else { '' }); sync_bitbucket = $true
        target = $Target; confirm_production = [bool]$ConfirmProduction; upload = [bool]$Upload
    }
    $planFile = Join-Path $work 'build-release.json'
    $planEnv = Join-Path $work 'plan-env'
    $planned = Invoke-Step 'plan' "& ./.ikaika_scripts_configs/ci-release-plan.ps1 -PlanFile '$planFile'; exit `$LASTEXITCODE" @{
        INPUTS_JSON = ($answers | ConvertTo-Json -Compress); GITHUB_REF_TYPE = 'branch'; GITHUB_REF_NAME = $branch; GITHUB_ENV = $planEnv
    }
    if (-not $planned) { Stop-Run 'The plan refused these choices; nothing was run.' }
    Import-StepEnvironment $planEnv
    $plan = Get-Content -LiteralPath $planFile -Raw | ConvertFrom-Json -AsHashtable
    $deploy = if ($env:DEPLOY_PLAN) { $env:DEPLOY_PLAN | ConvertFrom-Json -AsHashtable } else { $null }
    $tagging = $env:TAGGING -eq 'true'
    $purposes = @("$env:PURPOSES" -split ',' | Where-Object { $_ })
    $githubUrl = (Invoke-Git remote get-url github).Text.Trim()
    $githubRepo = if ($githubUrl -match 'github\.com[:/]([^/]+/[^/]+?)(\.git)?$') { $Matches[1] } else { '' }

    if ($PlanOnly) {
        Write-Phase 'What each phase would run'
        if ($purposes.Count) { Write-Host "  secrets      .\script.ps1 secrets -Ci -For $($purposes -join ', ')" }
        if ($tagging) { Write-Host "  version      .\script.ps1 tag-version -Bump $env:VERSION_BUMP$(if ($env:BUILD_DEBUG -eq 'true') { ' -Debug' })" }
        Write-Host "  package      .\script.ps1 build-release $(@($plan.Keys | ForEach-Object { "-$_ $($plan[$_])" }) -join ' ')"
        if ($deploy) { Write-Host "  upload       .\script.ps1 server/deploy-release $(@($deploy.Keys | ForEach-Object { "-$_ $($deploy[$_])" }) -join ' ')" }
        if ($tagging -and -not $NoGitHub) { Write-Host "  github       the version commit and tag to $(if ($githubRepo) { $githubRepo } else { 'GitHub (no github remote here)' })" }
        Write-Host ''
        exit 0
    }

    # ---------------------------------------------------------------- the secrets file
    Write-Phase 'Secrets - the workflow''s, from the local file'
    # Beside the other env files in .ikaika_scripts_configs, or at the repo root, as secrets looks for it.
    $secretsFile = if ($SecretsPath) { $SecretsPath } else {
        $found = @((Join-Path $repoRoot '.ikaika_scripts_configs\.local.actions-secret.env'), (Join-Path $repoRoot '.local.actions-secret.env') | Where-Object { Test-Path -LiteralPath $_ })[0]
        if ($found) { $found } else { Join-Path $repoRoot '.ikaika_scripts_configs\.local.actions-secret.env' }
    }
    if (-not (Test-Path -LiteralPath $secretsFile)) {
        Stop-Run "No $secretsFile. Copy .local.actions-secret.env.template to it and fill in the values GitHub keeps as this repo's Actions secrets."
    }
    $inside = [IO.Path]::GetFullPath($secretsFile).StartsWith($repoRoot, [StringComparison]::OrdinalIgnoreCase)
    if ($inside -and (Invoke-Git check-ignore -q -- $secretsFile).Code -ne 0) {
        Stop-Run "$secretsFile is not ignored by git, so a commit could carry every secret in it. Add it to .gitignore first."
    }
    $secrets = @{}
    foreach ($line in Get-Content -LiteralPath $secretsFile) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$') { $secrets[$Matches[1]] = $Matches[2] -replace '^(["''])(.*)\1$', '$2' }
    }
    $needed = @('BITBUCKET_REPO_TOKEN') + @(if ($purposes.Count) { 'SHARED_FILES_URL', 'SHARED_FILES_PASSWORD' })
    $missing = @($needed | Where-Object { -not $secrets[$_] })
    if ($missing) { Stop-Run "$secretsFile has no value for $($missing -join ', ')." }
    Write-Host "  read $(@($secrets.Keys | Where-Object { $secrets[$_] }).Count) value(s) from $(Split-Path -Leaf $secretsFile)"

    # Bitbucket through the token, whatever form origin's URL takes here; credential helpers stay out of it.
    $origin = (Invoke-Git remote get-url origin).Text.Trim()
    $user = if ($secrets['BITBUCKET_REPO_USER']) { $secrets['BITBUCKET_REPO_USER'] } else { 'x-token-auth' }
    $gitConfig = [ordered]@{}
    $bitbucketBase = "https://$([uri]::EscapeDataString($user)):$([uri]::EscapeDataString($secrets['BITBUCKET_REPO_TOKEN']))@bitbucket.org/"
    $gitConfig["url.$bitbucketBase.insteadOf"] = @('https://bitbucket.org/', 'git@bitbucket.org:') + @(if ($origin -match '^(https://[^/]+@bitbucket\.org/)') { $Matches[1] })
    if ($secrets['GH_MIRROR_TOKEN']) { $gitConfig["url.https://x-access-token:$($secrets['GH_MIRROR_TOKEN'])@github.com/.insteadOf"] = @('https://github.com/', 'git@github.com:') }
    $gitConfig['credential.helper'] = @('')
    $count = 0
    foreach ($key in $gitConfig.Keys) {
        foreach ($value in $gitConfig[$key]) {
            [Environment]::SetEnvironmentVariable("GIT_CONFIG_KEY_$count", $key)
            [Environment]::SetEnvironmentVariable("GIT_CONFIG_VALUE_$count", $value)
            $count++
        }
    }
    $env:GIT_CONFIG_COUNT = "$count"

    # ---------------------------------------------------------------- what Bitbucket has
    Write-Phase "Check - this checkout against Bitbucket's $branch"
    if ((Invoke-Git status --porcelain --untracked-files=no).Text) { Stop-Run 'This checkout has uncommitted changes, which a runner would not build; commit or stash them first.' }
    $fetch = Invoke-Git fetch --quiet --force --tags origin $branch
    if ($fetch.Code -ne 0) { Stop-Run "Could not read Bitbucket with BITBUCKET_REPO_TOKEN: $($fetch.Lines | Select-Object -First 1)" }
    $head = (Invoke-Git rev-parse HEAD).Text.Trim()
    $remote = (Invoke-Git rev-parse FETCH_HEAD).Text.Trim()
    if ($head -ne $remote) {
        Stop-Run "This checkout is at $($head.Substring(0, 7)) and Bitbucket's $branch at $($remote.Substring(0, 7)); pull or push until they agree, so what is built is what Bitbucket has."
    }
    if ($tagging) {
        $dry = Invoke-Git push --dry-run --porcelain origin "HEAD:refs/heads/$branch"
        if ($dry.Code -ne 0) { Stop-Run "Bitbucket refuses a push to ${branch}: BITBUCKET_REPO_TOKEN needs Repositories: Write. Nothing was built." }
    }
    Write-Host "  $branch at $($head.Substring(0, 7)), as Bitbucket has it$(if ($tagging) { ', and Bitbucket takes the version commit' })" -ForegroundColor Green
    $outcomes['check'] = 'success'

    # ---------------------------------------------------------------- the sealed archive
    if ($purposes.Count) {
        Write-Phase "Secrets for the upload - $($purposes -join ', '), from the sealed archive"
        $jobEnv = Join-Path $work 'github-env'
        $ok = Invoke-Step 'secrets' "./script.ps1 secrets -For $($purposes -join ',') -Ci; exit `$LASTEXITCODE" @{
            SHARED_FILES_URL = $secrets['SHARED_FILES_URL']; SHARED_FILES_PASSWORD = $secrets['SHARED_FILES_PASSWORD']; GITHUB_ENV = $jobEnv
        }
        Import-StepEnvironment $jobEnv
        Remove-Item -LiteralPath $jobEnv -Force -ErrorAction SilentlyContinue
        if (-not $ok) { Stop-Run 'The sealed archive did not give the upload what it needs; nothing was built.' }
    }

    # ---------------------------------------------------------------- version
    $tag = ''
    if ($tagging) {
        Write-Phase "Version - $env:VERSION_BUMP, the changelog filed, committed and tagged on Bitbucket"
        $bumped = Invoke-Step 'version' "./script.ps1 tag-version -Bump $env:VERSION_BUMP$(if ($env:BUILD_DEBUG -eq 'true') { ' -Debug' }); exit `$LASTEXITCODE"
        $tag = "v$(Get-AppVersion)$(if ($env:BUILD_DEBUG -ne 'true') { '-released' })"
        if ($bumped -and -not (Invoke-Git ls-remote --tags origin "refs/tags/$tag").Text) {
            $outcomes['version'] = 'failure'
            Write-Host "  $tag is not on Bitbucket; see tag-version's output above." -ForegroundColor Red
        }
        if ($outcomes['version'] -ne 'success') { Stop-Run 'The version did not reach Bitbucket, so nothing was packaged or uploaded.' }
    }

    # ---------------------------------------------------------------- package and upload
    Write-Phase "Package the API for $Target"
    $packaged = Invoke-Step 'package' @'
$plan = $env:PACKAGE_JSON | ConvertFrom-Json -AsHashtable
./script.ps1 build-release @plan
exit $LASTEXITCODE
'@ @{ PACKAGE_JSON = ($plan | ConvertTo-Json -Depth 3 -Compress) }

    if ($deploy -and $packaged) {
        Write-Phase "Upload to $Target - composer, the swap, migrations, the health check"
        [void](Invoke-Step 'upload' @'
$deploy = $env:DEPLOY_JSON | ConvertFrom-Json -AsHashtable
./script.ps1 server/deploy-release @deploy
exit $LASTEXITCODE
'@ @{ DEPLOY_JSON = ($deploy | ConvertTo-Json -Depth 3 -Compress) })
    }

    # ---------------------------------------------------------------- GitHub, best effort
    if ($tag -and -not $NoGitHub) {
        Write-Phase 'GitHub - the version commit and tag'
        if (-not $githubRepo) { Write-Host '  No github remote here, so GitHub is left for the next sync from Bitbucket.' -ForegroundColor Yellow }
        elseif (-not $secrets['GH_MIRROR_TOKEN']) { Write-Host '  No GH_MIRROR_TOKEN in the secrets file, so GitHub is left for the next sync from Bitbucket.' -ForegroundColor Yellow }
        else {
            $push = Invoke-Git push --quiet "https://github.com/$githubRepo.git" "HEAD:refs/heads/$branch" "refs/tags/$($tag):refs/tags/$tag"
            $outcomes['github'] = if ($push.Code -eq 0) { 'success' } else { 'failure' }
            if ($push.Code -ne 0) { Write-Host "  GitHub did not take $tag ($($push.Lines | Select-Object -Last 1)); the next sync from Bitbucket brings it over." -ForegroundColor Yellow }
            else { Write-Host "  Pushed $branch and $tag to $githubRepo." -ForegroundColor Green }
        }
    }

    # ---------------------------------------------------------------- result
    Write-Phase 'Result'
    $names = [ordered]@{ plan = 'Plan'; check = 'Checkout matches Bitbucket'; secrets = 'Secrets from the archive'; version = 'Changelog filed, version tagged'; package = "Packaged for $Target"; upload = "Installed on $Target"; github = 'Version on GitHub' }
    foreach ($key in $names.Keys) {
        if (-not $outcomes.Contains($key)) { continue }
        $color = if ($outcomes[$key] -eq 'success') { 'Green' } else { 'Red' }
        Write-Host ("  {0,-34} {1}" -f $names[$key], $(if ($outcomes[$key] -eq 'success') { 'done' } else { 'failed' })) -ForegroundColor $color
    }
    Write-Host ("  {0,-34} {1}" -f 'Version', "$(Get-AppVersion)$(if ($tag) { " ($tag)" } else { ' (unchanged)' })")
    Write-Host ''
    $failed = @('package', 'upload' | Where-Object { $outcomes[$_] -eq 'failure' })
    if ($deploy -and -not $outcomes.Contains('upload')) { $failed += 'upload (never ran)' }
    exit $(if ($failed.Count) { 1 } else { 0 })
} finally {
    Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}
