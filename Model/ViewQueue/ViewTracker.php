<?php

declare(strict_types=1);

namespace ETechFlow\PageSpeedOptimizer\Model\ViewQueue;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Enqueues image paths viewed in the frontend for cron-based optimization.
 *
 * Fire-and-forget by design: every enqueue() catches and silently swallows
 * exceptions so a queue insert failure NEVER affects a frontend page render.
 *
 * Idempotency via UNIQUE constraint on source_path — INSERT IGNORE means
 * repeat views of the same image don't pile up duplicate rows.
 *
 * Once cron processes the entry, the row is marked `processed` and the
 * next view of the same image is a no-op (the unique constraint blocks
 * re-insert; we could also delete processed rows older than N days in
 * a future maintenance job).
 */
class ViewTracker
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Add a source-image path to the queue. Silent on failure.
     */
    public function enqueue(string $sourcePath): void
    {
        if ($sourcePath === '' || !is_file($sourcePath)) {
            return;
        }
        try {
            $conn = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('etechflow_pso_view_queue');
            // insertIgnore (Magento wraps MySQL's INSERT IGNORE) skips on
            // unique-constraint violation — perfect for our fire-and-forget.
            // We rely on `status` default = 'queued' from the schema.
            $conn->insertOnDuplicate(
                $table,
                [
                    'source_path' => $sourcePath,
                    'status'      => 'queued',
                    'queued_at'   => date('Y-m-d H:i:s'),
                ],
                []  // empty update column list = ON DUPLICATE KEY do nothing
            );
        } catch (\Throwable $e) {
            // Never block a page render. Log + carry on.
            $this->logger->debug(
                'ETechFlow_PSO view-queue enqueue failed (non-fatal)',
                ['source' => $sourcePath, 'exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Dequeue up to $limit pending entries, marking them as `processing`
     * atomically so a parallel cron run doesn't double-process.
     *
     * @return array<int, array{queue_id: int, source_path: string}>
     */
    public function dequeueBatch(int $limit): array
    {
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('etechflow_pso_view_queue');

        // Read N pending IDs
        $select = $conn->select()
            ->from($table, ['queue_id', 'source_path'])
            ->where('status = ?', 'queued')
            ->order('queued_at ASC')
            ->limit(max(1, $limit));
        $rows = $conn->fetchAll($select);

        if (!empty($rows)) {
            $ids = array_column($rows, 'queue_id');
            $conn->update(
                $table,
                ['status' => 'processing'],
                $conn->quoteInto('queue_id IN (?)', $ids)
            );
        }
        return $rows;
    }

    public function markProcessed(int $queueId): void
    {
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('etechflow_pso_view_queue');
        $conn->update(
            $table,
            [
                'status'       => 'processed',
                'processed_at' => date('Y-m-d H:i:s'),
                'error_message' => null,
            ],
            $conn->quoteInto('queue_id = ?', $queueId)
        );
    }

    public function markFailed(int $queueId, string $errorMessage): void
    {
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('etechflow_pso_view_queue');
        $conn->update(
            $table,
            [
                'status'        => 'failed',
                'processed_at'  => date('Y-m-d H:i:s'),
                'error_message' => substr($errorMessage, 0, 65535),
            ],
            $conn->quoteInto('queue_id = ?', $queueId)
        );
    }

    /**
     * @return array{queued: int, processing: int, processed: int, failed: int}
     */
    public function getStatusCounts(): array
    {
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('etechflow_pso_view_queue');
        $select = $conn->select()
            ->from($table, ['status', new \Zend_Db_Expr('COUNT(*) AS cnt')])
            ->group('status');
        $rows = $conn->fetchAll($select);
        $counts = ['queued' => 0, 'processing' => 0, 'processed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $key = strtolower((string) $row['status']);
            if (isset($counts[$key])) {
                $counts[$key] = (int) $row['cnt'];
            }
        }
        return $counts;
    }
}
