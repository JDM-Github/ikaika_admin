# Browses and edits this repository's .env, beside the .env.example it is supposed to
# match - which key is missing, which is empty, which is set to something the example
# never mentioned, and what each one actually holds.
#
# THIS IS A VIEW, NOT A FORM. Get-DialogView puts `view: browser` in the manifest, so the
# terminal app draws a table with a tree beside it rather than a page of questions. The
# whole point is that a .env is a list of things that already exist: you recognise the
# key you want and act on it, instead of recalling its name into a box.
#
# WHY IT EXISTS. A .env that has drifted from .env.example is the single most common
# reason a local checkout does not work, and it fails a long way from the cause - a
# client that cannot reach an API, a queue that silently does nothing, a login that
# refuses. None of those errors name the key that is wrong. This lists them side by side.
#
# VALUES ARE MASKED BY DEFAULT, and the rule is the key's NAME rather than its contents:
# anything matching KEY, SECRET, PASSWORD, TOKEN, CREDENTIAL, DSN or SALT shows as dots
# and a length. That is deliberately crude, because the alternative - deciding a value
# looks harmless - is how an API token ends up in a screenshot. -Reveal turns it off for
# a run, and a single row can be revealed from the action bar.
#
# NOTHING IS WRITTEN WITHOUT AN ACTION. Opening a row shows it; editing is a keystroke of
# its own, and an edit rewrites exactly one line in place - the file's comments, blank
# lines and ordering all survive, because a .env is read by people at least as often as
# by the framework.
#
# WITHOUT THE VIEW - an older app, the setting switched off, a plain console - it prints
# the same table once and exits. Nothing above depends on the view being there, which is
# what keeps this usable over SSH and in a pipeline.
#
#     .\script.ps1 env-file                  everything, secrets masked
#     .\script.ps1 env-file -Show missing    only what .env.example has and .env does not
#     .\script.ps1 env-file -Reveal          values in the clear
#
# Requires PowerShell 7+.

# ============================[ start: Parameters ]============================
# [CmdletBinding()] so a mistyped switch is an ERROR, not a no-op. In a simple param
# block PowerShell silently IGNORES a parameter it does not recognise - measured, not
# assumed - so `.\script.ps1 env-file -Revael` would start the default set and say
# nothing about the flag that did nothing. Advanced binding refuses it by name.
[CmdletBinding()]
param(
    # Which keys to list. A fact about the command rather than about this machine, so it
    # is a ValidateSet and reaches the app as a dropdown.
    [ValidateSet('all', 'missing', 'empty', 'extra', 'secrets')]
    [string]$Show = 'all',
    # Show values in the clear. Off by default: a masked value is the difference between
    # a screenshot of this screen being shareable and being an incident.
    [switch]$Reveal,
    [switch]$Help
)
# =============================[ end: Parameters ]==============================

$ErrorActionPreference = 'Stop'

$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent
$libDir = Join-Path $repoRoot 'decoupled-script\lib'

. (Join-Path $libDir 'logging.ps1')
. (Join-Path $libDir 'load-env.ps1')
. (Join-Path $libDir 'interactive.ps1')

if ($Help) {
    Write-Host ''
    Write-Host '  env-file - browse and edit .env against .env.example.'
    Write-Host ''
    Write-Host '  Usage'
    Write-Host '    .\script.ps1 env-file [-Show all|missing|empty|extra|secrets] [-Reveal]'
    Write-Host ''
    Write-Host '  Draws a table in the terminal app: every key, its value, and how it'
    Write-Host '  compares with .env.example. Without the browser view it prints the same'
    Write-Host '  table once and exits.'
    Write-Host ''
    Write-Host '    missing   in .env.example, absent from .env'
    Write-Host '    empty     present in .env with no value'
    Write-Host '    extra     in .env, never mentioned by .env.example'
    Write-Host '    secrets   the keys whose names mark them as credentials'
    Write-Host ''
    Write-Host '  Values are masked unless -Reveal. The rule is the key NAME - KEY,'
    Write-Host '  SECRET, PASSWORD, TOKEN, CREDENTIAL, DSN, SALT - because deciding a'
    Write-Host '  value looks harmless is how a token ends up in a screenshot.'
    Write-Host ''
    Write-Host '  Edits rewrite one line in place; comments and ordering survive.'
    Write-Host ''
    exit 0
}

