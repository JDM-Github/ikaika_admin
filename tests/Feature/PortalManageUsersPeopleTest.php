<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Manage / Users, Add users and Change departments (2026-10-06). An admin -- MIS, like Change roles
 * -- adds someone to their own department before Airtable has them, and moves anyone they can see to
 * another department, which the portal's reaches and leading follow and the Airtable sync carries.
 */
class PortalManageUsersPeopleTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal', 'core'];

    private const BASE = '/api/development/portal/manage/users';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // The reported ask: add a new user, in their department.
    public function test_an_admin_adds_someone_to_their_department_as_an_active_user(): void
    {
        $admin = $this->misAdmin();
        $token = $this->token($admin);

        $options = $this->withToken($token)->getJson(self::BASE.'/new')->assertOk()->json('data');
        $this->assertSame(['MIS'], $options['departments']);
        $this->assertMatchesRegularExpression('/^'.now('Asia/Manila')->format('ymd').'-\d{4}$/', $options['nextIdNo']);
        $highest = Employee::query()->pluck('id_no')->map(fn ($idNo): int => preg_match('/^\d{6}-(\d{4})$/', (string) $idNo, $m) === 1 ? (int) $m[1] : 0)->max();
        $this->assertSame(sprintf('%04d', $highest + 1), substr($options['nextIdNo'], 7));

        $email = 'new.person.'.Str::lower(Str::random(6)).'@ikaika.test';
        $profile = $this->add($token, [
            'firstName' => '  Lani ', 'middleName' => '', 'lastName' => 'Kealoha',
            'idNo' => $options['nextIdNo'], 'email' => strtoupper($email), 'jobTitle' => 'BIM Modeler', 'department' => 'mis',
        ])->assertCreated()->json('data');

        $row = Employee::query()->where('id_no', $options['nextIdNo'])->firstOrFail();
        $this->assertSame(
            ['Lani', null, 'Kealoha', $email, 'BIM Modeler', 'MIS', 'Active', 'User'],
            [$row->first_name, $row->middle_name, $row->last_name, $row->email, $row->job_title, $row->department, $row->status, $row->role],
        );
        $this->assertSame((string) $row->getKey(), (string) $profile['id']);
        $this->assertTrue(DB::connection('portal')->table('logs')->where('resource', 'manage.users')->where('record_id', (string) $row->getKey())
            ->where('message', '{UserName|You} added Lani Kealoha as a user')->exists());

        // They sign in with their ID No. and appear on the roster.
        $this->postJson('/api/development/portal/auth/login', ['id_no' => $options['nextIdNo']])->assertOk();
        $roster = array_map('strval', array_column($this->withToken($token)->getJson(self::BASE.'?q=Kealoha')->assertOk()->json('data'), 'id'));
        $this->assertContains((string) $row->getKey(), $roster);
    }

    public function test_adding_refuses_a_taken_id_or_email_a_bad_id_and_a_department_out_of_reach(): void
    {
        $admin = $this->misAdmin();
        $token = $this->token($admin);
        $taken = Employee::query()->whereNotNull('email')->where('email', '<>', '')->whereNotNull('id_no')->firstOrFail();
        $body = ['firstName' => 'Kai', 'lastName' => 'Nohea', 'idNo' => '261006-9901', 'email' => 'kai.nohea@ikaika.test', 'department' => 'MIS'];

        $this->add($token, [...$body, 'idNo' => $taken->id_no])->assertStatus(422)->assertJsonPath('message', 'Someone already has the ID No. '.$taken->id_no.'.');
        $this->add($token, [...$body, 'email' => strtoupper((string) $taken->email)])->assertStatus(422)->assertJsonPath('message', 'Someone already has that email address.');
        $this->add($token, [...$body, 'idNo' => '2610069901'])->assertStatus(422)->assertJsonPath('message', 'An ID No. is the start date and a number, like 261006-0021.');
        // Users at their own department adds to that department only.
        $this->add($token, [...$body, 'department' => 'Engineering'])->assertStatus(422)->assertJsonPath('message', 'Choose a department from the list.');
        $this->add($token, [...$body, 'firstName' => '   '])->assertStatus(422);
        $this->add($token, [...$body, 'email' => 'not-an-email'])->assertStatus(422);
        $this->assertFalse(Employee::query()->where('id_no', '261006-9901')->exists());
    }

    // Airtable imports leave stray spaces and capitals in some addresses; they still count as taken.
    public function test_an_address_stored_with_spaces_and_capitals_is_still_taken(): void
    {
        $token = $this->token($this->misAdmin());
        $email = 'padded.'.Str::lower(Str::random(6)).'@ikaika.test';
        $this->member()->forceFill(['email' => '  '.strtoupper($email).' '])->save();

        $this->add($token, ['firstName' => 'Kai', 'lastName' => 'Nohea', 'idNo' => '261006-9903', 'email' => $email, 'department' => 'MIS'])
            ->assertStatus(422)->assertJsonPath('message', 'Someone already has that email address.');
    }

    // Holding Add users is not enough when they narrowed Users to their own record.
    public function test_adding_needs_users_at_the_department_at_least(): void
    {
        $admin = $this->misAdmin();
        DB::connection('portal')->table('access_self_reach')->insert(['employee_id' => $admin->getKey(), 'permission_key' => 'manage.users', 'reach' => 'own']);
        Cache::flush();

        $this->add($this->token($admin), ['firstName' => 'Kai', 'lastName' => 'Nohea', 'idNo' => '261006-9904', 'email' => 'kai.own@ikaika.test', 'department' => 'MIS'])
            ->assertForbidden()->assertJsonPath('message', 'Adding someone needs Users at your department or every department.');
    }

    public function test_someone_without_the_power_cannot_add_or_move_anyone(): void
    {
        $member = $this->member();
        $token = $this->token($member);

        $this->withToken($token)->getJson(self::BASE.'/new')->assertForbidden();
        $this->add($token, ['firstName' => 'A', 'lastName' => 'B', 'idNo' => '261006-9902', 'email' => 'a.b@ikaika.test', 'department' => 'MIS'])->assertForbidden();
        $this->withToken($token)->patchJson(self::BASE.'/'.$member->getKey().'/department', ['department' => 'Engineering'])->assertForbidden();
    }

    // The reported ask: assign someone to their department -- the one the portal uses, not the signature's.
    public function test_an_admin_moves_someone_to_another_department_which_syncs_and_tells_them(): void
    {
        $admin = $this->misAdmin();
        $token = $this->token($admin);
        $person = $this->member();
        $person->forceFill(['department' => 'MIS'])->save();

        $profile = $this->withToken($token)->getJson(self::BASE.'/'.$person->getKey())->assertOk()->json('data');
        $this->assertTrue($profile['canChangeDepartment']);
        $this->assertContains('Engineering', $profile['departmentOptions']);

        $this->withToken($token)->patchJson(self::BASE.'/'.$person->getKey().'/department', ['department' => 'engineering'])
            ->assertOk()
            ->assertJsonPath('data.department', 'Engineering');
        $this->assertSame('Engineering', $person->fresh()->department);

        // Carried to Airtable like a role change: only the department, as it changed.
        $action = DB::connection('core')->table('actions')->where('resource', 'employees')->where('record_id', (string) $person->getKey())->orderByDesc('id')->first();
        $this->assertNotNull($action);
        $parameters = json_decode((string) $action->parameters, true)['parameters'] ?? [];
        $this->assertSame(['department' => 'Engineering'], $parameters['fields'] ?? null);
        $this->assertEquals(['from' => 'MIS', 'to' => 'Engineering'], $parameters['changes']['department'] ?? null);
        $this->assertTrue(DB::connection('portal')->table('notifications')->where('employee_id', $person->getKey())->where('type', 'manage.users.department')->exists());

        // Moved out of the admin's department, they are out of the admin's reach.
        $this->withToken($token)->patchJson(self::BASE.'/'.$person->getKey().'/department', ['department' => 'MIS'])->assertNotFound();
    }

    public function test_a_move_needs_a_known_department_and_saving_the_same_one_changes_nothing(): void
    {
        $admin = $this->misAdmin();
        $token = $this->token($admin);
        $person = $this->member();
        $person->forceFill(['department' => 'MIS'])->save();
        $actions = DB::connection('core')->table('actions')->where('resource', 'employees')->count();

        $this->withToken($token)->patchJson(self::BASE.'/'.$person->getKey().'/department', ['department' => 'Narnia'])
            ->assertStatus(422)->assertJsonPath('message', 'Choose a department from the list.');
        $this->withToken($token)->patchJson(self::BASE.'/'.$person->getKey().'/department', ['department' => 'mis'])->assertOk();
        $this->assertSame($actions, DB::connection('core')->table('actions')->where('resource', 'employees')->count());
        $this->assertSame('MIS', $person->fresh()->department);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function add(string $token, array $body): TestResponse
    {
        return $this->withToken($token)->postJson(self::BASE, $body);
    }

    // Add users and Change departments are MIS admins', like Change roles.
    private function misAdmin(): Employee
    {
        $admin = Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'admin'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
        $admin->forceFill(['is_developer' => false, 'department' => 'MIS'])->save();

        return $admin;
    }

    private function member(): Employee
    {
        return Employee::query()
            ->whereRaw("LOWER(COALESCE(status, '')) = 'active'")
            ->whereRaw("LOWER(COALESCE(role, '')) = 'user'")
            ->whereRaw("LOWER(COALESCE(role_level, '')) != 'executive'")
            ->whereNotNull('id_no')->where('id_no', '!=', '')
            ->orderBy('id')
            ->firstOrFail();
    }

    private function token(Employee $employee): string
    {
        return (string) $this->postJson('/api/development/portal/auth/login', ['id_no' => $employee->id_no])->assertOk()->json('token');
    }
}
