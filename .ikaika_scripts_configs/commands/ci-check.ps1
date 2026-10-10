# Plays the Build release workflow through this checkout, so a change to the workflow, a ci-* script, secrets.json or the toolkit pin fails here and not on a GitHub runner.
# Nothing is pushed, built, deployed or released: each ci-* script runs in a child process against the answers and files a run would give it, with gh stood in for.
# The same file in every repo that has the workflow; a check for a script a repo does not have is left out rather than failed.
# A tool this machine lacks - actionlint (or uv to fetch it), Git Bash, PSScriptAnalyzer - is a SKIP, never a FAIL; a WARN is worth a look but passes.
# The toolkit is read from the commit the repo records for decoupled-script, which is what a runner fetches, not from whatever is checked out here.
# CHANGELOG.md, where a repo has one, is read by that toolkit's rules: a heading every build refuses fails, and no notes waiting for the next release, a CHANGELOG.json behind the file, anything its linter flags or a stray changelog file, warn.
#
#     .\script.ps1 ci-check            every local check
#     .\script.ps1 ci-check -Online    and the secrets on GitHub as well

# Advanced binding, so a mistyped switch is refused instead of silently ignored.
[CmdletBinding()]
param(
    # Also ask GitHub whether every secret the workflow reads exists, with git's stored github.com credential; no value is ever read.
    [switch]$Online,
    [switch]$Help
)

$ErrorActionPreference = 'Stop'

$configsDir = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $configsDir -Parent
$workflowRel = '.github/workflows/build-release.yml'
$workflowFile = Join-Path $repoRoot $workflowRel
$toolkitDir = Join-Path $repoRoot 'decoupled-script'

if ($Help) {
    Write-Host ''
    Write-Host 'ci-check - try the Build release workflow on this checkout before GitHub does' -ForegroundColor Cyan
    Write-Host ''
    Write-Host '  Usage'
    Write-Host '    .\script.ps1 ci-check [-Online]'
    Write-Host ''
    Write-Host '  Plays the workflow, the form against the plan, the toolkit pin, every ci-*'
    Write-Host '  script and the changelog. Nothing is pushed, built or uploaded; the exit'
    Write-Host '  code is 1 when any check fails. The form answers it tries live in'
    Write-Host '  .ikaika_scripts_configs/tests/ci-release-plan.cases.psd1.'
    Write-Host ''
    Write-Host '  Examples'
    Write-Host '    .\script.ps1 ci-check'
    Write-Host '        Every local check. Run it before pushing a change to the workflow,'
    Write-Host '        a ci-* script, secrets.json or the toolkit pin.'
    Write-Host ''
    Write-Host '    .\script.ps1 ci-check -Online'
    Write-Host '        The same, and whether each secret the workflow reads exists on GitHub,'
    Write-Host '        asked with git''s stored github.com credential; no value is read.'
    Write-Host ''
    exit 0
}

$pwshPath = (Get-Process -Id $PID).Path
$work = Join-Path ([IO.Path]::GetTempPath()) ("ci-check-" + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Path $work | Out-Null
$results = [Collections.Generic.List[object]]::new()

function Add-Result([string]$State, [string]$Check, [string]$Detail = '') {
    $results.Add([pscustomobject]@{ State = $State; Check = $Check; Detail = $Detail })
    $color = switch ($State) { 'PASS' { 'Green' } 'FAIL' { 'Red' } 'WARN' { 'Yellow' } default { 'DarkGray' } }
    Write-Host ("  {0,-4}  {1}{2}" -f $State, $Check, $(if ($Detail) { " - $Detail" } else { '' })) -ForegroundColor $color
}

function Write-Section([string]$Title) {
    Write-Host ''
    Write-Host $Title -ForegroundColor Cyan
}

# git with 'Continue', so a message on stderr is output rather than an error that stops the check.
function Invoke-Git {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = @(& git @args 2>$null)
        return @{ Code = $LASTEXITCODE; Lines = $output; Text = ($output -join "`n") }
    } finally {
        $ErrorActionPreference = $previous
    }
}

# A child pwsh given only these variables on top of this one; GitHub's own are removed, so nothing lands in a real run's files.
function Invoke-Child([string]$File, [string[]]$Arguments = @(), [hashtable]$Environment = @{}) {
    $psi = [Diagnostics.ProcessStartInfo]::new($pwshPath)
    foreach ($a in @('-NoProfile', '-File', $File) + $Arguments) { $psi.ArgumentList.Add($a) }
    $psi.WorkingDirectory = $repoRoot
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    foreach ($name in @($psi.Environment.Keys | Where-Object { $_ -like 'GITHUB_*' -or $_ -eq 'RUNNER_TEMP' })) { [void]$psi.Environment.Remove($name) }
    foreach ($name in $Environment.Keys) { $psi.Environment[$name] = "$($Environment[$name])" }
    $process = [Diagnostics.Process]::Start($psi)
    $stdout = $process.StandardOutput.ReadToEndAsync()
    $stderr = $process.StandardError.ReadToEndAsync()
    $process.WaitForExit()
    return @{ Code = $process.ExitCode; Out = $stdout.Result + $stderr.Result }
}

# ci-release-plan.ps1 with one set of form answers, as the plan step runs it: what it printed, exited with, planned and handed the jobs.
function Invoke-Plan([hashtable]$Answers, [string]$Ref) {
    $id = [guid]::NewGuid().ToString('N').Substring(0, 8)
    $planFile = Join-Path $work "plan-$id.json"
    $outputFile = Join-Path $work "output-$id"
    $envFile = Join-Path $work "env-$id"
    $environment = @{
        INPUTS_JSON = ($Answers | ConvertTo-Json -Compress)
        GITHUB_REF = $Ref
        GITHUB_REF_TYPE = $(if ($Ref -like 'refs/tags/*') { 'tag' } else { 'branch' })
        GITHUB_REF_NAME = ($Ref -replace '^refs/(heads|tags)/', '')
        GITHUB_OUTPUT = $outputFile
        GITHUB_ENV = $envFile
    }
    $run = Invoke-Child (Join-Path $configsDir 'ci-release-plan.ps1') @('-PlanFile', $planFile) $environment
    $outputs = @{}
    foreach ($file in $outputFile, $envFile) {
        if (Test-Path -LiteralPath $file) { foreach ($line in Get-Content -LiteralPath $file) { $key, $value = $line -split '=', 2; $outputs[$key] = $value } }
    }
    $plan = if (Test-Path -LiteralPath $planFile) { Get-Content -LiteralPath $planFile -Raw | ConvertFrom-Json -AsHashtable } else { $null }
    return @{ Code = $run.Code; Out = $run.Out; Outputs = $outputs; Plan = $plan }
}

