<?php

namespace App\Support\Smk\Builder;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Imaging\UrlBuilder;
use Statamic\Facades\Asset;

final class AssetResolver
{
    public function resolve(mixed $reference, array $use = [], bool $lcp = false): ?array
    {
        if ($reference === null || $reference === [] || $reference === '') {
            return null;
        }
        if (is_array($reference) && count($reference) === 1) {
            $reference = reset($reference);
        }
        if (! is_string($reference)) {
            return $this->missing();
        }
        // Fixed-container Assets fields persist paths, while API IDs include the container.
        $path = str_starts_with($reference, 'assets::') ? substr($reference, 8) : $reference;
        if (! preg_match('~\A[A-Za-z0-9_-][A-Za-z0-9_. /-]*\z~D', $path) || str_contains($path, '//') || collect(explode('/', $path))->contains(fn ($segment) => in_array($segment, ['', '.', '..'], true))) {
            return $this->missing();
        }
        try {
            $asset = Asset::find('assets::'.$path);
            if (! $asset || ! $asset->exists() || ! in_array($asset->mimeType(), ['image/jpeg', 'image/png', 'image/webp'], true)) {
                return $this->missing();
            }
            $w = (int) $asset->width();
            $h = (int) $asset->height();
            if ($w < 1 || $h < 1 || max($w, $h) > 6000 || $asset->size() > 8 * 1024 * 1024) {
                return $this->missing();
            }
            $decorative = ($use['decorative'] ?? false) === true || $asset->get('decorative', false) === true;
            $alt = trim((string) ($use['alt_override'] ?? '')) ?: trim((string) $asset->get('alt', ''));
            if (! $decorative && $alt === '') {
                return $this->missing();
            }
            $urls = [];
            // Intrinsic aspect ratio: focal metadata is retained; no crop or upscale.
            foreach (array_unique(array_merge(array_filter([480, 768, 1024, 1440, 1920], fn ($n) => $n <= $w), [min($w, 1920)])) as $size) {
                $urls[$size] = app(UrlBuilder::class)->build($asset, ['w' => $size, 'fit' => 'max', 'q' => 85]);
            }
            ksort($urls);

            return ['src' => $urls[array_key_last($urls)], 'srcset' => implode(', ', array_map(fn ($size, $url) => $url.' '.$size.'w', array_keys($urls), $urls)), 'width' => $w, 'height' => $h, 'alt' => $decorative ? '' : $alt, 'caption' => is_string($use['caption'] ?? $asset->get('caption')) ? ($use['caption'] ?? $asset->get('caption')) : '', 'credit' => is_string($use['credit'] ?? $asset->get('credit')) ? ($use['credit'] ?? $asset->get('credit')) : '', 'loading' => $lcp ? 'eager' : 'lazy', 'priority' => $lcp ? 'high' : 'auto'];
        } catch (\Throwable) {
            return $this->missing();
        }
    }

    private function missing(): ?array
    {
        Log::warning('SMK image omitted: unavailable or invalid media contract.');

        return null;
    }
}
