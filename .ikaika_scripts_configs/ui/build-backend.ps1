# Code-behind for build-backend.xaml. Behaviour only - it creates no controls and sets
# no colours or sizes. If something here starts building UI it belongs in the .xaml.
#
# The portal's copy of the toolkit's seed (lib/ui-templates), the project estimator backend's dialog: it runs
# this repo's build-release as one child - build, tag-version, server/deploy-release, in that order - with every
# choice as its arguments, so a bump survives "Run in this window" and keep is a version choice as well.
#
# Two modes, chosen by "Run in this window":
#   on  - the dialog runs the steps itself, streaming output into its console panel and
#         forwarding whatever is typed to the child's stdin, so a prompt can be answered
#         without a second window appearing
#   off - the dialog just returns the options and the caller runs them in the terminal
#         it was launched from
#
# Plain scriptblocks, never .GetNewClosure(): a closure runs in its own dynamic module,
# so "$script:x =" inside one writes somewhere the caller cannot read. That returned
# $null from every Build click once already. State goes through $state, a hashtable,
# which is captured by reference and works either way.
#
# Contract with the markup - Test-UiAsset checks exactly this list, without rendering:
function Get-BuildReleaseUiContract {
    return @('SubtitleText', 'OptionsPanel', 'ProductionRadio', 'StagingRadio', 'DeployCheck',
        'DeployHintText', 'DryRunCheck', 'CleanCheck', 'KeepBox', 'RunHereCheck', 'ErrorText',
        'VerSameRadio', 'VerKeepRadio', 'VerPatchRadio', 'VerMinorRadio', 'VerMajorRadio', 'VerCustomRadio',
        'VerCustomBox', 'VersionHintText', 'TagCheck',
        'ConsolePanel', 'ConsoleStatusText', 'ConsoleOutput', 'ConsoleInput', 'SendButton',
        'CopyLogButton', 'CancelButton', 'BackButton', 'BuildButton')
}

# The bump the radios currently describe - same, keep, patch, minor, major or an x.y.z - or $null if custom holds
# something that is not one. Kept as one function so the label, the enable/disable and the returned value cannot
# disagree about what is selected. Keep is the same x.y.z with the next build number, so it is a new version too.
function Resolve-PickedVersion($El, $Choices) {
    if ($El.VerCustomRadio.IsChecked) {
        $typed = "$($El.VerCustomBox.Text)".Trim()
        if ($typed -match '^\d+\.\d+\.\d+$') { return $typed }
        return $null
    }
    foreach ($pair in @(@('VerKeepRadio', 'keep'), @('VerPatchRadio', 'patch'),
            @('VerMinorRadio', 'minor'), @('VerMajorRadio', 'major'))) {
        if ($El[$pair[0]].IsChecked) { return $pair[1] }
    }
    return 'same'
}

# What a picked bump becomes, for the label beside it: x.y.z, and keep's same x.y.z with the next build.
function Get-PickedVersionName([string]$Picked, $Choices) {
    if ($Picked -eq 'keep') { return "$($Choices['same']) with the next build number" }
    if ($Choices.Contains($Picked)) { return $Choices[$Picked] }
    return $Picked
}

# Start-ConsoleStep, Read-ConsolePending, Stop-ConsoleSubscriptions and
# Stop-ConsoleProcess now live in lib\ui-console.ps1, which ui-host.ps1 dot-sources -
# build-release grew the same "run it here" box and a second copy of the ReadLineAsync
# reasoning would only ever be fixed in one of them.

