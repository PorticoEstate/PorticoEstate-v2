<?php

namespace App\modules\email\services;

final class EmailComposeSerializer
{
	private const STRING_FIELDS = ['to', 'cc', 'bcc', 'subject', 'body', 'sender', 'fwd_proc'];
	private const MSGBALL_FIELDS = ['acctnum', 'folder', 'msgnum', 'part_no'];
	private const FLDBALL_FIELDS = ['acctnum', 'folder'];

	public static function normalize(array $input, bool $requireRecipients = true): ?array
	{
		$payload = [];
		foreach (self::STRING_FIELDS as $field)
		{
			$value = $input[$field] ?? '';
			if (!is_scalar($value)) return null;
			$payload[$field] = trim((string) $value);
		}
		$payload['subject'] = preg_replace('/[\r\n]+/', ' ', $payload['subject']) ?? $payload['subject'];
		if ($requireRecipients && $payload['to'] === '' && $payload['cc'] === '' && $payload['bcc'] === '') return null;
		foreach (['sort', 'order', 'start', 'orig_action'] as $field)
		{
			if (isset($input[$field]) && is_scalar($input[$field])) $payload[$field] = (string) $input[$field];
		}

		$action = (string) ($input['action'] ?? 'new');
		if (!in_array($action, ['new', 'reply', 'replyall', 'forward', 'edit'], true)) return null;
		$payload['action'] = $action;
		foreach (['msgball' => self::MSGBALL_FIELDS, 'fldball' => self::FLDBALL_FIELDS] as $key => $allowedFields)
		{
			$ball = $input[$key] ?? null;
			if ($ball === null) continue;
			if (!is_array($ball)) return null;
			$normalized = [];
			foreach ($allowedFields as $field)
			{
				if (!array_key_exists($field, $ball)) continue;
				$value = $ball[$field];
				if ($field === 'folder' || $field === 'part_no')
				{
					if (!is_scalar($value)) return null;
					$normalized[$field] = trim((string) $value);
				} else
				{
					if (!is_numeric($value) || (int) $value < 0) return null;
					$normalized[$field] = (int) $value;
				}
			}
			if ($normalized) $payload[$key] = $normalized;
		}

		foreach (['attach_sig', 'req_notify'] as $checkbox)
		{
			if (!empty($input[$checkbox])) $payload[$checkbox] = 'true';
		}
		return $payload;
	}
}