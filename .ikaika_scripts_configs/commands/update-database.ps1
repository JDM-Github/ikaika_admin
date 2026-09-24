# Applies this repository's sql/ folder to the local databases, and runs Laravel's
# migrations. Which product and which file are switches, so ticking two boxes applies
# two files in a defined order and nothing else.
#
# READ THIS BEFORE TICKING -Schema. sql/portal/schema.sql and
# sql/project_estimator/schema.sql each open with a DROP DATABASE. "Apply the schema"
# therefore means destroy the database and build it again empty - every row, including
# anything typed in by hand this morning. That is what those files are for, and it is not
# what most people mean when they tick a box called Schema.
#
# So -Schema alone is refused on a file that drops. -DropAndRecreate is the second
# switch that allows it, and it is named after what it does rather than after being a
# confirmation, because a box called -Yes teaches nobody anything.
#
# WITH NO SWITCHES NOTHING IS APPLIED. Every file is listed with the database it targets,
# its size, and whether it destroys anything, and the run stops there. A destructive
# command with no confirmation prompt - which is what a form full of checkboxes is - has
# to have a safe thing to do when it is run out of curiosity, and this is it.
#
# THE FILES NAME THEIR OWN DATABASE and ignore .env. Every one of them carries a literal
# USE test_portal_database (or ikaika_platform, or test_estimator_database) inside it, so
# pointing PORTAL_DB_DATABASE somewhere else changes what Laravel reads and changes
# nothing about where these files write. That disagreement is reported per file, because
# the failure it causes otherwise is "I updated the database and the app saw nothing".
#
# ORDER IS FIXED, not the order the switches are typed: schema, separate, indexes, data.
# Indexes on a table that does not exist yet is an error, and data before schema is the
# same error one step earlier.
#
#     .\script.ps1 update-database                       list everything, change nothing
#     .\script.ps1 update-database -Portal -Data         load the portal rows
#     .\script.ps1 update-database -Core -Schema         core is the one schema that is safe
#     .\script.ps1 update-database -Portal -Schema -DropAndRecreate
#     .\script.ps1 update-database -Migrate              php artisan migrate
#
# Requires PowerShell 7+, a mysql client on PATH (environment-variables puts one there),
# and a running database (run-servers starts one).

# ============================[ start: Parameters ]============================
param(
    # The core platform database - the shared action log and recycle bin.
    [switch]$Core,
    # The employee portal database.
    [switch]$Portal,
    # The project estimator database.
    [switch]$Estimator,
    # All three products.
    [switch]$AllProducts,

    # schema.sql - the tables. DESTRUCTIVE for portal and estimator; see -DropAndRecreate.
    [switch]$Schema,
    # separate.sql - the tables kept out of the main schema file. Portal only.
    [switch]$Separate,
    # indexes.sql - indexes added to a database that already has the tables.
    [switch]$Indexes,
    # data.sql - the rows. Large, and portal's begins with a TRUNCATE.
    [switch]$Data,

    # Allow a file that begins with DROP DATABASE to run. Without this, such a file is
    # listed, named as destructive, and skipped.
    [switch]$DropAndRecreate,
    # Bring every database up to date and skip whatever is already there, so running it
    # again does nothing. Picks every product and every file when nothing else is named,
    # because "make my databases match the repo" is the whole point of it.
    [switch]$Idempotent,
    # php artisan migrate. Additive, and the supported way to update an existing
    # deployment - the sql/ files are for building a local one.
    [switch]$Migrate,
    [switch]$Help
)
# =============================[ end: Parameters ]==============================

$ErrorActionPreference = 'Stop'

$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent
$libDir = Join-Path $repoRoot 'decoupled-script\lib'

. (Join-Path $libDir 'logging.ps1')
. (Join-Path $libDir 'load-env.ps1')
. (Join-Path $configsDir 'lib\wamp.ps1')
. (Join-Path $configsDir 'lib\laravel.ps1')

