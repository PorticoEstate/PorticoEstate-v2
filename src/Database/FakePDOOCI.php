<?php

namespace App\Database;

use Exception;
use App\Database\FakePDOOCIStatement;

class FakePDOOCI
{
	private $connection;

	public function __construct($dsn, $username, $password)
	{
		// Parse the DSN string to extract the connection string and encoding
		$parsedDsn = $this->parseDsn($dsn);

		$connection_string = $parsedDsn['connection_string'];
		$encoding = $parsedDsn['encoding'];

		$this->connection = oci_pconnect($username, $password, $connection_string, $encoding);
		if (!$this->connection)
		{
			$e = oci_error();
			throw new Exception($e['message']);
		}
	}

	private function parseDsn($dsn)
	{
		$pattern = '/^oci:dbname=(.+?)(?:;charset=(.+))?$/';
		if (preg_match($pattern, $dsn, $matches))
		{
			return [
				'connection_string' => $matches[1],
				'encoding' => $matches[2] ?? ''
			];
		}
		else
		{
			throw new Exception('Invalid DSN string');
		}
	}

	public function prepare($query)
	{
		$statement = oci_parse($this->connection, $query);
		if (!$statement)
		{
			$e = oci_error($this->connection);
			throw new Exception($e['message']);
		}
		return new FakePDOOCIStatement($statement);
	}

	public function query($query)
	{
		$statement = $this->prepare($query);
		$statement->execute();
		return $statement;
	}

	public function close()
	{
		oci_close($this->connection);
	}
}
