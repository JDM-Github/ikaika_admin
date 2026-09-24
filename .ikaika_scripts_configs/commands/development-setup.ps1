# Prepares a freshly installed Windows machine to develop the Ikaika portal API:
# WampServer with PHP 8.4 and MySQL 8.0, Composer, the PHP extensions Laravel needs,
# and this repository's own dependencies.
#
# WRITTEN FOR A MACHINE WITH NOTHING ON IT. Every step grades first, names what it
# found, and installs only what is missing - so running it twice is a report the second
# time. Nothing is silent about failing: a step that cannot finish says so, the summary
# at the end is the truth, and the exit code is the number of things still broken. The
# one outcome worth avoiding is believing a tool is there when it is not.
#
# THE TWO UNCERTAIN STEPS are WampServer's own installer and the aviatechno add-ons.
# All three are Inno Setup packages driven with /VERYSILENT /SUPPRESSMSGBOXES, and
# WampServer's has historically asked about a default browser and a default text editor
# from its own code rather than from a wizard page. Those switches answer both with the
# default. If a run stops with no window in sight, that is where to look: run that one
# installer by hand once, and this script finds it and carries on from there.
#
# Why WampServer at all, when winget has PHP.PHP.8.4 and Oracle.MySQL: this is the stack
# the portal is deployed on and the one everyone here already runs. A second, separately
# configured PHP on the same machine buys nothing and costs a class of "works on mine".
# The version pair is stated in ikaika_scripts.env rather than here, so moving to a
# newer PHP is a line in that file and not an edit to this one.
#
# ELEVATION IS ASKED FOR ONCE, and only for the machine-wide half. Composer, the PATH
# entries and everything under this repository stay with the ordinary user, because an
# Administrator-owned vendor/ or a Composer under the admin account's profile is a
# problem that surfaces days later as a permission error nobody connects to setup.
#
# This project's own command, so it wins over anything in the toolkit with the same
# name. The toolkit's setup-dev-env is Flutter's - it grades an Android SDK and offers
# to install one - and there is no reason to run it here.
#
#     .\script.ps1 development-setup              install what is missing, then restore
#     .\script.ps1 development-setup -Report      say what is missing, install nothing
#     .\script.ps1 development-setup -ToolsOnly   the machine only, not the repository
#     .\script.ps1 development-setup -Force       restore dependencies even if unchanged
#
# Requires PowerShell 7+ and winget.

# ============================[ start: Parameters ]============================
param(
    # Grade everything and stop. Nothing is downloaded, installed or written.
    [switch]$Report,
    # Run composer install even when composer.lock has not changed since the last run.
    [switch]$Force,
    # Stop after the machine is ready, without touching this repository.
    [switch]$ToolsOnly,
    # The machine-wide half alone - VC++ runtimes, WampServer, its add-ons, php.ini -
    # and then stop. Composer, PATH and the repository are NOT done, because they must
    # run as the ordinary user.
    #
    # NAMED FOR WHAT IT DOES, not for who runs it. This was -Elevated, which is what the
    # elevated child is, and on a form that reads as "run this with administrator rights"
    # - so ticking it was the obvious thing to do and it silently skipped Composer and
    # the repository. A switch on a form is a promise about the outcome, and the outcome
    # here is "the machine only".
    [switch]$MachineOnly,
    [switch]$Help
)
# =============================[ end: Parameters ]==============================

$ErrorActionPreference = 'Stop'
# Invoke-WebRequest spends more time drawing its progress bar than downloading on a
# fast link, and in a redirected host it draws nothing and still pays for it.
$ProgressPreference = 'SilentlyContinue'

# This file's location is fixed by the toolkit - <repo>/.ikaika_scripts_configs/commands
# - so walking up two levels is exact here.
$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent
$toolkit = Join-Path $repoRoot 'decoupled-script'
$libDir = Join-Path $toolkit 'lib'

. (Join-Path $libDir 'logging.ps1')
. (Join-Path $libDir 'load-env.ps1')
# Everything about WampServer - where it is, which version it is serving, and how PATH
# follows that - is in one file, shared with environment-variables. Two copies of "which
# PHP is active" would be two answers to the question this whole command turns on.
. (Join-Path $configsDir 'lib\wamp.ps1')
. (Join-Path $configsDir 'lib\dev-dependencies.ps1')

if ($Help) {
    Write-Host ''
    Write-Host '  development-setup - prepare this machine, then restore this repository.'
    Write-Host ''
    Write-Host '  Usage'
    Write-Host '    .\script.ps1 development-setup [-Report] [-ToolsOnly] [-Force]'
    Write-Host '    .\script.ps1 development-setup -MachineOnly    the machine, then stop'
    Write-Host ''
    Write-Host '  Installs, in order: the VC++ runtimes WampServer depends on, WampServer'
    Write-Host '  itself, the PHP and MySQL add-ons when the bundled versions are not the'
    Write-Host '  ones this project runs, the PHP extensions Laravel needs, Composer, and'
    Write-Host '  then this repository - composer install, a .env with an application key,'
    Write-Host '  and the databases named in it.'
    Write-Host ''
    Write-Host '  -Report changes nothing at all, which is what makes it safe anywhere.'
    Write-Host ''
    Write-Host '  Config (ikaika_scripts.env)'
    Write-Host '    setup.wampRoot      where WampServer is, or is installed to'
    Write-Host '    setup.phpVersion    the PHP series this project runs (8.4)'
    Write-Host '    setup.mysqlVersion  the MySQL series this project runs (8.0)'
    Write-Host '    setup.phpAddon      the add-on to fetch when that PHP is absent'
    Write-Host '    setup.mysqlAddon    the add-on to fetch when that MySQL is absent'
    Write-Host '    setup.composerDir   where composer.phar goes (per user, no admin)'
    Write-Host ''
    Write-Host '  Admin rights are requested once, for the machine-wide installers only.'
    Write-Host '  Composer, PATH and this repository stay with the ordinary user.'
    Write-Host ''
    exit 0
}

Start-BuildLog 'development-setup'

