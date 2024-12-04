<?php

namespace App\modules\bookingfrontend\helpers;

use Psr\Http\Message\ResponseInterface as Response;

class ResponseHelper
{
    /**
     * Send an error response
     *
     * @param array $error
     * @param int $statusCode
     *
     * @return Response
     */
    public static function sendErrorResponse($error, $statusCode = 401): Response
    {
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode($error));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($statusCode);
    }
}