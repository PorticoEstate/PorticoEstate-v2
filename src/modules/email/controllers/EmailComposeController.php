<?php

namespace App\modules\email\controllers;

use App\modules\email\services\EmailComposeSerializer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailComposeController
{
	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}

	public function send(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
		{
			$input = json_decode((string) $request->getBody(), true);
		}
		$payload = EmailComposeSerializer::normalize(is_array($input) ? $input : []);
		if ($payload === null)
		{
			return $this->json($response, ['error' => lang('Provide at least one recipient and valid message data')], 422);
		}

		$originalPost = $_POST;
		$_POST = $payload;
		try
		{
			$businessObject = \CreateObject('email.bosend');
			$businessObject->api_mode = true;
			$result = $businessObject->send();
			if (empty($result['sent']))
			{
				return $this->json($response, ['error' => lang('Unable to send message')], 502);
			}
			return $this->json($response, ['sent' => true]);
		}
		catch (\Throwable $exception)
		{
			if (\App\modules\email\services\MailMessage::hasInstance() && \App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
			return $this->json($response, ['error' => lang('Unable to send message')], 500);
		}
		finally
		{
			$_POST = $originalPost;
		}
	}
}
