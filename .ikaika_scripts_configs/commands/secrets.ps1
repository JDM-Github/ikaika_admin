# Checks and sets up the secrets this repo's commands and GitHub workflows need, as listed in ../secrets.json.
# Locally a missing one starts its setup: fetched from the team share where one is configured, otherwise asked for.
# -Seal packs every file and value into this repo's 7-Zip archive, AES-256 with its file names hidden, for the shared folder link.
# In GitHub Actions, or with -Ci, nothing is ever asked: the archive is fetched, opened with its password, and only what the job needs is installed.
# Every file is checked against its committed checksum, every value is masked in the job's log, and nothing is printed or kept in a transcript.
#
# LOCALLY THE SAME ARCHIVE LIVES IN THE REPO, gitignored: secrets.json's archive.local, or -ArchiveFile. Every run looks for it - the status says
# whether it is there and what it holds - and -For takes a missing or untrusted secret from it before the team share or a prompt. -Import puts
# everything it holds in place, and -Export packs this machine's files and values back into it, so an update is one -Export here and one -Import
# on every other machine. It opens with the group's archive password, kept for this Windows account; nothing it holds is printed.
#
#     .\script.ps1 secrets                           every secret, whether this machine has it, and the archive in the repo
#     .\script.ps1 secrets -For deploy-staging       set up what a staging deploy needs, from the archive in the repo first
#     .\script.ps1 secrets -Import                   everything the archive in the repo holds, put in place
#     .\script.ps1 secrets -Export                   this machine's files and values, packed into the archive in the repo
#     .\script.ps1 secrets -Seal                     seal this repo's files and values, ready to upload
#     .\script.ps1 secrets -For deploy-staging -Ci   the same from the sealed archive, never asking
#     .\script.ps1 secrets -GitHub                   copy the archive password to the clipboard for GitHub

