<?php

namespace App\Support\Portal;

/**
 * Employee Registration's input sanitizing (2026-10-12). Every detail a candidate sends is cleaned
 * the way the portal cleans it while they type -- markup, control and invisible characters dropped,
 * runs of spaces collapsed -- then held to its field's shape: names in letters, numbers in digits of
 * the length their ID carries, a SWIFT code as banks write one. New Employee Data only ever receives
 * plain text an HR reviewer can trust.
 */
final class PortalRegistrationInput
{
    private const NAME = 'name';

    private const TEXT = 'text';

    private const PHONE = 'phone';

    private const NUMBER = 'number';

    private const SWIFT = 'swift';

    private const DATE = 'date';

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
        'address' => [self::TEXT, 'home address'],
        'phoneNumber' => [self::PHONE, 'phone number', 10, 13],
        'emergencyContactPersonName' => [self::NAME, 'contact person'],
        'emergencyContactNumber' => [self::PHONE, 'contact number', 10, 13],
        'emergencyContactAddress' => [self::TEXT, 'contact address'],
        'taxIdentificationNo' => [self::NUMBER, 'TIN', 9, 14],
        'philhealthNo' => [self::NUMBER, 'PhilHealth No.', 12, 12],
        'sssNo' => [self::NUMBER, 'SSS No.', 10, 10],
        'hdmfNo' => [self::NUMBER, 'Pag-IBIG (HDMF) No.', 12, 12],
        'bankName' => [self::TEXT, 'bank name'],
        'bankAccountNumber' => [self::NUMBER, 'account number', 6, 20],
        'bankSwiftCode' => [self::SWIFT, 'SWIFT code'],
    ];

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
            self::NAME => $this->isName($clean)
                ? $clean
                : abort(422, ucfirst($label).' can only have letters, spaces, periods, apostrophes and hyphens.'),
            self::TEXT => preg_match('/^[\p{L}\p{M}\p{N} .,#\'\-\/()&:]+$/u', $clean) === 1 && preg_match('/[\p{L}\p{N}]/u', $clean) === 1
                ? $clean
                : abort(422, ucfirst($label).' can only have letters, numbers, spaces and . , # \' - / ( ) & :'),
            self::PHONE => $this->digits($clean, '/^\+?[0-9 ()\-]+$/', $rule[2] ?? 0, $rule[3] ?? 0)
                ? $clean
                : abort(422, 'Enter a '.$label.' with 10 to 13 digits.'),
            self::NUMBER => $this->digits($clean, '/^[0-9][0-9 \-]*$/', $rule[2] ?? 0, $rule[3] ?? 0)
                ? $clean
                : abort(422, $this->digitsMessage($label, $rule[2] ?? 0, $rule[3] ?? 0)),
            self::SWIFT => $this->swift($clean),
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

    private function isName(string $value): bool
    {
        return preg_match('/^[\p{L}\p{M} .\'\-]+$/u', $value) === 1 && preg_match('/\p{L}/u', $value) === 1;
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
        if (preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $code) !== 1) {
            abort(422, 'Enter an 8 or 11 character SWIFT code, such as BNORPHMM.');
        }

        return $code;
    }

    // A real calendar day, written YYYY-MM-DD; a birthday is never ahead of today.
    private function date(string $field, string $value, string $label): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '1900-01-01') {
            abort(422, 'Choose a real '.$label.'.');
        }
        if ($field === 'dateOfBirth' && $value > now()->toDateString()) {
            abort(422, 'Your date of birth cannot be in the future.');
        }

        return $value;
    }
}
