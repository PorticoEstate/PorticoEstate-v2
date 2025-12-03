<?php

use App\modules\controller\helpers\RedirectHelper;
use App\modules\phpgwapi\security\AccessVerifier;
use App\modules\phpgwapi\middleware\SessionsMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\StreamFactory;


function serveImage(Response $response, string $filename): Response
{
	$path =	PHPGW_SERVER_ROOT . '/controller/images/' . $filename;

	if (!file_exists($path))
	{
		$response->getBody()->write('File not found.');
		return $response->withStatus(404);
	}

	$mimeType = mime_content_type($path);
	$response = $response->withHeader('Content-Type', $mimeType);

	$streamFactory = new StreamFactory();
	$stream = $streamFactory->createStreamFromFile($path);
	return $response->withBody($stream);
}

// Define the route to serve images
$app->get('/mobilefrontend/controller/images/{filename}', function (Request $request, Response $response, array $args)
{
	return serveImage($response, $args['filename']);
});

$app->get('/controller/images/{filename}', function (Request $request, Response $response, array $args)
{
	return serveImage($response, $args['filename']);
});

$app->get('/controller[/{params:.*}]', RedirectHelper::class . ':process')
	->addMiddleware(new AccessVerifier($container))
	->addMiddleware(new SessionsMiddleware($container));
