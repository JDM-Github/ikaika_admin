# IKAIKA Laravel Deployment on Bluehost

This guide deploys the IKAIKA central Laravel application to:

```text
Private Laravel application:
/home2/eoxvhumy/apps/central-platform

Public staging directory:
/home2/eoxvhumy/public_html/staging/central-api

Staging URL:
https://ikaikabim.com/staging/central-api
```

WordPress owns `https://ikaikabim.com/`. Laravel only owns the folder `public_html/staging/central-api/`. The browser must therefore call:

```text
https://ikaikabim.com/staging/central-api/api/staging/core/health
```

A request to `https://ikaikabim.com/api/staging/core/health` never reaches Laravel. It is a WordPress 404.

Apache already maps `/staging/central-api/` onto that public folder, so Laravel itself still sees `/api/staging/core/health`. Leave `API_PATH_PREFIX` empty on the server. Do not set it to `/staging/central-api` or Laravel will look for a path it never receives and health will 404.

The playground is Blade plus inline JavaScript. It is not Vite. `npm run build` only compiles CSS/JS/fonts into `public/build/`. It does not bake API routes.

The Bluehost web server currently uses PHP 8.3.33. The project must therefore retain the Composer PHP platform setting of `8.3.33` so Composer selects compatible Symfony 7.4 packages instead of Symfony 8.

## 1. Prepare and test locally

Open Command Prompt:

```bat
cd C:\IKAIKA\ikaika_admin

composer install
composer prohibits php 8.3.33
php artisan optimize:clear
php artisan test
```

Then do section 2 and run `npm ci` / `npm run build` there. A build with empty `ASSET_URL` puts font files at `/build/...`, which WordPress will 404.

Confirm that this file was generated:

```text
public\build\manifest.json
```

If the PHP 8.3 platform setting has not been added yet, run this once and commit the resulting `composer.json` and `composer.lock` changes:

```bat
composer config platform.php 8.3.33
composer update --with-all-dependencies
```

Confirm Symfony is compatible:

```bat
composer show symfony/console
composer prohibits php 8.3.33
```

Symfony packages should resolve to `7.4.x`, and Composer should not report packages that prohibit PHP 8.3.33.

## 2. Configure the local production build

Create or update `.env.production` locally. This file is only for `npm run build`. It is not copied to Bluehost.

```dotenv
VITE_APP_NAME="IKAIKA Platform"
ASSET_URL=/staging/central-api
```

`ASSET_URL` is read by Laravel's Vite plugin at build time so font URLs inside CSS become `/staging/central-api/build/...` instead of `/build/...` (which would hit WordPress).

There is no `VITE_API_BASE_URL` in this project. The playground gets API paths from PHP (`APP_URL` + the current page directory). Rebuilding Vite does not change playground fetches.

Rebuild after changing `ASSET_URL`:

```bat
npm ci
npm run build
```

## 3. Create the deployment ZIP

Include these directories and files:

```text
app/
bootstrap/
config/
database/
public/
resources/
routes/
storage/
artisan
composer.json
composer.lock
```

Confirm that `public/` includes:

```text
public/.htaccess
public/index.php
public/build/manifest.json
```

Exclude these files and directories:

```text
.env
.git/
.github/
.idea/
.serena/
.vscode/
graphify-out/
node_modules/
tests/
vendor/
.phpunit.result.cache
phpunit.xml
AGENTS.md
API.md
CLAUDE.md
README.md
public/hot
storage/logs/*.log
storage/framework/cache/data/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*.php
```

Name the archive:

```text
central-platform.zip
```

## 4. Upload and extract a new release

Do not delete the currently working application first. Create:

```text
/home2/eoxvhumy/apps/central-platform-new
```

Upload `central-platform.zip` into that directory and extract it.

Verify the layout:

```text
/home2/eoxvhumy/apps/central-platform-new/app
/home2/eoxvhumy/apps/central-platform-new/public
/home2/eoxvhumy/apps/central-platform-new/artisan
/home2/eoxvhumy/apps/central-platform-new/composer.json
```

There must not be an extra nested folder such as:

```text
/home2/eoxvhumy/apps/central-platform-new/ikaika_admin/app
```

## 5. Copy and update the server environment

Copy the current server `.env` into the new release:

```bash
cp -p \
  /home2/eoxvhumy/apps/central-platform/.env \
  /home2/eoxvhumy/apps/central-platform-new/.env
```

Edit the new file at:

```text
/home2/eoxvhumy/apps/central-platform-new/.env
```

Use the following production/staging structure. Replace every placeholder with its existing real server value. Never paste the real secrets into chat, Git, documentation, or frontend variables.

