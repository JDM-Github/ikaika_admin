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

        $notes = @()
        if ($facts.Drops) { $notes += 'DROPS THE DATABASE' }
        if ($facts.Truncates) { $notes += "truncates $($facts.Truncates) table(s)" }
        # The disagreement that otherwise reads as "the update did nothing": the file
        # writes where its own USE points, and Laravel reads where .env points.
        if ($facts.Target -and $facts.Target -ne $product.Database) {
            $notes += "writes to $($facts.Target), but .env reads $($product.Database)"
        }
        if (-not $facts.Target) { $notes += 'names no database - runs against the default' }

        $picked = ($wantedFolders -contains $folder) -and $part.Wanted
        $blocked = $picked -and $facts.Drops -and -not $DropAndRecreate

        $state = 'listed'
        if ($blocked) { $state = 'blocked' }
        elseif ($picked) { $state = 'will run' }

        $colour = @{ 'listed' = 'DarkGray'; 'will run' = 'Green'; 'blocked' = 'Yellow' }
        Write-Host ('    {0,-9}' -f $state) -ForegroundColor $colour[$state] -NoNewline
        Write-Host ('{0,-14} {1,-8} {2}' -f $part.File, (Format-Size $facts.Bytes), ($notes -join '; '))

        if ($picked -and -not $blocked) {
            $plan += [pscustomobject]@{ Product = $product; Folder = $folder; Facts = $facts; File = $part.File }
        }
        if ($blocked) {
            Write-Host '                            add -DropAndRecreate to allow it' -ForegroundColor DarkGray
        }
    }
}

if (-not $plan.Count -and -not $Migrate) {
    Write-Host ''
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
    if ($LASTEXITCODE -eq 0) {
        Write-Host "      applied to $(if ($step.Facts.Target) { $step.Facts.Target } else { $step.Product.Database })" -ForegroundColor Green
        continue
    }

    $why = "$(@($output | Where-Object { "$_".Trim() })[0])".Trim()
    Write-Host "      FAILED (exit $LASTEXITCODE) $why" -ForegroundColor Red
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
            & $php.Source 'artisan' 'migrate' '--no-interaction'
            if ($LASTEXITCODE -ne 0) {
                Write-Host "    artisan migrate exited with $LASTEXITCODE" -ForegroundColor Red
                $failures++
            }
        } finally { Pop-Location }
    }
}

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
