<?php

namespace App\Support\Portal;

use Illuminate\Http\Request;

/**
 * Reads what Finance's forms send -- names, notes, dates, amounts, choices, record ids and flags --
 * refusing anything unreadable with a 422 the form shows as it is. Every service that writes the
 * transaction tracker reads through this one, so a figure is read the same way wherever money is
 * entered, and money is carried in whole centavos from here on.
 */
final class PortalBudgetInput
{
    public const MAX_NAME = 255;

    public const MAX_TEXT = 5000;

    // DECIMAL(15, 2): thirteen digits before the point.
    public const MAX_AMOUNT_CENTS = 999_999_999_999_999;

    public function validatedName(Request $request, string $missing, string $label, string $key = 'name'): string
    {
        $name = trim((string) $request->input($key, ''));
        if ($name === '') {
            abort(422, $missing);
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            abort(422, $label.' cannot exceed '.self::MAX_NAME.' characters.');
        }

        return $name;
    }

    public function validatedText(Request $request, string $key, string $label, int $maximum = self::MAX_TEXT): ?string
    {
        $text = trim((string) $request->input($key, ''));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maximum) {
            abort(422, $label.' cannot exceed '.$maximum.' characters.');
        }

        return $text;
    }

    public function validatedDate(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $value;
    }

    public function requiredDate(Request $request, string $key, string $missing): string
    {
        return $this->validatedDate($request, $key) ?? abort(422, $missing);
    }

    /**
     * An amount in whole centavos, or null when left blank. Pesos arrive as a number or a numeric
     * string; a negative figure, or one past what the column holds, is refused.
     */
    public function validatedAmount(Request $request, string $key, string $label): ?int
    {
        $value = $request->input($key);
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_numeric($value)) {
            abort(422, $label.' must be a number.');
        }
        $cents = (int) round(((float) $value) * 100);
        if ($cents < 0) {
            abort(422, $label.' cannot be negative.');
        }
        if ($cents > self::MAX_AMOUNT_CENTS) {
            abort(422, $label.' is too large.');
        }

        return $cents;
    }

    // Money moved or received: required, and more than nothing.
    public function movedAmount(Request $request, string $missing): int
    {
        $cents = $this->validatedAmount($request, 'amount', 'The amount');
        if ($cents === null) {
            abort(422, $missing);
        }
        if ($cents === 0) {
            abort(422, 'The amount must be more than zero.');
        }

        return $cents;
    }

    /**
     * @param  list<string>  $choices
     */
    public function validatedChoice(Request $request, string $key, array $choices, string $message): ?string
    {
        $value = trim((string) $request->input($key, ''));
        if ($value === '') {
            return null;
        }
        if (! in_array($value, $choices, true)) {
            abort(422, $message);
        }

        return $value;
    }

    public function validatedId(Request $request, string $key, string $message): ?int
    {
        $value = $request->input($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value <= 0 || (string) (int) $value !== trim((string) $value)) {
            abort(422, $message);
        }

        return (int) $value;
    }

    public function requiredId(Request $request, string $key, string $missing): int
    {
        return $this->validatedId($request, $key, $missing) ?? abort(422, $missing);
    }

    public function validatedFlag(Request $request, string $key, string $message): bool
    {
        $flag = filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($flag === null) {
            abort(422, $message);
        }

        return $flag;
    }

    // An overspend or overdraw is refused unless the person saw the shortfall and said yes.
    public function confirmed(Request $request, string $key): bool
    {
        return $request->has($key) && $this->validatedFlag($request, $key, 'Say whether to go past the balance with true or false.');
    }

    public function nameOr(mixed $value, string $fallback): string
    {
        $name = trim((string) ($value ?? ''));

        return $name === '' ? $fallback : $name;
    }

    public function peso(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'₱'.number_format(abs($cents) / 100, 2);
    }

    public function decimal(?int $cents): ?string
    {
        return $cents === null ? null : number_format($cents / 100, 2, '.', '');
    }

    public function amount(?int $cents): ?float
    {
        return $cents === null ? null : round($cents / 100, 2);
    }

    // DECIMAL arrives as a string, and floats drift on sums, so money is compared in centavos.
    public function cents(mixed $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }
}
