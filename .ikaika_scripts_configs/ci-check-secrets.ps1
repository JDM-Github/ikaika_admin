# Checks that every secret this repo's workflows use works, not only that it is set: the Check secrets workflow's last step, run after secrets -Ci has installed what each purpose needs.
# Each token is used the way a workflow uses it - read, then a dry-run push for the ones that push - and every file the sealed archive delivered is opened: a keystore or certificate with its password, a deploy config by logging in to its server.
# Nothing changes anywhere: a dry-run push sends nothing, and on the server only test -d runs, on the deploy folder. Tokens reach git through GIT_CONFIG_*, never a command line, and no value is printed.
# The same file in every repo with the toolkit; which secrets there are comes from secrets.json, and SYNC_OUTCOME and INSTALL_OUTCOME are the steps before it.
param(
    # Also log in to each deploy config's server over SSH, with its key and passphrase, and look for the deploy folder.
    [switch]$Connect
)

$ErrorActionPreference = 'Stop'
$configsDir = $PSScriptRoot
$repoRoot = Split-Path $configsDir -Parent
$libDir = Join-Path $repoRoot 'decoupled-script\lib'
foreach ($lib in 'shared-fetch.ps1', 'deploy-config.ps1', 'deploy-ssh.ps1', 'signing.ps1', 'certificates.ps1') { . (Join-Path $libDir $lib) }

$manifest = Get-Content -LiteralPath (Join-Path $configsDir 'secrets.json') -Raw | ConvertFrom-Json
$rows = [Collections.Generic.List[object]]::new()
function Add-Row([string]$Area, [string]$Name, [string]$State, [string]$Detail) {
    $rows.Add([pscustomobject]@{ Area = $Area; Name = $Name; State = $State; Detail = $Detail })
    $mark = @{ warn = '::warning'; fail = '::error' }[$State]
    if ($mark) { Write-Output "$mark title=$Name::$Detail" } else { Write-Output ('{0,-5} {1} - {2}' -f $State, $Name, $Detail) }
}

# git with a token for one host, handed over in GIT_CONFIG_* and taken away again; the credential helper is off so nothing asks or stores.
function Invoke-GitWithToken([string]$GitHost, [string]$User, [string]$Token, [string[]]$Arguments) {
    $env:GIT_TERMINAL_PROMPT = '0'
    $env:GIT_CONFIG_COUNT = '2'
    $env:GIT_CONFIG_KEY_0 = "url.https://$($User):$($Token)@$GitHost/.insteadOf"
    $env:GIT_CONFIG_VALUE_0 = "https://$GitHost/"
    $env:GIT_CONFIG_KEY_1 = 'credential.helper'
    $env:GIT_CONFIG_VALUE_1 = ''
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& git -C $repoRoot @Arguments 2>&1 | ForEach-Object { "$_" })
        return @{ Code = $LASTEXITCODE; Out = (($output -join ' ') -replace [regex]::Escape($Token), '***') }
    } finally {
        Remove-Item env:GIT_CONFIG_COUNT, env:GIT_CONFIG_KEY_0, env:GIT_CONFIG_VALUE_0, env:GIT_CONFIG_KEY_1, env:GIT_CONFIG_VALUE_1 -ErrorAction SilentlyContinue
    }
}

# A repo a token is for: read with ls-remote, then, when it pushes, a dry-run push of this checkout to a branch that is never made.
function Test-RepoToken([string]$Name, [string]$Url, [string]$User, [string]$Token, [bool]$Pushes, [string]$WriteNeed) {
    $plain = $Url -replace '^(https://)[^@/]+@', '$1'
    $gitHost = ([uri]$plain).Host
    $read = Invoke-GitWithToken $gitHost $User $Token @('ls-remote', '--heads', $plain)
    if ($read.Code -ne 0) {
        Add-Row 'token' $Name 'fail' "cannot read $plain - $($read.Out)"
        return
    }
    if (-not $Pushes) {
        Add-Row 'token' $Name 'ok' "reads $plain"
        return
    }
    $write = Invoke-GitWithToken $gitHost $User $Token @('push', '--dry-run', '--porcelain', $plain, 'HEAD:refs/heads/ci-secrets-check-never-made')
    if ($write.Code -eq 0) { Add-Row 'token' $Name 'ok' "reads $plain, and may push to it (a dry run; nothing was sent)" }
    else { Add-Row 'token' $Name 'warn' "reads $plain but may not push to it, so $WriteNeed - $($write.Out)" }
}

