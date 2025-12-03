<?php

namespace App\modules\phpgwapi\controllers;

use App\modules\phpgwapi\security\Sessions;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Sanitizer;

class LanguageController
{
    public function __construct(ContainerInterface $container)
    {
        // Constructor for dependency injection if needed
    }

    /**
     * Set the user's language preference
     * 
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function setLanguage(Request $request, Response $response, array $args): Response
    {
        $lang = $args['lng'] ?? '';
        
        if (empty($lang)) {
            $error = ['error' => 'Language parameter is required'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $lang = Sanitizer::clean_value($lang, 'string');
        
        if (!$lang) {
            $error = ['error' => 'Invalid language parameter'];
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Set the language cookie for 14 days
        $sessions = Sessions::getInstance();
        $sessions->phpgw_setcookie('selected_lang', $lang, (time() + (60 * 60 * 24 * 14)));

        $result = ['success' => true, 'language' => $lang];
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
}