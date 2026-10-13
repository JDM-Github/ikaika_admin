# What this repository's .env and config/products.php say, read from PowerShell.
#
# Dot-sourced by run-servers and update-database. Both need to know which database a
# product uses and what credentials reach it, and neither can ask Laravel: artisan needs
# a working PHP on PATH, which is the very thing these commands exist to set up or start.
# So the .env is parsed directly, and the product list below mirrors config/products.php.
#
# THE PRODUCT LIST IS READ FROM config/products.php, not copied: a copy is what left the
# transaction tracker out of update-database when it landed. The catalog is PHP, so its
# entries are matched rather than evaluated - a key, a 'name', and a 'database' that reads
# env('<PREFIX>_DB_DATABASE', '<fallback>') - which is the shape every entry has had.

# One value from a dotenv file. Only the first '=' splits, quotes are stripped, and a
# missing file or key gives $Default - the same contract Laravel's own reader has, so a
# value that works in the app works here.
function Get-DotEnvValue {
    param(
        [Parameter(Mandatory)][string]$Path,
        [Parameter(Mandatory)][string]$Key,
        [string]$Default = ''
    )
    if (-not (Test-Path -LiteralPath $Path)) { return $Default }
    foreach ($line in (Get-Content -LiteralPath $Path)) {
        $text = "$line".Trim()
        if (-not $text -or $text.StartsWith('#')) { continue }
        if ($text -notmatch "^$([regex]::Escape($Key))\s*=") { continue }
        $value = ($text -split '=', 2)[1].Trim()
        if ($value.Length -ge 2 -and (($value[0] -eq '"' -and $value[-1] -eq '"') -or
                ($value[0] -eq "'" -and $value[-1] -eq "'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        if ($value -eq '') { return $Default }
        return $value
    }
    return $Default
}

# Every product database in config/products.php's catalog, each with the credentials that
# reach it, including the catalog's fallbacks to the DB_* keys.
#
# Key is the sql/ FOLDER name rather than the catalog key, because that is what this is
# used for: sql/project_estimator is the folder, 'project-estimator' is the catalog key,
# and confusing the two silently applies nothing. Key keeps the catalog key, which is what
# the core ledger's product column holds.
function Get-ProductDatabase([string]$EnvPath) {
    $host_ = Get-DotEnvValue $EnvPath 'DB_HOST' '127.0.0.1'
    $port = Get-DotEnvValue $EnvPath 'DB_PORT' '3306'
    $user = Get-DotEnvValue $EnvPath 'DB_USERNAME' 'root'
    $pass = Get-DotEnvValue $EnvPath 'DB_PASSWORD' ''

    $catalogPath = Join-Path (Split-Path -Parent $EnvPath) 'config\products.php'
    if (-not (Test-Path -LiteralPath $catalogPath)) { throw "There is no product catalog at $catalogPath." }
    $catalog = Get-Content -LiteralPath $catalogPath -Raw
    $pattern = "'([a-z0-9-]+)'\s*=>\s*\[\s*'name'\s*=>\s*'([^']+)'.*?'database'\s*=>\s*env\('([A-Z0-9_]+)_DB_DATABASE',\s*'([^']*)'\)"
    $entries = @([regex]::Matches($catalog, $pattern, 'Singleline') | ForEach-Object {
            $connection = if ($_.Value -match "'connection'\s*=>\s*'([A-Za-z0-9_]+)'") { $Matches[1] } else { '' }
            @{ Key = $_.Groups[1].Value; Name = $_.Groups[2].Value; Connection = $connection; Prefix = $_.Groups[3].Value; Fallback = $_.Groups[4].Value }
        })
    if (-not $entries.Count) { throw "No product in $catalogPath has a 'database' => env('<PREFIX>_DB_DATABASE', ...) to read." }

    $products = [ordered]@{}
    foreach ($entry in $entries) {
        $products[$entry.Key -replace '-', '_'] = [pscustomobject]@{
            Key        = $entry.Key
            Name       = $entry.Name
            # The Laravel connection the catalog gives it, which is how a migration names the database it writes to.
            Connection = $entry.Connection
            Database = Get-DotEnvValue $EnvPath "$($entry.Prefix)_DB_DATABASE" $entry.Fallback
            DbHost   = Get-DotEnvValue $EnvPath "$($entry.Prefix)_DB_HOST" $host_
            Port     = Get-DotEnvValue $EnvPath "$($entry.Prefix)_DB_PORT" $port
            Username = Get-DotEnvValue $EnvPath "$($entry.Prefix)_DB_USERNAME" $user
            Password = Get-DotEnvValue $EnvPath "$($entry.Prefix)_DB_PASSWORD" $pass
        }
    }
    return $products
}

# The arguments that point mysql.exe at one product, as a list.
#
# --protocol=tcp is not optional on Windows: without it the client tries a named pipe
# whenever the host is localhost, WampServer's MySQL does not listen on one, and the
# error says "Can't open named pipe" rather than anything about the port.
#
# The password goes in as --password=..., which puts it in this process's command line.
# That is visible in a process listing for as long as the client runs. It is the same
# exposure the project's own README documents (`mysql -u root -proot ...`), and the
# alternative - a temporary my.cnf - leaves a credential in a file instead. Neither is
# good; this one at least does not outlive the call.
function Get-MysqlArgument([object]$Product) {
    $arguments = @("--host=$($Product.DbHost)", "--port=$($Product.Port)",
        "--user=$($Product.Username)", '--protocol=tcp')
    if ($Product.Password) { $arguments += "--password=$($Product.Password)" }
    return $arguments
}

# Whether anything is listening on a TCP port here. Used to say "already running"
# rather than starting a second copy of something.
function Test-PortListening([int]$Port) {
    try {
        $found = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction Stop
        return @($found).Count -gt 0
    } catch {
        # Get-NetTCPConnection throws rather than returning nothing when the port is
        # free, and on a machine without the NetTCPIP module it is absent entirely.
        return $false
    }
}
