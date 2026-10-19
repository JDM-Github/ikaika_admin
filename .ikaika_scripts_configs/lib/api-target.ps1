# What a target needs before the API is built for it, read from ikaika_scripts.env: build-release refuses on it, its dialog shows it,
# and check-targets is what fills it in.
#
# <kind>.assetUrl is the public folder Vite bakes into every font URL, so a target without one has nothing to build for. Production
# also needs SITE_DIR, SITE_URL and API_CHANNEL among its serverEnv lines, because deploy-admin.sh falls back to staging's for any it
# is not handed - a production archive without them would install as staging. Staging's fallbacks are its own, so it needs only the folder.

function Resolve-ApiTarget([string]$Root, [string]$Kind) {
    $config = @(Get-Content -LiteralPath (Join-Path $Root 'ikaika_scripts.env') -ErrorAction SilentlyContinue)
    $assetUrl = "$(($config | Where-Object { $_ -match "^$Kind\.assetUrl=" } | Select-Object -First 1) -replace '^[^=]*=', '')".Trim()
    $named = @($config | ForEach-Object { if ($_ -match "^$Kind\.serverEnv\.\d+=([A-Za-z_][A-Za-z0-9_]*)=.") { $Matches[1] } })
    $problems = @()
    if (-not $assetUrl) { $problems += "$Kind.assetUrl is blank, so there is no folder to build for" }
    if ($Kind -eq 'release') {
        $missing = @('SITE_DIR', 'SITE_URL', 'API_CHANNEL' | Where-Object { $_ -notin $named })
        if ($missing.Count) { $problems += "release.serverEnv has no $($missing -join ', '), which deploy-admin.sh would take from staging" }
    }
    $name = if ($Kind -eq 'debug') { 'Staging' } else { 'Production' }
    return [pscustomobject]@{
        Kind     = $Kind
        AssetUrl = $assetUrl
        Problem  = if ($problems.Count) { "$name is not set up: $($problems -join '; ')." } else { '' }
    }
}
