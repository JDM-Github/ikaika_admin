# Starts and stops the servers this project develops against: WampServer's Apache and
# MySQL, phpMyAdmin in a browser, the Laravel API, and the Vite asset build.
#
# Every server is a switch, so ticking three boxes starts three things and nothing else.
# WITH NO SWITCHES it starts Apache and the active database - the pair the site cannot
# load without - and leaves the rest alone. That is a deliberate default rather than a
# form: starting a web server is not destructive, and a command called run-servers that
# does nothing until told which ones is a command nobody uses twice.
#
# TWO KINDS OF SERVER LIVE HERE, and they are managed differently because Windows does:
#
#   Apache, MySQL, MariaDB   Windows services WampServer installed. Start-Service, and
#                            the machine remembers them across a reboot.
#   The API, Vite            ordinary processes with no service behind them. Started
#                            detached, their output redirected to a log, and their pid
#                            written to .ikaika_scripts_configs/.servers.json so -Stop
#                            can find them again.
#
# That file is the only reason -Stop works at all. A pid is not enough on its own - pids
# are reused - so the start time is recorded beside it and checked before anything is
# killed. Stopping a process that merely inherited a pid would be a very confusing bug to
# be on the other end of.
#
# PHPMYADMIN IS NOT A SERVER. It is a PHP application Apache serves, so -PhpMyAdmin
# starts Apache if it is not up and opens the URL. It is a switch because that is where
# somebody looks for it, not because there is a process by that name.
#
#     .\script.ps1 run-servers                    Apache and the active database
#     .\script.ps1 run-servers -Status            what is up, change nothing
#     .\script.ps1 run-servers -PhpMyAdmin        Apache, then the browser
#     .\script.ps1 run-servers -Api -Vite         the API and the asset build
#     .\script.ps1 run-servers -All               everything
#     .\script.ps1 run-servers -Stop              stop whatever this started
#
# Requires PowerShell 7+. Services need administrator; the API and Vite do not, which is
# why a run that touches only those never asks for it.

# ============================[ start: Parameters ]============================
# [CmdletBinding()] so a mistyped switch is an ERROR, not a no-op. In a simple param
# block PowerShell silently IGNORES a parameter it does not recognise - measured, not
# assumed - so `.\script.ps1 run-servers -Appache` would start the default set and say
# nothing about the flag that did nothing. Advanced binding refuses it by name.
[CmdletBinding()]
param(
    # Start WampServer itself - wampmanager.exe, the tray application - and let it bring
    # up Apache and the database the way it does when a person double-clicks it. This is
    # what a bare run does.
    [switch]$WampServer,
    # WampServer's Apache, as a Windows service. Needs administrator; -WampServer does
    # not, which is why that is the default and this is the scalpel.
    [switch]$Apache,
    # WampServer's MySQL service.
    [switch]$MySQL,
    # WampServer's MariaDB service. Separate from MySQL on purpose - they are two
    # servers on two ports, and this project runs one of them.
    [switch]$MariaDB,
    # Open phpMyAdmin in the browser, starting Apache first if it is not up.
    [switch]$PhpMyAdmin,
    # The Laravel API, as php artisan serve.
    [switch]$Api,
    # The Vite asset build, as npm run dev.
    [switch]$Vite,
    # Everything above, which is what a fresh working session usually wants.
    [switch]$All,
    # Stop instead of start. With no other switch, stops everything this started.
    [switch]$Stop,
    # Say what is running and stop. Nothing is started, stopped or opened.
    [switch]$Status,
    # The port the API binds, overriding serve.apiPort for this run. Offered as a list
    # rather than a box: the choices are the configured one plus the usual alternatives,
    # and each says whether anything is already listening on it - which is the only
    # question anybody actually has when they change a port.
    [string]$ApiPort = '',
    # The same for Vite.
    [string]$VitePort = '',
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
    Write-Host '  run-servers - start and stop what this project develops against.'
    Write-Host ''
    Write-Host '  Usage'
    Write-Host '    .\script.ps1 run-servers [-WampServer] [-Apache] [-MySQL] [-MariaDB]'
    Write-Host '                             [-PhpMyAdmin] [-Api] [-Vite] [-All]'
    Write-Host '                             [-Stop] [-Status]'
    Write-Host ''
    Write-Host '  No switches starts WampServer itself - wampmanager, the tray'
    Write-Host '  application - which brings up Apache and the database. It needs no'
    Write-Host '  administrator, which is the whole reason it is the default.'
    Write-Host ''
    Write-Host '  -Apache, -MySQL and -MariaDB drive the Windows services directly and DO'
    Write-Host '  need an admin terminal. Use them when you want one of them alone.'
    Write-Host ''
    Write-Host '  The API and Vite are ordinary processes, run as you, with their output'
    Write-Host '  in .ikaika_scripts_configs/logs/. -Status changes nothing.'
    Write-Host ''
    Write-Host '  Config (ikaika_scripts.env)'
    Write-Host '    setup.wampRoot      where WampServer is'
    Write-Host '    setup.dbFlavor      which database the bare run starts'
    Write-Host '    serve.apiPort       the port artisan serve binds (default 8000)'
    Write-Host '    serve.phpMyAdminUrl where phpMyAdmin lives on this Apache'
    Write-Host ''
    exit 0
}

