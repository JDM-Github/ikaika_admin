# Everything this project knows about a WampServer installation: where it is, which
# versions it holds, which one it is actually serving, and how the terminal's PATH is
# kept pointing at that one.
#
# Dot-sourced by development-setup and environment-variables, so the two cannot disagree
# about which PHP is active - which is the whole hazard here. WampServer keeps every
# version it has ever been given side by side, and exactly one of each is live:
#
#     bin\php\php8.0.30  php8.1.33  php8.2.29  php8.3.28  [php8.4.15]  php8.5.0
#     bin\mysql\mysql8.0.46  [mysql8.4.7]
#     bin\mariadb\[mariadb11.4.9]
#
# THE ACTIVE ONE IS NOT DISCOVERABLE FROM THE DIRECTORIES. It is declared in
# wampmanager.conf, which the tray menu rewrites when somebody switches version, along
# with my.ini and the service definition. Newest-on-disk is therefore the wrong answer
# and a confident one: it happily hands back a PHP that WampServer is not running.
#
# WHY %WAMP_PHP% AND NOT THE PATH ITSELF. A literal C:\wamp64\bin\php\php8.4.15 on PATH
# is correct for exactly as long as nobody switches version, and wrong silently
# afterwards - `php -v` keeps answering, from a PHP that no longer matches the one
# serving the site. Worse, the obvious fix appends the new one, so PATH accumulates a
# version per switch and the FIRST one still wins. So PATH carries three indirections
# and nothing else, and a switch rewrites three variables instead:
#
#     PATH       ...;%WAMP_PHP%;%WAMP_DB%;%WAMP_APACHE%;...
#     WAMP_PHP   C:\wamp64\bin\php\php8.4.15
#
# That needs PATH to be REG_EXPAND_SZ, which Set-UserPathEntry keeps it as. A machine
# where it is not gets -Direct, which writes the literal paths and accepts the staleness.

# The user PATH as it is WRITTEN, not as it is resolved. Read through
# [Environment]::GetEnvironmentVariable and every %VAR% in it comes back already
# expanded, so writing it back bakes today's values in permanently - and the damage only
# surfaces when a profile moves or a drive letter changes. DoNotExpandEnvironmentNames
# is the entire reason this is done by hand.
function Get-UserPathEntry {
    $key = [Microsoft.Win32.Registry]::CurrentUser.OpenSubKey('Environment')
    if (-not $key) { return @() }
    try {
        $raw = "$($key.GetValue('Path', '', [Microsoft.Win32.RegistryValueOptions]::DoNotExpandEnvironmentNames))"
    } finally { $key.Dispose() }
    return @($raw.Split(';') | ForEach-Object { $_.Trim() } | Where-Object { $_ })
}

function Set-UserPathEntry([string[]]$Entries) {
    $key = [Microsoft.Win32.Registry]::CurrentUser.OpenSubKey('Environment', $true)
    if (-not $key) { throw 'Could not open HKCU\Environment for writing.' }
    try {
        # The kind is preserved rather than chosen, with one exception: an entry holding
        # a %VAR% in a REG_SZ value would never resolve, so a PATH that is about to carry
        # one is promoted. Demoting never happens - that would break somebody else's
        # existing entries.
        $kind = 'ExpandString'
        try { $kind = "$($key.GetValueKind('Path'))" } catch { $kind = 'ExpandString' }
        if ($kind -ne 'ExpandString' -and @($Entries | Where-Object { $_ -match '%' }).Count) {
            $kind = 'ExpandString'
        }
        if ($kind -ne 'ExpandString' -and $kind -ne 'String') { $kind = 'ExpandString' }
        $key.SetValue('Path', ($Entries -join ';'), $kind)
    } finally { $key.Dispose() }
}

