# Points this machine's PATH at whichever PHP, MySQL and Apache WampServer is currently
# set to serve, so php, composer and mysql work in any terminal without a full path.
#
# RUN IT AGAIN AFTER EVERY VERSION SWITCH. WampServer holds six PHPs and two databases
# side by side and serves exactly one of each, chosen from its tray menu - and that
# choice lives in wampmanager.conf, not in the directory names. Nothing on Windows
# notices when it changes, so a PATH set up once goes quietly wrong: `php -v` keeps
# answering, from a PHP that is no longer the one running the site. That mismatch is the
# thing this command exists to make impossible to hold for long.
#
# HOW IT AVOIDS GOING STALE. PATH gets three indirections and nothing else:
#
#     PATH       ...;%WAMP_PHP%;%WAMP_DB%;%WAMP_APACHE%;<composer>
#     WAMP_PHP   C:\wamp64\bin\php\php8.4.15
#     WAMP_DB    C:\wamp64\bin\mysql\mysql8.0.46\bin
#     WAMP_APACHE C:\wamp64\bin\apache\apache2.4.65\bin
#
# So a switch rewrites three variables and PATH never changes. The obvious alternative -
# appending the new version's directory - leaves the OLD one earlier in PATH, where it
# still wins, and grows PATH by an entry per switch until something truncates it.
#
# COMPOSER_HOME is set here too. It is not a PATH entry and follows no version, but it
# is an environment variable this project depends on being the same in every terminal,
# and this is the command somebody opens when one of them behaves differently.
#
# Every stale entry inside the WampServer directory is removed, whoever put it there.
# That is deliberate and slightly rude: an entry naming one version is exactly the thing
# that survives a switch and lies afterwards.
#
# NOTHING HERE NEEDS ADMINISTRATOR. It writes the per-user environment only, which is
# also why it must not be run elevated - those variables would land in the
# administrator's profile, where this account cannot see them.
#
#     .\script.ps1 environment-variables              point PATH at what is selected now
#     .\script.ps1 environment-variables -Report      show the difference, change nothing
#     .\script.ps1 environment-variables -Direct      literal paths, no %WAMP_*% indirection
#
# Requires PowerShell 7+.

