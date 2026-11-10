<?php

namespace App\Support\Smk\Builder;

final class SectionRegistry
{
    // Developer-owned allowlist; CMS data never supplies a template or CSS class.
    public const TYPES = [
        'hero' => ['partial' => 'smk/core/blocks/hero', 'themes' => ['surface', 'contrast'], 'widths' => ['content', 'wide'], 'spacings' => ['normal', 'spacious'], 'width' => 'wide'],
        'rich_text' => ['partial' => 'smk/core/blocks/rich_text', 'themes' => ['surface'], 'widths' => ['reading'], 'spacings' => ['compact', 'normal', 'spacious'], 'width' => 'reading'],
        'cta' => ['partial' => 'smk/core/blocks/cta', 'themes' => ['surface', 'contrast', 'accent'], 'widths' => ['content'], 'spacings' => ['normal', 'spacious'], 'width' => 'content'],
    ];
}
