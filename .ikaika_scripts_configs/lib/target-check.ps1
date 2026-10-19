# What a deploy target's settings are checked against, and how a fix writes them - the same file in both portal repos.
#
# Every value a fix writes is DERIVED, never guessed: the target's deploy config (<kind>.env: SITE_ROOT, PORTAL_PATH, API_PATH,
# DIR, AZURE_REDIRECT_URI), the domains cPanel serves (uapi DomainInfo domains_data over SSH - read-only), and whether the API
# answers. A domain is the one whose document root is SITE_ROOT; an API path is API_PATH under SITE_ROOT, as deploy-admin.sh reads
# it; Microsoft sign-in returns to the portal's own address, as deploy-admin.sh falls back to. What cannot be derived is reported
# as an error and left alone.
#
# Two hazards are caught as well, because both have been in a release.env copied from debug.env: an API_PATH outside its own
# SITE_ROOT, which serves production's API from another site's folder, and a production DIR that is staging's, which makes a
# production API deploy replace the staging one. The fix moves both under production's own folders.
#
# A finding is one key: where it lives, what it holds, what it should hold, and a level - ok, fix (a -Fix writes Want), warn
# (worth knowing, nothing to write), error (wrong and not derivable). A <kind>.env written by a fix gets its .sha256 sidecar
# rewritten, so this checkout trusts it; the sealed archive still holds the old copy until secrets -Export and -Seal.
# Requires lib\shared-fetch.ps1 (which brings lib\load-env.ps1), lib\deploy-ssh.ps1 and lib\deploy-config.ps1.

function New-TargetFinding([string]$Target, [string]$Where, [string]$Key, [string]$Have, [string]$Want, [string]$Level, [string]$Note = '') {
    return [pscustomobject]@{ Target = $Target; Where = $Where; Key = $Key; Have = $Have; Want = $Want; Level = $Level; Note = $Note }
}

# The path below Root, as Root/a/b gives a/b; '' for Root itself and $null when Path is not under it.
function Get-PathUnder([string]$Path, [string]$Root) {
    $p = "$Path".Trim().Trim('/')
    $r = "$Root".Trim().Trim('/')
    if (-not $p -or -not $r) { return $null }
    if ($p -eq $r) { return '' }
    if ($p.StartsWith("$r/")) { return $p.Substring($r.Length + 1) }
    return $null
}

# The target's deploy config as read for a deploy, whatever it lacks; Problem says when there is none to read.
function Get-TargetDeployFacts([bool]$IsDebug) {
    $deploy = Get-DeployConfig $IsDebug @()
    $name = if ($IsDebug) { 'debug.env' } else { 'release.env' }
    if ($deploy.Problem) { return [pscustomobject]@{ Name = $name; Path = $deploy.Path; Config = @{}; Problem = "$($deploy.Problem[0])" } }
    return [pscustomobject]@{ Name = $name; Path = $deploy.Path; Config = $deploy.Config; Problem = '' }
}

# The domains cPanel serves on the target's host, each with its document root relative to the home folder. Read-only.
function Get-ServedDomains($Config) {
    $keyName = Get-ProjectValue 'ssh.keyName' ''
    if (-not $keyName) { throw 'ssh.keyName is blank in ikaika_scripts.env.' }
    $script:SshKeyPath = Join-Path $HOME (Join-Path '.ssh' $keyName)
    $script:SshKnownHostsPath = Join-Path (Get-ConfigsDir) ($keyName + '.known_hosts')
    if (-not (Test-Path -LiteralPath $script:SshKeyPath)) { throw "There is no SSH key at $script:SshKeyPath - .\script.ps1 secrets -For deploy-staging installs it." }
    Assert-SshKeyPermissions $script:SshKeyPath
    $result = Invoke-Ssh $Config['Hostname'] $Config['SSHUsername'] "$($Config['SSHPASSPHRASE'])" 'echo "HOME=$HOME"; uapi --output=json DomainInfo domains_data' 6>$null
    $lines = @("$($result.Output)" -split "`r?`n")
    $homeLine = $lines | Where-Object { $_ -match '^\s*HOME=' } | Select-Object -First 1
    $json = $lines | Where-Object { $_ -match '^\s*\{' } | Select-Object -First 1
    if (-not $homeLine -or -not $json) { throw "The server did not list its domains (ssh exit $($result.ExitCode))." }
    $homeDir = ($homeLine -replace '^\s*HOME=', '').Trim().TrimEnd('/')
    $data = ($json | ConvertFrom-Json).result.data
    return @(@($data.main_domain) + @($data.addon_domains) + @($data.sub_domains) | Where-Object { $_ -and $_.domain -and $_.documentroot } | ForEach-Object {
            [pscustomobject]@{ Domain = "$($_.domain)"; Root = "$(Get-PathUnder "$($_.documentroot)" $homeDir)" }
        })
}

