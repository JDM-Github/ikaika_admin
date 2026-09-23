<?php

namespace Tests\Unit;

use App\Support\Workspace\WorkspaceRedactor;
use PHPUnit\Framework\TestCase;

class WorkspaceRedactorTest extends TestCase
{
    public function test_a_credential_is_withheld_from_everyone_including_an_admin(): void
    {
        // estimator.accounts carries 18 live bcrypt hashes and a live invite code.
        $this->assertTrue(WorkspaceRedactor::isSecret('password_hash'));
        $this->assertTrue(WorkspaceRedactor::isSecret('invite_code'));
        $this->assertTrue(WorkspaceRedactor::isSecret('remember_token'));

        $this->assertTrue(WorkspaceRedactor::isHidden('password_hash', isAdmin: true));
        $this->assertTrue(WorkspaceRedactor::isHidden('invite_code', isAdmin: true));
    }

    public function test_a_secret_is_caught_by_fragment_as_well_as_by_name(): void
    {
        $this->assertTrue(WorkspaceRedactor::isSecret('user_password_digest'));
        $this->assertTrue(WorkspaceRedactor::isSecret('CLIENT_SECRET'));
        $this->assertFalse(WorkspaceRedactor::isSecret('passport_no'));
    }

    public function test_government_ids_and_bank_details_are_admin_only(): void
    {
        foreach (['tax_identification_no', 'philhealth_no', 'sss_no', 'hdmf_no', 'bank_account_number', 'date_of_birth', 'address'] as $column) {
            $this->assertTrue(WorkspaceRedactor::isPrivate($column), $column.' should be private.');
            $this->assertTrue(WorkspaceRedactor::isHidden($column, isAdmin: false), $column.' should be hidden from a member.');
            $this->assertFalse(WorkspaceRedactor::isHidden($column, isAdmin: true), $column.' should be readable by an admin.');
        }
    }

    public function test_ordinary_columns_are_untouched(): void
    {
        foreach (['id', 'project_name', 'status', 'email', 'report_date', 'hours_rendered'] as $column) {
            $this->assertFalse(WorkspaceRedactor::isHidden($column, isAdmin: false), $column.' should be readable.');
        }
    }

    public function test_the_allow_list_drops_exactly_what_the_actor_may_not_read(): void
    {
        $columns = ['id', 'id_no', 'first_name', 'password_hash', 'bank_account_number', 'email'];

        $this->assertSame(
            ['id', 'id_no', 'first_name', 'email'],
            WorkspaceRedactor::allow($columns, isAdmin: false),
        );

        $this->assertSame(
            ['id', 'id_no', 'first_name', 'bank_account_number', 'email'],
            WorkspaceRedactor::allow($columns, isAdmin: true),
        );
    }
}
