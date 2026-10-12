# Everything to the Test Server in one go: the secret settings, then the admin, then the portal; see deployment/README.md.
param(
    [switch]$SkipSettings,
    [switch]$SkipBuild,
    [string]$SshHost = 'bluehost',
    [string]$PortalRepo = (Join-Path (Split-Path (Split-Path $PSScriptRoot -Parent) -Parent) 'ikaika-portal')
)

$ErrorActionPreference = 'Stop'
$portalDeploy = Join-Path $PortalRepo 'deployment/deploy.ps1'
if (-not (Test-Path $portalDeploy)) { throw "Portal deploy script not found at $portalDeploy; pass -PortalRepo" }

# Each step connects several times; with Windows' ssh-agent running, the passphrase is typed once here.
$agent = Get-Service ssh-agent -ErrorAction SilentlyContinue
if ($null -ne $agent -and $agent.Status -eq 'Running') {
    ssh-add -l *> $null
    if ($LASTEXITCODE -ne 0) { ssh-add (Join-Path $HOME '.ssh/bluehost') }
} else {
    Write-Host 'ssh-agent is not running, so the passphrase is asked at each step. To type it once, in an admin PowerShell:'
    Write-Host '  Set-Service ssh-agent -StartupType Automatic; Start-Service ssh-agent'
}

if (-not $SkipSettings) {
    Write-Host '== Settings'
    & (Join-Path $PSScriptRoot 'push-env.ps1') -SshHost $SshHost
}

Write-Host '== Admin'
& (Join-Path $PSScriptRoot 'deploy.ps1') -SkipBuild:$SkipBuild -SshHost $SshHost

Write-Host '== Portal'
& $portalDeploy -SkipBuild:$SkipBuild -SshHost $SshHost

Write-Host 'Deployed: settings, admin (migrations, tracker patches, scheduler cron) and portal.'
