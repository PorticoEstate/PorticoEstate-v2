<?php

/**
 * EMail - Filter Execution
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Applies mailbox filters to eligible accounts, supports test runs, and delegates matching-message moves.
 */

namespace App\modules\email\services;

final class EmailFilterRunner
{
	private EmailFilterMatcher $matcher;
	private \Closure $moveExecutor;

	/**
	 * Configure the filter matcher and message-move executor.
	 *
	 * @param EmailFilterMatcher|null $matcher The optional header matcher; null uses the default implementation.
	 * @param callable|null $moveExecutor The optional callback accepting a mail runtime and normalized move request and returning action results.
	 */
	public function __construct(?EmailFilterMatcher $matcher = null, ?callable $moveExecutor = null)
	{
		$this->matcher = $matcher ?? new EmailFilterMatcher();
		$this->moveExecutor = $moveExecutor
			? \Closure::fromCallable($moveExecutor)
			: static fn(object $mail, array $request): array => (new EmailMessageActions())->execute($mail, $request);
	}

	/**
	 * Evaluate eligible mailbox messages and optionally execute matching filter moves.
	 *
	 * @param object $mail The active mail runtime used for mailbox access and header decoding.
	 * @param array $filters The filter definitions keyed by their stored identifiers.
	 * @param bool $test Whether to report matching messages without executing filter moves.
	 * @param int|null $filterNumber The optional identifier limiting execution to a single filter.
	 * @return array|false Match and action reports, or false when no applicable filters exist.
	 */
	public function run(object $mail, array $filters, bool $test = false, ?int $filterNumber = null): array|false
	{
		if ($filterNumber !== null)
		{
			if (!isset($filters[$filterNumber]['source_accounts']))
            {
            	return false;
            }
			$filters = [$filterNumber => $filters[$filterNumber]];
		}
		if (!$filters)
        {
        	return false;
        }
		$enabledAccounts = array_map('intval', array_column(array_filter((array) $mail->extra_and_default_acounts,
			static fn($account) => ($account['status'] ?? '') === 'enabled'), 'acctnum'));
		$items = [];
		$matchReport = [];
		$failed = [];
		$didMove = false;
		$folderLists = [];
		foreach ($filters as $filterIndex => $filter)
		{
			$filter = (array) $filter;
			$filterName = $mail->html_quotes_decode((string) ($filter['filtername'] ?? ''));
			$destination = [];
			parse_str((string) ($filter['actions'][0]['folder'] ?? ''), $destination);
			$destinationAccount = (int) ($destination['acctnum'] ?? -1);
			$destinationFolder = (string) ($destination['folder'] ?? '');
			if (!in_array($destinationAccount, $enabledAccounts, true))
            {
            	throw new \InvalidArgumentException('Filter destination account is unavailable');
            }
			if (($filter['actions'][0]['judgement'] ?? '') !== 'fileinto')
            {
            	throw new \InvalidArgumentException('Filter action is not supported');
            }
			$folderLists[$destinationAccount] ??= array_column((array) $mail->get_folder_list($destinationAccount, true), 'folder_long');
			if (!in_array($destinationFolder, $folderLists[$destinationAccount], true))
            {
            	throw new \InvalidArgumentException('Filter destination folder is unavailable');
            }
			$matchedByAccount = [];
			foreach ((array) ($filter['source_accounts'] ?? []) as $source)
			{
				$sourceAccount = (int) ($source['acctnum'] ?? -1);
				if (!in_array($sourceAccount, $enabledAccounts, true))
                {
                	throw new \InvalidArgumentException('Filter source account is unavailable');
                }
				$sourceFolderEncoded = (string) ($source['folder'] ?? 'INBOX');
				$sourceFolder = $mail->prep_folder_in($sourceFolderEncoded, $sourceAccount);
				$folderLists[$sourceAccount] ??= array_column((array) $mail->get_folder_list($sourceAccount, true), 'folder_long');
				if (!in_array($sourceFolder, $folderLists[$sourceAccount], true))
                {
                	throw new \InvalidArgumentException('Filter source folder is unavailable');
                }
				$messageList = (array) $mail->get_msgball_list_oldschool($sourceAccount, $mail->prep_folder_out($sourceFolder), false, false);
				foreach ($messageList as $message)
				{
					$message = (array) $message;
					$messageNumber = (int) ($message['msgnum'] ?? 0);
					if ($messageNumber < 1)
                    {
                    	continue;
                    }
					$message['acctnum'] = $sourceAccount;
					$message['folder'] = (string) ($message['folder'] ?? $sourceFolder);
					$rawHeaders = $mail->phpgw_fetchheader($message, false);
					if (!is_string($rawHeaders) || $rawHeaders === '')
                    {
                    	continue;
                    }
					$headerMap = $this->parseHeaders((string) $mail->decode_rfc_header_glob($rawHeaders));
					$decodedFilter = $filter;
					foreach ($decodedFilter['matches'] as &$match)
                    {
                    	$match['matchthis'] = $mail->html_quotes_decode((string) ($match['matchthis'] ?? ''));
                    }
					unset($match);
					if (!$this->matcher->matches($decodedFilter, $headerMap))
                    {
                    	continue;
                    }
					$matchedByAccount[$sourceAccount][] = [
						'acctnum' => $sourceAccount,
						'folder' => $sourceFolder,
						'msgnum' => $messageNumber,
					];
				}
			}
			$matchCount = array_sum(array_map('count', $matchedByAccount));
			$items[] = ['id' => (int) $filterIndex, 'name' => $filterName, 'matchCount' => $matchCount,
				'action' => 'fileinto', 'destinationAccount' => $destinationAccount, 'destinationFolder' => $destinationFolder];
			$matchReport[] = sprintf('%s: %d matches', $filterName, $matchCount);
			if ($test || $matchCount === 0)
            {
            	continue;
            }
			foreach ($matchedByAccount as $sourceAccount => $messages)
			{
				$normalized = EmailMessageActions::normalize([
					'action' => 'move', 'account' => (int) $sourceAccount, 'messages' => $messages,
					'target_fldball' => ['acctnum' => $destinationAccount, 'folder' => $destinationFolder],
				]);
				if ($normalized === null)
                {
                	throw new \RuntimeException('Unable to prepare filter message move');
                }
				$result = ($this->moveExecutor)($mail, $normalized);
				$didMove = $didMove || (bool) $result['completed'];
				foreach ($result['failed'] as $failure)
                {
                	$failed[] = $failure;
                }
			}
		}
		return ['items' => $items, 'matchReport' => implode("\n", $matchReport), 'tested' => $test, 'didExpunge' => $didMove, 'failed' => $failed];
	}

	/**
	 * Unfold raw message headers into values used by filter matching.
	 *
	 * @param string $raw The complete original MIME message or raw header bytes required by the operation.
	 * @return array Parsed header names and values.
	 */
	private function parseHeaders(string $raw): array
	{
		$headers = [];
		$lastHeader = null;
		foreach (preg_split('/\r?\n/', $raw) ?: [] as $line)
		{
			if ($line === '')
            {
            	break;
            }
			if (preg_match('/^[\t ]+/', $line) && $lastHeader !== null)
			{
				$headers[$lastHeader][array_key_last($headers[$lastHeader])] .= ' ' . trim($line);
				continue;
			}
			$separator = strpos($line, ':');
			if ($separator === false)
            {
            	continue;
            }
			$lastHeader = strtolower(trim(substr($line, 0, $separator)));
			$headers[$lastHeader][] = trim(substr($line, $separator + 1));
		}
		$flattened = [];
		foreach ($headers as $name => $values)
        {
        	$flattened[$name] = implode("\n", $values);
        }
		$flattened['recipient'] = implode("\n", array_filter([
			$flattened['to'] ?? '', $flattened['cc'] ?? '', $flattened['bcc'] ?? '',
		], static fn($value) => $value !== ''));
		return $flattened;
	}
}