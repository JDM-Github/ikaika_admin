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
param(
    # WampServer's Apache. Serves the site and phpMyAdmin.
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
    Write-Host '    .\script.ps1 run-servers [-Apache] [-MySQL] [-MariaDB] [-PhpMyAdmin]'
    Write-Host '                             [-Api] [-Vite] [-All] [-Stop] [-Status]'
    Write-Host ''
    Write-Host '  No switches starts Apache and the active database - the pair the site'
    Write-Host '  cannot load without. -Status changes nothing.'
    Write-Host ''
    Write-Host '  Apache, MySQL and MariaDB are the services WampServer installed, so'
    Write-Host '  they need administrator. The API and Vite are ordinary processes, run'
    Write-Host '  as you, with their output in .ikaika_scripts_configs/logs/.'
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
$apiPort = [int](Get-ProjectValue 'serve.apiPort' '8000')
$phpMyAdminUrl = Get-ProjectValue 'serve.phpMyAdminUrl' 'http://localhost/phpmyadmin'
$startTimeout = [int](Get-ProjectValue 'serve.startTimeoutSeconds' '30')
$statePath = Join-Path $configsDir '.servers.json'
$logsDir = Get-LogsDir

if ($All) { $Apache = $MySQL = $PhpMyAdmin = $Api = $Vite = $true }

# The bare run. Written as "nothing was asked for" rather than as a default on each
# switch, because a default on a switch would make -Stop mean "stop everything except
# the two that default to on", which is not what anybody types it for.
$nothingPicked = -not ($Apache -or $MySQL -or $MariaDB -or $PhpMyAdmin -or $Api -or $Vite)
if ($nothingPicked -and -not $Stop -and -not $Status) {
    $Apache = $true
    if ($dbFlavor -eq 'mariadb') { $MariaDB = $true } else { $MySQL = $true }
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
        Service = ''; Port = $apiPort; Command = 'api'
    }
    [pscustomobject]@{
        Key = 'vite'; Label = 'Vite'; Wanted = [bool]$Vite
        Service = ''; Port = 5173; Command = 'vite'
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
    foreach ($server in $Servers) {
        if ($server.Service) {
            $status = Get-ServiceState $server.Service
            $detail = $(if ($status -eq 'absent') { "no $($server.Service) service - is WampServer installed?" }
                else { "$($server.Service)  port $($server.Port)" })
            Write-ServerLine $server.Label $status $detail
            continue
        }
        $tracked = Get-TrackedProcess $state[$server.Key]
        if ($tracked) {
            Write-ServerLine $server.Label 'running' "pid $($tracked.Id)  port $($server.Port)"
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

    foreach ($server in @($Servers | Where-Object { $nothingPicked -or $_.Wanted })) {
        if ($server.Service) {
            $status = Get-ServiceState $server.Service
            if ($status -eq 'absent') { Write-ServerLine $server.Label 'absent' 'no such service'; continue }
            if ($status -eq 'stopped') { Write-ServerLine $server.Label 'stopped' 'already'; continue }
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

# phpMyAdmin is Apache's, so asking for it asks for Apache.
if ($PhpMyAdmin) { $Apache = $true }
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
        $status = Get-ServiceState $server.Service
        if ($status -eq 'absent') {
            Write-ServerLine $server.Label 'absent' "no $($server.Service) - run development-setup"
            $failures++
            continue
        }
        if ($status -eq 'running') { Write-ServerLine $server.Label 'running' 'already'; continue }
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
                -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', "--port=$apiPort")
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
Write-Host "    http://localhost:$apiPort            the API" -ForegroundColor DarkGray
Write-Host "    $phpMyAdminUrl   the database, in a browser" -ForegroundColor DarkGray
Write-Host '    .\script.ps1 run-servers -Stop       stop what this started' -ForegroundColor DarkGray
Write-Host ''
exit $failures