# =========================[ start: Project requirements ]=====================
# DECLARED IN dev.json, not here. What this project needs installed is a fact about the
# project rather than about the command that installs it, so it lives in a file a person
# can read, CI can parse without a PowerShell host, and review shows as a one-line diff
# when a floor moves. This command is what ACTS on that list.
#
# What stays in ikaika_scripts.env is the wiring rather than the requirement: where
# WampServer is, which add-on URL to fetch, where composer.phar goes. Those are answers
# to "how", and dev.json answers "what and which version".

$devJsonPath = Join-Path $repoRoot 'dev.json'
$declared = @(Get-DevDependency -Path $devJsonPath)
$phpExtensionsDeclared = @((Get-Content -LiteralPath $devJsonPath -Raw | ConvertFrom-Json).phpExtensions.required)

function Get-Declared([string]$Key) {
    return ($declared | Where-Object { $_.Key -eq $Key } | Select-Object -First 1)
}

$wampRoot = Get-ProjectValue 'setup.wampRoot' 'C:\wamp64'
$phpSeries = "$((Get-Declared 'php').Series)"
$mysqlSeries = "$((Get-Declared 'mysql').Series)"
$addonBase = Get-ProjectValue 'setup.addonBase' 'https://wampserver.aviatechno.net/files'
$phpAddon = Get-ProjectValue 'setup.phpAddon' ''
$mysqlAddon = Get-ProjectValue 'setup.mysqlAddon' ''
$composerDir = Get-ProjectValue 'setup.composerDir' (Join-Path $env:LOCALAPPDATA 'Composerin')
$dbFlavor = "$(Get-ProjectValue 'setup.dbFlavor' 'mysql')".ToLower()
$composerHome = Get-ProjectValue 'setup.composerHome' (Join-Path $env:APPDATA 'Composer')
$stampPath = Join-Path $repoRoot '.ikaika-install'

# WampServer will not run without these, and the failure it gives when one is absent is
# an orange tray icon and a dialog naming a DLL - which is why the whole set is installed
# outright rather than graded. dev.json names the one that is PROBED (2015+); the rest are
# its historical companions, listed here because they are an implementation detail of
# installing that one and not a requirement anybody reads.
$VcRedistIds = @(
    'Microsoft.VCRedist.2005.x86', 'Microsoft.VCRedist.2005.x64'
    'Microsoft.VCRedist.2008.x86', 'Microsoft.VCRedist.2008.x64'
    'Microsoft.VCRedist.2010.x86', 'Microsoft.VCRedist.2010.x64'
    'Microsoft.VCRedist.2012.x86', 'Microsoft.VCRedist.2012.x64'
    'Microsoft.VCRedist.2013.x86', 'Microsoft.VCRedist.2013.x64'
    'Microsoft.VCRedist.2015+.x86', 'Microsoft.VCRedist.2015+.x64'
)

$RequiredExtensions = @($phpExtensionsDeclared)
# ==========================[ end: Project requirements ]======================

# ---------------------------------------------------------------- the report
# One row per requirement, filled in as the run goes, printed once at the start and
# again at the end. Two printings rather than one because the first is what -Report
# exists for, and the second is the only honest answer to "did that work".

$script:Rows = [ordered]@{}

function Set-Row {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][ValidateSet('OK', 'MISSING', 'FIXED', 'FAILED', 'SKIP', 'WARN')][string]$State,
        [string]$Detail = ''
    )
    $script:Rows[$Name] = [pscustomobject]@{ State = $State; Detail = $Detail }
}

function Write-Report([string]$Heading) {
    $colour = @{ OK = 'Green'; FIXED = 'Green'; MISSING = 'Yellow'; WARN = 'Yellow'; SKIP = 'DarkGray'; FAILED = 'Red' }
    Write-Host ''
    Write-Host "  $Heading" -ForegroundColor Cyan
    foreach ($name in $script:Rows.Keys) {
        $row = $script:Rows[$name]
        Write-Host ('    {0,-9}' -f $row.State) -ForegroundColor $colour[$row.State] -NoNewline
        Write-Host ('{0,-22} {1}' -f $name, $row.Detail)
    }
}

function Get-FailedCount {
    return @($script:Rows.Values | Where-Object { $_.State -eq 'FAILED' }).Count
}

# ------------------------------------------------------------------ machine
function Test-Elevated {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]::new($identity)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Update-SessionPath {
    # An installer writes the machine PATH, but this process keeps the copy it inherited
    # at launch, so a tool installed a moment ago stays invisible until the shell
    # restarts. Appended, never replaced: overwriting would strip a caller's own entries.
    $seen = [System.Collections.Generic.List[string]]::new()
    foreach ($source in @($env:Path,
            [Environment]::GetEnvironmentVariable('Path', 'Machine'),
            [Environment]::GetEnvironmentVariable('Path', 'User'))) {
        if ([string]::IsNullOrWhiteSpace($source)) { continue }
        foreach ($entry in $source.Split(';')) {
            $dir = $entry.Trim()
            if ($dir -and -not $seen.Contains($dir)) { $seen.Add($dir) }
        }
    }
    $env:Path = [string]::Join(';', $seen)
}

function Get-ExternalVersion([string]$Command, [string[]]$CommandArgs) {
    $found = Get-Command $Command -ErrorAction SilentlyContinue
    if (-not $found) { return $null }
    try { $raw = & $found.Source @CommandArgs 2>$null } catch { return $null }
    $text = ($raw | Out-String).Trim()
    if (-not ($text -match '(\d+(?:\.\d+){1,3})')) { return $null }
    return [version]$Matches[1]
}

function Get-WingetPath {
    $found = Get-Command winget.exe -ErrorAction SilentlyContinue
    return $(if ($found) { $found.Source } else { '' })
}

