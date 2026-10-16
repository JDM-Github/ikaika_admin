# Loads the migrations/ exporter outputs (Users & Projects, Project Estimator, Transaction Tracker)
# into the PRODUCTION databases on the Bluehost server, replacing their data. Each output is rebuilt
# naming the production database (schema.sql drops and recreates the DB it names), uploaded, and loaded
# by server/import-migrations.sh, which backs each database up first and runs the app's migrations last.
#
#     .\deployment\import-airtable-to-prod.ps1              rebuild, upload, load (asks first)
#     .\deployment\import-airtable-to-prod.ps1 -SkipGenerate reuse the outputs as they are
#     .\deployment\import-airtable-to-prod.ps1 -Yes          no confirmation (a pipeline)
param(
    [switch]$SkipGenerate,
    [switch]$Yes,
    [string]$SshHost = 'bluehost',
    [string]$RemoteDir = 'apps/portal-prod'
)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent

# Exporter target -> output folder -> the production database its schema.sql is rewritten to name.
$targets = @(
    @{ Target = 'users-projects-prod';      Folder = 'users_projects_prod';      Db = 'eoxvhumy_pp_portal' },
    @{ Target = 'project-estimator';        Folder = 'project_estimator';        Db = 'eoxvhumy_pp_estimator' },
    @{ Target = 'transaction-tracker-prod'; Folder = 'transaction_tracker_prod'; Db = 'eoxvhumy_pp_tracker' }
)

if (-not $Yes) {
    Write-Host ''
    Write-Host 'This REPLACES the data in the production databases on the server (each is backed up first):' -ForegroundColor Yellow
    foreach ($t in $targets) { Write-Host "  $($t.Db)  <-  migrations/outputs/$($t.Folder)/{schema,data}.sql" }
    Write-Host ''
    $answer = Read-Host 'Type REPLACE to go on'
    if ($answer -cne 'REPLACE') { throw 'Cancelled; nothing was sent.' }
}

# schema.sql names the database it drops and recreates, so rebuild each output for production's name.
if (-not $SkipGenerate) {
    foreach ($t in $targets) {
        Write-Host "== build $($t.Target) naming $($t.Db)"
        & pwsh -NoProfile -File (Join-Path $root 'migrations/migrate.ps1') -Target $t.Target -Produce both -Step generate -Database $t.Db
        if ($LASTEXITCODE -ne 0) { throw "migrate.ps1 failed for $($t.Target)" }
    }
}

Write-Host '== upload'
ssh $SshHost "mkdir -p ~/$RemoteDir/upload ~/$RemoteDir/scripts"
foreach ($t in $targets) {
    $out = Join-Path $root "migrations/outputs/$($t.Folder)"
    foreach ($part in 'schema', 'data') {
        scp (Join-Path $out "$part.sql") "${SshHost}:$RemoteDir/upload/$($t.Db)-$part.sql"
        if ($LASTEXITCODE -ne 0) { throw "upload failed: $($t.Db)-$part.sql" }
    }
}
scp (Join-Path $PSScriptRoot 'server/import-migrations.sh') "${SshHost}:$RemoteDir/scripts/import-migrations.sh"
if ($LASTEXITCODE -ne 0) { throw 'upload failed: import-migrations.sh' }

Write-Host '== load on the server'
ssh $SshHost "sed -i 's/\r\$//' ~/$RemoteDir/scripts/import-migrations.sh && ROOT=~/$RemoteDir bash ~/$RemoteDir/scripts/import-migrations.sh"
if ($LASTEXITCODE -ne 0) { throw 'The server import failed; see the output above (the databases were backed up first).' }

Write-Host 'Done: production databases loaded from the Airtable export.'
