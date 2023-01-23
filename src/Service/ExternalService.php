<?php

namespace App\Service;

use App\Manager\SuccessMetricsManagerInterface;
use Exception;

class ExternalService
{
    private const SUCCESSFUL_RATE = 50;

    public function __construct(
        private readonly SuccessMetricsManagerInterface $successMetricsManager
    ) {
    }

    /**
     * @throws Exception
     */
    public function getName(int $externalEntityId): string
    {
        $isSuccessful = random_int(0, 99) < self::SUCCESSFUL_RATE;

        if (!$isSuccessful) {
            throw new Exception('Cannot request name for entity '. $externalEntityId);
        }

        return 'External Entity '.$externalEntityId;
    }

    /**
     * @return array<int,string>
     */
    public function getMultipleNames(int $startId, int $count): array
    {
        $result = [];

        while ($count-- > 0) {
            try {
                $result[$startId] = $this->getName($startId);
                $this->successMetricsManager->logSuccess();
            } catch (Exception) {
                // log exception
                $this->successMetricsManager->logFail();
            }
            $startId++;
        }

        return $result;
    }
}
