<?php

namespace App\Support\Smk;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statamic\Events\NavTreeSaving;
use Statamic\Facades\Entry;

class NavigationTreeValidator
{
    public function __construct(private LinkResolver $links) {}

    /** Native NavTree::save dispatches this event before calling its repository. */
    public function handle(NavTreeSaving $event): void
    {
        $tree = $event->tree;
        if (! in_array($tree->handle(), ['main', 'footer', 'legal'], true)) {
            return;
        }
        $errors = [];
        $this->nodes($tree->tree(), $tree->handle(), $tree->locale(), 'tree', '', $errors);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function nodes(array $nodes, string $menu, string $site, string $path, string $position, array &$errors): void
    {
        foreach ($nodes as $index => $node) {
            $key = $path.'.'.$index;
            $number = $position === '' ? (string) ($index + 1) : $position.'.'.($index + 1);
            $label = is_array($node) && is_string($node['title'] ?? null) ? trim($node['title']) : '';
            $name = $label === '' ? 'bez etykiety' : Str::limit(strip_tags($label), 120);
            $context = 'Nawigacja „'.$menu.'”, element '.$number.' („'.$name.'”): ';
            if (! is_array($node)) {
                $errors[$key][] = $context.'element ma nieprawidłową strukturę.';

                continue;
            }
            $children = $node['children'] ?? [];
            if (! is_array($children)) {
                $errors[$key.'.children'][] = $context.'lista elementów podrzędnych jest nieprawidłowa.';
                $children = [];
            }
            // Scan every descendant, even if an ancestor itself has a valid target.
            $this->nodes($children, $menu, $site, $key.'.children', $number, $errors);
            $reference = $node['entry'] ?? null;
            $url = $node['url'] ?? null;
            $hasEntry = $reference !== null && $reference !== '';
            $hasUrl = $url !== null && $url !== '';
            if ($hasEntry && $hasUrl) {
                $errors[$key.'.url'][] = $context.'wybierz dokładnie jeden cel: wpis albo URL.';
            }
            if ($hasEntry) {
                $entry = is_string($reference) ? Entry::find($reference) : null;
                $entryUrl = $entry && $entry->locale() === $site && $entry->status() === 'published' ? $entry->url() : null;
                if (! is_string($entryUrl) || ! $this->links->safeUrl($entryUrl)) {
                    $errors[$key.'.entry'][] = $context.'wybierz istniejący, opublikowany wpis w tej witrynie z prawidłowym URL.';
                }
                if ($label === '' && $entry && is_string($title = $entry->value('title'))) {
                    $label = trim($title);
                }
            }
            if ($hasUrl && (! is_string($url) || ! $this->links->safeUrl($url))) {
                $errors[$key.'.url'][] = $context.'podaj bezpieczny URL zgodny z kontraktem linku.';
            }
            if (! $hasEntry && ! $hasUrl && $children === []) {
                $errors[$key.'.url'][] = $context.'dodaj prawidłowy URL, wybierz wpis lub dodaj elementy podrzędne grupy.';
            }
            if ($label === '') {
                $errors[$key.'.title'][] = $context.'uzupełnij etykietę elementu.';
            }
        }
    }
}