# The address a site root is served at: a domain whose document root it is, a real one before the host's own *.mybluehost.me.
function Select-SiteOrigin([object[]]$Domains, [string]$SiteRoot) {
    $root = "$SiteRoot".Trim().Trim('/')
    $served = @($Domains | Where-Object { $_.Root -eq $root } | Sort-Object @{ Expression = { $_.Domain -like '*.mybluehost.me' } }, @{ Expression = { $_.Domain.Length } })
    if (-not $served.Count) { return '' }
    return "https://$($served[0].Domain)"
}

# The status a GET answers with, 0 when nothing answers.
function Get-HttpStatus([string]$Url) {
    try { return [int](Invoke-WebRequest -Uri $Url -TimeoutSec 20 -SkipHttpErrorCheck -UseBasicParsing -Method Get).StatusCode } catch { return 0 }
}

# Findings on the deploy config itself, common to both repos: its folders under its own site root, production's DIR its own,
# and where Microsoft sign-in returns. Origin is the derived address of SITE_ROOT, '' when it could not be read.
function Get-DeployConfigFindings($Facts, $Other, [string]$Target, [string]$Origin) {
    $config = $Facts.Config
    $findings = [Collections.Generic.List[object]]::new()
    $siteRoot = "$($config['SITE_ROOT'])".Trim().Trim('/')
    foreach ($key in 'SITE_ROOT', 'PORTAL_PATH', 'API_PATH', 'DIR') {
        if (-not "$($config[$key])".Trim()) { $findings.Add((New-TargetFinding $Target $Facts.Name $key '' '' 'error' "blank in $($Facts.Name); it comes from the server's layout and cannot be derived")) }
    }
    if (-not $siteRoot) { return $findings.ToArray() }

    $apiPath = "$($config['API_PATH'])".Trim().Trim('/')
    if ($apiPath) {
        if ($null -eq (Get-PathUnder $apiPath $siteRoot)) {
            $want = "$siteRoot/$(Split-Path -Leaf $apiPath)"
            $findings.Add((New-TargetFinding $Target $Facts.Name 'API_PATH' $apiPath $want 'fix' "outside SITE_ROOT, so this target's API would be another site's"))
        } else { $findings.Add((New-TargetFinding $Target $Facts.Name 'API_PATH' $apiPath $apiPath 'ok')) }
    }
    $portalPath = "$($config['PORTAL_PATH'])".Trim().Trim('/')
    if ($portalPath -and $null -eq (Get-PathUnder $portalPath $siteRoot)) {
        $findings.Add((New-TargetFinding $Target $Facts.Name 'PORTAL_PATH' $portalPath '' 'error' "outside SITE_ROOT $siteRoot, so no address serves it"))
    }
    # Production's own DIR: sharing staging's makes a production API deploy replace the staging API.
    $dir = "$($config['DIR'])".Trim().Trim('/')
    $otherDir = "$($Other.Config['DIR'])".Trim().Trim('/')
    if ($Target -eq 'production' -and $dir -and $dir -eq $otherDir) {
        $findings.Add((New-TargetFinding $Target $Facts.Name 'DIR' $dir 'apps/production' 'fix' "staging's own DIR - a production deploy would replace staging"))
    } elseif ($dir) { $findings.Add((New-TargetFinding $Target $Facts.Name 'DIR' $dir $dir 'ok')) }

    $redirect = "$($config['AZURE_REDIRECT_URI'])".Trim()
    $portalUnder = if ($portalPath) { Get-PathUnder $portalPath $siteRoot } else { $null }
    $wantRedirect = if ($Origin -and $null -ne $portalUnder) { "$Origin$(if ($portalUnder) { "/$portalUnder" })" } else { '' }
    if (-not $redirect) {
        $level = if ($wantRedirect) { 'fix' } else { 'error' }
        $findings.Add((New-TargetFinding $Target $Facts.Name 'AZURE_REDIRECT_URI' '' $wantRedirect $level 'the portal''s own address; the Azure app must register it'))
    } elseif ($wantRedirect -and $redirect.TrimEnd('/') -ne $wantRedirect) {
        $findings.Add((New-TargetFinding $Target $Facts.Name 'AZURE_REDIRECT_URI' $redirect $wantRedirect 'warn' 'not the portal''s own address - right only if the Azure app registers this one'))
    } else { $findings.Add((New-TargetFinding $Target $Facts.Name 'AZURE_REDIRECT_URI' $redirect $redirect 'ok')) }
    return $findings.ToArray()
}

