# Builds and deploys the admin API and the portal to production, portal.ikaikabim.com/v2.
#
# A forwarder over deployment/deploy-all-prod.ps1, which still runs on its own: each repo's build-release, then its
# deploy-release, the API first. It asks for PROD here, unless -Yes, and hands -Force on so deploy-release does not ask a
# second time - one deliberate act either way. The portal is the checkout beside this one.
#
#     .\script.ps1 deploy/production              build and deploy both, after PROD
#     .\script.ps1 deploy/production -SkipBuild   the last builds again
param(
    # Deploy the last builds instead of building again.
    [switch]$SkipBuild,
    # Skip the question. For a scripted release, where a question is a hang.
    [switch]$Yes,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/production - the admin API and the portal, to production.

  Usage
    .\script.ps1 deploy/production [-SkipBuild] [-Yes]

  Runs deployment\deploy-all-prod.ps1: build-release then deploy-release in
  this repo and then in the portal's. Asks for PROD unless -Yes.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
Confirm-Deliberate -Word 'PROD' -Question 'PRODUCTION. This builds and deploys the admin API and the portal to portal.ikaikabim.com/v2.' -Yes:$Yes
Invoke-Forwarded 'deployment\deploy-all-prod.ps1' @{ SkipBuild = $SkipBuild; Force = $true; PortalRepo = (Get-PortalRepo) }
