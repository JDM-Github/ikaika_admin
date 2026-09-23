<#
.SYNOPSIS
  Exports an Airtable base into migrations/outputs/<base>/schema.sql + data.sql.

.DESCRIPTION
  Asks which base you want to migrate and whether you want schema, data, or
  both, then writes everything for that base into one folder:

      migrations/outputs/users_projects_prod/
          export/      raw Airtable JSON, downloaded attachments, Cloudinary cache
          schema.sql   the checked-in sql/<product>/ DDL, assembled into one file
          data.sql     generated from that export

  Pass -Target and -Produce to skip the prompts.

  Data is pulled live from Airtable (fetch -> Cloudinary upload -> SQL) for the
  bases that have a generator. Schema is never generated -- it is the checked-in
  DDL under sql/, copied here so one folder holds everything an import needs.

  The Airtable token only ever lives in this process's environment, for the fetch
  step alone. It is never written to disk, logged, or passed to another step.

  This script does not touch MySQL. It prints the import commands and stops,
  because schema.sql runs DROP DATABASE first.

.PARAMETER Target
  users-projects-prod | users-projects-test | project-estimator |
  transaction-tracker | custom. Prompted for when omitted.

.PARAMETER Produce
  both | data | schema. Prompted for when omitted.

.PARAMETER Token
  Airtable Personal Access Token. Falls back to migrations/.token.private.txt,
  then to a hidden prompt. Only needed when data is pulled from Airtable.

.PARAMETER BaseId
.PARAMETER Name
  -Target custom only: the base to fetch, and the outputs/ folder to write into.

.PARAMETER Database
  Override the database name that the assembled schema and the generated data
  target. Defaults to whatever the checked-in schema for that base already uses.

.PARAMETER Step
  all (default) | fetch | upload | generate -- resume a data pull part-way.

.EXAMPLE
  .\migrate.ps1
  .\migrate.ps1 -Target users-projects-prod -Produce both
  .\migrate.ps1 -Target users-projects-test -Produce data -Step generate
#>

[CmdletBinding()]
param(
    [string]$Target,
    [string]$Produce,
    [string]$Token,
    [string]$BaseId,
    [string]$Name,
    [string]$Database,
    [ValidateSet('all', 'fetch', 'upload', 'generate')]
    [string]$Step = 'all'
)

$ErrorActionPreference = 'Stop'
$scriptDir = $PSScriptRoot
$repoRoot = Split-Path -Parent $scriptDir

# Schema is a checked-in file per base, never generated -- SchemaFiles lists what
# to concatenate. Generator is the script that turns an Airtable export into SQL;
# DataSnapshot is the checked-in data.sql to fall back on for a base that has no
# generator yet. Either can be $null: the base is then only partly migratable.
$targets = @(
    @{
        Key          = 'users-projects-prod'
        Label        = 'Users & Projects -- PRODUCTION (real live data)'
        Folder       = 'users_projects_prod'
        BaseId       = 'app8jGTzRDQt4yPIa'
        Database     = 'test_portal_database'
        SchemaFiles  = @('sql/portal/schema.sql', 'sql/portal/separate.sql')
        Generator    = 'generate/generate_sql_users_projects.py'
        DataSnapshot = $null
    },
    @{
        Key          = 'users-projects-test'
        Label        = 'Users & Projects -- TEST (sandbox snapshot)'
        Folder       = 'users_projects_test'
        BaseId       = 'app8DvnFZErPZT5Az'
        Database     = 'test_portal_database'
        SchemaFiles  = @('sql/portal/schema.sql', 'sql/portal/separate.sql')
        Generator    = 'generate/generate_sql_users_projects.py'
        DataSnapshot = $null
    },
    @{
        Key          = 'project-estimator'
        Label        = 'Project Estimator'
        Folder       = 'project_estimator'
        BaseId       = 'appgh0Mki2uHDLAQY'
        Database     = 'test_estimator_database'
        SchemaFiles  = @('sql/project_estimator/schema.sql')
        Generator    = $null
        DataSnapshot = 'sql/project_estimator/data.sql'
    },
    @{
        Key          = 'transaction-tracker'
        Label        = 'Transaction Tracker'
        Folder       = 'transaction_tracker'
        BaseId       = 'appp5MogesHN2eVvG'
        Database     = 'test_tracker_database'
        SchemaFiles  = @('sql/transaction_tracker/schema.sql')
        Generator    = 'generate/generate_sql_transaction_tracker.py'
        DataSnapshot = $null
    },
    @{
        Key          = 'custom'
        Label        = 'Custom -- another base ID'
        Folder       = $null
        BaseId       = $null
        Database     = $null
        SchemaFiles  = @()
        Generator    = $null
        DataSnapshot = $null
    }
)

