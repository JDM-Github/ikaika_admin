# Applies this repository's sql/ folder to the local databases, and runs Laravel's migrations.
#
# WHAT THERE IS TO APPLY IS FOUND, NOT LISTED. The products are config/products.php's catalog, each
# with the sql/ folder named after its key (project-estimator is sql/project_estimator), and a step
# is any .sql file in that folder - schema, separate, indexes, data - or the patches/ folder beside
# them, whose files run in name order. A product or a file added later is picked up without an edit
# here; that is the whole difference from the version that never learned about the tracker.
#
# THE ORDER IS READ FROM THE FILES, never from the order switches are typed. What a file does decides
# where it goes:
#     rebuild    drops the database (DROP DATABASE)              first - it is what creates it
#     structure  creates tables (CREATE TABLE)
#     retrofit   alters what is there (ALTER TABLE, CREATE INDEX)
#     data       only inserts rows
#     patch      anything under patches/                          last, in name order
# A file that needs something else first says so in its own header, and that line wins:
#     -- update-database: after data.sql
# sql/portal/separate.sql carries one, because its grants need the employees data.sql loads.
#
# HOW A FILE RUNS FOLLOWS FROM ITS KIND. mysql stops at its first error, so a retrofit or a patch
# that meets an index or column already there would never reach the statements after it; those files
# run past every error, and only an error that is not "already there" counts as a failure. A rebuild
# or a data file runs strictly - a data file inserts rather than merges, so carrying on past a
# duplicate would double every table without a unique key.
#
# A REBUILD DESTROYS. Every rebuild file opens with DROP DATABASE, so it is refused on a database
# that has tables; on one that does not exist yet it only creates it. -DropAndRecreate is how a
# database that has tables is built again.
#
# THREE SWITCHES TOUCH A WHOLE DATABASE, each named after what it does:
#     -Drop             removes each picked product's database completely, as phpMyAdmin's Drop does;
#                       nothing is built back
#     -Idempotent       brings databases up to date, skipping what is already applied; never rebuilds
#     -DropAndRecreate  the two in that order: removed, then built up to date - every step, or the -Step
#                       ones, and the migrations. -Drop -Idempotent is the same thing.
# A drop takes only what is named, -Product or -AllProducts, never every database by itself. Core is
# dropped like any product when it is picked, and its database is Laravel's own - the migrations
# table among it - which migrate builds back. What described a dropped database goes with it: its
# entries in the local ledger, its product's rows in the core ledger (actions, recycle), and the
# record of every migration that writes to it, so the next migrate runs those again. Plain -Drop
# takes no -Step or -Migrate: a migrate with the database gone would record migrations that skipped it.
#
# WITH NOTHING PICKED NOTHING IS APPLIED. Every step is listed with its kind, the database it
# targets, its size and what it would destroy, and the run stops there.
#
# THE FILES NAME THEIR OWN DATABASE with a USE line, and ignore .env; a disagreement is reported per
# file, since otherwise it reads as "I updated the database and the app saw nothing". A file with no
# USE line runs against the product's .env database.
#
#     .\script.ps1 update-database                                        list everything, change nothing
#     .\script.ps1 update-database -Product portal -Step data             load the portal rows
#     .\script.ps1 update-database -Product transaction_tracker -Step patches
#     .\script.ps1 update-database -Product transaction_tracker -Drop       remove that database, nothing built back
#     .\script.ps1 update-database -Product portal -DropAndRecreate         the portal from nothing, up to date
#     .\script.ps1 update-database -Idempotent                            bring every database up to date
#     .\script.ps1 update-database -Migrate                               php artisan migrate
#
# Requires PowerShell 7+, a mysql client on PATH (environment-variables puts one there), and a running
# database (run-servers starts one).

