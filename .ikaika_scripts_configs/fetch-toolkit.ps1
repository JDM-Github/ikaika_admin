# Fetches decoupled-script from Bitbucket on a CI runner, which has no Bitbucket credentials of its own.
# TOOLKIT_BITBUCKET_TOKEN is required; TOOLKIT_BITBUCKET_USER is only for an API token and defaults to x-token-auth, the access-token username.
# The token reaches git through GIT_CONFIG_*, so it is never on a command line, and the credential helper is off so nothing asks for or stores it.
$ErrorActionPreference = 'Stop'

if (-not $env:TOOLKIT_BITBUCKET_TOKEN) {
    Write-Output '::error title=Missing secret TOOLKIT_BITBUCKET_TOKEN::A Bitbucket token that can read ikaika-web/ikaika_scripts. Add it under Settings > Secrets and variables > Actions.'
    exit 1
}

$repoRoot = Split-Path $PSScriptRoot -Parent
$user = if ($env:TOOLKIT_BITBUCKET_USER) { $env:TOOLKIT_BITBUCKET_USER } else { 'x-token-auth' }
$url = "$(git -C $repoRoot config -f .gitmodules --get submodule.decoupled-script.url)"
$prefix = $url -replace '^(https://[^/]+/).*$', '$1'

$env:GIT_TERMINAL_PROMPT = '0'
$env:GIT_CONFIG_COUNT = '2'
$env:GIT_CONFIG_KEY_0 = "url.https://$($user):$($env:TOOLKIT_BITBUCKET_TOKEN)@bitbucket.org/.insteadOf"
$env:GIT_CONFIG_VALUE_0 = $prefix
$env:GIT_CONFIG_KEY_1 = 'credential.helper'
$env:GIT_CONFIG_VALUE_1 = ''
try {
    git -C $repoRoot submodule update --init
    $code = $LASTEXITCODE
} finally {
    Remove-Item env:GIT_CONFIG_COUNT, env:GIT_CONFIG_KEY_0, env:GIT_CONFIG_VALUE_0, env:GIT_CONFIG_KEY_1, env:GIT_CONFIG_VALUE_1 -ErrorAction SilentlyContinue
}

if ($code -ne 0) {
    Write-Output '::error title=Toolkit fetch failed::git could not fetch decoupled-script. Check that TOOLKIT_BITBUCKET_TOKEN can read ikaika-web/ikaika_scripts.'
    exit 1
}
Write-Output "decoupled-script at $(git -C (Join-Path $repoRoot 'decoupled-script') describe --tags --always)"
