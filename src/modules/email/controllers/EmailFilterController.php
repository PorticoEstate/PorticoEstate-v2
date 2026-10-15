<?php

namespace App\modules\email\controllers;

use App\modules\email\services\EmailFilterSerializer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailFilterController
{
	public function editor(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$filterNum = (string) ($query['filter_num'] ?? 'add_new');
		if ($filterNum !== 'add_new' && (!ctype_digit($filterNum) || (int) $filterNum < 0))
		{
			return $this->json($response, ['error' => lang('Invalid filter number')], 400);
		}

		$originalGet = $_GET;
		$_GET['filter_num'] = $filterNum;
		try
		{
			$businessObject = \CreateObject('email.bofilters');
			$allFilters = (array) ($businessObject->all_filters ?? []);
			$filterExists = $filterNum !== 'add_new' && $businessObject->filter_exists((int) $filterNum);
			$filter = $filterExists ? (array) $allFilters[(int) $filterNum] : [];
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$accounts = [];
			foreach ((array) $mail->extra_and_default_acounts as $account)
			{
				$account = (array) $account;
				if (($account['status'] ?? '') !== 'enabled') continue;
				$accountNum = (int) ($account['acctnum'] ?? 0);
				$accounts[] = [
					'id' => $accountNum,
					'label' => trim((string) $mail->get_pref_value('fullname', $accountNum)) ?: sprintf('%s %d', lang('Account'), $accountNum),
				];
			}

			$destination = [];
			$action = (array) ($filter['actions'][0] ?? []);
			parse_str((string) ($action['folder'] ?? ''), $destination);
			$filterData = [
				'id' => $filterNum,
				'name' => (string) ($filter['filtername'] ?? 'My Mail Filter'),
				'sourceAccounts' => array_map(static fn(array $source): int => (int) ($source['acctnum'] ?? 0), (array) ($filter['source_accounts'] ?? [['acctnum' => 0]])),
				'matches' => array_values((array) ($filter['matches'] ?? [])),
				'action' => [
					'judgement' => (string) ($action['judgement'] ?? 'fileinto'),
					'account' => (int) ($destination['acctnum'] ?? 0),
					'folder' => (string) ($destination['folder'] ?? ''),
					'actiontext' => (string) ($action['actiontext'] ?? ''),
					'stopFiltering' => !empty($action['stop_filtering']),
				],
			];
			while (count($filterData['matches']) < 3) $filterData['matches'][] = [];
			return $this->json($response, ['filter' => $filterData, 'accounts' => $accounts]);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, [
				'error' => lang('Unable to load filter editor'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			$_GET = $originalGet;
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	public function save(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$input = is_array($input) ? $input : [];
		$filterNum = (string) ($input['id'] ?? 'add_new');
		$name = trim((string) ($input['name'] ?? ''));
		$sources = $input['sourceAccounts'] ?? null;
		$matches = $input['matches'] ?? null;
		$action = (array) ($input['action'] ?? []);
		$validId = $filterNum === 'add_new' || (ctype_digit($filterNum) && (int) $filterNum >= 0);
		$validAction = ($action['judgement'] ?? '') === 'fileinto'
			&& filter_var($action['account'] ?? null, FILTER_VALIDATE_INT) !== false
			&& (int) ($action['account'] ?? -1) >= 0
			&& is_string($action['folder'] ?? null) && trim((string) $action['folder']) !== '';
		$firstMatch = is_array($matches) ? (array) ($matches[0] ?? []) : [];
		if (!$validId || $name === '' || strlen($name) > 255 || !is_array($sources) || !$sources || !is_array($matches) || count($matches) !== 3 || trim((string) ($firstMatch['matchthis'] ?? '')) === '' || !$validAction)
		{
			return $this->json($response, ['error' => lang('Invalid filter data')], 422);
		}

		$allowedExamine = ['from', 'to', 'cc', 'recipient', 'sender', 'subject', 'received'];
		$allowedComparator = ['contains', 'notcontains'];
		$legacyPost = [
			'filter_num' => $filterNum,
			'filtername' => $name,
			'source_accounts' => [],
		];
		foreach ($sources as $sourceAccount)
		{
			$sourceAccount = filter_var($sourceAccount, FILTER_VALIDATE_INT);
			if ($sourceAccount === false || $sourceAccount < 0)
			{
				return $this->json($response, ['error' => lang('Invalid source account')], 422);
			}
			$legacyPost['source_accounts'][] = http_build_query(['folder' => 'INBOX', 'acctnum' => $sourceAccount]);
		}
		foreach (array_values($matches) as $index => $match)
		{
			$match = (array) $match;
			$examine = (string) ($match['examine'] ?? 'from');
			$comparator = (string) ($match['comparator'] ?? 'contains');
			$andor = (string) ($match['andor'] ?? ($index === 0 ? '' : 'ignore_me'));
			if (!in_array($examine, $allowedExamine, true) || !in_array($comparator, $allowedComparator, true) || !in_array($andor, ['', 'and', 'or', 'ignore_me'], true))
			{
				return $this->json($response, ['error' => lang('Invalid filter condition')], 422);
			}
			$legacyPost['match_' . $index] = [
				'examine' => $examine,
				'comparator' => $comparator,
				'matchthis' => trim((string) ($match['matchthis'] ?? '')),
			];
			if ($index > 0) $legacyPost['match_' . $index]['andor'] = $andor;
		}
		$legacyPost['action_0'] = [
			'judgement' => 'fileinto',
			'folder' => http_build_query(['folder' => (string) $action['folder'], 'acctnum' => (int) $action['account']]),
			'actiontext' => trim((string) ($action['actiontext'] ?? '')),
		];
		if (!empty($action['stopFiltering'])) $legacyPost['action_0']['stop_filtering'] = 'True';

		$originalPost = $_POST;
		$_POST = array_replace($originalPost, $legacyPost);
		try
		{
			$businessObject = \CreateObject('email.bofilters');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$enabledAccounts = [];
			foreach ((array) $mail->extra_and_default_acounts as $account)
			{
				$account = (array) $account;
				if (($account['status'] ?? '') === 'enabled') $enabledAccounts[] = (int) ($account['acctnum'] ?? 0);
			}
			parse_str($legacyPost['action_0']['folder'], $destination);
			$destinationAccount = (int) ($destination['acctnum'] ?? -1);
			if (!in_array($destinationAccount, $enabledAccounts, true))
			{
				return $this->json($response, ['error' => lang('Invalid destination account')], 422);
			}
			foreach ($legacyPost['source_accounts'] as $sourceAccount)
			{
				parse_str($sourceAccount, $sourceData);
				if (!in_array((int) ($sourceData['acctnum'] ?? -1), $enabledAccounts, true))
				{
					return $this->json($response, ['error' => lang('Invalid source account')], 422);
				}
			}
			$savedFilterNum = $businessObject->obtain_filer_num();
			if (!$businessObject->process_submitted_data(true))
			{
				return $this->json($response, ['error' => lang('Unable to save filter')], 422);
			}
			return $this->json($response, ['saved' => true, 'id' => $savedFilterNum]);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, [
				'error' => lang('Unable to save filter'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			$_POST = $originalPost;
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	public function index(Request $request, Response $response): Response
	{
		try
		{
			$businessObject = \CreateObject('email.bofilters');
			$filters = EmailFilterSerializer::serialize((array) ($businessObject->all_filters ?? []));
			foreach ($filters as $index => $filter)
			{
				$id = $filter['id'];
				$filters[$index]['editUrl'] = \phpgw::link('/email/view/filter', ['filter_num' => $id], true);
			}
			return $this->json($response, ['items' => $filters]);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, [
				'error' => lang('Unable to load filters'),
				'reason' => $exception->getMessage(),
			], 500);
		}
	}

	public function action(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
		{
			$input = json_decode((string) $request->getBody(), true);
		}
		$action = is_array($input) ? (string) ($input['action'] ?? '') : '';
		$filterNum = is_array($input) ? filter_var($input['filterNum'] ?? null, FILTER_VALIDATE_INT) : false;
		if (!in_array($action, ['delete', 'move-up', 'move-down'], true) || $filterNum === false || $filterNum < 0)
		{
			return $this->json($response, ['error' => lang('Invalid filter action')], 400);
		}

		$originalPost = $_POST;
		$_POST['filter_num'] = $filterNum;
		try
		{
			$businessObject = \CreateObject('email.bofilters');
			$updated = match ($action)
			{
				'delete' => $businessObject->delete_filter(true),
				'move-up' => $businessObject->move_up(true),
				'move-down' => $businessObject->move_down(true),
			};
			if (!$updated)
			{
				return $this->json($response, ['error' => lang('Filter action could not be completed')], 422);
			}
			return $this->json($response, ['updated' => true]);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, [
				'error' => lang('Unable to update filter'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			$_POST = $originalPost;
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	public function run(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input)) $input = json_decode((string) $request->getBody(), true);
		$input = is_array($input) ? $input : [];
		$test = filter_var($input['test'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$filterNum = $input['filterNum'] ?? null;
		if ($filterNum !== null && (filter_var($filterNum, FILTER_VALIDATE_INT) === false || (int) $filterNum < 0))
		{
			return $this->json($response, ['error' => lang('Invalid filter number')], 400);
		}

		$originalPost = $_POST;
		$_POST = array_replace($originalPost, ['filter_test' => $test ? '1' : '']);
		if ($filterNum !== null) $_POST['filter_num'] = (int) $filterNum;
		try
		{
			$businessObject = \CreateObject('email.bofilters');
			$result = $businessObject->do_filter(true);
			if ($result === false)
			{
				return $this->json($response, ['error' => lang('No filters are available to run')], 422);
			}
			return $this->json($response, $result);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			return $this->json($response, [
				'error' => lang('Unable to run filters'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			$_POST = $originalPost;
			if (\App\modules\email\services\MailMessage::hasInstance()) \App\modules\email\services\MailMessage::releaseInstance();
		}
	}

	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}
}