# Advanced binding, so a mistyped switch is refused instead of silently ignored.
[CmdletBinding()]
param(
    # Which job to prepare, from secrets.json; omitted lists every secret and changes nothing.
    [string]$For = '',
    # Never ask: open the sealed archive and fail by name when anything is missing; automatic in GitHub Actions.
    [switch]$Ci,
    # Pack every file and value secrets.json lists into this repo's encrypted archive, ready to upload to the shared folder.
    [switch]$Seal,
    # Where -Seal writes the archive; omitted, a folder in Downloads that every repo in the same group seals into.
    [string]$OutDir = '',
    # The sealed archive kept in the repo, gitignored, that -For looks in first, -Import opens and -Export writes; omitted, archive.local in secrets.json.
    [string]$ArchiveFile = '',
    # Put every file and value the archive in the repo holds where the toolkit reads it, replacing a copy that differs; a file only when its committed checksum trusts it.
    [switch]$Import,
    # Pack this machine's files and values into the archive in the repo, replacing it - after an update, so every other machine can -Import it.
    [switch]$Export,
    # Copy each GitHub secret this machine keeps to the clipboard in turn, ready to paste into the repository's settings.
    [switch]$GitHub,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'

$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent
$libDir = Join-Path $repoRoot 'decoupled-script\lib'

. (Join-Path $libDir 'shared-fetch.ps1')
. (Join-Path $libDir 'toolkit-version.ps1')
. (Join-Path $libDir 'deploy-ssh.ps1')

if ($Help) {
    Write-Host ''
    Write-Host '  secrets - check and set up what this repo''s commands and workflows need.'
    Write-Host ''
    Write-Host '  Usage'
    Write-Host '    .\script.ps1 secrets [-For <job>] [-Ci] [-Import | -Export] [-ArchiveFile <file>]'
    Write-Host '                         [-Seal [-OutDir <folder>]] [-GitHub]'
    Write-Host ''
    Write-Host '  Without -For it lists every secret in .ikaika_scripts_configs/secrets.json and'
    Write-Host '  whether this machine has it. With -For it sets up what that job needs: a missing'
    Write-Host '  file is fetched from the team share where one is configured, and a missing value'
    Write-Host '  is asked for, masked, and saved where the toolkit reads it.'
    Write-Host ''
    Write-Host '  Examples'
    Write-Host '    .\script.ps1 secrets'
    Write-Host '        Every secret secrets.json lists, whether this machine has it, and'
    Write-Host '        whether the archive in the repo is there and has it. Changes nothing.'
    Write-Host ''
    Write-Host '    .\script.ps1 secrets -For deploy-staging'
    Write-Host '        Sets up what a staging deploy needs: a missing file, or one its'
    Write-Host '        checksum does not trust, comes from the archive in the repo, else the'
    Write-Host '        team share; a missing value from the archive, else a prompt.'
    Write-Host ''
    Write-Host '    .\script.ps1 secrets -Import'
    Write-Host '        Everything the archive in the repo holds, put where the toolkit reads'
    Write-Host '        it - after someone has -Exported an update. A file goes in only when'
    Write-Host '        its committed checksum trusts the archive''s copy.'
    Write-Host ''
    Write-Host '    .\script.ps1 secrets -Export'
    Write-Host '        This machine''s files and values, packed into the archive in the repo,'
    Write-Host '        replacing it. Git must ignore it; send it to the team the usual way.'
    Write-Host ''
    Write-Host '    .\script.ps1 secrets -Import -ArchiveFile C:\path\to\other.7z'
    Write-Host '        The same from an archive somewhere else, for one run; archive.local in'
    Write-Host '        secrets.json changes where it is looked for every time.'
    Write-Host ''
    Write-Host '    .\script.ps1 secrets -Seal'
    Write-Host '        Packs every file and value into this repo''s archive in Downloads,'
    Write-Host '        replacing the one there; upload it to replace the copy CI opens.'
    Write-Host ''
    Write-Host '    .\script.ps1 secrets -Seal -OutDir C:\path\to\folder'
    Write-Host '        The same, written to that folder instead of Downloads.'
    Write-Host ''
    Write-Host '    .\script.ps1 secrets -For deploy-staging -Ci'
    Write-Host '        What CI runs: never asks, takes what a staging deploy needs from the'
    Write-Host '        sealed archive, and writes each file over any copy already there.'
    Write-Host ''
    Write-Host '    .\script.ps1 secrets -GitHub'
    Write-Host '        Copies the archive password to the clipboard for GitHub''s Actions'
    Write-Host '        secrets, and says how to make the rest. Needs someone at a console.'
    Write-Host ''
    Write-Host '  -Seal packs every file and value into this repo''s 7-Zip archive, AES-256 with'
    Write-Host '  its file names hidden, and writes it to a folder in Downloads that every repo in'
    Write-Host '  the same group shares. Upload that folder''s archives to the shared folder link.'
    Write-Host '  The password is made on first use and kept, encrypted, for your Windows account.'
    Write-Host '  It needs 7-Zip: winget install --id 7zip.7zip.'
    Write-Host ''
    Write-Host '  -Ci never asks. The archive is downloaded from the link secret, opened with the'
    Write-Host '  password secret, and only what the job needs is written where the toolkit reads'
    Write-Host '  it, checked against its committed checksum and exported to the steps after it.'
    Write-Host '  A missing or wrong one fails the step by name. Automatic when GITHUB_ACTIONS or'
    Write-Host '  CI is true.'
    Write-Host ''
    Write-Host '  The archive in the repo is .ikaika_scripts_configs/<repo>.7z unless secrets.json''s'
    Write-Host '  archive.local or -ArchiveFile says otherwise. It is the same archive -Seal makes,'
    Write-Host '  opened with the same password, and git must ignore it.'
    Write-Host ''
    Write-Host '  -GitHub copies the archive password to the clipboard, for pasting into'
    Write-Host '  Settings > Secrets and variables > Actions, and says how to make the rest. It'
    Write-Host '  needs a console, and never prints a value.'
    Write-Host ''
    exit 0
}

$manifestPath = Join-Path $configsDir 'secrets.json'
if (-not (Test-Path -LiteralPath $manifestPath)) {
    Write-Host ''
    Write-Host "  There is no secrets.json at $manifestPath." -ForegroundColor Red
    Write-Host ''
    exit 1
}
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$all = @($manifest.secrets)
$purposes = @($manifest.purposes.PSObject.Properties.Name)
$sealedKinds = @('file', 'value')
$archiveName = "$($manifest.archive.name)"
$group = "$($manifest.archive.group)"
if (-not $group) { $group = [IO.Path]::GetFileNameWithoutExtension($archiveName) }
$linkEntry = $all | Where-Object { $_.kind -eq 'link' } | Select-Object -First 1
$localName = "$($manifest.archive.local)"
if (-not $localName) { $localName = ".ikaika_scripts_configs/$(Split-Path -Leaf $repoRoot).7z" }
$localArchive = if ($ArchiveFile) { $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($ArchiveFile) } else { Join-Path $repoRoot $localName }
$passwordEntry = $all | Where-Object { $_.kind -eq 'password' } | Select-Object -First 1
$script:sealedWork = ''
$script:localWork = ''

# GitHub sets GITHUB_ACTIONS=true on every runner, and CI=true is what most other runners set.
$ciMode = $Ci -or $env:GITHUB_ACTIONS -eq 'true' -or $env:CI -eq 'true'
$canAsk = -not $ciMode -and -not (Get-ProjectEnvValue 'SCRIPTS_NO_PROMPT') -and (Test-CanPromptUser)

if ($For -and $purposes -notcontains $For) {
    Write-Host ''
    Write-Host "  -For takes one of: $($purposes -join ', ')." -ForegroundColor Yellow
    Write-Host ''
    exit 1
}
if (@($all | Where-Object { $_.kind -in $sealedKinds }).Count -and -not ($archiveName -and $linkEntry -and $passwordEntry)) {
    Write-Host ''
    Write-Host '  secrets.json seals files and values but names no archive, or no link or password to open it with.' -ForegroundColor Red
    Write-Host ''
    exit 1
}

# {prefix} is env.prefix, {path} the file's full path, {value} the secret itself, and any other {key} a value from ikaika_scripts.env.
function Resolve-Template([string]$Text, [string]$FullPath = '', [string]$Value = '') {
    return [regex]::Replace($Text, '\{([A-Za-z0-9._]+)\}', {
            param($m)
            switch ($m.Groups[1].Value) {
                'prefix' { return "$(Get-ProjectValue 'env.prefix' 'IKAIKA')" }
                'path' { return $FullPath }
                'value' { return $Value }
                default { return "$(Get-ProjectValue $m.Groups[1].Value '')" }
            }
        })
}

# A leading ~ is the user's home, as ssh expects; anything else is relative to the repo root.
function Resolve-SecretPath([string]$Path) {
    $resolved = Resolve-Template $Path
    if ($resolved -match '^~[\\/]') { return (Join-Path $HOME $resolved.Substring(2)) }
    return (Join-Path $repoRoot $resolved)
}

function Get-LocalValue($Secret) {
    return "$((Read-EnvFile (Resolve-SecretPath $Secret.local.file))[$Secret.local.key])"
}

# The archive password is kept for this Windows account only, encrypted with DPAPI, and shared by every repo in the group.
function Get-PasswordStorePath {
    $base = if ($env:LOCALAPPDATA) { $env:LOCALAPPDATA } else { [Environment]::GetFolderPath('LocalApplicationData') }
    return (Join-Path $base "ikaika_scripts\sealed\$group.password")
}

function Get-StoredPassword {
    $path = Get-PasswordStorePath
    if (-not (Test-Path -LiteralPath $path)) { return '' }
    try {
        $secure = ConvertTo-SecureString ([IO.File]::ReadAllText($path).Trim())
        return (ConvertFrom-SecureString -SecureString $secure -AsPlainText)
    } catch {
        return ''
    }
}

function Save-StoredPassword([string]$Password) {
    $path = Get-PasswordStorePath
    $dir = Split-Path -Parent $path
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    [IO.File]::WriteAllText($path, (ConvertFrom-SecureString -SecureString (ConvertTo-SecureString $Password -AsPlainText -Force)))
}

function Get-LocalState($Secret) {
    switch ($Secret.kind) {
        'file' {
            $path = Resolve-SecretPath $Secret.path
            if (-not (Test-Path -LiteralPath $path)) { return 'missing' }
            if ($Secret.checksum -and -not (Test-FileHash $path (Resolve-SecretPath $Secret.checksum))) { return 'mismatch' }
            return 'ok'
        }
        'value' { return $(if (Get-LocalValue $Secret) { 'ok' } else { 'missing' }) }
        'password' { return $(if (Get-StoredPassword) { 'ok' } else { 'missing' }) }
        default { return '-' }
    }
}

function Get-SetupHint($Secret) {
    if ($Secret.setup) { return $Secret.setup }
    $job = if ($For) { $For } else { @($Secret.for)[0] }
    return "$($Secret.description) Run .\script.ps1 secrets -For $job at a console to enter it."
}

function Show-Status {
    try {
        Show-StatusLines
    } finally {
        Close-LocalArchive
    }
}

# Kept open by Show-Status until every line is drawn, since a line says whether the archive has what this machine lacks.
function Show-StatusLines {
    $opened = $null
    if (-not $ciMode) {
        Write-Host ''
        $opened = Open-LocalArchive
        $where = [IO.Path]::GetRelativePath($repoRoot, $localArchive)
        if ($opened.Missing) {
            Write-Host "  Archive in the repo: none at $where - .\script.ps1 secrets -Export makes it from this machine, or put the team's copy there." -ForegroundColor Yellow
        } elseif ($opened.Error) {
            Write-Host "  Archive in the repo: $where, not opened - $($opened.Error)" -ForegroundColor Yellow
        } else {
            Write-Host "  Archive in the repo: $where, sealed $($opened.Sealed.sealedAt) - $(@($opened.Sealed.files).Count) file(s), $(@($opened.Sealed.values.PSObject.Properties).Count) value(s). -Import puts them in place." -ForegroundColor Green
        }
        if (-not $opened.Missing -and -not (Test-LocalArchiveIgnored)) { Write-Host "  Git does not ignore it - add it to .gitignore before anything commits the secrets in it." -ForegroundColor Red }
    }
    Write-Host ''
    Write-Host "  $(if ($ciMode) { 'In this job' } else { 'On this machine' }) - secrets.json lists $($all.Count):" -ForegroundColor Cyan
    Write-Host ''
    foreach ($s in $all) {
        $state = if (-not $ciMode) { Get-LocalState $s }
        elseif ($s.kind -in $sealedKinds) { 'sealed' }
        elseif ([Environment]::GetEnvironmentVariable($s.name)) { 'set' }
        else { 'missing' }
        if ($s.optional -and $state -eq 'missing') { $state = 'optional' }
        $colour = @{ ok = 'Green'; set = 'Green'; sealed = 'DarkCyan'; missing = 'Yellow'; mismatch = 'Red'; optional = 'DarkGray'; '-' = 'DarkGray' }[$state]
        Write-Host ('    {0,-9}' -f $state) -ForegroundColor $colour -NoNewline
        Write-Host ('{0,-26} {1}' -f $s.name, ($s.for -join ', ')) -NoNewline
        Write-Host $(if ($state -in 'missing', 'mismatch', 'optional' -and (Test-InArchive $s $opened)) { '  (the archive in the repo has it)' } else { '' }) -ForegroundColor DarkCyan
        Write-Host "             $($s.description)" -ForegroundColor DarkGray
    }
    Write-Host ''
}

# Later steps only see variables written to GITHUB_ENV, and a random delimiter keeps any value from ending the block early.
function Set-JobVariable([string]$Name, [string]$Value) {
    Set-Item -LiteralPath "env:$Name" -Value $Value
    if (-not $env:GITHUB_ENV) { return }
    $marker = "EOF_$([Guid]::NewGuid().ToString('N'))"
    [IO.File]::AppendAllText($env:GITHUB_ENV, "$Name<<$marker`n$Value`n$marker`n")
}

function Export-SecretVariable($Secret, [string]$FullPath, [string]$Value) {
    if (-not $Secret.exports) { return }
    foreach ($pair in $Secret.exports.PSObject.Properties) {
        Set-JobVariable (Resolve-Template $pair.Name) (Resolve-Template $pair.Value $FullPath $Value)
    }
}

# GitHub masks its own secrets but not what an archive holds, so each value is registered before any later step could print it.
function Add-LogMask([string]$Value) {
    if ($env:GITHUB_ACTIONS -ne 'true') { return }
    foreach ($line in ($Value -split '\r?\n')) {
        if ($line.Trim()) { Write-Host "::add-mask::$line" }
    }
}

# A passphrase reaches ssh through SSH_ASKPASS_REQUIRE, which OpenSSH honours only from 8.4, so an older one would wait for a keyboard.
function Test-SshKeyUsable([string]$KeyPath) {
    $ErrorActionPreference = 'Continue'
    Assert-SshKeyPermissions $KeyPath
    $keygen = Get-Command ssh-keygen -ErrorAction SilentlyContinue
    if (-not $keygen) { return 'ssh-keygen is not on PATH, so the deploy key cannot be checked.' }
    & $keygen.Source -y -P '' -f $KeyPath *> $null
    if ($LASTEXITCODE -eq 0) { return '' }
    $version = "$(& ssh -V 2>&1)"
    if ($version -match 'OpenSSH[^\d]*(\d+)\.(\d+)' -and ([int]$Matches[1] -gt 8 -or ([int]$Matches[1] -eq 8 -and [int]$Matches[2] -ge 4))) { return '' }
    return "The deploy key has a passphrase, which needs OpenSSH 8.4 or later, and this runner has '$version'."
}

# A deploy key tightened by an earlier run is read-only even to its owner, so its permissions are reset before it is replaced.
function Remove-TightenedFile([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $ErrorActionPreference = 'Continue'
    & icacls $Path /reset *> $null
    Remove-Item -LiteralPath $Path -Force -ErrorAction Stop
}

# 7-Zip from PATH, where the GitHub Windows runners have it, or from where its installer puts it.
function Find-SevenZip {
    foreach ($name in '7z', '7za', '7zr') {
        $command = Get-Command $name -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($command) { return $command.Source }
    }
    $dirs = @()
    $installed = Get-ItemProperty 'HKLM:\SOFTWARE\7-Zip' -ErrorAction SilentlyContinue
    if ($installed) { $dirs += @($installed.Path64, $installed.Path) }
    $dirs += @($env:ProgramFiles, ${env:ProgramFiles(x86)} | Where-Object { $_ } | ForEach-Object { Join-Path $_ '7-Zip' })
    foreach ($dir in @($dirs | Where-Object { $_ })) {
        $exe = Join-Path $dir '7z.exe'
        if (Test-Path -LiteralPath $exe) { return $exe }
    }
    return ''
}

# Dropbox ignores the download=1 that Get-SharedFile adds and serves its preview page unless the link says dl=1.
function ConvertTo-DownloadUrl([string]$Url) {
    if ($Url -notmatch '^https?://([^/?#]+\.)?dropbox\.com/') { return $Url }
    if ($Url -match '[?&]dl=') { return ($Url -replace '([?&])dl=[^&#]*', '${1}dl=1') }
    $separator = if ($Url.Contains('?')) { '&' } else { '?' }
    return "$Url${separator}dl=1"
}

# Opens a sealed archive into $Into: its folder and sealed.json, or why it could not be opened.
function Expand-SealedArchive([string]$Archive, [string]$Password, [string]$Into) {
    $sevenZip = Find-SevenZip
    if (-not $sevenZip) { return @{ Error = "$(Split-Path -Leaf $Archive) is opened with 7-Zip, and it is not installed here: winget install --id 7zip.7zip." } }
    $ErrorActionPreference = 'Continue'
    $opened = Join-Path $Into 'open'
    $output = & $sevenZip x -y "-p$Password" "-o$opened" -bso0 -bsp0 -- $Archive 2>&1
    $code = $LASTEXITCODE
    if ($code -ne 0) {
        return @{ Error = $(if ("$output" -match 'Wrong password') { "it does not open with $($passwordEntry.name), the password it was sealed with." } else { "7-Zip could not open it (exit $code): $output" }); WrongPassword = "$output" -match 'Wrong password' }
    }
    $sealedFile = Join-Path $opened 'sealed.json'
    if (-not (Test-Path -LiteralPath $sealedFile)) { return @{ Error = 'it holds no sealed.json - seal it again with .\script.ps1 secrets -Seal or -Export.' } }
    $sealed = Get-Content -LiteralPath $sealedFile -Raw | ConvertFrom-Json
    if ("$($sealed.archive)" -ne $archiveName) { return @{ Error = "it is $($sealed.archive), another repo's archive; this repo's is $archiveName." } }
    return @{ Dir = $opened; Sealed = $sealed; Error = '' }
}

# Whether git ignores the archive in the repo; one it does not would commit every secret it holds.
function Test-LocalArchiveIgnored {
    $relative = [IO.Path]::GetRelativePath($repoRoot, $localArchive)
    if ($relative.StartsWith('..')) { return $true }
    & git -C $repoRoot check-ignore -q --no-index -- $relative 2>$null
    return $LASTEXITCODE -eq 0
}

# Locally: the archive in the repo, opened with the password kept for this Windows account, then the one in the link's
# password variable, then - when -Ask and someone is there - one pasted in, kept once it opens the archive.
function Open-LocalArchive([switch]$Ask) {
    if (-not (Test-Path -LiteralPath $localArchive -PathType Leaf)) { return @{ Error = 'missing'; Missing = $true } }
    $work = Join-Path ([IO.Path]::GetTempPath()) "ikaika-sealed-$([Guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Path $work | Out-Null
    $script:localWork = $work
    $candidates = @(@(Get-StoredPassword), "$([Environment]::GetEnvironmentVariable($passwordEntry.name))") | Where-Object { $_ } | Select-Object -Unique
    foreach ($password in $candidates) {
        $result = Expand-SealedArchive $localArchive $password $work
        if (-not $result.Error) { return $result }
        if (-not $result.WrongPassword) { return $result }
    }
    if (-not ($Ask -and $canAsk)) { return @{ Error = $(if ($candidates.Count) { "it does not open with the $($passwordEntry.name) kept here." } else { "no $($passwordEntry.name) is kept for this Windows account to open it with." }) } }
    Write-Host "  $(Split-Path -Leaf $localArchive) opens with $($passwordEntry.name), the $group archives' password; paste it to open it and keep it for this Windows account." -ForegroundColor Cyan
    $typed = ConvertFrom-SecureString -AsPlainText (Read-Host -AsSecureString '    password')
    if (-not $typed) { return @{ Error = 'no password was given.' } }
    $result = Expand-SealedArchive $localArchive $typed $work
    if (-not $result.Error) { Save-StoredPassword $typed }
    return $result
}

function Close-LocalArchive {
    if ($script:localWork -and (Test-Path -LiteralPath $script:localWork)) { Remove-Item -LiteralPath $script:localWork -Recurse -Force -ErrorAction SilentlyContinue }
    $script:localWork = ''
}

# Whether an opened archive holds a secret, file or value.
function Test-InArchive($Secret, $Opened) {
    if (-not $Opened -or $Opened.Error) { return $false }
    if ($Secret.kind -eq 'value') { return [bool]"$($Opened.Sealed.values.($Secret.name))" }
    return [bool](Get-ChildItem -LiteralPath (Join-Path $Opened.Dir $Secret.name) -File -ErrorAction SilentlyContinue | Select-Object -First 1)
}

# Locally: one secret from the opened archive, put where the toolkit reads it. A file goes in only when its committed
# checksum trusts the archive's copy, and one already here byte for byte is left alone; a value goes to its env file.
function Import-FromArchive($Secret, $Opened) {
    if (-not (Test-InArchive $Secret $Opened)) { return 'absent' }
    if ($Secret.kind -eq 'value') {
        $value = "$($Opened.Sealed.values.($Secret.name))"
        if ((Get-LocalValue $Secret) -ceq $value) { return 'same' }
        $store = Resolve-SecretPath $Secret.local.file
        if (-not (Test-Path -LiteralPath $store)) { [IO.File]::WriteAllText($store, '') }
        if (-not (Set-EnvValue -Path $store -Key $Secret.local.key -Value $value)) { return 'failed' }
        return 'imported'
    }
    $source = Get-ChildItem -LiteralPath (Join-Path $Opened.Dir $Secret.name) -File | Select-Object -First 1
    if ($Secret.checksum -and -not (Test-FileHash $source.FullName (Resolve-SecretPath $Secret.checksum))) { return 'untrusted' }
    $path = Resolve-SecretPath $Secret.path
    if ((Test-Path -LiteralPath $path) -and (Get-FileHash -LiteralPath $path).Hash -eq (Get-FileHash -LiteralPath $source.FullName).Hash) { return 'same' }
    $dir = Split-Path -Parent $path
    try {
        if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
        Remove-TightenedFile $path
        [IO.File]::WriteAllBytes($path, [IO.File]::ReadAllBytes($source.FullName))
    } catch {
        return 'failed'
    }
    if ($Secret.ssh) { Assert-SshKeyPermissions $path }
    return 'imported'
}

function Invoke-Import {
    if ($ciMode) {
        Write-Host '  -Import is for a workstation; a CI job opens the archive from the link with -For.' -ForegroundColor Yellow
        return $false
    }
    if (-not (Test-LocalArchiveIgnored)) { Write-Host "  Git does not ignore $localArchive - add it to .gitignore before anything commits the secrets in it." -ForegroundColor Yellow }
    Write-Host ''
    Write-Host "  Importing $localArchive" -ForegroundColor Cyan
    try {
        $opened = Open-LocalArchive -Ask
        if ($opened.Error) {
            Write-Host "  Not imported: $(if ($opened.Missing) { "there is no archive there - .\script.ps1 secrets -Export makes one from a machine that has the secrets, or put the team's copy there." } else { "it $($opened.Error)" })" -ForegroundColor Red
            Write-Host ''
            return $false
        }
        Write-Host "    sealed   $($opened.Sealed.sealedAt)" -ForegroundColor DarkGray
        $counts = @{}
        foreach ($s in @($all | Where-Object { $_.kind -in $sealedKinds })) {
            $result = Import-FromArchive $s $opened
            $counts[$result] = 1 + [int]$counts[$result]
            $colour = @{ imported = 'Green'; same = 'DarkGray'; absent = 'DarkGray'; untrusted = 'Red'; failed = 'Red' }[$result]
            $note = @{ imported = "-> $(if ($s.kind -eq 'value') { "$($s.local.key) in $(Resolve-SecretPath $s.local.file)" } else { Resolve-SecretPath $s.path })"; same = 'already here'; absent = 'not in the archive'
                untrusted = "the archive's copy is not the one $(Resolve-Template $s.checksum) trusts - -Export it again from a machine that has the trusted one"; failed = 'could not be written' }[$result]
            Write-Host ('    {0,-10}{1,-26} {2}' -f $result, $s.name, $note) -ForegroundColor $colour
        }
    } finally {
        Close-LocalArchive
    }
    Write-Host ''
    Write-Host "  Imported $([int]$counts['imported']), $([int]$counts['same']) already here$(if ($counts['absent']) { ", $($counts['absent']) not in the archive" })." -ForegroundColor $(if ($counts['untrusted'] -or $counts['failed']) { 'Red' } else { 'Green' })
    Write-Host ''
    return -not ($counts['untrusted'] -or $counts['failed'])
}

# CI: the archive is fetched and opened once, into a folder removed with everything in it when the step ends.
function Open-SealedArchive {
    $url = "$([Environment]::GetEnvironmentVariable($linkEntry.name))"
    $password = "$([Environment]::GetEnvironmentVariable($passwordEntry.name))"
    $missing = @($(if (-not $url) { $linkEntry }), $(if (-not $password) { $passwordEntry }) | Where-Object { $_ })
    foreach ($m in $missing) {
        Write-Host "::error title=Missing secret $($m.name)::$($m.description) Add it under Settings > Secrets and variables > Actions."
    }
    if ($missing.Count) { return $null }
    if (-not (Find-SevenZip)) {
        Write-Host "::error title=7-Zip not found::$archiveName is opened with 7-Zip, which the GitHub Windows runners have; this runner needs it installed."
        return $null
    }
    $tempRoot = if ($env:RUNNER_TEMP) { $env:RUNNER_TEMP } else { [IO.Path]::GetTempPath() }
    $script:sealedWork = Join-Path $tempRoot "ikaika-sealed-$([Guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Path $script:sealedWork | Out-Null
    $archive = Join-Path $script:sealedWork $archiveName
    $name = $archiveName
    $fetch = Get-SharedFile (ConvertTo-DownloadUrl $url) $archive (Join-Path $script:sealedWork 'none.sha256') ({ $_.Name -eq $name }.GetNewClosure()) @($name)
    if (-not $fetch.Success -or -not (Test-Path -LiteralPath $archive)) {
        Write-Host "::error title=$archiveName not fetched::$($fetch.Error) It is the archive .\script.ps1 secrets -Seal makes, uploaded to the folder $($linkEntry.name) links to."
        return $null
    }
    $result = Expand-SealedArchive $archive $password $script:sealedWork
    Remove-Item -LiteralPath $archive -Force -ErrorAction SilentlyContinue
    if ($result.Error) {
        Write-Host "::error title=Cannot open $archiveName::The archive the link served $($result.Error)"
        return $null
    }
    Write-Host "    opened   $archiveName, sealed $($result.Sealed.sealedAt)" -ForegroundColor Green
    return $result
}

function Skip-OrFail($Secret, [string]$Why) {
    if ($Secret.optional) {
        Write-Host "    skip     $($Secret.name) (optional, not sealed)" -ForegroundColor DarkGray
        return 'skipped'
    }
    Write-Host "::error title=$($Secret.name) not sealed::$Why"
    return 'failed'
}

# CI: each secret comes out of the opened archive, and a file its committed checksum does not trust is never left behind.
function Install-FromArchive($Secret, $Opened) {
    $reseal = "Seal it again with .\script.ps1 secrets -Seal and upload $archiveName."
    if ($Secret.kind -eq 'value') {
        $value = "$($Opened.Sealed.values.($Secret.name))"
        if (-not $value) { return (Skip-OrFail $Secret "$archiveName was sealed without it. $reseal") }
        Add-LogMask $value
        Export-SecretVariable $Secret '' $value
        Write-Host "    set      $($Secret.name)" -ForegroundColor Green
        return 'ok'
    }
    $source = Get-ChildItem -LiteralPath (Join-Path $Opened.Dir $Secret.name) -File -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $source) { return (Skip-OrFail $Secret "$archiveName was sealed without it. $reseal") }
    $path = Resolve-SecretPath $Secret.path
    $dir = Split-Path -Parent $path
    try {
        if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
        Remove-TightenedFile $path
        [IO.File]::WriteAllBytes($path, [IO.File]::ReadAllBytes($source.FullName))
    } catch {
        Write-Host "::error title=Could not write $($Secret.name)::$path could not be written: $($_.Exception.Message)"
        return 'failed'
    }
    if ($Secret.checksum -and -not (Test-FileHash $path (Resolve-SecretPath $Secret.checksum))) {
        Remove-Item -LiteralPath $path -Force
        Write-Host "::error title=$($Secret.name) is out of date::The copy in $archiveName is not the file $(Resolve-Template $Secret.checksum) trusts. $reseal"
        return 'failed'
    }
    if ($Secret.ssh) {
        $problem = Test-SshKeyUsable $path
        if ($problem) {
            Write-Host "::error title=Deploy key unusable::$problem"
            return 'failed'
        }
    }
    if ($path -like '*.env') {
        foreach ($pair in (Read-EnvFile $path).GetEnumerator()) {
            if ($pair.Key -match 'pass|secret|token|key|credential|private|salt|dsn') { Add-LogMask "$($pair.Value)" }
        }
    }
    Export-SecretVariable $Secret $path ''
    Write-Host "    written  $($Secret.name) -> $path" -ForegroundColor Green
    return 'ok'
}

function Install-FileLocally($Secret) {
    $path = Resolve-SecretPath $Secret.path
    $url = if ($Secret.share) { Get-ShareUrl $Secret.share } else { '' }
    if ($url) {
        Write-Host "    fetching $($Secret.name) from the team share..." -ForegroundColor Cyan
        $checksum = if ($Secret.checksum) { Resolve-SecretPath $Secret.checksum } else { '' }
        $fetch = Get-SharedFile $url $path $checksum
        if ($fetch.Success -and (Test-Path -LiteralPath $path)) {
            Write-Host "    fetched  $($Secret.name) -> $path" -ForegroundColor Green
            return 'ok'
        }
        Write-Host "    could not fetch it: $($fetch.Error)" -ForegroundColor Yellow
    }
    Write-Host "    missing  $($Secret.name): $(Get-SetupHint $Secret)" -ForegroundColor Yellow
    return 'failed'
}

function Install-ValueLocally($Secret) {
    $optional = if ($Secret.optional) { ' Optional - press Enter to skip.' } else { '' }
    Write-Host "    $($Secret.name): $($Secret.description)$optional" -ForegroundColor Cyan
    $plain = ConvertFrom-SecureString -AsPlainText (Read-Host -AsSecureString '      value')
    if (-not $plain) { return $(if ($Secret.optional) { 'skipped' } else { 'failed' }) }
    $store = Resolve-SecretPath $Secret.local.file
    if (-not (Test-Path -LiteralPath $store)) { [IO.File]::WriteAllText($store, '') }
    if (-not (Set-EnvValue -Path $store -Key $Secret.local.key -Value $plain)) {
        Write-Host "    could not write $($Secret.local.key) to $store" -ForegroundColor Red
        return 'failed'
    }
    Write-Host "    saved    $($Secret.name) as $($Secret.local.key) in $store" -ForegroundColor Green
    return 'ok'
}

# Locally a missing secret is set up where it can be - from the archive in the repo first - and a file that is not the
# trusted copy is replaced from the archive when the archive's copy is the trusted one, and otherwise only reported.
function Install-Locally($Secret, $Opened = $null) {
    $state = Get-LocalState $Secret
    if ($state -eq 'ok') {
        Write-Host "    ok       $($Secret.name)" -ForegroundColor Green
        return 'ok'
    }
    if ($Opened -and $state -in 'missing', 'mismatch') {
        $imported = Import-FromArchive $Secret $Opened
        if ($imported -in 'imported', 'same' -and (Get-LocalState $Secret) -eq 'ok') {
            Write-Host "    imported $($Secret.name) from the archive in the repo" -ForegroundColor Green
            return 'ok'
        }
    }
    if ($state -eq 'mismatch') {
        Write-Host "    mismatch $($Secret.name): $(Resolve-SecretPath $Secret.path) is not the copy $(Resolve-Template $Secret.checksum) trusts." -ForegroundColor Red
        Write-Host '             Delete it and run this again to fetch the trusted one.' -ForegroundColor DarkGray
        return 'failed'
    }
    if (-not $canAsk) {
        if ($Secret.optional) {
            Write-Host "    skip     $($Secret.name) (optional)" -ForegroundColor DarkGray
            return 'skipped'
        }
        Write-Host "    missing  $($Secret.name): $(Get-SetupHint $Secret)" -ForegroundColor Yellow
        return 'failed'
    }
    if ($Secret.kind -eq 'file') { return (Install-FileLocally $Secret) }
    return (Install-ValueLocally $Secret)
}

# 40 letters and digits: about 238 bits, and nothing a command line or a secrets form could mangle.
function New-ArchivePassword {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
    return (-join (1..40 | ForEach-Object { $alphabet[[Security.Cryptography.RandomNumberGenerator]::GetInt32($alphabet.Length)] }))
}

function Get-SealPassword {
    $stored = Get-StoredPassword
    if ($stored) { return $stored }
    if (-not $canAsk) {
        Write-Host "  No archive password is kept for this Windows account, and there is nobody to ask. Run this at a console." -ForegroundColor Yellow
        return ''
    }
    Write-Host ''
    Write-Host "  No archive password is kept for this Windows account yet." -ForegroundColor Cyan
    Write-Host "  Paste $($passwordEntry.name) if the $group archives already have one, or press Enter to make a new one." -ForegroundColor Cyan
    $typed = ConvertFrom-SecureString -AsPlainText (Read-Host -AsSecureString '    password')
    if ($typed -match '[\s"]') {
        Write-Host '  A password with spaces or quotes cannot reach 7-Zip intact; press Enter instead to make one.' -ForegroundColor Red
        return ''
    }
    $password = if ($typed) { $typed } else { New-ArchivePassword }
    Save-StoredPassword $password
    if (-not $typed) {
        Write-Host "  Made a new password and kept it for this Windows account; every $group repo seals with it from now on." -ForegroundColor Yellow
        Write-Host "  Put it in $($passwordEntry.name) in each repo's GitHub secrets with .\script.ps1 secrets -Export, and seal again any archive made with another." -ForegroundColor Yellow
    }
    return $password
}

function Get-DownloadsDir {
    $known = (Get-ItemProperty 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\Shell Folders' -ErrorAction SilentlyContinue).'{374DE290-123F-4565-9164-39C4925E467B}'
    if ($known) { return $known }
    return (Join-Path $HOME 'Downloads')
}

# Only files their committed checksum trusts are sealed, so CI is never handed one it would refuse. -Seal writes to the
# group's folder for upload; -Export writes the archive in the repo, which git must ignore.
function Invoke-Seal([string]$Destination, [string]$ArchivePath = '') {
    if ($ciMode) {
        Write-Host '  Sealing runs where the secrets are; a CI job only opens the archive.' -ForegroundColor Yellow
        return $false
    }
    if ($ArchivePath -and -not (Test-LocalArchiveIgnored)) {
        Write-Host "  Not exported: git does not ignore $ArchivePath, and it would commit every secret it holds - add it to .gitignore first." -ForegroundColor Red
        return $false
    }
    $sevenZip = Find-SevenZip
    if (-not $sevenZip) {
        Write-Host '  7-Zip is not installed: winget install --id 7zip.7zip, then run this again.' -ForegroundColor Yellow
        return $false
    }
    $sealable = @($all | Where-Object { $_.kind -in $sealedKinds })
    Write-Host ''
    Write-Host "  $archiveName - every file and value secrets.json lists" -ForegroundColor Cyan
    $results = [ordered]@{}
    foreach ($s in $sealable) { $results[$s.name] = Install-Locally $s }
    $failed = @($results.Keys | Where-Object { $results[$_] -eq 'failed' })
    if ($failed.Count) {
        Write-Host ''
        Write-Host "  Not sealed: set up $($failed -join ', ') first." -ForegroundColor Red
        Write-Host ''
        return $false
    }
    $password = Get-SealPassword
    if (-not $password) { return $false }

    $target = if ($ArchivePath) { Split-Path -Parent $ArchivePath } elseif ($Destination) { $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Destination) } else { Join-Path (Get-DownloadsDir) "$group-secrets" }
    $archive = if ($ArchivePath) { $ArchivePath } else { Join-Path $target $archiveName }
    $stage = Join-Path ([IO.Path]::GetTempPath()) "ikaika-seal-$([Guid]::NewGuid().ToString('N'))"
    $fresh = "$stage.7z"
    $files = @()
    $values = [ordered]@{}
    $left = @()
    $ErrorActionPreference = 'Continue'
    try {
        New-Item -ItemType Directory -Path $stage -ErrorAction Stop | Out-Null
        foreach ($s in $sealable) {
            if ($results[$s.name] -ne 'ok') { $left += $s.name; continue }
            if ($s.kind -eq 'value') { $values[$s.name] = Get-LocalValue $s; continue }
            $source = Resolve-SecretPath $s.path
            $dir = New-Item -ItemType Directory -Path (Join-Path $stage $s.name) -ErrorAction Stop
            [IO.File]::WriteAllBytes((Join-Path $dir.FullName (Split-Path -Leaf $source)), [IO.File]::ReadAllBytes($source))
            $files += $s.name
        }
        $sealed = [ordered]@{
            format = 1
            archive = $archiveName
            sealedAt = (Get-Date).ToUniversalTime().ToString('yyyy-MM-dd HH:mm') + ' UTC'
            files = $files
            values = $values
        }
        [IO.File]::WriteAllText((Join-Path $stage 'sealed.json'), ($sealed | ConvertTo-Json -Depth 4), [Text.UTF8Encoding]::new($false))
        $output = & $sevenZip a -t7z -mhe=on -mx=9 "-p$password" -bso0 -bsp0 -- $fresh (Join-Path $stage '*') 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host "  7-Zip could not write the archive (exit $LASTEXITCODE): $output" -ForegroundColor Red
            return $false
        }
        $output = & $sevenZip t "-p$password" -bso0 -bsp0 -- $fresh 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host "  The new archive did not test clean (exit $LASTEXITCODE): $output" -ForegroundColor Red
            return $false
        }
        if (-not (Test-Path -LiteralPath $target)) { New-Item -ItemType Directory -Force -Path $target -ErrorAction Stop | Out-Null }
        Move-Item -LiteralPath $fresh -Destination $archive -Force -ErrorAction Stop
    } catch {
        Write-Host "  Not sealed: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    } finally {
        if (Test-Path -LiteralPath $stage) { Remove-Item -LiteralPath $stage -Recurse -Force -ErrorAction SilentlyContinue }
        if (Test-Path -LiteralPath $fresh) { Remove-Item -LiteralPath $fresh -Force -ErrorAction SilentlyContinue }
    }
    Write-Host ''
    Write-Host "  Sealed $($files.Count) file(s) and $($values.Count) value(s) into $archive" -ForegroundColor Green
    if ($left.Count) { Write-Host "  Left out, optional and not set up here: $($left -join ', ')" -ForegroundColor DarkGray }
    if ($ArchivePath) { Write-Host '  Kept in the repo and ignored by git; another machine with it takes the update with .\script.ps1 secrets -Import.' -ForegroundColor Cyan }
    else { Write-Host "  Upload it to the folder $($linkEntry.name) links to, replacing the one there." -ForegroundColor Cyan }
    Write-Host ''
    return $true
}

