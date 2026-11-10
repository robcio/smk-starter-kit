<?php

namespace App\Support\Smk;

use App\Rules\Smk\CanonicalUrl;
use App\Support\Smk\Builder\AssetResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Events\EntrySaving;
use Symfony\Component\HttpFoundation\Response;

final class SeoResolver
{
    public function handle(EntrySaving $event): void
    {
        if ($event->entry->collectionHandle() !== 'pages') {
            return;
        }
        $override = $event->entry->get('seo_canonical');
        if ($override === null || $override === '') {
            return;
        }
        $validator = Validator::make(['seo_canonical' => $override], ['seo_canonical' => ['string', 'max:2048', new CanonicalUrl]]);
        $validator->validate();
        if (! $this->queryKnown(parse_url($override, PHP_URL_QUERY) ?? '')) {
            throw ValidationException::withMessages(['seo_canonical' => 'Canonical strony pages nie może zawierać nieustalonych lub powielonych parametrów.']);
        }
    }

    // Pages has no semantic query parameters. Unknowns/arrays/duplicates fail closed.
    // Tracking names are percent-decoded and compared in ASCII lowercase.
    public function queryKnown(string $query): bool
    {
        $seen = [];
        foreach ($query === '' ? [] : explode('&', $query) as $part) {
            $key = strtolower(urldecode(explode('=', $part, 2)[0]));
            if ($key === '' || ! preg_match('/\A[a-z0-9_]+\z/D', $key) || isset($seen[$key]) || (! str_starts_with($key, 'utm_') && ! in_array($key, ['gclid', 'dclid', 'fbclid', 'msclkid'], true)) || str_contains($key, '[')) {
                return false;
            }
            $seen[$key] = true;
        }

        return true;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function resolve(?Entry $entry, Request $request, Response $response): array
    {
        $settings = app(SettingsResolver::class)->resolve($entry?->locale())['settings'];
        $siteName = $this->text($settings['site']['site_name']) ?? 'SMK Starter Kit';
        $status = $response->getStatusCode();
        $preview = $request->isLivePreview() || $request->has('token') || $request->headers->has('X-Statamic-Token') || $response->headers->has('X-Statamic-Live-Preview');
        $public = $status === 200 && $entry && $entry->collectionHandle() === 'pages' && $entry->status() === 'published' && ! $entry->private() && ! $entry->getProtectionScheme() && ! config('statamic.protect.default') && ! $response->headers->has('X-Statamic-Protected');
        $model = ['title' => $preview ? 'Preview' : ($status >= 400 ? $status.' — '.(Response::$statusTexts[$status] ?? 'Error') : $siteName), 'description' => null, 'robots' => 'noindex, nofollow', 'canonical' => null, 'social' => false, 'site_name' => $siteName, 'image' => null];
        if ($preview || ! $public) {
            return $model;
        }
        $title = $this->text($entry->get('seo_title')) ?? $this->text($entry->value('title')) ?? $siteName;
        $suffix = $this->text($settings['seo']['title_suffix']);
        $model['title'] = $suffix && ! str_ends_with($title, $suffix) ? $title.' — '.$suffix : $title;
        $model['description'] = $this->text($entry->get('seo_description')) ?? $this->text($settings['seo']['default_description']);
        $model['social'] = true;
        $base = (new CanonicalUrl)->normalize(config('app.url'));
        $origin = $base ? parse_url($base, PHP_URL_SCHEME).'://'.parse_url($base, PHP_URL_HOST) : null;
        if ($base && ! in_array(parse_url($base, PHP_URL_PATH), [null, '', '/'], true)) {
            $origin = null; // Subdirectory deployments need an explicit route/base-path policy.
        }
        $production = config('app.env') === 'production';
        if ($production && ($origin === null || ! str_starts_with($origin, 'https://') || in_array(parse_url($origin, PHP_URL_HOST), ['localhost', '127.0.0.1'], true) || str_ends_with(parse_url($origin, PHP_URL_HOST), '.test') || str_ends_with(parse_url($origin, PHP_URL_HOST), '.localhost') || str_ends_with(parse_url($origin, PHP_URL_HOST), '.local') || filter_var(parse_url($origin, PHP_URL_HOST), FILTER_VALIDATE_IP))) {
            $origin = null;
        }
        $known = $this->queryKnown($request->server('QUERY_STRING', ''));
        $uri = $entry->uri();
        $self = $origin && is_string($uri) && str_starts_with($uri, '/') && ! str_starts_with($uri, '//') ? (new CanonicalUrl)->normalize($origin.$uri) : null;
        if (! $origin || ! $known || ! $self) {
            Log::warning('SMK SEO canonical omitted: untrusted origin or unclassified representation.');
        } else {
            $model['canonical'] = $self;
            if ($override = $this->text($entry->get('seo_canonical'))) {
                $normalized = (new CanonicalUrl)->normalize($override);
                if ($normalized && $this->queryKnown(parse_url($normalized, PHP_URL_QUERY) ?? '')) {
                    $model['canonical'] = strtok($normalized, '?');
                } else {
                    Log::warning('SMK SEO canonical override rejected; using resolved self URL.');
                }
            }
        }
        if ($production && $known && $self) {
            $model['robots'] = in_array($entry->get('seo_noindex'), [null, false], true) ? 'index, follow' : 'noindex, follow';
        }
        if ($origin) {
            foreach ([$entry->get('seo_image'), $settings['seo']['default_og_image']] as $reference) {
                $media = app(AssetResolver::class)->resolve($reference);
                if (! $media || $media['alt'] === '') {
                    continue;
                }
                // Native local Glide URL: rebase its path onto trusted origin, never request Host.
                $path = parse_url($media['src'], PHP_URL_PATH);
                if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
                    continue;
                }
                $query = parse_url($media['src'], PHP_URL_QUERY);
                $model['image'] = ['url' => $origin.$path.($query ? '?'.$query : ''), 'alt' => $media['alt']];
                break;
            }
        }

        return $model;
    }
}
