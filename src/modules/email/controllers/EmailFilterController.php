<?php

/**
 * EMail - Filters API
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Provides filter editor data, persists filter changes, and runs or tests mailbox filters.
 */

namespace App\modules\email\controllers;

use App\modules\email\services\EmailFilterSerializer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailFilterController
{
	/**
	 * Load account, folder, and rule values for the filter editor.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response Filter editor data or a missing-rule error as JSON.
	 */
	public function editor(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$filterNum = (string) ($query['filter_num'] ?? 'add_new');
		if ($filterNum !== 'add_new' && (!ctype_digit($filterNum) || (int) $filterNum < 0))
		{
			return $this->json($response, ['error' => lang('Invalid filter number')], 400);
		}

		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('email.api.filter.editor');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$filter = (new \App\modules\email\services\EmailFilterStore())->editor($mail, $filterNum);
			$filter ??= [
				'id' => 'add_new', 'name' => 'My Mail Filter', 'sourceAccounts' => [0], 'matches' => [],
				'action' => ['judgement' => 'fileinto', 'account' => 0, 'folder' => '', 'actiontext' => '', 'stopFiltering' => false],
			];
			$accounts = [];
			foreach ((array) $mail->extra_and_default_acounts as $account)
			{
				$account = (array) $account;
				if (($account['status'] ?? '') !== 'enabled')
                {
                	continue;
                }
				$accountNum = (int) ($account['acctnum'] ?? 0);
				$accounts[] = [
					'id' => $accountNum,
					'label' => trim((string) $mail->get_pref_value('fullname', $accountNum)) ?: sprintf('%s %d', lang('Account'), $accountNum),
				];
			}

			$filterData = $filter;
			while (count($filterData['matches']) < 3)
            {
            	$filterData['matches'][] = [];
            }
			return $this->json($response, ['filter' => $filterData, 'accounts' => $accounts]);
		}
		catch (\OutOfBoundsException $exception)
		{
			return $this->json($response, ['error' => lang('Filter not found')], 404);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailFilterController::editor failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to load filter editor'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	/**
	 * Validate and persist a new or edited mailbox filter.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response The saved filter identifier or validation errors as JSON.
	 */
	public function save(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
        {
        	$input = json_decode((string) $request->getBody(), true);
        }
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
		$normalizedSources = [];
		foreach ($sources as $sourceAccount)
		{
			$sourceAccount = filter_var($sourceAccount, FILTER_VALIDATE_INT);
			if ($sourceAccount === false || $sourceAccount < 0)
			{
				return $this->json($response, ['error' => lang('Invalid source account')], 422);
			}
			$normalizedSources[] = (int) $sourceAccount;
		}
		$normalizedMatches = [];
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
			$normalizedMatches[] = [
				'examine' => $examine,
				'comparator' => $comparator,
				'matchthis' => trim((string) ($match['matchthis'] ?? '')),
				'andor' => $index === 0 ? '' : $andor,
			];
		}
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('email.api.filters.save');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$enabledAccounts = [];
			foreach ((array) $mail->extra_and_default_acounts as $account)
			{
				$account = (array) $account;
				if (($account['status'] ?? '') === 'enabled')
                {
                	$enabledAccounts[] = (int) ($account['acctnum'] ?? 0);
                }
			}
			$destinationAccount = (int) $action['account'];
			if (!in_array($destinationAccount, $enabledAccounts, true))
			{
				return $this->json($response, ['error' => lang('Invalid destination account')], 422);
			}
			foreach ($normalizedSources as $sourceAccount)
			{
				if (!in_array($sourceAccount, $enabledAccounts, true))
				{
					return $this->json($response, ['error' => lang('Invalid source account')], 422);
				}
			}
			$savedFilterNum = (new \App\modules\email\services\EmailFilterStore())->save($mail, [
				'name' => $name,
				'sourceAccounts' => $normalizedSources,
				'matches' => $normalizedMatches,
				'action' => [
					'account' => $destinationAccount,
					'folder' => trim((string) $action['folder']),
					'actiontext' => trim((string) ($action['actiontext'] ?? '')),
					'stopFiltering' => filter_var($action['stopFiltering'] ?? false, FILTER_VALIDATE_BOOLEAN),
				],
			], $filterNum);
			return $this->json($response, ['saved' => true, 'id' => $savedFilterNum]);
		}
		catch (\OutOfBoundsException $exception)
		{
			return $this->json($response, ['error' => lang('Filter not found')], 404);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailFilterController::save failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to save filter'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	/**
	 * List the current user's stored mailbox filters.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response Serialized filter entries or a loading error as JSON.
	 */
	public function index(Request $request, Response $response): Response
	{
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('email.api.filters');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$filters = EmailFilterSerializer::serialize((new \App\modules\email\services\EmailFilterStore())->raw($mail));
			foreach ($filters as $index => $filter)
			{
				$id = $filter['id'];
				$filters[$index]['editUrl'] = \phpgw::link('/email/view/filter', ['filter_num' => $id], true);
			}
			return $this->json($response, ['items' => $filters]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailFilterController::index failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to load filters'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	/**
	 * Apply a requested management action to a stored filter.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response The management result or an invalid-action error as JSON.
	 */
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

		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('email.api.filters.action');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$updated = (new \App\modules\email\services\EmailFilterStore())->action($mail, $action, $filterNum);
			if (!$updated)
			{
				return $this->json($response, ['error' => lang('Filter action could not be completed')], 422);
			}
			return $this->json($response, ['updated' => true]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailFilterController::action failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to update filter'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	/**
	 * Run or test the selected mailbox filters against eligible accounts.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response Matching and action results or execution errors as JSON.
	 */
	public function run(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
        {
        	$input = json_decode((string) $request->getBody(), true);
        }
		$input = is_array($input) ? $input : [];
		$test = filter_var($input['test'] ?? false, FILTER_VALIDATE_BOOLEAN);
		$filterNum = $input['filterNum'] ?? null;
		if ($filterNum !== null && (filter_var($filterNum, FILTER_VALIDATE_INT) === false || (int) $filterNum < 0))
		{
			return $this->json($response, ['error' => lang('Invalid filter number')], 400);
		}

		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(false);
			$bootstrap->ensure_mail_msg_exists('email.api.filters.run');
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$filters = (new \App\modules\email\services\EmailFilterStore())->raw($mail);
			$filterNumber = $filterNum === null ? null : (int) $filterNum;
			$result = (new \App\modules\email\services\EmailFilterRunner())->run($mail, $filters, $test, $filterNumber);
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
		catch (\InvalidArgumentException $exception)
		{
			return $this->json($response, ['error' => $exception->getMessage()], 422);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailFilterController::run failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to run filters'),
				'reason' => $exception->getMessage(),
			], 500);
		}
		finally
		{
			if (\App\modules\email\services\MailMessage::hasInstance())
			{
				\App\modules\email\services\MailMessage::getInstance()->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
		}
	}

	/**
	 * Encode an API payload and apply its JSON response headers and status.
	 *
	 * @param Response $response The response to populate with content, headers, and status.
	 * @param array $data The response payload or template variables to render.
	 * @param int $status The HTTP status code to return.
	 * @return Response The JSON HTTP response.
	 */
	private function json(Response $response, array $data, int $status = 200): Response
	{
		$encoded = json_encode($data);
		$response->getBody()->write($encoded === false ? '{}' : $encoded);
		return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
	}
}
