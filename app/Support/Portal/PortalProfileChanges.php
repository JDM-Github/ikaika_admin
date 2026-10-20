<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Profile changes (2026-10-14, user): anyone changes their own employee information and documents on
 * Profile, and nothing reaches their record until a head admin approves it. A request keeps the
 * details asked for -- cleaned by the same rules an administrator's edit follows -- the files put on
 * Cloudinary for it, and the files to take off. One waits at a time; withdrawing it lets them ask again.
 *
 * Whoever holds Approve profile changes is told when one comes in, and decides it on Manage / Users;
 * the one who asked is told how it went. Their own changes need no one else (2026-10-19, user): a head
 * admin's reach their record as they are sent, and one sent before that they may approve themselves.
 * Logs and notices name the details and the slots, never their values.
 *
 * Both addresses are edited in parts as the registration asks for them (2026-10-19, user): the record
 * keeps one line, so its place is read back into the pickers, and the parts sent are joined into the
 * line by the same rules the registration's are.
 */
final class PortalProfileChanges
{
    public const RULE = 'manage.users.information.approve';

    public const PENDING = 'Pending';

    public const APPROVED = 'Approved';

    public const REJECTED = 'Rejected';

    public const WITHDRAWN = 'Withdrawn';

    private const TABLE = 'employee_change_requests';

    // Every slot once, and the five bank certificates.
    private const MOST_FILES = 12;

    // The details a registration asks for and will not go without: all but the personal email.
    private const REQUIRED_FIELDS = [
        'dateOfBirth', 'bloodType', 'address', 'phoneNumber', 'taxIdentificationNo', 'sssNo', 'philhealthNo', 'hdmfNo',
        'bankName', 'bankAccountNumber', 'bankSwiftCode', 'emergencyContactName', 'emergencyContactNumber', 'emergencyContactAddress',
    ];

    /** Each address's line on the record, and the prefix its parts are sent under, as the registration's. */
    private const ADDRESSES = ['address' => 'address', 'emergencyContactAddress' => 'emergencyContact'];

    private const ADDRESS_PARTS = ['country', 'province', 'provinceCode', 'city', 'cityCode', 'barangay', 'barangayCode', 'street'];

    public function __construct(
        private readonly PortalManageUsers $users,
        private readonly PortalEmployeeDocuments $documents,
        private readonly PortalAudit $audit,
        private readonly PortalPlaces $places,
        private readonly PortalAddressResolver $resolver,
        private readonly PortalRegistrationInput $input,
        private readonly PortalHomeLocations $homes,
    ) {}

