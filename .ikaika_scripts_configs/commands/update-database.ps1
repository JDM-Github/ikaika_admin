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
# sql/portal/separate.sql carries one, because its grants need the employees data.sql loads. A product's
# order in update-database.json, when it has one, is the order of the steps it lists, over both: after
# lines then move only the steps it leaves out, which run after the listed ones and are flagged as not in
# it, and a name it gives that no step answers to is refused rather than left to run a step out of place.
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
# -CLEAR EMPTIES TABLES AND KEEPS THEM: TRUNCATE, with foreign key checks off - MySQL refuses to truncate a
# table other tables point at otherwise - so AUTO_INCREMENT starts again, by the sets update-database.json
# names: all (every table), necessary (the rows the data files load), non-important (logs, notifications,
# one-time codes, sessions, caches, jobs), or any set added there. The migrations table is never cleared.
# Like a drop it takes only the products named, and it runs before any step, so -Clear necessary -Step data
# loads the data again into the tables it emptied. What described the cleared rows goes with them: the
# ledger's record of each step that inserts into a cleared table, so -Idempotent runs it again; for a set
# that says so, the product's rows in the core ledger; and, for a set with migrations, the record of every
# migration that writes to the cleared database and checks before it writes, so migrate runs them again in
# order and puts back the rows they seed - Laravel's own only create tables, which are still there.
#
# WITH NOTHING PICKED NOTHING IS APPLIED. Every step is listed with its kind, the database it
# targets, its size and what it would destroy, and the run stops there.
#
# THE FILES NAME THEIR OWN DATABASE with a USE line, and ignore .env; a disagreement is reported per
# file, since otherwise it reads as "I updated the database and the app saw nothing". A file with no
# USE line runs against the product's .env database.
#
# WHAT CAN CHANGE LIVES IN .ikaika_scripts_configs/update-database.json, or the file -ConfigFile names: the
# sql folder and the patches folder's name, the order kinds run in and which run strictly, the errors that
# mean "already there", how a database is created, the migrations folder and table and whether a rebuild
# migrates, the core ledger, each product's overrides - its sql folder, its step order, a step's kind,
# after lines or strictness, a step or a product skipped, a product kept from every drop, a product the
# catalog does not have - and named runs for -Preset. Every key is optional: one left out is today's value,
# and without the file every value is.
#
#     .\script.ps1 update-database                                        list everything, change nothing
#     .\script.ps1 update-database -Product portal -Step data             load the portal rows
#     .\script.ps1 update-database -Product transaction_tracker -Step patches
#     .\script.ps1 update-database -Product transaction_tracker -Drop       remove that database, nothing built back
#     .\script.ps1 update-database -Product portal -DropAndRecreate         the portal from nothing, up to date
#     .\script.ps1 update-database -Product portal -Clear necessary -Step data   the portal's data loaded again
#     .\script.ps1 update-database -Idempotent                            bring every database up to date
#     .\script.ps1 update-database -Migrate                               php artisan migrate
#     .\script.ps1 update-database -Preset portal-from-nothing            a named run from update-database.json
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
    # Empty tables and keep them, by the sets update-database.json names - all, necessary, non-important; the sets are offered.
    [string[]]$Clear = @(),
    # Remove each picked product's database completely; nothing is built back. Takes no -Step or -Migrate.
    [switch]$Drop,
    # Bring every database up to date and skip what is already applied, so a second run does nothing.
    # Picks every product, every step and the migrations when nothing else is named; never rebuilds.
    [switch]$Idempotent,
    # -Drop, then -Idempotent: each picked product's database removed, then built up to date - every step, or the -Step ones, and the migrations.
    [switch]$DropAndRecreate,
    # php artisan migrate: additive, and the supported way to update an existing deployment.
    [switch]$Migrate,
    # A named run from update-database.json's presets: the products, steps and switches it sets; what is typed here wins.
    [string]$Preset = '',
    # The settings file; .ikaika_scripts_configs/update-database.json when left out.
    [string]$ConfigFile = '',
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

# ------------------------------------------------------------------------ the settings
# update-database.json's keys over today's values, one by one; 'about' anywhere is a note for people. JSON
# objects come back case-insensitive, so "Folder" and "folder" are one key rather than one ignored.
function ConvertTo-LooseValue($Value) {
    if ($Value -is [Collections.IDictionary]) {
        $loose = [ordered]@{}
        foreach ($key in @($Value.Keys)) { $loose[$key] = ConvertTo-LooseValue $Value[$key] }
        return $loose
    }
    if ($Value -is [string] -or $Value -isnot [Collections.IEnumerable]) { return $Value }
    return , @($Value | ForEach-Object { ConvertTo-LooseValue $_ })
}

