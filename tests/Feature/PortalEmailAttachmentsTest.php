<?php

namespace Tests\Feature;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;
use ZipArchive;

/**
 * Send Email's attachments: staged on the private disk as they are attached, claimed by one send,
 * mailed with every copy, and handed back from All Emails.
 *
 * Files are built for real and handed over as real uploads, so the type check reads their content as
 * the server would -- a fake upload reports whatever its name suggests.
 */
class PortalEmailAttachmentsTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const BASE = '/api/development/portal';

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private const PROGRAM = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00";

    /**
     * @var list<string>
     */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    // The reported ask: an email that carries a file.
    public function test_an_attached_file_is_staged_privately_and_goes_out_with_the_email(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $member = $this->member();

        $staged = $this->stage($this->upload('Quote.pdf', self::PDF), $headers)
            ->assertCreated()
            ->assertJsonPath('resource', 'email-attachments')
            ->assertJsonPath('data.name', 'Quote.pdf')
            ->assertJsonPath('data.mimeType', 'application/pdf')
            ->assertJsonPath('data.sizeBytes', strlen(self::PDF))
            ->json('data');
        $row = $this->row($staged['id']);
        $this->assertNull($row->message_id);
        $this->assertSame((int) $this->administrator()->getKey(), (int) $row->uploaded_by);
        Storage::disk('local')->assertExists($row->path);
        $this->assertStringStartsWith('email-attachments/', $row->path);

        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'The quotation',
            'body' => 'Attached.',
            'audience' => 'members',
            'memberIds' => [(string) $member->getKey()],
            'attachmentIds' => [$staged['id']],
        ], $headers)
            ->assertCreated()
            ->assertJsonPath('data.attachmentCount', 1)
            ->assertJsonPath('data.attachments.0.id', $staged['id'])
            ->assertJsonPath('data.attachments.0.name', 'Quote.pdf')
            ->json('data');

        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => $mail->hasTo(trim((string) $member->email))
            && $mail->hasAttachment(Attachment::fromStorageDisk('local', $row->path)->as('Quote.pdf')->withMime('application/pdf')));
        $this->assertSame((int) $sent['id'], (int) $this->row($staged['id'])->message_id);

        // The ledger counts the files and their size, and never names them.
        $log = PortalLog::query()->where('resource', 'administration.email')->where('action', 'POST')->where('record_id', (string) $sent['id'])->firstOrFail();
        $this->assertSame(1, $log->payload['attachments']);
        $this->assertSame(strlen(self::PDF), $log->payload['attachmentBytes']);
        $this->assertStringNotContainsString('Quote', (string) json_encode($log->payload));

        // All Emails: the list counts it, and the sent email names it.
        $listed = collect($this->getJson(self::BASE.'/administration/emails?per_page=100', $headers)->assertOk()->json('data'))
            ->firstWhere('id', (string) $sent['id']);
        $this->assertSame(1, $listed['attachmentCount']);
        $this->getJson(self::BASE.'/administration/emails/'.$sent['id'], $headers)
            ->assertOk()
            ->assertJsonPath('data.attachments', [[
                'id' => $staged['id'],
                'name' => 'Quote.pdf',
                'mimeType' => 'application/pdf',
                'sizeBytes' => strlen(self::PDF),
            ]]);
    }

    // Every copy carries every file: each member's own, and the CC and BCC copy.
    public function test_every_copy_of_a_send_carries_the_files(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $member = $this->member();
        $first = $this->stage($this->upload('Plan.docx', $this->ooxml('word/document.xml')), $headers)
            ->assertCreated()
            ->assertJsonPath('data.mimeType', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->json('data.id');
        $second = $this->stage($this->upload('Site.png', $this->png()), $headers)->assertCreated()->json('data.id');

        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Plans',
            'body' => 'Two files.',
            'audience' => 'members',
            'memberIds' => [(string) $member->getKey()],
            'cc' => ['boss@ikaika.test'],
            'attachmentIds' => [$first, $second],
        ], $headers)->assertCreated()->assertJsonPath('data.attachmentCount', 2);

        Mail::assertSent(PortalEmailMail::class, 2);
        Mail::assertSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => count($mail->attachments()) === 2
            && $mail->attachedFiles[0]['name'] === 'Plan.docx'
            && $mail->attachedFiles[1]['name'] === 'Site.png');
        Mail::assertNotSent(PortalEmailMail::class, fn (PortalEmailMail $mail): bool => count($mail->attachments()) !== 2);
    }

    public function test_a_disguised_program_and_types_the_portal_does_not_send_are_refused(): void
    {
        $headers = $this->authHeaders();
        $before = $this->stagedCount();

        $this->stage($this->upload('invoice.pdf', self::PROGRAM), $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'invoice.pdf does not look like the PDF file its name says it is.');
        $this->stage($this->upload('setup.exe', self::PROGRAM), $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'setup.exe is not a type the portal sends. Attach a PDF, Word, Excel, PowerPoint, CSV, text, image or ZIP file.');
        $this->stage($this->upload('page.html', '<!DOCTYPE html><html><script>alert(1)</script></html>'), $headers)->assertStatus(422);
        $this->stage($this->upload('no-extension', self::PDF), $headers)->assertStatus(422);
        // A sheet saved as CSV reads as text, and passes as the CSV it is.
        $this->stage($this->upload('Costs.csv', "item,amount\nTape,45\n"), $headers)
            ->assertCreated()
            ->assertJsonPath('data.mimeType', 'text/csv');

        $this->assertSame($before + 1, $this->stagedCount());
    }

    public function test_a_file_past_a_limit_is_refused_and_says_which(): void
    {
        $headers = $this->authHeaders();

        $this->stage(UploadedFile::fake()->create('Huge.pdf', 10 * 1024 + 1, 'application/pdf'), $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'A file can be up to 10 MB: every recipient gets a copy, so the whole email is held to that.');
        // Past upload_max_filesize, PHP hands over an error rather than the file.
        $tooLarge = new UploadedFile($this->temp(self::PDF), 'Big.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);
        $this->assertStringStartsWith(
            'That file is larger than this server accepts: up to ',
            (string) $this->stage($tooLarge, $headers)->assertStatus(422)->json('message'),
        );
        $this->stage($this->upload('Empty.pdf', ''), $headers)->assertStatus(422)->assertJsonPath('message', 'That file is empty.');
        $this->post(self::BASE.'/administration/email-attachments', [], $headers + ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose a file to attach.');
    }

    public function test_a_file_name_is_kept_to_one_safe_name(): void
    {
        $headers = $this->authHeaders();

        $this->stage($this->upload('..\\..\\Final <v2>: "quote"?.pdf', self::PDF), $headers)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Final v2 quote.pdf');
        $long = $this->stage($this->upload(str_repeat('a', 300).'.pdf', self::PDF), $headers)->assertCreated()->json('data.name');
        $this->assertSame(191, mb_strlen($long));
        $this->assertStringEndsWith('.pdf', $long);
        $this->stage($this->upload('.pdf', self::PDF), $headers)->assertCreated()->assertJsonPath('data.name', 'attachment.pdf');
    }

    // A send claims only its sender's own files, each once.
    public function test_a_send_takes_only_its_senders_files_that_are_not_yet_sent(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $member = $this->member();
        $draft = [
            'category' => 'notice',
            'subject' => 'Files',
            'body' => 'x',
            'audience' => 'members',
            'memberIds' => [(string) $member->getKey()],
        ];
        $someoneElses = $this->seedStaged($member, 'Theirs.pdf', 100);
        $missing = (string) Str::uuid();

        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'attachmentIds' => [$someoneElses]], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'An attachment is no longer available. Remove it and attach it again.');
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'attachmentIds' => [$missing]], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'attachmentIds' => ['../../etc/passwd']], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'One of the attachments is not one you attached.');
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'attachmentIds' => 'not-a-list'], $headers)->assertStatus(422);
        $this->postJson(self::BASE.'/administration/emails', [
            ...$draft,
            'attachmentIds' => array_map(static fn (): string => (string) Str::uuid(), range(1, 11)),
        ], $headers)->assertStatus(422)->assertJsonPath('message', 'An email can carry up to 10 attachments.');
        $big = [$this->seedStaged($this->administrator(), 'A.pdf', 6 * 1024 * 1024), $this->seedStaged($this->administrator(), 'B.pdf', 6 * 1024 * 1024)];
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'attachmentIds' => $big], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'The attachments come to more than 10 MB. Remove some to send.');
        Mail::assertNothingSent();

        // Sent once, a file is the outbox's: a second send cannot take it again.
        $mine = $this->stage($this->upload('Mine.pdf', self::PDF), $headers)->json('data.id');
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'attachmentIds' => [$mine]], $headers)->assertCreated();
        $this->postJson(self::BASE.'/administration/emails', [...$draft, 'attachmentIds' => [$mine]], $headers)->assertStatus(422);
        Mail::assertSent(PortalEmailMail::class, 1);
    }

    public function test_a_removed_file_is_taken_back_and_only_its_stager_can_remove_it(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $staged = $this->stage($this->upload('Draft.pdf', self::PDF), $headers)->json('data.id');
        $path = $this->row($staged)->path;

        $this->deleteJson(self::BASE.'/administration/email-attachments/'.$staged, [], $headers)->assertNoContent();
        $this->assertNull(DB::connection('portal')->table('email_attachments')->where('token', $staged)->first());
        Storage::disk('local')->assertMissing($path);
        $this->deleteJson(self::BASE.'/administration/email-attachments/'.$staged, [], $headers)->assertNotFound();

        $theirs = $this->seedStaged($this->member(), 'Theirs.pdf', 10);
        $this->deleteJson(self::BASE.'/administration/email-attachments/'.$theirs, [], $headers)->assertNotFound();
        $this->assertNotNull($this->row($theirs));

        // Once sent, it belongs to the outbox and stays.
        $sentFile = $this->stage($this->upload('Sent.pdf', self::PDF), $headers)->json('data.id');
        $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Sent',
            'body' => 'x',
            'audience' => 'members',
            'memberIds' => [(string) $this->member()->getKey()],
            'attachmentIds' => [$sentFile],
        ], $headers)->assertCreated();
        $this->deleteJson(self::BASE.'/administration/email-attachments/'.$sentFile, [], $headers)->assertNotFound();
        Storage::disk('local')->assertExists($this->row($sentFile)->path);
    }

    public function test_all_emails_hands_back_a_sent_file_and_logs_who_read_it(): void
    {
        Mail::fake();
        $headers = $this->authHeaders();
        $staged = $this->stage($this->upload('Quote.pdf', self::PDF), $headers)->json('data.id');
        $sent = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'The quotation',
            'body' => 'Attached.',
            'audience' => 'members',
            'memberIds' => [(string) $this->member()->getKey()],
            'attachmentIds' => [$staged],
        ], $headers)->assertCreated()->json('data');
        $other = $this->postJson(self::BASE.'/administration/emails', [
            'category' => 'notice',
            'subject' => 'Another',
            'body' => 'x',
            'audience' => 'members',
            'memberIds' => [(string) $this->member()->getKey()],
        ], $headers)->assertCreated()->json('data');

        $response = $this->get(self::BASE.'/administration/emails/'.$sent['id'].'/attachments/'.$staged, $headers)->assertOk();
        $this->assertSame(self::PDF, $response->streamedContent());
        $this->assertStringContainsString('attachment; filename=Quote.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $log = PortalLog::query()->where('resource', 'administration.email')->where('action', 'VIEW')->where('record_id', (string) $sent['id'])->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringEndsWith('downloaded Quote.pdf from the email "The quotation"', (string) $log->message);

        // A file opens only from the email that carried it.
        $this->get(self::BASE.'/administration/emails/'.$other['id'].'/attachments/'.$staged, $headers + ['Accept' => 'application/json'])->assertNotFound();
        $this->get(self::BASE.'/administration/emails/'.$sent['id'].'/attachments/not-a-token', $headers + ['Accept' => 'application/json'])->assertNotFound();
        $this->get(self::BASE.'/administration/emails/'.$sent['id'].'/attachments/'.$staged, $this->headersFor($this->member()) + ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_member_cannot_attach(): void
    {
        $this->stage($this->upload('Quote.pdf', self::PDF), $this->headersFor($this->member()))->assertForbidden();
    }

    public function test_files_never_sent_are_cleared_after_a_day_and_sent_ones_stay(): void
    {
        $headers = $this->authHeaders();
        $admin = $this->administrator();
        $abandoned = $this->seedStaged($admin, 'Old.pdf', 10, now()->subDays(2));
        $fresh = $this->seedStaged($admin, 'Fresh.pdf', 10);
        $kept = $this->seedStaged($admin, 'Kept.pdf', 10, now()->subDays(2));
        DB::connection('portal')->table('email_attachments')->where('token', $kept)->update(['message_id' => $this->seedMessage($admin)]);
        $abandonedPath = $this->row($abandoned)->path;

        Artisan::call('portal:prune-email-attachments');

        $this->assertNull($this->row($abandoned));
        Storage::disk('local')->assertMissing($abandonedPath);
        $this->assertNotNull($this->row($fresh));
        $this->assertNotNull($this->row($kept));
        Storage::disk('local')->assertExists($this->row($kept)->path);

        // Attaching clears the abandoned too, so no scheduler is needed for it to happen.
        $another = $this->seedStaged($admin, 'Older.pdf', 10, now()->subDays(3));
        $this->stage($this->upload('New.pdf', self::PDF), $headers)->assertCreated();
        $this->assertNull($this->row($another));
    }

    private function stage(UploadedFile $file, array $headers): TestResponse
    {
        return $this->post(self::BASE.'/administration/email-attachments', ['file' => $file], $headers + ['Accept' => 'application/json']);
    }

    // A real file under the name given, so its type is read from what is in it.
    private function upload(string $name, string $content): UploadedFile
    {
        return new UploadedFile($this->temp($content), $name, null, null, true);
    }

    private function temp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'attach-');
        file_put_contents($path, $content);
        $this->temporary[] = $path;

        return $path;
    }

    private function ooxml(string $part): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ooxml-').'.zip';
        $this->temporary[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/'.$part.'" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->addFromString($part, '<?xml version="1.0"?><root/>');
        $zip->close();

        return (string) file_get_contents($path);
    }

    private function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    private function seedStaged(Employee $owner, string $name, int $size, mixed $at = null): string
    {
        $token = (string) Str::uuid();
        $path = 'email-attachments/'.$token;
        Storage::disk('local')->put($path, 'x');
        DB::connection('portal')->table('email_attachments')->insert([
            'token' => $token,
            'message_id' => null,
            'name' => $name,
            'mime' => 'application/pdf',
            'size_bytes' => $size,
            'path' => $path,
            'uploaded_by' => $owner->getKey(),
            'date_created' => ($at ?? now())->format('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    private function seedMessage(Employee $sender): int
    {
        return (int) DB::connection('portal')->table('email_messages')->insertGetId([
            'category' => 'notice',
            'subject' => 'Seeded',
            'body' => 'x',
            'audience' => 'everyone',
            'recipient_count' => 1,
            'sent_count' => 1,
            'status' => 'sent',
            'sent_by' => $sender->getKey(),
            'date_created' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function row(string $token): ?object
    {
        return DB::connection('portal')->table('email_attachments')->where('token', $token)->first();
    }

    private function stagedCount(): int
    {
        return DB::connection('portal')->table('email_attachments')
            ->where('uploaded_by', $this->administrator()->getKey())
            ->whereNull('message_id')
            ->count();
    }
}
