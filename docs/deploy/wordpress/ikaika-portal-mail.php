<?php
/**
 * Plugin Name: IKAIKA Portal mail bridge
 * Description: Lets the IKAIKA Portal backend send its email through this server's wp_mail, as the
 *              estimator and the cron scripts do, until the portal backend itself runs on this host.
 *              Temporary: delete this file to remove it.
 *
 * Upload to wp-content/mu-plugins/ (must-use plugins load on their own; nothing to activate).
 *
 * POST /wp-json/ikaika-portal/v1/mail
 *   Header  X-Ikaika-Relay-Key: <the key>
 *   Body    { "to": [..], "cc": [..], "bcc": [..], "subject": "..", "html": "..", "text": "..",
 *             "fromName": "..", "replyTo": ".." }
 *
 * POST /wp-json/ikaika-portal/v2/mail
 *   The same, plus "attachments": [{ "name": "Quote.pdf", "type": "application/pdf", "content": "<base64>" }].
 *   A route of its own, so a portal that sends files to a bridge without it is refused (rest_no_route)
 *   instead of having its message go out without them.
 *
 * Not an open relay: without the key every request is refused, and the key is compared in constant
 * time. It lives in wp-config.php, never in this file:
 *
 *   define('IKAIKA_PORTAL_MAIL_KEY', '<64 random hex characters>');
 *
 * From is always noreply@ikaikabim.com; only its display name may be set. Recipients are validated
 * and capped per message, messages are capped per hour, files are capped by count, size and type, and
 * every send is written to error_log.
 */

if (! defined('ABSPATH')) {
    exit;
}

const IKAIKA_PORTAL_MAIL_FROM = 'noreply@ikaikabim.com';
// A Custom send is one message: up to 50 To, 20 Cc and 20 Bcc.
const IKAIKA_PORTAL_MAIL_MAX_RECIPIENTS = 100;
const IKAIKA_PORTAL_MAIL_MAX_PER_HOUR = 300;
const IKAIKA_PORTAL_MAIL_MAX_HTML = 200000;
// The portal holds an email's files to 10 files and 10 MB; this is the bridge's own ceiling over that.
const IKAIKA_PORTAL_MAIL_MAX_FILES = 10;
const IKAIKA_PORTAL_MAIL_MAX_FILE_BYTES = 12 * 1024 * 1024;
const IKAIKA_PORTAL_MAIL_FILE_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip'];

add_action('rest_api_init', function () {
    register_rest_route('ikaika-portal/v1', '/mail', [
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return ikaika_portal_mail_send($request, false);
        },
        'permission_callback' => 'ikaika_portal_mail_allowed',
    ]);
    register_rest_route('ikaika-portal/v2', '/mail', [
        'methods' => 'POST',
        'callback' => function (WP_REST_Request $request) {
            return ikaika_portal_mail_send($request, true);
        },
        'permission_callback' => 'ikaika_portal_mail_allowed',
    ]);
});

function ikaika_portal_mail_allowed(WP_REST_Request $request): bool
{
    $expected = defined('IKAIKA_PORTAL_MAIL_KEY') ? (string) IKAIKA_PORTAL_MAIL_KEY : '';
    $given = (string) $request->get_header('x_ikaika_relay_key');

    // A missing or short key on the server means the bridge is off, never open.
    return strlen($expected) >= 32 && $given !== '' && hash_equals($expected, $given);
}

function ikaika_portal_mail_reply(int $status, array $body): WP_REST_Response
{
    $response = new WP_REST_Response($body, $status);
    // The host's page cache must never keep or replay a mail response.
    $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

    return $response;
}

/**
 * @return list<string>|null null when any entry is not a real address
 */
function ikaika_portal_mail_addresses($value): ?array
{
    $addresses = [];
    foreach (is_array($value) ? $value : [] as $entry) {
        $address = strtolower(trim(is_string($entry) ? $entry : ''));
        if ($address === '') {
            continue;
        }
        // FILTER_VALIDATE_EMAIL also refuses CR and LF, so a passing address is safe in a header.
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        $addresses[$address] = true;
    }

    return array_keys($addresses);
}

/**
 * Writes the message's files into a folder of their own under the temp directory, each under its
 * own name, which is what wp_mail attaches it as. Answers with the paths, or with why it refused.
 *
 * @return array{0: list<string>, 1: ?string, 2: ?string} paths, the folder to remove, the refusal
 */
function ikaika_portal_mail_files($value): array
{
    if (! is_array($value) || $value === []) {
        return [[], null, null];
    }
    if (count($value) > IKAIKA_PORTAL_MAIL_MAX_FILES) {
        return [[], null, 'Too many attachments for one message.'];
    }

    $files = [];
    $total = 0;
    foreach ($value as $entry) {
        $name = sanitize_file_name(is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || ! in_array($extension, IKAIKA_PORTAL_MAIL_FILE_TYPES, true)) {
            return [[], null, 'An attachment is not a type this bridge sends.'];
        }
        $content = base64_decode(is_string($entry['content'] ?? null) ? $entry['content'] : '', true);
        if ($content === false || $content === '') {
            return [[], null, 'An attachment is empty or not base64.'];
        }
        $total += strlen($content);
        if ($total > IKAIKA_PORTAL_MAIL_MAX_FILE_BYTES) {
            return [[], null, 'The attachments are too large for one message.'];
        }
        $files[] = [$name, $content];
    }

    $folder = trailingslashit(get_temp_dir()).'ikaika-portal-mail-'.wp_generate_uuid4();
    if (! wp_mkdir_p($folder)) {
        return [[], null, 'The attachments could not be written to this server.'];
    }
    $paths = [];
    foreach ($files as [$name, $content]) {
        // Two files of one name would overwrite each other, so a repeat gets a number.
        $path = $folder.'/'.wp_unique_filename($folder, $name);
        if (file_put_contents($path, $content) === false) {
            ikaika_portal_mail_clear($paths, $folder);

            return [[], null, 'The attachments could not be written to this server.'];
        }
        $paths[] = $path;
    }

    return [$paths, $folder, null];
}

