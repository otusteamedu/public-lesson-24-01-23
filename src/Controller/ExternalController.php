<?php

namespace App\Controller;

use App\Service\ExternalService;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ExternalController extends AbstractController
{
    public function __construct(
        private readonly ExternalService $externalService
    ) {
    }

    public function requestExternalService(Request $request): Response
    {
        $externalEntityId = $request->query->get('id');
        $count = $request->query->get('count', 1);

        if (!is_numeric($externalEntityId) || !is_numeric($count)) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        $result = $this->externalService->getMultipleNames((int)$externalEntityId, (int)$count);

        return new Response(implode(
            '; ',
            array_map(
                static fn (int $id, string $name): string => $id.': '.$name,
                array_keys($result),
                array_values($result),
            )
        ));
    }
}
