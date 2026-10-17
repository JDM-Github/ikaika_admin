<?php

/**
 * Plugin Name: IKAIKA Portal mail bridge
 * Version: 6
 * Description: Lets the IKAIKA Portal backend send its email through this server's wp_mail, as the
 *              estimator and the cron scripts do, until the portal backend itself runs on this host.
 *              Temporary: delete this file to remove it.
 *
 * Upload to wp-content/mu-plugins/ (must-use plugins load on their own; nothing to activate).
 *
 * POST /wp-json/ikaika-portal/v1/mail
 *   Header  X-Ikaika-Relay-Key: <the key>
 *   Body    { "to": [..], "cc": [..], "bcc": [..], "subject": "..", "html": "..", "text": "..",
 *             "fromName": "..", "from": "..", "replyTo": ".." }
 *
 * POST /wp-json/ikaika-portal/v2/mail
 *   The same, plus "attachments": [{ "name": "Quote.pdf", "type": "application/pdf", "content": "<base64>" }].
 *   A route of its own, so a portal that sends files to a bridge without it is refused (rest_no_route)
 *   instead of having its message go out without them.
 *   An image the body shows itself (a share's QR, as <img src="cid:share-qr">) comes as
 *   { "name": "share-qr.png", ..., "inline": true, "cid": "share-qr" } and is embedded under that id,
 *   not attached; without this a mail client shows a broken image and the picture as a loose file.
 *
 * Not an open relay: without the key every request is refused, and the key is compared in constant
 * time. It lives in wp-config.php, never in this file:
 *
 *   define('IKAIKA_PORTAL_MAIL_KEY', '<64 random hex characters>');
 *
 * From is the sender's own address when "from" is one on this bridge's own domain (a "send as me"
 * mail), and otherwise noreply@ikaikabim.com; a "from" on any other domain is ignored, so the route
 * can never be used to send as another domain. Only the display name may be set in every other case.
 * Recipients are validated and capped per message, messages are capped per hour, files are capped by
 * count, size and type, and every send is written to error_log.
 *
 * A "send as me" mail leaves from the person's own mailbox. A single SMTP login cannot do that --
 * Gmail, and most relays, rewrite the From to the account they authenticate as -- so when these three
 * are set in wp-config.php that mail goes through Microsoft Graph with app-only Mail.Send instead,
 * and everything else (and every send without them) still goes through wp_mail:
 *
 *   define('IKAIKA_GRAPH_TENANT', '<directory (tenant) id>');
 *   define('IKAIKA_GRAPH_CLIENT_ID', '<application (client) id>');
 *   define('IKAIKA_GRAPH_CLIENT_SECRET', '<client secret value>');
 *
 * The app registration needs the Mail.Send APPLICATION permission, with admin consent; it may then
 * send as any mailbox in the tenant.
 */
if (! defined('ABSPATH')) {
    exit;
}

const IKAIKA_PORTAL_MAIL_FROM = 'noreply@ikaikabim.com';
// A "from" address must be on this domain to be used; anything else leaves from the address above.
const IKAIKA_PORTAL_MAIL_FROM_DOMAIN = 'ikaikabim.com';
// The copy of this plugin answering. Read back on every response as X-Ikaika-Bridge, so an old
// upload is obvious from one request instead of a mail header.
const IKAIKA_PORTAL_MAIL_VERSION = '6';
// A Custom send is one message: up to 50 To, 20 Cc and 20 Bcc.
const IKAIKA_PORTAL_MAIL_MAX_RECIPIENTS = 100;
const IKAIKA_PORTAL_MAIL_MAX_PER_HOUR = 300;
const IKAIKA_PORTAL_MAIL_MAX_HTML = 200000;
// The portal holds an email's files to 10 files and 10 MB; this is the bridge's own ceiling over that.
const IKAIKA_PORTAL_MAIL_MAX_FILES = 10;
const IKAIKA_PORTAL_MAIL_MAX_FILE_BYTES = 12 * 1024 * 1024;
const IKAIKA_PORTAL_MAIL_FILE_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip'];
// Only a picture can sit inside the body.
const IKAIKA_PORTAL_MAIL_INLINE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

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
    $response->header('X-Ikaika-Bridge', IKAIKA_PORTAL_MAIL_VERSION);

    return $response;
}

