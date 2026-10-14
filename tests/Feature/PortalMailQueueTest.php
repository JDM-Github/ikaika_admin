<?php

namespace Tests\Feature;

use App\Jobs\DeliverPortalEmail;
use App\Jobs\SendPortalMail;
use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalNotification;
use App\Support\Portal\PortalAdvancedSettings;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalEmailMarkup;
use App\Support\Portal\PortalEmailMessages;
use App\Support\Portal\PortalNotificationEmails;
use App\Support\Portal\PortalNotificationType;
use App\Support\Portal\PortalQrPng;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * The mail queue: Send Email answers before anything leaves and its row settles as each copy lands,
 * a refused copy is retried and then counted, and the system emails wait in the queue too.
 */
class PortalMailQueueTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_send_email_answers_at_once_and_each_copy_settles_the_row_as_it_lands(): void
    {
        Mail::fake();
        Queue::fake();
        [$first, $second] = $this->twoMembers();
        $noticesBefore = $this->emailNotices();

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Queued notice',
            'body' => 'Sent from the queue.',
            'audience' => 'members',
            'memberIds' => [(int) $first->getKey(), (int) $second->getKey()],
            'cc' => ['boss@ikaika.test'],
        ], $this->authHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', 'sending')
            ->assertJsonPath('data.recipientCount', 2)
            ->assertJsonPath('data.sentCount', 0)
            ->json('data');

        Mail::assertNothingSent();
        $copies = Queue::pushed(DeliverPortalEmail::class);
        // One own copy per member, and their CC shares one more.
        $this->assertCount(3, $copies);
        $this->assertSame(['boss@ikaika.test'], $copies->last()->to);
        $this->assertSame([], $copies->last()->recipients);

        // All Emails lists it as Sending, and counts it.
        $this->getJson(self::BASE.'/administration/emails?status=sending', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $sent['id'])
            ->assertJsonPath('data.0.status', 'sending');

        $messages = app(PortalEmailMessages::class);
        $copies->first()->handle($messages);
        $row = $this->row($sent['id']);
        $this->assertSame('sending', $row->status);
        $this->assertSame(1, (int) $row->sent_count);
        $this->assertNull($row->date_sent);

        foreach ($copies->slice(1) as $copy) {
            $copy->handle($messages);
        }
        $row = $this->row($sent['id']);
        $this->assertSame('sent', $row->status);
        $this->assertSame(2, (int) $row->sent_count);
        $this->assertSame(0, (int) $row->failed_count);
        $this->assertNotNull($row->date_sent);

        // Each copy is the composed email rebuilt from the row, and the bell rings for the two reached.
        Mail::assertSent(PortalEmailMail::class, 3);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo((string) $first->email)
            && $mail->subjectLine === 'Queued notice'
            && $mail->body === 'Sent from the queue.');
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo('boss@ikaika.test'));
        $this->assertSame($noticesBefore + 2, $this->emailNotices());

        // A copy the queue runs twice cannot count anyone past the total.
        $copies->first()->handle($messages);
        $this->assertSame(2, (int) $this->row($sent['id'])->sent_count);
    }

    public function test_a_refused_copy_is_retried_twice_then_counted_as_failed_by_name(): void
    {
        Queue::fake();
        $member = $this->member();
        $sent = $this->sendTo($member);
        $copy = Queue::pushed(DeliverPortalEmail::class)->first();
        $messages = app(PortalEmailMessages::class);
        $this->refuseMail('451 try again later for '.$member->email);

        $copy->withFakeQueueInteractions();
        $copy->handle($messages);
        $copy->assertReleased(60);
        $this->assertSame('sending', $this->row($sent['id'])->status);

        $second = (clone $copy)->withFakeQueueInteractions();
        $second->job->attempts = 2;
        $second->handle($messages);
        $second->assertReleased(300);

        $last = (clone $copy)->withFakeQueueInteractions();
        $last->job->attempts = 3;
        $last->handle($messages);
        $last->assertNotReleased();

        $row = $this->row($sent['id']);
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, (int) $row->failed_count);
        $failure = $row->failures[0];
        $this->assertSame((int) $member->getKey(), $failure['employeeId']);
        $this->assertStringContainsString('[address hidden]', $failure['reason']);
        $this->assertStringNotContainsString((string) $member->email, $failure['reason']);
    }

    // A worker killed mid-send never reaches the copy's own catch; giving up on it still settles the row.
    public function test_a_copy_the_worker_gave_up_on_settles_as_failed_with_a_plain_reason(): void
    {
        Queue::fake();
        $member = $this->member();
        $sent = $this->sendTo($member);
        $copy = Queue::pushed(DeliverPortalEmail::class)->first();

        $copy->failed(new MaxAttemptsExceededException('App\Jobs\DeliverPortalEmail has been attempted too many times.'));

        $row = $this->row($sent['id']);
        $this->assertSame('failed', $row->status);
        $this->assertSame('The mail queue stopped while sending this copy.', $row->failures[0]['reason']);

        // Given up on twice, it is still one recipient and one failure.
        $copy->failed(null);
        $row = $this->row($sent['id']);
        $this->assertSame(1, (int) $row->failed_count);
        $this->assertCount(1, $row->failures);
    }

    public function test_a_copy_outliving_its_row_sends_nothing(): void
    {
        Mail::fake();
        Queue::fake();
        $sent = $this->sendTo($this->member());
        $copy = Queue::pushed(DeliverPortalEmail::class)->first();
        EmailMessage::query()->whereKey($sent['id'])->delete();

        $copy->handle(app(PortalEmailMessages::class));

        Mail::assertNothingSent();
    }

    public function test_a_send_the_queue_cannot_take_is_marked_failed_and_refused(): void
    {
        Mail::fake();
        Queue::shouldReceive('bulk')->andThrow(new RuntimeException('jobs table missing'));
        $before = (int) EmailMessage::query()->max('id');

        $this->postJson(self::BASE.'/administration/emails', $this->draft($this->member()), $this->authHeaders())
            ->assertStatus(503)
            ->assertJsonPath('message', 'The email could not be queued. Try again in a moment.');

        $row = EmailMessage::query()->where('id', '>', $before)->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, (int) $row->failed_count);
        $this->assertSame('The email could not be queued for sending.', $row->failures[0]['reason']);
        Mail::assertNothingSent();
    }

    // The sync queue (local runs, tests) cannot hold a job for later, so its first refusal is final.
    public function test_on_the_sync_queue_a_refused_send_settles_inside_the_request(): void
    {
        $member = $this->member();
        $this->refuseMail('550 no such user');

        $this->postJson(self::BASE.'/administration/emails', $this->draft($member), $this->authHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failedCount', 1);
    }

    public function test_a_system_email_is_retried_then_logged_under_its_callers_event(): void
    {
        $job = new SendPortalMail(['someone@example.test'], PortalEmailMessages::testMail(), 'portal.test.failed', ['type' => 'demo']);
        Mail::shouldReceive('to')->andThrow(new RuntimeException('relay down'));
        Log::spy();

        $job->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(60);
        Log::shouldNotHaveReceived('warning');

        $last = (clone $job)->withFakeQueueInteractions();
        $last->job->attempts = 3;
        $last->handle();
        $last->assertNotReleased();
        Log::shouldHaveReceived('warning')->with('portal.test.failed', ['type' => 'demo', 'reason' => 'relay down'])->once();
    }

    public function test_notification_emails_wait_in_the_queue(): void
    {
        Mail::fake();
        Queue::fake();
        config(['products.catalog.portal.notification_emails' => true]);
        DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', PortalAdvancedSettings::NOTIFICATION_EMAILS)
            ->delete();
        $member = $this->member();

        app(PortalNotificationEmails::class)->send($member, PortalNotificationType::PROJECT_APPROVED, 'Project approved', 'Someone approved it.', 'projects', 'Open');

        Mail::assertNothingSent();
        Queue::assertPushed(SendPortalMail::class, fn (SendPortalMail $job): bool => $job->to === [strtolower(trim((string) $member->email))]
            && $job->mail instanceof PortalEmailMail
            && $job->mail->subjectLine === 'Project approved'
            && $job->failureEvent === 'portal.notification.email.failed');
    }

    // The reported ask: the email's Open project button opens that project, as the bell's notice does.
    public function test_a_notice_email_button_opens_the_record_it_is_about(): void
    {
        Mail::fake();
        Queue::fake();
        config(['products.catalog.portal.notification_emails' => true]);
        DB::connection('core')->table('settings')
            ->where('product', 'portal')
            ->where('setting_key', PortalAdvancedSettings::NOTIFICATION_EMAILS)
            ->delete();
        $member = $this->member();

        app(PortalAudit::class)->notify((int) $member->getKey(), PortalNotificationType::PROJECT_APPROVED, 'Project approved', 'Someone approved it.', '/projects/projects', 'Open project', ['recordId' => '42']);

        Queue::assertPushed(SendPortalMail::class, fn (SendPortalMail $job): bool => $job->mail instanceof PortalEmailMail
            && str_contains($job->mail->body, '/projects/projects?notice=projects.approved&record=42)'));
    }

    // The QR is raw PNG bytes, which a JSON queue payload cannot hold; it rides as base64 and is embedded on send.
    public function test_a_letter_with_a_qr_survives_the_database_queue_and_arrives_embedded(): void
    {
        $png = PortalQrPng::png('https://example.test/share/abc');
        $job = new SendPortalMail(['guest@example.test'], new PortalEmailMail(
            'Your link',
            '![QR](cid:share-qr)',
            null,
            PortalEmailMarkup::FORMAT_LETTER,
            PortalEmailMarkup::FORMAT_MARKUP,
            PortalNotificationEmails::FROM_NAME,
            null,
            [],
            [['name' => 'share-qr', 'data' => base64_encode($png), 'mime' => 'image/png']],
        ), 'portal.letter.failed');

        $payload = (fn (): string => $this->createPayload($job, 'default'))->call(Queue::connection('database'));
        $restored = unserialize(json_decode($payload, true, 512, JSON_THROW_ON_ERROR)['data']['command']);
        $this->assertInstanceOf(SendPortalMail::class, $restored);

        config(['mail.default' => 'array']);
        $restored->handle();
        $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last();
        $email = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $inline = array_values(array_filter($email->getAttachments(), static fn (DataPart $part): bool => $part->getFilename() === 'share-qr'));
        $this->assertCount(1, $inline);
        $this->assertSame($png, $inline[0]->getBody());
        $this->assertSame('image/png', $inline[0]->getContentType());
        $this->assertStringContainsString('cid:', (string) $email->getHtmlBody());
    }

    public function test_the_scheduler_starts_a_worker_every_minute_that_outlasts_no_job(): void
    {
        $workers = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'queue:work --stop-when-empty --max-time=55'),
        ));
        $this->assertCount(1, $workers);
        $this->assertSame('* * * * *', $workers[0]->expression);
        $this->assertTrue($workers[0]->runInBackground);

        // A reservation must outlive the slowest send, or a second worker takes it and it goes out twice.
        $timeout = (new SendPortalMail([], PortalEmailMessages::testMail(), 'x'))->timeout;
        $this->assertGreaterThan($timeout, (int) config('queue.connections.database.retry_after'));
    }

    /**
     * @return array{0: Employee, 1: Employee}
     */
    private function twoMembers(): array
    {
        $members = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('email', 'like', '%@%.%')
            ->orderBy('id')
            ->limit(2)
            ->get();
        $this->assertCount(2, $members);

        return [$members[0], $members[1]];
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(Employee $member): array
    {
        return [
            'category' => 'notice',
            'subject' => 'One member',
            'body' => 'x',
            'audience' => 'members',
            'memberIds' => [(int) $member->getKey()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendTo(Employee $member): array
    {
        return $this->postJson(self::BASE.'/administration/emails', $this->draft($member), $this->authHeaders())
            ->assertCreated()
            ->assertJsonPath('data.status', 'sending')
            ->json('data');
    }

    private function refuseMail(string $reason): void
    {
        Mail::shouldReceive('to')->andReturnUsing(function () use ($reason) {
            $pending = Mockery::mock();
            $pending->shouldReceive('cc')->andReturnSelf();
            $pending->shouldReceive('bcc')->andReturnSelf();
            $pending->shouldReceive('send')->andThrow(new RuntimeException($reason));

            return $pending;
        });
    }

    private function row(mixed $id): EmailMessage
    {
        return EmailMessage::query()->findOrFail((int) $id);
    }

    private function emailNotices(): int
    {
        return PortalNotification::query()->where('type', PortalNotificationType::EMAIL_SENT)->count();
    }
}
