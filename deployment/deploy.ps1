# Builds the admin and deploys it to the Bluehost Test Server in one command; see deployment/README.md.
# -Bump same, the default, installs this checkout untagged, as it always has. keep, patch, minor or major release it first
# (2026-10-21, user): build-release -Debug -Bump <b> -Tag -Deploy bumps VERSION, builds, tags v<version> on Bitbucket and
# installs it through server/deploy-release, so the Test Server runs a version a tag names.
param(
    [switch]$SkipBuild,
    [string]$SshHost = 'bluehost',
    [ArgumentCompletions('same', 'keep', 'patch', 'minor', 'major')]
    [string]$Bump = 'same'
)

$ErrorActionPreference = 'Stop'
$remote = '~/apps/test-server'

if ($Bump -and $Bump -ne 'same') {
    if ($SkipBuild) { throw '-SkipBuild installs the last build, so it takes no -Bump.' }
    if ($Bump -notmatch '^(keep|patch|minor|major)$') { throw "-Bump takes same, keep, patch, minor or major; it is '$Bump'." }
    $release = @{ Debug = $true; Bump = $Bump; Tag = $true; Deploy = $true }
    & (Join-Path (Split-Path $PSScriptRoot -Parent) 'script.ps1') build-release @release
    if ($LASTEXITCODE -ne 0) { throw 'The release did not reach the Test Server; see the output above.' }
    return
}

if (-not $SkipBuild) { & (Join-Path $PSScriptRoot 'build.ps1') }

# Scripts travel with every deploy, so the server always runs the version in this repo.
$files = @((Get-ChildItem (Join-Path $PSScriptRoot 'server/*.sh')).FullName) + (Join-Path $PSScriptRoot 'out/central-platform.zip')
scp @files "${SshHost}:$remote/upload/"
if ($LASTEXITCODE -ne 0) { throw 'Upload failed' }

# Windows checkouts can carry CRLF, which breaks bash; the server strips it before running.
ssh $SshHost "mkdir -p $remote/scripts && mv -f $remote/upload/*.sh $remote/scripts/ && sed -i 's/\r$//' $remote/scripts/*.sh && bash $remote/scripts/deploy-admin.sh"
if ($LASTEXITCODE -ne 0) { throw 'Deploy failed on the server; see the output above' }
