<#
Runs the Airtable -> Cloudinary -> SQL pipeline (fetch_airtable.py, upload_attachments_to_cloudinary.py,
generate_sql_users_projects.py) against a chosen base. The Airtable token is supplied at the command
line each run and only ever lives in this process's environment for the fetch step -- it is never
written to disk, logged, or passed to any step besides fetch_airtable.py.

Usage:
  .\migrate.ps1 --token="patXXXXXXXXXXXXXX"
  .\migrate.ps1 --token="patXXXXXXXXXXXXXX" --base-id=app8DvnFZErPZT5Az --output-dir=./export_test
  .\migrate.ps1 --token="patXXXXXXXXXXXXXX" --step=fetch
  .\migrate.ps1 --step=upload --output-dir=./export_prod          # token not needed past fetch

Options:
  --token=PAT        Airtable Personal Access Token. Required unless --step skips fetch.
  --base-id=ID        Airtable base ID. Defaults to the production Users & Projects base
                       (app8jGTzRDQt4yPIa). The TEST base is app8DvnFZErPZT5Az.
  --output-dir=DIR    Export directory. Defaults to ./export_prod.
  --step=STEP         all (default) | fetch | upload | generate
  --database=NAME      Passed through to generate_sql_users_projects.py. Defaults to test_portal_database.

This script does not touch the database. It stops after writing <output-dir>/data.sql and prints the
sql-ready import steps for you to run and confirm yourself, since importing runs DROP DATABASE first.
#>

$Token = $null
$BaseId = "app8jGTzRDQt4yPIa"
$OutputDir = "./export_prod"
$Step = "all"
$Database = "test_portal_database"

foreach ($arg in $args) {
    if ($arg -notmatch '^--([^=]+)=(.*)$') {
        Write-Warning "Ignoring unrecognized argument: $arg"
        continue
    }
    $key = $matches[1]
    $value = $matches[2]
    switch ($key) {
        'token'      { $Token = $value }
        'base-id'    { $BaseId = $value }
        'output-dir' { $OutputDir = $value }
        'step'       { $Step = $value }
        'database'   { $Database = $value }
        default      { Write-Warning "Unknown option --$key" }
    }
}

$validSteps = @('all', 'fetch', 'upload', 'generate')
if ($validSteps -notcontains $Step) {
    Write-Error "--step must be one of: $($validSteps -join ', ')"
    exit 1
}

$needsFetch = $Step -in @('all', 'fetch')
if ($needsFetch -and -not $Token) {
    Write-Error "--token is required for step '$Step'. Pass --token=`"patXXXXXXXXXXXXXX`"."
    exit 1
}

$python = Get-Command python -ErrorAction SilentlyContinue
if (-not $python) {
    Write-Error "python was not found on PATH."
    exit 1
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $scriptDir
try {
    if ($Step -in @('all', 'fetch')) {
        Write-Host "==> Fetching base $BaseId into $OutputDir" -ForegroundColor Cyan
        $env:AIRTABLE_API_KEY = $Token
        try {
            python fetch_airtable.py --base-id $BaseId --output-dir $OutputDir
            if ($LASTEXITCODE -ne 0) { throw "fetch_airtable.py exited with code $LASTEXITCODE" }
        } finally {
            Remove-Item Env:\AIRTABLE_API_KEY -ErrorAction SilentlyContinue
        }
    }

    if ($Step -in @('all', 'upload')) {
        Write-Host "==> Uploading attachments to Cloudinary" -ForegroundColor Cyan
        python upload_attachments_to_cloudinary.py --export-dir $OutputDir
        if ($LASTEXITCODE -ne 0) { throw "upload_attachments_to_cloudinary.py exited with code $LASTEXITCODE" }
    }

    if ($Step -in @('all', 'generate')) {
        Write-Host "==> Generating SQL" -ForegroundColor Cyan
        $sqlOut = Join-Path $OutputDir "data.sql"
        $cache = Join-Path $OutputDir "cloudinary_uploads.json"
        python generate_sql_users_projects.py --export-dir $OutputDir --output $sqlOut --database $Database --cloudinary-cache $cache
        if ($LASTEXITCODE -ne 0) { throw "generate_sql_users_projects.py exited with code $LASTEXITCODE" }

        Write-Host ""
        Write-Host "$OutputDir holds real downloaded files (bank certificates, SSS/PhilHealth/TIN, employee" -ForegroundColor Yellow
        Write-Host "photos). It matches the migrations/export_*/ gitignore rule -- keep it that way." -ForegroundColor Yellow
        Write-Host ""
        Write-Host "This script does not import into MySQL. Review $sqlOut, then run, in order:" -ForegroundColor Cyan
        Write-Host "  mysql -u root -pPASSWORD --default-character-set=utf8mb4 < sql/portal/schema.sql"
        Write-Host "  mysql -u root -pPASSWORD --default-character-set=utf8mb4 < sql/portal/separate.sql"
        Write-Host "  mysql -u root -pPASSWORD -D $Database --default-character-set=utf8mb4 < sql/portal/indexes.sql"
        Write-Host "  mysql -u root -pPASSWORD --default-character-set=utf8mb4 < $sqlOut"
        Write-Host "schema.sql runs DROP DATABASE IF EXISTS $Database first -- confirm that's really what you want."
    }
} finally {
    Pop-Location
}
