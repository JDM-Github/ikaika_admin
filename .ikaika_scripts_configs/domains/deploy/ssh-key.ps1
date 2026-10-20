# Makes the bluehost SSH alias the deployment scripts use answer without a passphrase, here or on a CI runner.
#
# At a desk it loads ~/.ssh/<ssh.keyName> into ssh-agent once, so a deploy asks for the passphrase a single time - the
# console's "Add SSH key". On a CI runner, where nobody types one, it writes the alias itself from the target's deploy
# config (debug.env or release.env, restored by secrets -For deploy-<target> -Ci): the host and user from it, a copy of
# the key with SSHPASSPHRASE taken off (through SSH_ASKPASS, never on a command line), and the host key pinned in the
# committed <ssh.keyName>.known_hosts. Either way it ends by connecting once, so a key that does not open fails here.
#
#     .\script.ps1 deploy/ssh-key                          the key into the agent
#     .\script.ps1 deploy/ssh-key -Target production       on a runner: production's alias
param(
    # On a CI runner, the server the alias reaches; at a desk the key is the same for both.
    [ValidateSet('staging', 'production')]
    [string]$Target = 'staging',
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/ssh-key - the bluehost SSH alias, answering without a passphrase.

  Usage
    .\script.ps1 deploy/ssh-key [-Target staging|production]

  At a desk: loads ~/.ssh/<ssh.keyName> into ssh-agent. On a CI runner: writes
  the bluehost alias from the target's deploy config, with the key unlocked by
  its SSHPASSPHRASE and the committed host key pinned. Then connects once.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
. (Join-Path (Get-ForwardPath 'decoupled-script\lib') 'deploy-ssh.ps1')
. (Join-Path (Get-ForwardPath 'decoupled-script\lib') 'deploy-config.ps1')

$alias = 'bluehost'
$keyName = "$(Get-ProjectValue 'ssh.keyName' 'bluehost')".Trim()
$keyPath = Join-Path $HOME (Join-Path '.ssh' $keyName)
if (-not (Test-Path -LiteralPath $keyPath)) {
    Write-Host "No SSH key at $keyPath. Restore it with .\script.ps1 secrets -For deploy-$Target." -ForegroundColor Red
    exit 1
}

function Test-AliasAnswers {
    & ssh -o BatchMode=yes -o ConnectTimeout=20 $alias true 2>$null
    return $LASTEXITCODE -eq 0
}

if ($env:CI -eq 'true') {
    $deploy = Get-DeployConfig ($Target -eq 'staging') @('Hostname', 'SSHUsername', 'SSHPASSPHRASE')
    if ($deploy.Problem.Count) {
        $deploy.Problem | ForEach-Object { Write-Host $_ -ForegroundColor Red }
        exit 1
    }
    $cfg = $deploy.Config
    $unlocked = "$keyPath.unlocked"
    Copy-Item -LiteralPath $keyPath -Destination $unlocked -Force
    if ($IsWindows) { Assert-SshKeyPermissions $unlocked } else { & chmod 600 $unlocked }
    $helper = New-SshAskpassHelper "$($cfg['SSHPASSPHRASE'])"
    try {
        $env:SSH_ASKPASS = $helper.BatPath
        $env:SSH_ASKPASS_REQUIRE = 'force'
        & ssh-keygen -p -q -N '' -f $unlocked 2>&1 | Out-Null
        $unlockedOk = $LASTEXITCODE -eq 0
    } finally {
        Remove-Item Env:SSH_ASKPASS, Env:SSH_ASKPASS_REQUIRE -ErrorAction SilentlyContinue
        Remove-SshAskpassHelper $helper
    }
    if (-not $unlockedOk) {
        Remove-Item -LiteralPath $unlocked -Force -ErrorAction SilentlyContinue
        Write-Host "The key at $keyPath did not open with the deploy config's SSHPASSPHRASE." -ForegroundColor Red
        exit 1
    }
    $knownHosts = Join-Path (Get-ConfigsDir) "$keyName.known_hosts"
    $sshConfig = Join-Path $HOME '.ssh/config'
    $block = @(
        "Host $alias",
        "    HostName $($cfg['Hostname'])",
        "    User $($cfg['SSHUsername'])",
        "    IdentityFile $($unlocked -replace '\\', '/')",
        '    IdentitiesOnly yes',
        "    UserKnownHostsFile $($knownHosts -replace '\\', '/')",
        '    StrictHostKeyChecking accept-new'
    )
    $existing = if (Test-Path -LiteralPath $sshConfig) { Get-Content -LiteralPath $sshConfig } else { @() }
    if ($existing -match "^Host\s+$alias\s*$") {
        Write-Host "~/.ssh/config already names $alias; it is left as it is." -ForegroundColor Yellow
    } else {
        Add-Content -LiteralPath $sshConfig -Value (@('') + $block)
    }
} else {
    & ssh-add -l *> $null
    if ($LASTEXITCODE -ne 0 -or -not (Test-AliasAnswers)) {
        $agent = if ($IsWindows) { Get-Service ssh-agent -ErrorAction SilentlyContinue } else { $null }
        if ($IsWindows -and ($null -eq $agent -or $agent.Status -ne 'Running')) {
            Write-Host 'ssh-agent is not running. In an admin PowerShell:' -ForegroundColor Red
            Write-Host '  Set-Service ssh-agent -StartupType Automatic; Start-Service ssh-agent'
            exit 1
        }
        if (Test-Unattended) {
            Write-Host "The agent does not hold $keyPath, and nobody is here to type its passphrase." -ForegroundColor Red
            exit 1
        }
        & ssh-add $keyPath
        if ($LASTEXITCODE -ne 0) { exit 1 }
    }
}

if (-not (Test-AliasAnswers)) {
    Write-Host "$alias does not answer with this key. Check the Host $alias entry in ~/.ssh/config, and which key ssh.keyName names." -ForegroundColor Red
    exit 1
}
Write-Host "$alias answers without a passphrase." -ForegroundColor Green
