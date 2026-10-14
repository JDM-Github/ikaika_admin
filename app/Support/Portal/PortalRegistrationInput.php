<?php

namespace App\Support\Portal;

/**
 * Employee Registration's input sanitizing (2026-10-12). Every detail a candidate sends is cleaned
 * the way the portal cleans it while they type -- markup, control and invisible characters dropped,
 * runs of space collapsed -- then held to its field's shape: names in letters, numbers in digits of
 * the length their ID carries, a SWIFT code as banks write one, a blood type from the list. Mashed
 * keys are refused too (2026-10-12): a letter three times running in a name, a number of one digit.
 * New Employee Data only ever receives plain text an HR reviewer can trust.
 *
 * An address comes in parts since 2026-10-13 (user): its street, and -- outside the Philippines, where
 * nothing is picked from a list -- its province, city and barangay as typed. A start date is today or
 * later, within a year.
 */
final class PortalRegistrationInput
{
    /** @var list<string> */
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private const NAME = 'name';

    private const TEXT = 'text';

    private const PHONE = 'phone';

    private const NUMBER = 'number';

    private const SWIFT = 'swift';

    private const DATE = 'date';

    private const BLOOD = 'blood';

    /**
     * Each detail's kind, the label its messages use and, for a number, the digits it must carry.
     *
     * @var array<string, array{0: string, 1: string, 2?: int, 3?: int}>
     */
    private const FIELDS = [
        'firstName' => [self::NAME, 'first name'],
        'middleName' => [self::NAME, 'middle name'],
        'lastName' => [self::NAME, 'last name'],
        'nickname' => [self::NAME, 'nickname'],
        'startDate' => [self::DATE, 'start date'],
        'dateOfBirth' => [self::DATE, 'date of birth'],
        'bloodType' => [self::BLOOD, 'blood type'],
        'address' => [self::TEXT, 'home address'],
        'phoneNumber' => [self::PHONE, 'phone number', 10, 13],
        'emergencyContactPersonName' => [self::NAME, 'contact person'],
        'emergencyContactNumber' => [self::PHONE, 'contact number', 10, 13],
        'emergencyContactAddress' => [self::TEXT, 'contact address'],
        'taxIdentificationNo' => [self::NUMBER, 'TIN', 9, 14],
        'philhealthNo' => [self::NUMBER, 'PhilHealth No.', 12, 12],
        'sssNo' => [self::NUMBER, 'SSS No.', 10, 10],
        'hdmfNo' => [self::NUMBER, 'Pag-IBIG (HDMF) No.', 12, 12],
        'nationalIdNo' => [self::NUMBER, 'National ID No.', 16, 16],
        'bankName' => [self::TEXT, 'bank name'],
        'bankAccountNumber' => [self::NUMBER, 'account number', 6, 20],
        'bankSwiftCode' => [self::SWIFT, 'SWIFT code'],
        'addressCountry' => [self::TEXT, 'country'],
        'addressProvince' => [self::TEXT, 'province'],
        'addressCity' => [self::TEXT, 'city'],
        'addressBarangay' => [self::TEXT, 'barangay'],
        'addressStreet' => [self::TEXT, 'house number and street'],
        'emergencyContactCountry' => [self::TEXT, 'contact country'],
        'emergencyContactProvince' => [self::TEXT, 'contact province'],
        'emergencyContactCity' => [self::TEXT, 'contact city'],
        'emergencyContactBarangay' => [self::TEXT, 'contact barangay'],
        'emergencyContactStreet' => [self::TEXT, 'contact house number and street'],
    ];

    /** The letters a full name must carry at least. */
    private const MIN_LETTERS = ['firstName' => 2, 'lastName' => 2, 'emergencyContactPersonName' => 2];

