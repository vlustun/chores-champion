<?php

declare(strict_types=1);

namespace App\Doctrine\Middleware;

use App\Metrics\QueryStats;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final class TimingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly QueryStats $stats)
    {
        parent::__construct($driver);
    }

    public function connect(array $params): DriverConnection
    {
        return new TimingConnection(parent::connect($params), $this->stats);
    }
}