Start-BuildLog 'run-servers'

$wampRoot = Find-WampRoot (Get-ProjectValue 'setup.wampRoot' 'C:\wamp64')
$dbFlavor = "$(Get-ProjectValue 'setup.dbFlavor' 'mysql')".ToLower()
# The switch wins over the file, and the file over the built-in - the ordinary
# precedence, and the reason a port can be changed for one run without editing anything
# that is committed. Only a number is accepted: a typo reaching Start-Process becomes an
# argument artisan reads as a host name.
# READ BEFORE ANYTHING ELSE, and this is not fussiness. PowerShell variable names are
# CASE-INSENSITIVE, so a local named $apiPort and the parameter $ApiPort are ONE variable
# reads the configured default was overwriting the parameter before the check could see
# it. The switch was silently ignored, and a value of 'eighty' passed validation because
# by then it had already become 8000. Hence the separate names.
$requestedApiPort = "$ApiPort".Trim()
$requestedVitePort = "$VitePort".Trim()

# Refused in the command's own voice rather than thrown. A throw here reaches the screen
# as a PowerShell exception with a source line and a squiggle under the code, which is a
# stack trace for somebody who simply mistyped a port.
function Resolve-Port([string]$Requested, [string]$Key, [string]$Fallback, [string]$Flag) {
    $port = [int](Get-ProjectValue $Key $Fallback)
    if (-not $Requested) { return $port }
    if ($Requested -notmatch '^\d+$' -or [int]$Requested -lt 1 -or [int]$Requested -gt 65535) {
        Write-Host ''
        Write-Host "  $Flag takes a port number between 1 and 65535, not '$Requested'." -ForegroundColor Yellow
        Write-Host ''
        exit 1
    }
    return [int]$Requested
}

# $apiPortNumber, not $apiPort - the latter IS the [string]$ApiPort parameter, because
# PowerShell matches variable names case-insensitively. It did not crash the way $status
# did, only because an int assigned to a [string] converts silently - so the parameter
# was quietly destroyed and the port travelled on as a string that happened to work.
# Found by the AST audit rather than by anything failing, which is the point of it.
$apiPortNumber = Resolve-Port $requestedApiPort 'serve.apiPort' '8000' '-ApiPort'
$vitePortNumber = Resolve-Port $requestedVitePort 'serve.vitePort' '5173' '-VitePort'
$phpMyAdminUrl = Get-ProjectValue 'serve.phpMyAdminUrl' 'http://localhost/phpmyadmin'
$startTimeout = [int](Get-ProjectValue 'serve.startTimeoutSeconds' '30')
$statePath = Join-Path $configsDir '.servers.json'
$logsDir = Get-LogsDir

if ($All) { $WampServer = $PhpMyAdmin = $Api = $Vite = $true }

