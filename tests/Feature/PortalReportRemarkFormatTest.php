<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * The Report remarks card's Polish button: a person's own note tidied into the house template by the
 * model, and refused -- never half-applied -- when the model drifts off it. Nothing is filed here.
 */
class PortalReportRemarkFormatTest extends TestCase
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

    private function format(Employee $actor, string $remark, string $day = '2026-10-17'): TestResponse
    {
        return $this->postJson('/api/development/portal/reports/remarks/format', [
            'remark' => $remark,
            'day' => $day,
        ], $this->headersFor($actor));
    }

    public function test_it_tidies_a_remark_into_the_house_template(): void
    {
        $this->reply("Date: October 17, 2026\nWhat: Fixed the login bug.\nChanged: Users can log in again.");

        $response = $this->format($this->member(), 'fix login bug, users can login again');

        $response->assertOk();
        $this->assertSame(
            "Date: October 17, 2026\nWhat: Fixed the login bug.\nChanged: Users can log in again.",
            $response->json('data.remark'),
        );
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/chat')
            && $request['model'] === 'test-model'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    // Fenced or bolded answers are the same answer; the field names are what we keep.
    public function test_it_keeps_a_fenced_answer_and_normalises_the_field_names(): void
    {
        $this->reply("```\ndate: October 17, 2026\nwhat: **Fixed the login bug.**\n```");

        $response = $this->format($this->member(), 'fix login bug');

        $response->assertOk();
        $this->assertSame("Date: October 17, 2026\nWhat: Fixed the login bug.", $response->json('data.remark'));
    }

    public function test_it_refuses_an_answer_that_changes_the_date(): void
    {
        $this->reply("Date: October 1, 2026\nWhat: Fixed the login bug.");

        $this->format($this->member(), 'fix login bug')->assertStatus(503);
    }

    public function test_it_refuses_a_field_that_is_not_ours(): void
    {
        $this->reply("Date: October 17, 2026\nSummary: Fixed the login bug.");

        $this->format($this->member(), 'fix login bug')->assertStatus(503);
    }

    // A date line with nothing under it means the model dropped the note; better to ask again.
    public function test_it_refuses_a_tidy_that_lost_the_note(): void
    {
        $this->reply('Date: October 17, 2026');

        $this->format($this->member(), 'fix login bug')->assertStatus(503);
    }

    public function test_it_is_off_when_no_key_is_configured(): void
    {
        config(['services.ollama.key' => null]);
        Http::fake();

        $this->format($this->member(), 'fix login bug')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_a_blank_remark_never_reaches_the_model(): void
    {
        Http::fake();

        $this->format($this->member(), '')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_a_too_long_remark_is_refused_before_the_model(): void
    {
        Http::fake();

        $this->format($this->member(), str_repeat('x', 501))->assertStatus(422);
        Http::assertNothingSent();
    }
}
