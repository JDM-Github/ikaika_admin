# Upgrades the local Windows PHP install to the latest release of the same 8.x branch.
# Fixes CVE-2026-109618 / CVE-2026-109619 (needs >= 8.5.12, 8.4.27, 8.3.36, 8.2.35).
param([switch]$Force)

$ErrorActionPreference = 'Stop'

$phpExe = (Get-Command php -ErrorAction Stop).Source
$root = Split-Path $phpExe
$current = [version](php -r "echo PHP_VERSION;")
$branch = "$($current.Major).$($current.Minor)"
$key = if ((php -i | Select-String 'Thread Safety => enabled')) { 'ts-vs17-x64' } else { 'nts-vs17-x64' }

$rel = (Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json').$branch
if (-not $rel -or -not $rel.$key) { throw "No $key build listed for PHP $branch" }
$target = [version]$rel.version
$zipPath = Join-Path $env:TEMP $rel.$key.zip.path

Write-Host "Installed: $current ($root)"
Write-Host "Latest $branch : $target"

if ($current -ge $target -and -not $Force) {
    Write-Host 'Already current. Nothing to do.'
    return
}
if (-not $Force -and ([version]'8.5.12' -gt $target)) {
    throw "php.net still lists $target as latest; CVE fix not published yet for $branch"
}

Write-Host "Downloading $($rel.$key.zip.path)..."
Invoke-WebRequest "https://windows.php.net/downloads/releases/$($rel.$key.zip.path)" -OutFile $zipPath
$actual = (certutil -hashfile $zipPath SHA256 | Select-Object -Skip 1 -First 1) -replace ' ', ''
if ($actual -ne $rel.$key.zip.sha256) {
    throw "SHA256 mismatch ($actual), aborting"
}

$newDir = "$root.new"
Remove-Item $newDir -Recurse -Force -ErrorAction SilentlyContinue
Expand-Archive $zipPath -DestinationPath $newDir
Copy-Item (Join-Path $root 'php.ini') $newDir

$backup = "$root.bak-$current-$(Get-Date -Format yyyyMMddHHmmss)"
try {
    Rename-Item $root $backup
    Rename-Item $newDir $root
} catch {
    if (Test-Path "$root.new") { Rename-Item $newDir $root }
    throw "Swap failed (is php-cgi/php artisan serve running?): $($_.Exception.Message)"
}
Remove-Item $zipPath -Force

Write-Host "Done. Backup: $backup"
php -v