# The bare run. Written as "nothing was asked for" rather than as a default on each
# switch, because a default on a switch would make -Stop mean "stop everything except
# the two that default to on", which is not what anybody types it for.
$nothingPicked = -not ($WampServer -or $Apache -or $MySQL -or $MariaDB -or $PhpMyAdmin -or $Api -or $Vite)

# THE BARE RUN STARTS WAMPSERVER, not the two services underneath it, and the reason is
# administrator rights. Start-Service on wampapache64 needs them; launching wampmanager
# does not - it is an ordinary application that manages its own services, asks for
# elevation itself if it needs any, and is what every developer here already
# double-clicks. Driving the services directly made the common case prompt for a
# password it never needed.
#
# The scalpel is still there: -Apache and -MySQL do exactly what they say, and still
# need an admin terminal. That is the right trade - the default is the easy path, and
# the precise one is available and honest about its cost.
if ($nothingPicked -and -not $Stop -and -not $Status) {
    if ($wampRoot -and (Test-Path -LiteralPath (Join-Path $wampRoot 'wampmanager.exe'))) {
        $WampServer = $true
    } else {
        $Apache = $true
        if ($dbFlavor -eq 'mariadb') { $MariaDB = $true } else { $MySQL = $true }
    }
    # AND THE API, because this repository IS the API. A bare run that brought up Apache
    # and the database served phpMyAdmin and nothing of the project, and the first thing
    # anybody does after starting the servers is open localhost:8000 - which was not
    # there. Vite stays opt-in: the backend has no node_modules and the asset build is
    # not what this project is.
    $Api = $true
}

# --------------------------------------------------------------- the servers
# One row per thing this command can start. Services and processes are told apart by
# Service being set, and everything below reads this rather than re-listing the names.
$Servers = @(
    [pscustomobject]@{
        Key = 'apache'; Label = 'Apache'; Wanted = [bool]$Apache
        Service = 'wampapache64'; Port = 80; Command = ''
    }
    [pscustomobject]@{
        Key = 'mysql'; Label = 'MySQL'; Wanted = [bool]$MySQL
        Service = 'wampmysqld64'; Port = 3306; Command = ''
    }
    [pscustomobject]@{
        Key = 'mariadb'; Label = 'MariaDB'; Wanted = [bool]$MariaDB
        Service = 'wampmariadb64'; Port = 3307; Command = ''
    }
    [pscustomobject]@{
        Key = 'api'; Label = 'Laravel API'; Wanted = [bool]$Api
        Service = ''; Port = $apiPortNumber; Command = 'api'
    }
    [pscustomobject]@{
        Key = 'vite'; Label = 'Vite'; Wanted = [bool]$Vite
        Service = ''; Port = $vitePortNumber; Command = 'vite'
    }
)

# ------------------------------------------------------------------- state
# What this command started last time, so -Stop has something to act on. A plain file
# rather than anything clever: the two processes it tracks have no service, no window
# and no name of their own, and a pid alone cannot be trusted.
function Get-ServerState {
    if (-not (Test-Path -LiteralPath $statePath)) { return @{} }
    try {
        $raw = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    } catch { return @{} }
    $state = @{}
    foreach ($property in $raw.PSObject.Properties) { $state[$property.Name] = $property.Value }
    return $state
}

function Save-ServerState([hashtable]$State) {
    if (-not $State.Count) {
        Remove-Item -LiteralPath $statePath -Force -ErrorAction SilentlyContinue
        return
    }
    ($State | ConvertTo-Json -Depth 4) | Set-Content -LiteralPath $statePath -Encoding utf8
}

# The process this command started, or $null. The start time is what makes this safe:
# Windows reuses pids, and killing whatever inherited one would be a bug nobody could
# reproduce.

# The port a pid is ACTUALLY listening on, asked of the OS. Used when the recorded state
# predates the Port field: falling back to the port this run happens to request would
# print a number the process has nothing to do with, which is the failure this whole
# function exists to avoid. $null when the pid holds no listening socket.
function Get-ListeningPort([int]$ProcessId) {
    try {
        $found = @(Get-NetTCPConnection -State Listen -OwningProcess $ProcessId -ErrorAction Stop)
        if ($found.Count) { return $found[0].LocalPort }
    } catch {
        # No NetTCPIP module, or the pid holds no socket. Both mean the same thing to the
        # caller - there is no port to report - and neither is worth a warning.
        return $null
    }
    return $null
}

