<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Image\Engine;

use ETechFlow\PageSpeedOptimizer\Model\Config;

/**
 * Picks the first available engine from the admin-configured order that
 * ALSO supports the requested target format.
 *
 * Engine availability + format support are cached per request — checking
 * binaries via exec() takes ~5ms each and we call this many times per
 * CLI run.
 *
 * v2.1+: format-aware. getFirstAvailable() takes an optional format param
 * (default WebP). For AVIF, only engines that supportFormat('avif')
 * (cavif, Imagick-with-libavif, or GD-with-libavif) are considered.
 */
class EngineChain
{
    /** @var array<string, ConversionEngineInterface> */
    private array $registry;

    /** @var array<string, bool> */
    private array $availabilityCache = [];

    /** @var array<string, array<string, bool>> [engineName][format] */
    private array $formatSupportCache = [];

    public function __construct(
        private readonly Config $config,
        CwebpEngine $cwebp,
        CavifEngine $cavif,
        ImagickEngine $imagick,
        GdEngine $gd
    ) {
        $this->registry = [
            $cwebp->getName()   => $cwebp,
            $cavif->getName()   => $cavif,
            $imagick->getName() => $imagick,
            $gd->getName()      => $gd,
        ];
    }

    /**
     * Returns the first available engine that supports the target format.
     *
     * @param string $format 'webp' | 'avif' (defaults to webp for back-compat)
     */
    public function getFirstAvailable(string $format = ConversionEngineInterface::FORMAT_WEBP): ?ConversionEngineInterface
    {
        // The admin order is configured for WebP historically. For AVIF, we
        // fall back to the natural priority: cavif → imagick → gd.
        $order = $format === ConversionEngineInterface::FORMAT_AVIF
            ? ['cavif', 'imagick', 'gd']
            : $this->config->getEngineOrder();

        foreach ($order as $name) {
            if (!isset($this->registry[$name])) {
                continue;
            }
            if (!$this->isAvailable($name)) {
                continue;
            }
            if (!$this->supportsFormat($name, $format)) {
                continue;
            }
            return $this->registry[$name];
        }
        return null;
    }

    /**
     * Report each known engine's availability and format support. Used by
     * verify CLI to give the merchant exact visibility.
     *
     * @return array<string, array{available: bool, webp: bool, avif: bool}>
     */
    public function getAvailabilityReport(): array
    {
        $report = [];
        foreach ($this->registry as $name => $engine) {
            $available = $this->isAvailable($name);
            $report[$name] = [
                'available' => $available,
                'webp'      => $available && $this->supportsFormat($name, ConversionEngineInterface::FORMAT_WEBP),
                'avif'      => $available && $this->supportsFormat($name, ConversionEngineInterface::FORMAT_AVIF),
            ];
        }
        return $report;
    }

    private function isAvailable(string $name): bool
    {
        if (!isset($this->availabilityCache[$name])) {
            $this->availabilityCache[$name] = $this->registry[$name]->available();
        }
        return $this->availabilityCache[$name];
    }

    private function supportsFormat(string $name, string $format): bool
    {
        if (!isset($this->formatSupportCache[$name][$format])) {
            $this->formatSupportCache[$name][$format] = $this->registry[$name]->supportsFormat($format);
        }
        return $this->formatSupportCache[$name][$format];
    }
}