if ($Help) {
    Write-Host ''
    Write-Host '  update-database - apply sql/ to the local databases.'
    Write-Host ''
    Write-Host '  Usage'
    Write-Host '    .\script.ps1 update-database [-Core] [-Portal] [-Estimator] [-AllProducts]'
    Write-Host '                                 [-Schema] [-Separate] [-Indexes] [-Data]'
    Write-Host '                                 [-DropAndRecreate] [-Migrate]'
    Write-Host ''
    Write-Host '  No switches lists every file and applies none of them.'
    Write-Host ''
    Write-Host '  -Schema is DESTRUCTIVE for portal and estimator: those files open with'
    Write-Host '  DROP DATABASE, so applying one empties it. They are skipped unless'
    Write-Host '  -DropAndRecreate is also given. sql/core/schema.sql does not drop.'
    Write-Host ''
    Write-Host '  Files run in a fixed order whatever order the switches are typed:'
    Write-Host '    schema -> separate -> indexes -> data'
    Write-Host ''
    Write-Host '  -Migrate runs php artisan migrate, which is additive and is what an'
    Write-Host '  existing deployment takes. The sql/ files build a local one.'
    Write-Host ''
    Write-Host '  Config: the databases and credentials come from .env, and the product'
    Write-Host '  list mirrors config/products.php.'
    Write-Host ''
    exit 0
}

Start-BuildLog 'update-database'

$sqlRoot = Join-Path $repoRoot 'sql'
$envPath = Join-Path $repoRoot '.env'
$products = Get-ProductDatabase $envPath

if ($AllProducts) { $Core = $Portal = $Estimator = $true }

# -Idempotent with nothing else named means everything. Asking somebody to tick eight
# boxes to say "bring this up to date" is asking them to get it wrong once.
if ($Idempotent -and -not ($Core -or $Portal -or $Estimator -or $Schema -or $Separate -or $Indexes -or $Data)) {
    $Core = $Portal = $Estimator = $true
    $Schema = $Separate = $Indexes = $Data = $true
}

$wantedFolders = @()
if ($Core) { $wantedFolders += 'core' }
if ($Portal) { $wantedFolders += 'portal' }
if ($Estimator) { $wantedFolders += 'project_estimator' }

# The fixed order, with each part's switch beside it. A hashtable would not have an
# order, and the order is the only thing keeping "indexes" from running before the table
# it indexes exists.
$Parts = @(
    [pscustomobject]@{ File = 'schema.sql'; Wanted = [bool]$Schema }
    [pscustomobject]@{ File = 'separate.sql'; Wanted = [bool]$Separate }
    [pscustomobject]@{ File = 'indexes.sql'; Wanted = [bool]$Indexes }
    [pscustomobject]@{ File = 'data.sql'; Wanted = [bool]$Data }
)

# ============================[ start: The ledger ]============================
# WHAT MAKES THIS IDEMPOTENT, and why it is not "write the SQL files idempotently".
#
# These files cannot be made safely repeatable from the inside: MySQL has no CREATE
# INDEX IF NOT EXISTS, data.sql inserts rather than merges, and they are generated from
# Airtable rather than hand-maintained. So repeatability is kept OUTSIDE them - a record
# of which file, at which content hash, was applied to which database and when.
#
# IT IS A LOCAL FILE AND NOT A TABLE. The first version put a bookkeeping table in each
# product database, which is wrong however convenient it is: those schemas are the
# product's, they get dumped, diffed, migrated and read in phpMyAdmin, and a table that
# belongs to a build tool has no business appearing in any of that. The cost of moving
# it out is that the record is per machine rather than per database - which is why the
# target database is checked below before the record is believed.
#
# THE HASH, NOT THE NAME. A file edited after it was applied is a file that has not been
# applied; recording only the name would silently skip the new version.
$LedgerPath = Join-Path $configsDir '.sql-applied.json'

function Get-FileHashHex([string]$Path) {
    return (Get-FileHash -Algorithm SHA256 -LiteralPath $Path).Hash.ToLower()
}

