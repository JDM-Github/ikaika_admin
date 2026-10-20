<#
.SYNOPSIS
  Ikaika Dev Console -- one window to run the migration and Test Server actions,
  one at a time, with their output streaming live.

.DESCRIPTION
  A self-contained WinForms front end over the scripts in migrations/ and
  deployment/. Nothing here does the work itself: every button runs one script as
  a child process and streams its stdout/stderr into the window, then prints the
  exit code and how long it took. Errors are shown immediately, in red.

  The Airtable group reads the env radio (test | prod). Destructive actions
  (reset local DBs, reset the server and reload, deploy-all with a DB reset) ask
  you to type 'yes' first.

  SSH passphrases cannot travel through the output box (ssh reads the terminal
  directly), so the header shows ssh-agent status and [Add SSH key] opens a small
  console for the passphrase. Do that once per login.

  Launch it with the "Ikaika Console.bat" beside this file, or:
      pwsh -NoProfile -File devtools\ikaika-console.ps1
#>

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName Microsoft.VisualBasic

[System.Windows.Forms.Application]::EnableVisualStyles()

$ErrorActionPreference = 'Stop'

$scriptDir   = $PSScriptRoot
$repoRoot    = Split-Path -Parent $scriptDir
$migrations  = Join-Path $repoRoot 'migrations'
$deploy      = Join-Path $repoRoot 'deployment'
# The portal frontend's checkout beside this one, under its old name or the one it has now (2026-10-20).
$portalRepo  = @('ikaika-portal', 'ikaika_portal_frontend_rewrite') | ForEach-Object { Join-Path (Split-Path -Parent $repoRoot) $_ } |
    Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $portalRepo) { $portalRepo = Join-Path (Split-Path -Parent $repoRoot) 'ikaika_portal_frontend_rewrite' }
$pwshPath    = (Get-Command pwsh -ErrorAction SilentlyContinue).Source
if (-not $pwshPath) { $pwshPath = Join-Path $PSHOME 'pwsh.exe' }

$script:env          = 'test'
$script:running      = $false
$script:stopRequested = $false
$script:proc         = $null
$script:sync         = $null

# ---------------------------------------------------------------- form + layout

$form                 = New-Object System.Windows.Forms.Form
$form.Text            = 'Ikaika Dev Console'
$form.Size            = New-Object System.Drawing.Size(1120, 760)
$form.MinimumSize     = New-Object System.Drawing.Size(900, 600)
$form.StartPosition   = 'CenterScreen'
$form.Font            = New-Object System.Drawing.Font('Segoe UI', 9)

$header                = New-Object System.Windows.Forms.Panel
$header.Dock           = 'Top'
$header.Height         = 62
$header.BackColor      = [System.Drawing.Color]::FromArgb(250, 250, 250)

$title                 = New-Object System.Windows.Forms.Label
$title.Text            = 'Ikaika Dev Console'
$title.Font            = New-Object System.Drawing.Font('Segoe UI Semibold', 12)
$title.Location        = New-Object System.Drawing.Point(12, 8)
$title.AutoSize        = $true
$header.Controls.Add($title)

$statusLabel           = New-Object System.Windows.Forms.Label
$statusLabel.Location  = New-Object System.Drawing.Point(14, 34)
$statusLabel.AutoSize  = $true
$statusLabel.ForeColor = [System.Drawing.Color]::DimGray
$header.Controls.Add($statusLabel)

$sshBtn                = New-Object System.Windows.Forms.Button
$sshBtn.Text           = 'Add SSH key'
$sshBtn.Size           = New-Object System.Drawing.Size(96, 26)
$sshBtn.Anchor         = 'Top,Right'
$sshBtn.Location       = New-Object System.Drawing.Point(($form.ClientSize.Width - 108), 30)
$header.Controls.Add($sshBtn)

$refreshBtn            = New-Object System.Windows.Forms.Button
$refreshBtn.Text       = 'Refresh'
$refreshBtn.Size       = New-Object System.Drawing.Size(72, 26)
$refreshBtn.Anchor     = 'Top,Right'
$refreshBtn.Location   = New-Object System.Drawing.Point(($form.ClientSize.Width - 186), 30)
$header.Controls.Add($refreshBtn)

$actions               = New-Object System.Windows.Forms.Panel
$actions.Dock          = 'Left'
$actions.Width         = 452
$actions.AutoScroll    = $true
$actions.Padding       = New-Object System.Windows.Forms.Padding(8)

