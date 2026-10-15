# The same two checks deploy-admin.sh runs before it calls a deploy good: nothing left to migrate, and
# the portal database answering healthy. Standalone, so it can be run any time.
#
#     .\deployment\check-health.ps1            production (portal.ikaikabim.com)
#     .\deployment\check-health.ps1 -Target test   the Test Server
param(
    [ValidateSet('prod', 'test')] [string]$Target = 'prod',
    [string]$SshHost = 'bluehost'
)

$ErrorActionPreference = 'Stop'

$targets = @{
    prod = @{ Remote = '~/apps/portal-prod'; Health = 'https://portal.ikaikabim.com/central-api/api/production/portal/health' }
    test = @{ Remote = '~/apps/test-server'; Health = 'https://test.ikaikabim.com/central-api/api/staging/portal/health' }
}
$t = $targets[$Target]

Write-Host "== migrations ($Target)"
ssh $SshHost "cd $($t.Remote)/app && php artisan migrate:status --pending=1"
if ($LASTEXITCODE -ne 0) { Write-Host 'FAIL: migrations are pending (or migrate:status could not run).' -ForegroundColor Red; exit 1 }

Write-Host "== health ($Target)"
$response = Invoke-WebRequest -Uri $t.Health -TimeoutSec 30 -UseBasicParsing -SkipHttpErrorCheck
Write-Host "   HTTP $($response.StatusCode) $($response.Content)"
if ($response.StatusCode -ne 200 -or "$($response.Content)" -match '"ok"\s*:\s*false') {
    Write-Host 'FAIL: health did not answer ok.' -ForegroundColor Red
    exit 1
}

Write-Host "OK: $Target is healthy." -ForegroundColor Green
