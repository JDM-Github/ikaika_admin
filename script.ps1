<#
    Entry point for this project's tooling, and the seed that installs it. Copy THIS
    FILE (and script.bat) into a new repository, run it, and it sets the rest up: adds
    the ikaika_scripts submodule, checks out the pinned version, and scaffolds
    ikaika_scripts.env from the toolkit's template.

        .\script.ps1                               the terminal app - every command
        .\script.ps1 -GUI                          the desktop window instead
        .\script.ps1 <command> -Help               what it does, and every switch
        .\script.ps1 use-version -Latest           move to the newest toolkit release

    This project has no commands of its own yet. What the listing shows is the
    toolkit's own - git/, toolkit/ - and that is deliberate: the integration is being
    stood up first, and the backend's build, test and deploy commands are added to
    ikaika_scripts.env and .ikaika_scripts_configs/ once we know what this machine
    actually needs installed.

    Everything is forwarded verbatim, including -Help. There is deliberately no
    parameter block and no [CmdletBinding()] here: either one would make this an
    ADVANCED script, which silently claims the whole set of common parameters, and then
    -Debug, -Verbose and -ErrorAction would bind to this shim instead of reaching the
    target. -Debug is what decides debug versus release, so swallowing it here would be
    the difference between a staging build and a production one.

    The three constants below are the only project-side configuration this file holds,
    and they have to live here rather than in ikaika_scripts.env: on a fresh project
    that file does not exist yet, and the toolkit that would create it is what is being
    fetched. Everything else about the project belongs in ikaika_scripts.env.
#>

$ErrorActionPreference = 'Stop'

# This project's own fork of the toolkit, not the shared upstream. The portal backend
# is a PHP/Laravel consumer and the first one; whatever it needs that the toolkit does
# not have yet gets written here first and merged upstream afterwards.
$ToolkitUrl = 'https://Alias2806@bitbucket.org/ikaika-web/ik4-ikaika_scripts.git'
$ToolkitDir = 'decoupled-script'
# The ref the toolkit is checked out at. A BRANCH here, not a tag, because this
# project develops the toolkit in its own fork alongside the app, so the two move
# together and a fix does not need a release before it is usable.
#
# That is a deliberate departure from the toolkit's ADR 0001, which distributes by
# tag so a consumer only ever moves on purpose. The cost is real: this consumer
# tracks a moving target, and scripts.updateMode / use-version both reason about
# tags, so neither will offer to move a branch. Put a vX.Y.Z tag here to re-pin
# once the fork settles.
$ToolkitSeedRef = 'main'
$toolkitPath = Join-Path $PSScriptRoot $ToolkitDir
$toolkitEntry = Join-Path $toolkitPath 'script.ps1'

# git writes ordinary progress to stderr, which under 'Stop' PowerShell turns into a
# terminating error on a perfectly successful clone.
function Invoke-SeedGit([string[]]$GitArgs) {
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & git -C $PSScriptRoot @GitArgs 2>&1 | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkGray }
        return ($LASTEXITCODE -eq 0)
    } finally {
        $ErrorActionPreference = $prev
    }
}

