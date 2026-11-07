<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use App\Metrics\QueryStats;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class TimingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly QueryStats $stats)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new TimingStatement(parent::prepare($sql), $sql, $this->stats);
    }

    public function query(string $sql): Result
    {
        $start = hrtime(true);
        try {
            return parent::query($sql);
        } finally {
            $this->stats->record($sql, (hrtime(true) - $start) / 1e6);
        }
    }

    public function exec(string $sql): int|string
    {
        $start = hrtime(true);
        try {
            return parent::exec($sql);
        } finally {
            $this->stats->record($sql, (hrtime(true) - $start) / 1e6);
        }
    }
}
