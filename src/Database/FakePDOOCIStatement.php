<?php

namespace App\Database;

use Exception;

class FakePDOOCIStatement
{
	private $statement;

	public function __construct($statement)
	{
		$this->statement = $statement;
	}

	public function execute($params = [])
	{
		foreach ($params as $key => $value)
		{
			$this->callOci('oci_bind_by_name', [$this->statement, $key, $params[$key]]);
		}
		$result = $this->callOci('oci_execute', [$this->statement]);
		if (!$result)
		{
			$e = $this->callOci('oci_error', [$this->statement]);
			throw new Exception($e['message']);
		}
		return $result;
	}

	public function fetch($mode = null)
	{
		if ($mode === null)
		{
			if (defined('OCI_ASSOC'))
			{
				$mode = constant('OCI_ASSOC');
				return $this->callOci('oci_fetch_array', [$this->statement, $mode]);
			}

			return $this->callOci('oci_fetch_array', [$this->statement]);
		}

		return $this->callOci('oci_fetch_array', [$this->statement, $mode]);
	}

	public function fetchAll($mode = null)
	{
		$rows = [];
		while ($row = $this->fetch($mode))
		{
			$rows[] = $row;
		}
		return $rows;
	}

	public function rowCount()
	{
		return $this->callOci('oci_num_rows', [$this->statement]);
	}

	public function close()
	{
		$this->callOci('oci_free_statement', [$this->statement]);
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