function Get-TrackedProcess([object]$Entry) {
    if (-not $Entry -or -not $Entry.Pid) { return $null }
    $process = Get-Process -Id ([int]$Entry.Pid) -ErrorAction SilentlyContinue
    if (-not $process) { return $null }
    if ($Entry.Started) {
        $recorded = [datetime]::Parse("$($Entry.Started)")
        if ([math]::Abs(($process.StartTime - $recorded).TotalSeconds) -gt 5) { return $null }
    }
    return $process
}

function Get-ServiceState([string]$Name) {
    $found = Get-Service -Name $Name -ErrorAction SilentlyContinue
    if (-not $found) { return 'absent' }
    return "$($found.Status)".ToLower()
}

function Test-Elevated {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]::new($identity)).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Write-ServerLine([string]$Label, [string]$State, [string]$Detail) {
    $colour = @{ running = 'Green'; started = 'Green'; stopped = 'DarkGray'
        absent = 'Yellow'; failed = 'Red'; skipped = 'DarkGray'; slow = 'Yellow'
    }
    $shown = $(if ($colour.ContainsKey($State)) { $colour[$State] } else { 'Gray' })
    Write-Host ('    {0,-9}' -f $State) -ForegroundColor $shown -NoNewline
    Write-Host ('{0,-14} {1}' -f $Label, $Detail)
}

# ------------------------------------------------------------------ status
$state = Get-ServerState

