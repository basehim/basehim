<?php
declare(strict_types=1);

namespace Basehim\WpMigrator\Importers;

use App\Core\Application;
use App\Core\Database;
use Basehim\WpMigrator\IdMap;
use Basehim\WpMigrator\Sources\Source;
use Basehim\WpMigrator\State;

/**
 * Importer
 *
 * Base class for entity importers. Each importer handles one step of the
 * migration (users, terms, posts, ...) and is invoked once per batch.
 *
 * Subclasses implement:
 *   entityType()  - short name like 'users', for state.counts and logs
 *   total(Source) - total records to process (drives the progress bar)
 *   runBatch()    - process up to $limit records starting at $offset,
 *                   return the actual number processed
 *
 * Batches are kept small (default 25) so each /run call finishes well
 * within typical PHP execution limits even when networking (media downloads).
 */
abstract class Importer
{
    protected int $batchSize = 25;

    public function __construct(
        protected Application $app,
        protected Database $db,
        protected Source $source,
        protected IdMap $idMap,
        protected State $state,
        protected int $jobId,
        protected array $options
    ) {}

    abstract public function entityType(): string;
    abstract public function total(): int;
    abstract public function runBatch(int $offset, int $limit): int;

    public function batchSize(): int { return $this->batchSize; }

    /** Helpful for importers that want to log progress without an exception. */
    protected function log(string $msg): void
    {
        $this->state->appendLog($this->jobId, "[{$this->entityType()}] {$msg}");
    }

    /** Pull an option value with a default. */
    protected function opt(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
