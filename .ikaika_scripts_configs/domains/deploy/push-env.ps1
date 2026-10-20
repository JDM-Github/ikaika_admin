# Pushes the secret settings - Cloudinary, Jira, mail relay and the rest - to a server's .env.
#
# A forwarder over deployment/push-env.ps1, which still runs on its own and never prints a value. The target decides both
# ends: staging sends this repo's .env to ~/apps/test-server; production sends its own file,
# .ikaika_scripts_configs/production-settings.env (sealed with the secrets), to ~/apps/portal-prod, and asks for PROD
# unless -Yes. A key left empty here never blanks the server's.
#
#     .\script.ps1 deploy/push-env                                 every key, to the Test Server
#     .\script.ps1 deploy/push-env -Only JIRA_API_TOKEN            one key
#     .\script.ps1 deploy/push-env -Target production -Yes         production's own file, unasked
param(
    # Where the settings go: the Test Server, or production.
    [ValidateSet('staging', 'production')]
    [string]$Target = 'staging',
    # The keys to send; every one of them when left out. Not -Keys: the dispatcher reads its own list as .Keys.
    [ValidateSet(
        'CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET',
        'BITBUCKET_TOKEN', 'BITBUCKET_WEBHOOK_SECRET',
        'JIRA_BASE_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN', 'JIRA_PROJECT_KEY', 'JIRA_CLOUD_ID', 'JIRA_WEBHOOK_SECRET',
        'MAIL_RELAY_URL', 'MAIL_RELAY_KEY',
        'OLLAMA_API_KEY',
        'PORTAL_ID_LOGIN_PASSWORD'
    )]
    [string[]]$Only,
    # Skip the question production asks. For a scripted run, where a question is a hang.
    [switch]$Yes,
    [switch]$Help
)

if ($Help) {
    Write-Host @'

  deploy/push-env - the secret settings, to a server's .env.

  Usage
    .\script.ps1 deploy/push-env [-Target staging|production] [-Only <key>,...] [-Yes]

  Runs deployment\push-env.ps1. Staging sends this repo's .env to the Test
  Server; production sends .ikaika_scripts_configs\production-settings.env to
  production and asks for PROD unless -Yes. Values are never printed.

'@
    exit 0
}

. (Join-Path $PSScriptRoot '..\..\lib\forward.ps1')
$arguments = @{}
if ($Only) { $arguments['Keys'] = $Only }
if ($Target -eq 'production') {
    $envFile = Get-ForwardPath '.ikaika_scripts_configs\production-settings.env'
    if (-not (Test-Path -LiteralPath $envFile)) {
        Write-Host "Production's settings are not here: $envFile. Restore them with .\script.ps1 secrets -For settings-production." -ForegroundColor Red
        exit 1
    }
    Confirm-Deliberate -Word 'PROD' -Question "PRODUCTION. This replaces production's $(if ($Only) { $Only -join ', ' } else { 'secret settings' }) with $(Split-Path -Leaf $envFile)'s." -Yes:$Yes
    $arguments.RemoteDir = '~/apps/portal-prod'
    $arguments.EnvFile = $envFile
}
Assert-SshReady
Invoke-Forwarded 'deployment\push-env.ps1' $arguments
