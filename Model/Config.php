<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Centralised reader for the entire PSO config surface — lifted from IO
 * plus new code-optimization + bfcache settings.
 *
 * isEnabled() returns false when EITHER the admin "Module Enabled" toggle
 * is No OR the license isn't valid. Feature-specific isXxxEnabled() methods
 * gate individual features.
 */
class Config
{
    // Global
    public const XML_PATH_ENABLED          = 'etechflow_pso/general/enabled';

    // PSI Diagnostic (existing in v1.x)
    public const XML_PATH_PSI_API_KEY      = 'etechflow_pso/psi/api_key';
    public const XML_PATH_PSI_STRATEGY     = 'etechflow_pso/psi/default_strategy';
    public const XML_PATH_PSI_TIMEOUT      = 'etechflow_pso/psi/timeout_seconds';

    // Image Optimization (lifted from IO + extended in v2.1)
    public const XML_PATH_IMG_ENABLED      = 'etechflow_pso/image/enabled';
    public const XML_PATH_IMG_QUALITY      = 'etechflow_pso/image/quality';
    public const XML_PATH_IMG_ENGINE_ORDER = 'etechflow_pso/image/engine_order';
    public const XML_PATH_IMG_BATCH_SIZE   = 'etechflow_pso/image/batch_size';
    public const XML_PATH_IMG_PICTURE      = 'etechflow_pso/image/picture_block';
    public const XML_PATH_IMG_LAZY_LOAD    = 'etechflow_pso/image/lazy_load';
    public const XML_PATH_IMG_PRODUCT      = 'etechflow_pso/image/coverage_product';
    public const XML_PATH_IMG_CATEGORY     = 'etechflow_pso/image/coverage_category';
    public const XML_PATH_IMG_CMS          = 'etechflow_pso/image/coverage_cms';
    public const XML_PATH_IMG_AVIF         = 'etechflow_pso/image/avif_enabled';
    public const XML_PATH_IMG_SOURCE_COMPRESS = 'etechflow_pso/image/source_compress';
    public const XML_PATH_IMG_AUTO_UPLOAD  = 'etechflow_pso/image/auto_optimize_on_upload';

    // Code Optimization (new in v2.0)
    public const XML_PATH_HTML_MINIFY      = 'etechflow_pso/code/html_minify';
    public const XML_PATH_HTML_MINIFY_EXCLUDE = 'etechflow_pso/code/html_minify_exclude_urls';
    public const XML_PATH_JS_DEFER         = 'etechflow_pso/code/js_defer';
    public const XML_PATH_JS_DEFER_EXCLUDE = 'etechflow_pso/code/js_defer_exclude_urls';
    public const XML_PATH_DEFER_FONTS      = 'etechflow_pso/code/defer_fonts';
    public const XML_PATH_DEFER_FONTS_EXCLUDE = 'etechflow_pso/code/defer_fonts_exclude_families';
    public const XML_PATH_SERVER_PUSH      = 'etechflow_pso/code/server_push';
    public const XML_PATH_PRELOAD_URLS     = 'etechflow_pso/code/preload_urls';

    // Back/Forward Cache
    public const XML_PATH_BFCACHE          = 'etechflow_pso/bfcache/enabled';
    public const XML_PATH_BFCACHE_EXCLUDE  = 'etechflow_pso/bfcache/exclude_urls';

