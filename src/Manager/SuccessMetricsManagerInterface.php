<?php

namespace App\Manager;

interface SuccessMetricsManagerInterface
{
    public function logSuccess(): void;

    public function logFail(): void;
}