function Invoke-Export([object[]]$Selected) {
    if (-not $canAsk) {
        Write-Host ''
        Write-Host '  -Export needs a console with someone at it, because it waits between secrets.' -ForegroundColor Yellow
        Write-Host ''
        return $false
    }
    $inGitHub = @($Selected | Where-Object { $_.kind -notin $sealedKinds })
    Write-Host ''
    Write-Host '  Each value goes to the clipboard, never the screen. Paste each one into' -ForegroundColor Cyan
    Write-Host '  GitHub > Settings > Secrets and variables > Actions > New repository secret.' -ForegroundColor Cyan
    Write-Host ''
    $i = 0
    try {
        foreach ($s in $inGitHub) {
            $i++
            $label = "  [$i/$($inGitHub.Count)] $($s.name)"
            if ($s.kind -ne 'password') {
                Write-Host "$label - not kept here; create it yourself. $($s.setup)" -ForegroundColor Yellow
                continue
            }
            $value = Get-StoredPassword
            if (-not $value) {
                Write-Host "$label - not kept for this Windows account yet; .\script.ps1 secrets -Seal or -Import keeps it." -ForegroundColor Yellow
                continue
            }
            Set-Clipboard -Value $value
            Write-Host "$label copied, $($value.Length) characters - $($s.description)" -ForegroundColor Green
            $null = Read-Host '        Paste it into GitHub, then press Enter for the next one'
        }
    } finally {
        Set-Clipboard -Value ' '
    }
    $sealedCount = $Selected.Count - $inGitHub.Count
    Write-Host ''
    if ($sealedCount) { Write-Host "  The other $sealedCount are sealed into $archiveName rather than kept as GitHub secrets." -ForegroundColor DarkGray }
    Write-Host '  Done, and the clipboard has been cleared.' -ForegroundColor DarkGray
    Write-Host ''
    return $true
}

