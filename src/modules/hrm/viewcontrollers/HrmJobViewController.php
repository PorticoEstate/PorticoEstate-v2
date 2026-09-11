<?php

namespace App\modules\hrm\viewcontrollers;

use App\helpers\ViewSettingsHelper;
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

	private function emptyJobValues(): array
	{
		return ['id' => 0, 'parent_id' => 0, 'entry_date' => '', 'name' => '', 'descr' => ''];
	}

	private function emptyTaskValues(int $jobId): array
	{
		return ['id' => 0, 'job_id' => $jobId, 'parent_id' => 0, 'entry_date' => '', 'name' => '', 'descr' => ''];
	}

	private function emptyQualificationValues(int $jobId): array
	{
		return ['id' => 0, 'job_id' => $jobId, 'quali_type_id' => 0, 'cat_id' => 0, 'skill_id' => 0, 'experience_id' => 0, 'entry_date' => '', 'name' => '', 'descr' => '', 'remark' => '', 'alternative_qualification' => []];
	}

	private function emptyQualificationTypeValues(): array
	{
		return ['quali_type_id' => 0, 'entry_date' => '', 'name' => '', 'descr' => ''];
	}

	private function taskBelongsToJob($jobs, int $jobId, int $taskId): bool
	{
		foreach ((array) $jobs->read_task($jobId) as $task)
		{
			if ((int) ($task['id'] ?? 0) === $taskId)
			{
				return true;
			}
		}

		return false;
	}

	private function qualificationBelongsToJob($jobs, int $jobId, int $qualificationId): bool
	{
		$values = (array) $jobs->read_single_qualification($qualificationId);
		return (int) ($values['job_id'] ?? 0) === $jobId;
	}

	private function qualificationTypeOptions($jobs, int $selected = 0): array
	{
		$jobs->start = 0;
		$jobs->query = '';
		$jobs->order = 'name';
		$jobs->sort = 'ASC';
		$jobs->allrows = true;

		return array_map(static function (array $item) use ($selected): array {
			return [
				'id' => (string) ($item['id'] ?? ''),
				'name' => (string) ($item['name'] ?? ''),
				'descr' => (string) ($item['descr'] ?? ''),
				'selected' => (int) ($item['id'] ?? 0) === $selected,
			];
		}, (array) $jobs->read_qualification_type());
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
		$rowsPerPage = ViewSettingsHelper::rowsPerPage();
		$html = $this->twig->render('@views/job/hrm_job_list.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/jobs'),
			'new_url' => \phpgw::link('/hrm/view/jobs/new'),
			'view_url_template' => \phpgw::link('/hrm/view/jobs/__JOB_ID__'),
			'edit_url_template' => \phpgw::link('/hrm/view/jobs/__JOB_ID__/edit'),
			'delete_url_template' => \phpgw::link('/hrm/view/jobs/__JOB_ID__/delete'),
			'task_url_template' => \phpgw::link('/hrm/view/jobs/__JOB_ID__/tasks'),
			'qualification_url_template' => \phpgw::link('/hrm/view/jobs/__JOB_ID__/qualifications'),
			'add_sub_url_template' => \phpgw::link('/hrm/view/jobs/new', ['parent_id' => '__JOB_ID__']),
			'reset_url' => \phpgw::link('/hrm/view/jobs/reset-hierarchy'),
			'print_url' => \phpgw::link('/hrm/view/jobs/pdf'),
			'can_add' => $this->hasJobAccess(ACL_ADD),
			'can_edit' => $this->hasJobAccess(ACL_EDIT),
			'can_delete' => $this->hasJobAccess(ACL_DELETE),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => ViewSettingsHelper::lengthMenu($rowsPerPage, true),
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

	public function tasks(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_READ))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$job = $jobId ? (array) $jobs->read_single_job($jobId) : [];
		if (!$job)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('task') . ': ' . lang('list task')]);
		$rowsPerPage = ViewSettingsHelper::rowsPerPage();
		$html = $this->twig->render('@views/job/hrm_job_task_list.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $jobId,
			'job' => $job,
			'api_url' => \phpgw::link('/hrm/jobs/' . $jobId . '/tasks'),
			'new_url' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/new'),
			'view_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/__TASK_ID__'),
			'edit_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/__TASK_ID__/edit'),
			'delete_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/__TASK_ID__/delete'),
			'move_up_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/__TASK_ID__/move/up'),
			'move_down_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/__TASK_ID__/move/down'),
			'list_url' => \phpgw::link('/hrm/view/jobs'),
			'can_add' => $this->hasJobAccess(ACL_ADD),
			'can_edit' => $this->hasJobAccess(ACL_EDIT),
			'can_delete' => $this->hasJobAccess(ACL_DELETE),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => ViewSettingsHelper::lengthMenu($rowsPerPage),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function moveTask(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_EDIT))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$taskId = (int) ($args['taskId'] ?? 0);
		$direction = (string) ($args['direction'] ?? '');
		$jobs = \CreateObject('hrm.bojob', false);
		if ($jobId && $taskId && $this->taskBelongsToJob($jobs, $jobId, $taskId))
		{
			$jobs->resort_value(['resort' => $direction, 'job_id' => $jobId, 'id' => $taskId, 'type' => 'task']);
		}

		return $this->redirect($response, \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks'));
	}

	public function viewTask(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_READ))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$taskId = (int) ($args['taskId'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$job = $jobId ? (array) $jobs->read_single_job($jobId) : [];
		$values = $taskId ? (array) $jobs->read_single_task($taskId) : [];
		if (!$job || !$values || !$this->taskBelongsToJob($jobs, $jobId, $taskId))
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('task') . ': ' . lang('view task')]);
		$html = $this->twig->render('@views/job/hrm_job_task_view.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $jobId,
			'task_id' => $taskId,
			'job' => $job,
			'values' => array_merge($this->emptyTaskValues($jobId), $values),
			'parent_list' => $this->normalizeSelectList((array) $jobs->select_task_list($values['parent_id'] ?? 0, $taskId, $jobId)),
			'list_url' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks'),
			'edit_url' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/' . $taskId . '/edit'),
			'delete_url' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/' . $taskId . '/delete'),
			'can_edit' => $this->hasJobAccess(ACL_EDIT),
			'can_delete' => $this->hasJobAccess(ACL_DELETE),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function editTask(Request $request, Response $response, array $args): Response
	{
		$taskId = (int) ($args['taskId'] ?? 0);
		$requiredAcl = $taskId ? ACL_EDIT : ACL_ADD;
		if (!$this->hasJobAccess($requiredAcl))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$job = $jobId ? (array) $jobs->read_single_job($jobId) : [];
		$baseValues = $this->emptyTaskValues($jobId);
		if (!$job)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}
		if ($taskId)
		{
			$storedValues = (array) $jobs->read_single_task($taskId);
			if (!$storedValues || !$this->taskBelongsToJob($jobs, $jobId, $taskId))
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
		$listUrl = \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks');

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
				$values['job_id'] = $jobId;
				$values['parent_id'] = (int) ($values['parent_id'] ?? 0);
				if (trim((string) ($values['name'] ?? '')) === '')
				{
					$errors[] = lang('Please enter a name !');
				}

				if (!$errors)
				{
					if ($taskId)
					{
						$values['id'] = $taskId;
					}
					$receipt = (array) $jobs->save_task($values, $taskId ? 'edit' : '');
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
						$taskId = (int) ($receipt['id'] ?? $taskId);
						if (!empty($postedValues['save']))
						{
							return $this->redirect($response, $listUrl);
						}

						if ($taskId)
						{
							$values = array_merge($this->emptyTaskValues($jobId), (array) $jobs->read_single_task($taskId));
						}
					}
				}
			}
		}

		$functionMsg = $taskId ? lang('edit task') : lang('add task');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('task') . ': ' . $functionMsg]);
		$html = $this->twig->render('@views/job/hrm_job_task_edit.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $jobId,
			'task_id' => $taskId,
			'job' => $job,
			'values' => $values,
			'errors' => $errors,
			'messages' => $messages,
			'parent_list' => $this->normalizeSelectList((array) $jobs->select_task_list($values['parent_id'] ?? 0, $taskId, $jobId)),
			'form_action' => $taskId ? \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/' . $taskId . '/edit') : \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/new'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function deleteTask(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_DELETE))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$taskId = (int) ($args['taskId'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$job = $jobId ? (array) $jobs->read_single_job($jobId) : [];
		$values = $taskId ? (array) $jobs->read_single_task($taskId) : [];
		$listUrl = \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks');
		if (!$job || !$values || !$this->taskBelongsToJob($jobs, $jobId, $taskId))
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		if (strtoupper($request->getMethod()) === 'POST')
		{
			$body = (array) ($request->getParsedBody() ?: []);
			if (!empty($body['confirm']))
			{
				$jobs->delete_task($taskId);
			}

			return $this->redirect($response, $listUrl);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('task') . ': ' . lang('delete')]);
		$html = $this->twig->render('@views/job/hrm_job_task_delete.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $jobId,
			'task_id' => $taskId,
			'job' => $job,
			'values' => array_merge($this->emptyTaskValues($jobId), $values),
			'form_action' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/tasks/' . $taskId . '/delete'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function qualifications(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_READ))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$job = $jobId ? (array) $jobs->read_single_job($jobId) : [];
		if (!$job)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('qualification') . ': ' . lang('list qualification')]);
		$rowsPerPage = ViewSettingsHelper::rowsPerPage();
		$html = $this->twig->render('@views/job/hrm_job_qualification_list.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $jobId,
			'job' => $job,
			'api_url' => \phpgw::link('/hrm/jobs/' . $jobId . '/qualifications'),
			'new_url' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/new'),
			'view_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/__QUALIFICATION_ID__'),
			'edit_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/__QUALIFICATION_ID__/edit'),
			'delete_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/__QUALIFICATION_ID__/delete'),
			'move_up_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/__QUALIFICATION_ID__/move/up'),
			'move_down_url_template' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/__QUALIFICATION_ID__/move/down'),
			'list_url' => \phpgw::link('/hrm/view/jobs'),
			'can_add' => $this->hasJobAccess(ACL_ADD),
			'can_edit' => $this->hasJobAccess(ACL_EDIT),
			'can_delete' => $this->hasJobAccess(ACL_DELETE),
			'rows_per_page' => $rowsPerPage,
			'length_menu' => ViewSettingsHelper::lengthMenu($rowsPerPage),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function qualificationTypes(Request $request, Response $response): Response
	{
		if (!$this->hasJobAccess(ACL_READ))
		{
			return $this->deny($response);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('qualification') . ': ' . lang('list qualification')]);
		$html = $this->twig->render('@views/job/hrm_job_qualification_type_list.twig', [
			'layout' => '@views/_bare.twig',
			'api_url' => \phpgw::link('/hrm/qualification-types'),
			'new_url' => \phpgw::link('/hrm/view/qualification-types/new'),
			'edit_url_template' => \phpgw::link('/hrm/view/qualification-types/__QUALIFICATION_TYPE_ID__/edit'),
			'list_url' => \phpgw::link('/hrm/view/jobs'),
			'can_add' => $this->hasJobAccess(ACL_ADD),
			'can_edit' => $this->hasJobAccess(ACL_EDIT),
			'rows_per_page' => ViewSettingsHelper::rowsPerPage(),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function editQualificationType(Request $request, Response $response, array $args): Response
	{
		$qualificationTypeId = (int) ($args['qualificationTypeId'] ?? 0);
		$requiredAcl = $qualificationTypeId ? ACL_EDIT : ACL_ADD;
		if (!$this->hasJobAccess($requiredAcl))
		{
			return $this->deny($response);
		}

		$jobs = \CreateObject('hrm.bojob', false);
		$baseValues = $this->emptyQualificationTypeValues();
		if ($qualificationTypeId)
		{
			$storedValues = (array) $jobs->read_single_qualification_type($qualificationTypeId);
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
		$listUrl = \phpgw::link('/hrm/view/qualification-types');

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
				if (trim((string) ($values['name'] ?? '')) === '')
				{
					$errors[] = lang('Please enter a name !');
				}

				if (!$errors)
				{
					if ($qualificationTypeId)
					{
						$values['quali_type_id'] = $qualificationTypeId;
					}
					$receipt = (array) $jobs->save_qualification_type($values, $qualificationTypeId ? 'edit' : '');
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
						$qualificationTypeId = (int) ($receipt['quali_type_id'] ?? $qualificationTypeId);
						if (!empty($postedValues['save']))
						{
							return $this->redirect($response, $listUrl);
						}

						if ($qualificationTypeId)
						{
							$values = array_merge($this->emptyQualificationTypeValues(), (array) $jobs->read_single_qualification_type($qualificationTypeId));
						}
					}
				}
			}
		}

		$functionMsg = $qualificationTypeId ? lang('edit qualification type') : lang('add qualification type');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('qualification') . ': ' . $functionMsg]);
		$html = $this->twig->render('@views/job/hrm_job_qualification_type_edit.twig', [
			'layout' => '@views/_bare.twig',
			'qualification_type_id' => $qualificationTypeId,
			'values' => $values,
			'errors' => $errors,
			'messages' => $messages,
			'form_action' => $qualificationTypeId ? \phpgw::link('/hrm/view/qualification-types/' . $qualificationTypeId . '/edit') : \phpgw::link('/hrm/view/qualification-types/new'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function moveQualification(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_EDIT))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$qualificationId = (int) ($args['qualificationId'] ?? 0);
		$direction = (string) ($args['direction'] ?? '');
		$jobs = \CreateObject('hrm.bojob', false);
		if ($jobId && $qualificationId && $this->qualificationBelongsToJob($jobs, $jobId, $qualificationId))
		{
			$jobs->resort_value(['resort' => $direction, 'job_id' => $jobId, 'id' => $qualificationId, 'type' => 'qualification']);
		}

		return $this->redirect($response, \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications'));
	}

	public function viewQualification(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_READ))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$qualificationId = (int) ($args['qualificationId'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$job = $jobId ? (array) $jobs->read_single_job($jobId) : [];
		$values = $qualificationId ? (array) $jobs->read_single_qualification($qualificationId) : [];
		if (!$job || !$values || !$this->qualificationBelongsToJob($jobs, $jobId, $qualificationId))
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		$bocategory = \CreateObject('hrm.bocategory');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('qualification') . ': ' . lang('view qualification')]);
		$html = $this->twig->render('@views/job/hrm_job_qualification_view.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $jobId,
			'qualification_id' => $qualificationId,
			'job' => $job,
			'values' => array_merge($this->emptyQualificationValues($jobId), $values),
			'cat_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('qualification', $values['cat_id'] ?? 0)),
			'skill_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('skill_level', $values['skill_id'] ?? 0)),
			'experience_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('experience', $values['experience_id'] ?? 0)),
			'alternative_list' => $this->normalizeSelectList((array) $jobs->select_qualification_list($jobId, $qualificationId)),
			'list_url' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications'),
			'edit_url' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/' . $qualificationId . '/edit'),
			'delete_url' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/' . $qualificationId . '/delete'),
			'can_edit' => $this->hasJobAccess(ACL_EDIT),
			'can_delete' => $this->hasJobAccess(ACL_DELETE),
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function editQualification(Request $request, Response $response, array $args): Response
	{
		$qualificationId = (int) ($args['qualificationId'] ?? 0);
		$requiredAcl = $qualificationId ? ACL_EDIT : ACL_ADD;
		if (!$this->hasJobAccess($requiredAcl))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$job = $jobId ? (array) $jobs->read_single_job($jobId) : [];
		$baseValues = $this->emptyQualificationValues($jobId);
		if (!$job)
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}
		if ($qualificationId)
		{
			$storedValues = (array) $jobs->read_single_qualification($qualificationId);
			if (!$storedValues || !$this->qualificationBelongsToJob($jobs, $jobId, $qualificationId))
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
		$listUrl = \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications');

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
				$values['job_id'] = $jobId;
				$values['quali_type_id'] = (int) ($values['quali_type_id'] ?? 0);
				$values['cat_id'] = (int) ($values['cat_id'] ?? 0);
				$values['skill_id'] = (int) ($values['skill_id'] ?? 0);
				$values['experience_id'] = (int) ($values['experience_id'] ?? 0);
				$values['alternative_qualification'] = (array) ($values['alternative_qualification'] ?? []);
				if (!$values['cat_id'])
				{
					$errors[] = lang('Please select a category !');
				}
				if (!$values['quali_type_id'])
				{
					$errors[] = lang('Please enter a name !');
				}

				if (!$errors)
				{
					if ($qualificationId)
					{
						$values['quali_id'] = $qualificationId;
					}
					$receipt = (array) $jobs->save_qualification($values, $qualificationId ? 'edit' : '');
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
						$qualificationId = (int) ($receipt['quali_id'] ?? $qualificationId);
						if (!empty($postedValues['save']))
						{
							return $this->redirect($response, $listUrl);
						}

						if ($qualificationId)
						{
							$values = array_merge($this->emptyQualificationValues($jobId), (array) $jobs->read_single_qualification($qualificationId));
						}
					}
				}
			}
		}

		$bocategory = \CreateObject('hrm.bocategory');
		$functionMsg = $qualificationId ? lang('edit qualification') : lang('add qualification');
		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('qualification') . ': ' . $functionMsg]);
		$html = $this->twig->render('@views/job/hrm_job_qualification_edit.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $jobId,
			'qualification_id' => $qualificationId,
			'job' => $job,
			'values' => $values,
			'errors' => $errors,
			'messages' => $messages,
			'qualification_types' => $this->qualificationTypeOptions($jobs, (int) ($values['quali_type_id'] ?? 0)),
			'cat_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('qualification', $values['cat_id'] ?? 0)),
			'skill_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('skill_level', $values['skill_id'] ?? 0)),
			'experience_list' => $this->normalizeSelectList((array) $bocategory->select_category_list('experience', $values['experience_id'] ?? 0)),
			'alternative_list' => $this->normalizeSelectList((array) $jobs->select_qualification_list($jobId, $qualificationId)),
			'qualification_type_url' => \phpgw::link('/hrm/view/qualification-types'),
			'form_action' => $qualificationId ? \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/' . $qualificationId . '/edit') : \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/new'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
		return $response->withHeader('Content-Type', 'text/html');
	}

	public function deleteQualification(Request $request, Response $response, array $args): Response
	{
		if (!$this->hasJobAccess(ACL_DELETE))
		{
			return $this->deny($response);
		}

		$jobId = (int) ($args['jobId'] ?? 0);
		$qualificationId = (int) ($args['qualificationId'] ?? 0);
		$jobs = \CreateObject('hrm.bojob', false);
		$job = $jobId ? (array) $jobs->read_single_job($jobId) : [];
		$values = $qualificationId ? (array) $jobs->read_single_qualification($qualificationId) : [];
		$listUrl = \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications');
		if (!$job || !$values || !$this->qualificationBelongsToJob($jobs, $jobId, $qualificationId))
		{
			$response->getBody()->write(lang('Not found'));
			return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
		}

		if (strtoupper($request->getMethod()) === 'POST')
		{
			$body = (array) ($request->getParsedBody() ?: []);
			if (!empty($body['confirm']))
			{
				$jobs->delete_qualification($jobId, $qualificationId);
			}

			return $this->redirect($response, $listUrl);
		}

		Settings::getInstance()->update('flags', ['app_header' => lang('hrm') . ' - ' . lang('qualification') . ': ' . lang('delete')]);
		$html = $this->twig->render('@views/job/hrm_job_qualification_delete.twig', [
			'layout' => '@views/_bare.twig',
			'job_id' => $jobId,
			'qualification_id' => $qualificationId,
			'job' => $job,
			'values' => array_merge($this->emptyQualificationValues($jobId), $values),
			'form_action' => \phpgw::link('/hrm/view/jobs/' . $jobId . '/qualifications/' . $qualificationId . '/delete'),
			'list_url' => $listUrl,
		]);

		$response->getBody()->write($this->legacyView->render($html, ['hrm', 'job', 'job_type'], 'hrm::job::job_type'));
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