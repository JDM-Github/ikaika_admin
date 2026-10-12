# Builds the admin and deploys it to the Bluehost Test Server in one command; see deployment/README.md.
param(
    [switch]$SkipBuild,
    [string]$SshHost = 'bluehost'
)

$ErrorActionPreference = 'Stop'
$remote = '~/apps/test-server'

if (-not $SkipBuild) { & (Join-Path $PSScriptRoot 'build.ps1') }

# Scripts travel with every deploy, so the server always runs the version in this repo.
$files = @((Get-ChildItem (Join-Path $PSScriptRoot 'server/*.sh')).FullName) + (Join-Path $PSScriptRoot 'out/central-platform.zip')
scp @files "${SshHost}:$remote/upload/"
if ($LASTEXITCODE -ne 0) { throw 'Upload failed' }

# Windows checkouts can carry CRLF, which breaks bash; the server strips it before running.
ssh $SshHost "mkdir -p $remote/scripts && mv -f $remote/upload/*.sh $remote/scripts/ && sed -i 's/\r$//' $remote/scripts/*.sh && bash $remote/scripts/deploy-admin.sh"
if ($LASTEXITCODE -ne 0) { throw 'Deploy failed on the server; see the output above' }
