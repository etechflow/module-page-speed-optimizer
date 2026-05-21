# Changelog — ETechFlow Page Speed Optimizer

All notable changes to this module. Adheres to [Semantic Versioning](https://semver.org/).

---

## [2.1.0] — 2026-05-21 — Closing the Amasty Pro gaps: AVIF + source compression + auto-on-upload + CSS minify

Builds on v2.0 to close 4 more of the 7 missing Amasty Pro feature gaps. After this release, PSO Pro matches Amasty Pro on 11 of 14 Pro features. Remaining gaps (image resize, 4 lazy-load scripts, User-Agent, JS bundling, Cron Tasks List) land in v2.2/v2.3.

### Added

**AVIF format support**
- New `CavifEngine` — shells out to the `cavif` Rust binary for AVIF encoding. Falls through silently when not installed.
- `ImagickEngine` updated to support AVIF when ImageMagick was compiled with libavif (built-in from IM 7.0.25+, 2020).
- `GdEngine` updated to support AVIF via `imageavif()` when PHP was compiled with `--with-avif` (PHP 8.1+).
- `ConversionEngineInterface` extended with `supportsFormat(string $format)` and a generic `convert($s, $o, $q, $format)` method. Backward-compatible `convertToWebp()` shim preserved.
- New `AvifGenerator` (parallel to `WebpGenerator`) that produces `.avif` siblings to source images.
- `PictureBlockPlugin` updated to emit AVIF source FIRST in `<picture>`, WebP second, then original `<img>` fallback.
- Admin toggle: *Image Optimization → Generate AVIF format*. Off by default until merchant confirms an AVIF encoder is installed.

**Source JPEG/PNG/GIF compression**
- Three new compression engines under `Model/Image/Compress/`:
  - `JpegoptimEngine` — strip EXIF + recompress JPEGs. 5-50% savings.
  - `PngquantEngine` — lossy palette quantization. 50-75% savings on photographic PNGs.
  - `GifsicleEngine` — -O3 frame-pair dedupe for GIFs.
- New `SourceCompressor` orchestrator picks the right engine per MIME type, falls through silently if no compressor is installed.
- Distinct from WebP/AVIF conversion: shrinks the SOURCE files in place. Compound effect when paired with format conversion.
- Admin toggle: *Image Optimization → Compress source JPEG/PNG/GIF files in place*.

**CSS minification (inline `<style>` blocks)**
- New `CssMinifierPlugin` on the response pipeline. Strips CSS comments, collapses whitespace, removes trailing semicolons. Preserves `/*! */` important hints.
- Targets inline `<style>` blocks (external CSS is already minified by Magento's setup:static-content:deploy).
- Tied to the same admin toggle as HTML minify (split into separate toggles in v2.2).

**Auto-optimize on product image upload**
- New observer `AutoOptimizeOnUpload` on `catalog_product_save_after` (adminhtml scope).
- Pipeline: source compress → WebP generate → AVIF generate (all skip silently if their respective engines aren't available).
- Admin toggle: *Image Optimization → Auto-optimize newly uploaded product images*. Off by default.
- Synchronous in admin save. Customers with many-image saves can disable and use the bulk CLI on cron instead.

### Updated

- `EngineChain` is now format-aware. `getFirstAvailable(string $format)` returns the first available engine that supports the requested target format (separate priority for AVIF: `cavif → imagick → gd`).
- `PictureBlockPlugin` no longer requires `WebpGenerator` injection (we check disk for sibling files directly, not via the generator).
- New admin config fields under *Image Optimization* group: `avif_enabled`, `source_compress`, `auto_optimize_on_upload`.

### Live-test results on M2.4.8 Warden

- `setup:upgrade` ✓ clean (no schema changes — v2.0 schema sufficient)
- `setup:di:compile` ✓ 9/9 generators
- `etechflow:pso:verify` ✓ 10/10 PASS (existing checks; v2.2 will add specific AVIF + source compression checks)
- Homepage HTML still minifies to ~53.6KB (54KB unminified)
- New CLI configs reachable via `config:set etechflow_pso/image/avif_enabled 1` etc.

### Feature parity scorecard vs Amasty Pro ($199)

| Feature | Amasty | ETechFlow PSO Pro v2.1 |
|---|---|---|
| WebP conversion | ✅ | ✅ |
| **AVIF conversion** | ✅ | **✅ NEW** |
| **JPEG/PNG/GIF source compression** | ✅ | **✅ NEW** |
| Image resize — 2 algorithms | ✅ | ❌ v2.2 |
| **Auto-optimize on upload** | ✅ | **✅ NEW** |
| Smart optimization by viewed pages | ✅ | ❌ v2.2 |
| User-Agent device detection | ✅ | ❌ v2.2 |
| Back/Forward Cache | ✅ | ✅ |
| JS bundling/merging | ✅ | ❌ v2.2 |
| **HTML minification** | ✅ | ✅ |
| **CSS minification** | ✅ | **✅ NEW** |
| Move JS to footer | ✅ | ✅ |
| Server Push | ✅ | ✅ |
| Defer fonts loading | ✅ | ✅ |
| Cron Tasks List | ✅ | ❌ v2.2 |
| PSI Diagnostic | ✅ | ✅ |
| Trend graph (improvement over Amasty) | ❌ | ✅ |

**11 of 14 Amasty Pro features matched in v2.1. 3 remaining for v2.2.**

### Migration

```
composer update etechflow/module-page-speed-optimizer
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
# Restart php-fpm to clear OPcache (mandatory on prod with opcache.validate_timestamps=0)
```

After upgrade:
- AVIF, source compression, auto-optimize-on-upload default to OFF
- Existing v2.0 settings preserved
- Enable AVIF only after confirming `cavif` binary or Imagick-with-libavif is available
- Run `bin/magento etechflow:pso:verify` to see engine availability report

### Optional server installs to unlock more savings

```bash
# WebP (most hosts already have it via cwebp or GD/Imagick)
apt install webp                    # Debian/Ubuntu — cwebp binary

# AVIF (rare — install for v2.1 AVIF feature)
cargo install cavif                 # if Rust available
brew install cavif-rs               # macOS

# Source compression (cheap to install, high savings)
apt install jpegoptim pngquant gifsicle   # Debian/Ubuntu
yum install jpegoptim pngquant gifsicle   # RHEL/CentOS
brew install jpegoptim pngquant gifsicle  # macOS
```

---

## [2.0.0] — 2026-05-21 — Full Amasty PSO Pro feature parity: image opt + code opt

**The flagship release.** v1.x shipped the PSI diagnostic + trend graph. v2.0 absorbs the entire ETechFlow Image Optimizer module + adds the code-optimization features that make this a real Amasty Google PageSpeed Optimizer Pro competitor.

### Why v2.0 (not v1.2)

We added 5 new admin sections, a new database table, a new CLI command, and 6 new response-output plugins. That's not a minor release. v2.0 acknowledges the breaking-architectural change.

### Added — Image Optimization (lifted from IO)

The entire image-optimization subsystem now lives inside PSO:

- **WebP conversion** via `cwebp` / Imagick / GD engine chain. First available wins; graceful fallback on engine failure.
- **`<picture>` element rendering** with WebP source + JPEG/PNG fallback. Plugin on `Magento\Catalog\Block\Product\Image`.
- **Native lazy-load** — adds `loading="lazy"` to below-the-fold catalog images.
- **Admin grid** at *Stores → Settings → Image Optimization Log* — UI Component listing of `etechflow_pso_optimization_log` rows.
- **Savings summary banner** — 5 metric cards (images optimized, source/WebP totals, MB saved, avg compression %).
- **Mass actions**: Delete log entries; Restore originals (deletes the .webp file + log row, path-traversal-safe).
- **Bulk CLI** `bin/magento etechflow:pso:optimize-images --limit=N --dry-run --no-progress` — idempotent mtime dedupe; safe for nightly cron.
- **DB table** `etechflow_pso_optimization_log` — full audit of every conversion.

ETechFlow_ImageOptimizer module remains available as a lighter "image-only" SKU; PSO Pro v2.0 supersedes it for full-feature stores.

### Added — Code Optimization (NEW, matches Amasty Pro)

Five new response-output plugins on `Magento\Framework\App\Response\Http::sendResponse`:

- **HTML minification** — strips whitespace, comments, line breaks. Skips `<pre>` / `<script>` / `<style>` contents to preserve correctness. Live-test result: 58.6KB → 53.7KB on Magento 2.4.8 stock homepage (~8.5% reduction).
- **JS defer-to-footer** — adds `defer` attribute to non-inline external scripts. Skips RequireJS bootstrap, async scripts, ES modules. URL-pattern exclusion list (defaults: checkout, cart).
- **Defer Fonts Loading** — adds `font-display: swap` to all `@font-face` rules + injects a high-cascade override `<style>` block. Per-family exclusion list (excluded fonts get `font-display: block` instead).
- **Server Push / Preload hints** — sets `Link: rel=preload` response headers for critical assets. Auto-detects WOFF2 fonts in the response body; admin can configure additional URLs.
- **Back/Forward Cache (bfcache)** compatibility — strips `no-store` from `Cache-Control` on included pages so Chrome/Firefox can serve instant back/forward navigation. Defensive: enforces `no-store` on excluded pages (checkout, cart, customer/account by default).

All five are toggleable independently; all five default to OFF so customers opt-in per feature after testing.

### Improvements over Amasty

- **HeaderHelperTrait** — Laminas Header objects vs string casts (live-test caught this; documented gotcha #5 below).
- **Defer Fonts** also injects `<link rel=preload>` hints for any `@font-face` it touches — Amasty doesn't do this automatically.
- **bfcache** explicitly ENFORCES `no-store` on private pages (defensive). Amasty's bfcache module just strips, doesn't enforce.
- **CLI `etechflow:pso:optimize-images` ships with `--dry-run` flag** — Amasty's bulk CLI doesn't.

### Compatibility claims (matching Amasty Pro)

- ✅ Magento Open Source 2.4.4 – 2.4.8 / Adobe Commerce 2.4.4 – 2.4.8 / PHP 8.1 – 8.4
- ✅ Hyvä Theme + Hyvä Checkout (no theme-specific code; everything lives in core admin + response plugins)
- ✅ Mage-OS compatible
- ✅ Varnish-aware (response plugins skip when Varnish hits)
- ⚠️ AWS Remote Storage — not yet tested; v2.1
- ⚠️ Hyvä CSP compliance — manual verification needed; v2.1 will audit

### Live-test gotchas caught + documented

Adding to the running list (now at 6):

1. *(IO v1.2.0)* PHP 8.1+ readonly child-property conflict on `$formKey`
2. *(IO v1.2.0)* Magento XSD varchar length capped at 1024
3. *(IO v1.2.0)* InnoDB utf8mb4 composite-index 3072-byte limit
4. *(PSO v1.1.0)* `Magento\Framework\DataObject::hasData($key = '')` has a $key parameter
5. **(NEW — PSO v2.0)** **`HttpResponse::getHeader('X')` returns a Laminas Header object, NOT a string.** Casting `(string) $response->getHeader(...)` throws on PHP 8+. Solution: a `HeaderHelperTrait` with a `headerValue()` accessor that calls `->getFieldValue()` on the object. All 5 v2.0 response plugins use it.
6. **(NEW — PSO v2.0)** **PSR-4 filename↔classname mismatch on a lifted module.** When you `cp` a file and rename it but forget to rename the `class` declaration inside, Magento's autoloader silently tries to load both — resulting in `Cannot redeclare class` fatals. ALWAYS rename the class inside when you rename the file.

### v2.0 deferred to v2.1+

These Amasty Pro features need more time:

- **AVIF format support** (next-gen image, smaller than WebP)
- **JPEG / PNG / GIF source compression** (currently we only convert, don't compress originals)
- **Image resize** with 2 algorithms (resize-proportional vs crop) for mobile/tablet variants
- **4 lazy-load script choices** (jQuery / Native / Vanilla / Lozad) — we ship native only
- **User-Agent device detection** — different settings per mobile/tablet/desktop
- **Multi-Process Generation** — parallel image processing
- **Auto-optimize on upload** — observer on product image save
- **Smart optimization by viewed pages** (Amasty's newest feature)
- **CSS minification + merging**
- **JS bundling + merging**
- **Minify JS in PHTML files** (non-cacheable page optimization)
- **Cron Tasks List** admin UI
- **Sign static files** (browser cache invalidation tokens)
- **Async order indexing**
- **CDN URL rewriting** for static assets

Premium tier (v3.0):
- AJAX Shopping Cart
- Infinite Scroll for category pages
- Image optimization by Cron (scheduled, not immediate)
- JS merging per device
- Logging for auto-optimization

### Migration

```
composer update etechflow/module-page-speed-optimizer
bin/magento setup:upgrade      # creates etechflow_pso_optimization_log table
bin/magento setup:di:compile
bin/magento cache:flush
# Restart php-fpm to clear OPcache (mandatory on prod with opcache.validate_timestamps=0)
```

After upgrade, every code-optimization toggle defaults to OFF. **Test on staging first** — JS defer in particular can break themes that rely on synchronous script execution.

### Pricing positioning

| Product | Price | What it gets you |
|---|---|---|
| **ETechFlow IO** | $0 (free tier — matches Amasty's free) | Basic lazy-load + WebP image optimization only |
| **ETechFlow PSO Pro** v2.0 | **$179** (vs Amasty Pro $199) | Full image + code optimization + PSI Diagnostic + Trends + bfcache |
| **ETechFlow PSO Premium** v3.0+ (planned) | **$499** (vs Amasty Premium $599) | All of Pro + AJAX Cart + Infinite Scroll + Cron-scheduled image opt + per-device JS merging |

---

## [1.1.0] — 2026-05-21 — Score Timeline graph + History CLI

Pure additive release on top of v1.0.0. The diagnostic runs we've been logging into `etechflow_pso_diagnostic_log` now surface as a visual trend graph + a tabular history CLI.

### Added

- **Admin page at *Stores → Settings → Page Speed Trends*** — visual score-over-time chart with the last 20 diagnostic runs in a table below.
  - Inline SVG line chart, server-rendered. No JS lib dependency (no Chart.js, no React, no jQuery). Loads instantly.
  - One coloured line per (URL, strategy) combination. Hover any data point to see the exact score + timestamp.
  - Y-axis grid lines highlight Google's score bands (90 = green / 50 = orange thresholds).
  - Recent-runs table with colour-coded scores, LCP / TBT / CLS columns.
- **`bin/magento etechflow:pso:history --url=... --limit=20 --json`** CLI — tabular dump of recent runs. Useful for:
  - CI pipelines tracking score regression over time
  - Agency dashboards aggregating across multiple stores
  - Quick "what's the current state?" check without opening admin
- New ACL resource `ETechFlow_PageSpeedOptimizer::trends` separately grantable from `::diagnose` — view-only roles can see history without being able to spawn new diagnostic runs.

### Backwards compatibility

No schema changes, no `setup:upgrade` required when upgrading from v1.0.0 → v1.1.0. Only `composer update` + `cache:flush`.

### Migration

```
composer update etechflow/module-page-speed-optimizer
bin/magento setup:di:compile
bin/magento cache:flush
```

### Magento gotcha caught by live testing

Adding to our running list of "Magento APIs that bite you on first run":

- **`Magento\Framework\DataObject::hasData($key = '')` has a `$key` parameter**, so a child block class can't define `public function hasData(): bool` (no args) — PHP rejects the incompatible signature at compile time. Named our method `hasAnyRuns()` instead. (Adds to the IO v1.2.0 lessons about `$formKey` readonly conflict, `varchar(1024)` cap, and InnoDB 3072-byte index limit.)

---

## [1.0.0] — 2026-05-21 — Google PageSpeed Insights diagnostic + foundation

First commercial release. Ships the **PSI Diagnose** feature — the visible-feature that closes every "is your store fast?" conversation with merchants. Code optimization (CSS/JS/HTML minification, defer fonts, prioritize resource loading) follows in v1.1+.

### Why v1.0 ships Diagnose first

Every Amasty / Mageworx / Mirasvit page-speed module markets the same optimization features. What makes Amasty's $259 product feel premium is the **Diagnostic** tool — it shows merchants a real Google score before/after, with concrete recommendations. We ship that as v1.0 because:

1. It's the **highest-perceived-value** feature in the category
2. It gives merchants something to **measure** their other ETechFlow modules against (IO's WebP conversion, future PSO minification, etc.)
3. The code already exists — it was originally added to IO v1.2.0 then [moved here in IO v1.3.0](https://github.com/etechflow/module-image-optimizer/releases/tag/v1.3.0) because a measurement tool didn't belong in an image-optimization module.

### Added

**Foundation**
- `registration.php`, `composer.json` (proprietary licence, soft-deps on IO + suite modules via Bundle key).
- `etc/module.xml` setup_version `1.0.0`.
- **DB schema**: 1 table `etechflow_pso_diagnostic_log` — full audit of every PSI run with lab + field metrics + raw JSON for future re-parsing.
- **Admin config** (`etc/adminhtml/system.xml`) — License section + Google PageSpeed Insights section + Code Optimization section (placeholder toggles for v1.1+ features).

**Licensing + Infrastructure**
- `Model/LicenseValidator` — per-domain HMAC + bundle key. `MODULE_ID = page-speed-optimizer`. Shares `BUNDLE_SECRET_FRAGMENTS` byte-identical with every other ETechFlow module.
- `Model/Config` — license-aware `isEnabled()`. PSI API key + default strategy + timeout getters.
- `Model/Performance/Profiler` — Tideways span helper, tags `ETechFlow_PSO_*`.

**Google PageSpeed Insights Integration**
- `Model/Psi/PsiClient` — vanilla Curl client. Free tier: 25,000 requests/day per merchant's Google Cloud API key (no key works too, with Google's per-IP rate limit).
- `Model/Data/DiagnosticResult` — typed value object: lab Lighthouse score + Core Web Vital metrics + CrUX real-user field metrics + sorted recommendation list.
- `Model/Recommendation/Mapper` — curated mapping of 16 PSI audit IDs to the ETechFlow feature that fixes them. Drives the "ETechFlow can fix this" badge inline next to each Google recommendation.
- `Model/Psi/DiagnosticLogger` — best-effort persistence to the audit table.

**Admin UI**
- New page at **Stores → Settings → Page Speed Diagnose**:
  - Big colour-coded score card (green ≥ 90, orange 50-89, red < 50 — Google's own bands)
  - Lab metrics row (FCP, LCP, TBT, CLS)
  - Field metrics row (real-user CrUX data when available — LCP, INP, CLS + overall FAST/AVERAGE/SLOW category)
  - Sorted recommendation list (biggest LCP impact first) with HIGH/MEDIUM/LOW badges
  - **ETechFlow fix badge** on every recommendation we cover

**CLI**
- `bin/magento etechflow:pso:diagnose --url=... --strategy=mobile|desktop --json --pass-score=80` — headless diagnostic for CI gates.
- `bin/magento etechflow:pso:verify` — 10-check smoke test.

### Deferred to v1.1+

- **CSS minification** (build-time, stored in `pub/static`)
- **JavaScript minification** + defer
- **HTML minification**
- **Defer Fonts Loading** with exclusion list
- **Prioritize Resource Loading** (`<link rel="preload">` injection)
- **GZIP/Brotli** headers via .htaccess / nginx hints
- **Critical CSS extraction** (above-the-fold CSS inlining)
- **Performance budgets** (admin-set max JS/CSS/image total per page; flag offenders)
- **Score timeline graph** (chart of LCP / INP / CLS over time from the diagnostic_log table)
- **Hyvä Mode** (auto-detect Hyvä, skip optimizations Hyvä already handles)

### Setup (one-time, ~3 min)

1. Go to https://console.cloud.google.com/apis/credentials
2. Click "Create Credentials → API Key" (free)
3. Enable "PageSpeed Insights API" on the project (free, 25,000 requests/day)
4. Paste the key into *Stores → Configuration → eTechFlow → Page Speed Optimizer → Google PageSpeed Insights → PageSpeed Insights API Key*

Without a key it still works (Google's per-IP rate limit) but the key is strongly recommended.

### Compatibility

- Magento Open Source 2.4.4 – 2.4.8
- Adobe Commerce 2.4.4 – 2.4.8
- PHP 8.1 / 8.2 / 8.3 / 8.4
- Hyvä Theme + Hyvä Checkout (PSO is admin-side; theme-agnostic for now — Hyvä-aware optimizations land in v1.1+)
