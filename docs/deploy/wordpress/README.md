# WordPress mail bridge (temporary)

`ikaika-portal-mail.php` lets this backend send email as `noreply@ikaikabim.com` through the company
WordPress site's `wp_mail`, the way the Project Estimator and the cron scripts send, with no mailbox
and no mail password. This file is the reference copy; the live copy is on the server.

It is temporary. Once the backend runs on the Bluehost host itself, `MAIL_MAILER=sendmail` does the
same with no bridge, and this can be removed.

## Where it lives

The live site is the WordPress install in **`public_html/`**, not `public_html/website_26e391bd/`
(a separate install that does not serve `ikaikabim.com`).

| Piece | Location |
| --- | --- |
| Plugin | `public_html/wp-content/mu-plugins/ikaika-portal-mail.php` |
| Key | `public_html/wp-config.php`: `define('IKAIKA_PORTAL_MAIL_KEY', '<64 hex characters>');` |
| Backend | `.env`: `MAIL_MAILER=wprelay`, `MAIL_RELAY_URL`, `MAIL_RELAY_KEY` (the same key) |
| Send log | `public_html/error_log`, lines starting `[IkaikaPortalMail]` |

Make a key with `php -r "echo bin2hex(random_bytes(32));"`. Never commit it or paste it anywhere.

## Attachments (v2)

Emails with files go to `/wp-json/ikaika-portal/v2/mail`; everything else still uses `v1`. The backend
works the v2 address out from `MAIL_RELAY_URL`, so `.env` does not change. A bridge from before v2 has
no such route: it answers `rest_no_route`, and the portal records the send as failed rather than letting
it go out without its files. **Upload the current `ikaika-portal-mail.php` before sending attachments.**

The bridge writes each message's files to a folder of its own under the server's temp directory, hands
them to `wp_mail`, and deletes them whether the send worked or not. It takes up to 10 files and 12 MB a
message (the portal holds a send to 10 MB) of PDF, Office, CSV, text, image and ZIP files.

The request body is the files in base64, about a third larger than the files: the host's PHP
`post_max_size` must allow roughly 16 MB.

## Checks

- `https://ikaikabim.com/wp-json/ikaika-portal/v1` lists `"namespace":"ikaika-portal/v1"`: the plugin is loaded.
- `https://ikaikabim.com/wp-json/ikaika-portal/v2` lists `"namespace":"ikaika-portal/v2"`: the plugin takes attachments.
- `rest_no_route`: the file is not in the live `mu-plugins` (a subfolder or the other install).
- `401 rest_forbidden`: the key in `.env` and `wp-config.php` differ.
- `php artisan portal:mail-test` prints `Server reply: 250 relayed id=...` when it works; the full report is `storage/logs/mail-test.log`.

## Remove

Delete the plugin file from `mu-plugins`, remove the `IKAIKA_PORTAL_MAIL_KEY` line from `wp-config.php`,
and set `MAIL_MAILER` back to `log` (local) or `sendmail` (on the host).
