<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The report forms' saved templates (2026-10-14, user): per user, in the database, read and written
 * only by their owner -- and the composer's own email templates, read with mine=1.
 */
class PortalReportTemplatesTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/reports/templates';

    private const EMAIL_BLOCKS = '/api/development/portal/administration/email-blocks';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function employee(string $where): Employee
    {
        return Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw($where)
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $name): array
    {
        return [
            'name' => $name,
            'notes' => 'Bring the checklist',
            'projects' => [['projectLabel' => 'Cutud', 'activities' => [['activityLabel' => '8001- REVIT MODELING', 'hours' => '8']]]],
        ];
    }

    public function test_templates_are_per_user_and_readable_only_by_their_owner(): void
    {
        $member = $this->employee("LOWER(COALESCE(role, '')) = 'user'");
        $other = $this->employee("LOWER(COALESCE(role, '')) = 'admin' AND LOWER(COALESCE(role_level, '')) != 'executive'");
        $token = $this->token($member);

        $created = $this->withToken($token)->postJson(self::BASE, $this->payload('Field day'))->assertCreated();
        $id = (string) $created->json('data.id');
        $this->assertSame('Field day', $created->json('data.name'));

        // The owner's list carries the recipe as sent (MySQL's JSON column reorders keys, so compare loosely).
        $mine = $this->withToken($token)->getJson(self::BASE)->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertEquals(
            [['projectLabel' => 'Cutud', 'activities' => [['activityLabel' => '8001- REVIT MODELING', 'hours' => '8']]]],
            $mine[0]['projects'],
        );

        // Nobody else sees it, and nobody else may change or remove it.
        $otherToken = $this->token($other);
        $this->assertSame([], $this->withToken($otherToken)->getJson(self::BASE)->assertOk()->json('data'));
        $this->withToken($otherToken)->patchJson(self::BASE.'/'.$id, $this->payload('Stolen'))->assertNotFound();
        $this->withToken($otherToken)->deleteJson(self::BASE.'/'.$id)->assertNotFound();

        // The owner edits and removes it.
        $this->withToken($token)->patchJson(self::BASE.'/'.$id, ['name' => 'Field day 2', 'notes' => null, 'projects' => []])
            ->assertOk()->assertJsonPath('data.name', 'Field day 2');
        $this->withToken($token)->deleteJson(self::BASE.'/'.$id)->assertNoContent();
        $this->assertSame([], $this->withToken($token)->getJson(self::BASE)->json('data'));

        // A malformed body is refused.
        $this->withToken($token)->postJson(self::BASE, ['name' => 'X', 'projects' => [['projectLabel' => 1, 'activities' => []]]])->assertStatus(422);
    }

    public function test_the_composer_reads_its_own_email_templates_only(): void
    {
        // Two administrators: the composer is an administrator's page, and each keeps their own templates.
        $admins = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->limit(2)
            ->get();
        $this->assertCount(2, $admins);
        $memberToken = $this->token($admins[0]);
        $adminToken = $this->token($admins[1]);

        $this->withToken($memberToken)->postJson(self::EMAIL_BLOCKS, [
            'kind' => 'template', 'name' => 'Member template', 'category' => 'notice',
            'subject' => 'Hi', 'body' => 'Body', 'format' => 'markup',
        ])->assertCreated();
        $this->withToken($adminToken)->postJson(self::EMAIL_BLOCKS, [
            'kind' => 'template', 'name' => 'Admin template', 'category' => 'notice',
            'subject' => 'Hi', 'body' => 'Body', 'format' => 'markup',
        ])->assertCreated();

        $names = fn (array $response): array => array_column($response, 'name');
        $mine = $names($this->withToken($memberToken)->getJson(self::EMAIL_BLOCKS.'?kind=template&mine=1')->assertOk()->json('data'));
        $this->assertContains('Member template', $mine);
        $this->assertNotContains('Admin template', $mine);

        // The library page keeps reading every block.
        $all = $names($this->withToken($memberToken)->getJson(self::EMAIL_BLOCKS.'?kind=template')->assertOk()->json('data'));
        $this->assertContains('Admin template', $all);
    }
}
