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

    /**
     * @throws Exception
     */
    public function requestExternalService(Request $request): Response
    {
        $externalEntityId = $request->query->get('id');
        $count = $request->query->get('count', 1);

        if (!is_numeric($externalEntityId) || !is_numeric($count)) {
            return new Response(null, Response::HTTP_BAD_REQUEST);
        }

        return new Response(implode(
            '; ',
            $this->externalService->getMultipleNames((int)$externalEntityId, (int)$count),
        ));
    }
}
