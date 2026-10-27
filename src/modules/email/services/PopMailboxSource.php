<?php

/**
 * EMail - POP Message Retrieval
 *
 * @author Sigurd Nes <sigurdne at gmail.com>
 * @copyright Copyright (C) 2026 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package email
 */

/**
 * Reads remote POP UIDLs and complete MIME messages over bounded socket responses without issuing remote deletes.
 */

namespace App\modules\email\services;

require_once __DIR__ . '/PopMailboxSourceException.php';

final class PopMailboxSource
{
	private mixed $socket = null;

	/**
	 * Open the POP connection, validate its greeting, and authenticate with mailbox credentials.
	 *
	 * @param string $host The POP server hostname used for the connection and mailbox identity.
	 * @param int $port The network port or port candidate to validate.
	 * @param bool $tls Whether to establish TLS at connection startup.
	 * @param bool $validate Whether TLS peer and hostname verification are required.
	 * @param string $username The POP mailbox login name.
	 * @param string $password The POP mailbox password used for authentication.
	 */
	public function __construct(string $host, int $port, bool $tls, bool $validate, string $username, string $password)
	{
		if (preg_match('/[\r\n]/', $username . $password))
        {
        	throw new PopMailboxSourceException('Invalid POP credentials');
        }
		$context = stream_context_create(['ssl' => ['verify_peer' => $validate, 'verify_peer_name' => $validate, 'allow_self_signed' => !$validate, 'peer_name' => $host]]);
		$this->socket = @stream_socket_client(($tls ? 'tls' : 'tcp') . '://' . $host . ':' . $port, $error, $description, 10, STREAM_CLIENT_CONNECT, $context);
		if (!$this->socket)
        {
        	throw new PopMailboxSourceException('Unable to connect to the POP server');
        }
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

	/**
	 * Read and validate the remote message-number to UIDL mapping.
	 *
	 * @return array The map of remote session message numbers to their unique POP UIDLs.
	 */
	public function uidls(): array
	{
		$this->command('UIDL');
		$result = [];
		$seen = [];
		while (($line = $this->line()) !== ".\r\n")
		{
			if (!preg_match('/^([1-9][0-9]*) ([\x21-\x7e]{1,70})\r\n$/D', $line, $match) || isset($result[(int) $match[1]]) || isset($seen[$match[2]]))
            {
            	throw new PopMailboxSourceException('Invalid or duplicate POP UIDL');
            }
			$result[(int) $match[1]] = $match[2];
			$seen[$match[2]] = true;
		}
		return $result;
	}

	/**
	 * Read a complete POP message with dot unstuffing and response-size limits.
	 *
	 * @param int $number The current remote POP session message number, not a local ID.
	 * @return string The original MIME message bytes, including headers and body.
	 */
	public function retrieve(int $number): string
	{
		if ($number < 1)
        {
        	throw new PopMailboxSourceException('Invalid POP message number');
        }
		$this->command('RETR ' . $number);
		$raw = '';
		while (($line = $this->line()) !== ".\r\n")
		{
			$raw .= str_starts_with($line, '..') ? substr($line, 1) : $line;
			if (strlen($raw) > 100 * 1024 * 1024)
            {
            	throw new PopMailboxSourceException('POP message exceeds the 100 MB import limit');
            }
		}
		return $raw;
	}

	/**
	 * Send POP logout when possible and close the socket even if logout fails.
	 *
	 * @return void No value is returned.
	 */
	public function close(): void
	{
		try
		{
			if (is_resource($this->socket))
            {
            	$this->command('QUIT');
            }
		}
		finally
        {
        	$this->disconnect();
        }
	}

	/**
	 * Release any remaining socket without issuing further POP commands.
	 */
	public function __destruct()
    {
    	$this->disconnect();
    }

	/**
	 * Write a complete POP command and require a positive response.
	 *
	 * @param string $command The POP command to send without a trailing CRLF.
	 * @return void No value is returned.
	 */
	private function command(string $command): void
	{
		$data = $command . "\r\n";
		while ($data !== '')
		{
			$written = @fwrite($this->socket, $data);
			if (!$written)
            {
            	throw new PopMailboxSourceException('POP command write failed');
            }
			$data = substr($data, $written);
		}
		$this->positive($this->line());
	}

	/**
	 * Require a positive POP status response and reject protocol failures.
	 *
	 * @param string $line The POP status line to validate.
	 * @return void No value is returned.
	 */
	private function positive(string $line): void
	{
		if (!preg_match('/^\+OK(?:[ \r\n]|$)/i', $line))
        {
        	throw new PopMailboxSourceException('POP server rejected the operation');
        }
	}

	/**
	 * Read one complete CRLF-terminated POP response line within the size limit.
	 *
	 * @return string The complete response line including its CRLF terminator.
	 */
	private function line(): string
	{
		$line = '';
		do
		{
			$chunk = @fgets($this->socket, 8192);
			if ($chunk === false || $chunk === '')
            {
            	throw new PopMailboxSourceException('Incomplete POP response');
            }
			$line .= $chunk;
			if (strlen($line) > 1024 * 1024)
            {
            	throw new PopMailboxSourceException('POP response line exceeds the import limit');
            }
		} while (!str_ends_with($line, "\r\n"));
		return $line;
	}

	/**
	 * Close the underlying socket and clear the connection reference.
	 *
	 * @return void No value is returned.
	 */
	private function disconnect(): void
	{
		if (is_resource($this->socket))
        {
        	fclose($this->socket);
        }
		$this->socket = null;
	}
}