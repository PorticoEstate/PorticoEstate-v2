<?php

namespace App\modules\hrm\viewcontrollers;

use App\modules\hrm\helpers\ViewSettingsHelper;
use App\modules\phpgwapi\controllers\Accounts\Accounts;
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

	private function sendPdf(Response $response, string $document, string $documentName): Response
	{
		$fileName = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $documentName) . '.pdf';
		$response->getBody()->write($document);

		return $response
			->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
			->withHeader('Content-Type', 'application/x-pdf')
			->withHeader('Content-Length', (string) strlen($document))
			->withHeader('Pragma', 'public')
			->withHeader('Expires', '0')
			->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
	}

	public function index(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('user') . ': ' . lang('list user')]);
		$rowsPerPage = ViewSettingsHelper::rowsPerPage();

		$html = $this->twig->render('@views/user/hrm_user_list.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/users'),
			'training_url_template' => \phpgw::link('/hrm/view/users/__USER_ID__/training'),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => ViewSettingsHelper::lengthMenu($rowsPerPage),
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
		$rowsPerPage = ViewSettingsHelper::rowsPerPage();
		$html = $this->twig->render('@views/user/hrm_user_training.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/users/' . $userId . '/training'),
			'list_url' => \phpgw::link('/hrm/view/users'),
			'user_values' => $users->get_user_data($userId),
			'cv_url' => \phpgw::link('/hrm/view/users/' . $userId . '/training/cv'),
			'new_url' => \phpgw::link('/hrm/view/users/' . $userId . '/training/new'),
			'view_url_template' => \phpgw::link('/hrm/view/users/' . $userId . '/training/__TRAINING_ID__'),
			'edit_url_template' => \phpgw::link('/hrm/view/users/' . $userId . '/training/__TRAINING_ID__/edit'),
			'delete_url_template' => \phpgw::link('/hrm/view/users/' . $userId . '/training/__TRAINING_ID__/delete'),
			'can_add' => $common->check_perms2($userId, $grants, ACL_ADD),
			'can_edit' => $common->check_perms2($userId, $grants, ACL_EDIT),
			'can_delete' => $common->check_perms2($userId, $grants, ACL_DELETE),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => ViewSettingsHelper::lengthMenu($rowsPerPage),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'user', 'training'], 'hrm::user'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function viewCv(Request $request, Response $response, array $args): Response
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

		$userValues = (array) $users->get_user_data($userId);
		$users->allrows = true;
		$users->order = 'start_date, category';
		$users->sort = 'ASC';
		$training = (array) $users->read_training($userId);
		$userSettings = Settings::getInstance()->get('user');
		$dateFormat = (string) ($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');
		$phpgwapiCommon = new \phpgwapi_common();
		$pdf = \CreateObject('phpgwapi.pdf');
		$contentHeading = [];

		foreach ($userValues as $entry)
		{
			if (empty($entry['value']))
			{
				continue;
			}

			$contentHeading[] = [
				'name' => $entry['name'],
				'value' => $entry['value'],
			];
		}

		$date = $phpgwapiCommon->show_date('', $dateFormat);
		set_time_limit(1800);
		$pdf->ezSetMargins(90, 70, 50, 50);
		$pdf->selectFont('Helvetica');

		$all = $pdf->openObject();
		$pdf->saveState();
		$pdf->setStrokeColor(0, 0, 0, 1);
		$pdf->line(20, 760, 578, 760);
		$pdf->line(200, 40, 200, 822);
		$pdf->addText(220, 770, 16, 'CV');
		$pdf->addText(300, 34, 6, $date);
		$pdf->restoreState();
		$pdf->closeObject();
		$pdf->addObject($all, 'all');
		$pdf->ezStartPageNumbers(500, 28, 10, 'right', '{PAGENUM} ' . lang('of') . ' {TOTALPAGENUM}', 1);

		$pdf->ezTable(
			$contentHeading,
			'',
			'',
			[
				'xPos' => 220,
				'xOrientation' => 'right',
				'width' => 300,
				0,
				'shaded' => 0,
				'fontSize' => 10,
				'gridlines' => 0,
				'titleFontSize' => 12,
				'outerLineThickness' => 0,
				'showHeadings' => 0,
				'cols' => [
					'text' => ['justification' => 'left', 'width' => 100],
					'value' => ['justification' => 'left', 'width' => 200],
				],
			]
		);

		$tableHeader = [
			'start_date' => ['justification' => 'left', 'width' => 70],
			'sep' => ['justification' => 'center', 'width' => 15],
			'end_date' => ['justification' => 'left', 'width' => 70],
			'spacer' => ['width' => 15],
			'what' => ['justification' => 'left', 'width' => 300],
		];

		$categoryOld = '';
		foreach ($training as $entry)
		{
			if (($entry['category'] ?? '') !== $categoryOld)
			{
				$content = [[
					'start_date' => '',
					'sep' => '',
					'end_date' => '',
					'spacer' => '',
					'what' => $entry['category'] ?? '',
				]];
				$pdf->ezSetDy(-20);
				$pdf->ezTable(
					$content,
					'',
					'',
					[
						'xPos' => 50,
						'xOrientation' => 'right',
						'width' => 500,
						'shaded' => 0,
						'fontSize' => 12,
						'gridlines' => 0,
						'titleFontSize' => 12,
						'outerLineThickness' => 2,
						'showHeadings' => 0,
						'cols' => $tableHeader,
					]
				);
			}

			$categoryOld = (string) ($entry['category'] ?? '');
			$startDate = !empty($entry['start_date']) ? $phpgwapiCommon->show_date($entry['start_date'], $dateFormat) : '';
			$endDate = !empty($entry['end_date']) ? $phpgwapiCommon->show_date($entry['end_date'], $dateFormat) : '';
			$content = [[
				'start_date' => $startDate,
				'sep' => '-',
				'end_date' => $endDate,
				'spacer' => '',
				'what' => (string) ($entry['title'] ?? '') . ', ' . (string) ($entry['place'] ?? ''),
			]];

			$pdf->ezTable(
				$content,
				'',
				'',
				[
					'xPos' => 50,
					'xOrientation' => 'right',
					'width' => 500,
					0,
					'shaded' => 0,
					'fontSize' => 10,
					'gridlines' => 0,
					'titleFontSize' => 12,
					'outerLineThickness' => 2,
					'showHeadings' => 0,
					'cols' => $tableHeader,
				]
			);
		}

		$accounts = new Accounts();
		$documentName = 'CV_' . $accounts->id2name($userId);

		return $this->sendPdf($response, $pdf->ezOutput(), $documentName);
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