param(
    # The products to apply, by sql/ folder; the folders that hold .sql files are offered.
    [string[]]$Product = @(),
    # Every product.
    [switch]$AllProducts,
    # The steps to apply, by file name without .sql, or patches; the ones the folders hold are offered.
    [string[]]$Step = @(),
    # Remove each picked product's database completely; nothing is built back. Takes no -Step or -Migrate.
    [switch]$Drop,
    # Bring every database up to date and skip what is already applied, so a second run does nothing.
    # Picks every product, every step and the migrations when nothing else is named; never rebuilds.
    [switch]$Idempotent,
    # -Drop, then -Idempotent: each picked product's database removed, then built up to date - every step, or the -Step ones, and the migrations.
    [switch]$DropAndRecreate,
    # php artisan migrate: additive, and the supported way to update an existing deployment.
    [switch]$Migrate,
    # List what would run, in order, and run nothing.
    [switch]$DryRun,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'

$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent
$libDir = Join-Path $repoRoot 'decoupled-script\lib'

. (Join-Path $libDir 'logging.ps1')
. (Join-Path $libDir 'load-env.ps1')
. (Join-Path $configsDir 'lib\wamp.ps1')
. (Join-Path $configsDir 'lib\laravel.ps1')

if ($Help) {
    Write-Host @'

  update-database - apply sql/ to the local databases, in the order the files call for.

  Usage
    .\script.ps1 update-database [-Product <folder>[,...] | -AllProducts] [-Step <name>[,...]]
                                 [-Drop | -DropAndRecreate] [-Idempotent] [-Migrate] [-DryRun]

  With nothing picked it lists every product's steps and applies none.

  Products are config/products.php's catalog; a product's steps are the .sql
  files in sql/<folder>, and patches for the files in sql/<folder>/patches.

  Steps run in the order their contents call for, whatever order they are typed:
    rebuild -> structure -> retrofit -> data -> patch
  and a file's own "-- update-database: after <file>" line moves it later.

  -Drop removes each picked product's database completely, as phpMyAdmin's
  Drop does, and builds nothing back. It takes no -Step or -Migrate.

  -Idempotent means everything - every product, every step, the migrations -
  skipping what is already applied and never rebuilding. A second run does
  nothing.

  -DropAndRecreate is the two in that order: each picked product's database
  removed, then built up to date - every step, or the -Step ones, and the
  migrations. -Drop -Idempotent is the same thing.

  A drop takes only the products named, and core when it is picked - its
  database is also Laravel's own, migrations table and all. The product's rows
  in the core ledger and the record of every migration that writes to a
  dropped database go with it, so the next migrate runs those again. A rebuild
  file - one that opens with DROP DATABASE - is refused on a database that has
  tables; -DropAndRecreate is how one is built again.

  Examples
    .\script.ps1 update-database -Product portal -Step data
    .\script.ps1 update-database -Product transaction_tracker -Step patches
    .\script.ps1 update-database -Product transaction_tracker -Drop
    .\script.ps1 update-database -Product portal -DropAndRecreate
    .\script.ps1 update-database -Product portal,transaction_tracker -DropAndRecreate -DryRun
    .\script.ps1 update-database -AllProducts -Step indexes,patches -DryRun

  The databases and credentials come from .env, as config/products.php reads them.

'@
    exit 0
}

Start-BuildLog 'update-database'

$sqlRoot = Join-Path $repoRoot 'sql'
$products = Get-ProductDatabase (Join-Path $repoRoot '.env')

# Errors that mean the statement's work is already there, so a retrofit or patch that meets them has
# nothing left to do on that line: table exists, duplicate column, key or row, primary key defined,
# nothing to drop, duplicate foreign key.
$AlreadyThere = @(1050, 1060, 1061, 1062, 1068, 1091, 1826)
$KindRank = [ordered]@{ rebuild = 0; structure = 1; retrofit = 2; other = 2; data = 3; patch = 4 }

# ------------------------------------------------------------------------ what a file is
function Get-SqlFileFacts([string]$Path, [bool]$IsPatch) {
    $lines = @(Get-Content -LiteralPath $Path)
    $count = { param($pattern) @($lines | Where-Object { $_ -match $pattern }).Count }
    $targets = @($lines | Where-Object { $_ -match '^\s*USE\s+`?([A-Za-z0-9_]+)`?\s*;' } | ForEach-Object { [void]($_ -match '^\s*USE\s+`?([A-Za-z0-9_]+)'); $Matches[1] })
    $after = @($lines | Where-Object { $_ -match '^\s*--\s*update-database:\s*after\s+(.+)$' } |
            ForEach-Object { [void]($_ -match 'after\s+(.+)$'); $Matches[1] -split '[,\s]+' } | Where-Object { $_ })
    $facts = [pscustomobject]@{
        Path         = $Path
        Bytes        = (Get-Item -LiteralPath $Path).Length
        Target       = $(if ($targets.Count) { $targets[0] } else { '' })
        Drops        = & $count '^\s*DROP\s+DATABASE'
        Truncates    = & $count '^\s*TRUNCATE'
        CreateTables = & $count '^\s*CREATE\s+TABLE'
        Alters       = (& $count '^\s*ALTER\s+TABLE') + (& $count '^\s*CREATE\s+(UNIQUE\s+)?INDEX')
        Inserts      = & $count '^\s*INSERT'
        After        = $after
        Kind         = ''
    }
    $facts.Kind = if ($IsPatch) { 'patch' }
    elseif ($facts.Drops) { 'rebuild' }
    elseif ($facts.CreateTables) { 'structure' }
    elseif ($facts.Alters) { 'retrofit' }
    elseif ($facts.Inserts) { 'data' }
    else { 'other' }
    return $facts
}

# A product's steps in the order they run: by kind, then name, then each file's own "after" lines.
function Get-ProductStep([string]$FolderPath) {
    $steps = [Collections.Generic.List[object]]::new()
    foreach ($file in @(Get-ChildItem -LiteralPath $FolderPath -Filter '*.sql' -File | Sort-Object Name)) {
        $facts = Get-SqlFileFacts $file.FullName $false
        $steps.Add([pscustomobject]@{ Name = $file.BaseName; Label = $file.Name; Facts = $facts })
    }
    $patchDir = Join-Path $FolderPath 'patches'
    if (Test-Path -LiteralPath $patchDir) {
        foreach ($file in @(Get-ChildItem -LiteralPath $patchDir -Filter '*.sql' -File | Sort-Object Name)) {
            $steps.Add([pscustomobject]@{ Name = 'patches'; Label = "patches/$($file.Name)"; Facts = (Get-SqlFileFacts $file.FullName $true) })
        }
    }
    $ordered = [Collections.Generic.List[object]]::new()
    foreach ($s in @($steps | Sort-Object { $KindRank[$_.Facts.Kind] }, Label)) { $ordered.Add($s) }
    # Each pass moves a file after anything it names that still comes later; a cycle stops at the cap.
    for ($pass = 0; $pass -lt $ordered.Count; $pass++) {
        $moved = $false
        foreach ($s in @($ordered)) {
            foreach ($dependency in $s.Facts.After) {
                $at = $ordered.IndexOf($s)
                $depAt = -1
                for ($i = 0; $i -lt $ordered.Count; $i++) { if ($ordered[$i].Label -eq $dependency -or $ordered[$i].Name -eq ($dependency -replace '\.sql$', '')) { $depAt = $i } }
                if ($depAt -gt $at) {
                    $ordered.RemoveAt($at)
                    $ordered.Insert($depAt, $s)
                    $moved = $true
                }
            }
        }
        if (-not $moved) { break }
    }
    return , $ordered
}

# ------------------------------------------------------------------------ the server
function Invoke-MysqlQuery([object]$Product, [string]$Query) {
    $mysql = Get-Command mysql -ErrorAction SilentlyContinue
    if (-not $mysql) { return $null }
    $arguments = (Get-MysqlArgument $Product) + @('--batch', '--skip-column-names', '--execute', $Query)
    try {
        $answer = @(& $mysql.Source @arguments 2>$null | ForEach-Object { "$_".Trim() } | Where-Object { $_ })
        if ($LASTEXITCODE -ne 0) { return $null }
        return , $answer
    } catch { return $null }
}

# Tables in a database, or -1 when the server cannot be asked - which callers read as "assume it has
# some", since refusing a rebuild is the safe way to be wrong.
function Get-DatabaseTableCount([object]$Product, [string]$Database) {
    if (-not $Database) { return -1 }
    $answer = Invoke-MysqlQuery $Product "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$($Database -replace "'", "''")'"
    if ($null -eq $answer -or "$($answer[0])" -notmatch '^\d+$') { return -1 }
    return [int]$answer[0]
}

function Get-PresentTable([object]$Product, [string]$Database) {
    $answer = Invoke-MysqlQuery $Product "SELECT table_name FROM information_schema.tables WHERE table_schema = '$($Database -replace "'", "''")'"
    if ($null -eq $answer) { return @() }
    return $answer
}

# Runs one file. Redirected through cmd rather than piped from PowerShell, which re-encodes the file
# and corrupts every non-ASCII byte of a large dump. Returns the exit code and every ERROR line.
function Invoke-SqlFile([object]$Product, [object]$Facts, [bool]$Force) {
    $mysql = (Get-Command mysql).Source
    $arguments = Get-MysqlArgument $Product
    if (-not $Facts.Target) { $arguments += $Product.Database }
    $arguments += '--default-character-set=utf8mb4'
    if ($Force) { $arguments += '--force' }
    $quoted = ($arguments | ForEach-Object { '"' + $_ + '"' }) -join ' '
    $output = @(& cmd.exe '/c' "`"$mysql`" $quoted < `"$($Facts.Path)`" 2>&1")
    $exit = $LASTEXITCODE
    $errors = @($output | ForEach-Object { "$_".Trim() } | Where-Object { $_ -match '^ERROR \d+' })
    return [pscustomobject]@{ Exit = $exit; Errors = $errors; First = "$(@($output | Where-Object { "$_".Trim() })[0])".Trim() }
}

function Get-ErrorCode([string]$Line) {
    if ($Line -match '^ERROR (\d+)') { return [int]$Matches[1] }
    return 0
}

# The databases each migration writes to: the product connections it names as a call's first argument
# or as its $connection, else Laravel's default. One counted against a database it does not touch only
# runs again, and the migrations that write to a product check before they write.
function Get-MigrationTarget([hashtable]$ConnectionDatabase, [string]$DefaultDatabase) {
    $dir = Join-Path $repoRoot 'database\migrations'
    if (-not (Test-Path -LiteralPath $dir)) { return @() }
    return @(Get-ChildItem -LiteralPath $dir -Filter '*.php' -File | Sort-Object Name | ForEach-Object {
            $text = Get-Content -LiteralPath $_.FullName -Raw
            $named = @($ConnectionDatabase.Keys | Where-Object { $text -match "(\(\s*|\`$connection\s*=\s*)['""]$([regex]::Escape($_))['""]" })
            [pscustomobject]@{ Name = $_.BaseName; Databases = @(if ($named.Count) { $named | ForEach-Object { $ConnectionDatabase[$_] } } else { $DefaultDatabase }) }
        })
}

# ------------------------------------------------------------------------ the ledger
# What -Idempotent skips: which file, at which content hash, was applied to which database. A local
# file rather than a table, so no product schema carries a table that belongs to a build tool; the
# hash, not the name, so a file edited after it was applied counts as unapplied again; and believed
# only while the database still has tables, since anything can have happened to it in between.
$LedgerPath = Join-Path $configsDir '.sql-applied.json'

function Get-Ledger {
    if (-not (Test-Path -LiteralPath $LedgerPath)) { return @{} }
    try { $raw = Get-Content -LiteralPath $LedgerPath -Raw | ConvertFrom-Json } catch { return @{} }
    $ledger = @{}
    foreach ($property in $raw.PSObject.Properties) { $ledger[$property.Name] = $property.Value }
    return $ledger
}

function Save-Ledger([hashtable]$Ledger) {
    if (-not $Ledger.Count) { Remove-Item -LiteralPath $LedgerPath -Force -ErrorAction SilentlyContinue; return }
    ($Ledger | ConvertTo-Json -Depth 4) | Set-Content -LiteralPath $LedgerPath -Encoding utf8
}

function Format-Size([long]$Bytes) {
    if ($Bytes -ge 1MB) { return "$([math]::Round($Bytes / 1MB, 1)) MB" }
    if ($Bytes -ge 1KB) { return "$([math]::Round($Bytes / 1KB)) KB" }
    return "$Bytes B"
}

# ------------------------------------------------------------------------ what was picked
if (-not (Test-Path -LiteralPath $sqlRoot)) {
    Write-Host "  There is no sql folder at $sqlRoot." -ForegroundColor Red
    exit 1
}
$folders = @($products.Keys | Where-Object { Test-Path -LiteralPath (Join-Path $sqlRoot $_) })
$orphans = @(Get-ChildItem -LiteralPath $sqlRoot -Directory | Where-Object { $folders -notcontains $_.Name -and (Get-ChildItem -LiteralPath $_.FullName -Filter '*.sql' -Recurse -File) } | ForEach-Object Name)

$wantedFolders = @($Product | ForEach-Object { "$_" -split ',' } | ForEach-Object { $_.Trim() -replace '-', '_' } | Where-Object { $_ })
$unknown = @($wantedFolders | Where-Object { $folders -notcontains $_ })
if ($unknown.Count) {
    Write-Host "  No product has a sql/ folder called $($unknown -join ', '); there are $($folders -join ', ')." -ForegroundColor Red
    exit 1
}
$wantedSteps = @($Step | ForEach-Object { "$_" -split ',' } | ForEach-Object { $_.Trim() -replace '\.sql$', '' } | Where-Object { $_ })
if ($AllProducts) { $wantedFolders = $folders }
$rebuild = $DropAndRecreate -or ($Drop -and $Idempotent)
$wiping = $Drop -or $DropAndRecreate
if ($wiping -and -not $wantedFolders.Count) {
    Write-Host '  A drop takes only what is named: -Product <folder>, or -AllProducts.' -ForegroundColor Red
    exit 1
}
if ($wiping -and -not $rebuild -and $wantedSteps.Count) {
    Write-Host '  -Drop removes the whole database, so it takes no -Step; -DropAndRecreate builds those steps back.' -ForegroundColor Red
    exit 1
}
if ($wiping -and -not $rebuild -and $Migrate) {
    Write-Host '  -Drop leaves the database gone, and migrate would record the migrations that skip it as run; -DropAndRecreate migrates once it is built back.' -ForegroundColor Red
    exit 1
}
# Built back up to date means the migrations too: the sql/ files make the tables, and what came since lives in a migration.
if ($rebuild) { $Idempotent = $true; $Migrate = $true }
# -Idempotent with nothing else named means everything, migrations included: every column added since
# the sql/ files were written lives in a migration, and artisan migrate skips what it has already run.
if ($Idempotent -and -not $wantedFolders.Count -and -not $wantedSteps.Count) {
    $wantedFolders = $folders
    $Migrate = $true
}
$everyStep = $Idempotent -and -not $wantedSteps.Count
$drops = @()

# Laravel's own database - the default connection, where the migrations table is - and the database behind each connection.
$envPath = Join-Path $repoRoot '.env'
$laravelDb = [pscustomobject]@{
    Name     = 'Laravel'
    Key      = ''
    Database = Get-DotEnvValue $envPath 'DB_DATABASE' 'laravel'
    DbHost   = Get-DotEnvValue $envPath 'DB_HOST' '127.0.0.1'
    Port     = Get-DotEnvValue $envPath 'DB_PORT' '3306'
    Username = Get-DotEnvValue $envPath 'DB_USERNAME' 'root'
    Password = Get-DotEnvValue $envPath 'DB_PASSWORD' ''
}
$connectionDatabase = @{}
foreach ($entry in $products.Values) { if ($entry.Connection) { $connectionDatabase[$entry.Connection] = $entry.Database } }

# ------------------------------------------------------------------------ the listing
Write-Host ''
Write-Host "  Update database - $(Get-ProjectValue 'app.displayName' 'this project')" -ForegroundColor Cyan
Write-Host "  $sqlRoot" -ForegroundColor DarkGray

$ledger = Get-Ledger
$ledgerTouched = $false
$plan = @()
$knownSteps = @()
foreach ($folder in $folders) {
    $db = $products[$folder]
    Write-Host ''
    Write-Host "  $($db.Name)  (sql/$folder)" -ForegroundColor Cyan
    Write-Host "    .env says $($db.Database) on $($db.DbHost):$($db.Port) as $($db.Username)" -ForegroundColor DarkGray
    $productSteps = Get-ProductStep (Join-Path $sqlRoot $folder)

    if ($wiping -and $wantedFolders -contains $folder) {
        $databases = @(@($productSteps | ForEach-Object { if ($_.Facts.Target) { $_.Facts.Target } else { $db.Database } }) + @($db.Database) | Select-Object -Unique)
        $odd = @($databases | Where-Object { $_ -notmatch '^[A-Za-z0-9_]+$' })
        if ($odd.Count) {
            Write-Host "  $($odd -join ', ') is not a plain database name, so a drop will not touch it." -ForegroundColor Red
            exit 1
        }
        $sizes = @($databases | ForEach-Object { $count = Get-DatabaseTableCount $db $_; "$_ ($(if ($count -lt 0) { 'could not ask the server' } elseif ($count -eq 0) { 'no tables' } else { "$count table(s) go" }))" })
        Write-Host '    DROP     ' -ForegroundColor Yellow -NoNewline
        Write-Host "$($sizes -join ', ') - $(if ($rebuild) { 'removed, then built up to date' } else { 'removed completely; nothing is built back' })"
        if ($databases -contains $laravelDb.Database) {
            Write-Host "             Laravel's own tables go with it, migrations included$(if ($rebuild) { ' - migrate builds them back' } else { " - -Product $folder -DropAndRecreate builds them back" })" -ForegroundColor Yellow
        }
        $drops += [pscustomobject]@{ Folder = $folder; Product = $db; Databases = $databases }
    }

    foreach ($s in $productSteps) {
        $facts = $s.Facts
        $knownSteps += $s.Name
        $target = if ($facts.Target) { $facts.Target } else { $db.Database }
        # Rebuilt after its drop, the database is empty when its steps run: nothing applied, nothing to destroy.
        $afterDrop = $rebuild -and $drops.Folder -contains $folder
        $liveTables = if ($afterDrop) { 0 } elseif ($facts.Drops) { Get-DatabaseTableCount $db $target } else { -1 }
        $wouldDestroy = $facts.Drops -and $liveTables -ne 0

        $notes = @()
        if ($facts.Drops -and $liveTables -eq 0) { $notes += 'creates the database - nothing there to destroy' }
        elseif ($facts.Drops -and $liveTables -gt 0) { $notes += "DROPS THE DATABASE ($liveTables table(s) would go)" }
        elseif ($facts.Drops) { $notes += 'DROPS THE DATABASE (could not reach the server to see what is in it)' }
        if ($facts.Truncates) { $notes += "truncates $($facts.Truncates) table(s)" }
        if ($facts.After.Count) { $notes += "after $($facts.After -join ', ')" }
        if ($facts.Target -and $facts.Target -ne $db.Database) { $notes += "writes to $($facts.Target), but .env reads $($db.Database)" }
        if (-not $facts.Target) { $notes += "no USE line - runs against $($db.Database)" }

        $picked = ($wantedFolders -contains $folder) -and ($everyStep -or ($wantedSteps -contains $s.Name))
        $key = "$folder/$($s.Label)"
        $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $facts.Path).Hash.ToLower()
        $applied = $false
        if ($Idempotent -and $picked -and -not $afterDrop) {
            $entry = $ledger["$target|$key"]
            $applied = $entry -and "$($entry.sha256)" -eq $hash -and (Get-DatabaseTableCount $db $target) -gt 0
        }
        # Up to date never means emptied: under -Idempotent a rebuild that would destroy is left alone.
        $kept = $picked -and $wouldDestroy -and $Idempotent
        $blocked = $picked -and $wouldDestroy -and -not $Idempotent

        $state = if ($applied) { 'applied' } elseif ($kept) { 'kept' } elseif ($blocked) { 'blocked' } elseif ($picked) { 'will run' } else { 'listed' }
        if ($applied) { $notes = @('already applied, unchanged since') }
        if ($kept) { $notes += 'left alone - -DropAndRecreate rebuilds it' }
        $colour = @{ 'listed' = 'DarkGray'; 'will run' = 'Green'; 'blocked' = 'Yellow'; 'applied' = 'DarkGray'; 'kept' = 'DarkGray' }
        Write-Host ('    {0,-9}' -f $state) -ForegroundColor $colour[$state] -NoNewline
        Write-Host ('{0,-38} {1,-10} {2,-8} {3}' -f $s.Label, $facts.Kind, (Format-Size $facts.Bytes), ($notes -join '; '))
        if ($blocked) { Write-Host '                 -DropAndRecreate rebuilds a database that has tables' -ForegroundColor DarkGray }
        if ($picked -and -not $blocked -and -not $applied -and -not $kept) {
            $plan += [pscustomobject]@{ Product = $db; Folder = $folder; Step = $s; Facts = $facts; Target = $target; Key = $key; Hash = $hash }
        }
    }
}
foreach ($orphan in $orphans) {
    Write-Host ''
    Write-Host "  sql/$orphan has .sql files but no product in config/products.php, so nothing knows its database; it is skipped." -ForegroundColor Yellow
}

# The migration records that describe a dropped database, forgotten so migrate runs them again - unless the
# migrations table is in a dropped database itself, in which case every record goes with it.
function Get-ForgottenMigration([string[]]$Databases) {
    if (-not $Databases.Count -or $Databases -contains $laravelDb.Database) { return @() }
    return @(Get-MigrationTarget $connectionDatabase $laravelDb.Database |
            Where-Object { @($_.Databases | Where-Object { $Databases -contains $_ }).Count -and $_.Name -match '^[A-Za-z0-9_]+$' } | ForEach-Object Name)
}
$droppedDatabases = @($drops | ForEach-Object { $_.Databases } | Select-Object -Unique)
$coreGone = $products.Contains('core') -and $droppedDatabases -contains $products['core'].Database
$forget = Get-ForgottenMigration $droppedDatabases
if ($forget.Count) {
    Write-Host ''
    Write-Host "  Migrations  $($forget.Count) record(s) in $($laravelDb.Database).migrations write to a dropped database; they are forgotten, so migrate runs them again" -ForegroundColor DarkGray
}

$strayStep = @($wantedSteps | Where-Object { $knownSteps -notcontains $_ })
if ($strayStep.Count) {
    Write-Host ''
    Write-Host "  No product has a step called $($strayStep -join ', '); the steps are $(@($knownSteps | Select-Object -Unique) -join ', ')." -ForegroundColor Red
    exit 1
}

if (-not $plan.Count -and -not $drops.Count -and -not $Migrate) {
    Write-Host ''
    if ($Idempotent) { Write-Host '  Everything is already applied - nothing to do.' -ForegroundColor Green }
    elseif (-not $wantedFolders.Count -or -not $wantedSteps.Count) {
        Write-Host '  Nothing applied. Pick a product (-Product, or -AllProducts) and a step (-Step), or use -Migrate or -Idempotent.' -ForegroundColor Yellow
    } else { Write-Host '  Nothing to apply for what was picked - see the listing above.' -ForegroundColor Yellow }
    Write-Host ''
    exit 0
}

if ($DryRun) {
    Write-Host ''
    Write-Host '  -DryRun: nothing ran. In order, this would apply:' -ForegroundColor Yellow
    foreach ($wipe in $drops) { Write-Host "    drop $($wipe.Databases -join ', ') ($($wipe.Folder))$(if ($rebuild) { ', and create it empty' })$(if ($wipe.Folder -ne 'core' -and -not $coreGone) { ", then clear $($wipe.Product.Key)'s core ledger rows" })" }
    if ($forget.Count) { Write-Host "    forget $($forget.Count) migration record(s) in $($laravelDb.Database).migrations" }
    foreach ($item in $plan) { Write-Host "    $($item.Folder)/$($item.Step.Label) -> $($item.Target)$(if ($item.Facts.Kind -eq 'rebuild' -and $drops.Folder -notcontains $item.Folder) { ', then clear its core ledger rows' })" }
    if ($Migrate) { Write-Host '    php artisan migrate' }
    Write-Host ''
    exit 0
}

# ------------------------------------------------------------------------ applying
if (($plan.Count -or $drops.Count) -and -not (Get-Command mysql -ErrorAction SilentlyContinue)) {
    Write-Host ''
    Write-Host '  No mysql client on PATH, so no file can be applied.' -ForegroundColor Red
    Write-Host '    .\script.ps1 environment-variables     put one there' -ForegroundColor DarkGray
    Write-Host ''
    exit 1
}

$failures = 0
if ($plan.Count -or $drops.Count) { Write-Host ''; Write-Host '  Applying' -ForegroundColor Cyan }

function Write-Applied([object]$Item, [string]$Note) {
    if (-not $Idempotent) { return }
    $ledger["$($Item.Target)|$($Item.Key)"] = @{ sha256 = $Item.Hash; bytes = $Item.Facts.Bytes; note = $Note; applied_at = (Get-Date).ToString('o') }
    $script:ledgerTouched = $true
}

# The product's rows in the core ledger describe records its rebuilt database no longer has.
function Clear-CoreLedger([object]$Rebuilt) {
    $core = $products['core']
    if (-not $core -or @(Get-PresentTable $core $core.Database) -notcontains 'actions') { return $true }
    $productKey = $Rebuilt.Key -replace "'", "''"
    $cleared = Invoke-MysqlQuery $core "DELETE FROM ``$($core.Database)``.actions WHERE product = '$productKey'; DELETE FROM ``$($core.Database)``.recycle WHERE product = '$productKey'; SELECT 'ok'"
    if ($null -eq $cleared) {
        Write-Host "      could not clear $($Rebuilt.Key)'s rows from the core ledger in $($core.Database)" -ForegroundColor Yellow
        return $false
    }
    Write-Host "      cleared $($Rebuilt.Key)'s rows from the core ledger (actions, recycle)" -ForegroundColor DarkGray
    return $true
}

$undropped = @()
$gone = @()
foreach ($wipe in $drops) {
    Write-Host "    drop $($wipe.Folder): $($wipe.Databases -join ', ')"
    # Created again empty only to be built back, so a step with no USE line and migrate have somewhere to write.
    $statements = ($wipe.Databases | ForEach-Object { "DROP DATABASE IF EXISTS ``$_``;$(if ($rebuild) { " CREATE DATABASE ``$_`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" })" }) -join ' '
    if ($null -eq (Invoke-MysqlQuery $wipe.Product "$statements SELECT 'ok'")) {
        Write-Host "      FAILED - could not drop $($wipe.Databases -join ', ')$(if ($rebuild) { '; its steps are skipped' })" -ForegroundColor Red
        $undropped += $wipe.Folder
        $failures++
        continue
    }
    Write-Host "      $(if ($rebuild) { 'dropped, and created empty for its steps' } else { 'dropped' })" -ForegroundColor Green
    $gone += $wipe.Databases
    # What the ledger said was applied to these databases went with them.
    foreach ($name in @($ledger.Keys | Where-Object { $wipe.Databases -contains ($_ -split '\|')[0] })) { $ledger.Remove($name); $ledgerTouched = $true }
    if ($wipe.Folder -ne 'core' -and -not $coreGone -and -not (Clear-CoreLedger $wipe.Product)) { $failures++ }
}
$forgetting = Get-ForgottenMigration $gone
if ($forgetting.Count -and @(Get-PresentTable $laravelDb $laravelDb.Database) -contains 'migrations') {
    $names = ($forgetting | ForEach-Object { "'$_'" }) -join ','
    if ($null -eq (Invoke-MysqlQuery $laravelDb "DELETE FROM ``$($laravelDb.Database)``.migrations WHERE migration IN ($names); SELECT 'ok'")) {
        Write-Host "      could not forget the migrations that write to what was dropped, in $($laravelDb.Database).migrations" -ForegroundColor Yellow
        $failures++
    } else {
        Write-Host "      forgot $($forgetting.Count) migration record(s) in $($laravelDb.Database).migrations, so migrate runs them again" -ForegroundColor DarkGray
    }
}

foreach ($item in $plan) {
    if ($undropped -contains $item.Folder) { continue }
    Write-Host "    $($item.Folder)/$($item.Step.Label)  ($($item.Facts.Kind))"
    $strict = $item.Facts.Kind -in @('rebuild', 'data')
    $run = Invoke-SqlFile $item.Product $item.Facts (-not $strict)
    $real = @($run.Errors | Where-Object { $AlreadyThere -notcontains (Get-ErrorCode $_) })
    $there = @($run.Errors | Where-Object { $AlreadyThere -contains (Get-ErrorCode $_) })

    if (-not $strict) {
        if ($real.Count) {
            foreach ($line in @($real | Select-Object -First 5)) { Write-Host "      $line" -ForegroundColor Red }
            if ($real.Count -gt 5) { Write-Host "      ...and $($real.Count - 5) more" -ForegroundColor Red }
            if ($real -match '^ERROR 1049') {
                $creator = @(Get-ProductStep (Join-Path $sqlRoot $item.Folder) | Where-Object { $_.Facts.Kind -eq 'rebuild' } | Select-Object -First 1)
                if ($creator.Count) { Write-Host "      $($item.Target) does not exist yet; $($creator[0].Label) creates it: -Product $($item.Folder) -Step $($creator[0].Name)" -ForegroundColor Yellow }
            }
            $failures++
            continue
        }
        Write-Host "      applied to $($item.Target)$(if ($there.Count) { " - $($there.Count) statement(s) already there, skipped" })" -ForegroundColor Green
        Write-Applied $item $(if ($there.Count -and $there.Count -eq $run.Errors.Count) { 'adopted' } else { 'applied' })
        continue
    }

    if ($run.Exit -eq 0) {
        Write-Host "      applied to $($item.Target)" -ForegroundColor Green
        Write-Applied $item 'applied'
        if ($item.Facts.Kind -eq 'rebuild' -and $item.Folder -ne 'core' -and $drops.Folder -notcontains $item.Folder) {
            if (-not (Clear-CoreLedger $item.Product)) { $failures++ }
        }
        continue
    }

    $why = if ($run.Errors.Count) { $run.Errors[0] } else { $run.First }
    Write-Host "      FAILED (exit $($run.Exit)) $why" -ForegroundColor Red
    if ((Get-ErrorCode $why) -eq 1062) {
        if ($Idempotent) {
            # Its rows are already there and the database predates the ledger: recorded, not reloaded.
            Write-Host '      already loaded - adopted into the ledger' -ForegroundColor DarkGray
            Write-Applied $item 'adopted'
            continue
        }
        Write-Host '      These rows are already loaded; a data file inserts rather than merges, so it only applies to an empty database:' -ForegroundColor Yellow
        Write-Host "        .\script.ps1 update-database -Product $($item.Folder) -DropAndRecreate" -ForegroundColor DarkGray
    } elseif ((Get-ErrorCode $why) -eq 1049) {
        Write-Host "      $($item.Target) does not exist yet; the product's rebuild step creates it." -ForegroundColor Yellow
    }
    $failures++
}

if ($Migrate) {
    Write-Host ''
    Write-Host '  Migrations' -ForegroundColor Cyan
    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        Write-Host '    php is not on PATH - run environment-variables' -ForegroundColor Red
        $failures++
    } else {
        Push-Location $repoRoot
        try {
            $output = & $php.Source 'artisan' 'migrate' '--no-interaction' 2>&1
            $migrateExit = $LASTEXITCODE
            $output | ForEach-Object { Write-Host "    $_" }
            if ($migrateExit -ne 0) {
                Write-Host "    artisan migrate exited with $migrateExit" -ForegroundColor Red
                # MySQL does not roll back DDL, so a migration that stopped part way leaves its tables, and the retry trips on them.
                if ("$output" -match 'already exists') {
                    Write-Host '    A previous migration stopped part way and left its tables behind. Drop the table the' -ForegroundColor Yellow
                    Write-Host '    error names, or rebuild from nothing: php artisan migrate:fresh (DESTROYS every table on that connection)' -ForegroundColor Yellow
                }
                # 1071 on a fresh WampServer is MyISAM's 1000-byte index cap, not the schema.
                if ("$output" -match 'key was too long|1071') {
                    Write-Host '    That is MyISAM, not your schema: switch WampServer to InnoDB and restart MySQL - .\script.ps1 development-setup' -ForegroundColor Yellow
                }
                $failures++
            }
        } finally { Pop-Location }
    }
}

if ($ledgerTouched) { Save-Ledger $ledger }

Write-Host ''
if ($failures) { Write-Host "  $failures step(s) failed - see above." -ForegroundColor Red }
else { Write-Host '  Done.' -ForegroundColor Green }
Write-Host '    .\script.ps1 update-database          list every step again' -ForegroundColor DarkGray
Write-Host '    .\script.ps1 run-servers -PhpMyAdmin  look at the result' -ForegroundColor DarkGray
Write-Host ''
exit $failures

# Read by the manifest generator and run alone, so it finds the repo from the working directory itself.
function Get-DialogChoiceParameters { return , @('Product', 'Step') }

function Get-DialogChoices([string]$Parameter) {
    $dir = (Get-Location).Path
    while ($dir -and -not (Test-Path -LiteralPath (Join-Path $dir 'ikaika_scripts.env'))) { $dir = Split-Path $dir -Parent }
    if (-not $dir -or -not (Test-Path -LiteralPath (Join-Path $dir 'sql'))) { return , @() }
    $folders = @(Get-ChildItem -LiteralPath (Join-Path $dir 'sql') -Directory | Where-Object { Get-ChildItem -LiteralPath $_.FullName -Filter '*.sql' -Recurse -File })
    if ($Parameter -eq 'Product') { return , @($folders | ForEach-Object Name) }
    if ($Parameter -eq 'Step') {
        $names = foreach ($folder in $folders) {
            Get-ChildItem -LiteralPath $folder.FullName -Filter '*.sql' -File | ForEach-Object BaseName
            if (Test-Path -LiteralPath (Join-Path $folder.FullName 'patches')) { 'patches' }
        }
        return , @($names | Sort-Object -Unique)
    }
    return , @()
}
