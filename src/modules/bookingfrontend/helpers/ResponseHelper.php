<?php

namespace App\modules\bookingfrontend\helpers;

use Psr\Http\Message\ResponseInterface as Response;

class ResponseHelper
{
    /**
     * Send an error response
     *
     * @param array $data
     * @param int $statusCode
     * @param Response|null $baseResponse
     *
     * @return Response
     */
    public static function sendErrorResponse($data, $statusCode = 401, ?Response $baseResponse = null): Response
    {
        $response = $baseResponse ?? new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }
}