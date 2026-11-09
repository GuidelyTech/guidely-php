<?php

declare(strict_types=1);

namespace GuidelyTech\Track;

/**
 * The statement class {@see GuidelyTrack::instrumentPdo()} gives a PDO connection
 * (`PDO::ATTR_STATEMENT_CLASS`): every `execute()` of a prepared statement is recorded, with its
 * duration, rows and error, without changing what it returns or throws.
 *
 * @internal
 */
class TracedPdoStatement extends \PDOStatement
{
    /** @param \Closure(string, float, ?\Throwable, ?int): void $record */
    protected function __construct(private readonly \Closure $record)
    {
    }

    public function execute(?array $params = null): bool
    {
        $start = hrtime(true);
        try {
            $ok = parent::execute($params);
        } catch (\Throwable $e) {
            $this->recordQuery($start, $e, null);
            throw $e;
        }
        $error = null;
        if (!$ok) {
            $info = $this->errorInfo();
            $error = new \RuntimeException(is_string($info[2] ?? null) ? $info[2] : 'SQLSTATE ' . ($info[0] ?? 'HY000'));
        }
        $this->recordQuery($start, $error, $ok ? $this->rowCount() : null);
        return $ok;
    }

    private function recordQuery(int $start, ?\Throwable $error, ?int $rows): void
    {
        try {
            ($this->record)($this->queryString, (hrtime(true) - $start) / 1e6, $error, $rows);
        } catch (\Throwable) {
            // recording must never break the query
        }
    }
}
