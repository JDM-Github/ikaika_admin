# Everything to the Test Server in one go: the secret settings, then the admin, then the portal; see deployment/README.md.
# -ResetDatabases (or -ResetSql) first replaces every Test Server database with your local ones, every table wiped, so the
# server's schema is exactly the one that works locally; the replaced data is backed up on the server. Test Server only.
param(
    [switch]$SkipSettings,
    [switch]$SkipBuild,
    [Alias('ResetSql')]
    [switch]$ResetDatabases,
    # Skips the question -ResetDatabases asks.
    [switch]$Yes,
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

if ($ResetDatabases) {
    Write-Host '== Reset databases'
    if (-not $Yes) {
        $answer = Read-Host 'This replaces EVERY Test Server database with your local ones (the old data is backed up on the server). Type RESET to go on'
        if ($answer -cne 'RESET') { throw 'Reset cancelled; nothing was deployed.' }
    }
    & (Join-Path $PSScriptRoot 'copy-databases.ps1') -Wipe -SshHost $SshHost
}

if (-not $SkipSettings) {
    Write-Host '== Settings'
    & (Join-Path $PSScriptRoot 'push-env.ps1') -SshHost $SshHost
}

Write-Host '== Admin'
& (Join-Path $PSScriptRoot 'deploy.ps1') -SkipBuild:$SkipBuild -SshHost $SshHost

Write-Host '== Portal'
& $portalDeploy -SkipBuild:$SkipBuild -SshHost $SshHost

Write-Host "Deployed: $(if ($ResetDatabases) { 'databases reset from local, ' })settings, admin (migrations, tracker patches, scheduler cron) and portal."
