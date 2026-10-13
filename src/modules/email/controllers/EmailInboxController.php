<?php

namespace App\modules\email\controllers;

use App\modules\email\services\InboxSerializer;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailInboxController
{
	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}

	public function index(Request $request, Response $response): Response
	{
		try
		{
			$businessObject = \CreateObject('email.boindex');
			$businessObject->index_data();
			$data = (array) ($businessObject->xi ?? []);
			$result = InboxSerializer::serialize($data);
			$user = (array) Settings::getInstance()->get('user');
			$result['pageSize'] = max(1, (int) ($user['preferences']['common']['maxmatchs'] ?? 25));
			return $this->json($response, $result);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, ['error' => lang('Unable to load messages')], 500);
		}
	}
}