$right                 = New-Object System.Windows.Forms.Panel
$right.Dock            = 'Fill'

$console               = New-Object System.Windows.Forms.RichTextBox
$console.Dock          = 'Fill'
$console.ReadOnly      = $true
$console.BackColor     = [System.Drawing.Color]::FromArgb(24, 24, 28)
$console.ForeColor     = [System.Drawing.Color]::Gainsboro
$console.Font          = New-Object System.Drawing.Font('Consolas', 9.5)
$console.WordWrap      = $false
$console.HideSelection = $false
$right.Controls.Add($console)

$sendBar               = New-Object System.Windows.Forms.Panel
$sendBar.Dock          = 'Bottom'
$sendBar.Height        = 38
$sendBar.Padding       = New-Object System.Windows.Forms.Padding(6)
$right.Controls.Add($sendBar)

$stopBtn               = New-Object System.Windows.Forms.Button
$stopBtn.Text          = 'Stop'
$stopBtn.Size          = New-Object System.Drawing.Size(70, 26)
$stopBtn.Dock          = 'Right'
$sendBar.Controls.Add($stopBtn)

$clearBtn              = New-Object System.Windows.Forms.Button
$clearBtn.Text         = 'Clear'
$clearBtn.Size         = New-Object System.Drawing.Size(64, 26)
$clearBtn.Dock         = 'Right'
$sendBar.Controls.Add($clearBtn)

$sendBtn               = New-Object System.Windows.Forms.Button
$sendBtn.Text          = 'Send'
$sendBtn.Size          = New-Object System.Drawing.Size(64, 26)
$sendBtn.Dock          = 'Right'
$sendBar.Controls.Add($sendBtn)

$sendBox               = New-Object System.Windows.Forms.TextBox
$sendBox.Dock          = 'Fill'
$sendBox.PlaceholderText = 'Type a prompt answer (Read-Host) and press Send, or Enter'
$sendBar.Controls.Add($sendBox)
$sendBox.BringToFront()

# Dock order is the REVERSE of the order added: the last one added claims its edge
# first. Add Fill first, then Left, then Top, so the header and the action column
# keep their strips and the console fills what is left.
$form.Controls.Add($right)
$form.Controls.Add($actions)
$form.Controls.Add($header)

# ---------------------------------------------------------------- helpers

function Write-Console {
    param([string]$Text, [string]$Color = 'DarkGray')
    if ($console.TextLength -gt 200000) { $console.Clear() }
    $console.SelectionStart = $console.TextLength
    $console.SelectionLength = 0
    $console.SelectionColor = [System.Drawing.Color]::FromName($Color)
    $console.AppendText($Text + "`n")
    $console.SelectionColor = $console.ForeColor
    $console.ScrollToCaret()
}

function Get-LineColor {
    param([string]$Line)
    if ($Line -match '(?i)\bERROR\b|\bFAIL\S*|fatal|denied|Traceback|exception|not recognized|cannot find') { return 'Salmon' }
    if ($Line -match '(?i)^\s*warn\b|\bWARNING\b|Gave up|retrying') { return 'Khaki' }
    if ($Line -match '^(====>|==>|-- |RESULT)') { return 'LightSkyBlue' }
    if ($Line -match '(?i)\bok\b|\bPASS\b|\bdone\b|Ready:|written') { return 'LightGreen' }
    return 'DarkGray'
}

function New-ActionButton {
    param([string]$Text, [int]$Y, [string]$Tag)
    $b = New-Object System.Windows.Forms.Button
    $b.Text = $Text
    $b.Tag = $Tag
    $b.Size = New-Object System.Drawing.Size(420, 30)
    $b.Location = New-Object System.Drawing.Point(12, $Y)
    $actions.Controls.Add($b)
    $script:sync.AllButtons.Add($b)
    return $b
}

function New-GroupLabel {
    param([string]$Text, [int]$Y)
    $l = New-Object System.Windows.Forms.Label
    $l.Text = $Text
    $l.Font = New-Object System.Drawing.Font('Segoe UI Semibold', 9.5)
    $l.ForeColor = [System.Drawing.Color]::FromArgb(40, 40, 40)
    $l.Location = New-Object System.Drawing.Point(12, $Y)
    $l.AutoSize = $true
    $actions.Controls.Add($l)
}

