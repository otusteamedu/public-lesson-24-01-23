<?php

namespace App\Manager;

use App\Client\StatsdClient;

class SuccessMetricsManager implements SuccessMetricsManagerInterface
{
    private const SUCCESS_SUFFIX = 'success';
    private const FAIL_SUFFIX = 'fail';

    public function __construct(
        private readonly StatsdClient $statsdClient,
        private readonly string $metricName,
    ) {
    }

    public function logSuccess(): void
    {
        $this->log(self::SUCCESS_SUFFIX);
    }

    public function logFail(): void
    {
        $this->log(self::FAIL_SUFFIX);
    }

    private function log(string $suffix): void
    {
        $this->statsdClient->increment("$this->metricName.$suffix");
    }
}
