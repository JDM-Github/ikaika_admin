<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortalClashFlowWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/development/portal/clashflow';

    /** @var list<string> */
    protected array $connectionsToTransact = ['portal', 'core'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_records_are_versioned_per_entity_and_coordinators_are_limited_to_their_batch(): void
    {
        $this->employee('p2-admin', 'Admin');
        $this->employee('p2-lead', 'ProjectAdmin');
        $member = $this->employee('p2-member', 'User');
        $this->employee('p2-outsider', 'User');
        $portal = $this->portalProject('P2-TEST-001', 'p2-lead');
        DB::connection('portal')->table('employees_projects')->insert([
            'employee_id' => $member, 'project_id' => $portal, 'role_on_project' => 'member',
        ]);

        $id = $this->withToken($this->tokenFor('p2-lead'))
            ->postJson(self::BASE.'/projects', ['mode' => 'team', 'portalProjectId' => $portal])
            ->assertCreated()->json('data.id');
        $url = self::BASE.'/projects/'.$id.'/workflow';
        $lead = $this->tokenFor('p2-lead');
        $coordinator = $this->tokenFor('p2-member');
        $admin = $this->tokenFor('p2-admin');

        $batch = ['Id' => 'p2-batch', 'Name' => 'P2 batch', 'SplitKey' => 'level', 'OwnerId' => (string) $member,
            'Status' => 'InProgress', 'Locked' => true, 'GroupIds' => ['p2-group']];
        $group = ['Id' => 'p2-group', 'Number' => 'P2-001-EL', 'Test' => 'PL vs EL', 'Level' => 'L1',
            'BatchId' => 'p2-batch', 'Decision' => 'None'];
        $this->withToken($lead)->patchJson($url, ['records' => [
            ['type' => 'batch', 'key' => 'batch:p2-batch', 'payload' => json_encode($batch), 'version' => 0],
            ['type' => 'group', 'key' => 'group:p2-group', 'payload' => json_encode($group), 'version' => 0],
        ]])->assertOk();

        // A coordinator may decide their own group, and the lead may change another record in parallel.
        $group['Decision'] = 'Valid';
        $this->withToken($coordinator)->patchJson($url, ['records' => [
            ['type' => 'group', 'key' => 'group:p2-group', 'payload' => json_encode($group), 'version' => 1],
        ]])->assertOk()->assertJsonPath('records.0.version', 2);
        $batch['Status'] = 'Submitted';
        $this->withToken($lead)->patchJson($url, ['records' => [
            ['type' => 'batch', 'key' => 'batch:p2-batch', 'payload' => json_encode($batch), 'version' => 1],
        ]])->assertOk()->assertJsonPath('records.0.version', 2);

        // A stale group write is a conflict and does not overwrite the newer row.
        $this->withToken($lead)->patchJson($url, ['records' => [
            ['type' => 'group', 'key' => 'group:p2-group', 'payload' => json_encode($group), 'version' => 1],
        ]])->assertStatus(409)->assertJsonCount(1, 'conflicts');

        // A group in a lead-owned batch is not writable by the coordinator.
        $otherBatch = ['Id' => 'p2-lead-batch', 'Name' => 'Lead batch', 'SplitKey' => 'level', 'OwnerId' => (string) $this->employeeId('p2-lead'),
            'Status' => 'Assigned', 'Locked' => true, 'GroupIds' => ['p2-lead-group']];
        $otherGroup = ['Id' => 'p2-lead-group', 'Number' => 'P2-002-EL', 'Test' => 'PL vs EL', 'Level' => 'L2',
            'BatchId' => 'p2-lead-batch', 'Decision' => 'None'];
        $this->withToken($lead)->patchJson($url, ['records' => [
            ['type' => 'batch', 'key' => 'batch:p2-lead-batch', 'payload' => json_encode($otherBatch), 'version' => 0],
            ['type' => 'group', 'key' => 'group:p2-lead-group', 'payload' => json_encode($otherGroup), 'version' => 0],
        ]])->assertOk();
        $this->withToken($coordinator)->patchJson($url, ['records' => [
            ['type' => 'group', 'key' => 'group:p2-lead-group', 'payload' => json_encode($otherGroup), 'version' => 1],
        ]])->assertStatus(403);

        // Viewers may read the snapshot but never write.
        $this->withToken($admin)->getJson($url)->assertOk()->assertJsonPath('records.0.version', 2);
        $this->withToken($admin)->patchJson($url, ['records' => [
            ['type' => 'group', 'key' => 'group:p2-group', 'payload' => json_encode($group), 'version' => 2],
        ]])->assertStatus(403);

        $approvedBatch = $batch;
        $approvedBatch['Status'] = 'Approved';
        $this->withToken($coordinator)->patchJson($url, ['records' => [
            ['type' => 'batch', 'key' => 'batch:p2-batch', 'payload' => json_encode($approvedBatch), 'version' => 2],
        ]])->assertStatus(403);

        $this->assertSame(4, DB::connection('portal')->table('clashflow_records')
            ->where('clashflow_project_id', $id)->count());
        $this->assertSame(6, DB::connection('portal')->table('clashflow_audit')
            ->where('clashflow_project_id', $id)->count());

        $this->withToken($coordinator)->deleteJson(self::BASE.'/states')->assertNoContent();
        $this->assertSame(0, DB::connection('portal')->table('clashflow_records')
            ->where('clashflow_project_id', $id)->count());
    }

    private function employee(string $name, string $role): int
    {
        return DB::connection('portal')->table('employees')->insertGetId([
            'id_no' => 'CF-'.$name,
            'first_name' => 'Clash',
            'last_name' => $name,
            'email' => $name.'@clashflow.test',
            'status' => 'Active',
            'role' => $role,
            'department' => 'CF-Engineering',
        ]);
    }

    private function employeeId(string $name): int
    {
        return (int) DB::connection('portal')->table('employees')->where('id_no', 'CF-'.$name)->value('id');
    }

    private function portalProject(string $number, string $leadName): int
    {
        return DB::connection('portal')->table('projects')->insertGetId([
            'project_number' => $number,
            'project_name' => 'P2 test '.$number,
            'project_lead_email' => $leadName.'@clashflow.test',
            'department' => 'CF-Engineering',
            'status' => 'Started',
        ]);
    }

    private function tokenFor(string $name): string
    {
        $token = $this->postJson('/api/development/portal/auth/login', ['id_no' => 'CF-'.$name])->json('token');
        $this->assertIsString($token);

        return $token;
    }
}