/**
 * A line of diagnostics, written to a file of its own under wp-content so it can be read in the File
 * Manager whatever the host's error_log path is, and to error_log beside it.
 */
function ikaika_portal_mail_note(string $line): void
{
    @file_put_contents(WP_CONTENT_DIR.'/ikaika-portal-mail.log', gmdate('c').' '.$line."\n", FILE_APPEND | LOCK_EX);
    error_log('[IkaikaPortalMail] '.$line);
}

// Whether the three Graph credentials are set, so a "send as me" mail can leave from the person's own
// mailbox instead of being rewritten to the account the site's SMTP login belongs to.
function ikaika_portal_mail_graph_ready(): bool
{
    foreach (['IKAIKA_GRAPH_TENANT', 'IKAIKA_GRAPH_CLIENT_ID', 'IKAIKA_GRAPH_CLIENT_SECRET'] as $name) {
        if (! defined($name) || trim((string) constant($name)) === '') {
            return false;
        }
    }

    return true;
}

// An app-only Graph token (client credentials), cached for its lifetime so each send does not wait on
// a round trip. Null when it could not be had.
function ikaika_portal_mail_graph_token(): ?string
{
    $cached = get_transient('ikaika_portal_mail_graph_token');
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $response = wp_remote_post('https://login.microsoftonline.com/'.rawurlencode((string) IKAIKA_GRAPH_TENANT).'/oauth2/v2.0/token', [
        'timeout' => 20,
        'body' => [
            'grant_type' => 'client_credentials',
            'client_id' => (string) IKAIKA_GRAPH_CLIENT_ID,
            'client_secret' => (string) IKAIKA_GRAPH_CLIENT_SECRET,
            'scope' => 'https://graph.microsoft.com/.default',
        ],
    ]);
    if (is_wp_error($response)) {
        return null;
    }
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    $token = is_array($data) && isset($data['access_token']) ? (string) $data['access_token'] : '';
    if ($token === '') {
        return null;
    }
    $lifetime = is_array($data) ? (int) ($data['expires_in'] ?? 3600) : 3600;
    set_transient('ikaika_portal_mail_graph_token', $token, max(60, $lifetime - 120));

    return $token;
}

/**
 * @param  list<string>  $list
 * @return list<array{emailAddress: array{address: string}}>
 */
function ikaika_portal_mail_graph_addresses(array $list): array
{
    return array_map(static fn (string $address): array => ['emailAddress' => ['address' => $address]], $list);
}

/**
 * The message's files as Graph fileAttachments, straight from the payload's base64 (an inline image
 * keeps its content id and is marked inline). Returns the attachments and a refusal reason.
 *
 * @return array{0: list<array<string, mixed>>, 1: ?string}
 */
function ikaika_portal_mail_graph_attachments($value): array
{
    if (! is_array($value) || $value === []) {
        return [[], null];
    }
    if (count($value) > IKAIKA_PORTAL_MAIL_MAX_FILES) {
        return [[], 'Too many attachments for one message.'];
    }

    $attachments = [];
    $total = 0;
    foreach ($value as $entry) {
        $name = sanitize_file_name(is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || ! in_array($extension, IKAIKA_PORTAL_MAIL_FILE_TYPES, true)) {
            return [[], 'An attachment is not a type this bridge sends.'];
        }
        $content = base64_decode(is_string($entry['content'] ?? null) ? $entry['content'] : '', true);
        if ($content === false || $content === '') {
            return [[], 'An attachment is empty or not base64.'];
        }
        $total += strlen($content);
        if ($total > IKAIKA_PORTAL_MAIL_MAX_FILE_BYTES) {
            return [[], 'The attachments are too large for one message.'];
        }
        $type = is_string($entry['type'] ?? null) && $entry['type'] !== '' ? (string) $entry['type'] : 'application/octet-stream';
        $attachment = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $name,
            'contentType' => $type,
            'contentBytes' => base64_encode($content),
        ];
        if (! empty($entry['inline'])) {
            $cid = is_string($entry['cid'] ?? null) ? $entry['cid'] : '';
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $cid) === 1 && in_array($extension, IKAIKA_PORTAL_MAIL_INLINE_TYPES, true)) {
                $attachment['contentId'] = $cid;
                $attachment['isInline'] = true;
            }
        }
        $attachments[] = $attachment;
    }

    return [$attachments, null];
}

