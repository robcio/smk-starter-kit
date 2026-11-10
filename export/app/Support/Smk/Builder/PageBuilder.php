<?php

namespace App\Support\Smk\Builder;

use App\Support\Smk\LinkResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Statamic\Events\EntrySaving;

final class PageBuilder
{
    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;
        if ($entry->collectionHandle() !== 'pages' || ! $entry->has('page_builder')) {
            return;
        }
        $errors = $this->errors($entry->get('page_builder'), (string) $entry->value('title'), $entry->locale());
        if ($entry->get('document_language') !== null && ! in_array($entry->get('document_language'), ['pl', 'en'], true)) {
            $errors['document_language'] = 'Wybierz język pl lub en.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function errors(mixed $blocks, string $title, ?string $site = null, bool $requireAvailable = true): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks) || count($blocks) > 20) {
            return ['page_builder' => 'Dodaj listę najwyżej 20 sekcji (łącznie z wyłączonymi).'];
        }
        $errors = [];
        $ids = $anchors = [];
        $heroCount = $visible = 0;
        foreach ($blocks as $index => $block) {
            $at = 'page_builder.'.$index;
            $type = is_array($block) ? ($block['type'] ?? '') : '';
            if (! is_string($type) || ! isset(SectionRegistry::TYPES[$type])) {
                $errors[$at.'.type'] = 'Nieznany typ sekcji Core.';

                continue;
            }
            $schema = SectionRegistry::TYPES[$type];
            $allowed = array_merge(['id', 'type', 'enabled', 'anchor', 'theme', 'width', 'spacing', 'variant', 'heading', 'appearance'], match ($type) {
                'hero' => ['eyebrow', 'lead', 'image', 'alt_override', 'decorative', 'caption', 'credit', 'links'], 'rich_text' => ['body'], 'cta' => ['text', 'links']
            });
            if (array_diff(array_keys($block), $allowed)) {
                $errors[$at.'.fields'] = 'Nieobsługiwane pole sekcji; kod, klasy i ścieżki nie są danymi CMS.';
            }
            if (isset($block['decorative']) && ! is_bool($block['decorative'])) {
                $errors[$at.'.decorative'] = 'Wybierz wartość logiczną.';
            }
            $id = $block['id'] ?? null;
            if (! is_string($id) || ! preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $id) || isset($ids[$id])) {
                $errors[$at.'.id'] = 'Sekcja wymaga stabilnego, unikalnego ID.';
            }
            $ids[is_string($id) ? $id : 'invalid'] = true;
            if (isset($block['enabled']) && ! is_bool($block['enabled'])) {
                $errors[$at.'.enabled'] = 'Widoczność musi być wartością logiczną.';
            }
            foreach (['theme' => ['surface', $schema['themes']], 'width' => [$schema['width'], $schema['widths']], 'spacing' => ['normal', $schema['spacings']], 'variant' => ['standard', ['standard']]] as $key => [$default, $allowed]) {
                if (! in_array($block[$key] ?? $default, $allowed, true)) {
                    $errors[$at.'.'.$key] = 'Wybierz dozwolone ustawienie sekcji '.$type.'.';
                }
            }
            $anchor = $block['anchor'] ?? '';
            if (! is_string($anchor) || ($anchor !== '' && (! preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $anchor) || $anchor === 'main-content' || str_starts_with($anchor, 'smk-') || isset($anchors[$anchor])))) {
                $errors[$at.'.anchor'] = 'Kotwica musi być unikalna i nie może kolidować z ID systemowym.';
            }
            if (is_string($anchor) && $anchor !== '') {
                $anchors[$anchor] = true;
            }
            foreach (['heading', 'eyebrow', 'lead', 'text', 'alt_override', 'caption', 'credit'] as $field) {
                if (isset($block[$field]) && (! is_string($block[$field]) || mb_strlen($block[$field]) > 3000)) {
                    $errors[$at.'.'.$field] = 'Wpisz zwykły tekst (maksymalnie 3000 znaków).';
                }
            }
            if ($type === 'hero') {
                $heroCount++;
                if (($block['enabled'] ?? true) && $visible !== 0) {
                    $errors[$at.'.type'] = 'Hero musi być pierwszą widoczną sekcją.';
                }
                if (trim(is_string($block['heading'] ?? null) ? $block['heading'] : $title) === '' && trim($title) === '') {
                    $errors[$at.'.heading'] = 'Hero wymaga nagłówka lub tytułu strony.';
                }
                if (! empty($block['image']) && app(AssetResolver::class)->resolve($block['image'], $block) === null) {
                    $errors[$at.'.image'] = 'Wybierz istniejący obraz JPEG/PNG/WebP z opisem alt lub oznacz jako dekoracyjny.';
                }
            }
            if ($type === 'rich_text' && (! app(BardContent::class)->valid($block['body'] ?? null, site: $site, requireAvailable: $requireAvailable) || ! app(BardContent::class)->hasText($block['body']))) {
                $errors[$at.'.body'] = 'Treść Bard jest wymagana; dozwolone akapity, H2/H3, listy i bezpieczne linki.';
            }
            if ($type === 'cta' && trim(is_string($block['heading'] ?? null) ? $block['heading'] : '') === '') {
                $errors[$at.'.heading'] = 'CTA wymaga nagłówka H2.';
            }
            if ($type !== 'rich_text') {
                $links = $block['links'] ?? [];
                if (! is_array($links) || ! array_is_list($links) || count($links) > 2 || ($type === 'cta' && $links === [])) {
                    $errors[$at.'.links'] = 'Hero: 0–2 linki. CTA: 1–2 linki.';
                } else {
                    foreach ($links as $n => $link) {
                        if (! is_array($link) || ! is_string($link['label'] ?? null) || mb_strlen($link['label']) > 120 || (isset($link['new_window']) && ! is_bool($link['new_window'])) || app(LinkResolver::class)->resolve($link, $site) === null) {
                            $errors[$at.'.links.'.$n] = 'Link wymaga etykiety i dokładnie jednego prawidłowego celu.';
                        }
                    }
                }
            }
            if ($block['enabled'] ?? true) {
                $visible++;
            }
        }
        if ($heroCount > 1) {
            $errors['page_builder'] = 'Dozwolona jest najwyżej jedna sekcja Hero, także wśród wyłączonych.';
        }

        return $errors;
    }

    public function resolve(mixed $blocks, string $title, ?string $site = null): array
    {
        $result = [];
        $hero = false;
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            Log::warning('SMK builder omitted: invalid section list.');
            $blocks = [];
        }
        $errors = $this->errors($blocks, $title, $site, requireAvailable: false);
        if (isset($errors['page_builder'])) {
            Log::warning('SMK builder contract violation: section limit or Hero count.');
        }
        foreach (array_slice($blocks, 0, 20) as $i => $block) {
            if (! is_array($block) || ($block['enabled'] ?? true) !== true) {
                continue;
            }
            $type = $block['type'] ?? '';
            $schema = is_string($type) ? (SectionRegistry::TYPES[$type] ?? null) : null;
            // Invalid content is omitted with neutral diagnostics, never interpreted as code.
            $invalid = array_filter(array_keys($errors), fn ($key) => str_starts_with($key, 'page_builder.'.$i.'.') && ! str_ends_with($key, '.image') && ! str_contains($key, '.links'));
            if (! $schema || ! view()->exists($schema['partial']) || $invalid || ($type === 'hero' && ($result !== [] || $hero))) {
                Log::warning('SMK section omitted: invalid Core contract.');

                continue;
            }
            $view = ['type' => $type, 'partial' => $schema['partial'], 'anchor' => $block['anchor'] ?? '', 'theme' => $block['theme'] ?? 'surface', 'width' => $block['width'] ?? $schema['width'], 'spacing' => $block['spacing'] ?? 'normal', 'heading' => trim($block['heading'] ?? '') ?: ($type === 'hero' ? $title : ''), 'eyebrow' => $block['eyebrow'] ?? '', 'lead' => $block['lead'] ?? '', 'text' => $block['text'] ?? '', 'links' => []];
            foreach (is_array($block['links'] ?? null) ? array_slice($block['links'], 0, 2) : [] as $link) {
                if (is_array($link) && ($resolved = app(LinkResolver::class)->resolve($link, $site))) {
                    $view['links'][] = ['item' => $resolved];
                }
            }
            if ($type === 'hero') {
                $hero = true;
                $view['image'] = app(AssetResolver::class)->resolve($block['image'] ?? null, $block, true);
            }
            if ($type === 'rich_text') {
                $view['html'] = app(BardContent::class)->render($block['body'], $site);
            }
            $view['block'] = $view;
            $result[] = $view;
        }

        return ['title' => $title, 'has_hero' => $hero, 'blocks' => $result];
    }
}