function Update-Status {
    $parts = @()

    $statusPath = Join-Path $repoRoot 'sql/status.txt'
    if (Test-Path $statusPath) {
        $envLine = (Get-Content $statusPath | Where-Object { $_ -match '^env=' } | Select-Object -First 1)
        $copied = (Get-Content $statusPath | Where-Object { $_ -match '^copied=' } | Select-Object -First 1)
        if ($envLine) {
            $e = $envLine.Split('=', 2)[1].Trim()
            $when = if ($copied) { ($copied.Split('=', 2)[1].Trim()) } else { '' }
            $parts += "sql/ holds: $e ($when)"
        }
    }
    else { $parts += 'sql/status.txt: none' }

    $agent = Get-Service ssh-agent -ErrorAction SilentlyContinue
    if ($agent -and $agent.Status -eq 'Running') {
        $keys = & ssh-add -l 2>&1
        $count = @($keys | Where-Object { "$_" -match 'SHA256|MD5' }).Count
        $parts += "ssh-agent: running ($count key(s))"
    }
    else { $parts += 'ssh-agent: stopped (passphrase asked each step)' }

    if (-not (Test-Path $portalRepo)) { $parts += "portal repo missing: $portalRepo" }

    $statusLabel.Text = ($parts -join '     |     ')
    if (-not (Test-Path $portalRepo)) { $statusLabel.ForeColor = [System.Drawing.Color]::DarkOrange }
    else { $statusLabel.ForeColor = [System.Drawing.Color]::DimGray }
}

function Set-Busy {
    param([bool]$Busy)
    foreach ($b in $script:sync.AllButtons) { $b.Enabled = -not $Busy }
    $stopBtn.Enabled = $Busy
}

function Start-Action {
    param(
        [string]$Label,
        [string[]]$ScriptArgs,
        [string]$WorkDir = $repoRoot,
        [string]$Confirm,
        [string]$ConfirmWord = 'yes'
    )

    if ($script:running) {
        Write-Console '[busy] an action is already running -- Stop it first.' 'Khaki'
        return
    }

    if ($Confirm) {
        $answer = [Microsoft.VisualBasic.Interaction]::InputBox($Confirm, "Confirm: $Label", '')
        if ($answer -cne $ConfirmWord) { Write-Console "Cancelled: $Label" 'Khaki'; return }
    }

    $spawn = @('-NoProfile', '-File') + $ScriptArgs
    Write-Console ''
    Write-Console ("=============================== $Label ===============================") 'DeepSkyBlue'
    Write-Console ("> pwsh " + ($spawn -join ' ')) 'Gray'

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $pwshPath
    $psi.WorkingDirectory = $WorkDir
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.RedirectStandardInput = $true
    $psi.CreateNoWindow = $true
    $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8
    $psi.StandardErrorEncoding = [System.Text.Encoding]::UTF8
    $psi.ArgumentList.Add('-NoProfile')
    $psi.ArgumentList.Add('-File')
    foreach ($a in $ScriptArgs) { [void]$psi.ArgumentList.Add($a) }

    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo = $psi
    $script:proc = $proc
    $script:stopRequested = $false
    $script:running = $true
    Set-Busy $true

    try {
        [void]$proc.Start()
        $proc.StandardInput.AutoFlush = $true

        $outTask = $proc.StandardOutput.ReadLineAsync()
        $errTask = $proc.StandardError.ReadLineAsync()
        $started = Get-Date

        while (-not $proc.HasExited -or
            ($outTask -ne $null -and -not $outTask.IsCompleted) -or
            ($errTask -ne $null -and -not $errTask.IsCompleted)) {

            if ($outTask -ne $null -and $outTask.IsCompleted) {
                $line = $outTask.Result
                if ($null -eq $line) { $outTask = $null }
                else {
                    Write-Console $line (Get-LineColor $line)
                    $outTask = $proc.StandardOutput.ReadLineAsync()
                }
            }
            if ($errTask -ne $null -and $errTask.IsCompleted) {
                $line = $errTask.Result
                if ($null -eq $line) { $errTask = $null }
                else {
                    Write-Console $line 'Salmon'
                    $errTask = $proc.StandardError.ReadLineAsync()
                }
            }

            [System.Windows.Forms.Application]::DoEvents()

            if ($script:stopRequested -and -not $proc.HasExited) {
                try { $proc.Kill($true) } catch { }
            }
            Start-Sleep -Milliseconds 25
        }
        $proc.WaitForExit()
        $code = $proc.ExitCode
        $elapsed = ((Get-Date) - $started).TotalSeconds
        $colour = if ($code -eq 0) { 'LightGreen' } else { 'Salmon' }
        Write-Console ('── exit {0} · {1:N1}s ──' -f $code, $elapsed) $colour
    }
    catch {
        Write-Console ("[error] " + $_.Exception.Message) 'Salmon'
    }
    finally {
        $script:running = $false
        $script:stopRequested = $false
        $script:proc = $null
        Set-Busy $false
        Update-Status
    }
}