function Install-WingetPackage([string]$Id, [string]$Override = '') {
    $winget = Get-WingetPath
    if (-not $winget) { throw 'winget was not found.' }

    $wingetArgs = @('install', '--exact', '--id', $Id, '--silent', '--disable-interactivity',
        '--accept-source-agreements', '--accept-package-agreements')
    # --override REPLACES winget's own installer switches rather than adding to them,
    # which is what this needs: WampServer's Inno script asks two questions from code,
    # and only /SUPPRESSMSGBOXES answers those. --custom would have appended a second,
    # contradictory /SILENT.
    if ($Override) { $wingetArgs += @('--override', $Override) }

    & $winget @wingetArgs
    # winget turns an install of an already-present package into an upgrade and then
    # reports "no applicable upgrade found" as a FAILURE exit code, so the exit code
    # here cannot tell a real failure from a healthy no-op. Every caller re-probes.
    Update-SessionPath
}

# The add-ons are 150 MB each, and a failed install leaves one behind on purpose - so a
# retry asks the server how big the file is and skips the transfer when what is already
# on disk is exactly that size. A length check and not a checksum, because none is
# published; it catches the case that matters, which is a download cut off half way.
function Save-Download([string]$Url, [string]$Destination) {
    if (Test-Path -LiteralPath $Destination) {
        $localLength = (Get-Item -LiteralPath $Destination).Length
        $remoteLength = -1
        try {
            $head = Invoke-WebRequest -Uri $Url -Method Head -UseBasicParsing
            $remoteLength = [int64]"$($head.Headers['Content-Length'])"
        } catch { $remoteLength = -1 }
        if ($remoteLength -gt 0 -and $remoteLength -eq $localLength) {
            Write-Host "    reusing $(Split-Path $Destination -Leaf) ($([Math]::Round($localLength / 1MB)) MB already here)"
            return
        }
        Remove-Item -LiteralPath $Destination -Force -ErrorAction SilentlyContinue
    }

    Write-Host "    downloading $(Split-Path $Url -Leaf)"
    Invoke-WebRequest -Uri $Url -OutFile $Destination -UseBasicParsing
    if (-not (Test-Path -LiteralPath $Destination)) { throw "Download produced no file: $Url" }
}

# Inno Setup's own exit codes. Named here because "exited with 7" is not a diagnosis:
# 7 in particular is a DELIBERATE refusal by the installer's own pre-install stage, and
# without this table it reads like a crash.
$InnoExitMeaning = @{
    1 = 'Setup failed to initialize.'
    2 = 'Cancelled before the install started.'
    3 = 'A fatal error while preparing the next phase.'
    4 = 'A fatal error during the install itself.'
    5 = 'Cancelled during the install.'
    6 = 'Terminated by a debugger.'
    7 = 'The installer refused at its own pre-install check - usually a file still in use.'
    8 = 'The pre-install check refused, and wants a restart first.'
}

# The suppressed message boxes, which is where a refusal explains itself.
function Read-InnoRefusal([string]$LogPath) {
    if (-not (Test-Path -LiteralPath $LogPath)) { return '' }
    $lines = @(Get-Content -LiteralPath $LogPath -ErrorAction SilentlyContinue)
    $said = @()
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -notmatch '^\s*Message box') { continue }
        foreach ($offset in 1..3) {
            if (($i + $offset) -ge $lines.Count) { break }
            $text = "$($lines[$i + $offset])".Trim()
            if ($text -and $text -notmatch '^The message box was suppressed') { $said += $text }
        }
    }
    $joined = (@($said | Select-Object -Unique) -join ' ')
    if ($joined.Length -gt 400) { $joined = $joined.Substring(0, 400) + '...' }
    return $joined
}

# /SP- skips the "This will install..." page, /NORESTART keeps an installer from
# rebooting the machine out from under a setup run, /SUPPRESSMSGBOXES answers the
# questions WampServer's own script asks from code, and /FORCECLOSEAPPLICATIONS tells
# Inno to shut whatever still holds a file rather than refusing over it.
#
# /LOG IS NOT OPTIONAL HERE, and it is the fix for the exact bug this was rewritten for:
# /SUPPRESSMSGBOXES also suppresses the message that EXPLAINS a refusal, so a failing
# installer returns a bare number and nothing else. Inno writes those suppressed message
# boxes to its log verbatim, which is the only place the reason survives.
function Install-InnoSilently([string]$Installer, [string[]]$Extra = @()) {
    $logPath = Join-Path ([IO.Path]::GetTempPath()) "inno-$([guid]::NewGuid().ToString('n')).log"
    $innoArgs = @('/VERYSILENT', '/SUPPRESSMSGBOXES', '/NORESTART', '/SP-',
        '/FORCECLOSEAPPLICATIONS', "/LOG=$logPath") + $Extra

    $process = Start-Process -FilePath $Installer -ArgumentList $innoArgs -Wait -PassThru
    if ($process.ExitCode -eq 0) {
        Remove-Item -LiteralPath $logPath -Force -ErrorAction SilentlyContinue
        return
    }

    $code = $process.ExitCode
    $why = $(if ($InnoExitMeaning.ContainsKey($code)) { $InnoExitMeaning[$code] } else { 'Unknown exit code.' })
    $said = Read-InnoRefusal $logPath
    Remove-Item -LiteralPath $logPath -Force -ErrorAction SilentlyContinue
    throw "exit $code - $why$(if ($said) { " It said: $said" })"
}

# ------------------------------------------------------------------- wamp
# Find-WampRoot, Get-WampActiveVersion, Get-WampComponentDir, Stop-WampServer and the
# php.ini helpers all live in .ikaika_scripts_configs/lib/wamp.ps1, dot-sourced above.

