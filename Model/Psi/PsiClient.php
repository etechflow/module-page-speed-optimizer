<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\Psi;

use ETechFlow\PageSpeedOptimizer\Model\Config;
use ETechFlow\PageSpeedOptimizer\Model\Data\DiagnosticResult;
use ETechFlow\PageSpeedOptimizer\Model\Data\Recommendation;
use ETechFlow\PageSpeedOptimizer\Model\Performance\Profiler;
use ETechFlow\PageSpeedOptimizer\Model\Recommendation\Mapper;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

/**
 * Talks to Google's PageSpeed Insights v5 API.
 *
 * Endpoint: https://www.googleapis.com/pagespeedonline/v5/runPagespeed
 * Free tier: 25,000 requests/day per API key (no key → ~1 req/sec per IP).
 *
 * Returns a DiagnosticResult — never throws to the caller. Network or
 * API errors captured as `errorMessage` on the result so the admin can
 * render a meaningful failure state.
 */
class PsiClient
{
    private const API_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** Lighthouse marks these informational; we skip them in the recommendation list. */
    private const SKIP_AUDIT_IDS = [
        'final-screenshot',
        'full-page-screenshot',
        'screenshot-thumbnails',
        'metrics',
        'diagnostics',
        'network-requests',
        'network-rtt',
        'network-server-latency',
        'main-thread-tasks',
        'resource-summary',
        'third-party-summary',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly Mapper $mapper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function diagnose(string $url, string $strategy = 'mobile'): DiagnosticResult
    {
        $span = Profiler::start('ETechFlow_PSO_PsiDiagnose');
        try {
            $strategy = in_array($strategy, ['mobile', 'desktop'], true) ? $strategy : 'mobile';
            $apiKey = $this->config->getGooglePsiApiKey();
            $timeout = $this->config->getPsiTimeoutSeconds();

            $query = [
                'url'      => $url,
                'category' => 'PERFORMANCE',
                'strategy' => $strategy,
            ];
            if ($apiKey !== '') {
                $query['key'] = $apiKey;
            }
            $requestUrl = self::API_ENDPOINT . '?' . http_build_query($query);

            // Google PSI (esp. the desktop strategy on heavy pages) is variable:
            // usually ~30-40s, but it sometimes stalls server-side and returns
            // nothing even past a long timeout. Waiting longer doesn't help a
            // stalled request, but a fresh retry does — by then Google has often
            // cached the result from the first (server-side-completed) run and
            // returns it in seconds. So: one full-length attempt for the cold
            // analysis, then a short second attempt to grab the cached result.
            $attemptTimeouts = [max(30, (int) $timeout), 45];
            $statusCode = 0;
            $body       = '';
            $lastError  = '';
            foreach ($attemptTimeouts as $idx => $attemptTimeout) {
                try {
                    $this->curl->setOption(CURLOPT_TIMEOUT, $attemptTimeout);
                    $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 20);
                    $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
                    $this->curl->get($requestUrl);
                    $statusCode = (int) $this->curl->getStatus();
                    $body = (string) $this->curl->getBody();
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    $statusCode = 0;
                    $body = '';
                }
                if ($statusCode === 200 && $body !== '') {
                    break;
                }
                if ($idx === 0) {
                    sleep(2); // let the cold run settle/cache before the retry
                }
            }

            if ($statusCode !== 200 || $body === '') {
                $msg = $body !== ''
                    ? $this->extractApiError($body, $statusCode)
                    : sprintf(
                        'Google PSI did not respond in time (%s). The desktop strategy is slower on heavy pages — please click Run again; it usually returns instantly the second time.',
                        $lastError !== '' ? $lastError : 'timeout'
                    );
                return $this->failedResult($url, $strategy, $msg);
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return $this->failedResult($url, $strategy, 'Malformed JSON from PSI API');
            }

            return $this->parseResponse($url, $strategy, $data, $body);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ETechFlow_PSO PSI diagnostic failed',
                ['url' => $url, 'strategy' => $strategy, 'exception' => $e->getMessage()]
            );
            return $this->failedResult($url, $strategy, 'Unexpected error: ' . $e->getMessage());
        } finally {
            Profiler::stop($span);
        }
    }

    private function parseResponse(string $url, string $strategy, array $data, string $rawJson): DiagnosticResult
    {
        $lighthouse = $data['lighthouseResult'] ?? [];
        $categories = $lighthouse['categories'] ?? [];
        $audits     = $lighthouse['audits'] ?? [];

        $scoreFloat = $categories['performance']['score'] ?? null;
        $score = is_numeric($scoreFloat) ? (int) round($scoreFloat * 100) : -1;

        $fcp = $this->extractNumericAudit($audits, 'first-contentful-paint', 0.001);
        $lcp = $this->extractNumericAudit($audits, 'largest-contentful-paint', 0.001);
        $tbt = $this->extractNumericAudit($audits, 'total-blocking-time', 1.0);
        $cls = $this->extractNumericAudit($audits, 'cumulative-layout-shift', 1.0);

        $loading = $data['loadingExperience'] ?? [];
        $fieldOverall = isset($loading['overall_category']) ? (string) $loading['overall_category'] : null;
        $fieldMetrics = $loading['metrics'] ?? [];
        $fieldLcp = isset($fieldMetrics['LARGEST_CONTENTFUL_PAINT_MS']['percentile'])
            ? (float) $fieldMetrics['LARGEST_CONTENTFUL_PAINT_MS']['percentile']
            : null;
        $fieldInp = isset($fieldMetrics['INTERACTION_TO_NEXT_PAINT']['percentile'])
            ? (float) $fieldMetrics['INTERACTION_TO_NEXT_PAINT']['percentile']
            : null;
        $fieldCls = isset($fieldMetrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'])
            ? (float) $fieldMetrics['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100.0
            : null;

        $recommendations = [];
        foreach ($audits as $auditId => $audit) {
            if (in_array($auditId, self::SKIP_AUDIT_IDS, true)) {
                continue;
            }
            $auditScore = $audit['score'] ?? null;
            if ($auditScore === null || $auditScore >= 0.9) {
                continue;
            }
            $impactMs = (float) ($audit['details']['overallSavingsMs'] ?? 0);
            $recommendations[] = new Recommendation(
                auditId:        (string) $auditId,
                title:          (string) ($audit['title'] ?? $auditId),
                description:    (string) ($audit['description'] ?? ''),
                impactSeconds:  $impactMs / 1000.0,
                etechflowFix:   $this->mapper->getFix((string) $auditId)
            );
        }
        usort($recommendations, fn(Recommendation $a, Recommendation $b)
            => $b->impactSeconds <=> $a->impactSeconds);

        return new DiagnosticResult(
            url:                  $url,
            strategy:             $strategy,
            performanceScore:     $score,
            labFcpSeconds:        $fcp,
            labLcpSeconds:        $lcp,
            labTbtMillis:         $tbt,
            labClsScore:          $cls,
            fieldLcpMillis:       $fieldLcp,
            fieldInpMillis:       $fieldInp,
            fieldClsScore:        $fieldCls,
            fieldOverallCategory: $fieldOverall,
            recommendations:      $recommendations,
            rawJson:              $rawJson
        );
    }

    private function extractNumericAudit(array $audits, string $auditId, float $scaleFactor): ?float
    {
        $value = $audits[$auditId]['numericValue'] ?? null;
        if (!is_numeric($value)) {
            return null;
        }
        return (float) $value * $scaleFactor;
    }

    private function extractApiError(string $body, int $statusCode): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            return sprintf('Google PSI API: %s (HTTP %d)', $decoded['error']['message'], $statusCode);
        }
        return sprintf('Google PSI API returned HTTP %d', $statusCode);
    }

    private function failedResult(string $url, string $strategy, string $message): DiagnosticResult
    {
        return new DiagnosticResult(
            url:                  $url,
            strategy:             $strategy,
            performanceScore:     -1,
            labFcpSeconds:        null,
            labLcpSeconds:        null,
            labTbtMillis:         null,
            labClsScore:          null,
            fieldLcpMillis:       null,
            fieldInpMillis:       null,
            fieldClsScore:        null,
            fieldOverallCategory: null,
            recommendations:      [],
            rawJson:              null,
            errorMessage:         $message
        );
    }
}
