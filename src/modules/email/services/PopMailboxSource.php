<?php

namespace App\modules\email\services;

require_once __DIR__ . '/PopMailboxSourceException.php';

final class PopMailboxSource
{
	private mixed $socket = null;

	public function __construct(string $host, int $port, bool $tls, bool $validate, string $username, string $password)
	{
		if (preg_match('/[\r\n]/', $username . $password)) throw new PopMailboxSourceException('Invalid POP credentials');
		$context = stream_context_create(['ssl' => ['verify_peer' => $validate, 'verify_peer_name' => $validate, 'allow_self_signed' => !$validate, 'peer_name' => $host]]);
		$this->socket = @stream_socket_client(($tls ? 'tls' : 'tcp') . '://' . $host . ':' . $port, $error, $description, 10, STREAM_CLIENT_CONNECT, $context);
		if (!$this->socket) throw new PopMailboxSourceException('Unable to connect to the POP server');
		stream_set_timeout($this->socket, 30);
		try
		{
			$this->positive($this->line());
			$this->command('USER ' . $username);
			$this->command('PASS ' . $password);
		}
		catch (\Throwable $exception)
		{
			$this->disconnect();
			throw $exception;
		}
	}

	public function uidls(): array
	{
		$this->command('UIDL');
		$result = [];
		$seen = [];
		while (($line = $this->line()) !== ".\r\n")
		{
			if (!preg_match('/^([1-9][0-9]*) ([\x21-\x7e]{1,70})\r\n$/D', $line, $match) || isset($result[(int) $match[1]]) || isset($seen[$match[2]])) throw new PopMailboxSourceException('Invalid or duplicate POP UIDL');
			$result[(int) $match[1]] = $match[2];
			$seen[$match[2]] = true;
		}
		return $result;
	}

	public function retrieve(int $number): string
	{
		if ($number < 1) throw new PopMailboxSourceException('Invalid POP message number');
		$this->command('RETR ' . $number);
		$raw = '';
		while (($line = $this->line()) !== ".\r\n")
		{
			$raw .= str_starts_with($line, '..') ? substr($line, 1) : $line;
			if (strlen($raw) > 100 * 1024 * 1024) throw new PopMailboxSourceException('POP message exceeds the 100 MB import limit');
		}
		return $raw;
	}

	public function close(): void
	{
		try
		{
			if (is_resource($this->socket)) $this->command('QUIT');
		}
		finally { $this->disconnect(); }
	}

	public function __destruct() { $this->disconnect(); }

	private function command(string $command): void
	{
		$data = $command . "\r\n";
		while ($data !== '')
		{
			$written = @fwrite($this->socket, $data);
			if (!$written) throw new PopMailboxSourceException('POP command write failed');
			$data = substr($data, $written);
		}
		$this->positive($this->line());
	}

	private function positive(string $line): void
	{
		if (!preg_match('/^\+OK(?:[ \r\n]|$)/i', $line)) throw new PopMailboxSourceException('POP server rejected the operation');
	}

	private function line(): string
	{
		$line = '';
		do
		{
			$chunk = @fgets($this->socket, 8192);
			if ($chunk === false || $chunk === '') throw new PopMailboxSourceException('Incomplete POP response');
			$line .= $chunk;
			if (strlen($line) > 1024 * 1024) throw new PopMailboxSourceException('POP response line exceeds the import limit');
		} while (!str_ends_with($line, "\r\n"));
		return $line;
	}

	private function disconnect(): void
	{
		if (is_resource($this->socket)) fclose($this->socket);
		$this->socket = null;
	}
}