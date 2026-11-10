<?php

namespace App\Support\Smk;

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

class NavigationResolver
{
    public function __construct(private LinkResolver $links) {}

    public function resolve(?string $currentId = null): array
    {
        $site = Site::current()->handle();
        $menus = [];
        foreach (['main' => 2, 'footer' => 1, 'legal' => 1] as $handle => $depth) {
            $tree = Nav::findByHandle($handle)?->in($site)?->tree() ?? [];
            $menus[$handle] = $this->items($tree, $depth, $handle, $currentId);
        }

        return $menus;
    }

    private function items(array $tree, int $depth, string $prefix, ?string $currentId): array
    {
        $items = [];
        foreach ($tree as $index => $node) {
            if (! is_array($node)) {
                continue;
            }
            $key = $prefix.'-'.($index + 1);
            $children = $node['children'] ?? [];
            if ($depth === 1 && ! empty($children)) {
                Log::warning('SMK navigation exceeds supported depth; deeper branches omitted.');
            }
            $children = $depth > 1 && is_array($children) ? $this->items($children, $depth - 1, $key, $currentId) : [];
            $reference = $node['entry'] ?? null;
            $label = is_string($node['title'] ?? null) ? trim($node['title']) : '';
            $newWindow = ($node['data']['new_window'] ?? false) === true;
            if ($reference !== null) {
                $entry = is_string($reference) ? Entry::find($reference) : null;
                if (! $entry || $entry->locale() !== Site::current()->handle() || $entry->status() !== 'published') {
                    Log::warning('SMK navigation branch omitted: unavailable entry.');

                    continue;
                }
                $entryTitle = $entry->value('title');
                $label = $label !== '' ? $label : (is_string($entryTitle) ? trim($entryTitle) : '');
                $link = $this->links->resolve(['label' => $label, 'destination_type' => 'entry', 'entry' => $reference, 'new_window' => $newWindow]);
            } elseif (is_string($node['url'] ?? null) && $node['url'] !== '') {
                $link = $this->links->resolve(['label' => $label, 'destination_type' => 'url', 'url' => $node['url'], 'new_window' => $newWindow]);
            } else {
                $link = null;
            }
            // A deliberate text-only group may own valid children, but is never an empty link.
            if (! $link && ($reference !== null || ! empty($node['url']) || $label === '' || ! $children)) {
                if ($label !== '' && $reference === null && empty($node['url']) && ! $children) {
                    Log::warning('SMK navigation leaf omitted: missing target.');
                }

                continue;
            }
            $current = $link && ($reference !== null
                ? $currentId !== null && $reference === $currentId
                : $this->isCurrentUrl($link['url']));
            $items[] = array_merge($link ?? ['label' => $label, 'url' => null, 'external' => false, 'new_window' => false], [
                'current' => (bool) $current,
                'link' => $link ? array_merge($link, ['current' => (bool) $current]) : null,
                'active' => (bool) $current || collect($children)->contains('active', true),
                'children' => $children, 'panel_id' => 'smk-nav-'.$key,
            ]);
        }

        return $items;
    }

    private function isCurrentUrl(string $url): bool
    {
        if ($this->links->external($url) || str_starts_with($url, '#') || preg_match('~^(mailto|tel):~', $url)
            || parse_url($url, PHP_URL_FRAGMENT) !== null || parse_url($url, PHP_URL_QUERY) !== null) {
            return false;
        }
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && '/'.trim($path, '/') === '/'.trim(request()->path(), '/');
    }
}