$produceModes = @(
    @{ Key = 'both';   Label = 'Schema and data'; Schema = $true;  Data = $true },
    @{ Key = 'data';   Label = 'Data only';       Schema = $false; Data = $true },
    @{ Key = 'schema'; Label = 'Schema only';     Schema = $true;  Data = $false }
)

function Stop-WithMessage {
    param([string]$Message, [int]$Code = 1)

    Write-Host ''
    Write-Host $Message -ForegroundColor Red
    exit $Code
}

function Read-Choice {
    param([string]$Title, [string[]]$Options)

    Write-Host ''
    Write-Host $Title -ForegroundColor Cyan
    for ($i = 0; $i -lt $Options.Count; $i++) {
        Write-Host ('  {0}. {1}' -f ($i + 1), $Options[$i])
    }
    while ($true) {
        $answer = Read-Host "Choose 1-$($Options.Count)"
        $parsed = 0
        if ([int]::TryParse($answer, [ref]$parsed) -and $parsed -ge 1 -and $parsed -le $Options.Count) {
            return $parsed - 1
        }
        Write-Host "  Enter a number between 1 and $($Options.Count)." -ForegroundColor Yellow
    }
}

function Resolve-Selection {
    param([string]$Requested, $Choices, [string]$Title, [string]$What)

    if ($Requested) {
        $match = $Choices | Where-Object { $_.Key -eq $Requested }
        if (-not $match) {
            Stop-WithMessage "Unknown $What '$Requested'. Valid: $(($Choices.Key) -join ', ')"
        }
        return $match
    }
    return $Choices[(Read-Choice -Title $Title -Options ($Choices | ForEach-Object { $_.Label }))]
}

function Invoke-Python {
    param([string]$Label, [string[]]$Arguments)

    Write-Host "==> $Label" -ForegroundColor Cyan
    python @Arguments
    if ($LASTEXITCODE -ne 0) {
        Stop-WithMessage "$Label failed (exit $LASTEXITCODE). Stopping -- nothing after this step ran." $LASTEXITCODE
    }
}

