<?php

namespace App\Support\Smk;

use Statamic\Contracts\Globals\GlobalRepository;
use Statamic\Facades\Site;

class SettingsResolver
{
    public function __construct(private GlobalRepository $globals) {}

    /** Public data only. Asset/entry references stay native; rendering is a later stage. */
    public function resolve(?string $site = null): array
    {
        $site ??= Site::current()->handle();
        $defaults = [
            'site' => ['site_name' => 'SMK Starter Kit', 'tagline' => null, 'email' => null, 'phone' => null, 'address' => null, 'social_links' => []],
            'brand' => ['logo' => null, 'logo_alt' => null, 'favicon' => null],
            'header' => ['sticky' => false, 'cta' => []],
            'footer' => ['description' => null, 'copyright_notice' => null],
            'seo' => ['title_suffix' => null, 'default_description' => null, 'default_og_image' => null],
        ];
        $settings = [];

        foreach ($defaults as $handle => $fields) {
            $data = $this->globals->findByHandle($handle)?->in($site)?->data()->all() ?? [];
            foreach ($fields as $key => $fallback) {
                $value = $data[$key] ?? $fallback;
                $settings[$handle][$key] = is_array($fallback)
                    ? $this->links($value, $key === 'cta' ? 1 : 8)
                    : (is_bool($fallback) ? $value === true : $this->text($value, $fallback));
            }
        }

        $settings['brand']['logo_alt'] ??= $settings['site']['site_name'];
        $settings['footer']['copyright_notice'] ??= date('Y').' '.$settings['site']['site_name'];

        return ['settings' => $settings];
    }

    private function text(mixed $value, ?string $fallback): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }

    private function links(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_map(fn ($link) => array_replace(['new_window' => false], array_intersect_key($link, array_flip([
            'label', 'destination_type', 'entry', 'url', 'new_window',
        ]))), array_slice(array_values(array_filter($value, 'is_array')), 0, $limit));
    }
}
