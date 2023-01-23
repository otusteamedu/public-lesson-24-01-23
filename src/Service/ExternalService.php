<?php

namespace App\Service;

use Exception;

class ExternalService
{
    private const SUCCESSFUL_RATE = 50;

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
}