    /** The characters an address or a bank name must run to at least. */
    private const MIN_TEXT = [
        'address' => 10, 'emergencyContactAddress' => 10, 'bankName' => 2,
        'addressStreet' => 3, 'emergencyContactStreet' => 3,
        'addressProvince' => 2, 'addressCity' => 2, 'addressBarangay' => 2, 'addressCountry' => 2,
        'emergencyContactProvince' => 2, 'emergencyContactCity' => 2, 'emergencyContactBarangay' => 2, 'emergencyContactCountry' => 2,
    ];

    /** The whole lines an address is written as, which run to a full address with a space in them. */
    private const ADDRESS_LINES = ['address', 'emergencyContactAddress'];

    private const ADULT_YEARS = 18;

    private const OLDEST_YEARS = 100;

    /**
     * The details sent, cleaned and checked; a blank one reads as null. A field this form does not
     * know passes through untouched, for the caller's own rules to refuse.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public function cleaned(array $details): array
    {
        $clean = [];
        foreach ($details as $field => $value) {
            $clean[$field] = isset(self::FIELDS[$field]) && is_string($value) ? $this->detail($field, $value) : $value;
        }

        return $clean;
    }

    // A candidate's name as an administrator types it on the link: plain text in letters, or blank.
    public function candidateName(string $value): string
    {
        $clean = $this->plain($value, 'candidate name');
        if ($clean !== '' && ! $this->isName($clean)) {
            abort(422, 'The candidate name can only have letters, spaces, periods, apostrophes and hyphens.');
        }

        return $clean;
    }

    private function detail(string $field, string $value): ?string
    {
        $rule = self::FIELDS[$field];
        [$kind, $label] = $rule;
        $clean = $this->plain($value, $label);
        if ($clean === '') {
            return null;
        }

        return match ($kind) {
            self::NAME => $this->name($field, $clean, $label),
            self::TEXT => $this->text($field, $clean, $label),
            self::PHONE => $this->phone($clean, $label, $rule[2] ?? 0, $rule[3] ?? 0),
            self::NUMBER => $this->number($clean, $label, $rule[2] ?? 0, $rule[3] ?? 0),
            self::SWIFT => $this->swift($clean),
            self::BLOOD => $this->bloodType($clean),
            default => $this->date($field, $clean, $label),
        };
    }

    // Markup, control and invisible characters go, and every run of space becomes one.
    private function plain(string $value, string $label): string
    {
        $clean = preg_replace(['/[\p{Cc}\p{Zl}\p{Zp}]+/u', '/\p{Cf}+/u', '/\s+/u'], [' ', '', ' '], strip_tags($value));
        if ($clean === null) {
            abort(422, ucfirst($label).' has characters we cannot read.');
        }

        return trim($clean);
    }

    private function name(string $field, string $value, string $label): string
    {
        if (! $this->isName($value)) {
            abort(422, ucfirst($label).' can only have letters, spaces, periods, apostrophes and hyphens.');
        }
        if ($this->repeats($value, 3)) {
            abort(422, ucfirst($label).' has the same letter three times in a row. Check it.');
        }
        $letters = preg_match_all('/\p{L}/u', $value);
        if ($letters < (self::MIN_LETTERS[$field] ?? 1)) {
            abort(422, ucfirst($label).' needs at least '.self::MIN_LETTERS[$field].' letters.');
        }

        return $value;
    }

    private function text(string $field, string $value, string $label): string
    {
        if (preg_match('/^[\p{L}\p{M}\p{N} .,#\'\-\/()&:]+$/u', $value) !== 1 || preg_match('/[\p{L}\p{N}]/u', $value) !== 1) {
            abort(422, ucfirst($label).' can only have letters, numbers, spaces and . , # \' - / ( ) & :');
        }
        if ($this->repeats($value, 4)) {
            abort(422, ucfirst($label).' has the same letter four times in a row. Check it.');
        }
        $shortest = self::MIN_TEXT[$field] ?? 1;
        $isAddress = in_array($field, self::ADDRESS_LINES, true);
        if (mb_strlen($value) < $shortest || ($isAddress && ! str_contains($value, ' '))) {
            abort(422, $isAddress
                ? 'Write the full '.$label.', at least '.$shortest.' characters.'
                : ucfirst($label).' needs at least '.$shortest.' characters.');
        }

        return $value;
    }

    private function phone(string $value, string $label, int $min, int $max): string
    {
        if (! $this->digits($value, '/^\+?[0-9 ()\-]+$/', $min, $max)) {
            abort(422, 'Enter a '.$label.' with 10 to 13 digits.');
        }
        if ($this->sameDigit($value) || preg_match('/(\d)\1{6}/', (string) preg_replace('/\D/', '', $value)) === 1) {
            abort(422, ucfirst($label).' repeats the same digit too many times. Check it.');
        }

        return $value;
    }

    private function number(string $value, string $label, int $min, int $max): string
    {
        if (! $this->digits($value, '/^[0-9][0-9 \-]*$/', $min, $max)) {
            abort(422, $this->digitsMessage($label, $min, $max));
        }
        if ($this->sameDigit($value)) {
            abort(422, ucfirst($label).' cannot be one digit repeated. Check it.');
        }

        return $value;
    }

    private function isName(string $value): bool
    {
        return preg_match('/^[\p{L}\p{M} .\'\-]+$/u', $value) === 1 && preg_match('/\p{L}/u', $value) === 1;
    }

    // A letter typed the given number of times running, whatever its case.
    private function repeats(string $value, int $times): bool
    {
        return preg_match('/(\p{L})\1{'.($times - 1).'}/iu', $value) === 1;
    }

    private function sameDigit(string $value): bool
    {
        return preg_match('/^(\d)\1*$/', (string) preg_replace('/\D/', '', $value)) === 1;
    }

    private function digits(string $value, string $shape, int $min, int $max): bool
    {
        $count = strlen((string) preg_replace('/\D/', '', $value));

        return preg_match($shape, $value) === 1 && $count >= $min && $count <= $max;
    }

    private function digitsMessage(string $label, int $min, int $max): string
    {
        $count = $min === $max ? $min.' digits' : $min.' to '.$max.' digits';

        return 'Enter your '.$label.': '.$count.', numbers and dashes only.';
    }

    // Six letters for the bank and country, two for the place, and three more for a branch.
    private function swift(string $value): string
    {
        $code = strtoupper(str_replace(' ', '', $value));
        if (preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $code) !== 1 || preg_match('/^(.)\1*$/', $code) === 1) {
            abort(422, 'Enter an 8 or 11 character SWIFT code, such as BNORPHMM.');
        }

        return $code;
    }

    private function bloodType(string $value): string
    {
        $type = strtoupper(str_replace([' ', "\u{2212}"], ['', '-'], $value));
        if (! in_array($type, self::BLOOD_TYPES, true)) {
            abort(422, 'Choose your blood type.');
        }

        return $type;
    }

    // A real calendar day, written YYYY-MM-DD: a candidate of working age, starting within a year of today.
    private function date(string $field, string $value, string $label): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '1900-01-01') {
            abort(422, 'Choose a real '.$label.'.');
        }
        $today = now()->startOfDay();
        if ($field === 'dateOfBirth') {
            if ($value > $today->toDateString()) {
                abort(422, 'Your date of birth cannot be in the future.');
            }
            if ($value > $today->copy()->subYears(self::ADULT_YEARS)->toDateString()) {
                abort(422, 'You must be at least '.self::ADULT_YEARS.' years old to register.');
            }
            if ($value < $today->copy()->subYears(self::OLDEST_YEARS)->toDateString()) {
                abort(422, 'Check your date of birth.');
            }
        }
        if ($field === 'startDate' && $value < $today->toDateString()) {
            abort(422, 'Your start date cannot be in the past.');
        }
        if ($field === 'startDate' && $value > $today->copy()->addYear()->toDateString()) {
            abort(422, 'Your start date must be within a year of today.');
        }

        return $value;
    }
}
