<?php

/**
 * EMail - Address Book API
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Handles address-book searches and returns contact suggestions for message recipients.
 */

namespace App\modules\email\controllers;

use App\modules\phpgwapi\services\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class EmailAddressBookController
{
	/**
	 * Search the address book for recipient suggestions.
	 *
	 * @param Request $request The HTTP request containing query parameters, submitted values, and request attributes.
	 * @param Response $response The response to populate with content, headers, and status.
	 * @return Response Contact suggestions or a search error as JSON.
	 */
	public function search(Request $request, Response $response): Response
	{
		$query = $request->getQueryParams();
		$rawSearch = $query['q'] ?? '';
		$rawFilter = $query['filter'] ?? 'none';
		$category = $query['category'] ?? '';
		if (!is_scalar($rawSearch) || !is_scalar($rawFilter) || !is_scalar($category))
		{
			return $this->json($response, ['error' => lang('Invalid address book query')], 400);
		}
		$search = trim((string) $rawSearch);
		$filter = (string) $rawFilter;
		if (strlen($search) > 120 || !in_array($filter, ['none', 'user_only', 'private'], true)
			|| ($category !== '' && (!ctype_digit((string) $category) || (int) $category < 1)))
		{
			return $this->json($response, ['error' => lang('Invalid address book query')], 400);
		}

		try
		{
			$user = (array) Settings::getInstance()->get('user');
			$owner = (int) ($user['account_id'] ?? 0);
			if ($owner < 1)
            {
            	return $this->json($response, ['error' => lang('Address book unavailable')], 403);
            }
			$contacts = \CreateObject('phpgwapi.contacts');
			$access = match ($filter)
			{
				'user_only' => PHPGW_CONTACTS_MINE,
				'private' => PHPGW_CONTACTS_PRIVATE,
				default => PHPGW_CONTACTS_ALL,
			};
			$categoryId = $category === '' ? PHPGW_CONTACTS_CATEGORIES_ALL : (int) $category;
			$fields = ['contact_id', 'per_full_name', 'per_first_name', 'per_last_name', 'email', 'email_home'];
			$criteria = $contacts->criteria_for_index($owner, $access, $categoryId, ['per_full_name', 'email', 'email_home'], $search, ['email', 'email_home']);
			$records = $contacts->get_persons($fields, 0, 50, 'per_last_name', 'ASC', '', $criteria);
			$items = [];
			foreach ((array) $records as $contact)
			{
				$contact = (array) $contact;
				$email = trim((string) (($contact['email'] ?? '') ?: ($contact['email_home'] ?? '')));
				if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false)
                {
                	continue;
                }
				$name = trim((string) ($contact['per_full_name'] ?? ''));
				if ($name === '')
                {
                	$name = trim((string) ($contact['per_first_name'] ?? '') . ' ' . (string) ($contact['per_last_name'] ?? ''));
                }
				if ($name === '')
                {
                	$name = $email;
                }
				$items[] = ['name' => $name, 'email' => $email];
			}
			return $this->json($response, ['items' => $items]);
		}
		catch (\Throwable $exception)
		{
			error_log('[email] EmailAddressBookController::search failed (' . get_class($exception) . ')');
			return $this->json($response, [
				'error' => lang('Unable to search the address book'),
				'reason' => $exception->getMessage(),
			], 500);
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
