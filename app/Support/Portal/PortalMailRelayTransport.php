<?php

namespace App\Support\Portal;

use Composer\CaBundle\CaBundle;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * MAIL_MAILER=wprelay: each message is handed to the WordPress mail bridge on the company site
 * (docs/deploy/wordpress/ikaika-portal-mail.php, a must-use plugin), which sends it with wp_mail from
 * noreply@ikaikabim.com -- the way the estimator and the cron scripts already send. It exists for as
 * long as this backend runs off that host; on it, MAIL_MAILER=sendmail does the same with no bridge.
 *
 * To, Cc and Bcc travel as the message has them, so a CC and BCC copy stays one message. From travels
 * as the display name and the address; the bridge keeps that address only when it is one on the domain
 * it sends for (a "send as me" mail), and otherwise sends from its own noreply. The bridge's own
 * message id comes back as a "250 relayed id=..." line, which the mail report reads like any server's
 * acceptance.
 *
 * A message with files goes to the bridge's v2 route instead, each file base64 in the body. The first
 * bridge only knows v1 and would send such a message without its files, so v2 is a route of its own:
 * an old bridge answers it with rest_no_route and the send fails, rather than going out incomplete --
 * with a reason that says what to upload, since All Emails shows it to whoever sent the email.
 */
final class PortalMailRelayTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $url,
        private readonly string $key,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->url === '' || $this->key === '') {
            throw new TransportException('The mail bridge is not configured: set MAIL_RELAY_URL and MAIL_RELAY_KEY.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $from = $email->getFrom()[0] ?? null;
        $replyTo = $email->getReplyTo()[0] ?? null;
        $files = $email->getAttachments();
        $payload = [
            'to' => self::addresses($email->getTo()),
            'cc' => self::addresses($email->getCc()),
            'bcc' => self::addresses($email->getBcc()),
            'subject' => (string) $email->getSubject(),
            'html' => self::body($email->getHtmlBody()),
            'text' => self::body($email->getTextBody()),
            'fromName' => $from?->getName() ?? '',
            // The bridge sends from its own address unless the address is one on the domain it sends for.
            'from' => $from?->getAddress(),
            'replyTo' => $replyTo?->getAddress(),
        ];
        if ($files !== []) {
            $payload['attachments'] = array_map(self::file(...), $files);
        }
        $url = $files === [] ? $this->url : $this->attachmentsUrl();

        try {
            // Windows PHP often has no CA file of its own, so verify against Composer's, as the other clients do.
            // Files make a far larger body, so they get longer to cross.
            $response = Http::timeout($files === [] ? 30 : 120)
                ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                ->acceptJson()
                ->withHeaders(['X-Ikaika-Relay-Key' => $this->key])
                ->post($url, $payload);
        } catch (Throwable $error) {
            throw new TransportException('The mail bridge could not be reached: '.$error->getMessage(), 0, $error);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            // The bridge answers with "error"; WordPress itself (no such route, no access) with "code" and "message".
            $reason = $response->json('error') ?? $response->json('message');
            $code = $response->json('code');
            if ($files !== [] && $code === 'rest_no_route') {
                throw new TransportException('The mail bridge on '.(string) parse_url($this->url, PHP_URL_HOST).' needs updating to send files: '
                    .'upload the current ikaika-portal-mail.php to its wp-content/mu-plugins, then send again. [rest_no_route]');
            }
            throw new TransportException('The mail bridge refused the message (HTTP '.$response->status().'): '
                .(is_string($reason) && $reason !== '' ? $reason : 'no reason given')
                .(is_string($code) && $code !== '' ? ' ['.$code.']' : ''));
        }

        $message->appendDebug(
            '< 250 relayed id='.(string) $response->json('id')
            .(($bridgeVersion = (string) $response->header('X-Ikaika-Bridge')) === '' ? '' : ' bridge=v'.$bridgeVersion)
            .PHP_EOL,
        );
    }

    public function __toString(): string
    {
        return 'wprelay://'.(string) parse_url($this->url, PHP_URL_HOST);
    }

    // The same bridge's v2 route, which takes files: MAIL_RELAY_URL names v1.
    private function attachmentsUrl(): string
    {
        $url = (string) preg_replace('#/ikaika-portal/v1/mail(?=$|[?\#])#', '/ikaika-portal/v2/mail', $this->url, 1, $replaced);
        if ($replaced !== 1) {
            throw new TransportException('MAIL_RELAY_URL must end in /ikaika-portal/v1/mail, so a message with files can reach the bridge\'s v2 route.');
        }

        return $url;
    }

    /*
     * One file for the bridge. An inline image (a share's QR) is shown by the body as cid:<its name>, so
     * the bridge must embed it under that id rather than attach it; it also gets an extension, which the
     * bridge needs to know its type. A bridge from before inline images attaches it instead of failing.
     *
     * @return array{name: string, type: string, content: string, inline?: bool, cid?: string}
     */
    private static function file(DataPart $file): array
    {
        $name = (string) $file->getFilename();
        $entry = [
            'name' => $name,
            'type' => $file->getMediaType().'/'.$file->getMediaSubtype(),
            'content' => base64_encode($file->getBody()),
        ];
        if ($file->getDisposition() !== 'inline') {
            return $entry;
        }

        return [
            ...$entry,
            'name' => pathinfo($name, PATHINFO_EXTENSION) === '' ? $name.'.'.$file->getMediaSubtype() : $name,
            'inline' => true,
            'cid' => $name,
        ];
    }

    /**
     * @param  list<Address>  $list
     * @return list<string>
     */
    private static function addresses(array $list): array
    {
        return array_map(static fn (Address $address): string => $address->getAddress(), $list);
    }

    private static function body(mixed $body): string
    {
        return is_resource($body) ? (string) stream_get_contents($body) : (string) $body;
    }
}
