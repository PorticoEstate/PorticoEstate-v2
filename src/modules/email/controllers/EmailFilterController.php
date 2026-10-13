<?php

namespace App\modules\email\controllers;

use App\modules\email\services\EmailFilterSerializer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailFilterController
{
	public function index(Request $request, Response $response): Response
	{
		try
		{
			$businessObject = \CreateObject('email.bofilters');
			$filters = EmailFilterSerializer::serialize((array) ($businessObject->all_filters ?? []));
			foreach ($filters as $index => $filter)
			{
				$id = $filter['id'];
				$filters[$index]['editUrl'] = \phpgw::link('/index.php', ['menuaction' => 'email.uifilters.filters_edit', 'filter_num' => $id]);
				$filters[$index]['moveUpUrl'] = \phpgw::link('/index.php', ['menuaction' => 'email.bofilters.move_up', 'filter_num' => $id]);
				$filters[$index]['moveDownUrl'] = \phpgw::link('/index.php', ['menuaction' => 'email.bofilters.move_down', 'filter_num' => $id]);
				$filters[$index]['deleteUrl'] = \phpgw::link('/index.php', ['menuaction' => 'email.bofilters.delete_filter', 'filter_num' => $id]);
			}
			return $this->json($response, ['items' => $filters]);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, ['error' => lang('Unable to load filters')], 500);
		}
	}

	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}
}
