# Reads dev.json - this repository's declared development dependencies and the versions
# it needs them at.
#
# WHY A FILE AND NOT A LIST IN THE SCRIPT. The requirement is a fact about the project,
# not about the command that installs it: a human reads it before running anything, CI
# reads it without a PowerShell host, and bumping a floor is a one-line diff in review
# rather than a change to an installer. The setup command becomes the thing that ACTS on
# the list instead of the thing that holds it.
#
# WHAT BELONGS HERE, AND WHAT DOES NOT. dev.json is for what a MACHINE needs - the
# runtimes, SDKs and CLIs a checkout cannot build without. Package-level dependencies
# stay where their ecosystem already declares them, in package.json and composer.json;
# copying those here would create a second list that goes stale the first time somebody
# runs `npm install` and does not think to update it.
#
# Nothing here installs anything. It reads, validates and hands back rows.

# The tools this repository declares, in file order, as objects with every field filled
# in so no caller has to test for a missing property.
#
# Order is the file's order and is preserved deliberately: it is an install order as
# well as a listing order - PowerShell before anything that needs a modern shell, the
# runtime before the SDK that sits on it.
function Get-DevDependency {
    param(
        [Parameter(Mandatory)][string]$Path,
        # Only the tools that apply here. A tool with no "when" always applies; one with
        # "when": "native" applies only when 'native' is named. This is how an optional
        # toolchain - an Android SDK, a Linux cross-build - stays declared without being
        # reported as missing on a machine that never builds that target.
        [string[]]$Include = @()
    )

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "There is no dev.json at $Path. It declares what this project needs installed."
    }

    try {
        $document = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
    } catch {
        # Named rather than swallowed: a trailing comma in this file would otherwise
        # surface as "nothing is missing", which is the worst possible way for a
        # requirements file to fail.
        throw "dev.json at $Path is not valid JSON: $($_.Exception.Message)"
    }

    if (-not $document.tools) { return @() }

    $rows = @()
    foreach ($property in $document.tools.PSObject.Properties) {
        $tool = $property.Value
        $when = "$($tool.when)".Trim()
        if ($when -and ($Include -notcontains $when)) { continue }

        $rows += [pscustomobject]@{
            Key        = $property.Name
            Name       = "$(if ($tool.name) { $tool.name } else { $property.Name })"
            MinVersion = "$($tool.minVersion)".Trim()
            Series     = "$($tool.series)".Trim()
            Winget     = "$($tool.winget)".Trim()
            Scope      = "$(if ($tool.scope) { $tool.scope } else { 'machine' })"
            When       = $when
            # Shown in the report beside the tool, so a row that is missing also says
            # what it is missing FOR. "not installed" on its own tells nobody whether
            # they can ignore it.
            For        = "$($tool.for)".Trim()
            Optional   = [bool]$tool.optional
        }
    }
    return $rows
}

# A version string as something [version] will accept: "17" becomes 17.0, "8.4"
# stays 8.4. Anything already valid is returned unchanged.
function ConvertTo-ComparableVersion([string]$Text) {
    $trimmed = "$Text".Trim()
    if ($trimmed -notmatch '\.') { $trimmed = "$trimmed.0" }
    return [version]$trimmed
}

# Whether a found version satisfies a declared one. A floor and a series are different
# questions and both are real here: Node needs "this or newer", while PHP needs "the 8.4
# line" because 8.5 is a different set of extensions and a different Apache module.
function Test-DevVersion {
    param(
        $Found,
        [string]$MinVersion = '',
        [string]$Series = ''
    )
    if ($null -eq $Found) { return $false }
    if ($Series) { return "$Found".StartsWith("$Series.") -or "$Found" -eq $Series }
    if ($MinVersion) {
        # "17" is a perfectly reasonable thing to write in dev.json and NOT a valid
        # [version] - that cast needs major.minor and throws on a bare major, which the
        # catch below then read as "the tool is too old". A JDK 17.0.20 was reported
        # missing on a machine that had it. Both sides are padded before comparing.
        try {
            return (ConvertTo-ComparableVersion "$Found") -ge (ConvertTo-ComparableVersion $MinVersion)
        } catch { return $false }
    }
    return $true
}

# What a declared version reads as in a report: ">= 20.19.0", "8.4.x", or nothing.
function Format-DevRequirement([object]$Tool) {
    if ($Tool.Series) { return "$($Tool.Series).x" }
    if ($Tool.MinVersion) { return ">= $($Tool.MinVersion)" }
    return ''
}
