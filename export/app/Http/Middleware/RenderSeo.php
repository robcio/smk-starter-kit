<?php

namespace App\Http\Middleware;

use App\Support\Smk\SeoResolver;
use Closure;
use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry;
use Symfony\Component\HttpFoundation\Response;

final class RenderSeo
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->render($request, $next($request));
    }

    public function render(Request $request, Response $response): Response
    {
        if ($request->attributes->get('smk.seo.rendered') === $response) {
            return $response;
        }
        $status = $response->getStatusCode();
        if ($status >= 400 || $request->isLivePreview() || $request->has('token') || $request->headers->has('X-Statamic-Token') || $response->headers->has('X-Statamic-Live-Preview')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->remove('ETag');
            $response->headers->remove('Last-Modified');
        }
        if ($request->is(trim(config('statamic.cp.route', 'cp'), '/').'/*') || ! str_contains(strtolower($response->headers->get('Content-Type', '')), 'text/html') || ($status >= 300 && $status < 400) || in_array($status, [204, 304], true) || ! method_exists($response, 'getContent') || ! is_string($body = $response->getContent())) {
            return $response;
        }
        $marker = '<!-- smk:seo -->';
        $hasMarker = str_contains($body, $marker);
        if (! $hasMarker && $status < 400) {
            return $response;
        }
        $entry = $request->attributes->get('smk.seo.entry');
        $model = app(SeoResolver::class)->resolve($entry instanceof Entry ? $entry : null, $request, $response);
        $response->headers->set('X-Robots-Tag', $model['robots']);
        if ($status >= 400) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        $head = view('smk/core/partials/seo', ['seo' => $model])->render();
        if ($hasMarker) {
            $body = str_replace($marker, $head, $body);
        } elseif (preg_match('~<head\b[^>]*>(.*?)</head>~is', $body, $match)) {
            // Error pages from Laravel may use their own shell. Replace their title only.
            $inner = $this->withoutOwnedMetadata($match[1]);
            $body = str_replace($match[0], '<head>'.$inner.$head.'</head>', $body);
        } else {
            return $response;
        }
        $request->attributes->set('smk.seo.rendered', $response);
        $response->setContent($body);
        $response->headers->remove('Content-Length');
        $response->headers->set('X-Robots-Tag', $model['robots']);
        if ($status >= 400 || $model['title'] === 'Preview' || ($entry && ! $model['social'])) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->remove('ETag');
            $response->headers->remove('Last-Modified');
        }

        return $response;
    }

    private function withoutOwnedMetadata(string $head): string
    {
        return preg_replace_callback('~<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<title\b[^>]*>.*?</title>|<(?:meta|link)\b[^>]*>~is', function ($match) {
            $tag = $match[0];
            if (preg_match('~^<(script|style)\b~i', $tag)) {
                return $tag;
            }
            if (preg_match('~^<title\b~i', $tag)) {
                return '';
            }
            $doc = new \DOMDocument;
            @$doc->loadHTML('<html><head>'.$tag.'</head><body></body></html>', LIBXML_NONET);
            foreach ($doc->getElementsByTagName('meta') as $meta) {
                $name = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property'));
                if (in_array($name, ['robots', 'description'], true) || str_starts_with($name, 'og:') || str_starts_with($name, 'twitter:')) {
                    return '';
                }
            }
            foreach ($doc->getElementsByTagName('link') as $link) {
                if (in_array('canonical', preg_split('/\s+/', strtolower($link->getAttribute('rel'))), true)) {
                    return '';
                }
            }

            return $tag;
        }, $head);
    }
}
