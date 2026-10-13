<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Sign-in by email (2026-10-12): the employee types their work email and a 6-digit code goes to it.
 * Work email only, by the supervisor's call -- a personal address neither starts a sign-in nor gets
 * a code. The answer still lists where the code went, masked, so the screen draws its code box.
 * (login_otps.personal_code_hash is left over from the two-inbox version and stays empty.)
 */
final class PortalEmailLogin
{
    public const KIND_WORK = 'work';

    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const RESEND_SECONDS = 60;

    // Unknown and inactive read the same, so the answer says nothing about whose address it is.
    private const NO_ACCOUNT = 'No active portal account uses that email.';

    /**
     * Mails a fresh code to the work email and says where it went.
     *
     * @return list<array{kind: string, hint: string}>
     */
    public function start(string $email): array
    {
        $employee = $this->employeeFor($email);
        $address = strtolower(trim((string) $employee->email));

        $existing = $this->connection()->table('login_otps')->where('employee_id', $employee->getKey())->first();
        if ($existing !== null && strtotime((string) $existing->issued_at) > now()->getTimestamp() - self::RESEND_SECONDS) {
            abort(429, 'Wait a moment before asking for a new code.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->connection()->table('login_otps')->updateOrInsert(
            ['employee_id' => $employee->getKey()],
            [
                'work_code_hash' => Hash::make($code),
                'personal_code_hash' => null,
                'attempts' => 0,
                'issued_at' => now(),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ],
        );

        PortalLetters::sendAfterResponse($address, 'Your IKAIKA Portal sign-in code', $this->letter((string) $employee->first_name, $code));

        return [['kind' => self::KIND_WORK, 'hint' => self::mask($address)]];
    }

    /**
     * Checks the code; the employee to sign in when it matches.
     *
     * @param  array<string, mixed>  $codes
     */
    public function verify(string $email, array $codes): Employee
    {
        $employee = $this->employeeFor($email);
        $row = $this->connection()->table('login_otps')->where('employee_id', $employee->getKey())->first();
        if ($row === null || $row->work_code_hash === null || strtotime((string) $row->expires_at) <= now()->getTimestamp()) {
            abort(422, 'Ask for a new code.');
        }
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            abort(422, 'Too many tries. Ask for a new code.');
        }
        if (! Hash::check(trim((string) ($codes[self::KIND_WORK] ?? '')), (string) $row->work_code_hash)) {
            $this->connection()->table('login_otps')->where('employee_id', $employee->getKey())->increment('attempts');
            abort(422, 'That code is not right.');
        }
        $this->connection()->table('login_otps')->where('employee_id', $employee->getKey())->delete();

        return $employee;
    }

    // "jd***@ikaikabim.com": enough to say which inbox, not enough to read the address.
    public static function mask(string $address): string
    {
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }

    // The active employee whose work email this is; anything else reads as no account.
    private function employeeFor(string $email): Employee
    {
        $email = strtolower(trim($email));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Type a valid email address.');
        }

        $employee = Employee::query()
            ->select(PortalManageUserPresenter::sessionColumns())
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();
        if ($employee === null || strtolower(trim((string) $employee->status)) !== 'active') {
            abort(422, self::NO_ACCOUNT);
        }

        return $employee;
    }

    private function letter(string $firstName, string $code): string
    {
        return PortalLetters::body(
            $firstName,
            'Your IKAIKA Portal sign-in code is **'.$code.'**. It is good for '.self::TTL_MINUTES.' minutes.'
                ."\n\nIf you did not try to sign in, you can ignore this email; nobody can sign in without the code.",
        );
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