# One KEY=value line rewritten in place, or added, in a deploy config; its .sha256 sidecar follows, so this checkout trusts it.
function Set-DeployConfigValue([string]$Path, [string]$Key, [string]$Value) {
    $lines = [Collections.Generic.List[string]]::new()
    if (Test-Path -LiteralPath $Path) { $lines.AddRange([string[]]@(Get-Content -LiteralPath $Path)) }
    $index = -1
    for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match "^\s*$([regex]::Escape($Key))\s*=") { $index = $i; break } }
    if ($index -ge 0) { $lines[$index] = "$Key=$Value" } else { $lines.Add("$Key=$Value") }
    $newline = if ((Test-Path -LiteralPath $Path) -and -not [IO.File]::ReadAllText($Path).Contains("`r`n")) { "`n" } else { "`r`n" }
    [IO.File]::WriteAllText($Path, (($lines -join $newline) + $newline), [Text.UTF8Encoding]::new($false))
    $sidecar = "$Path.sha256"
    if (Test-Path -LiteralPath $sidecar) {
        [IO.File]::WriteAllText($sidecar, "$((Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLower())  $(Split-Path -Leaf $Path)`n", [Text.UTF8Encoding]::new($false))
    }
}

# One key=value line in ikaika_scripts.env rewritten in place, or added after the last line of its family - release.serverEnv.* for
# release.serverEnv.2, else release.*.
function Set-ProjectConfigLine([string]$Path, [string]$Key, [string]$Value) {
    $lines = [Collections.Generic.List[string]]::new()
    $lines.AddRange([string[]]@(Get-Content -LiteralPath $Path))
    $index = -1
    for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match "^$([regex]::Escape($Key))=") { $index = $i; break } }
    if ($index -ge 0) {
        $lines[$index] = "$Key=$Value"
    } else {
        $parts = $Key -split '\.'
        $after = -1
        foreach ($family in @(($parts[0..($parts.Count - 2)] -join '.'), $parts[0]) | Select-Object -Unique) {
            for ($i = 0; $i -lt $lines.Count; $i++) { if ($lines[$i] -match "^$([regex]::Escape($family))\.") { $after = $i } }
            if ($after -ge 0) { break }
        }
        if ($after -ge 0) { $lines.Insert($after + 1, "$Key=$Value") } else { $lines.Add("$Key=$Value") }
    }
    $newline = if ([IO.File]::ReadAllText($Path).Contains("`r`n")) { "`r`n" } else { "`n" }
    [IO.File]::WriteAllText($Path, (($lines -join $newline) + $newline), [Text.UTF8Encoding]::new($false))
}

# The findings as a table, one target after another, and the counts the caller exits on.
function Write-TargetFindings([object[]]$Findings) {
    $colors = @{ ok = 'DarkGray'; fix = 'Yellow'; warn = 'Yellow'; error = 'Red'; info = 'DarkGray' }
    foreach ($target in @($Findings | ForEach-Object Target | Select-Object -Unique)) {
        Write-Host ''
        Write-Host "  $target" -ForegroundColor Cyan
        foreach ($f in @($Findings | Where-Object Target -eq $target)) {
            $shown = if ($f.Level -eq 'fix') { "$(if ($f.Have) { $f.Have } else { '(blank)' }) -> $($f.Want)" } elseif ($f.Have) { $f.Have } else { '(blank)' }
            Write-Host ("    {0,-5} {1,-40} {2}" -f $f.Level, "$($f.Where) $($f.Key)", $shown) -ForegroundColor $colors[$f.Level]
            if ($f.Note -and $f.Level -ne 'ok') { Write-Host ("          {0}" -f $f.Note) -ForegroundColor DarkGray }
        }
    }
    Write-Host ''
}