# The workflow_dispatch inputs - name, type, options, default - read from the block GitHub reads them from.
function Get-WorkflowInputs([string]$Text) {
    $inputs = [ordered]@{}
    $inBlock = $false
    $current = $null
    foreach ($line in $Text -split "`n") {
        if ($line -match '^    inputs:\s*$') { $inBlock = $true; continue }
        if (-not $inBlock -or $line -match '^\s*$') { continue }
        if ($line -match '^ {0,4}\S') { break }
        if ($line -match '^      ([A-Za-z0-9_-]+):\s*$') {
            $current = @{ type = 'string'; options = @(); default = $null }
            $inputs[$Matches[1]] = $current
            continue
        }
        if ($current -and $line -match '^        (type|default|options):\s*(.*)$') {
            $key = $Matches[1]
            $value = $Matches[2].Trim()
            if ($key -eq 'options') { $current.options = @($value.Trim('[', ']') -split ',' | ForEach-Object { $_.Trim().Trim("'", '"') } | Where-Object { $_ }) }
            else { $current[$key] = $value.Trim("'", '"') }
        }
    }
    return $inputs
}

# Each step's inline script with the shell it runs in: the step's own shell, else its job's default.
function Get-RunBlocks([string]$Text) {
    $blocks = @()
    $lines = $Text -split "`n"
    $job = ''; $jobShell = ''; $inSteps = $false; $step = $null
    for ($i = 0; $i -lt $lines.Count; $i++) {
        $line = $lines[$i]
        if ($line -match '^  ([A-Za-z0-9_-]+):\s*$') { $job = $Matches[1]; $jobShell = ''; $inSteps = $false; $step = $null; continue }
        if ($line -match '^    steps:\s*$') { $inSteps = $true; continue }
        if (-not $inSteps) { if ($line -match '^        shell:\s*(\w+)') { $jobShell = $Matches[1] }; continue }
        if ($line -match '^      - ') { $step = @{ Job = $job; Shell = ''; DefaultShell = $jobShell; Name = ''; Script = $null }; $blocks += $step }
        if (-not $step) { continue }
        if ($line -match '^      (?:- |  )name:\s*(.+)$') { $step.Name = $Matches[1].Trim() }
        if ($line -match '^        shell:\s*(\w+)' -or $line -match '^      - shell:\s*(\w+)') { $step.Shell = $Matches[1] }
        if ($line -match '^      (?:- |  )run:\s*\|\s*$') {
            $body = @()
            while ($i + 1 -lt $lines.Count -and ($lines[$i + 1] -match '^\s*$' -or $lines[$i + 1] -match '^ {10}')) {
                $i++
                $body += $(if ($lines[$i].Length -ge 10) { $lines[$i].Substring(10) } else { '' })
            }
            $step.Script = $body -join "`n"
        } elseif ($line -match '^      (?:- |  )run:\s*(.+)$') {
            $step.Script = $Matches[1]
        }
    }
    return @($blocks | Where-Object { $null -ne $_.Script } | ForEach-Object { if (-not $_.Shell) { $_.Shell = $_.DefaultShell }; $_ })
}

function Get-LiftedFunction([string]$Text, [string]$Name) {
    $ast = [Management.Automation.Language.Parser]::ParseInput($Text, [ref]$null, [ref]$null)
    $found = $ast.FindAll({ param($n) $n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $Name }, $true) | Select-Object -First 1
    if ($found) { return $found.Extent.Text }
    return $null
}

function Get-ConfigValue([string]$Key) {
    $line = Get-Content -LiteralPath (Join-Path $repoRoot 'ikaika_scripts.env') | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -First 1
    return "$($line -replace '^[^=]*=', '')".Trim()
}