function Write-Status {
    Write-Host ''
    Write-Host '  Servers' -ForegroundColor Cyan

    # First, because it is the thing that starts the two below it. A tray icon has no
    # state this can read - green, orange and red are wampmanager's own - so what is
    # reported is whether the application is up, and the services speak for themselves.
    $manager = @(Get-Process -Name 'wampmanager' -ErrorAction SilentlyContinue)
    Write-ServerLine 'WampServer' $(if ($manager.Count) { 'running' } else { 'stopped' }) `
        $(if ($manager.Count) { "wampmanager pid $($manager[0].Id)" } else { 'the tray application is not up' })
    foreach ($server in $Servers) {
        if ($server.Service) {
            # $serviceState rather than $status. Inside a function a local would in fact
            # shadow the [switch]$Status parameter safely - that was measured - so this
            # rename is for consistency with the two loops below, where the same
            # assignment IS at script scope and throws "Cannot convert System.String to
            # SwitchParameter". One name for one thing, so moving the code cannot break
            # it.
            $serviceState = Get-ServiceState $server.Service
            $detail = $(if ($serviceState -eq 'absent') { "no $($server.Service) service - is WampServer installed?" }
                else { "$($server.Service)  port $($server.Port)" })
            Write-ServerLine $server.Label $serviceState $detail
            continue
        }
        $tracked = Get-TrackedProcess $state[$server.Key]
        if ($tracked) {
            # Recorded first, then the OS, then the request - most trustworthy first.
            $onPort = $state[$server.Key].Port
            if (-not $onPort) { $onPort = Get-ListeningPort $tracked.Id }
            if (-not $onPort) { $onPort = $server.Port }
            $note = $(if ("$onPort" -ne "$($server.Port)") { "  (this run asked for $($server.Port))" } else { '' })
            Write-ServerLine $server.Label 'running' "pid $($tracked.Id)  port $onPort$note"
        } elseif (Test-PortListening $server.Port) {
            # Something answers on the port but this command did not start it. Said
            # plainly rather than called stopped: starting a second copy would fail on
            # the bind and the error would blame the wrong thing.
            Write-ServerLine $server.Label 'running' "port $($server.Port), started outside this command"
        } else {
            Write-ServerLine $server.Label 'stopped' "port $($server.Port)"
        }
    }
}

Write-Host ''
Write-Host "  Run servers - $(Get-ProjectValue 'app.displayName' 'this project')" -ForegroundColor Cyan
if ($wampRoot) { Write-Host "  $wampRoot" -ForegroundColor DarkGray }

if ($Status) {
    Write-Status
    Write-Host ''
    exit 0
}

# ------------------------------------------------------------------- stop
if ($Stop) {
    Write-Host ''
    Write-Host '  Stopping' -ForegroundColor Cyan
    $failures = 0

    # Stopping WampServer is the tray icon's own Exit, and there is no documented
    # command line for it. Rather than inventing one - killing wampmanager leaves its
    # services running, which is the worst of both - the services are stopped, which is
    # what "stop the servers" means, and the tray is left to be closed by hand.
    if ($WampServer -or $nothingPicked) {
        $manager = @(Get-Process -Name 'wampmanager' -ErrorAction SilentlyContinue)
        if ($manager.Count) {
            Write-ServerLine 'WampServer' 'running' 'the tray application stays - close it from the tray icon'
        }
    }

    foreach ($server in @($Servers | Where-Object { $nothingPicked -or $_.Wanted })) {
        if ($server.Service) {
            # $serviceState, NOT $status. PowerShell variable names are case-insensitive,
            # so $status IS the [switch]$Status parameter - and assigning a string to it
            # threw "Cannot convert System.String to SwitchParameter" from the assignment
            # itself. The whole service branch of -Stop died there, silently, because the
            # dispatcher reports a crashed command as exit 0. Second time this exact trap
            # has bitten in this file; the port parameters were the first.
            $serviceState = Get-ServiceState $server.Service
            if ($serviceState -eq 'absent') { Write-ServerLine $server.Label 'absent' 'no such service'; continue }
            if ($serviceState -eq 'stopped') { Write-ServerLine $server.Label 'stopped' 'already'; continue }
            if (-not (Test-Elevated)) {
                Write-ServerLine $server.Label 'skipped' 'a service needs an admin terminal'
                $failures++
                continue
            }
            try {
                Stop-Service -Name $server.Service -Force -ErrorAction Stop
                Write-ServerLine $server.Label 'stopped' $server.Service
            } catch {
                Write-ServerLine $server.Label 'failed' $_.Exception.Message
                $failures++
            }
            continue
        }

        $tracked = Get-TrackedProcess $state[$server.Key]
        if (-not $tracked) {
            Write-ServerLine $server.Label 'stopped' 'nothing this command started'
            $state.Remove($server.Key)
            continue
        }
        try {
            # The whole tree, not just the parent: artisan serve and npm each run the
            # real server as a child, and killing only the launcher leaves the port held
            # by an orphan nothing has a handle on any more.
            & taskkill.exe '/PID' "$($tracked.Id)" '/T' '/F' 2>&1 | Out-Null

            # STOPPED MEANS THE PORT IS FREE, for the same reason started means it
            # answers: taskkill returns when the request is made, not when the socket is
            # released, so the status printed straight after reported the server as
            # still running - from its own dying process.
            $deadline = (Get-Date).AddSeconds(10)
            while ((Get-Date) -lt $deadline -and (Test-PortListening $server.Port)) {
                Start-Sleep -Milliseconds 250
            }

            Write-ServerLine $server.Label 'stopped' "pid $($tracked.Id)"
            $state.Remove($server.Key)
        } catch {
            Write-ServerLine $server.Label 'failed' $_.Exception.Message
            $failures++
        }
    }

    Save-ServerState $state
    Write-Status
    Write-Host ''
    exit $failures
}

# ------------------------------------------------------------------ start
Write-Host ''
Write-Host '  Starting' -ForegroundColor Cyan

# phpMyAdmin is served by Apache, and WampServer is what starts Apache without a
# password. Asking for the page therefore asks for WampServer, not for the service.
if ($PhpMyAdmin -and -not ($Apache -or $WampServer)) { $WampServer = $true }

if ($WampServer) {
    $manager = $(if ($wampRoot) { Join-Path $wampRoot 'wampmanager.exe' } else { '' })
    if (-not $manager -or -not (Test-Path -LiteralPath $manager)) {
        Write-ServerLine 'WampServer' 'absent' 'no wampmanager.exe - run development-setup'
        $failures++
    } elseif (@(Get-Process -Name 'wampmanager' -ErrorAction SilentlyContinue).Count) {
        Write-ServerLine 'WampServer' 'running' 'wampmanager is already up'
    } else {
        try {
            # Started from the wamp root, because wampmanager resolves its own config
            # relative to the working directory and starts with an error dialog from
            # anywhere else - which, being a dialog, hangs an unattended run.
            [void](Start-Process -FilePath $manager -WorkingDirectory $wampRoot -PassThru)

            # Waited for by its PORTS, not by its window: wampmanager returns
            # immediately and then starts Apache and the database behind the tray icon.
            # Green means answering here exactly as it does everywhere else in this file.
            $dbPort = $(if ($dbFlavor -eq 'mariadb') { 3307 } else { 3306 })
            $deadline = (Get-Date).AddSeconds($startTimeout)
            while ((Get-Date) -lt $deadline) {
                if ((Test-PortListening 80) -and (Test-PortListening $dbPort)) { break }
                Start-Sleep -Milliseconds 500
            }
            $up = @()
            if (Test-PortListening 80) { $up += 'Apache' }
            if (Test-PortListening $dbPort) { $up += "the database on $dbPort" }
            if ($up.Count -eq 2) {
                Write-ServerLine 'WampServer' 'started' "$($up -join ' and ') are answering"
            } else {
                # Not called failed: wampmanager may still be working, or the tray icon
                # may be orange for a reason only it can explain. Named for what was
                # seen, with the one place that says why.
                Write-ServerLine 'WampServer' 'slow' "started, but $(if ($up.Count) { "only $($up -join '') is up" } else { 'nothing is answering yet' }) after ${startTimeout}s - check the tray icon"
            }
        } catch {
            Write-ServerLine 'WampServer' 'failed' $_.Exception.Message
            $failures++
        }
    }
}
foreach ($server in $Servers) { if ($server.Key -eq 'apache' -and $Apache) { $server.Wanted = $true } }

$failures = 0
$wanted = @($Servers | Where-Object { $_.Wanted })

# One elevation prompt for all the services at once, rather than one per service.
$servicesNeeded = @($wanted | Where-Object { $_.Service -and (Get-ServiceState $_.Service) -eq 'stopped' })
if ($servicesNeeded.Count -and -not (Test-Elevated)) {
    Write-Host '    a service needs administrator - asking once' -ForegroundColor DarkGray
    $names = ($servicesNeeded | ForEach-Object { $_.Service }) -join ','
    try {
        $child = Start-Process -FilePath 'powershell.exe' -Verb RunAs -Wait -PassThru -ArgumentList @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command',
            "Start-Service -Name @('$($names -replace ',', "','")') -ErrorAction Stop")
        if ($child.ExitCode -ne 0) { $failures++ }
    } catch {
        # Declining UAC is an answer, not a crash. Everything that does not need a
        # service still starts, and the table at the end says what did not.
        Write-Host '    administrator was declined - the services were left alone' -ForegroundColor Yellow
    }
}

foreach ($server in $wanted) {
    if ($server.Service) {
        $serviceState = Get-ServiceState $server.Service
        if ($serviceState -eq 'absent') {
            Write-ServerLine $server.Label 'absent' "no $($server.Service) - run development-setup"
            $failures++
            continue
        }
        if ($serviceState -eq 'running') { Write-ServerLine $server.Label 'running' 'already'; continue }
        try {
            Start-Service -Name $server.Service -ErrorAction Stop
            Write-ServerLine $server.Label 'started' "$($server.Service)  port $($server.Port)"
        } catch {
            Write-ServerLine $server.Label 'failed' $_.Exception.Message
            $failures++
        }
        continue
    }

    if (Get-TrackedProcess $state[$server.Key]) {
        Write-ServerLine $server.Label 'running' "pid $($state[$server.Key].Pid), already"
        continue
    }
    if (Test-PortListening $server.Port) {
        Write-ServerLine $server.Label 'running' "port $($server.Port) is already answering - left alone"
        continue
    }

    # Detached, with stdout and stderr on disk. A server started from here outlives the
    # command that started it, so its output cannot go to a console that is about to
    # close - and a log is what makes "it started and then died" answerable.
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $logPath = Join-Path $logsDir "$($server.Key)-$stamp.log"
    $errPath = Join-Path $logsDir "$($server.Key)-$stamp.err.log"
    try {
        $process = $null
        if ($server.Command -eq 'api') {
            $php = Get-Command php -ErrorAction SilentlyContinue
            if (-not $php) { throw 'php is not on PATH - run environment-variables' }
            $process = Start-Process -FilePath $php.Source -WorkingDirectory $repoRoot -PassThru `
                -WindowStyle Hidden -RedirectStandardOutput $logPath -RedirectStandardError $errPath `
                -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', "--port=$apiPortNumber")
        } else {
            $npm = Get-Command npm.cmd -ErrorAction SilentlyContinue
            if (-not $npm) { $npm = Get-Command npm -ErrorAction SilentlyContinue }
            if (-not $npm) { throw 'npm is not on PATH - install Node.js' }
            # Named before it is attempted. Without node_modules, npm run dev fails with
            # "'vite' is not recognized", which reads as a broken PATH or a missing
            # global install - neither of which is the problem, and neither of which is
            # fixed by anything the message suggests.
            if (-not (Test-Path -LiteralPath (Join-Path $repoRoot 'node_modules'))) {
                throw 'node_modules is missing - run "npm install" in the repo root first'
            }
            $process = Start-Process -FilePath $npm.Source -WorkingDirectory $repoRoot -PassThru `
                -WindowStyle Hidden -RedirectStandardOutput $logPath -RedirectStandardError $errPath `
                -ArgumentList @('run', 'dev')
        }

        # STARTED MEANS ANSWERING, not "a process with that pid exists". Those are
        # different things for the first few seconds: artisan serve bootstraps the whole
        # framework before it binds, so a status that goes green on the pid alone sends
        # somebody to a URL that refuses the connection, and they go looking at Apache,
        # the port, the firewall - anywhere but the thing that had not finished starting.
        #
        # So the port is polled until it answers. An exit during the wait is a real
        # failure and breaks out immediately with whatever the log caught.
        $deadline = (Get-Date).AddSeconds($startTimeout)
        $listening = $false
        while ((Get-Date) -lt $deadline) {
            if ($process.HasExited) { break }
            if (Test-PortListening $server.Port) { $listening = $true; break }
            Start-Sleep -Milliseconds 400
        }

        if ($process.HasExited) {
            $why = ''
            foreach ($path in @($errPath, $logPath)) {
                if (-not (Test-Path -LiteralPath $path)) { continue }
                $tail = @(Get-Content -LiteralPath $path -Tail 3 | Where-Object { "$_".Trim() })
                if ($tail.Count) { $why = ($tail -join ' ').Trim(); break }
            }
            Write-ServerLine $server.Label 'failed' "exited with $($process.ExitCode). $why"
            $failures++
            continue
        }

        $state[$server.Key] = @{
            Pid     = $process.Id
            Started = $process.StartTime.ToString('o')
            Log     = $logPath
            # THE PORT IT WAS STARTED ON, not the one asked for now. With -ApiPort the
            # two differ, and a status that printed the requested port beside a pid
            # listening on the old one was stating something untrue about a running
            # process - the exact class of thing this file keeps trying not to do.
            Port    = $server.Port
        }
        if ($listening) {
            Write-ServerLine $server.Label 'started' "pid $($process.Id)  port $($server.Port)  $logPath"
        } else {
            # Alive but silent. Not called a failure - it may still come up - and not
            # called started either, because the one thing this must never do is claim a
            # server is serving when nothing answers.
            Write-ServerLine $server.Label 'slow' "pid $($process.Id), nothing on port $($server.Port) after ${startTimeout}s - $logPath"
        }
    } catch {
        Write-ServerLine $server.Label 'failed' $_.Exception.Message
        $failures++
    }
}

Save-ServerState $state

if ($PhpMyAdmin) {
    Write-Host ''
    Write-Host '  phpMyAdmin' -ForegroundColor Cyan
    if ((Get-ServiceState 'wampapache64') -ne 'running') {
        Write-Host '    Apache is not running, so the page would not load - not opened.' -ForegroundColor Yellow
        $failures++
    } else {
        Start-Process $phpMyAdminUrl | Out-Null
        Write-Host "    opened $phpMyAdminUrl"
    }
}

Write-Status

Write-Host ''
if ($failures) {
    Write-Host "  $failures server(s) did not start - see above." -ForegroundColor Red
} else {
    Write-Host '  Running.' -ForegroundColor Green
}
Write-Host "    http://localhost:$apiPortNumber            the API" -ForegroundColor DarkGray
Write-Host "    $phpMyAdminUrl   the database, in a browser" -ForegroundColor DarkGray
Write-Host '    .\script.ps1 run-servers -Stop       stop what this started' -ForegroundColor DarkGray
Write-Host ''
exit $failures

# =========================[ start: What the app asks ]========================
# LIFTED OUT OF THIS FILE AND RUN ALONE, so nothing here may call anything above it -
# not Get-ProjectValue, not Test-PortListening, not one of this file's own helpers. That
# is why the walk up to ikaika_scripts.env is written out again here rather than shared.
#
# A PORT IS NOT A TEXT BOX. It is a number somebody has to recall, and a wrong one fails
# much later as "connection refused" pointing at the client. Offered as a list, each
# entry saying whether anything is already listening, it becomes recognition: the
# configured port first, then the usual alternatives, and the busy ones marked so the
# one mistake that matters - picking a port something else already holds - is visible
# before it is made.
#
# DECLARED EVEN THOUGH THE LIST IS COMPUTED. Without Get-DialogChoiceParameters a
# manifest generated before the provider ever returned anything records "no choices
# here", and the app draws a box that stays a box.
function Get-DialogChoiceParameters { return , @('ApiPort', 'VitePort') }

function Get-DialogChoices([string]$Parameter) {
    if ($Parameter -notin @('ApiPort', 'VitePort')) { return @() }

    $key = $(if ($Parameter -eq 'ApiPort') { 'serve.apiPort' } else { 'serve.vitePort' })
    $fallback = $(if ($Parameter -eq 'ApiPort') { '8000' } else { '5173' })

    $configured = ''
    $dir = Get-Item -LiteralPath (Get-Location).Path
    while ($null -ne $dir) {
        $file = Join-Path $dir.FullName 'ikaika_scripts.env'
        if (Test-Path -LiteralPath $file) {
            foreach ($line in (Get-Content -LiteralPath $file)) {
                if ("$line" -match "^\s*$([regex]::Escape($key))\s*=\s*(.*)$") {
                    $configured = "$($Matches[1])".Trim()
                }
            }
            break
        }
        $dir = $dir.Parent
    }
    if (-not $configured) { $configured = $fallback }

    $candidates = @($configured)
    foreach ($port in @($fallback, '8000', '8001', '8080', '3000', '5173', '5174', '4200')) {
        if ($candidates -notcontains $port) { $candidates += $port }
    }

    # BARE NUMBERS, never annotated. Whatever a row says is what the app sends back, so
    # "8000 (in use)" would arrive at -ApiPort as exactly that and fail validation. The
    # busy check therefore changes the ORDER instead of the text: the configured port
    # stays first because it is the answer unless somebody has a reason, and the rest are
    # sorted free-before-busy so the first one anybody scrolls to is one that will work.
    $free = @()
    $busy = @()
    foreach ($port in $candidates) {
        $taken = $false
        try {
            $taken = @(Get-NetTCPConnection -State Listen -LocalPort ([int]$port) -ErrorAction Stop).Count -gt 0
        } catch {
            # No NetTCPIP module, or nothing listening - both mean "no reason to demote".
            $taken = $false
        }
        if ($port -eq $configured) { continue }
        if ($taken) { $busy += $port } else { $free += $port }
    }
    return , @(@($configured) + $free + $busy)
}
# ==========================[ end: What the app asks ]=========================
