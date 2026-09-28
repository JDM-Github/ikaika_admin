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
 * Not an open relay: without the key every request is refused, and the key is compared in constant
 * time. It lives in wp-config.php, never in this file:
 *
 *   define('IKAIKA_PORTAL_MAIL_KEY', '<64 random hex characters>');
 *
 * From is always noreply@ikaikabim.com; only its display name may be set. Recipients are validated
 * and capped per message, messages are capped per hour, and every send is written to error_log.
 */

if (! defined('ABSPATH')) {
    exit;
}

const IKAIKA_PORTAL_MAIL_FROM = 'noreply@ikaikabim.com';
// A Custom send is one message: up to 50 To, 20 Cc and 20 Bcc.
const IKAIKA_PORTAL_MAIL_MAX_RECIPIENTS = 100;
const IKAIKA_PORTAL_MAIL_MAX_PER_HOUR = 300;
const IKAIKA_PORTAL_MAIL_MAX_HTML = 200000;

add_action('rest_api_init', function () {
    register_rest_route('ikaika-portal/v1', '/mail', [
        'methods' => 'POST',
        'callback' => 'ikaika_portal_mail_send',
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

function ikaika_portal_mail_send(WP_REST_Request $request): WP_REST_Response
{
    $payload = $request->get_json_params();
    if (! is_array($payload)) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => 'Expected a JSON body.']);
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
    $sent = wp_mail($to, $subject, $html, $headers);
    remove_action('wp_mail_failed', $failHook);
    remove_action('phpmailer_init', $textHook);

    $id = wp_generate_uuid4();
    if (! $sent) {
        error_log('[IkaikaPortalMail] '.$id.' failed ('.$count.' recipients): '.($error ?: 'wp_mail returned false'));

        return ikaika_portal_mail_reply(502, ['ok' => false, 'error' => $error ?: 'wp_mail could not send the message.', 'id' => $id]);
    }

    set_transient($window, $sentThisHour + 1, HOUR_IN_SECONDS);
    error_log('[IkaikaPortalMail] '.$id.' sent ('.$count.' recipients): '.$subject);

    return ikaika_portal_mail_reply(200, ['ok' => true, 'id' => $id]);
}