/**
 * One mail through Microsoft Graph, sent as $from. Graph answers 202 with no body on success.
 *
 * @param  list<string>  $to
 * @param  list<string>  $cc
 * @param  list<string>  $bcc
 * @param  list<string>  $replyTo
 * @param  list<array<string, mixed>>  $attachments
 * @return array{ok: bool, error: ?string}
 */
function ikaika_portal_mail_graph_send(string $from, string $fromName, array $to, array $cc, array $bcc, string $subject, string $html, array $replyTo, array $attachments): array
{
    $token = ikaika_portal_mail_graph_token();
    if ($token === null) {
        return ['ok' => false, 'error' => 'Microsoft Graph could not be reached (no token).'];
    }

    $message = [
        'subject' => $subject,
        'body' => ['contentType' => 'HTML', 'content' => $html],
        'from' => ['emailAddress' => ['address' => $from, 'name' => $fromName === '' ? $from : $fromName]],
        'toRecipients' => ikaika_portal_mail_graph_addresses($to),
    ];
    if ($cc !== []) {
        $message['ccRecipients'] = ikaika_portal_mail_graph_addresses($cc);
    }
    if ($bcc !== []) {
        $message['bccRecipients'] = ikaika_portal_mail_graph_addresses($bcc);
    }
    if ($replyTo !== []) {
        $message['replyTo'] = ikaika_portal_mail_graph_addresses($replyTo);
    }
    if ($attachments !== []) {
        $message['attachments'] = $attachments;
    }

    $response = wp_remote_post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($from).'/sendMail', [
        'timeout' => 60,
        'headers' => ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
        'body' => wp_json_encode(['message' => $message, 'saveToSentItems' => true]),
    ]);
    if (is_wp_error($response)) {
        return ['ok' => false, 'error' => $response->get_error_message()];
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $reason = is_array($body) && isset($body['error']['message']) ? (string) $body['error']['message'] : ('HTTP '.$status);

        return ['ok' => false, 'error' => $reason];
    }

    return ['ok' => true, 'error' => null];
}

/**
 * The address a message may leave from: the caller's own, but only one on the domain this bridge
 * sends for. Anything else is ignored, so the route can never be used to send as another domain;
 * null means "use the bridge's own noreply".
 */
