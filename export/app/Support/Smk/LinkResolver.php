<?php

namespace App\Support\Smk;

use App\Rules\Smk\LinkUrl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class LinkResolver
{
    public function resolve(array $data, ?string $site = null): ?array
    {
        $site ??= Site::current()->handle();
        $label = is_string($data['label'] ?? null) ? trim($data['label']) : '';
        $id = $data['entry'] ?? null;
        if (is_array($id) && count($id) === 1) {
            $id = reset($id);
        }
        $url = $data['url'] ?? null;
        if (($data['destination_type'] ?? null) === 'entry' && empty($url) && is_string($id)) {
            $entry = Entry::find($id);
            if (! $entry || $entry->locale() !== $site || $entry->status() !== 'published') {
                return $this->invalid();
            }
            $url = $entry->url();
        } elseif (($data['destination_type'] ?? null) !== 'url' || ! empty($id)) {
            return $this->invalid();
        }
        if ($label === '' || ! is_string($url) || ! $this->safeUrl($url)) {
            return $this->invalid();
        }

        return ['label' => $label, 'url' => $url, 'external' => $this->external($url),
            'new_window' => ($data['new_window'] ?? false) === true];
    }

    public function safeUrl(string $url): bool
    {
        return Validator::make(['url' => $url], ['url' => new LinkUrl])->passes();
    }

    public function external(string $url): bool
    {
        if (! preg_match('~^https?://~i', $url)) {
            return false;
        }
        $origin = fn ($value) => [strtolower(parse_url($value, PHP_URL_SCHEME) ?? ''),
            strtolower(parse_url($value, PHP_URL_HOST) ?? ''),
            parse_url($value, PHP_URL_PORT) ?? (strtolower(parse_url($value, PHP_URL_SCHEME) ?? '') === 'https' ? 443 : 80)];

        return $origin($url) !== $origin(Site::current()->absoluteUrl());
    }

    private function invalid(): ?array
    {
        // Do not log editorial values, URLs or unpublished titles.
        Log::warning('SMK link omitted: invalid or unavailable target.');

        return null;
    }
}
