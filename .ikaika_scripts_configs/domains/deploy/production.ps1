# Builds and deploys the admin API and the portal to production, portal.ikaikabim.com/v2.
#
# A forwarder over deployment/deploy-all-prod.ps1, which still runs on its own: each repo's build-release, then its
# deploy-release, the API first. It asks for PROD here, unless -Yes, and hands -Force on so deploy-release does not ask a
# second time - one deliberate act either way. The portal is the checkout beside this one.
#
# Every production deploy is a release (2026-10-21, user): both repos bump by -Bump, keep by default - the next build number
# only - and each version is committed, tagged v<version>-released and pushed once its build is good. The question names the
# versions it is about to make. -SkipBuild deploys the released archives as they stand.
#
#     .\script.ps1 deploy/production              release and deploy both, after PROD
#     .\script.ps1 deploy/production -Bump patch  the same, as the next patch
#     .\script.ps1 deploy/production -SkipBuild   the released archives again
param(
    # What both repos release as: keep takes the next build number only; patch, minor and major bump the version.
    [ArgumentCompletions('keep', 'patch', 'minor', 'major')]
    [string]$Bump = 'keep',
    # Deploy the released archives as they stand instead of releasing new ones.
    [switch]$SkipBuild,
    # Skip the question. For a scripted release, where a question is a hang.
    [switch]$Yes,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/production - release the admin API and the portal, and deploy both to production.

  Usage
    .\script.ps1 deploy/production [-Bump keep|patch|minor|major] [-Yes]
    .\script.ps1 deploy/production -SkipBuild [-Yes]

  Runs deployment\deploy-all-prod.ps1: build-release then deploy-release in
  this repo and then in the portal's. Each build is a release - bumped by
  -Bump (keep by default: the next build number only), then committed, tagged
  v<version>-released and pushed - so both checkouts must be on a branch, with
  nothing uncommitted and nothing on origin they lack. Asks for PROD, naming the
  versions, unless -Yes. -SkipBuild deploys the released archives again.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
$portal = Get-PortalRepo
if (-not $SkipBuild -and $Bump -notmatch '^(keep|patch|minor|major)$') {
    Write-Host "A production deploy is a release: -Bump takes keep, patch, minor or major, and it is '$Bump'." -ForegroundColor Red
    exit 1
}
$what = if ($SkipBuild) { 'the released archives as they stand' } else {
    $admin = Get-ReleasePreview -Root $script:ForwardRoot -Bump $Bump
    $front = Get-ReleasePreview -Root $portal -Bump $Bump
    "admin $($admin.Current) -> $($admin.Next), portal $($front.Current) -> $($front.Next), each tagged -released and pushed"
}
Confirm-Deliberate -Word 'PROD' -Question "PRODUCTION. This releases and deploys the admin API and the portal to portal.ikaikabim.com/v2: $what." -Yes:$Yes
$arguments = @{ SkipBuild = $SkipBuild; Force = $true; PortalRepo = $portal }
if (-not $SkipBuild) { $arguments.Bump = $Bump }
Invoke-Forwarded 'deployment\deploy-all-prod.ps1' $arguments