function ikaika_portal_mail_from($value): ?string
{
    $address = strtolower(trim(is_string($value) ? $value : ''));
    if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    $domain = substr($address, strpos($address, '@') + 1);

    return $domain === IKAIKA_PORTAL_MAIL_FROM_DOMAIN ? $address : null;
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
 * own name, which is what wp_mail attaches it as. An inline image is written the same way but kept
 * apart, with the content id the body names it by. Answers with the attachment paths, the folder to
 * remove, why it refused, and each inline image's path, cid and name.
 *
 * @return array{0: list<string>, 1: ?string, 2: ?string, 3: list<array{0: string, 1: string, 2: string}>}
 */
function ikaika_portal_mail_files($value): array
{
    if (! is_array($value) || $value === []) {
        return [[], null, null, []];
    }
    if (count($value) > IKAIKA_PORTAL_MAIL_MAX_FILES) {
        return [[], null, 'Too many attachments for one message.', []];
    }

    $files = [];
    $total = 0;
    foreach ($value as $entry) {
        $name = sanitize_file_name(is_array($entry) && is_string($entry['name'] ?? null) ? $entry['name'] : '');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || ! in_array($extension, IKAIKA_PORTAL_MAIL_FILE_TYPES, true)) {
            return [[], null, 'An attachment is not a type this bridge sends.', []];
        }
        $cid = null;
        if (! empty($entry['inline'])) {
            $cid = is_string($entry['cid'] ?? null) ? $entry['cid'] : '';
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $cid) !== 1 || ! in_array($extension, IKAIKA_PORTAL_MAIL_INLINE_TYPES, true)) {
                return [[], null, 'An inline image needs a picture type and a plain content id.', []];
            }
        }
        $content = base64_decode(is_string($entry['content'] ?? null) ? $entry['content'] : '', true);
        if ($content === false || $content === '') {
            return [[], null, 'An attachment is empty or not base64.', []];
        }
        $total += strlen($content);
        if ($total > IKAIKA_PORTAL_MAIL_MAX_FILE_BYTES) {
            return [[], null, 'The attachments are too large for one message.', []];
        }
        $files[] = [$name, $content, $cid];
    }

    $folder = trailingslashit(get_temp_dir()).'ikaika-portal-mail-'.wp_generate_uuid4();
    if (! wp_mkdir_p($folder)) {
        return [[], null, 'The attachments could not be written to this server.', []];
    }
    $paths = [];
    $inline = [];
    foreach ($files as [$name, $content, $cid]) {
        // Two files of one name would overwrite each other, so a repeat gets a number.
        $path = $folder.'/'.wp_unique_filename($folder, $name);
        if (file_put_contents($path, $content) === false) {
            ikaika_portal_mail_clear([...$paths, ...array_column($inline, 0)], $folder);

            return [[], null, 'The attachments could not be written to this server.', []];
        }
        if ($cid === null) {
            $paths[] = $path;
        } else {
            $inline[] = [$path, $cid, $name];
        }
    }

    return [$paths, $folder, null, $inline];
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

    // The display name is the caller's; the address is the caller's own only when it is on this
    // domain (a "send as me" mail), and the bridge's own noreply otherwise.
    $fromName = trim((string) preg_replace('/[\r\n"<>]+/', ' ', (string) ($payload['fromName'] ?? 'IKAIKA Portal')));
    $fromAddress = ikaika_portal_mail_from($payload['from'] ?? null) ?? IKAIKA_PORTAL_MAIL_FROM;
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: '.($fromName === '' ? 'IKAIKA Portal' : $fromName).' <'.$fromAddress.'>',
    ];
    // What the caller asked to leave from, and what this bridge will use, so a mismatch reads plainly
    // in the error_log instead of being inferred from the received mail.
    ikaika_portal_mail_note('v'.IKAIKA_PORTAL_MAIL_VERSION.' from requested='.(is_string($payload['from'] ?? null) && $payload['from'] !== '' ? (string) $payload['from'] : 'none').' using='.$fromAddress);
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

    // "Send as me": leave from the person's own mailbox. A single SMTP login cannot do that, so when
    // Graph is set up this one mail goes through it; every other mail, and every send without a Graph
    // setup, still goes through wp_mail below.
    $ownFrom = is_string($payload['from'] ?? null) && ikaika_portal_mail_from($payload['from']) !== null;
    if ($ownFrom && ikaika_portal_mail_graph_ready()) {
        [$attachments, $graphRefusal] = ikaika_portal_mail_graph_attachments($payload['attachments'] ?? []);
        if ($graphRefusal !== null) {
            return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => $graphRefusal]);
        }
        $graph = ikaika_portal_mail_graph_send($fromAddress, $fromName, $to, $cc, $bcc, $subject, $html, $replyTo, $attachments);
        $id = wp_generate_uuid4();
        $files = count($attachments);
        if ($graph['error'] !== null) {
            ikaika_portal_mail_note($id.' graph failed ('.$count.' recipients, '.$files.' files): '.$graph['error']);

            return ikaika_portal_mail_reply(502, ['ok' => false, 'error' => $graph['error'], 'id' => $id]);
        }
        set_transient($window, $sentThisHour + 1, HOUR_IN_SECONDS);
        ikaika_portal_mail_note($id.' graph sent ('.$count.' recipients, '.$files.' files): '.$subject.' from='.$fromAddress);

        return ikaika_portal_mail_reply(200, ['ok' => true, 'id' => $id, 'attachments' => $files]);
    }

    [$paths, $folder, $refusal, $inline] = $takesFiles ? ikaika_portal_mail_files($payload['attachments'] ?? []) : [[], null, null, []];
    if ($refusal !== null) {
        return ikaika_portal_mail_reply(400, ['ok' => false, 'error' => $refusal]);
    }

    $error = null;
    $failHook = function (WP_Error $failure) use (&$error) {
        $error = $failure->get_error_message();
    };
    $textHook = function ($phpmailer) use ($text, $inline) {
        if ($text !== '') {
            $phpmailer->AltBody = $text;
        }
        // Embedded under the id the body's <img src="cid:..."> names, so it shows in place.
        foreach ($inline as [$path, $cid, $name]) {
            $phpmailer->addEmbeddedImage($path, $cid, $name);
        }
    };
    add_action('wp_mail_failed', $failHook);
    add_action('phpmailer_init', $textHook);
    // A From header is read BEFORE WordPress's own wp_mail_from / wp_mail_from_name filters, so a
    // site filter or a mail plugin can put the portal's noreply back on. Setting those filters at the
    // highest priority makes the caller's address win over any filter the site has, and phpmailer_init
    // (which runs last, just before the send) puts it on the message as well.
    $fromFilter = static fn (string $email): string => $fromAddress;
    $fromNameFilter = static fn (string $name): string => $fromName === '' ? 'IKAIKA Portal' : $fromName;
    $setFromHook = function ($phpmailer) use ($fromAddress, $fromName): void {
        $phpmailer->setFrom($fromAddress, $fromName === '' ? 'IKAIKA Portal' : $fromName, false);
        // Keep the envelope sender in step with the From, so a host's own MTA does not rewrite the
        // header to the account's default address on the way out.
        $phpmailer->Sender = $fromAddress;
        // What PHPMailer will actually send with -- mailer and host name a relay that may rewrite it.
        ikaika_portal_mail_note('v'.IKAIKA_PORTAL_MAIL_VERSION.' phpmailer mailer='.$phpmailer->Mailer
            .' host='.(string) $phpmailer->Host.' from='.(string) $phpmailer->From.' sender='.(string) $phpmailer->Sender);
    };
    add_filter('wp_mail_from', $fromFilter, PHP_INT_MAX);
    add_filter('wp_mail_from_name', $fromNameFilter, PHP_INT_MAX);
    add_action('phpmailer_init', $setFromHook, PHP_INT_MAX);
    try {
        $sent = wp_mail($to, $subject, $html, $headers, $paths);
    } finally {
        remove_action('wp_mail_failed', $failHook);
        remove_action('phpmailer_init', $textHook);
        remove_action('phpmailer_init', $setFromHook, PHP_INT_MAX);
        remove_filter('wp_mail_from', $fromFilter, PHP_INT_MAX);
        remove_filter('wp_mail_from_name', $fromNameFilter, PHP_INT_MAX);
        // The files were only ever this message's: gone whether it sent or not.
        ikaika_portal_mail_clear([...$paths, ...array_column($inline, 0)], $folder);
    }

    $id = wp_generate_uuid4();
    $files = count($paths) + count($inline);
    if (! $sent) {
        ikaika_portal_mail_note($id.' failed ('.$count.' recipients, '.$files.' files): '.($error ?: 'wp_mail returned false'));

        return ikaika_portal_mail_reply(502, ['ok' => false, 'error' => $error ?: 'wp_mail could not send the message.', 'id' => $id]);
    }

    set_transient($window, $sentThisHour + 1, HOUR_IN_SECONDS);
    ikaika_portal_mail_note($id.' sent ('.$count.' recipients, '.$files.' files): '.$subject);

    return ikaika_portal_mail_reply(200, ['ok' => true, 'id' => $id, 'attachments' => $files]);
}