    /**
     * Their own employee information and documents, the change waiting on them, and how the last one went.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function mine(Employee $employee, Request $request): array
    {
        $employee = $this->whole($employee);
        $pending = $this->rowsOf($employee)->where('status', self::PENDING)->orderByDesc('id')->first();
        $decided = $this->rowsOf($employee)->whereIn('status', [self::APPROVED, self::REJECTED])->orderByDesc('id')->first();

        $information = $this->users->informationOf($employee);
        $documents = $this->documents->listFor($employee);
        $held = array_column($documents, 'field');

        return ['section' => 'user', 'resource' => 'information', 'data' => [
            'information' => $information,
            // Whoever approves profile changes saves their own straight onto their record (2026-10-19, user).
            'approvesOwn' => PortalRules::holds($employee, $request, self::RULE),
            // Where each address line places them in the Philippines, for the pickers to open on.
            'addressPlaces' => array_map(fn (string $line): ?array => $this->placeOf($information[$line] ?? null), array_combine(array_keys(self::ADDRESSES), array_keys(self::ADDRESSES))),
            'documents' => $documents,
            // What a registration would not go without, still not on their record (2026-10-19, user).
            'missing' => [
                'fields' => array_values(array_filter(self::REQUIRED_FIELDS, static fn (string $key): bool => ($information[$key] ?? null) === null)),
                'documents' => array_values(array_filter(
                    array_map(static fn (string $kind): string => PortalEmployeeDocuments::FIELD_FOR_KIND[$kind], PortalRegistrationDocuments::REQUIRED),
                    static fn (string $field): bool => ! in_array($field, $held, true),
                )),
            ],
            'pending' => $pending === null ? null : $this->presented($pending, $employee, false),
            // Only what came after their last request, so an old rejection does not linger over a new one.
            'lastDecision' => $decided === null || ($pending !== null && (int) $pending->id > (int) $decided->id) ? null : [
                'status' => (string) $decided->status,
                'remarks' => self::text($decided->remarks),
                'decidedAt' => self::instant($decided->decided_at),
            ],
        ]];
    }

    /**
     * Their changes sent for approval: details as the profile sends them, files under files[slot]
     * (files[bank_certificate][] for several), and the files to take off under removeDocumentIds[].
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function submit(Employee $employee, Request $request): array
    {
        $employee = $this->whole($employee);
        if ($this->rowsOf($employee)->where('status', self::PENDING)->exists()) {
            abort(422, 'Your earlier changes are still waiting for approval. Withdraw them to change something else.');
        }
        $this->joinAddresses($request);
        $fields = $this->users->cleanedInformation($employee, $request) ?? [];
        /** @var array{removeDocumentIds?: list<int>} $removal */
        $removal = $request->validate(['removeDocumentIds' => ['sometimes', 'array', 'max:20'], 'removeDocumentIds.*' => ['integer', 'min:1']]);
        $removedIds = array_values(array_unique(array_map('intval', $removal['removeDocumentIds'] ?? [])));
        foreach ($removedIds as $documentId) {
            if ($this->documents->fieldOf($employee, $documentId) === null) {
                abort(422, 'One of the documents to take off is not yours, or is gone.');
            }
        }

        $files = [];
        foreach ((array) $request->file('files', []) as $field => $value) {
            foreach (is_array($value) ? $value : [$value] as $file) {
                $files[] = [(string) $field, $file];
            }
        }
        if (count($files) > self::MOST_FILES) {
            abort(422, 'Send up to '.self::MOST_FILES.' files at a time.');
        }
        $addedFields = array_column($files, 0);
        foreach (array_count_values($addedFields) as $field => $count) {
            if ($count > 1 && ! (PortalEmployeeDocuments::EDITABLE[$field]['multiple'] ?? false)) {
                abort(422, 'Choose one file for each document.');
            }
        }
        if ($fields === [] && $files === [] && $removedIds === []) {
            abort(422, 'Change at least one detail or document.');
        }
        $this->documents->assertRoom($employee, $addedFields, $removedIds);

        // On Cloudinary first, so a file that fails leaves nothing waiting.
        $stored = array_map(fn (array $file): array => $this->documents->stored($file[0], $file[1]), $files);
        $row = [
            'employee_id' => (int) $employee->getKey(),
            'status' => self::PENDING,
            'fields' => json_encode((object) $fields, JSON_THROW_ON_ERROR),
            'documents' => json_encode(['add' => $stored, 'remove' => $removedIds], JSON_THROW_ON_ERROR),
            'submitted_at' => now(),
        ];

        // A head admin's own changes are approved by them as they are sent, and reach their record now.
        if (PortalRules::holds($employee, $request, self::RULE)) {
            $id = DB::connection('portal')->transaction(function () use ($row, $employee): int {
                $id = (int) DB::connection('portal')->table(self::TABLE)->insertGetId(
                    ['status' => self::APPROVED, 'decided_at' => now(), 'decided_by' => (int) $employee->getKey()] + $row,
                );
                $this->apply($employee, self::fieldsOf((object) $row), self::documentsOf((object) $row));

                return $id;
            });
            if (array_key_exists('address', $fields)) {
                $this->homes->followAddress((int) $employee->getKey());
            }
            $this->audit->record(
                $employee,
                PortalLogAction::PATCH,
                self::RULE,
                '{UserName|You} changed {UserName|your} own employee information',
                (string) $id,
                $request,
                ['fields' => array_keys($fields), 'documents' => array_values(array_unique($addedFields)), 'removed' => count($removedIds)],
            );

            return $this->mine($employee, $request);
        }