function Get-Ledger {
    if (-not (Test-Path -LiteralPath $LedgerPath)) { return @{} }
    try {
        $raw = Get-Content -LiteralPath $LedgerPath -Raw | ConvertFrom-Json
    } catch {
        # A corrupt record is not a reason to refuse to work; it is a reason to act as
        # though nothing has been applied, which is the safe direction to be wrong in.
        return @{}
    }
    $ledger = @{}
    foreach ($property in $raw.PSObject.Properties) { $ledger[$property.Name] = $property.Value }
    return $ledger
}

function Save-Ledger([hashtable]$Ledger) {
    if (-not $Ledger.Count) {
        Remove-Item -LiteralPath $LedgerPath -Force -ErrorAction SilentlyContinue
        return
    }
    ($Ledger | ConvertTo-Json -Depth 4) | Set-Content -LiteralPath $LedgerPath -Encoding utf8
}

# One key per database AND file, because the same file applied to two databases is two
# different facts - and because a database rebuilt elsewhere must only invalidate its
# own rows.
function Get-LedgerKey([string]$Database, [string]$File) { return "$Database|$File" }

# THE CHECK THAT MAKES A LOCAL RECORD HONEST. The record lives on this machine and the
# database does not, so anything could have happened to it in between - dropped in
# phpMyAdmin, rebuilt from a dump, pointed somewhere else in .env. An empty database is
# treated as nothing having been applied to it, whatever the file says.
function Test-LedgerStillTrue([object]$Product, [string]$Database) {
    $tables = Get-DatabaseTableCount $Product $Database
    if ($tables -lt 0) { return $false }
    return $tables -gt 0
}
# =============================[ end: The ledger ]=============================

# ------------------------------------------------------- what a file will do
# Read from the file itself, never assumed from its name. schema.sql drops in two
# products out of three, and a rule based on the filename would be wrong for core - in
# the direction that loses data.
function Get-SqlFileFacts([string]$Path) {
    $lines = @(Get-Content -LiteralPath $Path)
    $targets = @()
    foreach ($line in $lines) {
        if ($line -match '^\s*USE\s+`?([A-Za-z0-9_]+)`?\s*;') { $targets += $Matches[1] }
    }
    return [pscustomobject]@{
        Path      = $Path
        Bytes     = (Get-Item -LiteralPath $Path).Length
        Target    = $(if ($targets.Count) { $targets[0] } else { '' })
        Drops     = @($lines | Where-Object { $_ -match '^\s*DROP\s+DATABASE' }).Count
        Truncates = @($lines | Where-Object { $_ -match '^\s*TRUNCATE' }).Count
            }
}