```dotenv
APP_NAME="IKAIKA Platform"
APP_ENV=staging
APP_KEY=base64:REPLACE_WITH_EXISTING_SERVER_APP_KEY
APP_DEBUG=false
APP_URL=https://ikaikabim.com/staging/central-api

API_CHANNEL=staging
# Empty on Bluehost. Apache already strips /staging/central-api before Laravel.
API_PATH_PREFIX=
# Public CSS/JS URLs. Same subdirectory the browser uses.
ASSET_URL=/staging/central-api

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=eoxvhumy_ikaika_platform
DB_USERNAME=eoxvhumy_JDM
DB_PASSWORD="REPLACE_WITH_CENTRAL_DATABASE_PASSWORD"

PORTAL_ENABLED=true
PORTAL_DB_DATABASE=eoxvhumy_test_portal
PORTAL_DB_USERNAME=eoxvhumy_JDM
PORTAL_DB_PASSWORD="REPLACE_WITH_PORTAL_DATABASE_PASSWORD"

CORE_DB_DATABASE=eoxvhumy_ikaika_platform

ESTIMATOR_ENABLED=false
ESTIMATOR_DB_DATABASE=eoxvhumy_estimator

PORTAL_JWT_SECRET=REPLACE_WITH_GENERATED_JWT_SECRET
PORTAL_JWT_TTL=28800

SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/staging/central-api
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
CACHE_STORE=file

MEMCACHED_HOST=127.0.0.1

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@ikaikabim.com"
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

VITE_APP_NAME="${APP_NAME}"
```

Important:

- Preserve the existing server `APP_KEY`. Do not generate a new key during routine deployments.
- `DB_HOST=localhost` is correct because Laravel and MySQL run within the same Bluehost environment.
- Quoting database passwords avoids `.env` parsing problems with characters such as `#` or spaces.
- `MAIL_MAILER=log` does not send real email; it writes email output to Laravel logs.
- Keep `API_PATH_PREFIX` empty. The playground prefixes `/staging/central-api` for the browser; Laravel routes stay `/api/...`.
- `ASSET_URL` is for CSS/JS tags. It is not an API route prefix.
- Server-side `VITE_APP_NAME` is unused after assets have already been built locally.

Protect the new `.env`:

```bash
chmod 600 /home2/eoxvhumy/apps/central-platform-new/.env
```

## 6. Preserve persistent uploads

If the application stores uploaded files under `storage/app/public`, copy them into the new release:

```bash
cp -a \
  /home2/eoxvhumy/apps/central-platform/storage/app/public/. \
  /home2/eoxvhumy/apps/central-platform-new/storage/app/public/
```

Skip this command when no uploaded files exist.

## 7. Install production Composer dependencies

Run:

```bash
cd /home2/eoxvhumy/apps/central-platform-new

php /home2/eoxvhumy/bin/composer install \
  --no-dev \
  --prefer-dist \
  --optimize-autoloader
```

Verify compatibility with Bluehost PHP 8.3.33:

```bash
php /home2/eoxvhumy/bin/composer prohibits php 8.3.33
```

Correct permissions:

```bash
find /home2/eoxvhumy/apps/central-platform-new -type d -exec chmod 755 {} \;
find /home2/eoxvhumy/apps/central-platform-new -type f -exec chmod 644 {} \;

chmod 600 /home2/eoxvhumy/apps/central-platform-new/.env

chmod -R 775 \
  /home2/eoxvhumy/apps/central-platform-new/storage \
  /home2/eoxvhumy/apps/central-platform-new/bootstrap/cache
```

Verify Laravel before activating the release:

```bash
php artisan about
php artisan route:list
```

## 8. Activate the new private application

Rename the current application as a recoverable backup, then activate the new release:

```bash
cd /home2/eoxvhumy/apps

mv central-platform central-platform-backup-before-update
mv central-platform-new central-platform
```

Run the deployment commands:

```bash
cd /home2/eoxvhumy/apps/central-platform

php artisan optimize:clear
php artisan migrate --force
php artisan optimize
```

`php artisan migrate --force` also adds `idx_user_reports_date` on the portal `user_reports` table, and `idx_pe_holidays_date` on estimator `holidays` when that table exists. It creates `actions`, `recycle`, and `settings` on the **core** database when they are missing. If migrate cannot reach those databases, run `sql/portal/indexes.sql` (and `sql/project_estimator/indexes.sql`) in phpMyAdmin instead, and import `sql/core/schema.sql` against the core schema (`eoxvhumy_ikaika_platform` — skip the local `CREATE DATABASE` / `USE ikaika_platform` header).