function Find-WampRoot([string]$Preferred = '') {
    if ($Preferred -and (Test-Path -LiteralPath (Join-Path $Preferred 'wampmanager.exe'))) { return $Preferred }

    # Inno records where it actually put things, which is the only source that survives
    # somebody choosing a different directory during a manual install.
    foreach ($hive in @('HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall',
            'HKLM:\SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall')) {
        if (-not (Test-Path $hive)) { continue }
        foreach ($entry in (Get-ChildItem $hive -ErrorAction SilentlyContinue)) {
            $properties = Get-ItemProperty $entry.PSPath -ErrorAction SilentlyContinue
            if ("$($properties.DisplayName)" -notmatch 'wampserver') { continue }
            $location = "$($properties.InstallLocation)".Trim()
            if ($location -and (Test-Path -LiteralPath (Join-Path $location 'wampmanager.exe'))) {
                return $location
            }
        }
    }

    foreach ($candidate in @('C:\wamp64', 'C:\wamp')) {
        if (Test-Path -LiteralPath (Join-Path $candidate 'wampmanager.exe')) { return $candidate }
    }
    return ''
}

# Which version WampServer is SERVING, from its own config. $Key is phpVersion,
# mysqlVersion, mariadbVersion or apacheVersion.
function Get-WampActiveVersion([string]$Root, [string]$Key) {
    if (-not $Root) { return '' }
    $conf = Join-Path $Root 'wampmanager.conf'
    if (-not (Test-Path -LiteralPath $conf)) { return '' }
    foreach ($line in (Get-Content -LiteralPath $conf)) {
        if ($line -match "^\s*$Key\s*=\s*`"([^`"]+)`"") { return $Matches[1] }
    }
    return ''
}

# Newest version of a series that is ON DISK - which answers "has the add-on landed",
# and deliberately not "what will run". Get-WampActiveVersion answers that.
function Get-WampComponentDir([string]$Root, [string]$Kind, [string]$Series) {
    if (-not $Root) { return '' }
    $parent = Join-Path $Root "bin\$Kind"
    if (-not (Test-Path -LiteralPath $parent)) { return '' }
    $prefix = "$Kind$Series."
    $matched = @(Get-ChildItem -LiteralPath $parent -Directory -ErrorAction SilentlyContinue |
            Where-Object { $_.Name.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase) } |
            Sort-Object { try { [version]($_.Name -replace "^$Kind", '') } catch { [version]'0.0' } })
    if (-not $matched.Count) { return '' }
    return $matched[-1].FullName
}

function Get-WampComponentVersion([string]$Path, [string]$Kind) {
    if (-not $Path) { return '' }
    return (Split-Path $Path -Leaf) -replace "^$Kind", ''
}

# WampServer's own installer starts wampmanager when it finishes, and an add-on that then
# tries to replace files under bin\ is refused by Inno's pre-install stage with a bare
# exit 7. So the running server is stopped BEFORE any add-on, deliberately, rather than
# hoping /FORCECLOSEAPPLICATIONS reaches all six processes.
#
# Left stopped afterwards. Starting WampServer is the tray icon's job - it has its own
# port checks and a version switch behind it - and a half-started one is worse than a
# stopped one, because the icon is then orange for a reason nobody can see.
function Stop-WampServer {
    $stopped = @()
    # Named rather than thrown. One of these refusing is not worth aborting over - the
    # add-on may well not need that particular file - but it is exactly what explains a
    # refusal thirty seconds later, so it is said out loud instead of swallowed.
    $refused = @()

    foreach ($service in @('wampapache64', 'wampmysqld64', 'wampmariadb64')) {
        $found = Get-Service -Name $service -ErrorAction SilentlyContinue
        if (-not $found -or $found.Status -eq 'Stopped') { continue }
        try { Stop-Service -Name $service -Force -ErrorAction Stop; $stopped += $service }
        catch { $refused += "$service ($($_.Exception.Message))" }
    }
    foreach ($name in @('wampmanager', 'httpd', 'mysqld', 'mariadbd')) {
        foreach ($process in @(Get-Process -Name $name -ErrorAction SilentlyContinue)) {
            # A process that exited on its own between the enumeration and the Kill is
            # the outcome this wanted, so it is not counted as refused.
            if ($process.HasExited) { continue }
            try { $process.Kill(); $stopped += $name }
            catch { $refused += "$name ($($_.Exception.Message))" }
        }
    }

    if ($stopped.Count) {
        # Kill returns when the request is made, not when the handles are gone, and the
        # file locks are precisely what the installer is about to trip over.
        Start-Sleep -Seconds 3
        Write-Host "      stopped $(@($stopped | Select-Object -Unique) -join ', ')" -ForegroundColor DarkGray
    }
    if ($refused.Count) {
        Write-Host "      still running: $(@($refused | Select-Object -Unique) -join '; ')" -ForegroundColor Yellow
    }
    return $stopped.Count
}

# WampServer ships php.ini twice, and they are not the same file: the one beside php.exe
# is what the CLI reads, and the one under Apache's bin is what the web server reads.
# Composer and artisan use the first, a browser request uses the second, so an extension
# switched on in only one of them produces the exact bug where the site works and the
# command line does not.
function Get-PhpIniPaths([string]$Root, [string]$PhpDir) {
    $paths = @()
    foreach ($candidate in @((Join-Path $PhpDir 'php.ini'), (Join-Path $PhpDir 'phpForApache.ini'))) {
        if (Test-Path -LiteralPath $candidate) { $paths += $candidate }
    }
    $apacheBin = Join-Path $Root 'bin\apache'
    if (Test-Path -LiteralPath $apacheBin) {
        foreach ($ini in (Get-ChildItem -LiteralPath $apacheBin -Recurse -Filter 'php.ini' -ErrorAction SilentlyContinue)) {
            $paths += $ini.FullName
        }
    }
    return @($paths | Select-Object -Unique)
}

function Get-DisabledExtensions([string]$IniPath, [string[]]$Names) {
    if (-not (Test-Path -LiteralPath $IniPath)) { return @() }
    $lines = Get-Content -LiteralPath $IniPath
    $disabled = @()
    foreach ($name in $Names) {
        $enabled = @($lines | Where-Object { $_ -match "^\s*extension\s*=\s*""?$([regex]::Escape($name))(\.dll)?""?\s*$" }).Count
        if (-not $enabled) { $disabled += $name }
    }
    return $disabled
}

function Enable-PhpExtension([string]$IniPath, [string[]]$Names) {
    $lines = @(Get-Content -LiteralPath $IniPath)
    $changed = @()
    foreach ($name in $Names) {
        $pattern = "^\s*;\s*extension\s*=\s*""?$([regex]::Escape($name))(\.dll)?""?\s*$"
        $index = -1
        for ($i = 0; $i -lt $lines.Count; $i++) {
            if ($lines[$i] -match $pattern) { $index = $i; break }
        }
        if ($index -ge 0) {
            $lines[$index] = $lines[$index] -replace '^\s*;\s*', ''
            $changed += $name
            continue
        }
        # No commented line to uncomment: a version that never shipped the line at all.
        # Appended rather than skipped, because php reports a missing extension only when
        # something finally needs it, which is halfway through a migration.
        $lines += "extension=$name"
        $changed += $name
    }
    if ($changed.Count) {
        [IO.File]::WriteAllLines($IniPath, $lines, [System.Text.UTF8Encoding]::new($false))
    }
    return $changed
}

# =====================[ the environment this project wants ]==================

# One row per variable this project manages, in PATH order. Nothing is written here -
# this is the plan, and -Report prints exactly this and stops.
#
# $DbFlavor decides which of mysql\ and mariadb\ wins, and it matters more than it looks:
# both ship a mysql.exe, so whichever lands on PATH first IS `mysql` for everybody on
# this machine. Defaulting by accident would mean a developer connecting to MariaDB with
# a client that says MySQL on the tin.
function Get-WampEnvironmentPlan {
    param(
        [Parameter(Mandatory)][string]$Root,
        [string]$DbFlavor = 'mysql',
        [string]$ComposerDir = ''
    )

    $plan = @()

    $php = Get-WampActiveVersion $Root 'phpVersion'
    $plan += [pscustomobject]@{
        Name = 'WAMP_PHP'; Version = $php
        Path = $(if ($php) { Join-Path $Root "bin\php\php$php" } else { '' })
        For  = 'php, and the php.ini that Composer and artisan read'
    }

    # bin\ under the version directory, not the version directory itself: the database
    # servers put their client in a subdirectory and PHP does not.
    $dbKey = $(if ($DbFlavor -eq 'mariadb') { 'mariadbVersion' } else { 'mysqlVersion' })
    $db = Get-WampActiveVersion $Root $dbKey
    $plan += [pscustomobject]@{
        Name = 'WAMP_DB'; Version = $db
        Path = $(if ($db) { Join-Path $Root "bin\$DbFlavor\$DbFlavor$db\bin" } else { '' })
        For  = "mysql and mysqldump, from $DbFlavor"
    }

    $apache = Get-WampActiveVersion $Root 'apacheVersion'
    $plan += [pscustomobject]@{
        Name = 'WAMP_APACHE'; Version = $apache
        Path = $(if ($apache) { Join-Path $Root "bin\apache\apache$apache\bin" } else { '' })
        For  = 'httpd and ab, for checking a vhost by hand'
    }

    # Composer is not WampServer's and has no version to follow, so it goes on PATH as
    # itself. It is in the plan anyway because this command is where somebody looks when
    # a tool is not on PATH, and an entry that is missing from the list it prints reads
    # as an entry it does not manage.
    if ($ComposerDir) {
        $plan += [pscustomobject]@{
            Name = ''; Version = ''
            Path = $ComposerDir
            For  = 'composer'
        }
    }

    return $plan
}

# What the PATH should become, given the plan and what is on it now. Returned rather
# than written so -Report can print the difference without touching anything.
#
# The rule for removal is deliberately broad: ANY entry that resolves inside the wamp
# root goes, whoever wrote it. That is what clears the literal per-version paths this
# project itself used to add, and the ones WampServer's own installer leaves behind.
function Resolve-WampPathEntry {
    param(
        [Parameter(Mandatory)][string]$Root,
        [Parameter(Mandatory)][object[]]$Plan,
        [switch]$Direct
    )

    $managed = @()
    foreach ($row in $Plan) {
        if (-not $row.Path) { continue }
        $managed += $(if ($Direct -or -not $row.Name) { $row.Path } else { "%$($row.Name)%" })
    }

    $kept = @()
    $dropped = @()
    foreach ($entry in (Get-UserPathEntry)) {
        # Both forms of a stale entry: the literal path into the wamp tree, and an
        # indirection through a WAMP_* variable that is no longer in the plan.
        $expanded = [Environment]::ExpandEnvironmentVariables($entry)
        $isWamp = $expanded.StartsWith($Root, [StringComparison]::OrdinalIgnoreCase)
        $isMarker = $entry -match '^%WAMP_[A-Z]+%$'
        if ($isWamp -or $isMarker -or ($managed -contains $entry)) { $dropped += $entry; continue }
        $kept += $entry
    }

    return [pscustomobject]@{
        Entries = @($kept + $managed)
        Added   = @($managed | Where-Object { $dropped -notcontains $_ })
        Removed = @($dropped | Where-Object { $managed -notcontains $_ })
    }
}

# Write the plan: the variables first, then PATH.
#
# The variables go through [Environment]::SetEnvironmentVariable and PATH does not, and
# that split is on purpose. That accessor expands %VAR% on read, which is fatal for PATH
# and irrelevant for these three - they hold a literal directory. In exchange it
# broadcasts WM_SETTINGCHANGE, which is what tells Explorer to rebuild the block every
# new terminal inherits. One broadcast covers everything written in the same moment, so
# PATH gets the notification without having to survive the accessor.
function Set-WampEnvironment {
    param(
        [Parameter(Mandatory)][object[]]$Plan,
        [Parameter(Mandatory)][string[]]$PathEntries,
        [switch]$Direct
    )

    Set-UserPathEntry $PathEntries

    foreach ($row in $Plan) {
        if (-not $row.Name) { continue }
        if ($Direct) {
            # PATH already holds the literal directory, so the indirection variable would
            # be a second copy of the same fact - and the one nobody updates. Removed
            # rather than left behind, and the removal is also what broadcasts.
            [Environment]::SetEnvironmentVariable($row.Name, $null, 'User')
            continue
        }
        [Environment]::SetEnvironmentVariable($row.Name, $row.Path, 'User')
        Set-Item -Path "env:$($row.Name)" -Value $row.Path -ErrorAction SilentlyContinue
    }

    # This process keeps the block it inherited at launch, so without this the very run
    # that just fixed PATH still cannot see what it added.
    $live = @()
    foreach ($entry in $PathEntries) { $live += [Environment]::ExpandEnvironmentVariables($entry) }
    $machine = "$([Environment]::GetEnvironmentVariable('Path', 'Machine'))"
    if ($machine) { $live += $machine.Split(';') }
    $env:Path = [string]::Join(';', @($live | Where-Object { "$_".Trim() }))
}

# COMPOSER_HOME, created and declared rather than left to Composer's default.
#
# The value is the one Composer would have computed anyway, and setting it is still not
# redundant: that default is worked out per process from APPDATA, so a scheduled task, a
# service account or a shell started from a different profile quietly gets its own cache,
# its own auth.json and its own global packages. Declaring it is what makes "composer
# global require" mean the same thing in every terminal on the machine.
#
# An existing value is LEFT ALONE. Somebody who has pointed Composer at a shared cache
# has done so on purpose, and this is not the command that overrules them.
function Set-ComposerHome([string]$Path) {
    if (-not $Path) { return '' }
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Force -Path $Path | Out-Null
    }

    $existing = "$([Environment]::GetEnvironmentVariable('COMPOSER_HOME', 'User'))".Trim()
    if ($existing) {
        $env:COMPOSER_HOME = $existing
        return $existing
    }

    [Environment]::SetEnvironmentVariable('COMPOSER_HOME', $Path, 'User')
    $env:COMPOSER_HOME = $Path
    return $Path
}