# ---------------------------------------------------------------- the steps before this one
$sync = "$env:SYNC_OUTCOME"
if ($sync -eq 'failure') { Add-Row 'step' 'Sync from Bitbucket' 'fail' 'GitHub could not be brought up to date from Bitbucket - BITBUCKET_REPO_TOKEN or GH_MIRROR_TOKEN, named in that step''s log.' }
elseif ($sync -eq 'success') { Add-Row 'step' 'Sync from Bitbucket' 'ok' 'GitHub was brought up to date from Bitbucket with BITBUCKET_REPO_TOKEN and GH_MIRROR_TOKEN.' }
Add-Row 'step' 'Toolkit fetch' 'ok' "TOOLKIT_BITBUCKET_TOKEN fetched decoupled-script at $(git -C (Join-Path $repoRoot 'decoupled-script') describe --tags --always)."
if ("$env:INSTALL_OUTCOME" -eq 'success') { Add-Row 'step' 'Sealed archive' 'ok' 'SHARED_FILES_URL served the archive, SHARED_FILES_PASSWORD opened it, and every purpose''s files matched their committed checksums.' }
else { Add-Row 'step' 'Sealed archive' 'fail' 'secrets -Ci could not set up every purpose - its tables above name the link, the password or the file.' }

# ---------------------------------------------------------------- the workflow's own tokens
$mirror = (Select-String -LiteralPath (Join-Path $repoRoot 'ikaika_scripts.env') -Pattern '^mirror\.source=(.+)$' | Select-Object -First 1).Matches.Groups[1].Value
$toolkitUrl = "$(git -C $repoRoot config -f .gitmodules --get submodule.decoupled-script.url)"
$github = "$env:GITHUB_SERVER_URL/$env:GITHUB_REPOSITORY.git"
foreach ($secret in @($manifest.secrets | Where-Object { $_.kind -eq 'workflow' })) {
    $value = [Environment]::GetEnvironmentVariable($secret.name)
    if (-not $value) {
        if ($secret.optional) { Add-Row 'token' $secret.name 'skip' 'not set, and optional' }
        else { Add-Row 'token' $secret.name 'fail' "not set. $($secret.setup)" }
        continue
    }
    switch ($secret.name) {
        'TOOLKIT_BITBUCKET_TOKEN' {
            $user = if ($env:TOOLKIT_BITBUCKET_USER) { $env:TOOLKIT_BITBUCKET_USER } else { 'x-token-auth' }
            Test-RepoToken $secret.name $toolkitUrl $user $value $false ''
        }
        'BITBUCKET_REPO_TOKEN' {
            $user = if ($env:BITBUCKET_REPO_USER) { $env:BITBUCKET_REPO_USER } else { 'x-token-auth' }
            if ($mirror) { Test-RepoToken $secret.name $mirror $user $value $true 'a Build release with a version bump cannot push its commit and tag' }
            else { Add-Row 'token' $secret.name 'warn' 'set, but ikaika_scripts.env names no mirror.source to try it on' }
        }
        'GH_MIRROR_TOKEN' { Test-RepoToken $secret.name $github 'x-access-token' $value $true 'the sync from Bitbucket cannot mirror into GitHub' }
        default { Add-Row 'token' $secret.name 'ok' 'set' }
    }
}

