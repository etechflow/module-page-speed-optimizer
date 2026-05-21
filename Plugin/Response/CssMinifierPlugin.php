<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Plugin\Response;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * Minifies inline CSS inside `<style>` blocks in the HTML response.
 *
 * Why inline CSS specifically: external `.css` files served via Magento's
 * static-content pipeline are already minified by setup:static-content:deploy.
 * What this plugin handles is the (typically large) inline `<style>` block
 * that themes inject for above-the-fold styles, Hyvä's tailwind output,
 * and CSS-in-JS frameworks.
 *
 * Strips:
 *   - CSS comments  /* ... *\/  (except !important hints)
 *   - Whitespace around { } ; : , > + ~
 *   - Trailing semicolons before }
 *   - Multiple whitespace → single space
 *   - Newlines and tabs
 *
 * Skips processing pages whose URL matches the HTML-minify exclusion list
 * (we reuse that list — same intent).
 */
class CssMinifierPlugin
{
    use HeaderHelperTrait;

    public function __construct(
        private readonly Config $config,
        private readonly HttpRequest $request
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): array
    {
        if (!$this->config->isHtmlMinifyEnabled()) {
            // CSS minify is enabled together with HTML minify in v2.1 —
            // same toggle, until v2.2 splits them.
            return [];
        }
        if (!$this->shouldProcess($subject)) {
            return [];
        }
        $span = Profiler::start('ETechFlow_PSO_CssMinify');
        try {
            $body = (string) $subject->getBody();
            if ($body === '') {
                return [];
            }
            $rewritten = $this->minifyInlineStyles($body);
            if ($rewritten !== $body) {
                $subject->setBody($rewritten);
            }
        } catch (\Throwable $e) {
            // never break the response
        } finally {
            Profiler::stop($span);
        }
        return [];
    }

    private function shouldProcess(HttpResponse $response): bool
    {
        $contentType = $this->headerValue($response, 'Content-Type');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'text/html')) {
            return false;
        }
        $uri = (string) $this->request->getRequestUri();
        if (str_contains($uri, '/admin')) {
            return false;
        }
        if ($this->request->isAjax()) {
            return false;
        }
        foreach ($this->config->getHtmlMinifyExcludeUrls() as $pattern) {
            if ($pattern !== '' && str_contains($uri, $pattern)) {
                return false;
            }
        }
        return true;
    }

    private function minifyInlineStyles(string $html): string
    {
        return preg_replace_callback(
            '#(<style\b[^>]*>)(.*?)(</style>)#is',
            function ($m) {
                $opening = $m[1];
                $css     = $m[2];
                $closing = $m[3];
                $minified = $this->minifyCss($css);
                return $opening . $minified . $closing;
            },
            $html
        ) ?? $html;
    }

    private function minifyCss(string $css): string
    {
        // 1. Strip /* */ comments — but keep `/*! ... */` (the !important
        //    hint pattern used by Bootstrap/Tailwind for "do not remove")
        $css = preg_replace('#/\*(?!\!)[^*]*\*+(?:[^/*][^*]*\*+)*/#', '', $css) ?? $css;
        // 2. Collapse all whitespace (tabs, newlines, multi-spaces) → single space
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        // 3. Strip whitespace around CSS structural characters
        $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css) ?? $css;
        // 4. Strip trailing semicolons inside rule blocks
        $css = str_replace(';}', '}', $css);
        // 5. Trim leading/trailing whitespace
        return trim($css);
    }
}
