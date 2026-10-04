<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The editable details an employee's signature shows. The Airtable sync overwrites the mapped
 * `employees` columns one way on every run, so the portal's copy lives in `employee_signatures` and
 * is read as "portal value, else the employee row". Editing is the Edit profiles power.
 */
final class PortalEmployeeSignature
{
    public const RULE = 'manage.users.profile';

    public function __construct(private readonly PortalAudit $audit) {}

    /**
     * @return array{name: ?string, title: ?string, email: ?string, phoneNumber: ?string, department: ?string, location: ?string, footerId: ?int}
     */
    public function values(Employee $employee): array
    {
        $row = $this->row((int) $employee->getKey());

        return [
            'name' => PortalAccessGraph::name($employee),
            'title' => $this->pick($row->title ?? null, $employee->job_title),
            'email' => $this->pick($row->email ?? null, $employee->email),
            'phoneNumber' => $this->pick($row->phone_number ?? null, $employee->phone_number),
            'department' => $this->pick($row->department ?? null, $employee->department),
            'location' => $this->pick($row->location ?? null, $employee->location),
            'footerId' => $row !== null && $row->footer_id !== null ? (int) $row->footer_id : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{name: ?string, title: ?string, email: ?string, phoneNumber: ?string, department: ?string, location: ?string, footerId: ?int}
     */
    public function update(Employee $actor, Employee $target, array $fields, Request $request): array
    {
        $footerId = (int) ($fields['footerId'] ?? 0);
        $columns = [
            'title' => $this->text($fields['title'] ?? null),
            'email' => $this->text($fields['email'] ?? null),
            'phone_number' => $this->text($fields['phoneNumber'] ?? null),
            'department' => $this->text($fields['department'] ?? null),
            'location' => $this->text($fields['location'] ?? null),
            'footer_id' => $footerId > 0 ? $footerId : null,
            'updated_at' => now(),
        ];
        $this->connection()->table('employee_signatures')->updateOrInsert(
            ['employee_id' => (int) $target->getKey()],
            $columns,
        );

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::RULE,
            PortalActivityCopy::editedSignatureProfile($target),
            (string) $target->getKey(),
            $request,
            ['employeeId' => (int) $target->getKey()],
        );

        return $this->values($target);
    }

    private function row(int $employeeId): ?object
    {
        return $this->connection()->table('employee_signatures')->where('employee_id', $employeeId)->first();
    }

    private function pick(mixed $override, mixed $fallback): ?string
    {
        return $this->text($override) ?? $this->text($fallback);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