function Show-BuildReleaseDialog {
    param(
        [string]$ProjectName,
        [string]$Version,
        [string]$RepoRoot,
        # This repo's build-release, which the run-here child is.
        [string]$BuildRelease
    )

    $ui = New-XamlWindow 'build-backend.xaml'
    $el = $ui.Elements
    $state = @{
        Result    = $null
        Ran       = $false
        OutTask   = $null
        ErrTask   = $null
        Subs      = @()
        Proc      = $null
        Steps     = @()
        StepIndex = 0
        Timer     = $null
        RepoRoot  = $RepoRoot
        ExitCode  = 0
    }

    $el.SubtitleText.Text = "$ProjectName $Version"

    # Shared arithmetic, not a second copy of it - lib/app-version.ps1; VERSION holds x.y.z+n.
    $choices = Get-VersionChoices ($Version -replace '\+.*$', '')
    $el.VerSameRadio.Content  = "same ($Version)"
    $el.VerKeepRadio.Content  = "keep ($($choices['same']), next build)"
    $el.VerPatchRadio.Content = "patch ($($choices['patch']))"
    $el.VerMinorRadio.Content = "minor ($($choices['minor']))"
    $el.VerMajorRadio.Content = "major ($($choices['major']))"
    $el.VerCustomBox.Text = $choices['patch']

    # Tagging is only meaningful for a NEW version: "same" rebuilds one that already
    # exists, so there is nothing to tag and the box greys out rather than quietly
    # creating a duplicate tag.
    $syncVersion = {
        $el.VerCustomBox.IsEnabled = [bool]$el.VerCustomRadio.IsChecked
        $picked = Resolve-PickedVersion $el $choices
        if ($null -eq $picked) {
            $el.VersionHintText.Text = 'Custom must be x.y.z.'
            $el.TagCheck.IsEnabled = $false
            return
        }
        $isNew = ($picked -ne 'same')
        $el.TagCheck.IsEnabled = $isNew
        if (-not $isNew) { $el.TagCheck.IsChecked = $false }
        $el.VersionHintText.Text = if ($isNew) {
            "Will build and package as $(Get-PickedVersionName $picked $choices) (currently $Version)."
        } else {
            "Rebuilds $Version - VERSION is not changed."
        }
    }
    foreach ($n in @('VerSameRadio', 'VerKeepRadio', 'VerPatchRadio', 'VerMinorRadio', 'VerMajorRadio', 'VerCustomRadio')) {
        [void]$el[$n].Add_Checked($syncVersion)
    }
    [void]$el.VerCustomBox.Add_TextChanged($syncVersion)
    & $syncVersion

    $syncDeploy = {
        $on = [bool]$el.DeployCheck.IsChecked
        $el.DryRunCheck.IsEnabled = $on
        $el.CleanCheck.IsEnabled = $on
        if (-not $on) { $el.DryRunCheck.IsChecked = $false; $el.CleanCheck.IsChecked = $false }
    }
    # Naming the actual destination, because this is the one label here where picking
    # the wrong radio writes to production.
    $syncTarget = {
        if ($el.StagingRadio.IsChecked) { $el.DeployHintText.Text = 'The Test Server, using debug.env: composer, the swap, migrations, then the health check.' }
        else { $el.DeployHintText.Text = 'PRODUCTION, using release.env: composer, the swap, migrations, then the health check.' }
    }
    [void]$el.DeployCheck.Add_Checked($syncDeploy)
    [void]$el.DeployCheck.Add_Unchecked($syncDeploy)
    [void]$el.ProductionRadio.Add_Checked($syncTarget)
    [void]$el.StagingRadio.Add_Checked($syncTarget)
    & $syncTarget
    & $syncDeploy

    # Verbatim: Read-ConsolePending hands over raw chunks, partial lines included, which
    # is what makes an unterminated "Continue? [y/N]: " prompt visible at all.
    $appendText = {
        param($text)
        [void]$el.ConsoleOutput.AppendText($text)
        $el.ConsoleOutput.ScrollToEnd()
    }
    # For this dialog's own framing lines, which are whole lines by definition.
    $appendLine = {
        param($text)
        & $appendText ($text + [Environment]::NewLine)
    }

    $finishRun = {
        Stop-ConsoleSubscriptions $state
        if ($state.Timer) { $state.Timer.Stop() }
        $el.ConsoleInput.IsEnabled = $false
        $el.SendButton.IsEnabled = $false
        $el.BuildButton.Content = 'Close'
        $el.BuildButton.IsEnabled = $true
        if ($state.ExitCode -eq 0) {
            $el.ConsoleStatusText.Text = 'Finished.'
        } else {
            $el.ConsoleStatusText.Text = "Failed - exit code $($state.ExitCode). The log above is the whole story."
        }
        $state.Ran = $true
    }

    $startNext = {
        if ($state.StepIndex -ge $state.Steps.Count) { & $finishRun; return }
        $step = $state.Steps[$state.StepIndex]
        $state.StepIndex++
        & $appendLine ''
        & $appendLine "> $($step.Label)"
        Start-ConsoleStep $state $step.Exe $step.Args
    }

    # One timer, on the UI thread, doing both jobs: draining output and noticing that a
    # step has ended. Draining first matters - a process can exit with lines still in
    # the queue, and finishing on HasExited alone loses the tail, which is usually the
    # error someone needs.
    # Same reason as the Click handler: a throw in a timer tick is swallowed, and the
    # dialog would just stop updating with no clue why.
    $onTick = {
      try {
        if (-not $state.Proc) { return }
        $drained = Read-ConsolePending $state $appendText
        # Both streams at end-of-stream AND the process gone. Waiting on HasExited alone
        # would cut off the tail, which is usually the error someone needs.
        if ($drained -and $state.Proc.HasExited) {
            $state.Proc.WaitForExit()
            $code = $state.Proc.ExitCode
            $state.Proc = $null
            if ($code -ne 0) {
                $state.ExitCode = $code
                & $finishRun
                return
            }
            & $startNext
        }
      } catch {
        $state.ExitCode = 1
        & $appendLine ('  console error: ' + $_.Exception.Message)
        & $finishRun
      }
    }

    [void]$el.SendButton.Add_Click({
        if (-not $state.Proc) { return }
        $text = $el.ConsoleInput.Text
        $el.ConsoleInput.Text = ''
        # Echoed locally because the child's stdin is a pipe, not a terminal: nothing
        # else will ever show what was typed, and a log that omits the answers is
        # useless afterwards.
        & $appendLine "< $text"
        try {
            $state.Proc.StandardInput.WriteLine($text)
        } catch {
            & $appendLine ('  (could not write to the process: ' + $_.Exception.Message + ')')
        }
    })
    [void]$el.ConsoleInput.Add_KeyDown({
        if ($_.Key -eq 'Return') {
            $el.SendButton.RaiseEvent((New-Object System.Windows.RoutedEventArgs([System.Windows.Controls.Button]::ClickEvent)))
        }
    })

    [void]$el.CopyLogButton.Add_Click({
        try {
            [System.Windows.Clipboard]::SetText($el.ConsoleOutput.Text)
            $el.ConsoleStatusText.Text = 'Log copied to the clipboard.'
        } catch {
            $el.ConsoleStatusText.Text = 'Could not copy: ' + $_.Exception.Message
        }
    })

    # EVERYTHING in here is wrapped, because WPF swallows an exception thrown inside a
    # Click handler: the dialog simply sits there looking dead, the user eventually
    # presses Cancel, and the terminal says "Cancelled - nothing was built" - which
    # describes the click that closed it and says nothing about the failure that made
    # them click. Any error now lands in the dialog where it happened.
    [void]$el.BuildButton.Add_Click({
      try {
        # Second press, once a run has finished, is Close.
        if ($state.Ran) { $ui.Window.Close(); return }
        if ($state.Proc) { return }

        $keep = 0
        if (-not [int]::TryParse($el.KeepBox.Text.Trim(), [ref]$keep) -or $keep -lt 1) {
            $el.ErrorText.Text = 'Keep must be a whole number of 1 or more.'
            return
        }
        $picked = Resolve-PickedVersion $el $choices
        if ($null -eq $picked) {
            $el.ErrorText.Text = 'Custom version must be x.y.z.'
            return
        }
        $opts = @{
            Debug      = [bool]$el.StagingRadio.IsChecked
            Deploy     = [bool]$el.DeployCheck.IsChecked
            DryRun     = [bool]$el.DryRunCheck.IsChecked
            Clean      = [bool]$el.CleanCheck.IsChecked
            Keep       = $keep
            RunHere    = [bool]$el.RunHereCheck.IsChecked
            Version    = $picked
            BumpNeeded = ($picked -ne 'same')
            Tag        = [bool]$el.TagCheck.IsChecked
        }
        $state.Result = $opts

        if (-not $opts.RunHere) { $ui.Window.Close(); return }

        # Same host that is running this dialog, so the child behaves the way the same
        # command would if typed by hand.
        $exe = (Get-Process -Id $PID).Path
        $q = '"'
        $state.Steps = @(
            @{ Label = 'build-release'; Exe = $exe
               Args  = "-NoProfile -ExecutionPolicy Bypass -File $q$BuildRelease$q -Keep $($opts.Keep)" +
                       $(if ($opts.Debug) { ' -Debug' } else { '' }) +
                       $(if ($opts.BumpNeeded) { " -Bump $($opts.Version)" + $(if ($opts.Tag) { ' -Tag' } else { '' }) } else { '' }) +
                       $(if ($opts.Deploy) { ' -Deploy' + $(if ($opts.DryRun) { ' -DryRun' } else { '' }) + $(if ($opts.Clean) { ' -Clean' } else { '' }) } else { '' }) }
        )
        $state.StepIndex = 0

        $el.OptionsPanel.Visibility = 'Collapsed'
        $el.ConsolePanel.Visibility = 'Visible'
        $el.CopyLogButton.Visibility = 'Visible'
        $el.ConsoleInput.IsEnabled = $true
        $el.SendButton.IsEnabled = $true
        $el.BuildButton.IsEnabled = $false
        $el.ConsoleStatusText.Text = 'Running...'
        $ui.Window.SizeToContent = 'Manual'
        $ui.Window.Height = 640

        $timer = New-Object System.Windows.Threading.DispatcherTimer
        $timer.Interval = [TimeSpan]::FromMilliseconds(80)
        [void]$timer.Add_Tick($onTick)
        $state.Timer = $timer
        $timer.Start()

        & $startNext
      } catch {
        # Shown in BOTH places: the options panel if we never got as far as switching
        # views, and the console if we did.
        $msg = 'Build failed to start: ' + $_.Exception.Message
        $el.ErrorText.Text = $msg
        $el.ConsoleStatusText.Text = $msg
        if ($el.ConsolePanel.Visibility -eq 'Visible') {
            & $appendLine $msg
            & $appendLine ('  at: ' + $_.InvocationInfo.PositionMessage)
        }
        if ($state.Timer) { $state.Timer.Stop() }
        $el.BuildButton.IsEnabled = $true
      }
    })

    # Cancel leaves Result as it is - and closing with the X or Escape never reaches a
    # handler at all, which means the same thing: nothing more to do.
    [void]$el.CancelButton.Add_Click({
        if ($state.Proc) {
            Stop-ConsoleProcess $state
            & $appendLine '  (cancelled - the running step was killed)'
        }
        if (-not $state.Ran) { $state.Result = $null }
        $ui.Window.Close()
    })

    # Back is Cancel plus a request to reopen the picker, and it drops the Result
    # UNCONDITIONALLY - not just when nothing ran. Going to the menu is not a choice about
    # this build, so the command that opened this dialog must not read it as one.
    #
    # Shown only when the picker opened this; see Test-UiLauncherContext.
    if (Test-UiLauncherContext) {
        $el.BackButton.Visibility = 'Visible'
        [void]$el.BackButton.Add_Click({
            if ($state.Proc) {
                Stop-ConsoleProcess $state
                & $appendLine '  (going back - the running step was killed)'
            }
            $state.Result = $null
            Request-UiBackToMenu
            $ui.Window.Close()
        })
    }

    [void]$ui.Window.Add_Closed({
        Stop-ConsoleProcess $state
        Stop-ConsoleSubscriptions $state
        if ($state.Timer) { $state.Timer.Stop() }
    })

    [void](Show-XamlDialog $ui)

    # Ran in the window: the caller must not run the same steps a second time.
    if ($state.Ran) { return @{ AlreadyRan = $true; ExitCode = $state.ExitCode } }
    return $state.Result
}