        $id = (int) DB::connection('portal')->table(self::TABLE)->insertGetId($row);

        $name = PortalAccessGraph::name($employee);
        $this->audit->record(
            $employee,
            PortalLogAction::POST,
            self::RULE,
            '{UserName|You} asked for profile changes',
            (string) $id,
            $request,
            ['fields' => array_keys($fields), 'documents' => array_values(array_unique($addedFields)), 'removed' => count($removedIds)],
        );
        $reviewers = [];
        foreach (PortalRules::holders([self::RULE]) as $holder) {
            if (PortalRole::normalize($holder['employee']->status) === 'active' && (int) $holder['employee']->getKey() !== (int) $employee->getKey()) {
                $reviewers[] = (int) $holder['employee']->getKey();
            }
        }
        $this->audit->notifyEach(
            $reviewers,
            PortalNotificationType::PROFILE_CHANGES_TO_REVIEW,
            'Profile changes to review',
            $name.' asked to change their employee information.',
            PortalShellPath::USER_MANAGEMENT,
            'Review changes',
            ['recordId' => (string) $id],
            (int) $employee->getKey(),
        );

        return $this->mine($employee, $request);
    }

    /**
     * Their waiting change taken back, so they can ask for something else.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function withdraw(Employee $employee, Request $request): array
    {
        $pending = $this->rowsOf($employee)->where('status', self::PENDING)->orderByDesc('id')->first();
        if ($pending === null) {
            abort(404, 'You have no changes waiting for approval.');
        }
        DB::connection('portal')->table(self::TABLE)->where('id', (int) $pending->id)->where('status', self::PENDING)
            ->update(['status' => self::WITHDRAWN, 'decided_at' => now()]);
        $this->audit->record($employee, PortalLogAction::PATCH, self::RULE, '{UserName|You} withdrew profile changes', (string) $pending->id, $request);

        return $this->mine($employee, $request);
    }

    /**
     * The changes waiting for approval, oldest first, of the people whose information the reviewer sees.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function waiting(Employee $reviewer, Request $request): array
    {
        $rows = DB::connection('portal')->table(self::TABLE)->where('status', self::PENDING)->orderBy('submitted_at')->orderBy('id')->get();
        $people = Employee::query()->whereIn('id', $rows->pluck('employee_id')->map(static fn (mixed $id): int => (int) $id)->all())->get()->keyBy(static fn (Employee $employee): int => (int) $employee->getKey());
        $items = [];
        foreach ($rows as $row) {
            $employee = $people->get((int) $row->employee_id);
            if ($employee instanceof Employee && $this->users->seesPrivate($reviewer, $employee, $request)) {
                $items[] = $this->presented($row, $employee, true);
            }
        }

        return ['section' => 'manage', 'resource' => 'profile-changes', 'data' => ['items' => $items]];
    }

    /**
     * A waiting change approved -- its details and files reach their record -- or rejected with a reason.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function decide(Employee $reviewer, int $id, Request $request): array
    {
        /** @var array{decision: string, remarks?: string|null} $data */
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:500', 'required_if:decision,reject'],
        ], ['remarks.required_if' => 'Say why the changes are rejected.']);
        $isApproved = $data['decision'] === 'approve';
        $remarks = self::text($data['remarks'] ?? null);

        $outcome = DB::connection('portal')->transaction(function () use ($reviewer, $id, $request, $isApproved, $remarks): array {
            $row = DB::connection('portal')->table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
            if ($row === null) {
                abort(404, 'Those profile changes were not found.');
            }
            if ((string) $row->status !== self::PENDING) {
                abort(422, 'Those profile changes were already '.strtolower((string) $row->status).'.');
            }
            $employee = Employee::query()->find((int) $row->employee_id);
            if (! $employee instanceof Employee || ! $this->users->seesPrivate($reviewer, $employee, $request)) {
                abort(404, 'Those profile changes were not found.');
            }
            $documents = self::documentsOf($row);
            $fields = self::fieldsOf($row);
            if ($isApproved) {
                $this->apply($employee, $fields, $documents);
            }
            DB::connection('portal')->table(self::TABLE)->where('id', $id)->update([
                'status' => $isApproved ? self::APPROVED : self::REJECTED,
                'decided_at' => now(),
                'decided_by' => (int) $reviewer->getKey(),
                'remarks' => $remarks,
            ]);

            return ['employee' => $employee, 'fields' => array_keys($fields), 'documents' => array_values(array_unique(array_column($documents['add'], 'field')))];
        });

        /** @var Employee $employee */
        $employee = $outcome['employee'];
        // A new address approved moves their home on the map with it (2026-10-19, user), once it is on record.
        $homeMoved = $isApproved && in_array('address', $outcome['fields'], true) && $this->homes->followAddress((int) $employee->getKey());
        $name = PortalAccessGraph::name($employee);
        $this->audit->record(
            $reviewer,
            PortalLogAction::PATCH,
            self::RULE,
            '{UserName|You} '.($isApproved ? 'approved' : 'rejected').' the profile changes of '.$name,
            (string) $id,
            $request,
            ['fields' => $outcome['fields'], 'documents' => $outcome['documents']],
        );
        $this->audit->notifyIfOther(
            $employee,
            $reviewer,
            $isApproved ? PortalNotificationType::PROFILE_CHANGES_APPROVED : PortalNotificationType::PROFILE_CHANGES_REJECTED,
            $isApproved ? 'Profile changes approved' : 'Profile changes rejected',
            $isApproved
                ? 'Your changes to your employee information are now on your record.'.($homeMoved ? ' Your home on the map moved to your new address.' : '')
                : 'Your changes to your employee information were not approved: '.$remarks,
            PortalShellPath::PROFILE,
            'Open profile',
            ['recordId' => (string) $id],
        );

        return $this->waiting($reviewer, $request);
    }

    /**
     * A request as Profile and the reviewers see it: each detail from what is on record to what is
     * asked, the files asked for, and the files to take off that are still there.
     *
     * @return array<string, mixed>
     */
    private function presented(object $row, Employee $employee, bool $withEmployee): array
    {
        $current = $this->users->informationOf($employee);
        $documents = self::documentsOf($row);
        $held = [];
        foreach ($this->documents->listFor($employee) as $document) {
            $held[(int) $document['id']] = $document;
        }

        $fields = [];
        foreach (self::fieldsOf($row) as $key => $value) {
            $fields[] = ['key' => $key, 'from' => $current[$key] ?? null, 'to' => $value];
        }
        $added = [];
        foreach ($documents['add'] as $index => $stored) {
            $added[] = $this->documents->described('new-'.$index, $stored['field'], $stored['fileName'], $stored['mimeType'], $stored['sizeBytes'], $stored['fileUrl']);
        }
        $removed = [];
        foreach ($documents['remove'] as $documentId) {
            if (isset($held[$documentId])) {
                $removed[] = $held[$documentId];
            }
        }

        return [
            'id' => (string) $row->id,
            'status' => (string) $row->status,
            'submittedAt' => self::instant($row->submitted_at),
            'employee' => ! $withEmployee ? null : [
                'id' => (string) $employee->getKey(),
                'name' => PortalAccessGraph::name($employee),
                'idNo' => self::text($employee->id_no),
                'jobTitle' => self::text($employee->job_title),
                'department' => self::text($employee->department),
            ],
            'fields' => $fields,
            'documents' => ['added' => $added, 'removed' => $removed],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function fieldsOf(object $row): array
    {
        $fields = json_decode((string) ($row->fields ?? '{}'), true);

        return is_array($fields) ? array_map(static fn (mixed $value): ?string => is_string($value) ? $value : null, $fields) : [];
    }

    /**
     * @return array{add: list<array{field: string, fileUrl: string, fileName: string, sizeBytes: int, mimeType: string}>, remove: list<int>}
     */
    private static function documentsOf(object $row): array
    {
        $documents = json_decode((string) ($row->documents ?? '{}'), true);
        $add = [];
        foreach (is_array($documents['add'] ?? null) ? $documents['add'] : [] as $stored) {
            if (is_array($stored) && is_string($stored['field'] ?? null) && is_string($stored['fileUrl'] ?? null)) {
                $add[] = [
                    'field' => $stored['field'],
                    'fileUrl' => $stored['fileUrl'],
                    'fileName' => (string) ($stored['fileName'] ?? $stored['field']),
                    'sizeBytes' => (int) ($stored['sizeBytes'] ?? 0),
                    'mimeType' => (string) ($stored['mimeType'] ?? ''),
                ];
            }
        }
        $remove = array_values(array_map('intval', is_array($documents['remove'] ?? null) ? $documents['remove'] : []));

        return ['add' => $add, 'remove' => $remove];
    }

    /**
     * The province, city and barangay an address line names, for the pickers to open on; null when it
     * names none. The street stays typed, since a line does not say where the street ends.
     *
     * @return array{provinceCode: string, cityCode: ?string, barangayCode: ?string}|null
     */
    private function placeOf(?string $line): ?array
    {
        if ($line === null || trim($line) === '') {
            return null;
        }
        $place = $this->resolver->identify($line, null);

        return $place['provinceCode'] === null ? null : [
            'provinceCode' => $place['provinceCode'],
            'cityCode' => $place['cityCode'],
            'barangayCode' => $place['barangayCode'],
        ];
    }

    /**
     * An address sent in parts becomes its line, as the registration's does: in the Philippines each
     * picked code must sit in the one above it, and every part the line needs must be there.
     */
    private function joinAddresses(Request $request): void
    {
        foreach (self::ADDRESSES as $line => $prefix) {
            $keys = array_map(static fn (string $part): string => $prefix.ucfirst($part), self::ADDRESS_PARTS);
            if (array_intersect($keys, array_keys($request->all())) === []) {
                continue;
            }
            /** @var array<string, string|null> $sent */
            $sent = $request->validate(array_fill_keys($keys, ['sometimes', 'nullable', 'string', 'max:200']));
            $clean = $this->input->cleaned($sent);
            $parts = [];
            foreach (self::ADDRESS_PARTS as $part) {
                $value = $clean[$prefix.ucfirst($part)] ?? null;
                $parts[$part] = is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
            $joined = $this->places->resolve($parts)['line'];
            if ($joined === null) {
                abort(422, $line === 'address'
                    ? 'Finish your address: every part down to the house number and street.'
                    : 'Finish your contact person\'s address: every part down to the house number and street.');
            }
            $request->merge([$line => $joined]);
        }
    }

    /**
     * Approved changes put on their record: the files to take off that are still theirs, the files
     * asked for, and the details.
     *
     * @param  array<string, string|null>  $fields
     * @param  array{add: list<array{field: string, fileUrl: string, fileName: string, sizeBytes: int, mimeType: string}>, remove: list<int>}  $documents
     */
    private function apply(Employee $employee, array $fields, array $documents): void
    {
        foreach ($documents['remove'] as $documentId) {
            if ($this->documents->fieldOf($employee, $documentId) !== null) {
                $this->documents->remove($employee, $documentId);
            }
        }
        $this->documents->assertRoom($employee, array_column($documents['add'], 'field'), []);
        foreach ($documents['add'] as $stored) {
            $this->documents->attach($employee, $stored);
        }
        $this->users->applyInformation($employee, $fields);
        $this->users->bumpCache();
    }

    // The signed-in employee comes with their session's columns only; their details are read from the whole row.
    private function whole(Employee $employee): Employee
    {
        $whole = Employee::query()->find((int) $employee->getKey());
        if (! $whole instanceof Employee) {
            abort(401, 'Authentication is required.');
        }

        return $whole;
    }

    private function rowsOf(Employee $employee): Builder
    {
        return DB::connection('portal')->table(self::TABLE)->where('employee_id', (int) $employee->getKey());
    }

    private static function instant(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse((string) $value)->utc()->toIso8601String();
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
