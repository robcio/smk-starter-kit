<?php

namespace App\Providers;

use App\Http\Middleware\RenderSeo;
use App\Support\Smk\Builder\PageBuilder;
use App\Support\Smk\ChromeResolver;
use App\Support\Smk\NavigationTreeValidator;
use App\Support\Smk\SeoResolver;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Statamic\Contracts\Entries\Entry;
use Statamic\Events\EntrySaving;
use Statamic\Events\NavTreeSaving;
use Statamic\Events\ResponseCreated;
use Statamic\Facades\Cascade;
use Statamic\Facades\Site;
use Statamic\Statamic;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Narrow compatibility fix for the CP ElementContainer shipped in 6.39.0.
        if (Statamic::version() === '6.39.0') {
            $script = 'js/smk/cp-resize.js';
            Statamic::externalScript(asset($script).'?v='.substr(hash_file('sha256', public_path($script)), 0, 12));
        }
        Event::listen(NavTreeSaving::class, NavigationTreeValidator::class);
        Event::listen(EntrySaving::class, PageBuilder::class);
        Event::listen(EntrySaving::class, [SeoResolver::class, 'handle']);
        $this->app->make(Kernel::class)->pushMiddleware(RenderSeo::class);
        Event::listen(ResponseCreated::class, function (ResponseCreated $event) {
            request()->attributes->set('smk.seo.entry', $event->data instanceof Entry ? $event->data : null);
        });

        // The public `site` global shadows Statamic's site variable in the cascade.
        Cascade::hydrated(function ($cascade) {
            $content = $cascade->content();
            $currentId = $content instanceof Entry ? $content->id() : null;
            // Native edit previews carry processed, unaugmented fields as supplements.
            // Only the matching native preview token may select these values.
            $preview = $content instanceof Entry && request()->isLivePreviewOf($content);
            $value = fn (string $key) => $preview && $content->hasSupplement($key) ? $content->getSupplement($key) : $content->value($key);
            $hasBuilder = $content instanceof Entry && ($content->has('page_builder') || ($preview && $content->hasSupplement('page_builder')));
            $cascade->set('smk_page', $hasBuilder ? app(PageBuilder::class)->resolve($value('page_builder'), (string) $value('title'), $content->locale()) : null);
            $cascade->set('smk_shell', app(ChromeResolver::class)->resolve($currentId));
            $language = $content instanceof Entry ? $value('document_language') : null;
            $cascade->set('smk_document', ['lang' => in_array($language, ['pl', 'en'], true) ? $language : str_replace('_', '-', Site::current()->locale())]);
        });
    }
}