# ---------------------------------------------------------------- action buttons

$script:sync = @{ AllButtons = New-Object System.Collections.Generic.List[object] }

$y = 10
New-GroupLabel 'Airtable  ->  output' $y; $y += 24

$envPanel = New-Object System.Windows.Forms.Panel
$envPanel.Location = New-Object System.Drawing.Point(12, $y)
$envPanel.Size = New-Object System.Drawing.Size(420, 26)
$actions.Controls.Add($envPanel)
$rbTest = New-Object System.Windows.Forms.RadioButton
$rbTest.Text = 'test'; $rbTest.Checked = $true
$rbTest.Location = New-Object System.Drawing.Point(0, 4); $rbTest.AutoSize = $true
$envPanel.Controls.Add($rbTest)
$rbProd = New-Object System.Windows.Forms.RadioButton
$rbProd.Text = 'prod'
$rbProd.Location = New-Object System.Drawing.Point(70, 4); $rbProd.AutoSize = $true
$envPanel.Controls.Add($rbProd)
$rbTest.Add_CheckedChanged({ if ($rbTest.Checked) { $script:env = 'test' } })
$rbProd.Add_CheckedChanged({ if ($rbProd.Checked) { $script:env = 'prod' } })
$y += 34

$bMigrate = New-ActionButton 'Migrate all (fetch Airtable -> generate + verify)' $y
$bMigrate.Add_Click({ Start-Action "Migrate all ($script:env)" @((Join-Path $migrations 'migrate-all.ps1'), '-Env', $script:env) })
$y += 34
$bVerify = New-ActionButton 'Verify migrations (data.sql vs export)' $y
$bVerify.Add_Click({ Start-Action "Verify migrations ($script:env)" @((Join-Path $migrations 'verify_migrations.ps1'), '-Env', $script:env, '-Detailed') })
$y += 34
$bCopy = New-ActionButton 'Copy to sql (adopt snapshot, writes status.txt)' $y
$bCopy.Add_Click({ Start-Action "Copy to sql ($script:env)" @((Join-Path $migrations 'copy-to-sql.ps1'), '-Env', $script:env) })
$y += 34
$bLive = New-ActionButton 'Check export is current (live counts)' $y
$bLive.Add_Click({ Start-Action "Check export is current ($script:env)" @((Join-Path $migrations 'check_live.ps1'), '-Env', $script:env) })
$y += 44

New-GroupLabel 'Local database' $y; $y += 24
$bResetLocal = New-ActionButton 'Reset all local DBs from sql/' $y
$bResetLocal.Add_Click({
        Start-Action 'Reset all local DBs' @((Join-Path $migrations 'reset.ps1'), '-Force') `
            -Confirm "This DROPS and recreates your local portal/estimator/tracker databases and reloads them from sql/.`nType 'yes' to continue."
    })
$y += 44

New-GroupLabel 'Test Server  (test.ikaikabim.com)' $y; $y += 24
$bPush = New-ActionButton 'Push local DBs -> server  (update: replaces those tables)' $y
$bPush.Add_Click({ Start-Action 'Push local DBs -> server' @((Join-Path $deploy 'copy-databases.ps1')) })
$y += 34
$bWipe = New-ActionButton 'Reset server + load local  (clean / wipe)' $y
$bWipe.Add_Click({
        Start-Action 'Reset server + load local (clean)' @((Join-Path $deploy 'copy-databases.ps1'), '-Wipe') `
            -Confirm "This WIPES every table on the Test Server and reloads your local databases. The old data is backed up on the server.`nType 'yes' to continue."
    })
