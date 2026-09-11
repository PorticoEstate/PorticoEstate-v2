<?php

namespace App\modules\hrm\viewcontrollers;

use App\helpers\ViewSettingsHelper;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmPlaceViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('hrm');
	}

	private function redirect(Response $response, string $url): Response
	{
		return $response->withHeader('Location', $url)->withStatus(302);
	}

	private function emptyPlaceValues(): array
	{
		return [
			'id' => 0,
			'name' => '',
			'address' => '',
			'zip' => '',
			'town' => '',
			'remark' => '',
			'entry_date' => '',
		];
	}

	private function placeValuesFromBody(array $body, int $placeId, array $baseValues): array
	{
		$values = array_merge($baseValues, (array) ($body['values'] ?? []));
		$values['place_id'] = $placeId;

		return $values;
	}

	private function validatePlaceValues(array $values): array
	{
		$errors = [];
		if (empty($values['name']))
		{
			$errors[] = lang('Please enter a name !');
		}
		if (empty($values['address']))
		{
			$errors[] = lang('Please enter an address !');
		}
		if (empty($values['zip']))
		{
			$errors[] = lang('Please enter a zip code !');
		}
		if (empty($values['town']))
		{
			$errors[] = lang('Please enter a town !');
		}

		return $errors;
	}

	public function index(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('place') . ': ' . lang('list place')]);
		$rowsPerPage = ViewSettingsHelper::rowsPerPage();
		$html = $this->twig->render('@views/place/hrm_place_list.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/places'),
			'new_url' => \phpgw::link('/hrm/view/places/new'),
			'view_url_template' => \phpgw::link('/hrm/view/places/__PLACE_ID__'),
			'edit_url_template' => \phpgw::link('/hrm/view/places/__PLACE_ID__/edit'),
			'delete_url_template' => \phpgw::link('/hrm/view/places/__PLACE_ID__/delete'),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => ViewSettingsHelper::lengthMenu($rowsPerPage),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'place'], 'hrm::place'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function view(Request $request, Response $response, array $args): Response
	{
		$placeId = (int) ($args['id'] ?? 0);
		$places = \CreateObject('hrm.boplace', false);
		$values = $placeId ? (array) $places->read_single($placeId) : [];
		if (!$values)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Place') . ': ' . lang('view place')]);
		$html = $this->twig->render('@views/place/hrm_place_view.twig', [
			'layout' => '@views/_bare.twig',
			'place_id' => $placeId,
			'values' => array_merge($this->emptyPlaceValues(), $values),
			'list_url' => \phpgw::link('/hrm/view/places'),
			'edit_url' => \phpgw::link('/hrm/view/places/' . $placeId . '/edit'),
			'delete_url' => \phpgw::link('/hrm/view/places/' . $placeId . '/delete'),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'place'], 'hrm::place'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function edit(Request $request, Response $response, array $args): Response
	{
		$placeId = (int) ($args['id'] ?? 0);
		$places = \CreateObject('hrm.boplace', false);
		$baseValues = $this->emptyPlaceValues();
		if ($placeId)
		{
			$storedValues = (array) $places->read_single($placeId);
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
		$listUrl = \phpgw::link('/hrm/view/places');

		if (strtoupper($request->getMethod()) === 'POST')
		{
			$postedValues = (array) ($body['values'] ?? []);
			if (!empty($postedValues['cancel']))
			{
				return $this->redirect($response, $listUrl);
			}

			$values = $this->placeValuesFromBody($body, $placeId, $baseValues);
			$errors = $this->validatePlaceValues($values);
			if (!$errors)
			{
				$receipt = (array) $places->save($values, $placeId ? 'edit' : '');
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
					$placeId = (int) ($receipt['place_id'] ?? $placeId);
					if (!empty($postedValues['save']))
					{
						return $this->redirect($response, $listUrl);
					}

					if ($placeId)
					{
						$values = array_merge($this->emptyPlaceValues(), (array) $places->read_single($placeId));
					}
				}
			}
		}

		$functionMsg = $placeId ? lang('edit place') : lang('add place');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Place') . ': ' . $functionMsg]);
		$html = $this->twig->render('@views/place/hrm_place_edit.twig', [
			'layout' => '@views/_bare.twig',
			'place_id' => $placeId,
			'values' => $values,
			'errors' => $errors,
			'messages' => $messages,
			'form_action' => $placeId ? \phpgw::link('/hrm/view/places/' . $placeId . '/edit') : \phpgw::link('/hrm/view/places/new'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'place'], 'hrm::place'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function delete(Request $request, Response $response, array $args): Response
	{
		$placeId = (int) ($args['id'] ?? 0);
		$places = \CreateObject('hrm.boplace', false);
		$values = $placeId ? (array) $places->read_single($placeId) : [];
		$listUrl = \phpgw::link('/hrm/view/places');
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
				$places->delete($placeId);
			}

			return $this->redirect($response, $listUrl);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Place') . ': ' . lang('delete')]);
		$html = $this->twig->render('@views/place/hrm_place_delete.twig', [
			'layout' => '@views/_bare.twig',
			'place_id' => $placeId,
			'values' => array_merge($this->emptyPlaceValues(), $values),
			'form_action' => \phpgw::link('/hrm/view/places/' . $placeId . '/delete'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'place'], 'hrm::place'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}