if (@($Import, $Export, $Seal, $GitHub | Where-Object { $_ }).Count -gt 1) {
    Write-Host ''
    Write-Host '  -Import, -Export, -Seal and -GitHub each do one thing; pick one.' -ForegroundColor Yellow
    Write-Host ''
    exit 1
}

if ($GitHub) {
    $exported = Invoke-Export @($(if ($For) { $all | Where-Object { $_.for -contains $For } } else { $all }))
    exit $(if ($exported) { 0 } else { 1 })
}

if ($Import) {
    exit $(if (Invoke-Import) { 0 } else { 1 })
}

if ($Export) {
    exit $(if (Invoke-Seal '' $localArchive) { 0 } else { 1 })
}

if ($Seal) {
    exit $(if (Invoke-Seal $OutDir) { 0 } else { 1 })
}

if (-not $For) {
    Show-Status
    exit 0
}

# The link and the password are checked by opening the archive, so a missing one is named once rather than once per secret.
$needed = @($all | Where-Object { $_.kind -in $sealedKinds -and $_.for -contains $For })
Write-Host ''
Write-Host "  $For - $($manifest.purposes.$For)" -ForegroundColor Cyan
if (-not $needed.Count) {
    Write-Host '    nothing to set up' -ForegroundColor DarkGray
    Write-Host ''
    exit 0
}