# --------------------------------------------------------------- composer
# The phar route rather than Composer-Setup.exe: it needs no administrator, it puts the
# binary where this script says, and its integrity is checked against the signature
# Composer publishes for whatever the current installer is. A checksum copied into this
# file would have to be edited every time Composer releases, and the day it is not, this
# step either breaks or - much worse - gets commented out.
function Install-Composer([string]$PhpExe, [string]$TargetDir) {
    if (-not (Test-Path -LiteralPath $TargetDir)) {
        New-Item -ItemType Directory -Force -Path $TargetDir | Out-Null
    }
    $work = Join-Path ([IO.Path]::GetTempPath()) "composer-setup-$([guid]::NewGuid().ToString('n'))"
    New-Item -ItemType Directory -Force -Path $work | Out-Null
    try {
        $setup = Join-Path $work 'composer-setup.php'
        Save-Download 'https://getcomposer.org/installer' $setup

        $expected = "$(Invoke-RestMethod -Uri 'https://composer.github.io/installer.sig')".Trim()
        $actual = (Get-FileHash -Algorithm SHA384 -LiteralPath $setup).Hash
        if ($actual -ne $expected.Replace(' ', '')) {
            throw "The Composer installer did not match its published signature. Expected $expected, got $actual."
        }

        & $PhpExe $setup "--install-dir=$TargetDir" '--filename=composer.phar'
        if ($LASTEXITCODE -ne 0) { throw "The Composer installer exited with $LASTEXITCODE." }
    } finally {
        Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
    }

    # The shim calls php off PATH rather than the absolute php.exe this run happened to
    # find. An add-on installed next month moves that path, and a composer.bat pinned to
    # the old one keeps working against a PHP nobody else is using any more.
    $shim = @'
@echo off
php "%~dp0composer.phar" %*
'@
    [IO.File]::WriteAllText((Join-Path $TargetDir 'composer.bat'), $shim, [System.Text.UTF8Encoding]::new($false))
}

# ------------------------------------------------------------------ grading
function Test-VcRedistReady {
    # Graded on the two that actually matter and are cheap to see: 2012 and 2015+ are
    # what Apache and PHP link against, and the older ones are installed alongside them
    # anyway. A full twelve-package probe would take longer than installing them.
    foreach ($file in @('msvcr110.dll', 'vcruntime140.dll')) {
        if (-not (Test-Path -LiteralPath (Join-Path $env:SystemRoot "System32\$file"))) { return $false }
    }
    return $true
}