$y += 34
$bDeployAdmin = New-ActionButton 'Deploy admin' $y
$bDeployAdmin.Add_Click({ Start-Action 'Deploy admin' @((Join-Path $deploy 'deploy.ps1')) })
$y += 34
$bDeployPortal = New-ActionButton 'Deploy portal' $y
$bDeployPortal.Add_Click({ Start-Action 'Deploy portal' @((Join-Path $portalRepo 'deployment/deploy.ps1')) -WorkDir $portalRepo })
$y += 34
$bDeployAll = New-ActionButton 'Deploy all  (settings + admin + portal)' $y
$bDeployAll.Add_Click({ Start-Action 'Deploy all' @((Join-Path $deploy 'deploy-all.ps1')) })
$y += 34
$bDeployAllReset = New-ActionButton 'Deploy all + DB reset  (clean)' $y
$bDeployAllReset.Add_Click({
        Start-Action 'Deploy all + DB reset (clean)' @((Join-Path $deploy 'deploy-all.ps1'), '-ResetDatabases', '-Yes') `
            -Confirm "This WIPES every Test Server database, reloads your local ones, then deploys settings, admin and portal.`nType 'yes' to continue."
    })
$y += 34
$bHealth = New-ActionButton 'Health check' $y
$bHealth.Add_Click({ Start-Action 'Health check' @((Join-Path $deploy 'check-health.ps1'), '-Target', 'test') })
$y += 44

New-GroupLabel 'Production  (portal.ikaikabim.com/v2) -- type PROD to confirm' $y; $y += 24
$bProdDb = New-ActionButton 'Load Airtable export -> prod DBs (drops + recreates them)' $y
$bProdDb.Add_Click({
        Start-Action 'Load Airtable export -> prod DBs' @((Join-Path $deploy 'import-airtable-to-prod.ps1'), '-Yes') `
            -Confirm "PRODUCTION. DROPS and RECREATES the live databases (eoxvhumy_pp_portal / estimator / tracker) from the AIRTABLE EXPORT in migrations/outputs -- NOT from your local databases. Each is backed up on the server first, then the app's migrations run.`nType PROD to continue." -ConfirmWord 'PROD'
    })
$y += 34
$bProdDeploy = New-ActionButton 'Deploy all prod  (admin + portal @ /v2)' $y
$bProdDeploy.Add_Click({
        Start-Action 'Deploy all prod' @((Join-Path $deploy 'deploy-all-prod.ps1'), '-Force') `
            -Confirm "PRODUCTION. Builds and deploys the admin API and the portal to portal.ikaikabim.com/v2 (release flow).`nType PROD to continue." -ConfirmWord 'PROD'
    })
$y += 40

# ---------------------------------------------------------------- button wiring

$stopBtn.Add_Click({ $script:stopRequested = $true; Write-Console '[stop requested]' 'Khaki' })
$clearBtn.Add_Click({ $console.Clear() })

function Send-Input {
    $text = $sendBox.Text
    $sendBox.Clear()
    if (-not $script:proc -or $script:proc.HasExited) {
        Write-Console '[no running action to send to]' 'Khaki'
        return
    }
    try {
        $script:proc.StandardInput.WriteLine($text)
        Write-Console ("> " + $text) 'Gray'
    }
    catch { Write-Console ("[send failed] " + $_.Exception.Message) 'Salmon' }
}
$sendBtn.Add_Click({ Send-Input })
$sendBox.Add_KeyDown({ param($s, $e) if ($e.KeyCode -eq [System.Windows.Forms.Keys]::Enter) { $e.SuppressKeyPress = $true; Send-Input } })

$sshBtn.Add_Click({
        $key = Join-Path $HOME '.ssh/bluehost'
        if (-not (Test-Path $key)) { Write-Console "No SSH key at $key" 'Khaki'; return }
        Write-Console "Opening a console to run: ssh-add $key (type the passphrase there)" 'LightSkyBlue'
        Start-Process -FilePath $pwshPath -ArgumentList @('-NoExit', '-Command', "ssh-add `"$key`"")
    })
$refreshBtn.Add_Click({ Update-Status })

# ---------------------------------------------------------------- go

Update-Status
Write-Console 'Ikaika Dev Console ready.' 'LightGreen'
Write-Console 'Pick an env (test/prod) for the Airtable group, then run one action at a time.' 'Gray'
Write-Console 'Errors print here in red; each action ends with its exit code.' 'Gray'

[void]$form.ShowDialog()