# WHAT A DROP ACTUALLY COSTS, asked of the server rather than assumed from the file.
# Every one of these files says DROP DATABASE **IF EXISTS**, so on a machine that has
# never had the database the drop destroys nothing and the guard was refusing a no-op -
# which made the first run impossible, because schema.sql is the only thing that creates
# the database in the first place. Returns -1 when the server cannot be reached, and the
# caller treats that as "assume it exists", because refusing is the safe way to be wrong.
function Get-DatabaseTableCount([object]$Product, [string]$Database) {
    $mysql = Get-Command mysql -ErrorAction SilentlyContinue
    if (-not $mysql -or -not $Database) { return -1 }
    $arguments = Get-MysqlArgument $Product
    $arguments += @('--batch', '--skip-column-names', '--execute',
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$($Database -replace "'", "''")'")
    try {
        $answer = & $mysql.Source @arguments 2>$null
        if ($LASTEXITCODE -ne 0) { return -1 }
        $text = "$(@($answer)[0])".Trim()
        if ($text -match '^\d+$') { return [int]$text }
        return -1
    } catch { return -1 }
}

# The tables a file declares, and which of them the server actually has.
#
# This exists because of how mysql handles an error: it STOPS. So "Table x already
# exists" halfway down a file means everything after that line did not run - and calling
# that outcome "already applied" would be claiming a state nobody verified. Asking which
# tables are really there is the only way to tell a harmless re-run from a file that
# stopped a third of the way in.
function Get-DeclaredTableName([string]$Path) {
    $names = @()
    foreach ($line in (Get-Content -LiteralPath $Path)) {
        if ($line -match '^\s*CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?') {
            $names += $Matches[2]
        }
    }
    return @($names | Select-Object -Unique)
}

function Get-MissingTable([object]$Product, [string]$Database, [string[]]$Names) {
    if (-not $Names.Count) { return @() }
    $mysql = Get-Command mysql -ErrorAction SilentlyContinue
    if (-not $mysql -or -not $Database) { return $Names }
    $arguments = Get-MysqlArgument $Product
    $arguments += @('--batch', '--skip-column-names', '--execute',
        "SELECT table_name FROM information_schema.tables WHERE table_schema = '$($Database -replace "'", "''")'")
    try {
        $present = @(& $mysql.Source @arguments 2>$null | ForEach-Object { "$_".Trim() } | Where-Object { $_ })
        if ($LASTEXITCODE -ne 0) { return $Names }
    } catch { return $Names }
    return @($Names | Where-Object { $present -notcontains $_ })
}

function Format-Size([long]$Bytes) {
    if ($Bytes -ge 1MB) { return "$([math]::Round($Bytes / 1MB, 1)) MB" }
    if ($Bytes -ge 1KB) { return "$([math]::Round($Bytes / 1KB)) KB" }
    return "$Bytes B"
}

# --------------------------------------------------------------- the listing
Write-Host ''
Write-Host "  Update database - $(Get-ProjectValue 'app.displayName' 'this project')" -ForegroundColor Cyan
Write-Host "  $sqlRoot" -ForegroundColor DarkGray

if (-not (Test-Path -LiteralPath $sqlRoot)) {
    Write-Host ''
    Write-Host "  There is no sql folder at $sqlRoot." -ForegroundColor Red
    Write-Host ''
    exit 1
}

$ledger = Get-Ledger
$ledgerTouched = $false

$plan = @()
foreach ($folder in @('core', 'portal', 'project_estimator')) {
    $product = $products[$folder]
    $folderPath = Join-Path $sqlRoot $folder
    if (-not (Test-Path -LiteralPath $folderPath)) { continue }

    Write-Host ''
    Write-Host "  $($product.Name)  ($folder)" -ForegroundColor Cyan
    Write-Host "    .env says $($product.Database) on $($product.DbHost):$($product.Port) as $($product.Username)" -ForegroundColor DarkGray

    foreach ($part in $Parts) {
        $path = Join-Path $folderPath $part.File
        if (-not (Test-Path -LiteralPath $path)) { continue }
        $facts = Get-SqlFileFacts $path

        # Only asked when it matters, so a listing of files that drop nothing costs no
        # round trips to a server that may not even be running.
        $liveTables = $(if ($facts.Drops) { Get-DatabaseTableCount $product $(if ($facts.Target) { $facts.Target } else { $product.Database }) } else { -1 })
        $wouldDestroy = $facts.Drops -and $liveTables -ne 0

        $notes = @()
        if ($facts.Drops -and $liveTables -eq 0) {
            $notes += 'creates the database - nothing there to destroy'
        } elseif ($facts.Drops -and $liveTables -gt 0) {
            $notes += "DROPS THE DATABASE ($liveTables table(s) would go)"
        } elseif ($facts.Drops) {
            $notes += 'DROPS THE DATABASE (could not reach the server to see what is in it)'
        }
        if ($facts.Truncates) { $notes += "truncates $($facts.Truncates) table(s)" }
        # The disagreement that otherwise reads as "the update did nothing": the file
        # writes where its own USE points, and Laravel reads where .env points.
        if ($facts.Target -and $facts.Target -ne $product.Database) {
            $notes += "writes to $($facts.Target), but .env reads $($product.Database)"
        }
        if (-not $facts.Target) { $notes += 'names no database - runs against the default' }

        $picked = ($wantedFolders -contains $folder) -and $part.Wanted

        # Already applied, at exactly this content. The hash is what makes an edited
        # file count as unapplied rather than being skipped by its name.
        $fileKey = "$folder/$($part.File)"
        $fileHash = Get-FileHashHex $path
        $applied = $false
        if ($Idempotent -and $picked) {
            $ledgerTarget = $(if ($facts.Target) { $facts.Target } else { $product.Database })
            $entry = $ledger[(Get-LedgerKey $ledgerTarget $fileKey)]
            $applied = $entry -and "$($entry.sha256)" -eq $fileHash -and (Test-LedgerStillTrue $product $ledgerTarget)
        }

        # Under -Idempotent a destructive schema is KEPT rather than blocked, and the
        # difference is the whole point: blocked is an error to be argued with,
        # kept is the correct outcome. "Bring this up to date" must never mean "empty
        # it"; -DropAndRecreate is still how you ask for that, deliberately and alone.
        $kept = $picked -and $Idempotent -and $wouldDestroy -and -not $DropAndRecreate
        $blocked = $picked -and $wouldDestroy -and -not $DropAndRecreate -and -not $Idempotent

        $state = 'listed'
        if ($applied) { $state = 'applied' }
        elseif ($kept) { $state = 'kept' }
        elseif ($blocked) { $state = 'blocked' }
        elseif ($picked) { $state = 'will run' }

        if ($applied) { $notes = @("already applied, unchanged since") }
        if ($kept) { $notes += 'left alone - -DropAndRecreate is how you ask for a rebuild' }

        $colour = @{ 'listed' = 'DarkGray'; 'will run' = 'Green'; 'blocked' = 'Yellow'
            'applied' = 'DarkGray'; 'kept' = 'DarkGray'
        }
        Write-Host ('    {0,-9}' -f $state) -ForegroundColor $colour[$state] -NoNewline
        Write-Host ('{0,-14} {1,-8} {2}' -f $part.File, (Format-Size $facts.Bytes), ($notes -join '; '))

        if ($picked -and -not $blocked -and -not $applied -and -not $kept) {
            $plan += [pscustomobject]@{
                Product = $product; Folder = $folder; Facts = $facts; File = $part.File
                Key = $fileKey; Hash = $fileHash
            }
        }
        if ($blocked) {
            Write-Host '                            add -DropAndRecreate to allow it' -ForegroundColor DarkGray
        }
    }
}

if (-not $plan.Count -and -not $Migrate) {
    Write-Host ''
    if ($Idempotent) {
        # The point of the whole thing: a second run says this and exits 0.
        Write-Host '  Everything is already applied - nothing to do.' -ForegroundColor Green
        Write-Host ''
        exit 0
    }
    if (-not $wantedFolders.Count) {
        Write-Host '  Nothing applied. Tick a product (-Core, -Portal, -Estimator) and a file' -ForegroundColor Yellow
        Write-Host '  (-Schema, -Separate, -Indexes, -Data), or use -Migrate.' -ForegroundColor DarkGray
    } else {
        Write-Host '  Nothing to apply for what was picked - see the listing above.' -ForegroundColor Yellow
    }
    Write-Host ''
    exit 0
}

# ------------------------------------------------------------------ applying
$mysql = Get-Command mysql -ErrorAction SilentlyContinue
if ($plan.Count -and -not $mysql) {
    Write-Host ''
    Write-Host '  No mysql client on PATH, so no file can be applied.' -ForegroundColor Red
    Write-Host '    .\script.ps1 environment-variables     put one there' -ForegroundColor DarkGray
    Write-Host ''
    exit 1
}

$failures = 0
if ($plan.Count) {
    Write-Host ''
    Write-Host '  Applying' -ForegroundColor Cyan
}

foreach ($step in $plan) {
    $label = "$($step.Folder)/$($step.File)"
    Write-Host "    $label"
    $arguments = Get-MysqlArgument $step.Product
    # The file's own USE decides the database when it has one; only a file that names
    # none is given the .env database on the command line.
    if (-not $step.Facts.Target) { $arguments += $step.Product.Database }
    $arguments += '--default-character-set=utf8mb4'

    # Redirected with cmd rather than piped from PowerShell: PowerShell decodes the file
    # and re-encodes it into the pipe, which corrupts every non-ASCII byte in a 1 MB dump
    # and fails somewhere in the middle with a syntax error nobody can place.
    $quoted = ($arguments | ForEach-Object { '"' + $_ + '"' }) -join ' '
    $output = & cmd.exe '/c' "`"$($mysql.Source)`" $quoted < `"$($step.Facts.Path)`" 2>&1"
    $ledgerTarget = $(if ($step.Facts.Target) { $step.Facts.Target } else { $step.Product.Database })
    if ($LASTEXITCODE -eq 0) {
        Write-Host "      applied to $ledgerTarget" -ForegroundColor Green
        if ($Idempotent) { $ledger[(Get-LedgerKey $ledgerTarget $step.Key)] = @{ sha256 = $step.Hash; bytes = $step.Facts.Bytes; note = 'applied'; applied_at = (Get-Date).ToString('o') }; $ledgerTouched = $true }
        continue
    }

    $why = "$(@($output | Where-Object { "$_".Trim() })[0])".Trim()

    # ERROR 1061 is "that index is already there", which is the state this file exists to
    # produce - so it is the desired outcome arriving by a different route, not a
    # failure. sql/portal/indexes.sql says exactly this in its own header: it is for
    # databases that predate the index, and "Skip if MySQL reports Duplicate key name".
    # Counting it as a failure made a correct first run exit non-zero.
    # "Table x already exists" is the same question as the index one but a harder
    # answer, because mysql stopped there and the rest of the file did not run. So the
    # end state is checked rather than assumed: every table the file declares is either
    # present - in which case the re-run was a no-op and nothing was lost - or some are
    # missing, and that is a real failure wearing a reassuring error message.
    if ($why -match 'already exists') {
        $declaredTables = @(Get-DeclaredTableName $step.Facts.Path)
        $target = $(if ($step.Facts.Target) { $step.Facts.Target } else { $step.Product.Database })
        $missing = @(Get-MissingTable $step.Product $target $declaredTables)
        if ($declaredTables.Count -and -not $missing.Count) {
            Write-Host "      already applied - all $($declaredTables.Count) table(s) are present" -ForegroundColor DarkGray
            if ($Idempotent) { $ledger[(Get-LedgerKey $ledgerTarget $step.Key)] = @{ sha256 = $step.Hash; bytes = $step.Facts.Bytes; note = 'adopted'; applied_at = (Get-Date).ToString('o') }; $ledgerTouched = $true }
            continue
        }
        Write-Host "      FAILED (exit $LASTEXITCODE) $why" -ForegroundColor Red
        Write-Host "      mysql stops at the first error, so the rest of the file did not run." -ForegroundColor Yellow
        Write-Host "      Still missing from ${target}: $($missing -join ', ')" -ForegroundColor Yellow
        $failures++
        continue
    }

    if ($why -match 'Duplicate key name') {
        Write-Host "      already there - $why" -ForegroundColor DarkGray
        Write-Host "      (that file is for databases built before the index; a fresh schema.sql has it)" -ForegroundColor DarkGray
        if ($Idempotent) { $ledger[(Get-LedgerKey $ledgerTarget $step.Key)] = @{ sha256 = $step.Hash; bytes = $step.Facts.Bytes; note = 'adopted'; applied_at = (Get-Date).ToString('o') }; $ledgerTouched = $true }
        continue
    }

    Write-Host "      FAILED (exit $LASTEXITCODE) $why" -ForegroundColor Red
    # data.sql is NOT re-runnable and that is a property of the file, not a bug here: it
    # truncates one table and then inserts into fifty, so a second run collides with the
    # rows the first one wrote. Reported as the failure it is, with the only thing that
    # actually fixes it - rebuild the schema, then load the rows into an empty database.
    if ($why -match 'Duplicate entry') {
        if ($Idempotent) {
            # ADOPTION. The database already carries this file's effects but predates the
            # ledger, which is every database that existed before this switch did. Recorded
            # rather than re-applied, so the run converges instead of failing forever on the
            # same rows - and marked 'adopted' so the ledger never claims this command is
            # the one that put them there.
            Write-Host "      already loaded - adopted into the ledger" -ForegroundColor DarkGray
            $ledger[(Get-LedgerKey $ledgerTarget $step.Key)] = @{ sha256 = $step.Hash; bytes = $step.Facts.Bytes; note = 'adopted'; applied_at = (Get-Date).ToString('o') }; $ledgerTouched = $true
            continue
        }
        Write-Host "      These rows are already loaded. data.sql inserts rather than merges," -ForegroundColor Yellow
        Write-Host "      so it only applies to an empty database:" -ForegroundColor Yellow
        $switch = $(if ($step.Folder -eq 'project_estimator') { 'Estimator' } else { (Get-Culture).TextInfo.ToTitleCase($step.Folder) })
        Write-Host "        .\script.ps1 update-database -$switch -Schema -DropAndRecreate -Data" -ForegroundColor DarkGray
        $failures++
        continue
    }

    # The one failure worth translating. Every file except schema.sql opens with a USE,
    # so on a database that was never created they all fail with the same unhelpful
    # line - and the fix is always the same file.
    if ($why -match 'Unknown database') {
        Write-Host "      That database does not exist yet. schema.sql is what creates it:" -ForegroundColor Yellow
        Write-Host "        .\script.ps1 update-database -$(if ($step.Folder -eq 'project_estimator') { 'Estimator' } else { (Get-Culture).TextInfo.ToTitleCase($step.Folder) }) -Schema" -ForegroundColor DarkGray
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
            # Captured rather than streamed, so the one line that explains a failure can
            # be found in it. Printed either way - a migration's own output is the most
            # useful thing on screen when it goes wrong.
            $output = & $php.Source 'artisan' 'migrate' '--no-interaction' 2>&1
            $output | ForEach-Object { Write-Host "    $_" }
            if ($LASTEXITCODE -ne 0) {
                Write-Host "    artisan migrate exited with $LASTEXITCODE" -ForegroundColor Red
                # The failure every fresh WampServer gives, translated. 1071 here is not
                # a schema problem: it is MyISAM, which caps an index at 1000 bytes,
                # while Laravel's users_email_unique on a utf8mb4 varchar(255) needs
                # 1020. Nothing in the error names MyISAM or the file that selected it.
                # A migration that failed part way leaves what it had already created,
                # because MySQL does not roll back DDL - so the retry dies on the table
                # the first attempt made rather than on whatever actually broke. Named,
                # because "already exists" sends people looking for a duplicate
                # migration file that does not exist.
                if ("$output" -match 'already exists') {
                    Write-Host '    A previous migration stopped part way and left its tables behind;' -ForegroundColor Yellow
                    Write-Host '    MySQL does not roll back DDL, so the retry trips over them. Either drop' -ForegroundColor Yellow
                    Write-Host '    the table the error names, or rebuild from nothing:' -ForegroundColor Yellow
                    Write-Host '      php artisan migrate:fresh     (DESTROYS every table on that connection)' -ForegroundColor DarkGray
                }
                if ("$output" -match 'key was too long|1071') {
                    Write-Host '    That is MyISAM, not your schema: WampServer sets' -ForegroundColor Yellow
                    Write-Host '    default_storage_engine=MYISAM in my.ini, and MyISAM caps an index at' -ForegroundColor Yellow
                    Write-Host '    1000 bytes. Switch it to InnoDB and restart MySQL:' -ForegroundColor Yellow
                    Write-Host '      .\script.ps1 development-setup' -ForegroundColor DarkGray
                }
                $failures++
            }
        } finally { Pop-Location }
    }
}

if ($ledgerTouched) { Save-Ledger $ledger }

Write-Host ''
if ($failures) {
    Write-Host "  $failures step(s) failed - see above." -ForegroundColor Red
} else {
    Write-Host '  Done.' -ForegroundColor Green
}
Write-Host '    .\script.ps1 update-database          list every file again' -ForegroundColor DarkGray
Write-Host '    .\script.ps1 run-servers -PhpMyAdmin  look at the result' -ForegroundColor DarkGray
Write-Host ''
exit $failures
