<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use App\Metrics\QueryStats;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

#[AsMiddleware]
final class QueryTimingMiddleware implements Middleware
{
    public function __construct(private readonly QueryStats $stats)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new TimingDriver($driver, $this->stats);
    }
}
