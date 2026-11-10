<?php

namespace App\Rules\Smk;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class PublishedEntry implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Empty relationship arrays are native CP values for an inactive target.
        // Presence is enforced by required_if in the link fieldset.
        if ($value === []) {
            return;
        }

        // CP validates relationship arrays; content stores a scalar for max_items: 1.
        $id = is_array($value) && count($value) === 1 ? reset($value) : $value;
        $entry = is_string($id) ? Entry::find($id) : null;

        if (! $entry || $entry->status() !== 'published'
            || $entry->locale() !== Site::current()->handle() || ! $entry->url()) {
            $fail('Wybierz istniejący, opublikowany wpis dostępny w bieżącej witrynie.');
        }
    }
}