if (-not (Test-Path -LiteralPath $toolkitEntry)) {
    if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
        Write-Host ''
        Write-Host '  git is not on PATH, so the toolkit cannot be fetched.' -ForegroundColor Red
        Write-Host '  Install git, then run this again.' -ForegroundColor Yellow
        Write-Host ''
        exit 1
    }

    $declared = Test-Path -LiteralPath (Join-Path $PSScriptRoot '.gitmodules')
    if ($declared) {
        $declared = @(Select-String -LiteralPath (Join-Path $PSScriptRoot '.gitmodules') `
                -Pattern "path\s*=\s*$([regex]::Escape($ToolkitDir))\s*$" -ErrorAction SilentlyContinue).Count -gt 0
    }

    if ($declared) {
        # Known submodule, just never populated - what a plain "git clone" leaves behind.
        Write-Host "  The toolkit is not checked out yet - fetching it..." -ForegroundColor Cyan
        [void](Invoke-SeedGit @('submodule', 'update', '--init', '--recursive'))
        # --init leaves a DETACHED head at the recorded gitlink even when .gitmodules
        # names a branch. Land on the branch, so an edit made here is committable.
        [void](Invoke-SeedGit @('-C', $ToolkitDir, 'checkout', '--quiet', $ToolkitSeedRef))
    } else {
        # First time in this repository: add it. This is what makes this file a seed
        # rather than only a launcher.
        Write-Host ''
        Write-Host '  First run in this repository. Installing the ikaika_scripts toolkit:' -ForegroundColor Cyan
        Write-Host "    $ToolkitUrl -> $ToolkitDir@$ToolkitSeedRef" -ForegroundColor DarkGray
        Write-Host ''
        if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.git'))) {
            Write-Host '  This directory is not a git repository, so a submodule cannot be added.' -ForegroundColor Red
            Write-Host '  Run "git init" (or clone the project) first.' -ForegroundColor Yellow
            Write-Host ''
            exit 1
        }
        # An empty directory left by a previous attempt blocks "submodule add".
        if ((Test-Path -LiteralPath $toolkitPath) -and
            -not @(Get-ChildItem -LiteralPath $toolkitPath -Force -ErrorAction SilentlyContinue).Count) {
            Remove-Item -LiteralPath $toolkitPath -Force -ErrorAction SilentlyContinue
        }
        if (-not (Invoke-SeedGit @('submodule', 'add', '-b', $ToolkitSeedRef, $ToolkitUrl, $ToolkitDir))) {
            Write-Host ''
            Write-Host '  Could not add the submodule. The output above says why - a credential' -ForegroundColor Red
            Write-Host "  prompt and a non-empty $ToolkitDir\ are the usual causes." -ForegroundColor DarkGray
            Write-Host ''
            exit 1
        }
        [void](Invoke-SeedGit @('-C', $ToolkitDir, 'fetch', '--tags', '--quiet'))
        if (-not (Invoke-SeedGit @('-C', $ToolkitDir, 'checkout', '--quiet', $ToolkitSeedRef))) {
            Write-Host "  Warning: could not check out $ToolkitSeedRef - staying on the default branch." -ForegroundColor Yellow
        }
    }

    if (-not (Test-Path -LiteralPath $toolkitEntry)) {
        Write-Host ''
        Write-Host "  The toolkit is still missing from $ToolkitDir\." -ForegroundColor Red
        Write-Host '  Try it by hand to see why:' -ForegroundColor Yellow
        Write-Host '    git submodule update --init --recursive' -ForegroundColor Yellow
        Write-Host ''
        exit 1
    }

    # The project identity file. Scaffolded from the toolkit's template rather than
    # written here, so the keys and their documentation have exactly one source.
    $envPath = Join-Path $PSScriptRoot 'ikaika_scripts.env'
    $template = Join-Path $toolkitPath 'ikaika_scripts.env.template'
    if (-not (Test-Path -LiteralPath $envPath) -and (Test-Path -LiteralPath $template)) {
        Copy-Item -LiteralPath $template -Destination $envPath
        # Written so the pin matches what was just checked out; the rest is left blank
        # deliberately, because guessing a project's identity would be worse than
        # leaving obvious gaps.
        $toolkitVersionFile = Join-Path $toolkitPath 'VERSION'
        $toolkitVersion = ''
        if (Test-Path -LiteralPath $toolkitVersionFile) {
            $toolkitVersion = "$(@(Get-Content -LiteralPath $toolkitVersionFile)[0])".Trim()
        }
        $seeded = @(Get-Content -LiteralPath $envPath) -replace '^scripts\.version=.*$',
            "scripts.version=$toolkitVersion"
        [System.IO.File]::WriteAllLines($envPath, $seeded, (New-Object System.Text.UTF8Encoding($false)))

        Write-Host ''
        Write-Host '  Created ikaika_scripts.env from the toolkit template.' -ForegroundColor Green
        Write-Host '  Fill it in before building - every key is documented in the file itself.' -ForegroundColor Yellow
        Write-Host '  It is committed and must never hold a secret; add "!ikaika_scripts.env" to' -ForegroundColor DarkGray
        Write-Host '  .gitignore if a "*.env" rule there would otherwise exclude it.' -ForegroundColor DarkGray
    }

    Write-Host ''
    Write-Host '  Toolkit ready.' -ForegroundColor Green
    Write-Host ''
}

# & rather than dot-sourcing: the target manages its own exit codes, and running it in
# its own scope keeps this shim from leaking variables into it.
& $toolkitEntry @args
exit $LASTEXITCODE
