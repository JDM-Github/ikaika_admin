# Checks that staging and production can be built and deployed, and fills in what is missing from what is already known - the
# checker build-release consults before it refuses a target, and fixes with when asked (-FixTarget, or its prompt).
#
# A target's keys in ikaika_scripts.env - <kind>.assetUrl, the <kind>.serverEnv lines deploy-admin.sh reads (SITE_DIR, SITE_URL,
# API_CHANNEL) and <kind>.healthUrl - are held against its deploy config (<kind>.env) and the server
# (.ikaika_scripts_configs/lib/target-check.ps1, the same file as the portal's): the domain cPanel serves SITE_ROOT at, API_PATH
# under it, and the API's channel - the one whose health answers, else staging's or production's by name. The deploy config is
# checked too: its folders under its own site root, production's DIR its own, and where sign-in returns.
#
# -Fix writes every value that can be derived, and nothing that cannot; a value already set that differs is reported, never
# overwritten. A <kind>.env it changes is trusted here at once, and reaches the workflow once secrets -Export and -Seal pack it.
# -Offline checks the files against each other without SSH: the domain and the API are then taken as configured.
#
#     .\script.ps1 check-targets                          both targets, with the server
#     .\script.ps1 check-targets -Target production -Fix  production, and what can be derived filled in
#     .\script.ps1 check-targets -Offline                 the files only
param(
    # Which target to check: staging (debug.*), production (release.*), or both.
    [ValidateSet('all', 'staging', 'production')]
    [string]$Target = 'all',
    # Write every value that can be derived.
    [switch]$Fix,
    # Check the files against each other only; no SSH to the server and no request to the API.
    [switch]$Offline,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'
$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent
$libDir = Join-Path $repoRoot 'decoupled-script\lib'
foreach ($lib in 'shared-fetch.ps1', 'deploy-ssh.ps1', 'deploy-config.ps1') { . (Join-Path $libDir $lib) }
. (Join-Path $configsDir 'lib\target-check.ps1')

if ($Help) {
    Write-Host @'

  check-targets - can staging and production be built and deployed, and what is missing.

  Usage
    .\script.ps1 check-targets [-Target all|staging|production] [-Fix] [-Offline]

  Each target's assetUrl, serverEnv lines and healthUrl in ikaika_scripts.env
  are held against its deploy config (debug.env, release.env) and the server:
  the domain that serves SITE_ROOT, the API folder under it, and the API
  channel whose health answers. -Fix writes what can be derived; a value
  already set is reported, never overwritten. A deploy config it changes
  needs .\script.ps1 secrets -Export, then -Seal, before the workflow sees it.

'@
    exit 0
}

$configPath = Join-Path $repoRoot 'ikaika_scripts.env'
$facts = @{ debug = Get-TargetDeployFacts $true; release = Get-TargetDeployFacts $false }
$domainsByHost = @{}
$online = -not $Offline

function Get-ConfigValue([string]$Key) { return "$(Get-ProjectValue $Key '')".Trim() }

# The target's serverEnv lines as name -> number and value, and the numbers free for new ones: a blank line first, then the next.
function Get-ServerEnvLines([string]$Kind) {
    $byName = [ordered]@{}
    $blank = [Collections.Generic.List[int]]::new()
    $highest = 0
    for ($i = 1; $i -le 50; $i++) {
        $line = Get-ConfigValue "$Kind.serverEnv.$i"
        $exists = @(Get-Content -LiteralPath $configPath | Where-Object { $_ -match "^$([regex]::Escape("$Kind.serverEnv.$i"))=" }).Count
        if (-not $exists) { continue }
        $highest = $i
        if (-not $line) { $blank.Add($i); continue }
        if ($line -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { $byName[$Matches[1]] = @{ Number = $i; Value = $Matches[2] } }
    }
    return @{ ByName = $byName; Free = $blank; Next = $highest + 1 }
}

function Get-ApiTargetFindings([string]$Kind) {
    $name = if ($Kind -eq 'debug') { 'staging' } else { 'production' }
    $f = $facts[$Kind]
    $other = $facts[$(if ($Kind -eq 'debug') { 'release' } else { 'debug' })]
    if ($f.Problem) { return @(New-TargetFinding $name $f.Name '' '' '' 'error' $f.Problem) }
    $cfg = $f.Config
    $siteRoot = "$($cfg['SITE_ROOT'])".Trim().Trim('/')
    $lines = Get-ServerEnvLines $Kind
    $haveUrl = if ($lines.ByName.Contains('SITE_URL')) { "$($lines.ByName['SITE_URL'].Value)".TrimEnd('/') } else { '' }

    $origin = ''
    $originNote = 'the server was not asked (-Offline)'
    if ($online) {
        try {
            $hostName = "$($cfg['Hostname'])"
            if (-not $domainsByHost.ContainsKey($hostName)) { $domainsByHost[$hostName] = @(Get-ServedDomains $cfg) }
            $origin = Select-SiteOrigin $domainsByHost[$hostName] $siteRoot
            $originNote = if ($origin) { "the domain cPanel serves $siteRoot at" } else { "no domain on the server serves $siteRoot" }
        } catch { $originNote = "the server's domains could not be read: $($_.Exception.Message)" }
    }
    $base = if ($origin) { $origin } else { $haveUrl }
    $findings = [Collections.Generic.List[object]]::new()
    $deployFindings = @(Get-DeployConfigFindings $f $other $name $base)
    $findings.AddRange([object[]]$deployFindings)

    $apiFinding = @($deployFindings | Where-Object { $_.Key -eq 'API_PATH' })[0]
    $apiFolder = if ($apiFinding -and $apiFinding.Want) { Get-PathUnder $apiFinding.Want $siteRoot } else { $null }
    $apiPath = if ($apiFolder) { "/$apiFolder" } else { '' }
    $named = if ($Kind -eq 'debug') { 'staging' } else { 'production' }
    $haveChannel = if ($lines.ByName.Contains('API_CHANNEL')) { "$($lines.ByName['API_CHANNEL'].Value)" } else { '' }
    $channel = if ($haveChannel) { $haveChannel } else { $named }
    $answers = $false
    if ($online -and $base -and $apiPath) {
        foreach ($candidate in @($haveChannel, $named) | Where-Object { $_ } | Select-Object -Unique) {
            if ((Get-HttpStatus "$base$apiPath/api/$candidate/portal/health") -eq 200) { $channel = $candidate; $answers = $true; break }
        }
    }
    $quiet = if ($answers) { '' } else { "it does not answer yet - deploy the API to $name first" }

    # A plain key: ok when it holds what is derived, fix when it is blank and something is, warn when it holds something else.
    $judge = {
        param([string]$Key, [string]$Want, [string]$Note)
        $current = Get-ConfigValue $Key
        if ($current -eq $Want) { return (New-TargetFinding $name 'ikaika_scripts.env' $Key $current $Want 'ok') }
        if (-not $current -and $Want) { return (New-TargetFinding $name 'ikaika_scripts.env' $Key '' $Want 'fix' $Note) }
        if (-not $current) { return (New-TargetFinding $name 'ikaika_scripts.env' $Key '' '' 'error' $Note) }
        if (-not $Want) { return (New-TargetFinding $name 'ikaika_scripts.env' $Key $current '' 'warn' $Note) }
        return (New-TargetFinding $name 'ikaika_scripts.env' $Key $current $Want 'warn' "not what is derived ($Want); kept, since it is set")
    }
    $findings.Add((& $judge "$Kind.assetUrl" $apiPath 'API_PATH under SITE_ROOT, the folder the API is served from'))

    # A serverEnv line, numbered: the one already naming it, else a blank line, else the next number.
    $free = [Collections.Generic.Queue[int]]::new([int[]]@($lines.Free))
    $next = $lines.Next
    foreach ($entry in @(@('SITE_DIR', (Split-Path -Leaf $siteRoot), "SITE_ROOT's own folder"), @('SITE_URL', $base, $originNote), @('API_CHANNEL', $channel, $(if ($answers) { 'the channel whose health answers' } else { "the API's channel by name; $quiet" })))) {
        $variable, $want, $note = $entry
        if ($lines.ByName.Contains($variable)) {
            $line = $lines.ByName[$variable]
            $key = "$Kind.serverEnv.$($line.Number)"
            if ($line.Value -eq $want) { $findings.Add((New-TargetFinding $name 'ikaika_scripts.env' $key "$variable=$($line.Value)" "$variable=$want" $(if ($variable -eq 'API_CHANNEL' -and -not $answers) { 'warn' } else { 'ok' }) $note)) }
            else { $findings.Add((New-TargetFinding $name 'ikaika_scripts.env' $key "$variable=$($line.Value)" "$variable=$want" 'warn' "not what is derived ($want); kept, since it is set")) }
            continue
        }
        $number = if ($free.Count) { $free.Dequeue() } else { $next; $next++ }
        $level = if ($want) { 'fix' } else { 'error' }
        $findings.Add((New-TargetFinding $name 'ikaika_scripts.env' "$Kind.serverEnv.$number" '' $(if ($want) { "$variable=$want" } else { '' }) $level $note))
    }
    $health = if ($base -and $apiPath) { "$base$apiPath/api/$channel/portal/health" } else { '' }
    $healthFinding = & $judge "$Kind.healthUrl" $health "the API's health, where the deploy checks it$(if ($quiet) { "; $quiet" })"
    if ($healthFinding.Level -eq 'ok' -and $quiet) { $healthFinding.Level = 'warn'; $healthFinding.Note = $quiet }
    $findings.Add($healthFinding)
    return $findings.ToArray()
}

$kinds = switch ($Target) { 'staging' { @('debug') } 'production' { @('release') } default { @('debug', 'release') } }
$findings = @(foreach ($kind in $kinds) { Get-ApiTargetFindings $kind })
Write-TargetFindings $findings

if ($Fix) {
    $changedEnv = @()
    foreach ($finding in @($findings | Where-Object { $_.Level -eq 'fix' })) {
        if ($finding.Where -eq 'ikaika_scripts.env') {
            Set-ProjectConfigLine $configPath $finding.Key $finding.Want
        } else {
            $kind = if ($finding.Where -eq 'debug.env') { 'debug' } else { 'release' }
            Set-DeployConfigValue $facts[$kind].Path $finding.Key $finding.Want
            $changedEnv += $finding.Where
        }
        Write-Host "  wrote  $($finding.Where) $($finding.Key)=$($finding.Want)" -ForegroundColor Green
    }
    if (@($findings | Where-Object { $_.Level -eq 'fix' }).Count -eq 0) { Write-Host '  Nothing to fill in.' -ForegroundColor DarkGray }
    foreach ($name in @($changedEnv | Select-Object -Unique)) {
        Write-Host "  $name changed: .\script.ps1 secrets -Export packs it into the archive here, and -Seal makes the copy the workflow reads." -ForegroundColor Yellow
    }
    $script:ProjectConfig = $null
    $facts = @{ debug = Get-TargetDeployFacts $true; release = Get-TargetDeployFacts $false }
    $findings = @(foreach ($kind in $kinds) { Get-ApiTargetFindings $kind })
    Write-Host '  After the fix:'
    Write-TargetFindings $findings
}

$open = @($findings | Where-Object { $_.Level -in 'fix', 'error' })
if ($open.Count) {
    Write-Host "  $($open.Count) setting(s) to $(if (@($open | Where-Object Level -eq 'fix').Count) { 'fill in - .\script.ps1 check-targets -Fix writes what can be derived' } else { 'set by hand' })." -ForegroundColor Yellow
    exit 1
}
Write-Host '  Every checked target can be built.' -ForegroundColor Green
exit 0