# ---------------------------------------------------------------- what the sealed archive delivered
function Resolve-SecretPath([string]$Path) {
    $resolved = [regex]::Replace($Path, '\{([A-Za-z0-9._]+)\}', { param($m) "$(Get-ProjectValue $m.Groups[1].Value '')" })
    if ($resolved -match '^~[\\/]') { return [IO.Path]::GetFullPath((Join-Path $HOME $resolved.Substring(2))) }
    return [IO.Path]::GetFullPath((Join-Path $repoRoot $resolved))
}
$signing = Get-SigningSecrets
$certificates = @(Get-CertificateItems $signing)
foreach ($secret in @($manifest.secrets | Where-Object { $_.kind -eq 'file' })) {
    $path = Resolve-SecretPath $secret.path
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        if ($secret.optional) { Add-Row 'file' $secret.name 'skip' 'optional, and not in the archive' }
        else { Add-Row 'file' $secret.name 'fail' "secrets -Ci did not put it at $(Split-Path -Leaf $path)" }
        continue
    }
    $item = @($certificates | Where-Object { [IO.Path]::GetFullPath($_.Path) -eq $path })[0]
    if ($item) {
        $state = Read-CertificateState $item $signing
        $found = @(Get-CertificateFindings @($state) (Get-ProjectValue 'windows.publisher' '') | Where-Object { $_.Rule -notin 'checksum-none', 'same-as-release' })
        $errors = @($found | Where-Object Severity -eq 'error')
        $until = if ($state.Facts -and $state.Facts.NotAfter -ne [datetime]::MaxValue) { ", valid until $($state.Facts.NotAfter.ToString('yyyy-MM-dd'))" } else { '' }
        $opens = if ($item.Kind -eq 'ssh') { "is in place$(if ($Connect) { ' - the logins below try it' } else { ' (connect is off, so no login tried it)' })" } else { "opens with its password$until" }
        if ($errors.Count) { Add-Row 'file' $secret.name 'fail' "$($item.Label): $($errors[0].Message)" }
        elseif ($found.Count) { Add-Row 'file' $secret.name 'warn' "$($item.Label) $opens, but $($found[0].Message)" }
        else { Add-Row 'file' $secret.name 'ok' "$($item.Label) $opens" }
        continue
    }
    $deploy = $null
    foreach ($isDebug in $true, $false) { if ([IO.Path]::GetFullPath((Get-EnvPath -Role deploy -IsDebug $isDebug)) -eq $path) { $deploy = $isDebug } }
    if ($null -eq $deploy) {
        Add-Row 'file' $secret.name 'ok' 'in place, and its committed checksum trusts it'
        continue
    }
    $target = if ($deploy) { 'staging' } else { 'production' }
    $config = Get-DeployConfig $deploy @('Hostname', 'DIR', 'SSHUsername')
    if ($config.Problem) {
        Add-Row 'file' $secret.name 'fail' "the $target deploy config: $($config.Problem[0])"
        continue
    }
    if (-not $Connect) {
        Add-Row 'file' $secret.name 'ok' "the $target deploy config names a host, a user and a folder (connect is off, so no login was tried)"
        continue
    }
    $keyName = Get-ProjectValue 'ssh.keyName' ''
    $script:SshKeyPath = if ($keyName) { Join-Path $HOME (Join-Path '.ssh' $keyName) } else { '' }
    $script:SshKnownHostsPath = Join-Path $configsDir "$keyName.known_hosts"
    if (-not $keyName -or -not (Test-Path -LiteralPath $script:SshKeyPath)) {
        Add-Row 'file' $secret.name 'fail' "the $target deploy config is fine, but there is no deploy key ($(if ($keyName) { "~/.ssh/$keyName" } else { 'ssh.keyName is blank' })) to log in with"
        continue
    }
    Assert-SshKeyPermissions $script:SshKeyPath
    $pinned = Test-Path -LiteralPath $script:SshKnownHostsPath
    $remote = ConvertTo-RemoteDir $config.Dir
    $login = Invoke-SshToolWithRetry 'ssh' "$($config.Config['SSHPASSPHRASE'])" ((Get-SshCommonArgs) + @('-o', 'BatchMode=no', "$($config.Config['SSHUsername'])@$($config.Config['Hostname'])", "test -d $remote && echo deploy-folder-found || echo deploy-folder-missing"))
    if ($login.ExitCode -ne 0) { Add-Row 'file' $secret.name 'fail' "logging in to the $target server failed (ssh exit $($login.ExitCode)) - the key, its passphrase (SSHPASSPHRASE) or the user$(if ($pinned) { ', or a host that is not the one pinned' })" }
    elseif ("$($login.Output)" -match 'deploy-folder-missing') { Add-Row 'file' $secret.name 'warn' "logs in to the $target server, but its deploy folder (DIR) is not there yet; the first deploy makes it" }
    else { Add-Row 'file' $secret.name 'ok' "logs in to the $target server with the deploy key and finds its deploy folder$(if (-not $pinned) { '; no host pin is committed, so the host was trusted on sight' })" }
}

# ---------------------------------------------------------------- the summary
$failed = @($rows | Where-Object State -eq 'fail')
$warned = @($rows | Where-Object State -eq 'warn')
$lines = @('### Secrets check', '', "$(if ($failed.Count) { "**$($failed.Count) not working**" } else { '**Every secret checked works**' })$(if ($warned.Count) { ", $($warned.Count) worth a look" }).", '', '| | Secret | What was found |', '| --- | --- | --- |')
foreach ($row in $rows) { $lines += "| $(@{ ok = 'ok'; warn = 'warn'; fail = '**fail**'; skip = 'skip' }[$row.State]) | ``$($row.Name)`` | $($row.Detail -replace '\|', '/') |" }
if ($env:GITHUB_STEP_SUMMARY) { Add-Content -LiteralPath $env:GITHUB_STEP_SUMMARY -Value ($lines + '') }
Write-Output ''
Write-Output "$($rows.Count) checked: $($failed.Count) failed, $($warned.Count) warning(s)."
exit $(if ($failed.Count) { 1 } else { 0 })
