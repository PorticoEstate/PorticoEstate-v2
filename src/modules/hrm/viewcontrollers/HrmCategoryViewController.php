<?php

namespace App\modules\hrm\viewcontrollers;

use App\modules\hrm\helpers\ViewSettingsHelper;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmCategoryViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;
	private array $allowedTypes = ['training', 'skill_level', 'experience', 'qualification'];

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('hrm');
	}

	private function redirect(Response $response, string $url): Response
	{
		return $response->withHeader('Location', $url)->withStatus(302);
	}

	private function assertType(string $type, Response $response): ?Response
	{
		if (in_array($type, $this->allowedTypes, true))
		{
			return null;
		}

		$response->getBody()->write(lang('Not found'));
		return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
	}

	private function emptyCategoryValues(): array
	{
		return [
			'id' => '',
			'descr' => '',
		];
	}

	private function categoryValuesFromBody(array $body, int $id, array $baseValues): array
	{
		$values = array_merge($baseValues, (array) ($body['values'] ?? []));
		if ($id)
		{
			$values['id'] = $id;
		}

		return $values;
	}

	private function validateCategoryValues(array $values, int $id): array
	{
		$errors = [];
		if (!$id && !ctype_digit((string) ($values['id'] ?? '')))
		{
			$errors[] = lang('Please enter an integer !');
		}
		if (trim((string) ($values['descr'] ?? '')) === '')
		{
			$errors[] = lang('Please enter a description !');
		}

		return $errors;
	}

	public function index(Request $request, Response $response, array $args): Response
	{
		$type = (string) ($args['type'] ?? '');
		if ($typeResponse = $this->assertType($type, $response))
		{
			return $typeResponse;
		}

		$query = $request->getQueryParams();
		$typeId = (int) ($query['type_id'] ?? 0);
		$rowsPerPage = ViewSettingsHelper::rowsPerPage();
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang($type) . ' ' . $typeId . ': ' . lang('list %1 category', $type)]);

		$html = $this->twig->render('@views/category/hrm_category_list.twig', [
			'layout' => '@views/_bare.twig',
			'type' => $type,
			'type_id' => $typeId,
			'api_url' => \phpgw::link('/hrm/categories/' . $type, ['type_id' => $typeId]),
			'new_url' => \phpgw::link('/hrm/view/categories/' . $type . '/new', ['type_id' => $typeId]),
			'edit_url_template' => \phpgw::link('/hrm/view/categories/' . $type . '/__CATEGORY_ID__/edit', ['type_id' => $typeId]),
			'delete_url_template' => \phpgw::link('/hrm/view/categories/' . $type . '/__CATEGORY_ID__/delete', ['type_id' => $typeId]),
			'admin_url' => \phpgw::link('/admin/index.php'),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => ViewSettingsHelper::lengthMenu($rowsPerPage),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'admin', $type], "admin::hrm::$type"));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function edit(Request $request, Response $response, array $args): Response
	{
		$type = (string) ($args['type'] ?? '');
		if ($typeResponse = $this->assertType($type, $response))
		{
			return $typeResponse;
		}

		$id = (int) ($args['id'] ?? 0);
		$query = $request->getQueryParams();
		$typeId = (int) ($query['type_id'] ?? 0);
		$categories = \CreateObject('hrm.bocategory', false);
		$baseValues = $this->emptyCategoryValues();
		if ($id)
		{
			$storedValues = (array) $categories->read_single($id, $type, $typeId);
			if (!$storedValues)
			{
				$response->getBody()->write(lang('Not found'));
				return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
			}
			$baseValues = array_merge($baseValues, $storedValues);
		}

		$body = (array) ($request->getParsedBody() ?: []);
		$values = $baseValues;
		$errors = [];
		$messages = [];
		$listUrl = \phpgw::link('/hrm/view/categories/' . $type, ['type_id' => $typeId]);

		if (strtoupper($request->getMethod()) === 'POST')
		{
			$postedValues = (array) ($body['values'] ?? []);
			if (!empty($postedValues['done']))
			{
				return $this->redirect($response, $listUrl);
			}

			if (!empty($postedValues['save']))
			{
				$values = $this->categoryValuesFromBody($body, $id, $baseValues);
				$errors = $this->validateCategoryValues($values, $id);
				if (!$errors)
				{
					$receipt = (array) $categories->save($values, $typeId, $id ? 'edit' : '', $type);
					foreach ((array) ($receipt['error'] ?? []) as $error)
					{
						$errors[] = (string) ($error['msg'] ?? $error);
					}
					foreach ((array) ($receipt['message'] ?? []) as $message)
					{
						$messages[] = (string) ($message['msg'] ?? $message);
					}

					if (!$errors)
					{
						return $this->redirect($response, $listUrl);
					}
				}
			}
		}

		$functionMsg = $id ? lang('edit category') : lang('add category');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang($type) . ' ' . $typeId . ': ' . $functionMsg]);
		$html = $this->twig->render('@views/category/hrm_category_edit.twig', [
			'layout' => '@views/_bare.twig',
			'type' => $type,
			'type_id' => $typeId,
			'category_id' => $id,
			'values' => $values,
			'errors' => $errors,
			'messages' => $messages,
			'form_action' => $id
				? \phpgw::link('/hrm/view/categories/' . $type . '/' . $id . '/edit', ['type_id' => $typeId])
				: \phpgw::link('/hrm/view/categories/' . $type . '/new', ['type_id' => $typeId]),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'admin', $type], "admin::hrm::$type"));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function delete(Request $request, Response $response, array $args): Response
	{
		$type = (string) ($args['type'] ?? '');
		if ($typeResponse = $this->assertType($type, $response))
		{
			return $typeResponse;
		}

		$id = (int) ($args['id'] ?? 0);
		$query = $request->getQueryParams();
		$typeId = (int) ($query['type_id'] ?? 0);
		$categories = \CreateObject('hrm.bocategory', false);
		$values = $id ? (array) $categories->read_single($id, $type, $typeId) : [];
		$listUrl = \phpgw::link('/hrm/view/categories/' . $type, ['type_id' => $typeId]);
		if (!$values)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		if (strtoupper($request->getMethod()) === 'POST')
		{
			$body = (array) ($request->getParsedBody() ?: []);
			if (!empty($body['confirm']))
			{
				$categories->delete($id, $type, $typeId);
			}

			return $this->redirect($response, $listUrl);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang($type) . ' ' . $typeId . ': ' . lang('delete ' . $type . ' category')]);
		$html = $this->twig->render('@views/category/hrm_category_delete.twig', [
			'layout' => '@views/_bare.twig',
			'type' => $type,
			'type_id' => $typeId,
			'category_id' => $id,
			'values' => array_merge($this->emptyCategoryValues(), $values),
			'form_action' => \phpgw::link('/hrm/view/categories/' . $type . '/' . $id . '/delete', ['type_id' => $typeId]),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'admin', $type], "admin::hrm::$type"));
		return $response->withHeader('Content-Type', 'text/html');
	}
}