<?php

namespace App\modules\bookingfrontend\middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\modules\bookingfrontend\helpers\ResponseHelper;


class HttpBodyExist implements MiddlewareInterface
{

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
  {
    $method = $request->getMethod();
    if (in_array($method, ['POST', 'PATCH', 'PUT'])) {
        $requestBody = $request->getBody()->getContents();
        $requestObject = json_decode($requestBody, true);
        if (!$requestObject) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Invalid JSON data'],
                400
            );
        }
        $request = $request->withParsedBody($requestObject);
    }

    return $handler->handle($request);
  }
}