try {
    $yaml = if (Test-Path -LiteralPath $workflowFile) { [IO.File]::ReadAllText($workflowFile) -replace "`r`n", "`n" } else { '' }
    $secretsJson = Get-Content -LiteralPath (Join-Path $configsDir 'secrets.json') -Raw | ConvertFrom-Json
    $declared = @{}
    foreach ($secret in $secretsJson.secrets) { $declared[$secret.name] = $secret }
    # Inside ${{ }} only: secrets.ps1, the command, is named in plenty of comments and run lines.
    $referenced = @([regex]::Matches($yaml, '\$\{\{.*?\}\}') | ForEach-Object { [regex]::Matches($_.Value, '\bsecrets\.([A-Za-z0-9_]+)') } | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique)
    $inputs = Get-WorkflowInputs $yaml
    $bash = $null
    $gitCommand = Get-Command git -ErrorAction SilentlyContinue
    if ($gitCommand) {
        $gitRoot = Split-Path $gitCommand.Source -Parent
        while ($gitRoot -and -not (Test-Path -LiteralPath (Join-Path $gitRoot 'bin\bash.exe'))) { $gitRoot = Split-Path $gitRoot -Parent }
        if ($gitRoot) { $bash = Join-Path $gitRoot 'bin\bash.exe' }
    }

    # ---------------------------------------------------------------- the workflow
    Write-Section "Workflow ($workflowRel)"
    if (-not $yaml) {
        Add-Result 'FAIL' 'workflow file' "$workflowRel is missing"
    } else {
        $workflows = @(Get-ChildItem -LiteralPath (Split-Path $workflowFile) -Filter '*.yml' | ForEach-Object FullName)
        $actionlint = Get-Command actionlint -ErrorAction SilentlyContinue
        $uvx = Get-Command uvx -ErrorAction SilentlyContinue
        if ($actionlint -or $uvx) {
            $previous = $ErrorActionPreference
            $ErrorActionPreference = 'Continue'
            $lint = if ($actionlint) { @(& $actionlint.Source -shellcheck= -pyflakes= @workflows 2>&1) } else { @(& $uvx.Source --quiet --from actionlint-py actionlint -shellcheck= -pyflakes= @workflows 2>&1) }
            $lintCode = $LASTEXITCODE
            $ErrorActionPreference = $previous
            if ($lintCode -eq 0) { Add-Result 'PASS' 'actionlint' "$($workflows.Count) workflow file(s)" }
            else { Add-Result 'FAIL' 'actionlint' (($lint | Select-Object -First 4 | ForEach-Object { "$_" }) -join ' | ') }
        } else {
            Add-Result 'SKIP' 'actionlint' 'neither actionlint nor uv (to fetch it) is on PATH'
        }

        $inputProblems = @()
        if (-not $inputs.Count) { $inputProblems += 'no workflow_dispatch inputs could be read' }
        if ($inputs.Count -gt 25) { $inputProblems += "$($inputs.Count) inputs, and GitHub allows 25" }
        foreach ($name in $inputs.Keys) {
            $field = $inputs[$name]
            if ($field.type -eq 'choice' -and $field.options -cnotcontains $field.default) { $inputProblems += "$name defaults to '$($field.default)', which is not among its options" }
            if ($field.type -eq 'boolean' -and $field.default -notin @('true', 'false')) { $inputProblems += "$name is a boolean defaulting to '$($field.default)'" }
        }
        if ($inputProblems) { Add-Result 'FAIL' 'form inputs' ($inputProblems -join '; ') }
        else { Add-Result 'PASS' 'form inputs' "$($inputs.Count) of GitHub's 25, every default a value the input accepts" }

        $uses = @([regex]::Matches($yaml, '(?m)^\s*(?:-\s+)?uses:\s*([^\s#]+)') | ForEach-Object { $_.Groups[1].Value })
        $loose = @($uses | Where-Object { $_ -notmatch '^\./' -and $_ -notmatch '@[0-9a-f]{40}$' })
        if ($loose) { Add-Result 'FAIL' 'actions pinned to a commit' "not pinned to a full SHA: $($loose -join ', ')" }
        else { Add-Result 'PASS' 'actions pinned to a commit' "$($uses.Count) uses, each at a full SHA" }

        $undeclared = @($referenced | Where-Object { -not $declared.ContainsKey($_) })
        $notOnGitHub = @($referenced | Where-Object { $declared.ContainsKey($_) -and $declared[$_].kind -notin @('workflow', 'link', 'password') })
        if ($undeclared -or $notOnGitHub) {
            Add-Result 'FAIL' 'secrets the workflow reads' ("$(if ($undeclared) { "not in secrets.json: $($undeclared -join ', ')" }) $(if ($notOnGitHub) { "sealed in the archive, not GitHub secrets: $($notOnGitHub -join ', ')" })".Trim())
        } else {
            Add-Result 'PASS' 'secrets the workflow reads' "$($referenced.Count), each declared in secrets.json as a GitHub secret"
        }

        $mainRef = foreach ($candidate in 'origin/main', 'main') { if ((Invoke-Git -C $repoRoot rev-parse --verify --quiet $candidate).Code -eq 0) { $candidate; break } }
        if (-not $mainRef) {
            Add-Result 'SKIP' "main's copy of the workflow" 'no main branch here'
        } else {
            $mainText = (Invoke-Git -C $repoRoot show "$($mainRef):$workflowRel").Text -replace "`r`n", "`n"
            if ($mainText.TrimEnd() -ceq $yaml.TrimEnd()) { Add-Result 'PASS' "main's copy of the workflow" "the same as $mainRef" }
            else { Add-Result 'WARN' "main's copy of the workflow" "$mainRef has a different one; GitHub lists the workflow from main, so commit this one there too" }
        }

        $eol = @((Invoke-Git -C $repoRoot ls-files --eol -- '*.sh' '.github/workflows/*.yml').Lines)
        $crlf = @($eol | Where-Object { $_ -match '\bi/crlf\b' -or ($_ -match '\.sh$' -and $_ -match '\bw/crlf\b') } | ForEach-Object { ($_ -split "`t")[-1] })
        if ($crlf) { Add-Result 'FAIL' 'line endings' "CRLF where a runner needs LF: $($crlf -join ', ')" }
        else { Add-Result 'PASS' 'line endings' "$($eol.Count) shell script(s) and workflow(s), LF" }

        $runBlocks = Get-RunBlocks $yaml
        $badRuns = @()
        $bashChecked = 0
        foreach ($block in $runBlocks) {
            # A runner substitutes ${{ }} before the shell sees it, so the check does the same with a stand-in.
            $script = [regex]::Replace($block.Script, '\$\{\{.*?\}\}', 'GH_EXPRESSION')
            if ($block.Shell -eq 'pwsh') {
                $parseErrors = $null
                [void][Management.Automation.Language.Parser]::ParseInput($script, [ref]$null, [ref]$parseErrors)
                if ($parseErrors) { $badRuns += "$($block.Job) / $($block.Name): $($parseErrors[0].Message)" }
            } elseif ($block.Shell -eq 'bash' -and $bash) {
                $file = Join-Path $work ("run-" + [guid]::NewGuid().ToString('N').Substring(0, 6) + '.sh')
                [IO.File]::WriteAllText($file, $script + "`n")
                $previous = $ErrorActionPreference
                $ErrorActionPreference = 'Continue'
                $syntax = @(& $bash -n $file 2>&1)
                $syntaxCode = $LASTEXITCODE
                $ErrorActionPreference = $previous
                $bashChecked++
                if ($syntaxCode -ne 0) { $badRuns += "$($block.Job) / $($block.Name): $($syntax | Select-Object -First 1)" }
            }
        }
        $pwshBlocks = @($runBlocks | Where-Object Shell -eq 'pwsh').Count
        if ($badRuns) { Add-Result 'FAIL' "steps' inline scripts" ($badRuns -join '; ') }
        else { Add-Result 'PASS' "steps' inline scripts" "$pwshBlocks PowerShell and $bashChecked bash script(s) parse$(if (-not $bash -and $runBlocks.Count -gt $pwshBlocks) { ', bash skipped: no Git Bash' })" }
    }

    # ---------------------------------------------------------------- the form against the plan
    Write-Section 'Form and plan (ci-release-plan.ps1)'
    $planScript = Join-Path $configsDir 'ci-release-plan.ps1'
    $data = $null
    $casesFile = Join-Path $configsDir 'tests\ci-release-plan.cases.psd1'
    if (Test-Path -LiteralPath $casesFile) { $data = Import-PowerShellDataFile -LiteralPath $casesFile }
    $defaultRef = if ($data -and $data.DefaultRef) { $data.DefaultRef } else { 'refs/heads/ci-check' }
    $choices = @($inputs.Keys | Where-Object { $inputs[$_].type -eq 'choice' })
    $planAst = [Management.Automation.Language.Parser]::ParseFile($planScript, [ref]$null, [ref]$null)
    $allowedAst = $planAst.FindAll({ param($n) $n -is [Management.Automation.Language.AssignmentStatementAst] -and "$($n.Left)" -eq '$allowed' }, $true) | Select-Object -First 1
    $drift = @()
    if ($allowedAst) {
        # One table of what each choice takes: compared as it stands.
        $allowed = & ([scriptblock]::Create($allowedAst.Right.Extent.Text))
        foreach ($name in $allowed.Keys) {
            if (-not $inputs.Contains($name)) { $drift += "the plan checks '$name', which the form does not have"; continue }
            $offered = @($inputs[$name].options | Sort-Object)
            $taken = @($allowed[$name] | Sort-Object)
            if (($offered -join ',') -cne ($taken -join ',')) { $drift += "$name - the form offers $($offered -join '/'), the plan takes $($taken -join '/')" }
        }
        $unchecked = @($choices | Where-Object { -not $allowed.Contains($_) })
    } else {
        # No table: every option is put to the plan, and a value no form can send must be refused.
        $unchecked = @()
        foreach ($name in $choices) {
            foreach ($value in $inputs[$name].options) {
                if ((Invoke-Plan @{ $name = $value } $defaultRef).Out -match "$([regex]::Escape($name)) is '$([regex]::Escape($value))'; it takes") { $drift += "the plan refuses $name '$value', which the form offers" }
            }
            if ((Invoke-Plan @{ $name = 'ci-check-not-an-option' } $defaultRef).Out -notmatch "$([regex]::Escape($name)) is 'ci-check-not-an-option'") { $unchecked += $name }
        }
    }
    if ($drift) { Add-Result 'FAIL' 'form choices against the plan' ($drift -join '; ') }
    elseif ($unchecked) { Add-Result 'WARN' 'form choices against the plan' "choices the plan never checks: $($unchecked -join ', ')" }
    else { Add-Result 'PASS' 'form choices against the plan' "$($choices.Count) choice inputs, every option one the plan takes" }

    $planKeys = @{}
    $deployKeys = @{}
    $purposes = @{}
    if (-not $data) {
        Add-Result 'FAIL' 'plan scenarios' "$casesFile is missing"
    } else {
        $failed = @()
        foreach ($case in $data.Cases) {
            $run = Invoke-Plan $case.Answers $(if ($case.Ref) { $case.Ref } else { $defaultRef })
            $plan = $run.Plan
            $problems = @()
            if ($run.Code -ne $case.Exit) { $problems += "exit $($run.Code), expected $($case.Exit)$(if ($run.Code -ne 0) { ': ' + (($run.Out -split "`n" | Where-Object { $_ -match '::error' } | Select-Object -First 1) -replace '^::error[^:]*::', '') })" }
            if ($case.Plan) { foreach ($key in $case.Plan.Keys) { $got = if ($plan) { @($plan[$key]) -join ',' } else { '<no plan>' }; if ($got -ne (@($case.Plan[$key]) -join ',')) { $problems += "$key = '$got', expected '$(@($case.Plan[$key]) -join ',')'" } } }
            if ($case.Outputs) { foreach ($key in $case.Outputs.Keys) { if ("$($run.Outputs[$key])" -cne "$($case.Outputs[$key])") { $problems += "output $key = '$($run.Outputs[$key])', expected '$($case.Outputs[$key])'" } } }
            if ($case.Absent) { foreach ($key in $case.Absent) { if ($plan -and $plan.Contains($key)) { $problems += "$key is in the plan, and should not be" } } }
            if ($case.Says -and -not $run.Out.Contains($case.Says)) { $problems += "the output does not say '$($case.Says)'" }
            if ($problems) { $failed += "$($case.Name): $($problems[0])" }
            if ($run.Code -eq 0 -and $plan) { foreach ($key in $plan.Keys) { $planKeys[$key] = $true } }
            if ($run.Code -eq 0 -and $run.Outputs['windows_plan']) { foreach ($key in ($run.Outputs['windows_plan'] | ConvertFrom-Json -AsHashtable).Keys) { $planKeys[$key] = $true } }
            if ($run.Code -eq 0 -and $run.Outputs['deploy_plan']) { foreach ($key in ($run.Outputs['deploy_plan'] | ConvertFrom-Json -AsHashtable).Keys) { $deployKeys[$key] = $true } }
            foreach ($purpose in "$($run.Outputs['purposes'])$(if ($run.Outputs['PURPOSES']) { ',' + $run.Outputs['PURPOSES'] })" -split ',') { if ($purpose) { $purposes[$purpose] = $true } }
        }
        if ($failed) { Add-Result 'FAIL' 'plan scenarios' "$($failed.Count) of $($data.Cases.Count): $($failed -join ' | ')" }
        else { Add-Result 'PASS' 'plan scenarios' "$($data.Cases.Count) form answers, each planned as expected" }

        $unknownPurposes = @($purposes.Keys | Where-Object { -not $secretsJson.purposes.PSObject.Properties[$_] } | Sort-Object)
        if ($unknownPurposes) { Add-Result 'FAIL' 'secrets purposes' "the plan asks secrets.ps1 for purposes secrets.json lacks: $($unknownPurposes -join ', ')" }
        else { Add-Result 'PASS' 'secrets purposes' "$($purposes.Count) the plan can ask for, each in secrets.json" }
    }

    # ---------------------------------------------------------------- the toolkit the workflow fetches
    Write-Section 'Toolkit (decoupled-script, as the commit records it)'
    $gitlink = ((Invoke-Git -C $repoRoot ls-files -s decoupled-script).Text -split '\s+')[1]
    $pin = Get-ConfigValue 'scripts.version'
    $toolkitReadable = $gitlink -and (Test-Path -LiteralPath (Join-Path $toolkitDir '.git')) -and (Invoke-Git -C $toolkitDir cat-file -e "$gitlink^{commit}").Code -eq 0
    $toolkitOnDisk = $false
    $toolkitFiles = @{}
    if (-not $toolkitReadable) {
        Add-Result 'SKIP' 'toolkit checks' "the recorded decoupled-script commit $gitlink is not here; run git submodule update --init"
    } else {
        $tags = @((Invoke-Git -C $toolkitDir tag --points-at $gitlink).Lines)
        if ($tags -contains "v$pin") { Add-Result 'PASS' 'pin and submodule agree' "scripts.version $pin is the commit decoupled-script records" }
        else { Add-Result 'FAIL' 'pin and submodule agree' "scripts.version says $pin, but the recorded commit is $(if ($tags) { $tags -join ', ' } else { $gitlink.Substring(0, 7) }); a runner fetches the commit - move both with .\script.ps1 use-version" }

        $toolkitOnDisk = (Invoke-Git -C $toolkitDir rev-parse HEAD).Text.Trim() -eq $gitlink
        if (-not $toolkitOnDisk) { Add-Result 'WARN' 'toolkit checked out here' "decoupled-script is at $((Invoke-Git -C $toolkitDir describe --tags --always).Text.Trim()), not the recorded $(if ($tags) { $tags[0] } else { $gitlink.Substring(0, 7) }); git submodule update lines it up" }

        # What the CI scripts take from the toolkit: functions they lift, and the lib files the repo's commands dot-source.
        $needed = [ordered]@{}
        if (Test-Path -LiteralPath (Join-Path $configsDir 'ci-next-version.ps1')) {
            $needed['lib/toolkit-version.ps1'] = @('Get-BumpedVersion')
            $needed['lib/app-version.ps1'] = @('Get-VersionChoices', 'Get-NextBuildNumber')
        }
        if (Test-Path -LiteralPath (Join-Path $configsDir 'ci-ubuntu.sh')) { $needed['flutter/build-release.ps1'] = @('Get-ExtraArtifactPlatform') }
        if (Test-Path -LiteralPath (Join-Path $repoRoot 'CHANGELOG.md')) { $needed['lib/changelog.ps1'] = @('Test-ChangelogMarkdown', 'Get-ChangelogDrafts', 'ConvertTo-ChangelogDocument') }
        foreach ($command in Get-ChildItem -LiteralPath (Join-Path $configsDir 'commands') -Filter '*.ps1') {
            foreach ($match in [regex]::Matches([IO.File]::ReadAllText($command.FullName), "Join-Path \`$libDir '([\w.-]+\.ps1)'")) {
                $key = "lib/$($match.Groups[1].Value)"
                if (-not $needed.Contains($key)) { $needed[$key] = @() }
            }
        }
        $missing = @()
        foreach ($file in $needed.Keys) {
            $read = Invoke-Git -C $toolkitDir show "$($gitlink):$file"
            if ($read.Code -ne 0) { $missing += $file; continue }
            $toolkitFiles[$file] = $read.Text
            foreach ($function in $needed[$file]) { if (-not (Get-LiftedFunction $read.Text $function)) { $missing += "$function in $file" } }
        }
        if ($missing) { Add-Result 'FAIL' 'what the repo takes from the toolkit' "gone from the pinned toolkit: $($missing -join ', ')" }
        else { Add-Result 'PASS' 'what the repo takes from the toolkit' "$($needed.Count) file(s) and every function the CI scripts lift, all still there" }
    }

    # The command the build step splats the plan into, read where a runner would find it: this repo's own commands first, else the pinned toolkit.
    $buildCommand = if ($yaml -match 'script\.ps1\s+(\S+)\s+@plan') { $Matches[1] } else { '' }
    $buildSource = ''
    if ($buildCommand) {
        $local = Join-Path $configsDir "commands\$($buildCommand -replace '/', '\').ps1"
        if (Test-Path -LiteralPath $local) { $buildSource = [IO.File]::ReadAllText($local) }
        elseif ($toolkitReadable) { $buildSource = (Invoke-Git -C $toolkitDir show "$($gitlink):$buildCommand.ps1").Text }
    }
    if (-not $buildCommand -or -not $buildSource) {
        Add-Result 'SKIP' 'build-release arguments' "$(if ($buildCommand) { "$buildCommand could not be read" } else { 'the workflow splats no plan into a command' })"
    } elseif ($planKeys.Count) {
        $buildAst = [Management.Automation.Language.Parser]::ParseInput($buildSource, [ref]$null, [ref]$null)
        $parameters = @($buildAst.ParamBlock.Parameters | ForEach-Object { $_.Name.VariablePath.UserPath })
        $handed = @($planKeys.Keys) + @(if ($yaml -match '\$plan\.ExtraArtifactPath') { 'ExtraArtifactPath' })
        $unknown = @($handed | Where-Object { $parameters -notcontains $_ } | Sort-Object -Unique)
        if ($unknown) { Add-Result 'FAIL' 'build-release arguments' "$buildCommand has no parameter: $($unknown -join ', ')" }
        else { Add-Result 'PASS' 'build-release arguments' "$($handed.Count) the plan and workflow can pass, each a parameter of $buildCommand" }
    }

    # Every command a step runs, found where the dispatcher would find it: this repo's commands, else the pinned toolkit's.
    $toolkitTree = if ($toolkitReadable) { @((Invoke-Git -C $toolkitDir ls-tree -r --name-only $gitlink).Lines) } else { @() }
    function Get-CommandSource([string]$Name) {
        $local = Join-Path $configsDir "commands\$($Name -replace '/', '\').ps1"
        if (Test-Path -LiteralPath $local) { return [IO.File]::ReadAllText($local) }
        $file = @($toolkitTree | Where-Object { $_ -eq "$Name.ps1" -or $_ -match "(^|/)$([regex]::Escape($Name))\.ps1$" })[0]
        if ($file) { return (Invoke-Git -C $toolkitDir show "$($gitlink):$file").Text }
        return $null
    }
    $called = @([regex]::Matches($yaml, 'script\.ps1\s+([a-z][\w/-]*)') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique)
    if (-not $toolkitReadable) { Add-Result 'SKIP' 'commands the steps run' 'the pinned toolkit is not here to look in' }
    else {
        $absent = @($called | Where-Object { $null -eq (Get-CommandSource $_) })
        if ($absent) { Add-Result 'FAIL' 'commands the steps run' "neither this repo nor the pinned toolkit has: $($absent -join ', ')" }
        else { Add-Result 'PASS' 'commands the steps run' "$($called -join ', '), each in this repo or the pinned toolkit" }
    }

    # The upload is a step of its own, splatting the plan's deploy_plan into the command that uploads.
    $deployCommand = if ($yaml -match 'script\.ps1\s+(\S+)\s+@deploy') { $Matches[1] } else { '' }
    if ($deployCommand -and $deployKeys.Count) {
        $deploySource = if ($toolkitReadable) { Get-CommandSource $deployCommand } else { $null }
        if (-not $deploySource) { Add-Result 'SKIP' 'upload arguments' "$deployCommand could not be read" }
        else {
            $deployParameters = @([Management.Automation.Language.Parser]::ParseInput($deploySource, [ref]$null, [ref]$null).ParamBlock.Parameters | ForEach-Object { $_.Name.VariablePath.UserPath })
            $unknownDeploy = @($deployKeys.Keys | Where-Object { $deployParameters -notcontains $_ } | Sort-Object)
            if ($unknownDeploy) { Add-Result 'FAIL' 'upload arguments' "$deployCommand has no parameter: $($unknownDeploy -join ', ')" }
            else { Add-Result 'PASS' 'upload arguments' "$($deployKeys.Count) the plan can pass, each a parameter of $deployCommand" }
        }
    }

    $pubspec = Join-Path $repoRoot 'pubspec.yaml'
    $versionLine = if (Test-Path -LiteralPath $pubspec) { Get-Content -LiteralPath $pubspec | Where-Object { $_ -match '^version:' } | Select-Object -First 1 } else { '' }
    $prefix = Get-ConfigValue 'artifact.prefix'
    $nextVersion = Join-Path $configsDir 'ci-next-version.ps1'
    if ((Test-Path -LiteralPath $nextVersion) -and $toolkitReadable) {
        if ($versionLine -notmatch '^version:\s*(\d+\.\d+\.\d+)(?:\+(\d+))?\s*$') {
            Add-Result 'FAIL' 'pubspec version' "build-release cannot read '$versionLine'"
        } elseif (-not $toolkitOnDisk) {
            Add-Result 'SKIP' 'ci-next-version against the toolkit' 'it loads the toolkit from disk, and the checkout is not the recorded commit'
        } else {
            $current = $Matches[1]
            $currentBuild = if ($Matches[2]) { [int]$Matches[2] } else { 0 }
            foreach ($file in 'lib/toolkit-version.ps1', 'lib/app-version.ps1') {
                foreach ($function in $needed[$file]) { . ([scriptblock]::Create((Get-LiftedFunction $toolkitFiles[$file] $function))) }
            }
            $build = Get-NextBuildNumber -CurrentBuild $currentBuild -TagNames @((Invoke-Git -C $repoRoot for-each-ref refs/tags --format='%(refname:short)').Lines)
            $versionChoices = Get-VersionChoices $current
            $versionChoices['keep'] = $versionChoices['same']
            $wrong = @()
            foreach ($bump in 'keep', 'patch', 'minor', 'major', '9.8.7') {
                $copy = Join-Path $work "pubspec-$bump.yaml"
                Copy-Item -LiteralPath $pubspec -Destination $copy
                $run = Invoke-Child $nextVersion @('-Bump', $bump, '-PubspecPath', $copy)
                $expected = "$(if ($versionChoices.Contains($bump)) { $versionChoices[$bump] } else { $bump })+$build"
                $written = (Get-Content -LiteralPath $copy | Where-Object { $_ -match '^version:' } | Select-Object -First 1) -replace '^version:\s*', ''
                $others = Compare-Object @(Get-Content -LiteralPath $copy | Where-Object { $_ -notmatch '^version:' }) @(Get-Content -LiteralPath $pubspec | Where-Object { $_ -notmatch '^version:' })
                if ($run.Code -ne 0 -or $written -ne $expected -or $others) { $wrong += "$bump wrote '$written', expected '$expected'$(if ($others) { ' and changed other lines' })" }
            }
            if ($wrong) { Add-Result 'FAIL' 'ci-next-version against the toolkit' ($wrong -join '; ') }
            else { Add-Result 'PASS' 'ci-next-version against the toolkit' "keep, patch, minor, major and x.y.z each write what build-release will bump to (keep: $($versionChoices['keep'])+$build)" }
        }
    }

    # Every file the web build is handed instead of building it: Ubuntu's two, by ci-ubuntu.sh's naming, and the Windows job's own two from its first build-release.
    $ubuntuScript = Join-Path $configsDir 'ci-ubuntu.sh'
    $acceptor = if ($toolkitFiles['flutter/build-release.ps1']) { Get-LiftedFunction $toolkitFiles['flutter/build-release.ps1'] 'Get-ExtraArtifactPlatform' } else { $null }
    if ((Test-Path -LiteralPath $ubuntuScript) -and $acceptor -and $versionLine -match '^version:\s*(\S+)') {
        . ([scriptblock]::Create($acceptor))
        $version = $Matches[1]
        $naming = [IO.File]::ReadAllText($ubuntuScript)
        $refusals = @()
        if ($naming -notmatch 'stem="\$prefix-\$version\$debug_tag-linux"' -or $naming -notmatch 'dist/\$stem\.tar\.gz') { $refusals += 'ci-ubuntu.sh no longer names the tarball <prefix>-<version>[-debug]-linux.tar.gz' }
        if ($naming -notmatch 'stem="\$prefix-\$version\$debug_tag"' -or $naming -notmatch 'dist/\$stem\.apk') { $refusals += 'ci-ubuntu.sh no longer names the APK <prefix>-<version>[-debug].apk' }
        foreach ($tag in '-debug', '') {
            $stem = "$prefix-$version$tag"
            foreach ($file in @{ Name = "$stem-linux.tar.gz"; Platform = 'linux' }, @{ Name = "$stem.apk"; Platform = 'android' }, @{ Name = "$stem-windows.zip"; Platform = 'windows' }, @{ Name = "$stem-windows-setup.exe"; Platform = 'windows' }) {
                $check = Get-ExtraArtifactPlatform $file.Name $stem
                if ($check.Error -or $check.Platform -ne $file.Platform) { $refusals += "$($file.Name): $($check.Error)" }
            }
        }
        if ($refusals) { Add-Result 'FAIL' 'what the web build is handed' ($refusals -join '; ') }
        else { Add-Result 'PASS' 'what the web build is handed' "build-release bundles $prefix-$version[-debug]-linux.tar.gz and .apk as ci-ubuntu.sh names them, and the Windows zip and installer" }
    }

    # ---------------------------------------------------------------- the notes the next build publishes
    # Read by the pinned toolkit's own rules: a file it refuses fails every build, while missing notes or a json
    # behind the file only warn - a release may ship without notes, and the release that tags rewrites the json.
    $changelogFile = Join-Path $repoRoot 'CHANGELOG.md'
    if ((Test-Path -LiteralPath $changelogFile) -and $toolkitFiles['lib/changelog.ps1']) {
        Write-Section 'Changelog (CHANGELOG.md, as the pinned toolkit reads it)'
        try {
            . ([scriptblock]::Create($toolkitFiles['lib/changelog.ps1']))
            $notesLines = @(Get-Content -LiteralPath $changelogFile)
            $notesProblems = @(Test-ChangelogMarkdown $notesLines)
            if ($notesProblems) {
                Add-Result 'FAIL' 'changelog format' "every build refuses it - $("$($notesProblems[0])".TrimEnd('.'))$(if ($notesProblems.Count -gt 1) { " (and $($notesProblems.Count - 1) more)" }); .\script.ps1 changelog -Edit opens it on the first"
            } else {
                Add-Result 'PASS' 'changelog format' 'every heading and note reads as a build reads it'
                $notesDoc = Split-ChangelogMarkdown $notesLines
                $newestRelease = @($notesDoc.Blocks | Where-Object Kind -eq 'release')[0]
                $waiting = @($notesDoc.Blocks | Where-Object { $_.Kind -in @('unreleased', 'planned') -and (Test-ChangelogNotes (Read-ChangelogNotes @($_.Body)).Notes) } |
                        ForEach-Object { if ($_.Kind -eq 'unreleased') { 'Unreleased' } else { "$($_.Version) (planned)" } })
                if ($waiting) { Add-Result 'PASS' 'notes for the next release' "waiting under $($waiting -join ', ')" }
                else { Add-Result 'WARN' 'notes for the next release' "nothing under Unreleased or a planned release, so the next build ships without notes and the app keeps showing $(if ($newestRelease) { $newestRelease.Version } else { 'none' }); .\script.ps1 changelog -Edit, or -Future -Add x.y.z" }
                $jsonFile = Join-Path $repoRoot 'CHANGELOG.json'
                $jsonText = if (Test-Path -LiteralPath $jsonFile) { [IO.File]::ReadAllText($jsonFile) -replace "`r`n", "`n" } else { '' }
                $expected = ConvertTo-ChangelogDocument $notesLines (Read-ChangelogDocument $jsonFile) -IncludeUpcoming
                if ($jsonText -match '"generated":\s*"([^"]*)"') { $expected.generated = $Matches[1] }
                if ((($expected | ConvertTo-Json -Depth 6) -replace "`r`n", "`n").Trim() -eq $jsonText.Trim()) { Add-Result 'PASS' 'CHANGELOG.json' 'in step with CHANGELOG.md' }
                else { Add-Result 'WARN' 'CHANGELOG.json' 'behind CHANGELOG.md, edited without the command; .\script.ps1 changelog -Fix writes it, as does saving through -Edit or the next release' }
                # A toolkit older than its linter has no Get-ChangelogLint, and that is no reason to fail.
                if (Get-Command Get-ChangelogLint -ErrorAction SilentlyContinue) {
                    $lint = @(Get-ChangelogLint $notesLines)
                    $mendable = @($lint | Where-Object Fixable).Count
                    if ($lint) { Add-Result 'WARN' 'changelog lint' "$($lint.Count) thing(s) read badly - line $($lint[0].Line): $("$($lint[0].Message)".TrimEnd('.')); .\script.ps1 changelog -Lint lists them$(if ($mendable) { ", -Fix mends $mendable" })" }
                    else { Add-Result 'PASS' 'changelog lint' 'nothing in it reads badly' }
                }
                if (Get-Command Get-StrayChangelogFiles -ErrorAction SilentlyContinue) {
                    foreach ($stray in @(Get-StrayChangelogFiles $repoRoot)) {
                        Add-Result 'WARN' 'changelog files' "$($stray.Name) is named like a changelog but nothing reads it$(if ($stray.Empty) { ' - it is empty, and .\script.ps1 changelog -Fix removes it' } else { '; move what it says into CHANGELOG.md' })"
                    }
                }
            }
        } catch {
            Add-Result 'SKIP' 'changelog' "the pinned toolkit could not read it: $($_.Exception.Message)"
        }
    }

    # ---------------------------------------------------------------- the scripts the jobs run
    Write-Section 'Scripts (.ikaika_scripts_configs)'
    $ps1 = @(Get-ChildItem -LiteralPath $configsDir -Recurse -Filter '*.ps1' | Where-Object { $_.FullName -notmatch '\\logs\\' })
    $broken = @()
    foreach ($file in $ps1) {
        $parseErrors = $null
        [void][Management.Automation.Language.Parser]::ParseFile($file.FullName, [ref]$null, [ref]$parseErrors)
        if ($parseErrors) { $broken += "$($file.Name): $($parseErrors[0].Message)" }
    }
    if ($broken) { Add-Result 'FAIL' 'PowerShell parses' ($broken -join '; ') }
    else { Add-Result 'PASS' 'PowerShell parses' "$($ps1.Count) script(s)" }

    # Findings warn rather than fail: they are about how a script reads, not whether a run gets through it.
    if (Get-Module -ListAvailable PSScriptAnalyzer) {
        $settings = Join-Path $toolkitDir 'PSScriptAnalyzerSettings.psd1'
        $findings = @()
        foreach ($file in $ps1) {
            $found = if (Test-Path -LiteralPath $settings) { Invoke-ScriptAnalyzer -Path $file.FullName -Settings $settings } else { Invoke-ScriptAnalyzer -Path $file.FullName -Severity Warning, Error }
            $findings += @($found | ForEach-Object { "$($file.Name):$($_.Line) $($_.RuleName)" })
        }
        if ($findings) { Add-Result 'WARN' 'PSScriptAnalyzer' "$($findings.Count) finding(s): $(($findings | Select-Object -First 5) -join ', ')" }
        else { Add-Result 'PASS' 'PSScriptAnalyzer' "$($ps1.Count) script(s), the toolkit's rules" }
    } else {
        Add-Result 'SKIP' 'PSScriptAnalyzer' 'the module is not installed'
    }

    if (Test-Path -LiteralPath $ubuntuScript) {
        if ($bash) {
            $previous = $ErrorActionPreference
            $ErrorActionPreference = 'Continue'
            $syntax = @(& $bash -n $ubuntuScript 2>&1); $syntaxCode = $LASTEXITCODE
            $env:PROJECT_DIR = $repoRoot; [void](& $bash $ubuntuScript 2>&1); $usageCode = $LASTEXITCODE
            $env:PROJECT_DIR = Join-Path $work 'no-such-checkout'; [void](& $bash $ubuntuScript 2>&1); $missingCode = $LASTEXITCODE
            Remove-Item env:PROJECT_DIR
            $ErrorActionPreference = $previous
            $ubuntuText = [IO.File]::ReadAllText($ubuntuScript)
            $called = @([regex]::Matches($yaml, 'ci-ubuntu\.sh"[ \t]+([a-z][a-z-]*)') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique)
            $unknownStep = @($called | Where-Object { $ubuntuText -notmatch "(?m)^\s+$([regex]::Escape($_))\)" })
            if ($syntaxCode -ne 0) { Add-Result 'FAIL' 'ci-ubuntu.sh' "bash -n: $($syntax | Select-Object -First 1)" }
            elseif ($usageCode -ne 2 -or $missingCode -eq 0) { Add-Result 'FAIL' 'ci-ubuntu.sh' "PROJECT_DIR is not honoured: usage exit $usageCode (2 expected), a missing checkout exit $missingCode" }
            elseif ($unknownStep) { Add-Result 'FAIL' 'ci-ubuntu.sh' "the workflow runs steps it does not have: $($unknownStep -join ', ')" }
            else { Add-Result 'PASS' 'ci-ubuntu.sh' "parses, builds in PROJECT_DIR wherever it is run from, and has every step the workflow runs ($($called -join ', '))" }
        } else {
            Add-Result 'SKIP' 'ci-ubuntu.sh' 'no Git Bash beside git'
        }
    }

    $releaseScript = Join-Path $configsDir 'ci-github-release.ps1'
    $version = "$($versionLine -replace '^version:\s*', '')".Trim()
    if (Test-Path -LiteralPath $releaseScript) {
        # gh stood in for by a batch file that records each call: "release view" answers not found, "api" answers with notes.
        $fakeGh = Join-Path $work 'fake-gh'
        New-Item -ItemType Directory -Path $fakeGh | Out-Null
        $ghLines = @('@ECHO off', 'ECHO %*>> "%~dp0calls.txt"', 'IF "%1 %2"=="release view" EXIT /B 1', 'IF "%1"=="api" ECHO {"body":"generated notes"}', 'EXIT /B 0')
        [IO.File]::WriteAllText((Join-Path $fakeGh 'gh.cmd'), ($ghLines -join "`r`n") + "`r`n")
        $releaseProblems = @()
        # The tag comes from the run, as the tag its version step pushed: a staging tag, and production's -released one.
        foreach ($kind in @(@{ Name = 'staging'; Debug = 'true'; Stem = "$prefix-$version-debug"; Flags = '--prerelease --latest=false'; Tag = 'v1.2.3+4' }, @{ Name = 'production'; Debug = 'false'; Stem = "$prefix-$version"; Flags = '--latest'; Tag = 'v1.2.3+4-released' })) {
            $dist = Join-Path $work "dist-$($kind.Name)"
            New-Item -ItemType Directory -Path $dist | Out-Null
            foreach ($name in "$($kind.Stem)-web-wasm.zip", "$($kind.Stem)-web-wasm.zip.sha256", "$($kind.Stem).apk") { Set-Content -LiteralPath (Join-Path $dist $name) -Value 'x' }
            $calls = Join-Path $fakeGh 'calls.txt'
            if (Test-Path -LiteralPath $calls) { Remove-Item -LiteralPath $calls }
            $environment = @{ PATH = "$fakeGh;$env:PATH"; GITHUB_REPOSITORY = 'owner/repo'; BUILD_DEBUG = $kind.Debug; RELEASE_TAG = $kind.Tag; RUNNER_TEMP = $work; GH_TOKEN = 'not-a-token' }
            $run = Invoke-Child $releaseScript @('-DistDir', $dist) $environment
            $logged = if (Test-Path -LiteralPath $calls) { @(Get-Content -LiteralPath $calls) } else { @() }
            $create = $logged | Where-Object { $_ -match '^release create ' } | Select-Object -First 1
            if ($run.Code -ne 0) { $releaseProblems += "$($kind.Name) exits $($run.Code): $(($run.Out -split "`n" | Where-Object { $_ } | Select-Object -Last 1))" }
            elseif (@($logged | Where-Object { $_ -match '^release ' -and $_ -notmatch '--repo owner/repo' }).Count) { $releaseProblems += "$($kind.Name): a gh release call without --repo" }
            elseif (-not $create -or $create -notmatch [regex]::Escape($kind.Flags) -or $create -notmatch '--verify-tag') { $releaseProblems += "$($kind.Name): gh release create is not $($kind.Flags) --verify-tag" }
            elseif ($create -notmatch "^release create $([regex]::Escape($kind.Tag)) ") { $releaseProblems += "$($kind.Name): the release is not for $($kind.Tag), the tag the run pushed" }
            elseif (@($create -split ' ' | Where-Object { $_ -like "$dist*" }).Count -ne 3) { $releaseProblems += "$($kind.Name): not every file in dist is attached" }
        }
        if ($releaseProblems) { Add-Result 'FAIL' 'ci-github-release.ps1' ($releaseProblems -join '; ') }
        else { Add-Result 'PASS' 'ci-github-release.ps1' 'a staging prerelease and a latest production release, every file attached, the repository named on each gh call' }
    }

    $summaryScript = Join-Path $configsDir 'ci-run-summary.ps1'
    if (Test-Path -LiteralPath $summaryScript) {
        $dist = Join-Path $work 'dist-summary'
        New-Item -ItemType Directory -Path $dist -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $dist "$prefix-$version-debug.apk") -Value 'x'
        $summaryProblems = @()
        foreach ($outcome in 'success', 'failure') {
            $environment = @{ BUILD_DEBUG = 'true'; BUILD_OUTCOME = $outcome; WINDOWS_OUTCOME = $outcome; UBUNTU_OUTCOME = $outcome; TAGGING = 'true'; TAG_OUTCOME = $outcome; UPLOAD_OUTCOME = $outcome; BITBUCKET_OUTCOME = $outcome; GITHUB_OUTCOME = $outcome; DEPLOYS = 'true'; TARGET = 'staging'; ARTIFACT = 'artifact'; RETENTION = '1'; BUMP = 'keep' }
            $run = Invoke-Child $summaryScript @('-DistDir', $dist) $environment
            if ($run.Code -ne 0 -or $run.Out -notmatch '### Result' -or $run.Out -notmatch '### Files') { $summaryProblems += "after a $outcome it exits $($run.Code)" }
        }
        if ($summaryProblems) { Add-Result 'FAIL' 'ci-run-summary.ps1' ($summaryProblems -join '; ') }
        else { Add-Result 'PASS' 'ci-run-summary.ps1' 'reports a finished run and a failed one' }
    }

    # ---------------------------------------------------------------- GitHub itself
    Write-Section 'GitHub'
    if (-not $Online) {
        Add-Result 'SKIP' 'secrets on GitHub' 'run with -Online to ask GitHub'
    } else {
        $slug = ''
        foreach ($line in (Invoke-Git -C $repoRoot remote -v).Lines) { if ($line -match 'github\.com[:/]([^/\s]+/[^/\s]+?)(?:\.git)?\s') { $slug = $Matches[1]; break } }
        $env:GIT_TERMINAL_PROMPT = '0'
        $env:GCM_INTERACTIVE = 'never'
        $credential = "protocol=https`nhost=github.com`n`n" | git credential fill 2>$null
        $token = ($credential | Where-Object { $_ -like 'password=*' }) -replace '^password=', ''
        if (-not $slug -or -not $token) {
            Add-Result 'SKIP' 'secrets on GitHub' "$(if (-not $slug) { 'no remote here points at github.com' } else { 'git has no stored github.com credential' })"
        } else {
            try {
                $names = @((Invoke-RestMethod -Headers @{ Authorization = "Bearer $token"; Accept = 'application/vnd.github+json' } -Uri "https://api.github.com/repos/$slug/actions/secrets?per_page=100").secrets.name)
                $absent = @($referenced | Where-Object { $names -notcontains $_ })
                $required = @($absent | Where-Object { -not $declared[$_].optional })
                if ($required) { Add-Result 'FAIL' 'secrets on GitHub' "$slug lacks: $($required -join ', ')" }
                else { Add-Result 'PASS' 'secrets on GitHub' "$slug has every secret the workflow needs$(if ($absent) { "; optional and unset: $($absent -join ', ')" })" }
            } catch {
                Add-Result 'SKIP' 'secrets on GitHub' "GitHub would not list them: $($_.Exception.Message)"
            }
        }
    }
} finally {
    Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}

$failures = @($results | Where-Object State -eq 'FAIL').Count
$warnings = @($results | Where-Object State -eq 'WARN').Count
Write-Host ''
$verdict = if ($failures) { "$failures check(s) would break the Build release workflow." } elseif ($warnings) { "Nothing breaks; $warnings warning(s) worth a look." } else { 'Nothing here would break the Build release workflow.' }
Write-Host "$(@($results | Where-Object State -eq 'PASS').Count) passed, $warnings warning(s), $failures failure(s), $(@($results | Where-Object State -eq 'SKIP').Count) skipped. $verdict" -ForegroundColor $(if ($failures) { 'Red' } elseif ($warnings) { 'Yellow' } else { 'Green' })
exit $(if ($failures) { 1 } else { 0 })
