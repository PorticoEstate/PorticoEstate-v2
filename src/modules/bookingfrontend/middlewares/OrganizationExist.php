<?php

namespace App\modules\bookingfrontend\middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\services\OrganizationService;
use Psr\Container\ContainerInterface;
use Slim\Routing\RouteContext;

class OrganizationExist implements MiddlewareInterface
{
    private OrganizationService $service;

    public function __construct(ContainerInterface $container)
    {
        $this->service = $container->get(OrganizationService::class);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);                                                                                                             
        $route = $routeContext->getRoute();                                                                                                                                                                                                                                                             
        $orgId = $route->getArgument('id');
        
        if (!$this->service->existOrganization($orgId)) {
            return ResponseHelper::sendErrorResponse(
                ['error' => 'Organization not found'],
                404
            );
        }
        return $handler->handle($request);
    }
}
