<?php

namespace App\Support\Smk;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Asset;
use Statamic\Facades\Site;

class ChromeResolver
{
    public function __construct(private SettingsResolver $settings, private NavigationResolver $navigation, private LinkResolver $links) {}

    public function resolve(?string $currentId = null): array
    {
        $settings = $this->settings->resolve()['settings'];
        $logo = null;
        if ($reference = $settings['brand']['logo']) {
            $asset = ! str_contains($reference, '::') || str_starts_with($reference, 'assets::')
                ? Asset::find(str_contains($reference, '::') ? $reference : 'assets::'.$reference) : null;
            if ($asset && $asset->exists() && in_array($asset->mimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)
                && $asset->width() > 0 && $asset->height() > 0 && $this->links->safeUrl($asset->url())) {
                $logo = ['url' => $asset->url(), 'width' => $asset->width(), 'height' => $asset->height(), 'alt' => $settings['brand']['logo_alt']];
            } else {
                Log::warning('SMK logo unavailable; using site name.');
            }
        }

        return ['settings' => $settings, 'navigation' => $this->navigation->resolve($currentId),
            'home_url' => Site::current()->url(), 'logo' => $logo,
            'cta' => isset($settings['header']['cta'][0]) ? $this->links->resolve($settings['header']['cta'][0]) : null];
    }
}
