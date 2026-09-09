<?php

namespace App\modules\hrm\viewcontrollers;

use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HrmJobViewController
{
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;

	public function __construct()
	{
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('hrm');
	}

	private function hasJobAccess(int $acl): bool
	{
		return (bool) \CreateObject('phpgwapi.acl')->check('.job', $acl, 'hrm');
	}

	private function deny(Response $response): Response
	{
		$response->getBody()->write(lang('Access not permitted'));
		return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
	}

	private function redirect(Response $response, string $url): Response
	{
		return $response->withHeader('Location', $url)->withStatus(302);
	}

	private function rowsPerPage(): int
	{
		$user = Settings::getInstance()->get('user');
		return isset($user['preferences']['common']['maxmatchs']) && (int) $user['preferences']['common']['maxmatchs'] > 0
			? (int) $user['preferences']['common']['maxmatchs']
			: 10;
	}

	private function emptyJobValues(): array
	{
		return ['id' => 0, 'parent_id' => 0, 'entry_date' => '', 'name' => '', 'descr' => ''];
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
		if (!$this->hasJobAccess(ACL_READ))
		{
			return $this->deny($response);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('job') . ': ' . lang('list job')]);
		$rowsPerPage = $this->rowsPerPage();
		$html = $this->twig->render('@views/job/hrm_job_list.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/jobs'),
			'new_url' => \phpgw::link('/hrm/view/jobs/new'),
			'view_url_template' => \phpgw::link('/hrm/view/jobs/__JOB_ID__'),
			'edit_url_template' => \phpgw::link('/hrm/view/jobs/__JOB_ID__/edit'),
			'delete_url_template' => \phpgw::link('/hrm/view/jobs/__JOB_ID__/delete'),
			'task_url_template' => \phpgw::link('/index.php', ['menuaction' => 'hrm.uijob.task', 'job_id' => '__JOB_ID__'], true),
			'qualification_url_template' => \phpgw::link('/index.php', ['menuaction' => 'hrm.uijob.qualification', 'job_id' => '__JOB_ID__'], true),
			'add_sub_url_template' => \phpgw::link('/hrm/view/jobs/new', ['parent_id' => '__JOB_ID__']),
			'reset_url' => \phpgw::link('/hrm/view/jobs/reset-hierarchy'),
			'print_url' => \phpgw::link('/hrm/view/jobs/pdf'),
			'can_add' => $this->hasJobAccess(ACL_ADD),
			'can_edit' => $this->hasJobAccess(ACL_EDIT),
			'can_delete' => $this->hasJobAccess(ACL_DELETE),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => [$rowsPerPage, $rowsPerPage * 2, $rowsPerPage * 3],
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function hierarchy(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('Organisation')]);
		$html = $this->twig->render('@views/job/hrm_job_hierarchy.twig', ['layout' => '@views/_bare.twig']);
		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'organisation'], 'hrm::job::organisation'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function view(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_READ))
		{
			return $this->deny($response);
		}

		$id = (int) ($args['id'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$values = $id ? (array) $jobs->read_single_job($id) : [];
		if (!$values)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('job') . ': ' . lang('view job')]);
		$html = $this->twig->render('@views/job/hrm_job_view.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $id,
			'values' => array_merge($this->emptyJobValues(), $values),
			'parent_list' => $this->normalizeSelectList((array) $jobs->select_job_list($values['parent_id'] ?? 0)),
			'list_url' => \phpgw::link('/hrm/view/jobs'),
			'edit_url' => \phpgw::link('/hrm/view/jobs/' . $id . '/edit'),
			'delete_url' => \phpgw::link('/hrm/view/jobs/' . $id . '/delete'),
			'can_edit' => $this->hasJobAccess(ACL_EDIT),
			'can_delete' => $this->hasJobAccess(ACL_DELETE),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function edit(Request $request, Response $response, array $args): Response
	{
		$id = (int) ($args['id'] ?? 0);
		$requiredAcl = $id ? ACL_EDIT : ACL_ADD;
		if (!$this->hasJobAccess($requiredAcl))
		{
			return $this->deny($response);
		}

		$jobs = \CreateObject('hrm.bojob', false);
		$baseValues = $this->emptyJobValues();
		if ($id)
		{
			$storedValues = (array) $jobs->read_single_job($id);
			if (!$storedValues)
			{
				$response->getBody()->write(lang('Not found'));
				return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
			}
			$baseValues = array_merge($baseValues, $storedValues);
		}
		else
		{
			$query = $request->getQueryParams();
			$baseValues['parent_id'] = (int) ($query['parent_id'] ?? 0);
		}

		$body = (array) ($request->getParsedBody() ?: []);
		$values = $baseValues;
		$errors = [];
		$messages = [];
		$listUrl = \phpgw::link('/hrm/view/jobs');

		if (strtoupper($request->getMethod()) === 'POST')
		{
			$postedValues = (array) ($body['values'] ?? []);
			if (!empty($postedValues['cancel']))
			{
				return $this->redirect($response, $listUrl);
			}

			if (!empty($postedValues['save']) || !empty($postedValues['apply']))
			{
				$values = array_merge($baseValues, $postedValues);
				$values['parent_id'] = (int) ($values['parent_id'] ?? 0);
				if (trim((string) ($values['name'] ?? '')) === '')
				{
					$errors[] = lang('Please enter a name !');
				}

				if (!$errors)
				{
					if ($id)
					{
						$values['id'] = $id;
					}
					$receipt = (array) $jobs->save_job($values, $id ? 'edit' : '');
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
						$id = (int) ($receipt['id'] ?? $id);
						if (!empty($postedValues['save']))
						{
							return $this->redirect($response, $listUrl);
						}

						if ($id)
						{
							$values = array_merge($this->emptyJobValues(), (array) $jobs->read_single_job($id));
						}
					}
				}
			}
		}

		$functionMsg = $id ? lang('edit job') : lang('add job');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('job') . ': ' . $functionMsg]);
		$html = $this->twig->render('@views/job/hrm_job_edit.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $id,
			'values' => $values,
			'errors' => $errors,
			'messages' => $messages,
			'parent_list' => $this->normalizeSelectList((array) $jobs->select_job_list($values['parent_id'] ?? 0)),
			'form_action' => $id ? \phpgw::link('/hrm/view/jobs/' . $id . '/edit') : \phpgw::link('/hrm/view/jobs/new'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function delete(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_DELETE))
		{
			return $this->deny($response);
		}

		$id = (int) ($args['id'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$values = $id ? (array) $jobs->read_single_job($id) : [];
		$listUrl = \phpgw::link('/hrm/view/jobs');
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
				$jobs->delete_job($id);
			}

			return $this->redirect($response, $listUrl);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('job') . ': ' . lang('delete')]);
		$html = $this->twig->render('@views/job/hrm_job_delete.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $id,
			'values' => array_merge($this->emptyJobValues(), $values),
			'form_action' => \phpgw::link('/hrm/view/jobs/' . $id . '/delete'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function resetHierarchy(Request $request, Response $response): Response
	{
		if (!$this->hasJobAccess(ACL_DELETE))
		{
			return $this->deny($response);
		}

		$jobs = \CreateObject('hrm.bojob', false);
		$listUrl = \phpgw::link('/hrm/view/jobs');
		if (strtoupper($request->getMethod()) === 'POST')
		{
			$body = (array) ($request->getParsedBody() ?: []);
			if (!empty($body['confirm']))
			{
				$jobs->reset_job_type_hierarchy();
			}

			return $this->redirect($response, $listUrl);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('job') . ': ' . lang('reset hierarchy')]);
		$html = $this->twig->render('@views/job/hrm_job_reset.twig', [
			'layout' => '@views/_bare.twig',
			'form_action' => \phpgw::link('/hrm/view/jobs/reset-hierarchy'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function printPdf(Request $request, Response $response): Response
	{
		if (!$this->hasJobAccess(ACL_READ))
		{
			return $this->deny($response);
		}

		$body = (array) ($request->getParsedBody() ?: []);
		$selected = array_map('intval', (array) ($body['values']['select'] ?? $body['select'] ?? []));
		if (!$selected)
		{
			return $this->redirect($response, \phpgw::link('/hrm/view/jobs'));
		}

		$jobs = \CreateObject('hrm.bojob', false);
		$userSettings = Settings::getInstance()->get('user');
		$dateFormat = (string) ($userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d');
		$phpgwapiCommon = new \phpgwapi_common();
		$pdf = \CreateObject('phpgwapi.pdf');
		$date = $phpgwapiCommon->show_date('', $dateFormat);
		set_time_limit(1800);
		$pdf->ezSetMargins(50, 70, 100, 50);
		$pdf->selectFont('Helvetica');

		$all = $pdf->openObject();
		$pdf->saveState();
		$pdf->setStrokeColor(0, 0, 0, 1);
		$pdf->addText(220, 770, 16, 'Stillingsbeskrivelse');
		$pdf->addText(300, 34, 6, $date);
		$pdf->restoreState();
		$pdf->closeObject();
		$pdf->addObject($all, 'all');
		$pdf->ezStartPageNumbers(500, 28, 10, 'right', '{PAGENUM} ' . lang('of') . ' {TOTALPAGENUM}', 1);
		$pdf->ezSetDy(-50);

		$index = 0;
		foreach ($selected as $jobId)
		{
			if ($index > 0)
			{
				$pdf->ezNewPage();
			}
			$jobInfo = (array) $jobs->read_single_job($jobId);
			if (!$jobInfo)
			{
				continue;
			}

			$qualification = (array) $jobs->read_qualification($jobId);
			$task = (array) $jobs->read_task($jobId);
			$index++;

			$pdf->ezSetY(720);
			$pdf->ezText($jobInfo['name'] ?? '', 14);
			$pdf->ezSetDy(-10);
			$pdf->ezText(lang('tasks') . ':', 12);
			$pdf->ezSetDy(-5);
			$counter = 1;
			foreach ($task as $entry)
			{
				$text = $counter . ' ' . (string) ($entry['name'] ?? '');
				$pdf->ezText($text . (!empty($entry['descr']) ? ': ' : ''), 12, ['left' => 10]);
				if (!empty($entry['descr']))
				{
					$pdf->ezText($entry['descr'], 12, ['left' => 30]);
				}
				$counter++;
			}

			$pdf->ezSetDy(-10);
			$pdf->ezText(lang('qualification') . ':', 12);
			$pdf->ezSetDy(-5);
			$counter = 1;
			foreach ($qualification as $entry)
			{
				$text = $counter . ' ' . (string) ($entry['name'] ?? '');
				$pdf->ezText($text . (!empty($entry['descr']) ? ': ' : ''), 12, ['left' => 10]);
				if (!empty($entry['descr']))
				{
					$pdf->ezText($entry['descr'], 12, ['left' => 30]);
				}
				$counter++;
			}
		}

		return $this->sendPdf($response, $pdf->ezOutput(), 'job');
	}
}