# Copies your local databases onto the Bluehost Test Server in one command; see deployment/README.md.
# Replaces the Test Server's data with yours. -Only portal (or core, tracker, estimator) copies just those.
param(
    [string[]]$Only,
    [string]$SshHost = 'bluehost'
)

$ErrorActionPreference = 'Stop'
$remote = '~/apps/test-server'
$dumps  = Join-Path $PSScriptRoot 'out/db'

Remove-Item -Force -ErrorAction SilentlyContinue (Join-Path $dumps '*.sql')
& (Join-Path $PSScriptRoot 'dump-local-databases.ps1') -Only $Only

$files = @((Get-ChildItem (Join-Path $dumps '*.sql')).FullName) +
         (Join-Path $PSScriptRoot 'server/create-databases.sh') +
         (Join-Path $PSScriptRoot 'server/import-databases.sh')
scp @files "${SshHost}:$remote/upload/"
if ($LASTEXITCODE -ne 0) { throw 'Upload failed' }

# create-databases.sh is safe to rerun; it only adds what is missing before the import.
# Windows checkouts can carry CRLF, which breaks bash; the server strips it before running.
ssh $SshHost "mkdir -p $remote/scripts && mv -f $remote/upload/*.sh $remote/scripts/ && sed -i 's/\r$//' $remote/scripts/*.sh && bash $remote/scripts/create-databases.sh && bash $remote/scripts/import-databases.sh"
if ($LASTEXITCODE -ne 0) { throw 'Import failed on the server; see the output above' }
