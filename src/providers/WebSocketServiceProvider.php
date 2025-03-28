<?php

namespace App\providers;

use DI\Container;
use Psr\Log\LoggerInterface;
use App\WebSocket\WebSocketServer;

class WebSocketServiceProvider
{
    /**
     * Register WebSocket services with the container
     *
     * @param Container $container DI container
     * @return void
     */
    public function register(Container $container): void
    {
        // Register the WebSocketServer
        $container->set(WebSocketServer::class, function (Container $c) {
            return new WebSocketServer($c->get(LoggerInterface::class));
        });
    }
}