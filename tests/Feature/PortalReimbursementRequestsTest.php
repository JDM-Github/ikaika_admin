<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Action;
use App\Modules\Portal\Models\Employee;
use App\Support\Core\CoreActionType;
use App\Support\Portal\PortalSubmittedReportPresenter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalReimbursementRequestsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_catalog_lists_reimbursement_under_portal_sections(): void
    {
        $sections = $this->getJson('/api/development/portal')
            ->assertOk()
            ->json('sections');
        $this->assertIsArray($sections);

        $requests = collect($sections)->firstWhere('name', 'requests');
        $this->assertIsArray($requests);
        $this->assertFalse($requests['requires_admin']);

        $resource = collect($requests['resources'] ?? [])->firstWhere('name', 'reimbursement');
        $this->assertIsArray($resource);
        $this->assertSame(
            '/api/development/portal/requests/reimbursement',
            $resource['url'] ?? null,
        );
        $this->assertFalse($resource['requires_admin'] ?? true);
    }

    public function test_a_claim_writes_one_row_per_item_under_the_member(): void
    {
        $actor = $this->activeMember();
        $date = '2025-12-15';
        $token = $this->loginToken($actor);

        $response = $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement', [
            'requestDate' => $date,
            'items' => [
                $this->item('Office Grocery', 2095.6, 1, 'Office grocery and maintenance'),
                $this->item('Water Refill', 40, 4, 'Water refill'),
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('section', 'requests')
            ->assertJsonPath('resource', 'reimbursement')
            ->assertJsonPath('data.submittedOn', $date)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.label', 'Office Grocery')
            ->assertJsonPath('data.items.1.quantity', 4);

        $ids = array_map('intval', $response->json('data.items.*.id'));
        $this->assertCount(2, $ids);

        $rows = DB::connection('portal')->table('reimbursements')->whereIn('id', $ids)->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame($date, substr((string) $row->reimb_date, 0, 10));
            $this->assertSame('Pending', $row->status);
            $this->assertSame('Angeles Pampanga Office', $row->team);
            $this->assertSame($this->memberName($actor), $row->employee_name_input);
            $this->assertTrue(DB::connection('portal')->table('employees_reimbursements')
                ->where('employee_id', $actor->getKey())
                ->where('reimbursement_id', $row->id)
                ->exists());
        }

        $this->assertTrue(Action::query()
            ->where('resource', 'requests.reimbursement')
            ->where('record_id', $response->json('data.id'))
            ->where('action_type', CoreActionType::ADD)
            ->exists());
    }

    public function test_a_past_date_is_allowed_because_reimbursement_records_spending(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $yesterday = Carbon::today()->subDay()->toDateString();

        $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement', [
            'requestDate' => $yesterday,
            'items' => [$this->item('Snacks', 120, 1, 'Overtime dinner')],
        ])
            ->assertCreated()
            ->assertJsonPath('data.submittedOn', $yesterday);
    }

    public function test_the_list_returns_the_members_own_claims_and_the_office_vocabulary(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $date = Carbon::today()->subDays(3)->toDateString();
        $mine = $this->insertClaim($actor, $date, 'Pending', [
            ['item' => 'Key duplicates', 'cost' => 225, 'qty' => 1],
        ]);
        $this->insertClaim($other, $date, 'Pending', [
            ['item' => 'Someone else', 'cost' => 10, 'qty' => 1],
        ]);
        Cache::flush();
        $token = $this->loginToken($actor);

        $list = $this->withToken($token)->getJson(
            '/api/development/portal/requests/reimbursement?from='.$date.'&to='.$date,
        )->assertOk();

        $this->assertSame([$mine], $list->json('data.*.id'));
        $this->assertSame('Key duplicates', $list->json('data.0.items.0.label'));
        $this->assertSame(
            ['Angeles Pampanga Office', 'Cebu Office'],
            $list->json('teams'),
        );
        $this->assertSame($this->memberName($actor), $list->json('data.0.memberName'));
    }

    public function test_items_filed_together_read_back_as_one_claim(): void
    {
        $actor = $this->activeMember();
        $date = Carbon::today()->subDays(2)->toDateString();
        $stamp = $date.' 09:15:00';
        $first = $this->insertItem($actor, $date, 'Pending', 'Paper Towel', 178, 1, $stamp);
        $second = $this->insertItem($actor, $date, 'Pending', 'Toilet Paper', 416, 1, $stamp);
        Cache::flush();
        $token = $this->loginToken($actor);

        $list = $this->withToken($token)->getJson(
            '/api/development/portal/requests/reimbursement?from='.$date.'&to='.$date,
        )->assertOk();

        $this->assertCount(1, $list->json('data'));
        $this->assertSame((string) min($first, $second), $list->json('data.0.id'));
        $this->assertCount(2, $list->json('data.0.items'));
    }

    public function test_a_completed_claim_reads_back_as_approved(): void
    {
        $actor = $this->activeMember();
        $date = Carbon::today()->subDays(5)->toDateString();
        $this->insertClaim($actor, $date, 'Completed', [
            ['item' => 'Water Refill', 'cost' => 40, 'qty' => 4],
        ]);
        Cache::flush();
        $token = $this->loginToken($actor);

        $this->withToken($token)->getJson(
            '/api/development/portal/requests/reimbursement?from='.$date.'&to='.$date,
        )
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved');
    }

    public function test_the_office_the_cost_and_the_items_are_all_checked(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);
        $date = Carbon::today()->toDateString();

        $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement', [
            'requestDate' => $date,
            'items' => [],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Add at least one item before filing.');

        $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement', [
            'requestDate' => $date,
            'items' => [$this->item('Snacks', 120, 1, null, 'Structural')],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose one of the offices the form offers.');

        $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement', [
            'requestDate' => $date,
            'items' => [$this->item('Snacks', 0, 1)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Every reimbursement needs a cost above zero.');

        $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement', [
            'requestDate' => $date,
            'items' => [$this->item('Snacks', 120, 1.5)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Quantity has to be a whole number above zero.');
    }

    public function test_reimbursement_requires_authentication(): void
    {
        $this->getJson('/api/development/portal/requests/reimbursement')->assertStatus(401);
        $this->postJson('/api/development/portal/requests/reimbursement', [])->assertStatus(401);
        $this->post('/api/development/portal/requests/reimbursement/receipts')->assertStatus(401);
        $this->patchJson('/api/development/portal/requests/reimbursement/1', [])->assertStatus(401);
        $this->postJson('/api/development/portal/requests/reimbursement/1/cancel')->assertStatus(401);
    }

    public function test_a_pending_claim_can_be_edited_and_a_decided_one_cannot(): void
    {
        $actor = $this->activeMember();
        $date = Carbon::today()->subDays(4)->toDateString();
        $moved = Carbon::today()->subDays(3)->toDateString();
        $id = $this->insertClaim($actor, $date, 'Pending', [
            ['item' => 'Paper Towel', 'cost' => 178, 'qty' => 1],
            ['item' => 'Toilet Paper', 'cost' => 416, 'qty' => 1],
        ]);
        Cache::flush();
        $token = $this->loginToken($actor);

        $this->withToken($token)->patchJson('/api/development/portal/requests/reimbursement/'.$id, [
            'requestDate' => $moved,
            'items' => [
                $this->item('Office Grocery', 2095.6, 1, 'Office grocery and maintenance'),
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.submittedOn', $moved)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.label', 'Office Grocery');

        $this->assertSame('Office Grocery', DB::connection('portal')->table('reimbursements')
            ->where('id', (int) $id)
            ->value('item'));
        $this->assertTrue(Action::query()
            ->where('resource', 'requests.reimbursement')
            ->where('record_id', $id)
            ->where('action_type', CoreActionType::EDIT)
            ->exists());

        DB::connection('portal')->table('reimbursements')->where('id', (int) $id)->update(['status' => 'Completed']);
        Cache::flush();

        $this->withToken($token)->patchJson('/api/development/portal/requests/reimbursement/'.$id, [
            'requestDate' => $moved,
            'items' => [$this->item('Office Grocery', 2095.6, 1)],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a request nobody has decided on yet can be changed.');
    }

    public function test_a_pending_claim_can_be_cancelled(): void
    {
        $actor = $this->activeMember();
        $date = Carbon::today()->subDays(6)->toDateString();
        $id = $this->insertClaim($actor, $date, 'Pending', [
            ['item' => 'Key duplicates', 'cost' => 225, 'qty' => 1],
            ['item' => 'Water Refill', 'cost' => 40, 'qty' => 4],
        ]);
        Cache::flush();
        $token = $this->loginToken($actor);

        $cancelled = $this->withToken($token)
            ->postJson('/api/development/portal/requests/reimbursement/'.$id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonCount(2, 'data.items');

        foreach (array_map('intval', $cancelled->json('data.items.*.id')) as $itemId) {
            $this->assertSame('Cancelled', DB::connection('portal')->table('reimbursements')
                ->where('id', $itemId)
                ->value('status'));
        }
    }

    public function test_somebody_elses_claim_is_not_found_rather_than_forbidden(): void
    {
        $actor = $this->activeMember();
        $other = $this->otherActiveMember($actor);
        $date = Carbon::today()->subDays(2)->toDateString();
        $id = $this->insertClaim($other, $date, 'Pending', [
            ['item' => 'Someone else', 'cost' => 10, 'qty' => 1],
        ]);
        $token = $this->loginToken($actor);

        $this->withToken($token)
            ->postJson('/api/development/portal/requests/reimbursement/'.$id.'/cancel')
            ->assertStatus(404);
        $this->withToken($token)->patchJson('/api/development/portal/requests/reimbursement/'.$id, [
            'requestDate' => $date,
            'items' => [$this->item('Office Grocery', 2095.6, 1)],
        ])->assertStatus(404);
    }

    public function test_a_receipt_uploads_then_lands_on_the_filed_item(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.cloudinary.com/v1_1/test-cloud/auto/upload' => Http::response([
                'secure_url' => 'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/reimbursements/grab.jpg',
                'original_filename' => 'grab',
                'bytes' => 1200,
            ], 200),
        ]);

        $actor = $this->activeMember();
        $date = Carbon::today()->toDateString();
        $token = $this->loginToken($actor);
        $file = UploadedFile::fake()->create('grab.jpg', 12, 'image/jpeg');

        $uploaded = $this->withToken($token)->post(
            '/api/development/portal/requests/reimbursement/receipts',
            ['file' => $file],
        )->assertCreated();

        $receiptId = $uploaded->json('data.receiptId');
        $this->assertIsString($receiptId);
        $this->assertSame('grab.jpg', $uploaded->json('data.fileName'));
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/reimbursements/grab.jpg',
            $uploaded->json('data.fileUrl'),
        );
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/c_fill,h_96,w_96,f_auto,q_auto/v1/ikaika-portal/reimbursements/grab.jpg',
            $uploaded->json('data.thumbUrl'),
        );

        $filed = $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement', [
            'requestDate' => $date,
            'items' => [array_merge($this->item('Site visit transport', 850, 2), [
                'receiptId' => $receiptId,
            ])],
        ])->assertCreated();

        $itemId = (int) $filed->json('data.items.0.id');
        $this->assertSame('grab.jpg', $filed->json('data.items.0.receiptName'));
        $this->assertSame(
            'https://res.cloudinary.com/test-cloud/image/upload/v1/ikaika-portal/reimbursements/grab.jpg',
            $filed->json('data.items.0.receiptUrl'),
        );
        $this->assertTrue(DB::connection('portal')->table('attachments')
            ->where('table_name', 'reimbursements')
            ->where('record_id', $itemId)
            ->where('field_name', 'receipts')
            ->exists());
    }

    public function test_a_receipt_url_from_somewhere_else_is_refused(): void
    {
        $actor = $this->activeMember();
        $token = $this->loginToken($actor);

        $this->withToken($token)->postJson('/api/development/portal/requests/reimbursement', [
            'requestDate' => Carbon::today()->toDateString(),
            'items' => [array_merge($this->item('Snacks', 120, 1), [
                'receiptUrl' => 'https://example.com/not-ours.pdf',
                'receiptName' => 'not-ours.pdf',
            ])],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That receipt is not one this form uploaded.');
    }

    /**
     * @param  array{label?: string, cost?: float, quantity?: int, purpose?: ?string, teamLabel?: string}  $overrides
     * @return array{label: string, cost: float, quantity: int, teamLabel: string, purpose: ?string}
     */
    private function item(
        string $label,
        float $cost,
        float $quantity,
        ?string $purpose = null,
        string $team = 'Angeles Pampanga Office',
    ): array {
        return [
            'label' => $label,
            'cost' => $cost,
            'quantity' => $quantity,
            'teamLabel' => $team,
            'purpose' => $purpose,
        ];
    }

    /**
     * @param  list<array{item: string, cost: float, qty: int}>  $items
     */
    private function insertClaim(Employee $actor, string $date, string $status, array $items): string
    {
        $stamp = $date.' 10:00:00';
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $this->insertItem(
                $actor,
                $date,
                $status,
                $item['item'],
                $item['cost'],
                $item['qty'],
                $stamp,
            );
        }

        return (string) min($ids);
    }

    private function insertItem(
        Employee $actor,
        string $date,
        string $status,
        string $item,
        float $cost,
        int $qty,
        string $createdAt,
    ): int {
        $id = (int) DB::connection('portal')->table('reimbursements')->insertGetId([
            'reimb_date' => $date,
            'item' => $item,
            'cost' => $cost,
            'qty' => $qty,
            'purpose' => 'seeded',
            'employee_name_input' => $this->memberName($actor),
            'team' => 'Angeles Pampanga Office',
            'status' => $status,
            'date_created' => $createdAt,
        ]);
        DB::connection('portal')->table('employees_reimbursements')->insert([
            'employee_id' => $actor->getKey(),
            'reimbursement_id' => $id,
        ]);

        return $id;
    }

    private function memberName(Employee $actor): string
    {
        return PortalSubmittedReportPresenter::memberName(
            is_string($actor->first_name) ? $actor->first_name : null,
            is_string($actor->last_name) ? $actor->last_name : null,
        );
    }

    private function activeMember(): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')
            ->where('id_no', '!=', '')
            ->whereNotNull('first_name')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function otherActiveMember(Employee $actor): Employee
    {
        $employee = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->where('id', '!=', $actor->id)
            ->whereNotNull('first_name')
            ->first();
        $this->assertNotNull($employee);

        return $employee;
    }

    private function loginToken(Employee $employee): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