Start-BuildLog 'env-file'

$envPath = Join-Path $repoRoot '.env'
$examplePath = Join-Path $repoRoot '.env.example'

# ============================[ start: The file ]==============================
# Parsed into a list rather than a map, and the order is the file's. A .env is edited by
# hand far more often than it is generated, so its comments, blank lines and grouping are
# content - and rewriting it from a dictionary would throw all of that away on the first
# edit somebody made through this screen.

# Crude on purpose. A name-based rule has false positives - APP_KEY_ROTATION_DAYS is not
# a secret - and that costs a reveal keystroke; a value-based rule has false NEGATIVES,
# and those cost a leaked credential. The asymmetry decides it.
$SecretPattern = '(KEY|SECRET|PASSWORD|PASSWD|TOKEN|CREDENTIAL|DSN|SALT|CERT|PRIVATE)'

function Read-EnvLine([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return @() }
    $entries = @()
    $number = 0
    foreach ($line in (Get-Content -LiteralPath $Path)) {
        $number++
        $text = "$line"
        if ($text.Trim() -eq '' -or $text.Trim().StartsWith('#')) { continue }
        if ($text -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$') { continue }
        $value = "$($Matches[2])".Trim()
        # Only the OUTER quotes, and only a matched pair. A value that is genuinely
        # quoted inside - a JSON blob, a regex - keeps its own quoting.
        if ($value.Length -ge 2 -and (($value[0] -eq '"' -and $value[-1] -eq '"') -or
                ($value[0] -eq "'" -and $value[-1] -eq "'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        $entries += [pscustomobject]@{ Key = $Matches[1]; Value = $value; Line = $number }
    }
    return $entries
}

function Format-Value([string]$Key, [string]$Value) {
    if ($Value -eq '') { return '' }
    if ($script:RevealAll -or $script:Revealed -contains $Key) { return $Value }
    if ($Key -notmatch $SecretPattern) { return $Value }
    return ('*' * [Math]::Min(12, $Value.Length)) + "  ($($Value.Length) chars)"
}

$script:Revealed = @()

# The switch, captured once into script scope. Read straight from $Reveal inside
# Format-Value it resolves fine at runtime but reads as unused to any static analysis,
# which is a warning somebody eventually silences rather than checks.
$script:RevealAll = [bool]$Reveal

# One row per key that appears in EITHER file, because "missing" is as much a fact about
# this .env as any key it does have - and a screen that only lists what is present cannot
# show you what is not.
function Get-EnvRow {
    $mine = Read-EnvLine $envPath
    $theirs = Read-EnvLine $examplePath
    $mineByKey = @{}
    foreach ($e in $mine) { $mineByKey[$e.Key] = $e }
    $theirsByKey = @{}
    foreach ($e in $theirs) { $theirsByKey[$e.Key] = $e }

    $ordered = @()
    foreach ($e in $mine) { $ordered += $e.Key }
    foreach ($e in $theirs) { if ($ordered -notcontains $e.Key) { $ordered += $e.Key } }

    $rows = @()
    foreach ($key in $ordered) {
        $has = $mineByKey.ContainsKey($key)
        $value = $(if ($has) { $mineByKey[$key].Value } else { '' })
        $state = 'set'
        if (-not $has) { $state = 'missing' }
        elseif ($value -eq '') { $state = 'empty' }
        elseif (-not $theirsByKey.ContainsKey($key)) { $state = 'extra' }

        $rows += [pscustomobject]@{
            Key      = $key
            Value    = $value
            State    = $state
            Example  = $(if ($theirsByKey.ContainsKey($key)) { $theirsByKey[$key].Value } else { '' })
            IsSecret = [bool]($key -match $SecretPattern)
            Line     = $(if ($has) { $mineByKey[$key].Line } else { 0 })
        }
    }
    return $rows
}

function Select-EnvRow([object[]]$Rows, [string]$Filter) {
    switch ($Filter) {
        'missing' { return @($Rows | Where-Object { $_.State -eq 'missing' }) }
        'empty' { return @($Rows | Where-Object { $_.State -eq 'empty' }) }
        'extra' { return @($Rows | Where-Object { $_.State -eq 'extra' }) }
        'secrets' { return @($Rows | Where-Object { $_.IsSecret }) }
        default { return @($Rows) }
    }
}

# ONE LINE REWRITTEN, never the whole file regenerated. Everything that is not this key
# is copied through byte for byte, so comments, blank lines, ordering and any hand
# formatting survive an edit made from this screen.
function Set-EnvValue([string]$Key, [string]$Value) {
    if (-not (Test-Path -LiteralPath $envPath)) {
        [IO.File]::WriteAllText($envPath, "$Key=$Value`n", [Text.UTF8Encoding]::new($false))
        return
    }
    $lines = @(Get-Content -LiteralPath $envPath)
    $done = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^\s*$([regex]::Escape($Key))\s*=") {
            $lines[$i] = "$Key=$Value"
            $done = $true
            break
        }
    }
    # A key the file has never carried goes at the end rather than being slotted in
    # beside its neighbours: guessing where it belongs would reorder somebody's grouping.
    if (-not $done) { $lines += "$Key=$Value" }
    [IO.File]::WriteAllLines($envPath, $lines, [Text.UTF8Encoding]::new($false))
}
# =============================[ end: The file ]===============================

# ---------------------------------------------------------------- plain output
function Write-PlainTable([object[]]$Rows) {
    Write-Host ''
    Write-Host "  $envPath" -ForegroundColor Cyan
    Write-Host ''
    $colour = @{ set = 'Gray'; missing = 'Yellow'; empty = 'Yellow'; extra = 'DarkGray' }
    foreach ($row in $Rows) {
        Write-Host ('    {0,-9}' -f $row.State) -ForegroundColor $colour[$row.State] -NoNewline
        Write-Host ('{0,-34} {1}' -f $row.Key, (Format-Value $row.Key $row.Value))
    }
}

function Write-Summary([object[]]$All) {
    $missing = @($All | Where-Object { $_.State -eq 'missing' }).Count
    $empty = @($All | Where-Object { $_.State -eq 'empty' }).Count
    $extra = @($All | Where-Object { $_.State -eq 'extra' }).Count
    return "$($All.Count) key(s): $missing missing, $empty empty, $extra not in the example"
}

# ==============================[ start: The run ]=============================
$all = @(Get-EnvRow)

if (-not (Test-Path -LiteralPath $envPath)) {
    Write-Host ''
    Write-Host "  There is no .env at $envPath." -ForegroundColor Yellow
    Write-Host '    .\script.ps1 development-setup      creates one from .env.example' -ForegroundColor DarkGray
    Write-Host ''
}

if (-not (Test-InteractiveList)) {
    # The plain path, and it is the one an older app, a switched-off setting, a hook and
    # an SSH session all get. Nothing above depends on the view.
    Write-PlainTable (Select-EnvRow $all $Show)
    Write-Host ''
    Write-Host "  $(Write-Summary $all)" -ForegroundColor DarkGray
    Write-Host ''
    exit 0
}

function Get-Columns {
    return @(
        (New-BrowserColumn -Key 'state' -Label '' -Width 9),
        (New-BrowserColumn -Key 'key' -Label 'Key' -Width 34),
        (New-BrowserColumn -Key 'value' -Label 'Value' -Grow)
    )
}

function Get-Tree {
    return @(
        (New-BrowserNode -Id 'all' -Label 'All keys'),
        (New-BrowserNode -Id 'missing' -Label 'Missing'),
        (New-BrowserNode -Id 'empty' -Label 'Empty'),
        (New-BrowserNode -Id 'extra' -Label 'Not in example'),
        (New-BrowserNode -Id 'secrets' -Label 'Secrets')
    )
}

function Get-Actions([object[]]$Rows) {
    $actions = @(
        (New-BrowserAction -Id 'edit' -Label 'Edit' -Key 'e'),
        (New-BrowserAction -Id 'reveal' -Label 'Reveal' -Key 'r'),
        (New-BrowserAction -Id 'reload' -Label 'Reload' -Key 'l')
    )
    # Offered only when there is something for it to do, so the bar never carries a
    # button that answers nothing.
    if (@($Rows | Where-Object { $_.State -eq 'missing' -and $_.Example -ne '' }).Count) {
        $actions += (New-BrowserAction -Id 'fill' -Label 'Fill from example' -Key 'f')
    }
    return $actions
}

function ConvertTo-Rows([object[]]$Rows) {
    $out = @()
    foreach ($row in $Rows) {
        $detail = switch ($row.State) {
            'missing' { ".env.example has this key; .env does not$(if ($row.Example) { ". Example: $($row.Example)" })" }
            'empty' { "present but empty$(if ($row.Example) { ". Example: $($row.Example)" })" }
            'extra' { '.env.example never mentions this key' }
            default { $(if ($row.IsSecret) { 'masked - the key name marks it a credential' } else { '' }) }
        }
        $out += New-BrowserRow -Id $row.Key -Kind $row.State -Detail $detail -Cells @{
            state = $row.State
            key   = $row.Key
            value = (Format-Value $row.Key $row.Value)
        }
    }
    return $out
}

Send-BrowserView -Columns (Get-Columns) -Tree (Get-Tree)

$filter = $Show
while ($true) {
    $all = @(Get-EnvRow)
    $shown = @(Select-EnvRow $all $filter)
    Send-BrowserRows -Rows (ConvertTo-Rows $shown) -Breadcrumb @('.env', $filter) -Actions (Get-Actions $all)
    Write-BrowserStatus (Write-Summary $all)

    $said = Read-BrowserInput
    if ($null -eq $said) { break }

    switch ($said.Kind) {
        'open' {
            # A tree node filters; a row opened is a row revealed, which is the only
            # thing "open this key" can usefully mean.
            if (@('all', 'missing', 'empty', 'extra', 'secrets') -contains $said.Id) {
                $filter = $said.Id
            } elseif ($said.Id -and $script:Revealed -notcontains $said.Id) {
                $script:Revealed += $said.Id
            }
        }
        'action' {
            switch ($said.Action) {
                'reload' { $script:Revealed = @() }
                'reveal' {
                    if ($said.Id) {
                        if ($script:Revealed -contains $said.Id) {
                            $script:Revealed = @($script:Revealed | Where-Object { $_ -ne $said.Id })
                        } else {
                            $script:Revealed += $said.Id
                        }
                    }
                }
                'edit' {
                    if (-not $said.Id) { break }
                    $current = @($all | Where-Object { $_.Key -eq $said.Id })[0]
                    # Seeded with the example's value when .env has none - which turns
                    # "fill this in" from recall into a confirmation.
                    $seed = $(if ($current.Value) { $current.Value } else { $current.Example })
                    $answer = Request-Text -Prompt "$($said.Id) =" -Value $seed
                    if ($null -ne $answer) {
                        Set-EnvValue $said.Id $answer
                        Write-BrowserStatus "$($said.Id) written to .env"
                    }
                }
                'fill' {
                    $filled = @()
                    foreach ($row in @($all | Where-Object { $_.State -eq 'missing' -and $_.Example -ne '' })) {
                        Set-EnvValue $row.Key $row.Example
                        $filled += $row.Key
                    }
                    Write-BrowserStatus $(if ($filled.Count) { "filled $($filled.Count): $($filled -join ', ')" } else { 'nothing to fill' })
                }
            }
        }
    }
}

Stop-Listing
exit 0
# ===============================[ end: The run ]==============================

# =========================[ start: What the app asks ]========================
# Read by the manifest generator, and lifted out of this file and run alone - so nothing
# here may call anything above it.
function Get-DialogView { return 'browser' }

# It speaks the list protocol as well as drawing the view: the edit prompt is an ordinary
# question shown over the table.
function Get-DialogInteractive { return $true }

function Get-DialogChoiceParameters { return , @('Show') }

function Get-DialogChoices([string]$Parameter) {
    if ($Parameter -ne 'Show') { return @() }
    return , @('all', 'missing', 'empty', 'extra', 'secrets')
}
# ==========================[ end: What the app asks ]=========================
