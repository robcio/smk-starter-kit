<?php

namespace App\Support\Smk\Builder;

use App\Support\Smk\LinkResolver;
use Illuminate\Support\Facades\Log;
use Statamic\Fields\Field;

final class BardContent
{
    public function valid(mixed $nodes, int $depth = 0, ?string $site = null, bool $requireAvailable = true): bool
    {
        if (! is_array($nodes) || ! array_is_list($nodes) || $depth > 12 || count($nodes) > 500) {
            return false;
        }
        foreach ($nodes as $node) {
            if (! is_array($node) || array_diff(array_keys($node), ['type', 'text', 'content', 'attrs', 'marks'])) {
                return false;
            }
            $type = $node['type'] ?? null;
            if (! in_array($type, ['paragraph', 'heading', 'text', 'bulletList', 'orderedList', 'listItem', 'hardBreak'], true)) {
                return false;
            }
            $attrs = $node['attrs'] ?? [];
            $allowedAttrs = match ($type) {
                'heading' => ['level'], 'orderedList' => ['start'], default => []
            };
            if (! is_array($attrs) || array_diff(array_keys($attrs), $allowedAttrs) || ($type === 'heading' && ! in_array($attrs['level'] ?? null, [2, 3], true)) || ($type === 'orderedList' && isset($attrs['start']) && (! is_int($attrs['start']) || $attrs['start'] < 1 || $attrs['start'] > 9999))) {
                return false;
            }
            if ($type === 'text' && ! is_string($node['text'] ?? null)) {
                return false;
            }
            if (isset($node['marks']) && (! is_array($node['marks']) || ! array_is_list($node['marks']))) {
                return false;
            }
            foreach ($node['marks'] ?? [] as $mark) {
                if (! is_array($mark) || array_diff(array_keys($mark), ['type', 'attrs']) || ! in_array($mark['type'] ?? '', ['bold', 'italic', 'link'], true)) {
                    return false;
                }
                if ($mark['type'] === 'link') {
                    $a = $mark['attrs'] ?? [];
                    if (! $this->validLink($a, $site, $requireAvailable)) {
                        return false;
                    }
                } elseif (! empty($mark['attrs'])) {
                    return false;
                }
            }
            if (isset($node['content']) && ! $this->valid($node['content'], $depth + 1, $site, $requireAvailable)) {
                return false;
            }
        }

        return true;
    }

    public function hasText(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (is_string($node['text'] ?? null) && trim($node['text']) !== '') {
                return true;
            }
            if (is_array($node['content'] ?? null) && $this->hasText($node['content'])) {
                return true;
            }
        }

        return false;
    }

    private function validLink(mixed $attrs, ?string $site, bool $requireAvailable): bool
    {
        if (! is_array($attrs) || array_diff(array_keys($attrs), ['href', 'target', 'rel', 'title']) || ! is_string($attrs['href'] ?? null) || $attrs['href'] === '' || ! in_array($attrs['target'] ?? null, [null, '_self'], true)) {
            return false;
        }
        foreach (['title', 'rel'] as $key) {
            if (isset($attrs[$key]) && (! is_string($attrs[$key]) || mb_strlen($attrs[$key]) > 3000 || preg_match('/[\x00-\x1F\x7F]/', $attrs[$key]))) {
                return false;
            }
        }
        if (preg_match('~\Astatamic://entry::[A-Za-z0-9_-]+(?:[?#].*)?\z~D', $attrs['href'])) {
            return ! $requireAvailable || $this->href($attrs['href'], $site) !== null;
        }

        return app(LinkResolver::class)->safeUrl($attrs['href']);
    }

    private function href(string $href, ?string $site): ?string
    {
        if (preg_match('~\Astatamic://entry::([A-Za-z0-9_-]+)([?#].*)?\z~D', $href, $match)) {
            // Reuse the same publication/site policy as Core CTA/navigation links.
            $link = app(LinkResolver::class)->resolve(['label' => 'Bard', 'destination_type' => 'entry', 'entry' => $match[1]], $site);
            $href = $link ? $link['url'].($match[2] ?? '') : '';
        }

        return $href !== '' && app(LinkResolver::class)->safeUrl($href) ? $href : null;
    }

    private function renderNodes(array $nodes, ?string $site): array
    {
        foreach ($nodes as &$node) {
            if (isset($node['marks'])) {
                $marks = [];
                foreach ($node['marks'] as $mark) {
                    if ($mark['type'] === 'link') {
                        $href = $this->href($mark['attrs']['href'], $site);
                        if ($href === null) {
                            Log::warning('SMK Bard link omitted: invalid or unavailable target.');

                            continue;
                        }
                        // Resolve once in the explicit site context, before native HTML encoding.
                        $mark['attrs']['href'] = $href;
                    }
                    $marks[] = $mark;
                }
                $node['marks'] = $marks;
            }
            if (isset($node['content'])) {
                $node['content'] = $this->renderNodes($node['content'], $site);
            }
        }

        return $nodes;
    }

    public function render(array $nodes, ?string $site = null): string
    {
        if (! $this->valid($nodes, site: $site, requireAvailable: false)) {
            return '';
        }
        $nodes = $this->renderNodes($nodes, $site);

        // Native supported Bard renderer, Antlers explicitly disabled. No second parse.
        return (string) (new Field('body', ['type' => 'bard', 'antlers' => false, 'sets' => [], 'buttons' => ['h2', 'h3', 'bold', 'italic', 'unorderedlist', 'orderedlist', 'anchor']]))->fieldtype()->augment($nodes);
    }
}
