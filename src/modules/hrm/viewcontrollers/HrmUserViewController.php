<?php

namespace App\modules\hrm\viewcontrollers;

use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmUserViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('hrm');
	}

	private function rowsPerPage(): int
	{
		$user = Settings::getInstance()->get('user');
		return isset($user['preferences']['common']['maxmatchs']) && (int) $user['preferences']['common']['maxmatchs'] > 0
			? (int) $user['preferences']['common']['maxmatchs']
			: 10;
	}

	private function redirect(Response $response, string $url): Response
	{
		return $response->withHeader('Location', $url)->withStatus(302);
	}

	private function emptyTrainingValues(int $userId): array
	{
		return [
			'id' => 0,
			'user_id' => $userId,
			'cat_id' => '',
			'title' => '',
			'start_date' => '',
			'end_date' => '',
			'credits' => 0,
			'reference' => '',
			'descr' => '',
			'skill' => '',
			'place_id' => '',
			'entry_date' => '',
			'new_place_name' => '',
			'new_place_address' => '',
			'new_place_zip' => '',
			'new_place_town' => '',
			'new_place_remark' => '',
		];
	}

	private function trainingValuesFromBody(array $body, int $userId, int $trainingId, array $baseValues): array
	{
		$values = array_merge($baseValues, (array) ($body['values'] ?? []));
		$values['user_id'] = $userId;
		$values['training_id'] = $trainingId;
		$values['place_id'] = (string) ($body['place_id'] ?? $values['place_id'] ?? '');
		$values['credits'] = (int) ($values['credits'] ?? 0);
		$values['new_place_descr'] = (string) ($values['new_place_descr'] ?? '');

		return $values;
	}

	private function validateTrainingValues(array $values): array
	{
		$errors = [];
		if (empty($values['cat_id']))
		{
			$errors[] = lang('Please select a category !');
		}

		if (empty($values['start_date']))
		{
			$errors[] = lang('Please select a start date !');
		}

		if (empty($values['place_id']) && empty($values['new_place_name']))
		{
			$errors[] = lang('Please select a place or enter a new place !');
		}

		if (($values['place_id'] ?? '') === 'new_place')
		{
			if (empty($values['new_place_address']))
			{
				$errors[] = lang('Please enter an address !');
			}
			if (empty($values['new_place_zip']))
			{
				$errors[] = lang('Please enter a zip code !');
			}
			if (empty($values['new_place_town']))
			{
				$errors[] = lang('Please enter a town !');
			}
		}

		return $errors;
	}

	private function normalizeSelectList(array $items): array
	{
		return array_map(static function (array $item): array {
			return [
				'id' => (string) ($item['id'] ?? ''),
				'name' => (string) ($item['name'] ?? ''),
				'selected' => !empty($item['selected']),
			];
		}, $items);
	}

	public function index(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('user') . ': ' . lang('list user')]);
		$rowsPerPage = $this->rowsPerPage();

		$html = $this->twig->render('@views/user/hrm_user_list.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/users'),
			'training_url_template' => \phpgw::link('/hrm/view/users/__USER_ID__/training'),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => [$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3],
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'user'], 'hrm::user'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function training(Request $request, Response $response, array $args): Response
	{
		$userId = (int) ($args['id'] ?? 0);
		$users = \CreateObject('hrm.bouser', false);
		$common = \CreateObject('hrm.bocommon');
		$grants = (array) $users->grants;
		if (!$userId || !$common->check_perms2($userId, $grants, ACL_READ))
		{
			$response->getBody()->write(lang('Access not permitted'));
			return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Training')]);
		$rowsPerPage = $this->rowsPerPage();
		$html = $this->twig->render('@views/user/hrm_user_training.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/users/' . $userId . '/training'),
			'list_url' => \phpgw::link('/hrm/view/users'),
			'user_values' => $users->get_user_data($userId),
			'cv_url' => \phpgw::link('/index.php', ['menuaction' => 'hrm.uiuser.view_cv', 'user_id' => $userId], true),
			'new_url' => \phpgw::link('/hrm/view/users/' . $userId . '/training/new'),
			'view_url_template' => \phpgw::link('/hrm/view/users/' . $userId . '/training/__TRAINING_ID__'),
			'edit_url_template' => \phpgw::link('/hrm/view/users/' . $userId . '/training/__TRAINING_ID__/edit'),
			'delete_url_template' => \phpgw::link('/hrm/view/users/' . $userId . '/training/__TRAINING_ID__/delete'),
			'can_add' => $common->check_perms2($userId, $grants, ACL_ADD),
			'can_edit' => $common->check_perms2($userId, $grants, ACL_EDIT),
			'can_delete' => $common->check_perms2($userId, $grants, ACL_DELETE),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => [$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3],
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'user', 'training'], 'hrm::user'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function view(Request $request, Response $response, array $args): Response
	{
		$userId = (int) ($args['id'] ?? 0);
		$trainingId = (int) ($args['trainingId'] ?? 0);
		$users = \CreateObject('hrm.bouser', false);
		$common = \CreateObject('hrm.bocommon');
		$grants = (array) $users->grants;

		if (!$userId || !$trainingId || !$common->check_perms2($userId, $grants, ACL_READ))
		{
			$response->getBody()->write(lang('Access not permitted'));
			return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
		}

		$values = (array) $users->read_single_training($trainingId);
		if (!$values || (int) ($values['user_id'] ?? 0) !== $userId)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		$bocategory = \CreateObject('hrm.bocategory');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Training') . ': ' . lang('view training')]);

		$html = $this->twig->render('@views/user/hrm_user_view.twig', [
			'layout' => '@views/_bare.twig',
			'training_id' => $trainingId,
			'user_id' => $userId,
			'values' => $values,
			'list_url' => \phpgw::link('/hrm/view/users/' . $userId . '/training'),
			'edit_url' => \phpgw::link('/hrm/view/users/' . $userId . '/training/' . $trainingId . '/edit'),
			'delete_url' => \phpgw::link('/hrm/view/users/' . $userId . '/training/' . $trainingId . '/delete'),
			'can_edit' => $common->check_perms2($userId, $grants, ACL_EDIT),
			'can_delete' => $common->check_perms2($userId, $grants, ACL_DELETE),
			'cat_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('training', $values['cat_id'] ?? '')),
			'skill_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('skill_level', $values['skill'] ?? '')),
			'place_list' => $this->normalizeSelectList((array) $users->select_place_list($values['place_id'] ?? '')),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'user', 'training'], 'hrm::user'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function delete(Request $request, Response $response, array $args): Response
	{
		$userId = (int) ($args['id'] ?? 0);
		$trainingId = (int) ($args['trainingId'] ?? 0);
		$users = \CreateObject('hrm.bouser', false);
		$common = \CreateObject('hrm.bocommon');
		$grants = (array) $users->grants;
		$listUrl = \phpgw::link('/hrm/view/users/' . $userId . '/training');

		if (!$userId || !$trainingId || !$common->check_perms2($userId, $grants, ACL_DELETE))
		{
			$response->getBody()->write(lang('Access not permitted'));
			return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
		}

		$values = (array) $users->read_single_training($trainingId);
		if (!$values || (int) ($values['user_id'] ?? 0) !== $userId)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		$body = (array) ($request->getParsedBody() ?: []);
		if (strtoupper($request->getMethod()) === 'POST')
		{
			if (!empty($body['confirm']))
			{
				$users->delete_training($userId, $trainingId);
			}

			return $this->redirect($response, $listUrl);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Training') . ': ' . lang('delete')]);
		$html = $this->twig->render('@views/user/hrm_user_delete.twig', [
			'layout' => '@views/_bare.twig',
			'training_id' => $trainingId,
			'user_id' => $userId,
			'values' => $values,
			'form_action' => \phpgw::link('/hrm/view/users/' . $userId . '/training/' . $trainingId . '/delete'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'user', 'training'], 'hrm::user'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function edit(Request $request, Response $response, array $args): Response
	{
		$userId = (int) ($args['id'] ?? 0);
		$trainingId = (int) ($args['trainingId'] ?? 0);
		$users = \CreateObject('hrm.bouser', false);
		$common = \CreateObject('hrm.bocommon');
		$grants = (array) $users->grants;
		$requiredAcl = $trainingId ? ACL_EDIT : ACL_ADD;

		if (!$userId || !$common->check_perms2($userId, $grants, $requiredAcl))
		{
			$response->getBody()->write(lang('Access not permitted'));
			return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
		}

		$baseValues = $this->emptyTrainingValues($userId);
		if ($trainingId)
		{
			$storedValues = (array) $users->read_single_training($trainingId);
			if (!$storedValues || (int) ($storedValues['user_id'] ?? 0) !== $userId)
			{
				$response->getBody()->write(lang('Not found'));
				return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
			}
			$baseValues = array_merge($baseValues, $storedValues, ['training_id' => $trainingId]);
		}

		$body = (array) ($request->getParsedBody() ?: []);
		$values = $baseValues;
		$errors = [];
		$messages = [];
		$listUrl = \phpgw::link('/hrm/view/users/' . $userId . '/training');

		if (strtoupper($request->getMethod()) === 'POST')
		{
			$postedValues = (array) ($body['values'] ?? []);
			if (!empty($postedValues['cancel']))
			{
				return $this->redirect($response, $listUrl);
			}

			$values = $this->trainingValuesFromBody($body, $userId, $trainingId, $baseValues);
			$errors = $this->validateTrainingValues($values);
			if (!$errors)
			{
				$receipt = (array) $users->save($values, $trainingId ? 'edit' : '');
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
					$trainingId = (int) ($receipt['training_id'] ?? $trainingId);
					if (!empty($postedValues['save']))
					{
						return $this->redirect($response, $listUrl);
					}

					if ($trainingId)
					{
						$values = array_merge($this->emptyTrainingValues($userId), (array) $users->read_single_training($trainingId), ['training_id' => $trainingId]);
					}
				}
			}
		}

		$bocategory = \CreateObject('hrm.bocategory');
		$userSettings = Settings::getInstance()->get('user');
		$dateFormat = (string) ($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');
		$functionMsg = $trainingId ? lang('edit training') : lang('add training');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Training') . ': ' . $functionMsg]);
		\phpgw::import_class('phpgwapi.jquery');
		\phpgwapi_jquery::load_widget('select2');
		\phpgwapi_jquery::load_widget('datepicker');

		$html = $this->twig->render('@views/user/hrm_user_edit.twig', [
			'layout' => '@views/_bare.twig',
			'training_id' => $trainingId,
			'user_id' => $userId,
			'values' => $values,
			'errors' => $errors,
			'messages' => $messages,
			'form_action' => $trainingId
				? \phpgw::link('/hrm/view/users/' . $userId . '/training/' . $trainingId . '/edit')
				: \phpgw::link('/hrm/view/users/' . $userId . '/training/new'),
			'list_url' => $listUrl,
			'date_format' => $dateFormat,
			'cat_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('training', $values['cat_id'] ?? '')),
			'skill_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('skill_level', $values['skill'] ?? '')),
			'place_list' => $this->normalizeSelectList((array) $users->select_place_list($values['place_id'] ?? '')),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'user', 'training'], 'hrm::user'));
		return $response->withHeader('Content-Type', 'text/html');
	}
}
