<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Support\Portal\PortalEmailMessages;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * MAIL_MAILER=wprelay hands each message to the WordPress mail bridge. What matters is that the
 * message reaches it whole -- recipients by kind, the built HTML, the sender's name and Reply-To --
 * with the key in a header, and that any refusal surfaces as a transport failure a send records.
 */
class PortalMailRelayTest extends TestCase
{
    private const URL = 'https://relay.test/wp-json/ikaika-portal/v1/mail';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.mailers.wprelay.endpoint' => self::URL,
            'mail.mailers.wprelay.key' => str_repeat('k', 64),
        ]);
    }

    public function test_a_message_reaches_the_bridge_whole_with_the_key(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true, 'id' => 'abc-123'])]);

        $sent = Mail::mailer('wprelay')
            ->to('alpha@supplier.test')
            ->cc('boss@ikaika.test')
            ->bcc('audit@ikaika.test')
            ->send(new PortalEmailMail('Hello', '**Hi** there', null, 'markup', 'markup', 'Jane Doe via IKAIKA Portal', 'jane@ikaika.test'));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::URL
                && $request->header('X-Ikaika-Relay-Key') === [str_repeat('k', 64)]
                && $request['to'] === ['alpha@supplier.test']
                && $request['cc'] === ['boss@ikaika.test']
                && $request['bcc'] === ['audit@ikaika.test']
                && $request['subject'] === 'Hello'
                && str_contains((string) $request['html'], '<strong>Hi</strong>')
                && $request['fromName'] === 'Jane Doe via IKAIKA Portal'
                && $request['replyTo'] === 'jane@ikaika.test';
        });
        $this->assertSame('250 relayed id=abc-123', PortalEmailMessages::acceptance($sent));
    }

    public function test_a_refusal_from_the_bridge_is_a_transport_failure_with_its_reason(): void
    {
        Http::fake([self::URL => Http::response(['ok' => false, 'error' => 'The hourly mail limit is reached.'], 429)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('HTTP 429): The hourly mail limit is reached.');

        Mail::mailer('wprelay')->to('alpha@supplier.test')->send(PortalEmailMessages::testMail());
    }

    public function test_a_bridge_that_answers_without_a_reason_or_cannot_be_reached_still_fails(): void
    {
        Http::fake([self::URL => Http::response('', 403)]);
        try {
            Mail::mailer('wprelay')->to('alpha@supplier.test')->send(PortalEmailMessages::testMail());
            $this->fail('A refused message must not read as sent.');
        } catch (TransportException $error) {
            $this->assertStringContainsString('HTTP 403): no reason given', $error->getMessage());
        }

        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('could not be reached: Could not resolve host');
        Mail::mailer('wprelay')->to('alpha@supplier.test')->send(PortalEmailMessages::testMail());
    }

    // A 404 here means WordPress has no such route: the plugin file is not loaded.
    public function test_wordpress_own_refusal_is_reported_in_its_words(): void
    {
        Http::fake([self::URL => Http::response([
            'code' => 'rest_no_route',
            'message' => 'No route was found matching the URL and request method.',
        ], 404)]);

        $this->expectExceptionMessage('HTTP 404): No route was found matching the URL and request method. [rest_no_route]');

        Mail::mailer('wprelay')->to('alpha@supplier.test')->send(PortalEmailMessages::testMail());
    }

    public function test_an_unconfigured_bridge_says_what_to_set(): void
    {
        config(['mail.mailers.wprelay.key' => '']);
        Http::fake();

        $this->expectExceptionMessage('set MAIL_RELAY_URL and MAIL_RELAY_KEY');

        Mail::mailer('wprelay')->to('alpha@supplier.test')->send(PortalEmailMessages::testMail());
    }
}