function Build-Schema {
    param($Selected, [string]$OutFile, [string]$DatabaseName)

    $sources = $Selected.SchemaFiles | ForEach-Object { "--   $_" }
    $header = @"
-- ============================================================================
-- $($Selected.Label) -- schema
--
-- Assembled by migrations/migrate.ps1 on $(Get-Date -Format 'yyyy-MM-dd HH:mm') from:
$($sources -join "`n")
--
-- Run this before data.sql. It DROPS and recreates ``$DatabaseName``.
--
-- sql/*/indexes.sql is deliberately left out: it only retrofits an index onto
-- databases that predate it, and the schema below already creates that index on
-- a fresh install -- importing it here would fail with Duplicate key name and
-- abort everything after it in this file.
-- ============================================================================

"@

    $body = foreach ($relative in $Selected.SchemaFiles) {
        $path = Join-Path $repoRoot $relative
        if (-not (Test-Path $path)) {
            Stop-WithMessage "Schema source not found: $path"
        }
        "-- ---------------------------------------------------------------------------`n" +
        "-- $relative`n" +
        "-- ---------------------------------------------------------------------------`n`n" +
        (Get-Content -Raw -Encoding UTF8 -Path $path) + "`n"
    }

    $sql = $header + ($body -join "`n")

    # Only rewrite the database name where it is a statement's own identifier, so a
    # rename can never reach into string data that happens to contain the old name.
    if ($DatabaseName -ne $Selected.Database) {
        $old = [regex]::Escape($Selected.Database)
        $sql = $sql -replace "(?im)^(\s*(?:DROP\s+DATABASE\s+IF\s+EXISTS|CREATE\s+DATABASE(?:\s+IF\s+NOT\s+EXISTS)?|USE)\s+)$old\b", "`${1}$DatabaseName"
    }

    Set-Content -Path $OutFile -Value $sql -Encoding UTF8 -NoNewline
    Write-Host "    wrote $OutFile" -ForegroundColor Green
}

$selected = Resolve-Selection -Requested $Target -Choices $targets `
    -Title 'What do you want to migrate?' -What 'target'
$mode = Resolve-Selection -Requested $Produce -Choices $produceModes `
    -Title 'What do you want out of it?' -What 'produce mode'

if ($selected.Key -eq 'custom') {
    if (-not $BaseId) { $BaseId = Read-Host 'Airtable base ID (appXXXXXXXXXXXXXX)' }
    if (-not $Name) { $Name = Read-Host 'Folder name under outputs/' }
    if (-not $BaseId -or -not $Name) {
        Stop-WithMessage 'A custom target needs both a base ID and a folder name.'
    }
    $selected.BaseId = $BaseId.Trim()
    $selected.Folder = $Name.Trim()
}

if (-not $Database) { $Database = $selected.Database }
$databaseName = $Database

$targetDir = Join-Path (Join-Path $scriptDir 'outputs') $selected.Folder
$exportDir = Join-Path $targetDir 'export'
New-Item -ItemType Directory -Force -Path $targetDir | Out-Null

Write-Host ''
Write-Host "Base    : $($selected.Label)" -ForegroundColor White
Write-Host "Base ID : $($selected.BaseId)"
Write-Host "Output  : $targetDir"
if ($databaseName) { Write-Host "Database: $databaseName" }

$python = Get-Command python -ErrorAction SilentlyContinue
if (-not $python -and $mode.Data) {
    Stop-WithMessage 'python was not found on PATH.'
}

Push-Location $scriptDir
try {
    if ($mode.Data) {
        if ($selected.Generator) {
            if ($Step -in @('all', 'fetch')) {
                if (-not $Token) {
                    $tokenFile = Join-Path $scriptDir '.token.private.txt'
                    if (Test-Path $tokenFile) {
                        $Token = (Get-Content -Raw -Path $tokenFile).Trim()
                        Write-Host 'Using the token in .token.private.txt.' -ForegroundColor DarkGray
                    }
                }
                if (-not $Token) {
                    $secure = Read-Host 'Airtable Personal Access Token' -AsSecureString
                    $Token = [System.Net.NetworkCredential]::new('', $secure).Password
                }
                if (-not $Token) {
                    Stop-WithMessage 'A token is required to fetch from Airtable.'
                }

                $env:AIRTABLE_API_KEY = $Token
                try {
                    Invoke-Python -Label "Fetching $($selected.BaseId) into export/" `
                        -Arguments @('fetch_airtable.py', '--base-id', $selected.BaseId, '--output-dir', $exportDir)
                } finally {
                    Remove-Item Env:\AIRTABLE_API_KEY -ErrorAction SilentlyContinue
                }
            }

            if ($Step -in @('all', 'upload')) {
                Invoke-Python -Label 'Uploading attachments to Cloudinary' `
                    -Arguments @('upload_attachments_to_cloudinary.py', '--export-dir', $exportDir)
            }

            if ($Step -in @('all', 'generate')) {
                Invoke-Python -Label 'Generating data.sql' -Arguments @(
                    $selected.Generator,
                    '--export-dir', $exportDir,
                    '--output', (Join-Path $targetDir 'data.sql'),
                    '--database', $databaseName,
                    '--cloudinary-cache', (Join-Path $exportDir 'cloudinary_uploads.json')
                )
            }
        }
        elseif ($Step -eq 'fetch') {
            Invoke-Python -Label "Fetching $($selected.BaseId) into export/ (raw export only)" `
                -Arguments @('fetch_airtable.py', '--base-id', $selected.BaseId, '--output-dir', $exportDir)
            Write-Host "No generator exists for this base yet, so no data.sql was written." -ForegroundColor Yellow
            Write-Host "Inspect the export with: python inspect_schema.py --export-dir $exportDir" -ForegroundColor Yellow
        }
        elseif ($selected.DataSnapshot) {
            $snapshot = Join-Path $repoRoot $selected.DataSnapshot
            Copy-Item -Path $snapshot -Destination (Join-Path $targetDir 'data.sql') -Force
            Write-Host "==> Copied data.sql from $($selected.DataSnapshot)" -ForegroundColor Cyan
            Write-Host "    That file is a checked-in snapshot, not a fresh Airtable pull -- this base" -ForegroundColor Yellow
            Write-Host "    has no generator yet. Re-run with -Step fetch to pull the raw export." -ForegroundColor Yellow
        }
        else {
            Write-Host ''
            Write-Host "No data.sql was written: this base has neither a generator nor a checked-in" -ForegroundColor Yellow
            Write-Host "snapshot. Run '.\migrate.ps1 -Target $($selected.Key) -Produce data -Step fetch'" -ForegroundColor Yellow
            Write-Host "to pull the raw export, then build a schema and a generator for it (NOTE.md" -ForegroundColor Yellow
            Write-Host "describes this base's tables and the fields worth omitting as computed)." -ForegroundColor Yellow
        }
    }

    if ($mode.Schema) {
        if ($selected.SchemaFiles.Count -gt 0) {
            Write-Host '==> Assembling schema.sql' -ForegroundColor Cyan
            Build-Schema -Selected $selected -OutFile (Join-Path $targetDir 'schema.sql') -DatabaseName $databaseName
        }
        else {
            Write-Host ''
            Write-Host "No schema.sql was written: no checked-in DDL exists for this base yet." -ForegroundColor Yellow
        }
    }
}
finally {
    Pop-Location
}

Write-Host ''
if (Test-Path $exportDir) {
    Write-Host "$exportDir holds real downloaded files (bank certificates, SSS/PhilHealth/TIN," -ForegroundColor Yellow
    Write-Host "employee photos). migrations/outputs/ is gitignored -- keep it that way." -ForegroundColor Yellow
    Write-Host ''
}

$schemaOut = Join-Path $targetDir 'schema.sql'
$dataOut = Join-Path $targetDir 'data.sql'

# A base with nothing to migrate yet should not leave an empty folder behind.
if (-not (Get-ChildItem -Force -Path $targetDir)) {
    Remove-Item -Path $targetDir
}

if ((Test-Path $schemaOut) -or (Test-Path $dataOut)) {
    Write-Host 'This script does not import into MySQL. Review the output, then run, in order:' -ForegroundColor Cyan
    if (Test-Path $schemaOut) {
        Write-Host "  mysql -u root -pPASSWORD --default-character-set=utf8mb4 < `"$schemaOut`""
    }
    if (Test-Path $dataOut) {
        Write-Host "  mysql -u root -pPASSWORD --default-character-set=utf8mb4 < `"$dataOut`""
    }
    if (Test-Path $schemaOut) {
        Write-Host "schema.sql runs DROP DATABASE IF EXISTS $databaseName first -- confirm that's really what you want."
    }
}