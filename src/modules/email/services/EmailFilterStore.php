<?php

/**
 * EMail - Filter Preferences
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Loads and persists user filter definitions and applies filter editor and management changes.
 */

namespace App\modules\email\services;

use App\modules\phpgwapi\services\Preferences;

final class EmailFilterStore
{
	public function raw(object $mail): array
	{
		$filters = is_array($mail->raw_filters ?? null) ? $mail->raw_filters : [];
		foreach ($filters as &$filter)
		{
			if (!is_array($filter))
            {
            	$filter = [];
            }
			foreach (['source_accounts', 'matches', 'actions'] as $key)
            {
            	if (!is_array($filter[$key] ?? null))
				{
					$filter[$key] = [];
				}
            }
		}
		unset($filter);
		return $filters;
	}

	public function editor(object $mail, string $id): ?array
	{
		$filters = $this->raw($mail);
		if ($id === 'add_new')
        {
        	return null;
        }
		$index = (int) $id;
		if (!isset($filters[$index]['source_accounts']))
        {
        	throw new \OutOfBoundsException('Filter not found');
        }
		$filter = $filters[$index];
		$action = (array) ($filter['actions'][0] ?? []);
		$destination = [];
		parse_str((string) ($action['folder'] ?? ''), $destination);
		return [
			'id' => $index,
			'name' => $mail->html_quotes_decode((string) ($filter['filtername'] ?? '')),
			'sourceAccounts' => array_map(static fn($source): int => (int) ($source['acctnum'] ?? 0), $filter['source_accounts']),
			'matches' => array_values(array_map(function ($match) use ($mail): array
            {
				$match = (array) $match;
				$match['matchthis'] = $mail->html_quotes_decode((string) ($match['matchthis'] ?? ''));
				return $match;
			}, $filter['matches'])),
			'action' => [
				'judgement' => (string) ($action['judgement'] ?? 'fileinto'),
				'account' => (int) ($destination['acctnum'] ?? 0),
				'folder' => (string) ($destination['folder'] ?? ''),
				'actiontext' => (string) ($action['actiontext'] ?? ''),
				'stopFiltering' => !empty($action['stop_filtering']),
			],
		];
	}

	public function save(object $mail, array $filter, string $id, ?object $preferenceStore = null): int
	{
		$filters = $this->raw($mail);
		$index = $id === 'add_new' ? count($filters) : (int) $id;
		if ($id !== 'add_new' && !isset($filters[$index]['source_accounts']))
        {
        	throw new \OutOfBoundsException('Filter not found');
        }
		$stored = [
			'filtername' => $mail->html_quotes_encode((string) $filter['name']),
			'source_accounts' => [],
			'matches' => [],
			'actions' => [],
		];
		foreach ($filter['sourceAccounts'] as $account)
		{
			$stored['source_accounts'][] = ['folder' => 'INBOX', 'acctnum' => (int) $account];
		}
		foreach (array_values($filter['matches']) as $match)
		{
			$match = (array) $match;
			if (($match['andor'] ?? '') === 'ignore_me')
            {
            	continue;
            }
			$row = [
				'examine' => (string) $match['examine'],
				'comparator' => (string) $match['comparator'],
				'matchthis' => $mail->html_quotes_encode((string) $match['matchthis']),
			];
			if (isset($match['andor']) && $match['andor'] !== '')
            {
            	$row['andor'] = (string) $match['andor'];
            }
			$stored['matches'][] = $row;
		}
		$action = (array) $filter['action'];
		$stored['actions'][] = [
			'judgement' => 'fileinto',
			'folder' => http_build_query(['folder' => (string) $action['folder'], 'acctnum' => (int) $action['account']]),
			'actiontext' => trim((string) ($action['actiontext'] ?? '')),
		];
		if (!empty($action['stopFiltering']))
        {
        	$stored['actions'][0]['stop_filtering'] = 'True';
        }
		$filters[$index] = $stored;
		$this->persist($mail, $filters, $preferenceStore);
		return $index;
	}

	public function action(object $mail, string $action, int $id, ?object $preferenceStore = null): bool
	{
		$filters = $this->raw($mail);
		if (!isset($filters[$id]['source_accounts']))
        {
        	return false;
        }
		if ($action === 'delete')
		{
			unset($filters[$id]);
		}
        elseif ($action === 'move-up')
		{
			if ($id < 1 || !isset($filters[$id - 1]))
            {
            	return false;
            }
			[$filters[$id - 1], $filters[$id]] = [$filters[$id], $filters[$id - 1]];
		}
        elseif ($action === 'move-down')
		{
			if (!isset($filters[$id + 1]))
            {
            	return false;
            }
			[$filters[$id], $filters[$id + 1]] = [$filters[$id + 1], $filters[$id]];
		}
        else
		{
			return false;
		}
		$this->persist($mail, $filters, $preferenceStore);
		return true;
	}

	private function persist(object $mail, array $filters, ?object $preferenceStore = null): void
	{
		$filters = array_values($filters);
		$preferences = $preferenceStore ?? Preferences::getInstance();
		$preferences->delete_struct('email', '["filters"]');
		foreach ($filters as $index => $filter)
		{
			$base = '["filters"][' . $index . ']';
			$preferences->add_struct('email', $base . '["filtername"]', (string) $filter['filtername']);
			foreach ($filter['source_accounts'] as $row => $source)
			{
				$preferences->add_struct('email', $base . '["source_accounts"][' . $row . ']["folder"]', (string) ($source['folder'] ?? 'INBOX'));
				$preferences->add_struct('email', $base . '["source_accounts"][' . $row . ']["acctnum"]', (int) ($source['acctnum'] ?? 0));
			}
			foreach ($filter['matches'] as $row => $match)
			{
				foreach (['andor', 'examine', 'comparator', 'matchthis'] as $key)
				{
					if (array_key_exists($key, $match))
                    {
                    	$preferences->add_struct('email', $base . '["matches"][' . $row . ']["' . $key . '"]', $match[$key]);
                    }
				}
			}
			foreach ($filter['actions'] as $row => $action)
			{
				foreach (['judgement', 'folder', 'actiontext', 'stop_filtering'] as $key)
				{
					if (array_key_exists($key, $action))
                    {
                    	$preferences->add_struct('email', $base . '["actions"][' . $row . ']["' . $key . '"]', $action[$key]);
                    }
				}
			}
		}
		$mail->so->so_appsession_passthru('0;cached_prefs', ' ');
		$preferences->save_repository();
		$mail->raw_filters = $filters;
	}
}