# Builds deployment/out/central-platform.zip for Bluehost; see deployment/README.md.
# -Root packages another checkout's sources - a tag's worktree, for build-release -FromTag - into this one's out/. That checkout is
# thrown away afterwards, so its Vite build is not put back for artisan serve. Anything added to the package below later must be
# optional, as sql/transaction_tracker/patches is, or every older tag stops packaging.
param(
    [string]$AssetUrl = '/central-api',
    [string]$Root = ''
)

$ErrorActionPreference = 'Stop'
# $root below is $Root - PowerShell's names ignore case - so whether the sources are another checkout's is kept first.
$isElsewhere = [bool]$Root
$root  = if ($isElsewhere) { (Resolve-Path -LiteralPath $Root).Path } else { Split-Path $PSScriptRoot -Parent }
$out   = Join-Path $PSScriptRoot 'out'
$stage = Join-Path $out 'central-platform'
$zip   = Join-Path $out 'central-platform.zip'

Push-Location $root
try {
    # Vite bakes ASSET_URL into the font URLs, so the build must know the public subfolder.
    $env:ASSET_URL = $AssetUrl
    npm run build
    if ($LASTEXITCODE -ne 0) { throw 'npm run build failed' }

    Remove-Item -Recurse -Force $stage, $zip -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Force $stage | Out-Null

    foreach ($item in 'app', 'config', 'database', 'public', 'resources', 'routes', 'artisan', 'composer.json', 'composer.lock') {
        Copy-Item -Recurse (Join-Path $root $item) (Join-Path $stage $item)
    }
    Remove-Item -Force -ErrorAction SilentlyContinue (Join-Path $stage 'public/hot'), (Join-Path $stage 'public/fonts-manifest.dev.json')
    # The release carries its own version, so the server can say which one it is running.
    if (Test-Path (Join-Path $root 'VERSION')) { Copy-Item (Join-Path $root 'VERSION') (Join-Path $stage 'VERSION') }
    Copy-Item (Join-Path $PSScriptRoot 'server/.htaccess') (Join-Path $stage 'public/.htaccess')

    # Tracker table patches ride along; deploy-admin.sh runs each one (no migration reaches the tracker database).
    $patches = Join-Path $root 'sql/transaction_tracker/patches'
    if (Test-Path $patches) {
        New-Item -ItemType Directory -Force (Join-Path $stage 'sql/transaction_tracker') | Out-Null
        Copy-Item -Recurse $patches (Join-Path $stage 'sql/transaction_tracker/patches')
    }

    # bootstrap and storage ship as empty skeletons so no local cache, log, or session leaks out.
    New-Item -ItemType Directory -Force (Join-Path $stage 'bootstrap/cache') | Out-Null
    Copy-Item (Join-Path $root 'bootstrap/app.php'), (Join-Path $root 'bootstrap/providers.php') (Join-Path $stage 'bootstrap')
    foreach ($dir in 'bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data',
                     'storage/framework/sessions', 'storage/framework/views', 'storage/logs') {
        $path = Join-Path $stage $dir
        New-Item -ItemType Directory -Force $path | Out-Null
        [IO.File]::WriteAllText((Join-Path $path '.gitignore'), "*`n!.gitignore`n")
    }

    # Windows tar writes forward-slash entries; Compress-Archive on PowerShell 5 does not.
    $entries = Get-ChildItem -Force -Name $stage
    & "$env:SystemRoot\System32\tar.exe" -a -c -f $zip -C $stage @entries
    if ($LASTEXITCODE -ne 0) { throw 'zip failed' }
}
finally {
    # Put the local build back to root-relative URLs for php artisan serve; another checkout's is thrown away.
    Remove-Item Env:ASSET_URL -ErrorAction SilentlyContinue
    if (-not $isElsewhere) { npm run build | Out-Null }
    Pop-Location
}

Write-Host "Ready: $zip"
