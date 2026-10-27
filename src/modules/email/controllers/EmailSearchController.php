<?php

/**
 * EMail - Search API
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Validates mailbox search requests, executes supported criteria, and returns matching message data.
 */

namespace App\modules\email\controllers;

use App\modules\email\services\EmailSearchCriteria;
use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailSearchController
{
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

	/**
	 * Validate search input and query the selected mailbox folders.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response Matching message entries and search pagination state as JSON.
	 */
	public function search(Request $request, Response $response): Response
	{
		$input = $request->getParsedBody();
		if (!is_array($input))
        {
        	$input = json_decode((string) $request->getBody(), true);
        }
		$criteria = EmailSearchCriteria::normalize(is_array($input) ? $input : []);
		if ($criteria === null)
		{
			return $this->json($response, ['error' => lang('Select at least one folder and provide valid search criteria')], 422);
		}

		$originalPost = $_POST;
		$_POST = ['fldball' => ['acctnum' => $criteria['account'], 'folder' => $criteria['folders'][0]]];
		$mail = null;
		try
		{
			$bootstrap = \CreateObject('email.msg_bootstrap');
			$bootstrap->set_do_login(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED);
			$bootstrap->ensure_mail_msg_exists('email.api.search', 0);
			$mail = \App\modules\email\services\MailMessage::getInstance();
			$availableFolders = (array) $mail->get_arg_value('folder_list', $criteria['account']);
			$foldersByName = [];
			foreach ($availableFolders as $folder)
			{
				$longName = (string) ($folder['folder_long'] ?? '');
				$shortName = (string) ($folder['folder_short'] ?? '');
				if ($longName !== '')
                {
                	$foldersByName[$longName] = ['long' => $longName, 'short' => $shortName];
                }
				if ($shortName !== '')
                {
                	$foldersByName[$shortName] = ['long' => $longName, 'short' => $shortName];
                }
			}

			$selectedFolders = [];
			foreach ($criteria['folders'] as $folderName)
			{
				if (isset($foldersByName[$folderName]))
				{
					$selectedFolders[$foldersByName[$folderName]['long']] = $foldersByName[$folderName]['short'];
				}
			}
			if (!$selectedFolders)
			{
				return $this->json($response, ['error' => lang('Select a valid folder')], 422);
			}

			$items = [];
			$imapCriteria = EmailSearchCriteria::build($criteria);
			foreach ($selectedFolders as $folderLong => $folderShort)
			{
				$folderBall = ['acctnum' => $criteria['account'], 'folder' => $folderLong];
				$messageNumbers = $mail->phpgw_search($folderBall, $imapCriteria, 0);
				foreach ((array) $messageNumbers as $messageNumber)
				{
					$msgball = ['acctnum' => $criteria['account'], 'folder' => $folderLong, 'msgnum' => (int) $messageNumber];
					$headers = $mail->phpgw_header($msgball);
					$items[] = [
						'account' => $criteria['account'],
						'folder' => $folderLong,
						'folderName' => $folderShort,
						'messageNumber' => (int) $messageNumber,
						'unseen' => stripos($mail->make_flags_str($headers), 'Seen') === false,
						'from' => (string) ($headers->fromaddress ?? ''),
						'subject' => (string) $mail->get_subject($headers, ''),
						'date' => isset($headers->udate) ? date('Y-m-d H:i', (int) $headers->udate) : '',
						'size' => (string) $mail->format_byte_size((int) ($headers->Size ?? 0)),
						'messageUrl' => \phpgw::link('/email/view/message', [
							'msgball[acctnum]' => $criteria['account'],
							'msgball[folder]' => $folderLong,
							'msgball[msgnum]' => (int) $messageNumber,
						], true),
					];
				}
			}

			$total = count($items);
			$user = (array) Settings::getInstance()->get('user');
			$pageSize = max(1, (int) ($user['preferences']['common']['maxmatchs'] ?? 25));
			$start = min($criteria['start'], $total);
			return $this->json($response, [
				'items' => array_slice($items, $start, $pageSize),
				'total' => $total,
				'start' => $start,
				'pageSize' => $pageSize,
			]);
		}
		catch (\App\modules\email\services\EmailMailConfigurationException $exception)
		{
			$message = $exception->getMessage();
			return $this->json($response, ['error' => $message, 'reason' => $message], 422);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailSearchController::search failed (' . get_class($exception) . ')');
			return $this->json($response, ['error' => lang('Unable to search messages')], 500);
		}
		finally
		{
			if (is_object($mail))
			{
				$mail->end_request();
				\App\modules\email\services\MailMessage::releaseInstance();
			}
			$_POST = $originalPost;
		}
	}
}
