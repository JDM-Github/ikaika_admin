<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-in by email (2026-10-12, user): the employee types their work or personal email, a separate
 * 6-digit code goes to every address on record (work and personal, or just the one they have), and
 * all of them must be typed back. Two inboxes, two codes: a stolen mailbox alone is not enough.
 *
 * The start answers which addresses got a code, masked, so the screen draws one box per code.
 */
final class PortalEmailLogin
{
    public const KIND_WORK = 'work';

    public const KIND_PERSONAL = 'personal';

    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const RESEND_SECONDS = 60;

    // Unknown and inactive read the same, so the answer says nothing about whose address it is.
    private const NO_ACCOUNT = 'No active portal account uses that email.';

    /**
     * Mails a fresh code to each address on record and says where they went.
     *
     * @return list<array{kind: string, hint: string}>
     */
    public function start(string $email): array
    {
        $employee = $this->employeeFor($email);
        $addresses = $this->addresses($employee);

        $existing = $this->connection()->table('login_otps')->where('employee_id', $employee->getKey())->first();
        if ($existing !== null && strtotime((string) $existing->issued_at) > now()->getTimestamp() - self::RESEND_SECONDS) {
            abort(429, 'Wait a moment before asking for new codes.');
        }

        $codes = [];
        foreach (array_keys($addresses) as $kind) {
            $codes[$kind] = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        }
        $this->connection()->table('login_otps')->updateOrInsert(
            ['employee_id' => $employee->getKey()],
            [
                'work_code_hash' => isset($codes[self::KIND_WORK]) ? Hash::make($codes[self::KIND_WORK]) : null,
                'personal_code_hash' => isset($codes[self::KIND_PERSONAL]) ? Hash::make($codes[self::KIND_PERSONAL]) : null,
                'attempts' => 0,
                'issued_at' => now(),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ],
        );

        $targets = [];
        foreach ($addresses as $kind => $address) {
            PortalLetters::send(
                $address,
                'Your IKAIKA Portal sign-in code ('.($kind === self::KIND_WORK ? 'work' : 'personal').' email)',
                $this->letter((string) $employee->first_name, $codes[$kind], $kind, count($addresses) > 1),
            );
            $targets[] = ['kind' => $kind, 'hint' => self::mask($address)];
        }

        return $targets;
    }

    /**
     * Checks every code that went out; the employee to sign in when all of them match.
     *
     * @param  array<string, mixed>  $codes
     */
    public function verify(string $email, array $codes): Employee
    {
        $employee = $this->employeeFor($email);
        $row = $this->connection()->table('login_otps')->where('employee_id', $employee->getKey())->first();
        if ($row === null || strtotime((string) $row->expires_at) <= now()->getTimestamp()) {
            abort(422, 'Ask for new codes.');
        }
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            abort(422, 'Too many tries. Ask for new codes.');
        }

        $hashes = array_filter([
            self::KIND_WORK => $row->work_code_hash,
            self::KIND_PERSONAL => $row->personal_code_hash,
        ], static fn (mixed $hash): bool => $hash !== null);
        foreach ($hashes as $kind => $hash) {
            if (! Hash::check(trim((string) ($codes[$kind] ?? '')), (string) $hash)) {
                $this->connection()->table('login_otps')->where('employee_id', $employee->getKey())->increment('attempts');
                abort(422, 'One of the codes is not right.');
            }
        }
        $this->connection()->table('login_otps')->where('employee_id', $employee->getKey())->delete();

        return $employee;
    }

    // "jd***@ikaikabim.com": enough to tell the two inboxes apart, not enough to read the address.
    public static function mask(string $address): string
    {
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }

    private function employeeFor(string $email): Employee
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Type a valid email address.');
        }

        $employee = Employee::query()
            ->select(PortalManageUserPresenter::sessionColumns())
            ->addSelect('personal_email')
            ->where(static fn ($query) => $query->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                ->orWhereRaw('LOWER(TRIM(personal_email)) = ?', [$email]))
            ->first();
        if ($employee === null || strtolower(trim((string) $employee->status)) !== 'active') {
            abort(422, self::NO_ACCOUNT);
        }

        return $employee;
    }

    /**
     * The addresses on record, by kind; a duplicate personal address counts once.
     *
     * @return array<string, string>
     */
    private function addresses(Employee $employee): array
    {
        $addresses = [];
        foreach ([self::KIND_WORK => $employee->email, self::KIND_PERSONAL => $employee->personal_email] as $kind => $value) {
            $address = strtolower(trim((string) $value));
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false && ! in_array($address, $addresses, true)) {
                $addresses[$kind] = $address;
            }
        }
        if ($addresses === []) {
            abort(422, self::NO_ACCOUNT);
        }

        return $addresses;
    }

    private function letter(string $firstName, string $code, string $kind, bool $hasTwo): string
    {
        $other = $kind === self::KIND_WORK ? 'personal' : 'work';
        $name = trim($firstName) !== '' ? trim($firstName) : 'there';

        return 'Hi '.$name.','
            ."\n\nYour IKAIKA Portal sign-in code is **".$code.'**. It is good for '.self::TTL_MINUTES.' minutes.'
            .($hasTwo ? "\n\nA second code went to your ".$other.' email. Type both to sign in.' : '')
            ."\n\nIf you did not try to sign in, you can ignore this email; nobody can sign in without the code."
            ."\n\nBest Regards,\n".PortalNotificationEmails::FROM_NAME;
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