$results = [ordered]@{}
$opened = $null
if (-not $ciMode) {
    try {
        $local = $null
        if (@($needed | Where-Object { (Get-LocalState $_) -in 'missing', 'mismatch' }).Count -and (Test-Path -LiteralPath $localArchive)) {
            $local = Open-LocalArchive -Ask
            if ($local.Error) {
                Write-Host "    archive  $(Split-Path -Leaf $localArchive) not opened - $($local.Error)" -ForegroundColor Yellow
                $local = $null
            }
        }
        foreach ($s in $needed) { $results[$s.name] = Install-Locally $s $local }
    } finally {
        Close-LocalArchive
    }
} elseif (-not @($needed | Where-Object { -not $_.optional }).Count -and -not ([Environment]::GetEnvironmentVariable($linkEntry.name) -and [Environment]::GetEnvironmentVariable($passwordEntry.name))) {
    foreach ($s in $needed) {
        Write-Host "    skip     $($s.name) (optional, and this job has no archive to open)" -ForegroundColor DarkGray
        $results[$s.name] = 'skipped'
    }
} else {
    try {
        $opened = Open-SealedArchive
        foreach ($s in $needed) { $results[$s.name] = if ($opened) { Install-FromArchive $s $opened } else { 'not opened' } }
    } finally {
        if ($script:sealedWork -and (Test-Path -LiteralPath $script:sealedWork)) { Remove-Item -LiteralPath $script:sealedWork -Recurse -Force -ErrorAction SilentlyContinue }
    }
}
$failed = @($results.Keys | Where-Object { $results[$_] -in @('failed', 'not opened') })

