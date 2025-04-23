<?php

namespace App\modules\booking\controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;


class VippsController
{
	/**
	 * Get pending unposted Vipps transactions
	 *
	 * @param Request $request
	 * @param Response $response
	 * @param array $args
	 * @return Response
	 */
	public function getPendingTransactions(Request $request, Response $response, array $args)
	{
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		$userSettings = Settings::getInstance()->get('user');
		$dateformat = $userSettings['preferences']['common']['dateformat'];
		$datetimeformat = $dateformat . ' H:i:s';

		// Check for admin access
		if (!Acl::getInstance()->check('run', ACL_READ, 'admin'))
		{
			$response->getBody()->write(json_encode(['error' => 'No access']));
			return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
		}

		// Get query parameters
		$params = $request->getQueryParams();

		// Pagination and sorting parameters
		$pagination_params = [
			'status' => isset($params['status']) ? $params['status'] : null,
			'limit' => isset($params['result']) ? (int)$params['result'] : 10,
			'offset' => isset($params['start']) ? (int)$params['start'] : 0,
			'sort' => isset($params['order']) ? $params['order'] : 'date',
			'dir' => isset($params['sort']) ? $params['sort'] : 'desc',
		];

		// Create soapplication instance
		$soapplication = \CreateObject('booking.soapplication');

		// Fetch transactions with pagination and filtering
		$result = $soapplication->get_unposted_transactions($pagination_params);
		$transactions = $result['results'];
		$total = $result['total'];

		// Format the transactions for the frontend
		foreach ($transactions as &$transaction)
		{
			// Ensure amount is properly formatted
			$transaction['amount'] = number_format((float)$transaction['amount'], 2, '.', '');

			// Convert date from Unix timestamp if it's already numeric
			if (is_numeric($transaction['date']))
			{
				$transaction['date'] = date($datetimeformat, $transaction['date']);
			}
			else
			{

				$transaction['date'] = date($datetimeformat, strtotime($transaction['date']));
			}
		}

		// Return the transactions as JSON
		$responseData = [
			'ResultSet' => [
				'Result' => $transactions,
				'totalRecords' => $total,
			],
		];

		$response->getBody()->write(json_encode($responseData));
		return $response->withHeader('Content-Type', 'application/json');
	}
}
