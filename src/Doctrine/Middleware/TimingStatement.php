<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use App\Metrics\QueryStats;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class TimingStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly string $sql, private readonly QueryStats $stats)
    {
        parent::__construct($statement);
    }

    public function execute(): Result
    {
        $start = hrtime(true);
        try {
            return parent::execute();
        } finally {
            $this->stats->record($this->sql, (hrtime(true) - $start) / 1e6);
        }
    }
}