# ============================[ start: Parameters ]============================
param(
    # Print what WampServer has selected and what PATH would become, and stop.
    [switch]$Report,
    # Write the literal directories instead of %WAMP_PHP% and friends. For a machine
    # whose PATH cannot be REG_EXPAND_SZ, or a tool that reads the registry itself and
    # does not expand. The cost is the staleness this command exists to avoid, so the
    # summary says so every time.
    [switch]$Direct,
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

if ($Help) {
    Write-Host ''
    Write-Host '  environment-variables - point PATH at the versions WampServer is serving.'
    Write-Host ''
    Write-Host '  Usage'
    Write-Host '    .\script.ps1 environment-variables [-Report] [-Direct]'
    Write-Host ''
    Write-Host '  WampServer holds every version side by side and serves one of each, picked'
    Write-Host '  in its tray menu. This reads that choice from wampmanager.conf and sets:'
    Write-Host ''
    Write-Host '    WAMP_PHP      the active PHP directory      php, and its php.ini'
    Write-Host '    WAMP_DB       the active database client    mysql, mysqldump'
    Write-Host '    WAMP_APACHE   the active Apache bin         httpd, ab'
    Write-Host ''
    Write-Host '  PATH then carries %WAMP_PHP% rather than the directory, so the next'
    Write-Host '  version switch is three variables and not a new PATH entry. Run this'
    Write-Host '  again after every switch.'
    Write-Host ''
    Write-Host '  Config (ikaika_scripts.env)'
    Write-Host '    setup.wampRoot      where WampServer is'
    Write-Host '    setup.dbFlavor      mysql or mariadb - which one owns `mysql`'
    Write-Host '    setup.composerDir   where composer.phar lives'
    Write-Host '    setup.composerHome  COMPOSER_HOME - the cache, auth.json and globals'
    Write-Host ''
    Write-Host '  Per-user only, and no administrator. Do not run it elevated: the'
    Write-Host '  variables would land in the administrator profile instead of yours.'
    Write-Host ''
    exit 0
}

Start-BuildLog 'environment-variables'

$wampRoot = Find-WampRoot (Get-ProjectValue 'setup.wampRoot' 'C:\wamp64')
$dbFlavor = "$(Get-ProjectValue 'setup.dbFlavor' 'mysql')".ToLower()
$composerDir = Get-ProjectValue 'setup.composerDir' (Join-Path $env:LOCALAPPDATA 'Composer\bin')
$composerHome = Get-ProjectValue 'setup.composerHome' (Join-Path $env:APPDATA 'Composer')

Write-Host ''
Write-Host '  Environment variables' -ForegroundColor Cyan

if (-not $wampRoot) {
    Write-Host ''
    Write-Host '  WampServer is not installed, so there is nothing to point PATH at.' -ForegroundColor Yellow
    Write-Host '    .\script.ps1 development-setup      install it' -ForegroundColor DarkGray
    Write-Host ''
    exit 1
}
Write-Host "  $wampRoot" -ForegroundColor DarkGray

$plan = @(Get-WampEnvironmentPlan -Root $wampRoot -DbFlavor $dbFlavor -ComposerDir $composerDir)

Write-Host ''
Write-Host '  Selected in WampServer' -ForegroundColor Cyan
foreach ($row in $plan) {
    $label = $(if ($row.Name) { $row.Name } else { '(composer)' })
    if (-not $row.Path) {
        Write-Host ('    {0,-13} {1}' -f $label, 'nothing selected - skipped') -ForegroundColor Yellow
        continue
    }
    # A directory named in wampmanager.conf that is not on disk is WampServer's own
    # inconsistency, and it is worth seeing rather than silently dropping: it is what a
    # half-finished version switch looks like.
    $exists = Test-Path -LiteralPath $row.Path
    Write-Host ('    {0,-13} {1}' -f $label, $row.Path) -ForegroundColor $(if ($exists) { 'Gray' } else { 'Yellow' })
    Write-Host ('    {0,-13} {1}' -f '', "$(if ($exists) { '' } else { 'MISSING on disk - ' })$($row.For)") -ForegroundColor DarkGray
}

$usable = @($plan | Where-Object { $_.Path -and (Test-Path -LiteralPath $_.Path) })
if (-not $usable.Count) {
    Write-Host ''
    Write-Host '  None of those directories exist, so PATH is left alone.' -ForegroundColor Red
    Write-Host ''
    exit 1
}

$resolved = Resolve-WampPathEntry -Root $wampRoot -Plan $usable -Direct:$Direct

Write-Host ''
Write-Host '  PATH' -ForegroundColor Cyan
if (-not $resolved.Added.Count -and -not $resolved.Removed.Count) {
    Write-Host '    already exactly this - nothing to change' -ForegroundColor DarkGray
} else {
    foreach ($entry in $resolved.Removed) { Write-Host "    - $entry" -ForegroundColor DarkYellow }
    foreach ($entry in $resolved.Added) { Write-Host "    + $entry" -ForegroundColor Green }
}

# Not a PATH entry, so it is reported on its own. Composer computes this per process
# from APPDATA when it is unset, which means a scheduled task or a differently-started
# shell quietly gets its own cache and its own global packages.
$homeNow = "$([Environment]::GetEnvironmentVariable('COMPOSER_HOME', 'User'))".Trim()
Write-Host ''
Write-Host '  COMPOSER_HOME' -ForegroundColor Cyan
if ($homeNow) {
    Write-Host "    already set: $homeNow" -ForegroundColor DarkGray
} else {
    Write-Host "    + $composerHome" -ForegroundColor Green
}

if ($Report) {
    Write-Host ''
    Write-Host '  -Report: nothing was written.' -ForegroundColor DarkGray
    Write-Host ''
    exit 0
}

Set-WampEnvironment -Plan $usable -PathEntries $resolved.Entries -Direct:$Direct
[void](Set-ComposerHome $composerHome)

# Proved rather than announced. Every one of these runs out of the PATH this command
# just built, in this process, so a green line here is the thing itself working and not
# a claim that it should.
Write-Host ''
Write-Host '  In this terminal now' -ForegroundColor Cyan
$failures = 0
foreach ($check in @(
        @{ Name = 'php'; Args = @('-v') }
        @{ Name = 'composer'; Args = @('--version', '--no-interaction') }
        @{ Name = 'mysql'; Args = @('--version') })) {

    $found = Get-Command $check.Name -ErrorAction SilentlyContinue
    if (-not $found) {
        Write-Host ('    {0,-10} not on PATH' -f $check.Name) -ForegroundColor Yellow
        $failures++
        continue
    }
    $answer = ''
    try { $answer = "$(@(& $found.Source @($check.Args) 2>$null)[0])".Trim() } catch { $answer = '' }
    if ($answer) {
        Write-Host ('    {0,-10} {1}' -f $check.Name, $answer) -ForegroundColor Green
    } else {
        Write-Host ('    {0,-10} found at {1}, but would not answer' -f $check.Name, $found.Source) -ForegroundColor Yellow
        $failures++
    }
}

Write-Host ''
if ($Direct) {
    Write-Host '  -Direct wrote literal paths, so this goes stale on the next version switch.' -ForegroundColor Yellow
    Write-Host '  Run this command again after switching, or drop -Direct to use %WAMP_PHP%.' -ForegroundColor DarkGray
} else {
    Write-Host '  PATH carries %WAMP_PHP%, %WAMP_DB% and %WAMP_APACHE%, so a version switch in' -ForegroundColor DarkGray
    Write-Host '  the tray menu is three variables - run this again and nothing else changes.' -ForegroundColor DarkGray
}
Write-Host '  Terminals already open keep the block they started with. New ones are fine.' -ForegroundColor DarkGray
Write-Host ''
exit $failures
