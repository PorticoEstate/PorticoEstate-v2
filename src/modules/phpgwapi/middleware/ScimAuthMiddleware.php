<?php

namespace App\modules\phpgwapi\middleware;

use App\modules\phpgwapi\services\ScimResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ScimAuthMiddleware implements MiddlewareInterface
{
	private ?string $token;

	public function __construct(?string $token = null)
	{
		$configuredToken = $token ?? getenv('SCIM_BEARER_TOKEN');
		$this->token = is_string($configuredToken) && $configuredToken !== '' ? $configuredToken : null;
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
	{
		if ($this->token === null)
		{
			return ScimResponse::error(503, 'SCIM provisioning is not configured');
		}

		$authorization = $request->getHeaderLine('Authorization');
		if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches))
		{
			return ScimResponse::error(401, 'Invalid bearer token');
		}

		if (!hash_equals($this->token, trim($matches[1])))
		{
			return ScimResponse::error(401, 'Invalid bearer token');
		}

		return $handler->handle($request);
	}
}