function ikaika_portal_mail_clear(array $paths, ?string $folder): void
{
    foreach ($paths as $path) {
        @unlink($path);
    }
    if ($folder !== null) {
        @rmdir($folder);
    }
}

function ikaika_portal_mail_send(WP_REST_Request $request, bool $takesFiles): WP_REST_Response
{
    $payload = $request->get_json_params();
    if (! is_array($payload)) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => 'Expected a JSON body.']);
    }
    // v1 never carried files; one that does was meant for v2 and must not go out without them.
    if (! $takesFiles && ! empty($payload['attachments'])) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => 'Attachments go to /ikaika-portal/v2/mail.']);
    }

    $to = ikaika_portal_mail_addresses($payload['to'] ?? []);
    $cc = ikaika_portal_mail_addresses($payload['cc'] ?? []);
    $bcc = ikaika_portal_mail_addresses($payload['bcc'] ?? []);
    if ($to === null || $cc === null || $bcc === null) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => 'Every address must be a valid email address.']);
    }
    $count = count($to) + count($cc) + count($bcc);
    if ($count === 0) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => 'The message has no recipient.']);
    }
    if ($count > IKAIKA_PORTAL_MAIL_MAX_RECIPIENTS) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => 'Too many recipients for one message.']);
    }

    $subject = trim((string) preg_replace('/[\r\n]+/', ' ', (string) ($payload['subject'] ?? '')));
    $html = (string) ($payload['html'] ?? '');
    $text = (string) ($payload['text'] ?? '');
    if ($subject === '' || $html === '') {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => 'A subject and an HTML body are required.']);
    }
    if (strlen($html) > IKAIKA_PORTAL_MAIL_MAX_HTML) {
        return ikaika_portal_mail_reply(413, ['ok' => false, 'error' => 'The message is too large.']);
    }

    // Counts messages per hour on a fixed window, so a runaway loop stops rather than floods.
    $window = 'ikaika_portal_mail_'.gmdate('YmdH');
    $sentThisHour = (int) get_transient($window);
    if ($sentThisHour >= IKAIKA_PORTAL_MAIL_MAX_PER_HOUR) {
        return ikaika_portal_mail_reply(429, ['ok' => false, 'error' => 'The hourly mail limit is reached.']);
    }

    // Only the display name is the caller's; the address is always the domain's own noreply.
    $fromName = trim((string) preg_replace('/[\r\n"<>]+/', ' ', (string) ($payload['fromName'] ?? 'IKAIKA Portal')));
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: '.($fromName === '' ? 'IKAIKA Portal' : $fromName).' <'.IKAIKA_PORTAL_MAIL_FROM.'>',
    ];
    $replyTo = ikaika_portal_mail_addresses([$payload['replyTo'] ?? '']);
    if ($replyTo === null) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => 'The reply address is not valid.']);
    }
    if ($replyTo !== []) {
        $headers[] = 'Reply-To: '.$replyTo[0];
    }
    foreach ($cc as $address) {
        $headers[] = 'Cc: '.$address;
    }
    foreach ($bcc as $address) {
        $headers[] = 'Bcc: '.$address;
    }

    [$paths, $folder, $refusal] = $takesFiles ? ikaika_portal_mail_files($payload['attachments'] ?? []) : [[], null, null];
    if ($refusal !== null) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => $refusal]);
    }

    $error = null;
    $failHook = function (WP_Error $failure) use (&$error) {
        $error = $failure->get_error_message();
    };
    $textHook = function ($phpmailer) use ($text) {
        if ($text !== '') {
            $phpmailer->AltBody = $text;
        }
    };
    add_action('wp_mail_failed', $failHook);
    add_action('phpmailer_init', $textHook);
    try {
        $sent = wp_mail($to, $subject, $html, $headers, $paths);
    } finally {
        remove_action('wp_mail_failed', $failHook);
        remove_action('phpmailer_init', $textHook);
        // The files were only ever this message's: gone whether it sent or not.
        ikaika_portal_mail_clear($paths, $folder);
    }

    $id = wp_generate_uuid4();
    $files = count($paths);
    if (! $sent) {
        error_log('[IkaikaPortalMail] '.$id.' failed ('.$count.' recipients, '.$files.' files): '.($error ?: 'wp_mail returned false'));

        return ikaika_portal_mail_reply(502, ['ok' => false, 'error' => $error ?: 'wp_mail could not send the message.', 'id' => $id]);
    }

    set_transient($window, $sentThisHour + 1, HOUR_IN_SECONDS);
    error_log('[IkaikaPortalMail] '.$id.' sent ('.$count.' recipients, '.$files.' files): '.$subject);

    return ikaika_portal_mail_reply(200, ['ok' => true, 'id' => $id, 'attachments' => $files]);
}