Do not run `php artisan key:generate` during an update.

## 9. Replace the public staging directory

Back up the current public directory:

```bash
cd /home2/eoxvhumy/public_html/staging

mv central-api central-api-backup-before-update
mkdir central-api
```

Copy every public Laravel file, including `.htaccess` and `build/`:

```bash
cp -a \
  /home2/eoxvhumy/apps/central-platform/public/. \
  /home2/eoxvhumy/public_html/staging/central-api/
```

Keep the stock Laravel `.htaccess` (the one with `!-d` and `!-f`). Do not add `RewriteBase`. The playground prefixes `/staging/central-api` onto `/api/...` in the browser; Laravel itself still sees `/api/...`.

Because the complete `public/` directory is copied, there is no need to copy `public/build` separately.

## 10. Replace the public `index.php`

Edit:

```text
/home2/eoxvhumy/public_html/staging/central-api/index.php
```

Its complete contents should be:

```php
<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists(
    $maintenance = __DIR__.'/../../../apps/central-platform/storage/framework/maintenance.php'
)) {
    require $maintenance;
}

require __DIR__.'/../../../apps/central-platform/vendor/autoload.php';

(require_once __DIR__.'/../../../apps/central-platform/bootstrap/app.php')
    ->handleRequest(Request::capture());
```

The three `../../../apps/central-platform/...` paths are required because only Laravel's public files are inside `public_html`.

Do not add the ineffective PHP 8.4 `.htaccess` handler. The project dependencies are intentionally resolved for Bluehost PHP 8.3.33.

Set public permissions:

```bash
find /home2/eoxvhumy/public_html/staging/central-api -type d -exec chmod 755 {} \;
find /home2/eoxvhumy/public_html/staging/central-api -type f -exec chmod 644 {} \;
```

## 11. Test the deployment

Test Laravel health:

```text
https://ikaikabim.com/staging/central-api/up
```

Test the central database:

```text
https://ikaikabim.com/staging/central-api/api/staging/core/health
```

Expected response structure:

```json
{
  "product": "core",
  "health": {
    "ok": true,
    "database": "eoxvhumy_ikaika_platform",
    "error": null
  }
}
```

Test the portal database:

```text
https://ikaikabim.com/staging/central-api/api/staging/portal/health
```

Hard-refresh the API playground using `Ctrl+F5`. The address bar stays:

```text
https://ikaikabim.com/staging/central-api/
```

Click Core health. The path field and the status line must show:

```text
/staging/central-api/api/staging/core/health
```

If it still shows `/api/staging/core/health` and returns WordPress HTML, the new `playground.blade.php` is not in this release. Laravel routes stay `/api/...`; the browser must prefix `/staging/central-api`.

If it still shows `/api/staging/core/health` and returns WordPress HTML, the new playground code is not in this release. Confirm `app/Support/ApiPath.php` exists in `/home2/eoxvhumy/apps/central-platform` and that `resources/views/playground.blade.php` was in the ZIP.

The Expo portal is a separate app. Production portal `.env` needs:

```dotenv
EXPO_PUBLIC_API_PATH_PREFIX=/staging/central-api
EXPO_PUBLIC_API_CHANNEL=staging
```

or one full override:

```dotenv
EXPO_PUBLIC_API_BASE_URL=https://ikaikabim.com/staging/central-api/api/staging/portal
```

Inspect Laravel errors when necessary:

```bash
tail -n 100 /home2/eoxvhumy/apps/central-platform/storage/logs/laravel.log
```

## 12. Roll back if testing fails

Restore the previous private application:

```bash
cd /home2/eoxvhumy/apps

mv central-platform central-platform-failed-release
mv central-platform-backup-before-update central-platform
```

Restore the previous public directory:

```bash
cd /home2/eoxvhumy/public_html/staging

mv central-api central-api-failed-release
mv central-api-backup-before-update central-api
```

If the failed release ran database migrations, review the migration before using `php artisan migrate:rollback`. Do not blindly roll back a shared or existing database.

## 13. Clean up after successful verification

Keep the backups until health checks, login, database reads, writes, authorization, and frontend API requests have been verified. Afterward, delete the following backup directories through Bluehost File Manager and move them to Trash:

```text
/home2/eoxvhumy/apps/central-platform-backup-before-update
/home2/eoxvhumy/public_html/staging/central-api-backup-before-update
```

Do not delete the active directories:

```text
/home2/eoxvhumy/apps/central-platform
/home2/eoxvhumy/public_html/staging/central-api
```
