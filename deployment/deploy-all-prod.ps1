# Deploys BOTH ends to production (portal.ikaikabim.com/v2) the ikaika_scripts way: build-release then
# deploy-release in each repo, admin first (the portal calls the API). The Test Server keeps deploy-all.ps1.
#
# Production's settings are the repos' own: ikaika_scripts.env release.* keys and
# .ikaika_scripts_configs/release.env (SSHUsername, SSHPASSPHRASE, the paths, AZURE_REDIRECT_URI).
# Nothing is sent from here, so the two repos can never disagree about where production is.
#
# Every production deploy is a release (2026-10-21, user): each repo bumps by -Bump - keep takes the next build number only -
# and tag-version commits, tags v<version>-released and pushes it once its build is good. So both repos are checked first, before
# anything builds: on a branch, nothing uncommitted, nothing on origin they lack. -SkipBuild deploys the archives as they stand,
# and only when each one's deploy.json names a production version a -released tag already holds.
#
#     .\deployment\deploy-all-prod.ps1              release and deploy both ends (asks before each deploy)
#     .\deployment\deploy-all-prod.ps1 -Bump patch  the same, as the next patch
#     .\deployment\deploy-all-prod.ps1 -SkipBuild   deploy the released archives as they stand
#     .\deployment\deploy-all-prod.ps1 -Force       no production confirmation (CI)
param(
    [switch]$SkipBuild,
    [switch]$Force,
    # What each repo releases as: keep (the next build number only), patch, minor or major. same is refused: an upload to
    # production is a release.
    [ArgumentCompletions('keep', 'patch', 'minor', 'major')]
    [string]$Bump = 'keep',
    [string]$PortalRepo = (Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'ikaika-portal')
)

$ErrorActionPreference = 'Stop'
# The portal frontend's checkout beside this one, under its old name or the one it has now (2026-10-20).
if (-not $PSBoundParameters.ContainsKey('PortalRepo') -and -not (Test-Path $PortalRepo)) {
    $PortalRepo = Join-Path (Split-Path $PortalRepo -Parent) 'ikaika_portal_frontend_rewrite'
}
$adminRoot = Split-Path $PSScriptRoot -Parent
$portalScript = Join-Path $PortalRepo 'script.ps1'
if (-not (Test-Path $portalScript)) { throw "Portal toolkit entry not found at $portalScript; pass -PortalRepo" }
foreach ($lib in 'tag-checkout.ps1', 'release-policy.ps1') { . (Join-Path $adminRoot ".ikaika_scripts_configs\lib\$lib") }

if ($SkipBuild -and $PSBoundParameters.ContainsKey('Bump')) { throw '-SkipBuild deploys the archives as they stand, so it takes no -Bump.' }
if (-not $SkipBuild -and $Bump -notmatch '^(keep|patch|minor|major)$') { throw "A production deploy is a release: -Bump takes keep, patch, minor or major, and it is '$Bump'." }

# The toolkit's entry point in a repo, forwarded verbatim; -Debug is left off, so it is production.
function Invoke-Toolkit([string]$RepoRoot, [string]$Command, [string[]]$Extra = @(), [switch]$NoConfirm) {
    Write-Host "== $Command  ($RepoRoot)"
    $toolkitArgs = @('-NoProfile', '-File', (Join-Path $RepoRoot 'script.ps1'), $Command) + $Extra
    if ($NoConfirm) { $toolkitArgs += '-Force' }
    & pwsh @toolkitArgs
    if ($LASTEXITCODE -ne 0) { throw "$Command failed in $RepoRoot" }
}

$repos = @(@{ Name = 'admin'; Root = $adminRoot; Archive = 'central-platform.zip' }, @{ Name = 'portal'; Root = $PortalRepo; Archive = 'portal.zip' })
if ($SkipBuild) {
    # What is already built must be a release production can take: built for it, and its version tagged -released.
    foreach ($repo in $repos) {
        $manifest = Join-Path $repo.Root "deployment\out\$($repo.Archive).deploy.json"
        $built = if (Test-Path -LiteralPath $manifest) { Get-Content -LiteralPath $manifest -Raw | ConvertFrom-Json } else { $null }
        if ($null -eq $built -or "$($built.target)" -ne 'release' -or -not "$($built.version)") { throw "$($repo.Name): $manifest names no production version, so there is nothing released to deploy - drop -SkipBuild to release one." }
        $tag = "v$($built.version)-released"
        if ((Invoke-TagGit $repo.Root @('rev-parse', '-q', '--verify', "refs/tags/$tag")).Code -ne 0) { throw "$($repo.Name): its archive is $($built.version), and no $tag names it - drop -SkipBuild to release a version a tag holds." }
        Write-Host "  $($repo.Name): $($built.version), released as $tag"
    }
} else {
    # Both checked before either builds, so a refusal in the portal never leaves the API released on its own.
    foreach ($repo in $repos) {
        $problem = Test-ReleaseReady $repo.Root
        if ($problem) { throw "$problem Nothing was built or deployed." }
    }
}

# A bare build-release opens its Build release dialog; any named option (each its own default here) builds straight away.
if (-not $SkipBuild) { Invoke-Toolkit $adminRoot 'build-release' -Extra @('-Bump', $Bump, '-Tag', '-Keep', '10') }
Invoke-Toolkit $adminRoot 'deploy-release' -NoConfirm:$Force

if (-not $SkipBuild) {
    Invoke-Toolkit $PortalRepo 'build-release' -Extra @('-Platform', 'web', '-Bump', $Bump)
    Invoke-Toolkit $PortalRepo 'tag-version'
}
Invoke-Toolkit $PortalRepo 'deploy-release' -NoConfirm:$Force

Write-Host 'Deployed: admin API and portal to production (portal.ikaikabim.com/v2).'
