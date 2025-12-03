<?php

namespace App\modules\booking\controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\modules\phpgwapi\security\Sessions;
use App\modules\booking\services\WebhookManager;

/**
 * Webhook subscription management REST API controller
 */
class WebhookController
{
	/**
	 * Create a new webhook subscription
	 */
	public function create(Request $request, Response $response): Response
	{
		// Check authentication
		$sessions = Sessions::getInstance();
		if (!$sessions->verify())
		{
			$response->getBody()->write(json_encode(array('error' => 'Authentication required')));
			return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
		}

		// Get request body
		$data = $request->getParsedBody();
		if (!$data)
		{
			$body = (string)$request->getBody();
			$data = json_decode($body, true);
		}

		if (!$data)
		{
			$response->getBody()->write(json_encode(array('error' => 'Invalid request body')));
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
		}

		// Create subscription
		$manager = new WebhookManager();
		$result = $manager->create($data);

		if (isset($result['error']))
		{
			$response->getBody()->write(json_encode($result));
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
		}

		$response->getBody()->write(json_encode($result));
		return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Get a webhook subscription by ID
	 */
	public function read(Request $request, Response $response, array $args): Response
	{
		// Check authentication
		$sessions = Sessions::getInstance();
		if (!$sessions->verify())
		{
			$response->getBody()->write(json_encode(array('error' => 'Authentication required')));
			return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
		}

		$subscription_id = $args['id'] ?? null;
		if (!$subscription_id)
		{
			$response->getBody()->write(json_encode(array('error' => 'Subscription ID required')));
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
		}

		$manager = new WebhookManager();
		$subscription = $manager->read($subscription_id);

		if (!$subscription)
		{
			$response->getBody()->write(json_encode(array('error' => 'Subscription not found')));
			return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
		}

		$response->getBody()->write(json_encode(array('subscription' => $subscription)));
		return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
	}

	/**
	 * List webhook subscriptions
	 */
	public function list(Request $request, Response $response): Response
	{
		// Check authentication
		$sessions = Sessions::getInstance();
		if (!$sessions->verify())
		{
			$response->getBody()->write(json_encode(array('error' => 'Authentication required')));
			return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
		}

		$queryParams = $request->getQueryParams();
		$filters = array();

		if (isset($queryParams['resource_type']))
		{
			$filters['resource_type'] = $queryParams['resource_type'];
		}

		if (isset($queryParams['resource_id']))
		{
			$filters['resource_id'] = (int)$queryParams['resource_id'];
		}

		if (isset($queryParams['is_active']))
		{
			$filters['is_active'] = (bool)$queryParams['is_active'];
		}

		$manager = new WebhookManager();
		$subscriptions = $manager->listSubscriptions($filters);

		$response->getBody()->write(json_encode(array('subscriptions' => $subscriptions)));
		return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Renew a webhook subscription
	 */
	public function renew(Request $request, Response $response, array $args): Response
	{
		// Check authentication
		$sessions = Sessions::getInstance();
		if (!$sessions->verify())
		{
			$response->getBody()->write(json_encode(array('error' => 'Authentication required')));
			return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
		}

		$subscription_id = $args['id'] ?? null;
		if (!$subscription_id)
		{
			$response->getBody()->write(json_encode(array('error' => 'Subscription ID required')));
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
		}

		// Get request body
		$data = $request->getParsedBody();
		if (!$data)
		{
			$body = (string)$request->getBody();
			$data = json_decode($body, true);
		}

		$expirationMinutes = isset($data['expirationMinutes']) ? (int)$data['expirationMinutes'] : 43200;

		$manager = new WebhookManager();
		$result = $manager->renew($subscription_id, $expirationMinutes);

		if (isset($result['error']))
		{
			$response->getBody()->write(json_encode($result));
			return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
		}

		$response->getBody()->write(json_encode($result));
		return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Delete a webhook subscription
	 */
	public function delete(Request $request, Response $response, array $args): Response
	{
		// Check authentication
		$sessions = Sessions::getInstance();
		if (!$sessions->verify())
		{
			$response->getBody()->write(json_encode(array('error' => 'Authentication required')));
			return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
		}

		$subscription_id = $args['id'] ?? null;
		if (!$subscription_id)
		{
			$response->getBody()->write(json_encode(array('error' => 'Subscription ID required')));
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
		}

		$manager = new WebhookManager();
		$result = $manager->delete($subscription_id);

		if (isset($result['error']))
		{
			$response->getBody()->write(json_encode($result));
			return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
		}

		$response->getBody()->write(json_encode($result));
		return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Get delivery log for a subscription
	 */
	public function deliveryLog(Request $request, Response $response, array $args): Response
	{
		// Check authentication
		$sessions = Sessions::getInstance();
		if (!$sessions->verify())
		{
			$response->getBody()->write(json_encode(array('error' => 'Authentication required')));
			return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
		}

		$subscription_id = $args['id'] ?? null;
		if (!$subscription_id)
		{
			$response->getBody()->write(json_encode(array('error' => 'Subscription ID required')));
			return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
		}

		$queryParams = $request->getQueryParams();
		$limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 100;

		$manager = new WebhookManager();
		$log = $manager->getDeliveryLog($subscription_id, $limit);

		$response->getBody()->write(json_encode(array('deliveryLog' => $log)));
		return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Validation endpoint for webhook handshake
	 */
	public function validate(Request $request, Response $response): Response
	{
		$queryParams = $request->getQueryParams();
		$validationToken = $queryParams['validationToken'] ?? null;

		if (!$validationToken)
		{
			$response->getBody()->write('Missing validationToken');
			return $response->withStatus(400)->withHeader('Content-Type', 'text/plain');
		}

		// Simply echo back the validation token
		$response->getBody()->write($validationToken);
		return $response->withStatus(200)->withHeader('Content-Type', 'text/plain');
	}
}
