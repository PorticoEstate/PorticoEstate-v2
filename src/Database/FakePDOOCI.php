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

		$this->connection = $this->callOci('oci_pconnect', [$username, $password, $connection_string, $encoding]);
		if (!$this->connection)
		{
			$e = $this->callOci('oci_error');
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
		$statement = $this->callOci('oci_parse', [$this->connection, $query]);
		if (!$statement)
		{
			$e = $this->callOci('oci_error', [$this->connection]);
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
		$this->callOci('oci_close', [$this->connection]);
	}

	/**
	 * Call an OCI function when available and fail with a clear message otherwise.
	 */
	private function callOci($function, array $args = [])
	{
		if (!function_exists($function))
		{
			throw new Exception(sprintf('Required OCI function %s() is not available', $function));
		}

		return call_user_func_array($function, $args);
	}
}
