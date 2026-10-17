<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * The "Polish" tidy-up every remarks box shares: a report's note becomes the labelled work log, a
 * leave or reimbursement note becomes plain prose. It is refused -- never half-applied -- when the
 * model drifts off what that box asks for, and a line the note could not support comes back as [Blank]
 * so the person sees what is missing. Nothing is filed here.
 */
class PortalRemarkFormatTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.ollama.key' => 'test-key',
            'services.ollama.host' => 'https://ollama.test',
            'services.ollama.model' => 'test-model',
        ]);
    }

    private function reply(string $content): void
    {
        Http::fake(['ollama.test/*' => Http::response(['message' => ['content' => $content]])]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function format(Employee $actor, array $payload): TestResponse
    {
        return $this->postJson('/api/development/portal/remarks/format', $payload, $this->headersFor($actor));
    }

    private function log(Employee $actor, string $context, string $remark, string $day = '2026-10-17'): TestResponse
    {
        return $this->format($actor, ['context' => $context, 'remark' => $remark, 'day' => $day]);
    }

    public function test_it_tidies_a_report_note_into_the_labelled_log(): void
    {
        $this->reply("Date: October 17, 2026\nWhat: Fixed the login bug.\nChanged: Users can log in again.\nWins: The login bug is gone.");

        $response = $this->log($this->member(), 'report.daily', 'fix login bug, users can log in again');

        $response->assertOk();
        $this->assertSame(
            "Date: October 17, 2026\nWhat: Fixed the login bug.\nChanged: Users can log in again.\nWins: The login bug is gone.",
            $response->json('data.remark'),
        );
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/chat')
            && $request['model'] === 'test-model'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && str_contains((string) $request['messages'][1]['content'], 'Date: October 17, 2026'));
    }

    // A report without Wins is not the log: the win is worded from the work, and the checker keeps the order.
    public function test_a_log_always_carries_what_and_wins_in_the_house_order(): void
    {
        $this->reply("Wins: The portal is easier to use.\nWhat: Created the Polish button.\nChanged: Everyone writes better notes.");

        $response = $this->log($this->member(), 'report.daily', 'created the polish button');

        $response->assertOk();
        $this->assertSame(
            "Date: October 17, 2026\nWhat: Created the Polish button.\nChanged: Everyone writes better notes.\nWins: The portal is easier to use.",
            $response->json('data.remark'),
        );
    }

    public function test_a_late_report_also_carries_a_reason(): void
    {
        $this->reply("What: Filed the daily report.\nWins: The week is complete.\nReason: The power was out on Friday.");

        $response = $this->log($this->member(), 'report.late', 'filed late, power was out');

        $response->assertOk();
        $this->assertSame(
            "Date: October 17, 2026\nWhat: Filed the daily report.\nWins: The week is complete.\nReason: The power was out on Friday.",
            $response->json('data.remark'),
        );
    }

    // The marker is our word, never the model's: an unsaid line comes back as [Blank] so the person fills it.
    public function test_a_line_the_note_could_not_support_comes_back_blank(): void
    {
        $this->reply('Reason: I was on leave and filed the next morning.');

        $response = $this->log($this->member(), 'report.late', 'filed late, i was on leave');

        $response->assertOk();
        $this->assertSame(
            "Date: October 17, 2026\nWhat: [Blank]\nWins: [Blank]\nReason: I was on leave and filed the next morning.",
            $response->json('data.remark'),
        );
    }

    public function test_a_late_report_with_no_reason_says_so(): void
    {
        $this->reply("What: Checked the site drawings.\nWins: Two issues were caught early.");

        $response = $this->log($this->member(), 'report.late', 'checked the drawings');

        $response->assertOk();
        $this->assertSame(
            "Date: October 17, 2026\nWhat: Checked the site drawings.\nWins: Two issues were caught early.\nReason: [Blank]",
            $response->json('data.remark'),
        );
    }

    // The daily log has no reason line at all, so one coming back means the model wrote its own shape.
    public function test_the_daily_log_refuses_a_reason_line(): void
    {
        $this->reply("What: Checked the invoices.\nReason: No reason given.\nWins: Billing is current.");

        $this->log($this->member(), 'report.daily', 'checked the invoices')->assertStatus(503);
    }

    // Fenced or bolded answers are the same answer; the field names are what we keep.
    public function test_it_keeps_a_fenced_answer_and_normalises_the_field_names(): void
    {
        $this->reply("```\ndate: October 17, 2026\nwhat: **Fixed the login bug.**\nwins: The login bug is gone.\n```");

        $this->log($this->member(), 'report.daily', 'fix login bug')
            ->assertOk()
            ->assertJsonPath('data.remark', "Date: October 17, 2026\nWhat: Fixed the login bug.\nWins: The login bug is gone.");
    }

    // The Date line is the form's own day, so the model cannot change or invent it.
    public function test_the_date_is_the_forms_own_day(): void
    {
        $this->reply("Date: October 1, 2026\nWhat: Fixed the login bug.\nWins: The login bug is gone.");

        $this->log($this->member(), 'report.daily', 'fix login bug')
            ->assertOk()
            ->assertJsonPath('data.remark', "Date: October 17, 2026\nWhat: Fixed the login bug.\nWins: The login bug is gone.");
    }

    public function test_it_refuses_a_field_that_is_not_one_of_ours(): void
    {
        $this->reply("Date: October 17, 2026\nSummary: Fixed the login bug.\nWins: It is gone.");

        $this->log($this->member(), 'report.daily', 'fix login bug')->assertStatus(503);
    }

    // A date line with nothing under it means the model dropped the note; better to leave the box as typed.
    public function test_it_refuses_a_tidy_that_lost_the_note(): void
    {
        $this->reply('Date: October 17, 2026');

        $this->log($this->member(), 'report.daily', 'fix login bug')->assertStatus(503);
    }

    public function test_a_leave_note_comes_back_as_plain_prose_with_no_labels(): void
    {
        $this->reply('I was sick yesterday and could not file my report on time.');

        $this->format($this->member(), ['context' => 'request.leave', 'remark' => 'i was sick yesterday so i couldnt file on time'])
            ->assertOk()
            ->assertJsonPath('data.remark', 'I was sick yesterday and could not file my report on time.');
    }

    public function test_a_prose_answer_that_uses_a_field_name_is_refused(): void
    {
        $this->reply("Reason: I was sick yesterday.\nWins: None.");

        $this->format($this->member(), ['context' => 'request.leave', 'remark' => 'i was sick'])
            ->assertStatus(503);
    }

    public function test_a_reimbursement_purpose_over_its_box_is_refused(): void
    {
        $this->reply(str_repeat('Client meeting in Cebu. ', 12));

        $this->format($this->member(), ['context' => 'request.reimbursement', 'remark' => 'taxi to the client'])
            ->assertStatus(503);
    }

    public function test_it_is_off_when_no_key_is_configured(): void
    {
        config(['services.ollama.key' => null]);
        Http::fake();

        $this->log($this->member(), 'report.daily', 'fix login bug')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_a_blank_note_never_reaches_the_model(): void
    {
        Http::fake();

        $this->log($this->member(), 'report.daily', '')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_too_long_note_is_refused_before_the_model(): void
    {
        Http::fake();

        $this->log($this->member(), 'report.daily', str_repeat('x', 501))->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_log_needs_its_day(): void
    {
        Http::fake();

        $this->format($this->member(), ['context' => 'report.daily', 'remark' => 'fix login bug'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_prose_needs_no_day(): void
    {
        $this->reply('I was sick yesterday and could not file my report on time.');

        $this->format($this->member(), ['context' => 'request.leave', 'remark' => 'i was sick yesterday'])
            ->assertOk()
            ->assertJsonPath('data.remark', 'I was sick yesterday and could not file my report on time.');
    }

    public function test_an_unknown_box_is_refused(): void
    {
        Http::fake();

        $this->format($this->member(), ['context' => 'report.something', 'remark' => 'x'])->assertStatus(422);
        Http::assertNothingSent();
    }

    private function replyMany(array $items): void
    {
        Http::fake(['ollama.test/*' => Http::response(['message' => ['content' => json_encode($items)]])]);
    }

    private function formatMany(Employee $actor, array $remarks, string $context = 'approval.remark'): TestResponse
    {
        return $this->postJson('/api/development/portal/remarks/format-many', [
            'context' => $context,
            'remarks' => array_values($remarks),
        ], $this->headersFor($actor));
    }

    public function test_a_batch_of_notes_comes_back_tidied_in_the_same_order(): void
    {
        $this->replyMany(['The reimbursement was approved.', 'No receipt.', 'Taxi to the client.']);

        $response = $this->formatMany($this->member(), ['approved', 'no reciept', 'taxi to client']);

        $response->assertOk();
        $this->assertSame(
            ['The reimbursement was approved.', 'No receipt.', 'Taxi to the client.'],
            $response->json('data.remarks'),
        );
        // The schema is what keeps a batch well-formed: the count and each note's length are the model's
        // constraint, not something checked after the fact.
        Http::assertSent(function ($request): bool {
            $format = $request['format'] ?? null;

            return is_array($format)
                && ($format['minItems'] ?? null) === 3
                && ($format['maxItems'] ?? null) === 3
                && ($format['items']['maxLength'] ?? null) === 120;
        });
    }

    // A quote inside a note is content, not a syntax error: it comes back escaped, never "fixed".
    public function test_a_quote_inside_a_note_survives(): void
    {
        $this->replyMany(['The receipt says "SM Makati", but the amount is wrong.']);

        $this->formatMany($this->member(), ['receipt says "SM Makati" but the amount is wrong'])
            ->assertOk()
            ->assertJsonPath('data.remarks', ['The receipt says "SM Makati", but the amount is wrong.']);
    }

    // One note that broke a rule is dropped to null, so its row keeps what the person typed.
    public function test_a_note_that_breaks_a_rule_comes_back_null_and_the_rest_still_land(): void
    {
        $this->replyMany(['No receipt.', 'Reason: the receipt was lost.', str_repeat('x', 121)]);

        $this->formatMany($this->member(), ['no reciept', 'the receipt was lost', 'long one'])
            ->assertOk()
            ->assertJsonPath('data.remarks', ['No receipt.', null, null]);
    }

    // A batch that lost an item cannot be mapped back to its rows, so nothing is applied at all.
    public function test_a_batch_with_the_wrong_count_is_refused(): void
    {
        $this->replyMany(['Only one note came back.']);

        $this->formatMany($this->member(), ['one', 'two', 'three'])->assertStatus(503);
    }

    public function test_a_batch_that_is_not_json_is_refused(): void
    {
        Http::fake(['ollama.test/*' => Http::response(['message' => ['content' => 'Here are your notes: First. Second.']])]);

        $this->formatMany($this->member(), ['one', 'two'])->assertStatus(503);
    }

    public function test_a_batch_of_logs_is_refused(): void
    {
        Http::fake();

        $this->formatMany($this->member(), ['fix login bug'], 'report.daily')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_batch_over_ten_rows_is_refused_before_the_model(): void
    {
        Http::fake();

        $this->formatMany($this->member(), array_fill(0, 11, 'note'))->assertStatus(422);
        Http::assertNothingSent();
    }
}