# =====================[ the storage engine MySQL defaults to ]================
# WHY THIS IS GRADED AT ALL. WampServer ships my.ini with default_storage_engine=MYISAM
# and the InnoDB line commented out directly beneath it. MySQL 8's own default is
# InnoDB, so this is WampServer's choice rather than the server's, and it is the wrong
# one for anything using Laravel: MyISAM caps an index at 1000 bytes, Laravel's
# users_email_unique is a utf8mb4 varchar(255) needing 1020, and the very first
# migration dies with "Specified key was too long" - an error that names neither MyISAM
# nor the file that selected it.
#
# It also has no foreign keys and no transactions, which this schema uses throughout.
function Get-MyIniPath([string]$Root, [string]$MysqlDir) {
    if ($MysqlDir) {
        $candidate = Join-Path $MysqlDir 'my.ini'
        if (Test-Path -LiteralPath $candidate) { return $candidate }
    }
    return ''
}

function Get-DefaultStorageEngine([string]$IniPath) {
    if (-not $IniPath -or -not (Test-Path -LiteralPath $IniPath)) { return '' }
    foreach ($line in (Get-Content -LiteralPath $IniPath)) {
        if ($line -match '^\s*default[-_]storage[-_]engine\s*=\s*([A-Za-z]+)') { return $Matches[1] }
    }
    return ''
}

# Rewritten in WampServer's own idiom: the setting is a pair of lines, one live and one
# commented, and flipping which is which is what its documentation tells you to do. The
# alternative - appending a second assignment - leaves two in the file, and which one
# wins is then a question about ordering nobody should have to ask.
function Set-DefaultStorageEngine([string]$IniPath, [string]$Engine) {
    $lines = @(Get-Content -LiteralPath $IniPath)
    $changed = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^\s*default[-_]storage[-_]engine\s*=') {
            $lines[$i] = "default_storage_engine=$Engine"
            $changed = $true
            continue
        }
        # The commented twin, left commented so the file still documents the choice.
        if ($lines[$i] -match '^\s*;\s*default[-_]storage[-_]engine\s*=') {
            $lines[$i] = ";default_storage_engine=$(if ($Engine -eq 'InnoDB') { 'MYISAM' } else { 'InnoDB' })"
        }
    }
    if (-not $changed) { $lines += "default_storage_engine=$Engine" }
    [IO.File]::WriteAllLines($IniPath, $lines, [System.Text.UTF8Encoding]::new($false))
}
