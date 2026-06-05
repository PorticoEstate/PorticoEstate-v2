<?php

namespace App\modules\property\helpers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;

class WorkorderFormHelper
{
	/**
	 * Transitional save bridge used during Workorder API cutover.
	 *
	 * This keeps legacy save behavior intact while moving request handling to
	 * REST endpoints and JS API clients.
	 */
	public function persistSave(Request $request, array $input, int $id = 0): array
	{
		$originalPost = $_POST ?? array();
		$originalRequest = $_REQUEST ?? array();

		try
		{
			$_POST = is_array($input) ? $input : array();
			$_REQUEST = array_merge($originalRequest, $_POST);

			if ($id > 0)
			{
				$_POST['id'] = $id;
				$_REQUEST['id'] = $id;
			}

			$_POST['phpgw_return_as'] = 'json';
			$_REQUEST['phpgw_return_as'] = 'json';

			$ui = CreateObject('property.uiworkorder');
			$result = $ui->save();

			if (!is_array($result))
			{
				throw new HttpBadRequestException($request, 'Invalid response from legacy workorder save');
			}

			$receipt = isset($result['receipt']) && is_array($result['receipt']) ? $result['receipt'] : array();
			$errorList = isset($receipt['error']) && is_array($receipt['error']) ? $receipt['error'] : array();
			$resolvedId = (int)($receipt['id'] ?? $_POST['id'] ?? $id);

			return array(
				'status' => empty($errorList) ? 'success' : 'error',
				'data' => array('id' => $resolvedId),
				'receipt' => $receipt,
			);
		}
		finally
		{
			$_POST = $originalPost;
			$_REQUEST = $originalRequest;
		}
	}
}
