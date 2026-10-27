<?php

namespace App\modules\email\services;

use App\Database\Db;
use PDO;

final class PopMessageStore
{
	private const TABLE = 'phpgw_email_pop_messages';
	private array $scope;
	private object $db;

	public function __construct(int $owner, int $account, string $mailboxKey, ?object $db = null)
	{
		if ($owner < 1 || $account < 0 || !preg_match('/^[a-f0-9]{64}$/', $mailboxKey)) throw new \InvalidArgumentException('Invalid POP mailbox identity');
		$this->scope = ['account_id' => $owner, 'mail_account' => $account, 'mailbox_key' => $mailboxKey];
		$this->db = $db ?? Db::getInstance();
	}

	public static function mailboxKey(string $host, int $port, string $username): string
	{
		return hash('sha256', json_encode([strtolower($host), $port, $username], JSON_THROW_ON_ERROR));
	}

	public function uidls(): array
	{
		$statement = $this->query('SELECT uidl FROM ' . self::TABLE . ' WHERE ' . $this->where());
		return array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
	}

	public function save(string $uidl, string $raw): void
	{
		if (!preg_match('/^[\x21-\x7e]{1,70}$/D', $uidl) || $raw === '' || !str_contains($raw, "\r\n\r\n")) throw new \RuntimeException('Incomplete POP message or invalid UIDL');
		$values = $this->scope + [
			'uidl' => $uidl, 'raw_message' => $raw, 'content_hash' => hash('sha256', $raw),
			'message_size' => strlen($raw), 'received_at' => time(), 'is_seen' => 0, 'is_deleted' => 0, 'is_hidden' => 0,
		];
		try
		{
			$sql = 'INSERT INTO ' . self::TABLE . ' (' . implode(', ', array_keys($values)) . ') VALUES (:' . implode(', :', array_keys($values)) . ')';
			if (is_callable([$this->db, 'getAttribute']) && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql')
			{
				$sql .= ' ON CONFLICT (account_id, mail_account, mailbox_key, uidl) DO NOTHING';
			}
			$this->query($sql, $values);
		}
		catch (\PDOException $exception)
		{
			if (!in_array((string) $exception->getCode(), ['23505', '23000'], true)) throw $exception;
			$existing = $this->query('SELECT content_hash FROM ' . self::TABLE . ' WHERE ' . $this->where() . ' AND uidl = :uidl', ['uidl' => $uidl])->fetchColumn();
			if ($existing !== $values['content_hash']) throw new \RuntimeException('POP UIDL collision; existing message was retained', 0, $exception);
		}
		$saved = $this->query('SELECT raw_message, content_hash FROM ' . self::TABLE . ' WHERE ' . $this->where() . ' AND uidl = :uidl', ['uidl' => $uidl])->fetch(PDO::FETCH_ASSOC);
		if (!$saved || self::binaryValue($saved['raw_message']) !== $raw || $saved['content_hash'] !== $values['content_hash']) throw new \RuntimeException('POP message persistence verification failed; remote message was retained');
	}

	public function rows(): array
	{
		return $this->query('SELECT message_id, uidl, message_size, received_at, is_seen, is_deleted FROM ' . self::TABLE . ' WHERE ' . $this->where() . ' AND is_hidden = 0 ORDER BY message_id')->fetchAll(PDO::FETCH_ASSOC);
	}

	public function raw(int $id): string
	{
		$row = $this->query('SELECT raw_message, content_hash FROM ' . self::TABLE . ' WHERE ' . $this->where() . ' AND message_id = :message_id AND is_hidden = 0', ['message_id' => $id])->fetch(PDO::FETCH_ASSOC);
		if (!$row) throw new \RuntimeException('Local POP message no longer exists');
		$raw = self::binaryValue($row['raw_message']);
		if (!hash_equals($row['content_hash'], hash('sha256', $raw))) throw new \RuntimeException('Local POP message failed its integrity check');
		return $raw;
	}

	public function flag(int $id, string $flag, bool $enabled): bool
	{
		$column = match (strtolower($flag)) { '\\seen' => 'is_seen', '\\deleted' => 'is_deleted', default => null };
		if ($column === null) return false;
		if (!$this->query('SELECT message_id FROM ' . self::TABLE . ' WHERE ' . $this->where() . ' AND message_id = :message_id AND is_hidden = 0', ['message_id' => $id])->fetchColumn()) return false;
		$this->query('UPDATE ' . self::TABLE . ' SET ' . $column . ' = :flag_value WHERE ' . $this->where() . ' AND message_id = :message_id AND is_hidden = 0', ['flag_value' => (int) $enabled, 'message_id' => $id]);
		return true;
	}

	public function expunge(): void
	{
		$this->query('UPDATE ' . self::TABLE . ' SET is_hidden = 1 WHERE ' . $this->where() . ' AND is_deleted = 1');
	}

	private function where(): string
	{
		return 'account_id = :account_id AND mail_account = :mail_account AND mailbox_key = :mailbox_key';
	}

	private function query(string $sql, array $values = []): object
	{
		try
		{
			$statement = $this->db->prepare($sql);
			foreach ($this->scope + $values as $name => $value)
			{
				$type = $name === 'raw_message' ? PDO::PARAM_LOB : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
				if (!$statement->bindValue(':' . $name, $value, $type)) throw new \RuntimeException('Unable to bind local POP mailbox data');
			}
			if (!$statement->execute()) throw new \RuntimeException('Unable to persist the local POP mailbox');
			return $statement;
		}
		catch (\PDOException $exception)
		{
			if (in_array((string) $exception->getCode(), ['42P01', '42S02'], true)) throw new EmailMailConfigurationException('Upgrade the Email application to version 0.9.17.503 before using local POP storage', 0, $exception);
			throw $exception;
		}
	}

	private static function binaryValue(mixed $value): string
	{
		if (is_resource($value)) $value = stream_get_contents($value);
		if (!is_string($value)) throw new \RuntimeException('Unable to read local POP message bytes');
		return $value;
	}
}