function Read-UpdateDatabaseConfig([string]$Path) {
    $kinds = @('rebuild', 'structure', 'retrofit', 'other', 'data', 'patch')
    $config = [ordered]@{
        sqlRoot        = 'sql'
        catalog        = 'config/products.php'
        patches        = 'patches'
        ledger         = '.ikaika_scripts_configs/.sql-applied.json'
        kindOrder      = $kinds
        strictKinds    = @('rebuild', 'data')
        alreadyThere   = @(1050, 1060, 1061, 1062, 1068, 1091, 1826)
        createDatabase = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        migrations     = [ordered]@{ path = 'database/migrations'; table = 'migrations'; afterRebuild = $true }
        coreLedger     = [ordered]@{ product = 'core'; tables = @('actions', 'recycle'); column = 'product' }
        clear          = [ordered]@{
            keep   = @('migrations')
            groups = [ordered]@{
                all             = [ordered]@{ tables = @('*'); coreLedger = $true; migrations = $true }
                necessary       = [ordered]@{ loadedBy = @('data'); coreLedger = $true }
                'non-important' = [ordered]@{ tables = @('logs', 'notifications', '*_otps', 'shared_page_visits', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens') }
            }
        }
        products       = [ordered]@{}
        presets        = [ordered]@{}
        _path          = $Path
        _present       = $false
    }
    if (-not (Test-Path -LiteralPath $Path)) { return $config }
    $config._present = $true
    try { $file = ConvertTo-LooseValue (Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json -AsHashtable) }
    catch { throw "$Path is not JSON update-database can read: $($_.Exception.Message)" }
    if ($file -isnot [Collections.IDictionary]) { throw "$Path must hold one JSON object." }

    $fail = { param([string]$Message) throw "$Path - $Message" }
    $identifier = '^[A-Za-z0-9_]+$'
    $isList = { param($Value) $Value -isnot [string] -and $Value -is [Collections.IEnumerable] -and $Value -isnot [Collections.IDictionary] }
    foreach ($key in @($file.Keys)) {
        if ($key -in @('about', '$schema')) { continue }
        if (-not $config.Contains($key) -or $key.StartsWith('_')) { & $fail "'$key' is not a setting; the settings are $(@($config.Keys | Where-Object { -not $_.StartsWith('_') }) -join ', ')." }
        if ($key -in @('migrations', 'coreLedger', 'clear')) {
            if ($file[$key] -isnot [Collections.IDictionary]) { & $fail "$key must be an object." }
            foreach ($inner in @($file[$key].Keys | Where-Object { $_ -ne 'about' })) {
                if (-not $config[$key].Contains($inner)) { & $fail "$key.$inner is not a setting; $key has $(@($config[$key].Keys) -join ', ')." }
                $config[$key][$inner] = $file[$key][$inner]
            }
        } else { $config[$key] = $file[$key] }
    }

    foreach ($key in 'sqlRoot', 'catalog', 'patches', 'ledger') {
        if ($config[$key] -isnot [string] -or -not $config[$key].Trim()) { & $fail "$key must be a path, as text." }
    }
    if ($config.createDatabase -isnot [string] -or $config.createDatabase -notmatch '^[A-Za-z0-9_ =]*$') {
        & $fail 'createDatabase must be what follows CREATE DATABASE <name>, such as CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci.'
    }
    foreach ($key in 'kindOrder', 'strictKinds') {
        if (-not (& $isList $config[$key])) { & $fail "$key must be a list of kinds: $($kinds -join ', ')." }
        $strange = @($config[$key] | Where-Object { $kinds -notcontains $_ })
        if ($strange.Count) { & $fail "$key names $($strange -join ', '); the kinds are $($kinds -join ', ')." }
    }
    # A kind the order leaves out runs after the ones it names, in today's order.
    $config.kindOrder = @(@($config.kindOrder | Select-Object -Unique) + @($kinds | Where-Object { $config.kindOrder -notcontains $_ }))
    if (-not (& $isList $config.alreadyThere) -or @($config.alreadyThere | Where-Object { "$_" -notmatch '^\d+$' }).Count) {
        & $fail 'alreadyThere must be a list of MySQL error numbers, such as 1061.'
    }
    if ("$($config.migrations.path)" -eq '' -or "$($config.migrations.table)" -notmatch $identifier) { & $fail 'migrations needs a path and a table name.' }
    if ($config.migrations.afterRebuild -isnot [bool]) { & $fail 'migrations.afterRebuild must be true or false.' }
    if ("$($config.coreLedger.product)" -eq '' -or "$($config.coreLedger.column)" -notmatch $identifier -or -not (& $isList $config.coreLedger.tables) -or
        @($config.coreLedger.tables | Where-Object { "$_" -notmatch $identifier }).Count) {
        & $fail 'coreLedger needs a product, a column, and a list of tables, each a plain name.'
    }

    $pattern = '^[A-Za-z0-9_*?]+$'
    if (-not (& $isList $config.clear.keep) -or @($config.clear.keep | Where-Object { "$_" -notmatch $pattern }).Count) {
        & $fail 'clear.keep must be a list of table names, with * and ? as wildcards.'
    }
    if ($config.clear.groups -isnot [Collections.IDictionary]) { & $fail 'clear.groups must be an object, one key per set.' }
    foreach ($name in @($config.clear.groups.Keys | Where-Object { $_ -ne 'about' })) {
        $set = $config.clear.groups[$name]
        if ($set -isnot [Collections.IDictionary]) { & $fail "clear.groups.$name must be an object." }
        $stray = @($set.Keys | Where-Object { @('about', 'tables', 'loadedBy', 'except', 'coreLedger', 'migrations') -notcontains $_ })
        if ($stray.Count) { & $fail "clear.groups.$name.$($stray[0]) is not a setting; a set has tables, loadedBy, except, coreLedger, migrations." }
        if (-not $set.Contains('tables') -and -not $set.Contains('loadedBy')) { & $fail "clear.groups.$name names no tables and no loadedBy, so it would clear nothing." }
        foreach ($field in 'tables', 'except') {
            if ($set.Contains($field) -and (-not (& $isList $set[$field]) -or @($set[$field] | Where-Object { "$_" -notmatch $pattern }).Count)) {
                & $fail "clear.groups.$name.$field must be a list of table names, with * and ? as wildcards."
            }
        }
        if ($set.Contains('loadedBy') -and (-not (& $isList $set['loadedBy']) -or @($set['loadedBy'] | Where-Object { "$_" -notmatch '^[A-Za-z0-9_./-]+$' }).Count)) {
            & $fail "clear.groups.$name.loadedBy must be a list of step names or kinds."
        }
        foreach ($field in 'coreLedger', 'migrations') {
            if ($set.Contains($field) -and $set[$field] -isnot [bool]) { & $fail "clear.groups.$name.$field must be true or false." }
        }
    }

    $shapes = [ordered]@{
        products = @('about', 'name', 'database', 'envPrefix', 'connection', 'folder', 'skip', 'drop', 'order', 'steps')
        presets  = @('about', 'product', 'step', 'clear', 'drop', 'dropAndRecreate', 'idempotent', 'migrate')
    }
    foreach ($section in $shapes.Keys) {
        if ($config[$section] -isnot [Collections.IDictionary]) { & $fail "$section must be an object, one key per $($section.TrimEnd('s'))." }
        foreach ($name in @($config[$section].Keys | Where-Object { $_ -ne 'about' })) {
            $spec = $config[$section][$name]
            if ($spec -isnot [Collections.IDictionary]) { & $fail "$section.$name must be an object." }
            $stray = @($spec.Keys | Where-Object { $shapes[$section] -notcontains $_ })
            if ($stray.Count) { & $fail "$section.$name.$($stray[0]) is not a setting; one has $(@($shapes[$section] | Where-Object { $_ -ne 'about' }) -join ', ')." }
            foreach ($field in 'skip', 'drop', 'dropAndRecreate', 'idempotent', 'migrate') {
                if ($spec.Contains($field) -and $spec[$field] -isnot [bool]) { & $fail "$section.$name.$field must be true or false." }
            }
        }
    }
    foreach ($name in @($config.products.Keys | Where-Object { $_ -ne 'about' })) {
        $spec = $config.products[$name]
        foreach ($field in 'database', 'envPrefix', 'connection') {
            if ($spec.Contains($field) -and "$($spec[$field])" -notmatch $identifier) { & $fail "products.$name.$field must be a plain name." }
        }
        if ($spec.Contains('folder') -and "$($spec['folder'])" -notmatch '^[A-Za-z0-9_.-]+$') { & $fail "products.$name.folder must be one folder's name under $($config.sqlRoot)." }
        if ($spec.Contains('order') -and -not (& $isList $spec['order'])) { & $fail "products.$name.order must be a list of step names." }
        if (-not $spec.Contains('steps')) { continue }
        if ($spec['steps'] -isnot [Collections.IDictionary]) { & $fail "products.$name.steps must be an object, one key per step." }
        foreach ($stepName in @($spec['steps'].Keys | Where-Object { $_ -ne 'about' })) {
            $override = $spec['steps'][$stepName]
            if ($override -isnot [Collections.IDictionary]) { & $fail "products.$name.steps.$stepName must be an object." }
            $stray = @($override.Keys | Where-Object { @('about', 'kind', 'after', 'skip', 'strict') -notcontains $_ })
            if ($stray.Count) { & $fail "products.$name.steps.$stepName.$($stray[0]) is not a setting; a step has kind, after, skip, strict." }
            if ($override.Contains('kind') -and $kinds -notcontains $override['kind']) { & $fail "products.$name.steps.$stepName.kind is '$($override['kind'])'; the kinds are $($kinds -join ', ')." }
            if ($override.Contains('after') -and -not (& $isList $override['after'])) { & $fail "products.$name.steps.$stepName.after must be a list of step names." }
            foreach ($field in 'skip', 'strict') {
                if ($override.Contains($field) -and $override[$field] -isnot [bool]) { & $fail "products.$name.steps.$stepName.$field must be true or false." }
            }
        }
    }
    foreach ($name in @($config.presets.Keys | Where-Object { $_ -ne 'about' })) {
        $set = $config.presets[$name]
        if ($set.Contains('product') -and -not ("$($set['product'])" -eq 'all' -or (& $isList $set['product']))) { & $fail "presets.$name.product must be a list of products, or ""all""." }
        if ($set.Contains('step') -and -not (& $isList $set['step'])) { & $fail "presets.$name.step must be a list of step names." }
        if ($set.Contains('clear')) {
            $sets = @($config.clear.groups.Keys | Where-Object { $_ -ne 'about' })
            $strange = @(@($set['clear']) | Where-Object { $sets -notcontains $_ })
            if ($strange.Count) { & $fail "presets.$name.clear names $($strange -join ', '); the sets are $($sets -join ', ')." }
        }
    }
    return $config
}

# Each product's settings - its sql folder, whether it is skipped or kept from a drop, its step order and its
# step overrides. A product the catalog lacks is added when update-database.json names its database, reached
# with <envPrefix>_DB_* from .env where it gives one, else with DB_*.
function Get-ProductSetting($Products, [string]$EnvPath) {
    $settings = @{}
    foreach ($key in @($Products.Keys)) { $settings[$key] = [pscustomobject]@{ Folder = $key; Skip = $false; Drop = $true; Order = @(); Steps = [ordered]@{} } }
    foreach ($name in @($Config.products.Keys | Where-Object { $_ -ne 'about' })) {
        $spec = $Config.products[$name]
        $key = $name -replace '-', '_'
        if (-not $Products.Contains($key)) {
            if (-not $spec.Contains('database')) { throw "products.$name in $($Config._path) is no product in the catalog, and names no database to add it with." }
            $prefix = "$($spec['envPrefix'])"
            $read = { param([string]$Suffix, [string]$Fallback) if ($prefix) { Get-DotEnvValue $EnvPath "$($prefix)_DB_$Suffix" $Fallback } else { $Fallback } }
            $Products[$key] = [pscustomobject]@{
                Key        = $name
                Name       = $(if ($spec.Contains('name')) { "$($spec['name'])" } else { $name })
                Connection = "$($spec['connection'])"
                Database   = & $read 'DATABASE' "$($spec['database'])"
                DbHost     = & $read 'HOST' (Get-DotEnvValue $EnvPath 'DB_HOST' '127.0.0.1')
                Port       = & $read 'PORT' (Get-DotEnvValue $EnvPath 'DB_PORT' '3306')
                Username   = & $read 'USERNAME' (Get-DotEnvValue $EnvPath 'DB_USERNAME' 'root')
                Password   = & $read 'PASSWORD' (Get-DotEnvValue $EnvPath 'DB_PASSWORD' '')
            }
            $settings[$key] = [pscustomobject]@{ Folder = $key; Skip = $false; Drop = $true; Order = @(); Steps = [ordered]@{} }
        }
        if ($spec.Contains('folder')) { $settings[$key].Folder = "$($spec['folder'])" }
        if ($spec.Contains('skip')) { $settings[$key].Skip = $spec['skip'] }
        if ($spec.Contains('drop')) { $settings[$key].Drop = $spec['drop'] }
        if ($spec.Contains('order')) { $settings[$key].Order = @($spec['order']) }
        if ($spec.Contains('steps')) { $settings[$key].Steps = $spec['steps'] }
    }
    return $settings
}

$configPath = if ($ConfigFile) { [IO.Path]::GetFullPath($ConfigFile, (Get-Location).Path) } else { Join-Path $configsDir 'update-database.json' }
if ($ConfigFile -and -not (Test-Path -LiteralPath $configPath)) {
    Write-Host "  There is no settings file at $configPath." -ForegroundColor Red
    exit 1
}
try { $Config = Read-UpdateDatabaseConfig $configPath }
catch {
    Write-Host "  $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

if ($Help) {
    Write-Host @'

  update-database - apply sql/ to the local databases, in the order the files call for.

  Usage
    .\script.ps1 update-database [-Product <folder>[,...] | -AllProducts] [-Step <name>[,...]]
                                 [-Clear <set>[,...]]
                                 [-Drop | -DropAndRecreate] [-Idempotent] [-Migrate]
                                 [-Preset <name>] [-ConfigFile <file>] [-DryRun]

  With nothing picked it lists every product's steps and applies none.

  Products are config/products.php's catalog; a product's steps are the .sql
  files in sql/<folder>, and patches for the files in sql/<folder>/patches.

  Steps run in the order their contents call for, whatever order they are typed:
    rebuild -> structure -> retrofit -> data -> patch
  and a file's own "-- update-database: after <file>" line moves it later. A
  product's order in update-database.json, when it has one, is the order of the
  steps it lists; a step it leaves out runs after them, and is flagged.

  -Drop removes each picked product's database completely, as phpMyAdmin's
  Drop does, and builds nothing back. It takes no -Step or -Migrate.

  -Idempotent means everything - every product, every step, the migrations -
  skipping what is already applied and never rebuilding. A second run does
  nothing.

  -DropAndRecreate is the two in that order: each picked product's database
  removed, then built up to date - every step, or the -Step ones, and the
  migrations. -Drop -Idempotent is the same thing.

  -Clear empties tables and keeps them - TRUNCATE, with foreign key checks off,
  so AUTO_INCREMENT starts again - by the sets update-database.json names: all
  (every table), necessary (the rows the data files load), non-important (logs,
  notifications, one-time codes, sessions, caches, jobs), or your own. The
  migrations table is never cleared. It takes only the products named and runs
  before any step, so -Clear necessary -Step data loads the data again. The
  steps that inserted the cleared rows are forgotten, so -Idempotent runs them
  again; a set with migrations (all, by default) also forgets the cleared
  database's migrations, so migrate puts back the rows they seed.

  A drop takes only the products named, and core when it is picked - its
  database is also Laravel's own, migrations table and all. The product's rows
  in the core ledger and the record of every migration that writes to a
  dropped database go with it, so the next migrate runs those again. A rebuild
  file - one that opens with DROP DATABASE - is refused on a database that has
  tables; -DropAndRecreate is how one is built again.

  Settings live in .ikaika_scripts_configs/update-database.json, or -ConfigFile:
  the sql folder, the order kinds run in, the errors that mean "already there",
  how a database is created, the migrations and the core ledger, each product's
  overrides, and named runs for -Preset. Every key is optional, and a key left
  out keeps today's value.

  Examples
    .\script.ps1 update-database -Product portal -Step data
    .\script.ps1 update-database -Product transaction_tracker -Step patches
    .\script.ps1 update-database -Product transaction_tracker -Drop
    .\script.ps1 update-database -Product portal -DropAndRecreate
    .\script.ps1 update-database -Product portal -Clear necessary -Step data
    .\script.ps1 update-database -AllProducts -Clear non-important -DryRun
    .\script.ps1 update-database -Product portal,transaction_tracker -DropAndRecreate -DryRun
    .\script.ps1 update-database -AllProducts -Step indexes,patches -DryRun
    .\script.ps1 update-database -Preset portal-from-nothing -DryRun

  The databases and credentials come from .env, as config/products.php reads them.

'@
    $named = @($Config.presets.Keys | Where-Object { $_ -ne 'about' })
    if ($named.Count) {
        Write-Host "  Presets, from $($Config._path)"
        foreach ($name in $named) { Write-Host "    $name$(if ($Config.presets[$name].about) { " - $($Config.presets[$name].about)" })" }
        Write-Host ''
    }
    exit 0
}

Start-BuildLog 'update-database'

# A preset fills in only what was not typed, so a named run can still be narrowed or widened by hand.
if ($Preset) {
    $named = @($Config.presets.Keys | Where-Object { $_ -ne 'about' })
    if ($named -notcontains $Preset) {
        Write-Host "  There is no preset called $Preset in $($Config._path)$(if ($named.Count) { "; there are $($named -join ', ')" } else { '; it has none' })." -ForegroundColor Red
        exit 1
    }
    $set = $Config.presets[$Preset]
    if ($set.Contains('product') -and -not $PSBoundParameters.ContainsKey('Product') -and -not $PSBoundParameters.ContainsKey('AllProducts')) {
        if ("$($set['product'])" -eq 'all') { $AllProducts = $true } else { $Product = @($set['product']) }
    }
    if ($set.Contains('step') -and -not $PSBoundParameters.ContainsKey('Step')) { $Step = @($set['step']) }
    if ($set.Contains('clear') -and -not $PSBoundParameters.ContainsKey('Clear')) { $Clear = @($set['clear']) }
    foreach ($pair in @(@('drop', 'Drop'), @('dropAndRecreate', 'DropAndRecreate'), @('idempotent', 'Idempotent'), @('migrate', 'Migrate'))) {
        if ($set.Contains($pair[0]) -and -not $PSBoundParameters.ContainsKey($pair[1])) { Set-Variable -Name $pair[1] -Value $set[$pair[0]] }
    }
}

$sqlRoot = Join-Path $repoRoot $Config.sqlRoot
$envPath = Join-Path $repoRoot '.env'
try {
    $products = Get-ProductDatabase $envPath (Join-Path $repoRoot $Config.catalog)
    $settings = Get-ProductSetting $products $envPath
} catch {
    Write-Host "  $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
$coreKey = "$($Config.coreLedger.product)" -replace '-', '_'

# Errors that mean the statement's work is already there, so a retrofit or patch that meets them has nothing
# left to do on that line - by default table exists, duplicate column, key or row, primary key defined,
# nothing to drop, duplicate foreign key.
$AlreadyThere = @($Config.alreadyThere | ForEach-Object { [int]$_ })
$KindRank = @{}
for ($i = 0; $i -lt $Config.kindOrder.Count; $i++) { $KindRank[$Config.kindOrder[$i]] = $i }

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
        InsertTables = @($lines | Where-Object { $_ -match '^\s*(INSERT(\s+IGNORE)?|REPLACE)\s+INTO\s+' } |
                ForEach-Object { if ($_ -match 'INTO\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?') { $Matches[1] } } | Select-Object -Unique)
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

# A product's steps in the order they run: its order from update-database.json for the steps it lists, then
# the rest by kind and name, each moved by its "after" lines - which a step's settings replace - while the
# listed ones stay where the order puts them. A step the settings skip is not a step.
function Get-ProductStep([string]$FolderPath, $Setting = $null) {
    $found = [Collections.Generic.List[object]]::new()
    foreach ($file in @(Get-ChildItem -LiteralPath $FolderPath -Filter '*.sql' -File | Sort-Object Name)) {
        $found.Add([pscustomobject]@{ Name = $file.BaseName; Label = $file.Name; Facts = (Get-SqlFileFacts $file.FullName $false); Strict = $null; Listed = $false })
    }
    $patchDir = Join-Path $FolderPath $Config.patches
    if (Test-Path -LiteralPath $patchDir) {
        foreach ($file in @(Get-ChildItem -LiteralPath $patchDir -Filter '*.sql' -File | Sort-Object Name)) {
            $found.Add([pscustomobject]@{ Name = $Config.patches; Label = "$($Config.patches)/$($file.Name)"; Facts = (Get-SqlFileFacts $file.FullName $true); Strict = $null; Listed = $false })
        }
    }
    $overrides = if ($Setting) { $Setting.Steps } else { [ordered]@{} }
    $steps = foreach ($s in $found) {
        $override = if ($overrides.Contains($s.Label)) { $overrides[$s.Label] } elseif ($overrides.Contains($s.Name)) { $overrides[$s.Name] } else { $null }
        if ($override) {
            if ($override.Contains('skip') -and $override['skip']) { continue }
            if ($override.Contains('kind')) { $s.Facts.Kind = $override['kind'] }
            if ($override.Contains('after')) { $s.Facts.After = @($override['after']) }
            if ($override.Contains('strict')) { $s.Strict = $override['strict'] }
        }
        $s
    }
    $order = @(if ($Setting) { $Setting.Order })
    # Where the order lists a step, by its file name or its step name and in any case; -1 when it does not.
    $position = {
        param($Item)
        for ($i = 0; $i -lt $order.Count; $i++) { if ($order[$i] -eq $Item.Label -or $order[$i] -eq $Item.Name) { return $i } }
        return -1
    }
    foreach ($s in @($steps)) { $s.Listed = (& $position $s) -ge 0 }
    $rank = {
        param($Item)
        $at = & $position $Item
        if ($at -ge 0) { return $at }
        return $order.Count + $KindRank[$Item.Facts.Kind]
    }
    $ordered = [Collections.Generic.List[object]]::new()
    foreach ($s in @($steps | Sort-Object { & $rank $_ }, Label)) { $ordered.Add($s) }
    # Each pass moves a file after anything it names that still comes later; a cycle stops at the cap.
    for ($pass = 0; $pass -lt $ordered.Count; $pass++) {
        $moved = $false
        foreach ($s in @($ordered)) {
            if ($s.Listed) { continue }
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

# The names a product's order gives that no file in its folder answers to - a typo there would run a step
# out of place. Skipped steps still answer, since they are on disk.
function Get-OrderStray([string]$FolderPath, $Setting) {
    $order = @(if ($Setting) { $Setting.Order })
    if (-not $order.Count) { return @() }
    $names = @(Get-ChildItem -LiteralPath $FolderPath -Filter '*.sql' -File | ForEach-Object { $_.BaseName; $_.Name })
    $patchDir = Join-Path $FolderPath $Config.patches
    if (Test-Path -LiteralPath $patchDir) {
        $names += $Config.patches
        $names += @(Get-ChildItem -LiteralPath $patchDir -Filter '*.sql' -File | ForEach-Object { "$($Config.patches)/$($_.Name)" })
    }
    return @($order | Where-Object { $names -notcontains $_ })
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

# The tables a database has, with InnoDB's estimate of their rows - an estimate, which is all a listing needs.
function Get-TableRow([object]$Product, [string]$Database) {
    $rows = [ordered]@{}
    $answer = Invoke-MysqlQuery $Product "SELECT table_name, COALESCE(table_rows, 0) FROM information_schema.tables WHERE table_schema = '$($Database -replace "'", "''")' AND table_type = 'BASE TABLE'"
    foreach ($line in @($answer)) {
        $parts = "$line" -split "`t"
        if ($parts.Count -ge 2 -and $parts[1] -match '^\d+$') { $rows[$parts[0]] = [long]$parts[1] }
    }
    return $rows
}

# What -Clear empties in one database: the tables each set names - by pattern, or as the tables the steps it
# names (by name or kind) insert into - less what that set excepts, then less what clear.keep always keeps. A
# table the database lacks is no error; a set names what a product may have.
function Get-ClearSet([object]$Db, [string]$Database, $Steps, [string[]]$Groups) {
    $present = Get-TableRow $Db $Database
    $chosen = [Collections.Generic.List[string]]::new()
    foreach ($groupName in $Groups) {
        $set = $Config.clear.groups[$groupName]
        $names = [Collections.Generic.List[string]]::new()
        if ($set.Contains('tables')) {
            foreach ($like in @($set['tables'])) { foreach ($table in @($present.Keys)) { if ($table -like $like -and -not $names.Contains($table)) { $names.Add($table) } } }
        }
        if ($set.Contains('loadedBy')) {
            foreach ($source in @($set['loadedBy'])) {
                foreach ($s in @($Steps | Where-Object { ($_.Name -eq $source -or $_.Facts.Kind -eq $source) -and $(if ($_.Facts.Target) { $_.Facts.Target } else { $Db.Database }) -eq $Database })) {
                    foreach ($table in @($s.Facts.InsertTables)) { if ($present.Contains($table) -and -not $names.Contains($table)) { $names.Add($table) } }
                }
            }
        }
        foreach ($table in $names) {
            $excepted = $set.Contains('except') -and @($set['except'] | Where-Object { $table -like $_ }).Count
            if (-not $excepted -and -not $chosen.Contains($table)) { $chosen.Add($table) }
        }
    }
    $tables = @($chosen | Where-Object { $name = $_; -not @($Config.clear.keep | Where-Object { $name -like $_ }).Count } | Sort-Object)
    $rows = [long]0
    foreach ($table in $tables) { $rows += $present[$table] }
    return [pscustomobject]@{ Database = $Database; Tables = $tables; Rows = $rows }
}

# A few names and how many more, for a line that has to stay a line.
function Format-Names([string[]]$Names, [int]$Show = 6) {
    if ($Names.Count -le $Show) { return $Names -join ', ' }
    return "$(($Names | Select-Object -First $Show) -join ', '), and $($Names.Count - $Show) more"
}

# The databases a product's steps write to - each file's USE, or the product's .env database - which is what a
# drop removes and a clear looks through.
function Get-StepDatabase([object]$Db, $Steps) {
    return @(@($Steps | ForEach-Object { if ($_.Facts.Target) { $_.Facts.Target } else { $Db.Database } }) + @($Db.Database) | Select-Object -Unique)
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
    $dir = Join-Path $repoRoot $Config.migrations.path
    if (-not (Test-Path -LiteralPath $dir)) { return @() }
    return @(Get-ChildItem -LiteralPath $dir -Filter '*.php' -File | Sort-Object Name | ForEach-Object {
            $text = Get-Content -LiteralPath $_.FullName -Raw
            $named = @($ConnectionDatabase.Keys | Where-Object { $text -match "(\(\s*|\`$connection\s*=\s*)['""]$([regex]::Escape($_))['""]" })
            [pscustomobject]@{ Name = $_.BaseName; Databases = @(if ($named.Count) { $named | ForEach-Object { $ConnectionDatabase[$_] } } else { $DefaultDatabase }); Text = $text }
        })
}

# ------------------------------------------------------------------------ the ledger
# What -Idempotent skips: which file, at which content hash, was applied to which database. A local
# file rather than a table, so no product schema carries a table that belongs to a build tool; the
# hash, not the name, so a file edited after it was applied counts as unapplied again; and believed
# only while the database still has tables, since anything can have happened to it in between.
$LedgerPath = Join-Path $repoRoot $Config.ledger

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
$folders = @($products.Keys | Where-Object { -not $settings[$_].Skip -and (Test-Path -LiteralPath (Join-Path $sqlRoot $settings[$_].Folder)) })
$skipped = @($products.Keys | Where-Object { $settings[$_].Skip })
$claimed = @($products.Keys | ForEach-Object { $settings[$_].Folder })
$orphans = @(Get-ChildItem -LiteralPath $sqlRoot -Directory | Where-Object { $claimed -notcontains $_.Name -and (Get-ChildItem -LiteralPath $_.FullName -Filter '*.sql' -Recurse -File) } | ForEach-Object Name)

$wantedFolders = @($Product | ForEach-Object { "$_" -split ',' } | ForEach-Object { $_.Trim() -replace '-', '_' } | Where-Object { $_ })
$unknown = @($wantedFolders | Where-Object { $folders -notcontains $_ })
if ($unknown.Count) {
    Write-Host "  There is no product called $($unknown -join ', ') with a sql folder; there are $($folders -join ', ')." -ForegroundColor Red
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
$clearGroups = @($Clear | ForEach-Object { "$_" -split ',' } | ForEach-Object { $_.Trim() } | Where-Object { $_ } | Select-Object -Unique)
if ($clearGroups.Count) {
    $sets = @($Config.clear.groups.Keys | Where-Object { $_ -ne 'about' })
    $strange = @($clearGroups | Where-Object { $sets -notcontains $_ })
    if ($strange.Count) {
        Write-Host "  There is no set called $($strange -join ', ') to clear; $(Split-Path -Leaf $Config._path) has $($sets -join ', ')." -ForegroundColor Red
        exit 1
    }
    if (-not $wantedFolders.Count) {
        Write-Host '  A clear takes only what is named: -Product <folder>, or -AllProducts.' -ForegroundColor Red
        exit 1
    }
    if ($wiping) {
        Write-Host '  A drop already takes every row with the tables; -Clear empties the tables of a database that stays.' -ForegroundColor Red
        exit 1
    }
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
if ($rebuild) { $Idempotent = $true; if ($Config.migrations.afterRebuild) { $Migrate = $true } }
# -Idempotent with nothing else named means everything, migrations included: every column added since
# the sql/ files were written lives in a migration, and artisan migrate skips what it has already run.
if ($Idempotent -and -not $wantedFolders.Count -and -not $wantedSteps.Count) {
    $wantedFolders = $folders
    $Migrate = $true
}
$everyStep = $Idempotent -and -not $wantedSteps.Count
$drops = @()
$clears = @()

# Laravel's own database - the default connection, where the migrations table is - and the database behind each connection.
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
Write-Host "  settings: $($Config._path)$(if (-not $Config._present) { ' - not there, so every value is the default' })" -ForegroundColor DarkGray
if ($Preset) { Write-Host "  preset:   $Preset$(if ($Config.presets[$Preset].about) { " - $($Config.presets[$Preset].about)" })" -ForegroundColor DarkGray }

$ledger = Get-Ledger
$ledgerTouched = $false
$plan = @()
$knownSteps = @()
foreach ($folder in $folders) {
    $db = $products[$folder]
    Write-Host ''
    Write-Host "  $($db.Name)  ($($Config.sqlRoot)/$($settings[$folder].Folder))" -ForegroundColor Cyan
    Write-Host "    .env says $($db.Database) on $($db.DbHost):$($db.Port) as $($db.Username)" -ForegroundColor DarkGray
    $productSteps = Get-ProductStep (Join-Path $sqlRoot $settings[$folder].Folder) $settings[$folder]
    $stray = @(Get-OrderStray (Join-Path $sqlRoot $settings[$folder].Folder) $settings[$folder])
    if ($stray.Count) {
        Write-Host "  products.$folder.order in $(Split-Path -Leaf $Config._path) names $($stray -join ', '), which no file in $($Config.sqlRoot)/$($settings[$folder].Folder) answers to." -ForegroundColor Red
        exit 1
    }
    $hasOrder = @($settings[$folder].Order).Count -gt 0

    if ($wiping -and $wantedFolders -contains $folder -and -not $settings[$folder].Drop) {
        Write-Host "    kept     products.$folder.drop is false in $(Split-Path -Leaf $Config._path), so no drop touches it" -ForegroundColor DarkGray
    } elseif ($wiping -and $wantedFolders -contains $folder) {
        $databases = Get-StepDatabase $db $productSteps
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

    $clearing = @()
    if ($clearGroups.Count -and $wantedFolders -contains $folder) {
        $databases = Get-StepDatabase $db $productSteps
        $odd = @($databases | Where-Object { $_ -notmatch '^[A-Za-z0-9_]+$' })
        if ($odd.Count) {
            Write-Host "  $($odd -join ', ') is not a plain database name, so a clear will not touch it." -ForegroundColor Red
            exit 1
        }
        $clearing = @($databases | ForEach-Object { Get-ClearSet $db $_ $productSteps $clearGroups } | Where-Object { $_.Tables.Count })
        Write-Host '    CLEAR    ' -ForegroundColor Yellow -NoNewline
        if (-not $clearing.Count) { Write-Host "$($clearGroups -join ', '): no table here is in it" }
        for ($i = 0; $i -lt $clearing.Count; $i++) {
            if ($i) { Write-Host '             ' -NoNewline }
            Write-Host "$($clearGroups -join ', ') in $($clearing[$i].Database): $($clearing[$i].Tables.Count) table(s), about $($clearing[$i].Rows) row(s) - $(Format-Names $clearing[$i].Tables)"
        }
        $flag = { param([string]$Field) @($clearGroups | Where-Object { $Config.clear.groups[$_].Contains($Field) -and $Config.clear.groups[$_][$Field] }).Count -gt 0 }
        $clears += [pscustomobject]@{ Folder = $folder; Product = $db; Sets = $clearing; Steps = $productSteps; CoreLedger = (& $flag 'coreLedger'); Migrations = (& $flag 'migrations') }
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
        if ($hasOrder -and -not $s.Listed) { $notes += "not in products.$folder.order - runs after the steps it lists" }
        if ($facts.After.Count -and -not $s.Listed) { $notes += "after $($facts.After -join ', ')" }
        if ($facts.Target -and $facts.Target -ne $db.Database) { $notes += "writes to $($facts.Target), but .env reads $($db.Database)" }
        if (-not $facts.Target) { $notes += "no USE line - runs against $($db.Database)" }

        $picked = ($wantedFolders -contains $folder) -and ($everyStep -or ($wantedSteps -contains $s.Name))
        $key = "$folder/$($s.Label)"
        $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $facts.Path).Hash.ToLower()
        $applied = $false
        # A step that inserts into a table the clear empties is not applied any more, whatever the ledger says.
        $emptied = @($clearing | Where-Object { $_.Database -eq $target } | ForEach-Object { $_.Tables })
        $afterClear = @($facts.InsertTables | Where-Object { $emptied -contains $_ }).Count -gt 0
        if ($Idempotent -and $picked -and -not $afterDrop -and -not $afterClear) {
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
    Write-Host "  $($Config.sqlRoot)/$orphan has .sql files but no product claims it - in the catalog, or in $(Split-Path -Leaf $Config._path)'s products - so nothing knows its database; it is skipped." -ForegroundColor Yellow
}
if ($skipped.Count) {
    Write-Host ''
    Write-Host "  Skipped by $(Split-Path -Leaf $Config._path): $($skipped -join ', ')" -ForegroundColor DarkGray
}

# The migration records that describe a dropped database, forgotten so migrate runs them again - unless the
# migrations table is in a dropped database itself, in which case every record goes with it.
function Get-ForgottenMigration([string[]]$Databases) {
    if (-not $Databases.Count -or $Databases -contains $laravelDb.Database) { return @() }
    return @(Get-MigrationTarget $connectionDatabase $laravelDb.Database |
            Where-Object { @($_.Databases | Where-Object { $Databases -contains $_ }).Count -and $_.Name -match '^[A-Za-z0-9_]+$' } | ForEach-Object Name)
}
# The records of every migration that writes to one of these databases and checks before it writes, forgotten
# so migrate runs them again in order - as on a fresh machine - and puts back the rows they seed. One that does
# not check is left alone: Laravel's own only create tables, and a clear leaves the tables there.
function Get-ClearedMigration([string[]]$Databases) {
    if (-not $Databases.Count) { return @() }
    return @(Get-MigrationTarget $connectionDatabase $laravelDb.Database | Where-Object {
            @($_.Databases | Where-Object { $Databases -contains $_ }).Count -and
            $_.Text -match 'hasTable|hasColumn|hasIndex|insertOrIgnore|updateOrInsert|upsert\(|->exists\(\)' -and
            $_.Name -match '^[A-Za-z0-9_]+$'
        } | ForEach-Object Name)
}
$clearForget = Get-ClearedMigration @($clears | Where-Object { $_.Migrations } | ForEach-Object { $_.Sets } | ForEach-Object { $_.Database } | Select-Object -Unique)
if ($clearForget.Count) {
    Write-Host ''
    Write-Host "  Migrations  $($clearForget.Count) record(s) in $($laravelDb.Database).$($Config.migrations.table) write to a cleared database; they are forgotten, so migrate runs them again and puts back the rows they seed$(if (-not $Migrate) { ' - add -Migrate, or run it after' })" -ForegroundColor DarkGray
}

$droppedDatabases = @($drops | ForEach-Object { $_.Databases } | Select-Object -Unique)
$coreGone = $products.Contains($coreKey) -and $droppedDatabases -contains $products[$coreKey].Database
$forget = Get-ForgottenMigration $droppedDatabases
if ($forget.Count) {
    Write-Host ''
    Write-Host "  Migrations  $($forget.Count) record(s) in $($laravelDb.Database).$($Config.migrations.table) write to a dropped database; they are forgotten, so migrate runs them again" -ForegroundColor DarkGray
}

$strayStep = @($wantedSteps | Where-Object { $knownSteps -notcontains $_ })
if ($strayStep.Count) {
    Write-Host ''
    Write-Host "  No product has a step called $($strayStep -join ', '); the steps are $(@($knownSteps | Select-Object -Unique) -join ', ')." -ForegroundColor Red
    exit 1
}

if (-not $plan.Count -and -not $drops.Count -and -not $clears.Count -and -not $Migrate) {
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
    foreach ($wipe in $drops) { Write-Host "    drop $($wipe.Databases -join ', ') ($($wipe.Folder))$(if ($rebuild) { ', and create it empty' })$(if ($wipe.Folder -ne $coreKey -and -not $coreGone) { ", then clear $($wipe.Product.Key)'s core ledger rows" })" }
    if ($forget.Count) { Write-Host "    forget $($forget.Count) migration record(s) in $($laravelDb.Database).$($Config.migrations.table)" }
    foreach ($c in $clears) {
        foreach ($set in $c.Sets) { Write-Host "    clear $($set.Tables.Count) table(s) in $($set.Database) ($($c.Folder)) - $(Format-Names $set.Tables)" }
        if ($c.Sets.Count -and $c.CoreLedger -and $c.Folder -ne $coreKey) { Write-Host "    clear $($c.Product.Key)'s core ledger rows" }
    }
    if ($clearForget.Count) { Write-Host "    forget $($clearForget.Count) migration record(s) in $($laravelDb.Database).$($Config.migrations.table)" }
    foreach ($item in $plan) { Write-Host "    $($item.Folder)/$($item.Step.Label) -> $($item.Target)$(if ($item.Facts.Kind -eq 'rebuild' -and $drops.Folder -notcontains $item.Folder) { ', then clear its core ledger rows' })" }
    if ($Migrate) { Write-Host '    php artisan migrate' }
    Write-Host ''
    exit 0
}

# ------------------------------------------------------------------------ applying
if (($plan.Count -or $drops.Count -or $clears.Count) -and -not (Get-Command mysql -ErrorAction SilentlyContinue)) {
    Write-Host ''
    Write-Host '  No mysql client on PATH, so no file can be applied.' -ForegroundColor Red
    Write-Host '    .\script.ps1 environment-variables     put one there' -ForegroundColor DarkGray
    Write-Host ''
    exit 1
}

$failures = 0
if ($plan.Count -or $drops.Count -or $clears.Count) { Write-Host ''; Write-Host '  Applying' -ForegroundColor Cyan }

function Write-Applied([object]$Item, [string]$Note) {
    if (-not $Idempotent) { return }
    $ledger["$($Item.Target)|$($Item.Key)"] = @{ sha256 = $Item.Hash; bytes = $Item.Facts.Bytes; note = $Note; applied_at = (Get-Date).ToString('o') }
    $script:ledgerTouched = $true
}

# The product's rows in the core ledger describe records its rebuilt database no longer has.
function Clear-CoreLedger([object]$Rebuilt) {
    if (-not $products.Contains($coreKey)) { return $true }
    $core = $products[$coreKey]
    $present = @(Get-PresentTable $core $core.Database)
    $tables = @($Config.coreLedger.tables | Where-Object { $present -contains $_ })
    if (-not $tables.Count) { return $true }
    $productKey = $Rebuilt.Key -replace "'", "''"
    $statements = ($tables | ForEach-Object { "DELETE FROM ``$($core.Database)``.``$_`` WHERE ``$($Config.coreLedger.column)`` = '$productKey';" }) -join ' '
    if ($null -eq (Invoke-MysqlQuery $core "$statements SELECT 'ok'")) {
        Write-Host "      could not clear $($Rebuilt.Key)'s rows from the core ledger in $($core.Database)" -ForegroundColor Yellow
        return $false
    }
    Write-Host "      cleared $($Rebuilt.Key)'s rows from the core ledger ($($tables -join ', '))" -ForegroundColor DarkGray
    return $true
}

$undropped = @()
$gone = @()
foreach ($wipe in $drops) {
    Write-Host "    drop $($wipe.Folder): $($wipe.Databases -join ', ')"
    # Created again empty only to be built back, so a step with no USE line and migrate have somewhere to write.
    $statements = ($wipe.Databases | ForEach-Object { "DROP DATABASE IF EXISTS ``$_``;$(if ($rebuild) { " CREATE DATABASE ``$_`` $($Config.createDatabase);" })" }) -join ' '
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
    if ($wipe.Folder -ne $coreKey -and -not $coreGone -and -not (Clear-CoreLedger $wipe.Product)) { $failures++ }
}
$forgetting = Get-ForgottenMigration $gone
$migrationsTable = $Config.migrations.table
if ($forgetting.Count -and @(Get-PresentTable $laravelDb $laravelDb.Database) -contains $migrationsTable) {
    $names = ($forgetting | ForEach-Object { "'$_'" }) -join ','
    if ($null -eq (Invoke-MysqlQuery $laravelDb "DELETE FROM ``$($laravelDb.Database)``.``$migrationsTable`` WHERE migration IN ($names); SELECT 'ok'")) {
        Write-Host "      could not forget the migrations that write to what was dropped, in $($laravelDb.Database).$migrationsTable" -ForegroundColor Yellow
        $failures++
    } else {
        Write-Host "      forgot $($forgetting.Count) migration record(s) in $($laravelDb.Database).$migrationsTable, so migrate runs them again" -ForegroundColor DarkGray
    }
}

$emptiedSets = @()
foreach ($c in $clears) {
    foreach ($set in $c.Sets) {
        Write-Host "    clear $($c.Folder): $($set.Tables.Count) table(s) in $($set.Database)"
        $sqlFile = Join-Path ([IO.Path]::GetTempPath()) "update-database-clear-$([guid]::NewGuid().ToString('N').Substring(0, 8)).sql"
        $truncates = @('SET FOREIGN_KEY_CHECKS=0;') + @($set.Tables | ForEach-Object { "TRUNCATE TABLE ``$($set.Database)``.``$_``;" }) + @('SET FOREIGN_KEY_CHECKS=1;')
        [IO.File]::WriteAllLines($sqlFile, [string[]]$truncates)
        try { $run = Invoke-SqlFile $c.Product ([pscustomobject]@{ Path = $sqlFile; Target = $set.Database }) $true }
        finally { Remove-Item -LiteralPath $sqlFile -Force -ErrorAction SilentlyContinue }
        if ($run.Errors.Count) {
            foreach ($line in @($run.Errors | Select-Object -First 5)) { Write-Host "      $line" -ForegroundColor Red }
            $failures++
            continue
        }
        Write-Host "      emptied $(Format-Names $set.Tables)" -ForegroundColor Green
        if ($c.Migrations) { $emptiedSets += $set.Database }
        # The steps that inserted into what was emptied are not applied any more, so -Idempotent runs them again.
        foreach ($s in @($c.Steps)) {
            $target = if ($s.Facts.Target) { $s.Facts.Target } else { $c.Product.Database }
            $key = "$target|$($c.Folder)/$($s.Label)"
            if ($target -eq $set.Database -and $ledger.Contains($key) -and @($s.Facts.InsertTables | Where-Object { $set.Tables -contains $_ }).Count) {
                $ledger.Remove($key)
                $ledgerTouched = $true
            }
        }
    }
    if ($c.Sets.Count -and $c.CoreLedger -and $c.Folder -ne $coreKey -and -not (Clear-CoreLedger $c.Product)) { $failures++ }
}
$seedsForgotten = Get-ClearedMigration @($emptiedSets | Select-Object -Unique)
if ($seedsForgotten.Count -and @(Get-PresentTable $laravelDb $laravelDb.Database) -contains $Config.migrations.table) {
    $names = ($seedsForgotten | ForEach-Object { "'$_'" }) -join ','
    if ($null -eq (Invoke-MysqlQuery $laravelDb "DELETE FROM ``$($laravelDb.Database)``.``$($Config.migrations.table)`` WHERE migration IN ($names); SELECT 'ok'")) {
        Write-Host "      could not forget the migrations that write to what was cleared, in $($laravelDb.Database).$($Config.migrations.table)" -ForegroundColor Yellow
        $failures++
    } else {
        Write-Host "      forgot $($seedsForgotten.Count) migration record(s), so migrate runs them again and puts back the rows they seed$(if (-not $Migrate) { ' - run it, or add -Migrate next time' })" -ForegroundColor DarkGray
    }
}

foreach ($item in $plan) {
    if ($undropped -contains $item.Folder) { continue }
    Write-Host "    $($item.Folder)/$($item.Step.Label)  ($($item.Facts.Kind))"
    $strict = if ($null -ne $item.Step.Strict) { $item.Step.Strict } else { $Config.strictKinds -contains $item.Facts.Kind }
    $run = Invoke-SqlFile $item.Product $item.Facts (-not $strict)
    $real = @($run.Errors | Where-Object { $AlreadyThere -notcontains (Get-ErrorCode $_) })
    $there = @($run.Errors | Where-Object { $AlreadyThere -contains (Get-ErrorCode $_) })

    if (-not $strict) {
        if ($real.Count) {
            foreach ($line in @($real | Select-Object -First 5)) { Write-Host "      $line" -ForegroundColor Red }
            if ($real.Count -gt 5) { Write-Host "      ...and $($real.Count - 5) more" -ForegroundColor Red }
            if ($real -match '^ERROR 1049') {
                $creator = @(Get-ProductStep (Join-Path $sqlRoot $settings[$item.Folder].Folder) $settings[$item.Folder] | Where-Object { $_.Facts.Kind -eq 'rebuild' } | Select-Object -First 1)
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
        if ($item.Facts.Kind -eq 'rebuild' -and $item.Folder -ne $coreKey -and $drops.Folder -notcontains $item.Folder) {
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

# Read by the manifest generator and run alone, so it finds the repo from the working directory itself, and
# reads update-database.json for its presets and its sql folder.
function Get-DialogChoiceParameters { return , @('Product', 'Step', 'Preset', 'Clear') }

function Get-DialogChoices([string]$Parameter) {
    $dir = (Get-Location).Path
    while ($dir -and -not (Test-Path -LiteralPath (Join-Path $dir 'ikaika_scripts.env'))) { $dir = Split-Path $dir -Parent }
    if (-not $dir) { return , @() }
    $settings = $null
    $settingsFile = Join-Path $dir '.ikaika_scripts_configs\update-database.json'
    if (Test-Path -LiteralPath $settingsFile) { try { $settings = Get-Content -LiteralPath $settingsFile -Raw | ConvertFrom-Json } catch { $settings = $null } }
    if ($Parameter -eq 'Preset') {
        if (-not $settings -or -not $settings.presets) { return , @() }
        return , @($settings.presets.PSObject.Properties.Name | Where-Object { $_ -ne 'about' })
    }
    if ($Parameter -eq 'Clear') {
        if (-not $settings -or -not $settings.clear -or -not $settings.clear.groups) { return , @('all', 'necessary', 'non-important') }
        return , @($settings.clear.groups.PSObject.Properties.Name | Where-Object { $_ -ne 'about' })
    }
    $root = Join-Path $dir $(if ($settings -and $settings.sqlRoot) { "$($settings.sqlRoot)" } else { 'sql' })
    if (-not (Test-Path -LiteralPath $root)) { return , @() }
    $folders = @(Get-ChildItem -LiteralPath $root -Directory | Where-Object { Get-ChildItem -LiteralPath $_.FullName -Filter '*.sql' -Recurse -File })
    if ($Parameter -eq 'Product') { return , @($folders | ForEach-Object Name) }
    if ($Parameter -eq 'Step') {
        $names = foreach ($folder in $folders) {
            Get-ChildItem -LiteralPath $folder.FullName -Filter '*.sql' -File | ForEach-Object BaseName
            $patches = if ($settings -and $settings.patches) { "$($settings.patches)" } else { 'patches' }
            if (Test-Path -LiteralPath (Join-Path $folder.FullName $patches)) { $patches }
        }
        return , @($names | Sort-Object -Unique)
    }
    return , @()
}
