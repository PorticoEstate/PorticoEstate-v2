<?php

namespace App\modules\phpgwapi\services;

use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

final class ScimResponse
{
	public const CONTENT_TYPE = 'application/scim+json';
	public const ERROR_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

	public static function json(array $payload, int $status = 200, ?ResponseInterface $response = null): ResponseInterface
	{
		$response = $response ?? new Response();
		$response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

		return $response
			->withHeader('Content-Type', self::CONTENT_TYPE)
			->withStatus($status);
	}

	public static function error(int $status, string $detail, ?string $scimType = null): ResponseInterface
	{
		$payload = [
			'schemas' => [self::ERROR_SCHEMA],
			'status' => (string) $status,
			'detail' => $detail,
		];
		if ($scimType !== null)
		{
			$payload['scimType'] = $scimType;
		}

		return self::json($payload, $status);
	}
}