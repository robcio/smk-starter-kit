<?php

namespace App\Rules\Smk;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CanonicalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Only the configured deployment origin is trusted, never the request Host.
        if ($this->normalize($value) === null) {
            $fail('Canonical musi być absolutnym adresem HTTP(S) w skonfigurowanej domenie witryny, bez fragmentu i danych logowania.');
        }
    }

    public function normalize(mixed $url): ?string
    {
        $origin = $this->origin(config('app.url'));
        if ($origin === null || $this->origin($url) !== $origin) {
            return null;
        }
        $parts = parse_url($url);

        return $origin[0].'://'.$origin[1].($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function origin(mixed $url): ?array
    {
        if (! is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($url))
            || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (! in_array($scheme, ['http', 'https'], true) || $host === ''
            || preg_match('/[^a-z0-9.-]/', $host) || preg_match('/(?:^|\.)xn--/', $host)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || $port !== ($scheme === 'https' ? 443 : 80)) {
            return null;
        }

        return [$scheme, $host, $port];
    }
}