function Read-Grades {
    $script:Rows = [ordered]@{}

    $pwshVersion = Get-ExternalVersion 'pwsh' @('-NoProfile', '-NoLogo', '-Command', '$PSVersionTable.PSVersion.ToString()')
    Set-Row 'PowerShell 7' $(if ($pwshVersion -and $pwshVersion.Major -ge 7) { 'OK' } else { 'MISSING' }) `
        $(if ($pwshVersion) { "$pwshVersion" } else { 'not installed' })

    $winget = Get-WingetPath
    Set-Row 'winget' $(if ($winget) { 'OK' } else { 'FAILED' }) `
        $(if ($winget) { $winget } else { 'install "App Installer" from the Microsoft Store, then run this again' })

    $gitVersion = Get-ExternalVersion 'git' @('--version')
    Set-Row 'Git' $(if ($gitVersion) { 'OK' } else { 'MISSING' }) `
        $(if ($gitVersion) { "$gitVersion" } else { 'not installed' })

    $vcReady = Test-VcRedistReady
    Set-Row 'VC++ runtimes' $(if ($vcReady) { 'OK' } else { 'MISSING' }) `
        $(if ($vcReady) { '2012 and 2015+ present' } else { 'WampServer will not start without them' })

    $root = Find-WampRoot $wampRoot
    Set-Row 'WampServer' $(if ($root) { 'OK' } else { 'MISSING' }) $(if ($root) { $root } else { "will install to $wampRoot" })

    $phpDir = Get-WampComponentDir $root 'php' $phpSeries
    Set-Row "PHP $phpSeries" $(if ($phpDir) { 'OK' } else { 'MISSING' }) `
        $(if ($phpDir) { "$(Get-WampComponentVersion $phpDir 'php')  $phpDir" } else { 'not in WampServer' })

    $mysqlDir = Get-WampComponentDir $root 'mysql' $mysqlSeries
    Set-Row "MySQL $mysqlSeries" $(if ($mysqlDir) { 'OK' } else { 'MISSING' }) `
        $(if ($mysqlDir) { "$(Get-WampComponentVersion $mysqlDir 'mysql')  $mysqlDir" } else { 'not in WampServer' })

    # A separate row from the one above, because they answer different questions and
    # only one of them is about a file existing. Installing the add-on puts 8.0 on disk;
    # making it the one WampServer serves is a tray-menu switch that rewrites my.ini and
    # the service definition, so it is reported rather than forged here.
    $activeMysql = Get-WampActiveVersion $root 'mysqlVersion'
    if (-not $root) {
        Set-Row 'MySQL active' 'SKIP' 'nothing to read until WampServer is installed'
    } elseif ($activeMysql -and $activeMysql.StartsWith("$mysqlSeries.")) {
        Set-Row 'MySQL active' 'OK' $activeMysql
    } else {
        Set-Row 'MySQL active' 'WARN' "$activeMysql - switch it in the WampServer tray menu: MySQL > Version > $mysqlSeries.x"
    }

    if ($phpDir) {
        $disabled = @()
        foreach ($ini in (Get-PhpIniPaths $root $phpDir)) {
            $disabled += (Get-DisabledExtensions $ini $RequiredExtensions)
        }
        $disabled = @($disabled | Select-Object -Unique | Sort-Object)
        Set-Row 'PHP extensions' $(if ($disabled.Count) { 'MISSING' } else { 'OK' }) `
            $(if ($disabled.Count) { "off: $($disabled -join ', ')" } else { "$($RequiredExtensions.Count) on" })
    } else {
        Set-Row 'PHP extensions' 'SKIP' 'nothing to grade until PHP is installed'
    }

    # WampServer picks MyISAM in my.ini; Laravel's first migration needs InnoDB. Graded
    # from the file rather than from the running server, because the file is what
    # survives a restart and what this command can fix.
    $activeMysqlDir = $(if ($activeMysql) { Join-Path $root "bin\mysql\mysql$activeMysql" } else { '' })
    $myIni = Get-MyIniPath $root $activeMysqlDir
    $engine = Get-DefaultStorageEngine $myIni
    if (-not $myIni) {
        Set-Row 'MySQL engine' 'SKIP' 'no my.ini to read until MySQL is installed'
    } elseif ($engine -and $engine.ToLower() -eq 'innodb') {
        Set-Row 'MySQL engine' 'OK' "InnoDB  $myIni"
    } else {
        Set-Row 'MySQL engine' 'MISSING' "$engine - Laravel's first migration fails on MyISAM (key longer than 1000 bytes)"
    }

    $composerVersion = Get-ExternalVersion 'composer' @('--version', '--no-interaction')
    Set-Row 'Composer' $(if ($composerVersion) { 'OK' } else { 'MISSING' }) `
        $(if ($composerVersion) { "$composerVersion" } else { "will install to $composerDir" })

    # Reported, never installed. Vite is a devDependency of this repository and nothing
    # the API itself serves needs it, so a machine without Node is a machine that cannot
    # build the assets - not a broken one. Promote this to a row that installs the day
    # the asset build joins check-all.
    $nodeVersion = Get-ExternalVersion 'node' @('-v')
    Set-Row 'Node.js' $(if ($nodeVersion) { 'OK' } else { 'WARN' }) `
        $(if ($nodeVersion) { "$nodeVersion" } else { 'optional - winget install OpenJS.NodeJS.LTS for the Vite build' })

    return [pscustomobject]@{
        WampRoot = $root
        PhpDir   = $phpDir
        MysqlDir = $mysqlDir
    }
}

function Test-Missing([string]$Name) {
    return ($script:Rows.Contains($Name) -and $script:Rows[$Name].State -eq 'MISSING')
}

# =============================[ start: The run ]==============================

Write-Host ''
Write-Host "  Development setup for $(Get-ProjectValue 'app.displayName' 'this project')" -ForegroundColor Cyan
Write-Host "  $repoRoot" -ForegroundColor DarkGray

$state = Read-Grades
Write-Report 'Before'

if ($Report) {
    $outstanding = @($script:Rows.Values | Where-Object { $_.State -in @('MISSING', 'FAILED') }).Count
    Write-Host ''
    if ($outstanding) {
        Write-Host "  $outstanding thing(s) to fix. Run .\script.ps1 development-setup to install them." -ForegroundColor Yellow
    } else {
        Write-Host '  Nothing missing.' -ForegroundColor Green
    }
    Write-Host ''
    exit 0
}

if (-not (Get-WingetPath)) {
    Write-Host ''
    Write-Host '  winget is not on PATH, and every machine-wide install here goes through it.' -ForegroundColor Red
    Write-Host '  Install "App Installer" from the Microsoft Store, open a new terminal, and' -ForegroundColor Yellow
    Write-Host '  run this again.' -ForegroundColor Yellow
    Write-Host ''
    exit 1
}

# ---------------------------------------------------- the machine-wide half
$machineWork = @(@('PowerShell 7', 'Git', 'VC++ runtimes', 'WampServer', "PHP $phpSeries",
        "MySQL $mysqlSeries", 'PHP extensions') | Where-Object { Test-Missing $_ })

if ($machineWork.Count -and -not $MachineOnly -and -not (Test-Elevated)) {
    Write-Host ''
    Write-Host '  Requesting administrator rights once, for:' -ForegroundColor Cyan
    foreach ($item in $machineWork) { Write-Host "    $item" -ForegroundColor DarkGray }

    # powershell.exe, not pwsh: this half may be what INSTALLS PowerShell 7, and a
    # machine that has none cannot start the child that would fix that.
    $childArgs = @('-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', ('"{0}"' -f $PSCommandPath), '-MachineOnly')

    # NOT a reason to stop. One refused add-on used to abort the whole run, so a machine
    # with a working PHP got no Composer and no vendor/ over a MySQL version that
    # nothing later in this script depends on. The child's failures come back through
    # the re-grade below and end up in the final table, where they belong.
    try {
        $child = Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList $childArgs -Wait -PassThru
        if ($child.ExitCode -ne 0) {
            Write-Host ''
            Write-Host "  The elevated half finished with $($child.ExitCode) thing(s) unfixed - see the table" -ForegroundColor Yellow
            Write-Host "  at the end. Its own transcript is in $(Get-LogsDir)" -ForegroundColor DarkGray
        }
    } catch {
        # Declining the UAC prompt lands here, and it is an ordinary answer rather than
        # an error: everything that does not need administrator still runs.
        Write-Host ''
        Write-Host "  Administrator rights were not granted, so the machine-wide half was skipped." -ForegroundColor Yellow
        Write-Host "  Re-run this, or run it from an admin terminal with -MachineOnly." -ForegroundColor DarkGray
    }
    Update-SessionPath
    $state = Read-Grades
    $machineWork = @()
}

if ($machineWork.Count) {
    Write-Host ''
    Write-Host '  Machine' -ForegroundColor Cyan

    if (Test-Missing 'PowerShell 7') {
        Write-Host '    PowerShell 7'
        try {
            Install-WingetPackage 'Microsoft.PowerShell'
            $found = Get-ExternalVersion 'pwsh' @('-NoProfile', '-NoLogo', '-Command', '$PSVersionTable.PSVersion.ToString()')
            Set-Row 'PowerShell 7' $(if ($found) { 'FIXED' } else { 'FAILED' }) "$found"
        } catch { Set-Row 'PowerShell 7' 'FAILED' $_.Exception.Message }
    }

    if (Test-Missing 'Git') {
        Write-Host '    Git'
        try {
            Install-WingetPackage 'Git.Git'
            $found = Get-ExternalVersion 'git' @('--version')
            Set-Row 'Git' $(if ($found) { 'FIXED' } else { 'FAILED' }) "$found"
        } catch { Set-Row 'Git' 'FAILED' $_.Exception.Message }
    }

    if (Test-Missing 'VC++ runtimes') {
        Write-Host '    VC++ runtimes'
        $failed = @()
        foreach ($id in $VcRedistIds) {
            try { Install-WingetPackage $id } catch { $failed += $id }
        }
        # Graded again rather than trusted: several of these report a failure code for an
        # already-present package, and one genuinely unavailable architecture is not a
        # reason to call the set broken.
        if (Test-VcRedistReady) {
            Set-Row 'VC++ runtimes' 'FIXED' $(if ($failed.Count) { "$($failed.Count) package(s) skipped, runtimes present" } else { 'all present' })
        } else {
            Set-Row 'VC++ runtimes' 'FAILED' "still missing after winget: $($failed -join ', ')"
        }
    }

    if (Test-Missing 'WampServer') {
        Write-Host "    WampServer -> $wampRoot"
        try {
            Install-WingetPackage 'Wampserver.Wampserver' "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /SP- /DIR=""$wampRoot"""
            $state.WampRoot = Find-WampRoot $wampRoot
            if ($state.WampRoot) {
                Set-Row 'WampServer' 'FIXED' $state.WampRoot
            } else {
                Set-Row 'WampServer' 'FAILED' 'the installer ran but no wampmanager.exe was found afterwards'
            }
        } catch { Set-Row 'WampServer' 'FAILED' $_.Exception.Message }
    }

    # The add-ons are only fetched when the base install did not already carry the
    # version this project runs. WampServer 3.4.0 bundles PHP 8.4, so the PHP add-on is
    # normally a no-op; its MySQL is 8.4, so the MySQL one normally is not.
    foreach ($component in @(
            @{ Row = "PHP $phpSeries"; Kind = 'php'; Series = $phpSeries; Addon = $phpAddon }
            @{ Row = "MySQL $mysqlSeries"; Kind = 'mysql'; Series = $mysqlSeries; Addon = $mysqlAddon })) {

        if (-not (Test-Missing $component.Row)) { continue }
        if (-not $state.WampRoot) { Set-Row $component.Row 'SKIP' 'no WampServer to add it to'; continue }
        if (-not $component.Addon) {
            Set-Row $component.Row 'FAILED' "no add-on named in ikaika_scripts.env (setup.$($component.Kind)Addon)"
            continue
        }

        Write-Host "    $($component.Row) add-on"
        [void](Stop-WampServer)
        $installer = Join-Path ([IO.Path]::GetTempPath()) (Split-Path $component.Addon -Leaf)
        try {
            Save-Download "$addonBase/$($component.Addon)" $installer
            Install-InnoSilently $installer
            $found = Get-WampComponentDir $state.WampRoot $component.Kind $component.Series
            if ($found) {
                Set-Row $component.Row 'FIXED' "$(Get-WampComponentVersion $found $component.Kind)  $found"
                if ($component.Kind -eq 'php') { $state.PhpDir = $found } else { $state.MysqlDir = $found }
                Remove-Item -LiteralPath $installer -Force -ErrorAction SilentlyContinue
            } else {
                Set-Row $component.Row 'FAILED' 'the add-on ran but the version directory is not there'
            }
        } catch {
            # The installer is KEPT on failure, deliberately, and its path is in the row.
            # The next thing anyone does after a refusal is run it by hand to see the
            # message /SUPPRESSMSGBOXES hid, so it stays where it can be double-clicked.
            Set-Row $component.Row 'FAILED' "$($_.Exception.Message)  [kept: $installer]"
        }
    }

    if ($state.PhpDir -and (Test-Missing 'PHP extensions')) {
        Write-Host '    PHP extensions'
        $enabled = @()
        $failed = @()
        foreach ($ini in (Get-PhpIniPaths $state.WampRoot $state.PhpDir)) {
            $disabled = @(Get-DisabledExtensions $ini $RequiredExtensions)
            if (-not $disabled.Count) { continue }
            try { $enabled += (Enable-PhpExtension $ini $disabled) } catch { $failed += $ini }
        }
        if ($failed.Count) {
            Set-Row 'PHP extensions' 'FAILED' "could not write $($failed -join ', ')"
        } else {
            Set-Row 'PHP extensions' 'FIXED' "switched on: $(@($enabled | Select-Object -Unique | Sort-Object) -join ', ')"
        }
    }
}

if ($MachineOnly) {
    # Labelled, because the parent prints its own table straight after this one and two
    # identical headings read as the same table printed twice.
    Write-Report 'Machine only'
    Write-Host ''
    Write-Host '  Composer, PATH and this repository were NOT touched - they belong to the' -ForegroundColor Yellow
    Write-Host '  ordinary user, and this half may be running as somebody else. Finish with:' -ForegroundColor DarkGray
    Write-Host '    .\script.ps1 development-setup' -ForegroundColor DarkGray
    Write-Host ''
    exit (Get-FailedCount)
}

# ------------------------------------------------------- the per-user half
# Deliberately after the elevated child has returned, and deliberately not inside it.
# A PATH entry written while elevated lands in the administrator's profile, and a
# Composer installed there is a Composer the ordinary user cannot see.

# my.ini, and NOT in the elevated half. It is an ordinary writable file, so asking for
# administrator to edit it would be asking for a password the work does not need - and
# it sat in that half for one draft, where it never ran at all on a machine that needed
# nothing else installed. If a locked-down install does refuse the write, that is said
# rather than assumed.
if (Test-Missing 'MySQL engine') {
    Write-Host ''
    Write-Host '  MySQL storage engine' -ForegroundColor Cyan
    $activeMysqlVersion = Get-WampActiveVersion $state.WampRoot 'mysqlVersion'
    $myIniPath = Get-MyIniPath $state.WampRoot $(if ($activeMysqlVersion) { Join-Path $state.WampRoot "bin\mysql\mysql$activeMysqlVersion" } else { '' })
    if (-not $myIniPath) {
        Set-Row 'MySQL engine' 'SKIP' 'no my.ini found for the active MySQL'
    } else {
        try {
            Set-DefaultStorageEngine $myIniPath 'InnoDB'
            Write-Host "    InnoDB in $myIniPath"
            # The running server keeps whatever it started with, so a correct file is
            # only half of it - and a migration run before the restart fails exactly as
            # it did before, which is the confusing kind of half-fixed.
            Write-Host '    MySQL must be restarted before this takes effect:' -ForegroundColor Yellow
            Write-Host '      .\script.ps1 run-servers -MySQL -Stop' -ForegroundColor DarkGray
            Write-Host '      .\script.ps1 run-servers -MySQL' -ForegroundColor DarkGray
            Set-Row 'MySQL engine' 'FIXED' "InnoDB in $myIniPath - restart MySQL for it to take effect"
        } catch {
            Set-Row 'MySQL engine' 'FAILED' "could not write $myIniPath - $($_.Exception.Message)"
        }
    }
}

# Composer BEFORE the PATH step, and verified after it. The shim it writes calls `php`
# off PATH rather than an absolute php.exe, so composer cannot answer until PATH exists -
# which is why the row below is filled in on the far side of the sync and not here.
$composerInstalled = $false
if (Test-Missing 'Composer') {
    Write-Host ''
    Write-Host '  Composer' -ForegroundColor Cyan
    $phpExe = $(if ($state.PhpDir) { Join-Path $state.PhpDir 'php.exe' } else { '' })
    if (-not $phpExe -or -not (Test-Path -LiteralPath $phpExe)) {
        Set-Row 'Composer' 'SKIP' 'needs a working PHP first'
    } else {
        try {
            Install-Composer $phpExe $composerDir
            $composerInstalled = $true
        } catch {
            Set-Row 'Composer' 'FAILED' $_.Exception.Message
        }
    }
}

# PATH, through the same plan environment-variables uses - deliberately, because the two
# disagreeing about which PHP is active is the exact failure this project keeps hitting.
# What goes on PATH is %WAMP_PHP%, not a directory named after a version, so a switch in
# WampServer's tray menu does not silently strand this.
if ($state.WampRoot) {
    Write-Host ''
    Write-Host '  PATH' -ForegroundColor Cyan
    try {
        $plan = @(Get-WampEnvironmentPlan -Root $state.WampRoot -DbFlavor $dbFlavor -ComposerDir $composerDir)
        $usable = @($plan | Where-Object { $_.Path -and (Test-Path -LiteralPath $_.Path) })
        if (-not $usable.Count) {
            Set-Row 'PATH' 'WARN' 'WampServer names no version that exists on disk'
        } else {
            $resolved = Resolve-WampPathEntry -Root $state.WampRoot -Plan $usable
            foreach ($entry in $resolved.Removed) { Write-Host "    - $entry" -ForegroundColor DarkYellow }
            foreach ($entry in $resolved.Added) { Write-Host "    + $entry" -ForegroundColor Green }
            if (-not $resolved.Added.Count -and -not $resolved.Removed.Count) {
                Write-Host '    already exactly this' -ForegroundColor DarkGray
            }
            Set-WampEnvironment -Plan $usable -PathEntries $resolved.Entries
            Set-Row 'PATH' 'FIXED' "$(@($usable | Where-Object { $_.Name } | ForEach-Object { $_.Name }) -join ', ') -> the selected versions"
        }
    } catch {
        Set-Row 'PATH' 'FAILED' $_.Exception.Message
    }
}

# COMPOSER_HOME, on the far side of the PATH step because both are the same kind of
# thing and this is where the user's environment is written.
#
# The value it is set to is the one Composer would have defaulted to anyway. Setting it
# explicitly is not redundant: the default is computed per process from APPDATA, so a
# task, a service or a shell with a different profile silently gets its own cache,
# its own auth.json and its own global packages - and "composer global require worked
# yesterday" becomes unexplainable.
$composerReady = $(if ($script:Rows.Contains('Composer')) { $script:Rows['Composer'].State -ne 'SKIP' } else { $false })
if ($composerReady) {
    Write-Host ''
    Write-Host '  Composer home' -ForegroundColor Cyan
    try {
        Set-ComposerHome $composerHome
        Set-Row 'Composer home' 'FIXED' $composerHome
        Write-Host "    COMPOSER_HOME = $composerHome"
    } catch {
        # Not fatal: Composer computes a default when this is unset, so the cost is the
        # ambiguity above and not a broken install.
        Set-Row 'Composer home' 'WARN' $_.Exception.Message
    }
}

# Proved, not assumed - and proved HERE rather than at the install, because the shim
# calls php off PATH and PATH only exists as of the step above.
if ($composerInstalled) {
    $found = Get-ExternalVersion 'composer' @('--version', '--no-interaction')
    Set-Row 'Composer' $(if ($found) { 'FIXED' } else { 'FAILED' }) `
        $(if ($found) { "$found  $composerDir" } else { "installed to $composerDir but would not run" })
}

if ($ToolsOnly) {
    Write-Report 'After'
    Write-Host ''
    Write-Host '  Machine ready. -ToolsOnly stopped before the repository.' -ForegroundColor Green
    Write-Host '  Open a NEW terminal before the next command: PATH changed in this one only.' -ForegroundColor DarkGray
    Write-Host ''
    exit (Get-FailedCount)
}

# ------------------------------------------------------------- this repository
# Gated on the two rows this half actually uses, not on the whole table. A failed MySQL
# add-on says nothing about whether composer install will work, and stopping over it
# leaves a machine with a PHP, no vendor/ and no explanation.
$blockers = @(@("PHP $phpSeries", 'Composer') | Where-Object {
        $script:Rows.Contains($_) -and $script:Rows[$_].State -in @('FAILED', 'MISSING', 'SKIP')
    })
if ($blockers.Count) {
    Write-Report 'After'
    Write-Host ''
    Write-Host "  Stopping before the repository: it needs $($blockers -join ' and ')." -ForegroundColor Yellow
    Write-Host '  Fix that above and run this again - everything already done is graded OK' -ForegroundColor DarkGray
    Write-Host '  on the next run and costs nothing.' -ForegroundColor DarkGray
    Write-Host ''
    exit ([Math]::Max((Get-FailedCount), 1))
}

Write-Host ''
Write-Host '  Repository' -ForegroundColor Cyan

$envFile = Join-Path $repoRoot '.env'
$envExample = Join-Path $repoRoot '.env.example'
if (-not (Test-Path -LiteralPath $envFile)) {
    Copy-Item -LiteralPath $envExample -Destination $envFile
    Write-Host '    .env created from .env.example'
    Set-Row '.env' 'FIXED' 'created from .env.example'
} else {
    Write-Host '    .env already there, left alone' -ForegroundColor DarkGray
    Set-Row '.env' 'OK' 'left as it is'
}

# Hashed rather than timestamped: a pull that changes nothing in composer.lock should
# not spend a minute reinstalling, and a lock file restored to an older state should.
$lockPath = Join-Path $repoRoot 'composer.lock'
$fingerprint = ''
if (Test-Path -LiteralPath $lockPath) {
    $fingerprint = (Get-FileHash -Algorithm SHA256 -LiteralPath $lockPath).Hash
}
$vendorPresent = Test-Path -LiteralPath (Join-Path $repoRoot 'vendor\autoload.php')
$upToDate = (-not $Force) -and $vendorPresent -and $fingerprint -and (Test-Path -LiteralPath $stampPath) -and
    ((Get-Content -LiteralPath $stampPath -Raw).Trim() -eq $fingerprint)

if ($upToDate) {
    Write-Host '    vendor/ already matches composer.lock (-Force to install anyway)' -ForegroundColor DarkGray
    Set-Row 'PHP packages' 'OK' 'vendor/ matches composer.lock'
} else {
    Write-Host '    composer install'
    Push-Location $repoRoot
    try {
        & composer install --no-interaction --prefer-dist
        if ($LASTEXITCODE -ne 0) { throw "composer install exited with $LASTEXITCODE." }
        if ($fingerprint) { [IO.File]::WriteAllText($stampPath, $fingerprint) }
        Set-Row 'PHP packages' 'FIXED' 'composer install'
    } catch {
        Set-Row 'PHP packages' 'FAILED' $_.Exception.Message
    } finally { Pop-Location }
}

if ($script:Rows['PHP packages'].State -ne 'FAILED') {
    $appKey = @(Get-Content -LiteralPath $envFile | Where-Object { $_ -match '^\s*APP_KEY\s*=\s*\S' }).Count
    if ($appKey) {
        Write-Host '    APP_KEY already set, left alone' -ForegroundColor DarkGray
        Set-Row 'APP_KEY' 'OK' 'already set'
    } else {
        Write-Host '    php artisan key:generate'
        Push-Location $repoRoot
        try {
            & (Join-Path $state.PhpDir 'php.exe') 'artisan' 'key:generate' '--no-interaction'
            Set-Row 'APP_KEY' $(if ($LASTEXITCODE -eq 0) { 'FIXED' } else { 'FAILED' }) `
                $(if ($LASTEXITCODE -eq 0) { 'generated' } else { "artisan exited with $LASTEXITCODE" })
        } catch {
            Set-Row 'APP_KEY' 'FAILED' $_.Exception.Message
        } finally { Pop-Location }
    }
}

# The databases this .env names, created empty. WampServer's MySQL starts with a root
# account and no password, which is the only reason this can be done without asking for
# a credential - and the reason it is CREATE DATABASE IF NOT EXISTS and nothing else.
# Migrations are not run here: which of the three is seeded, and when, is the
# developer's call and not something a setup script should decide.
$mysqlExe = $(if ($state.MysqlDir) { Join-Path $state.MysqlDir 'bin\mysql.exe' } else { '' })
$serverUp = @(Get-Process -Name 'mysqld' -ErrorAction SilentlyContinue).Count -gt 0

if ($mysqlExe -and (Test-Path -LiteralPath $mysqlExe) -and -not $serverUp) {
    # The normal state at the end of a first run, and not a failure: this script stops
    # WampServer to install an add-on and deliberately does not start it again.
    Set-Row 'Databases' 'WARN' 'WampServer is stopped - start it from the tray, then run this again'
} elseif ($mysqlExe -and (Test-Path -LiteralPath $mysqlExe)) {
    $names = @()
    foreach ($line in (Get-Content -LiteralPath $envFile)) {
        if ($line -match '^\s*[A-Z_]*DB_DATABASE\s*=\s*(\S+)\s*$') { $names += $Matches[1].Trim('"').Trim("'") }
    }
    $names = @($names | Select-Object -Unique | Where-Object { $_ })

    Write-Host "    databases: $($names -join ', ')"
    $user = "$((Get-Content -LiteralPath $envFile | Where-Object { $_ -match '^\s*DB_USERNAME\s*=' } | Select-Object -First 1) -replace '^.*=', '')".Trim()
    if (-not $user) { $user = 'root' }
    $created = @()
    $unreachable = ''
    foreach ($name in $names) {
        try {
            & $mysqlExe "--user=$user" '--protocol=tcp' '--execute' "CREATE DATABASE IF NOT EXISTS ``$name``" 2>&1 | Out-Null
            if ($LASTEXITCODE -eq 0) { $created += $name } else { $unreachable = "mysql exited with $LASTEXITCODE" }
        } catch { $unreachable = $_.Exception.Message }
    }
    if ($created.Count -eq $names.Count) {
        Set-Row 'Databases' 'FIXED' "$($created -join ', ')"
    } else {
        # A WARN and not a FAILED: MySQL not running yet is the normal state five minutes
        # after WampServer installs, and it is fixed by starting WampServer, not by
        # anything this script could do differently.
        Set-Row 'Databases' 'WARN' "start WampServer, then re-run - $unreachable"
    }
} else {
    Set-Row 'Databases' 'SKIP' 'no mysql.exe found'
}

Write-Report 'After'

$failed = Get-FailedCount
Write-Host ''
if ($failed) {
    Write-Host "  $failed thing(s) still need attention - see FAILED above." -ForegroundColor Red
} else {
    Write-Host '  Setup complete.' -ForegroundColor Green
}
Write-Host ''
Write-Host '  Open a NEW terminal first: PATH changed for this one only.' -ForegroundColor DarkGray
Write-Host '    Start WampServer from the Start menu, and wait for a green tray icon'
if ($script:Rows.Contains('MySQL active') -and $script:Rows['MySQL active'].State -eq 'WARN') {
    Write-Host "    Left-click the tray icon: MySQL > Version > $mysqlSeries.x  (it is on $(Get-WampActiveVersion $state.WampRoot 'mysqlVersion') now)"
}
Write-Host '    .\script.ps1 environment-variables  re-point PATH at the versions in use'
Write-Host '    .\script.ps1 development-setup      again, for the databases'
Write-Host '    php artisan migrate                 build the schema'
Write-Host '    php artisan serve                   run the API on http://localhost:8000'
Write-Host '    .\script.ps1                        what else is available'
Write-Host ''
exit $failed
# ==============================[ end: The run ]===============================
