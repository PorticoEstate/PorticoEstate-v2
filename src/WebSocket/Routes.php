<?php

namespace App\WebSocket;

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class Routes
{
    public function register(App $app): void
    {
        // Route to serve the WebSocket test page
        $app->get('/websocket-test', function (Request $request, Response $response) {
            $html = file_get_contents(__DIR__ . '/test.html');
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html');
        });
    }
}