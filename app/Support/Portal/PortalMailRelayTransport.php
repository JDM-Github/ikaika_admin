<?php

namespace App\Support\Portal;

use Composer\CaBundle\CaBundle;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * MAIL_MAILER=wprelay: each message is handed to the WordPress mail bridge on the company site
 * (docs/deploy/wordpress/ikaika-portal-mail.php, a must-use plugin), which sends it with wp_mail from
 * noreply@ikaikabim.com -- the way the estimator and the cron scripts already send. It exists for as
 * long as this backend runs off that host; on it, MAIL_MAILER=sendmail does the same with no bridge.
 *
 * To, Cc and Bcc travel as the message has them, so a CC and BCC copy stays one message. The bridge's
 * own message id comes back as a "250 relayed id=..." line, which the mail report reads like any
 * server's acceptance.
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

        try {
            // Windows PHP often has no CA file of its own, so verify against Composer's, as the other clients do.
            $response = Http::timeout(30)
                ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                ->acceptJson()
                ->withHeaders(['X-Ikaika-Relay-Key' => $this->key])
                ->post($this->url, [
                    'to' => self::addresses($email->getTo()),
                    'cc' => self::addresses($email->getCc()),
                    'bcc' => self::addresses($email->getBcc()),
                    'subject' => (string) $email->getSubject(),
                    'html' => self::body($email->getHtmlBody()),
                    'text' => self::body($email->getTextBody()),
                    'fromName' => $from?->getName() ?? '',
                    'replyTo' => $replyTo?->getAddress(),
                ]);
        } catch (Throwable $error) {
            throw new TransportException('The mail bridge could not be reached: '.$error->getMessage(), 0, $error);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            // The bridge answers with "error"; WordPress itself (no such route, no access) with "code" and "message".
            $reason = $response->json('error') ?? $response->json('message');
            $code = $response->json('code');
            throw new TransportException('The mail bridge refused the message (HTTP '.$response->status().'): '
                .(is_string($reason) && $reason !== '' ? $reason : 'no reason given')
                .(is_string($code) && $code !== '' ? ' ['.$code.']' : ''));
        }

        $message->appendDebug('< 250 relayed id='.(string) $response->json('id').PHP_EOL);
    }

    public function __toString(): string
    {
        return 'wprelay://'.(string) parse_url($this->url, PHP_URL_HOST);
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