# The job's summary page shows which secrets this step needed and what became of each.
if ($ciMode -and $env:GITHUB_STEP_SUMMARY) {
    $lines = @("### Secrets for $For", '', '| Secret | State |', '| --- | --- |')
    foreach ($name in $results.Keys) { $lines += "| ``$name`` | $($results[$name]) |" }
    Add-Content -LiteralPath $env:GITHUB_STEP_SUMMARY -Value ($lines + '')
}

Write-Host ''
if ($failed.Count) {
    $what = if ($ciMode -and -not $opened -and @($results.Values) -contains 'not opened') { "the sealed archive could not be opened, so nothing for $For was set up" } else { "$($failed.Count) secret(s) missing or wrong: $($failed -join ', ')" }
    Write-Host "  Not ready - $what." -ForegroundColor Red
    Write-Host ''
    exit 1
}
Write-Host "  $For is ready." -ForegroundColor Green
Write-Host ''
exit 0

# Read by the manifest generator and run alone, so it finds secrets.json from the working directory itself.
function Get-DialogChoiceParameters { return , @('For') }

function Get-DialogChoices([string]$Parameter) {
    if ($Parameter -ne 'For') { return @() }
    $dir = (Get-Location).Path
    while ($dir -and -not (Test-Path -LiteralPath (Join-Path $dir 'ikaika_scripts.env'))) { $dir = Split-Path $dir -Parent }
    if (-not $dir) { return @() }
    $file = Join-Path $dir '.ikaika_scripts_configs\secrets.json'
    if (-not (Test-Path -LiteralPath $file)) { return @() }
    return , @((Get-Content -LiteralPath $file -Raw | ConvertFrom-Json).purposes.PSObject.Properties.Name)
}