    private const DEFAULT_QUALITY = 80;
    private const DEFAULT_BATCH_SIZE = 200;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LicenseValidator $licenseValidator
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->licenseValidator->isValid()) {
            return false;
        }
        return $this->isAdminEnabled();
    }

    public function isAdminEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    // ─────────────────────────────────────────────────────────
    // PSI Diagnostic
    // ─────────────────────────────────────────────────────────

    public function getGooglePsiApiKey(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PSI_API_KEY, ScopeInterface::SCOPE_STORE);
        return trim((string) $value);
    }

    public function getPsiDefaultStrategy(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_PSI_STRATEGY, ScopeInterface::SCOPE_STORE);
        return in_array($value, ['mobile', 'desktop'], true) ? $value : 'mobile';
    }

    public function getPsiTimeoutSeconds(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_PSI_TIMEOUT, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : 90;
    }

    // ─────────────────────────────────────────────────────────
    // Image Optimization — IO compatibility shims so the lifted
    // image-processing code reads from PSO config paths
    // ─────────────────────────────────────────────────────────

    public function isImageOptimizationEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getQuality(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_IMG_QUALITY, ScopeInterface::SCOPE_STORE);
        if ($value < 1 || $value > 100) {
            return self::DEFAULT_QUALITY;
        }
        return $value;
    }

    /** @return string[] */
    public function getEngineOrder(): array
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_IMG_ENGINE_ORDER, ScopeInterface::SCOPE_STORE);
        if ($value === '') {
            return ['cwebp', 'imagick', 'gd'];
        }
        $parts = array_filter(array_map('trim', explode(',', strtolower($value))));
        return $parts ?: ['cwebp', 'imagick', 'gd'];
    }

    public function getBatchSize(): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_IMG_BATCH_SIZE, ScopeInterface::SCOPE_STORE);
        return $value > 0 ? $value : self::DEFAULT_BATCH_SIZE;
    }

    public function isPictureBlockEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_PICTURE, ScopeInterface::SCOPE_STORE);
    }

    public function isLazyLoadEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_LAZY_LOAD, ScopeInterface::SCOPE_STORE);
    }

    public function isProductCacheCovered(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_PRODUCT, ScopeInterface::SCOPE_STORE);
    }

    public function isCategoryCovered(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_CATEGORY, ScopeInterface::SCOPE_STORE);
    }

    public function isCmsCovered(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_CMS, ScopeInterface::SCOPE_STORE);
    }

    public function isAvifEnabled(): bool
    {
        if (!$this->isImageOptimizationEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_AVIF, ScopeInterface::SCOPE_STORE);
    }

    public function isSourceCompressEnabled(): bool
    {
        if (!$this->isImageOptimizationEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_SOURCE_COMPRESS, ScopeInterface::SCOPE_STORE);
    }

    public function isAutoOptimizeOnUploadEnabled(): bool
    {
        if (!$this->isImageOptimizationEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_IMG_AUTO_UPLOAD, ScopeInterface::SCOPE_STORE);
    }

    // ─────────────────────────────────────────────────────────
    // Code Optimization
    // ─────────────────────────────────────────────────────────

    public function isHtmlMinifyEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_HTML_MINIFY, ScopeInterface::SCOPE_STORE);
    }

    /** @return string[] */
    public function getHtmlMinifyExcludeUrls(): array
    {
        return $this->splitLines((string) $this->scopeConfig->getValue(
            self::XML_PATH_HTML_MINIFY_EXCLUDE, ScopeInterface::SCOPE_STORE));
    }

    public function isJsDeferEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_JS_DEFER, ScopeInterface::SCOPE_STORE);
    }

    /** @return string[] */
    public function getJsDeferExcludeUrls(): array
    {
        return $this->splitLines((string) $this->scopeConfig->getValue(
            self::XML_PATH_JS_DEFER_EXCLUDE, ScopeInterface::SCOPE_STORE));
    }

    public function isDeferFontsEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DEFER_FONTS, ScopeInterface::SCOPE_STORE);
    }

    /** @return string[] */
    public function getDeferFontsExcludeFamilies(): array
    {
        return $this->splitLines((string) $this->scopeConfig->getValue(
            self::XML_PATH_DEFER_FONTS_EXCLUDE, ScopeInterface::SCOPE_STORE));
    }

    public function isServerPushEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SERVER_PUSH, ScopeInterface::SCOPE_STORE);
    }

    /** @return string[] */
    public function getPreloadUrls(): array
    {
        return $this->splitLines((string) $this->scopeConfig->getValue(
            self::XML_PATH_PRELOAD_URLS, ScopeInterface::SCOPE_STORE));
    }

    // ─────────────────────────────────────────────────────────
    // Back/Forward Cache
    // ─────────────────────────────────────────────────────────

    public function isBfcacheEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        return $this->scopeConfig->isSetFlag(self::XML_PATH_BFCACHE, ScopeInterface::SCOPE_STORE);
    }

    /** @return string[] */
    public function getBfcacheExcludeUrls(): array
    {
        return $this->splitLines((string) $this->scopeConfig->getValue(
            self::XML_PATH_BFCACHE_EXCLUDE, ScopeInterface::SCOPE_STORE));
    }

    /** @return string[] */
    private function splitLines(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $lines), fn($s) => $s !== ''));
    }
}
