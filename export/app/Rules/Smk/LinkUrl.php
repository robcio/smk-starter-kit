<?php

namespace App\Rules\Smk;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class LinkUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->isSafe($value)) {
            $fail('Podaj bezpieczny adres HTTP(S), mailto, tel, ścieżkę lokalną lub kotwicę.');
        }
    }

    private function isSafe(string $value): bool
    {
        // Reject ambiguous browser parsing, including percent-encoded controls/backslashes.
        $decoded = rawurldecode($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)) {
            return false;
        }

        if (str_starts_with($decoded, '/')) {
            return ! str_starts_with($decoded, '//');
        }

        if (str_starts_with($value, '#')) {
            return strlen($value) > 1;
        }

        if (str_starts_with($value, 'mailto:')) {
            return filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) !== false;
        }

        if (str_starts_with($value, 'tel:')) {
            return (bool) preg_match('/^tel:\+?[0-9][0-9().-]*$/', $value);
        }

        $parts = parse_url($value);

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }
}
