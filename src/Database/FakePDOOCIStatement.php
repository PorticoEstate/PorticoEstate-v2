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
			oci_bind_by_name($this->statement, $key, $params[$key]);
		}
		$result = oci_execute($this->statement);
		if (!$result)
		{
			$e = oci_error($this->statement);
			throw new Exception($e['message']);
		}
		return $result;
	}

	public function fetch($mode = OCI_ASSOC)
	{
		return oci_fetch_array($this->statement, $mode);
	}

	public function fetchAll($mode = OCI_ASSOC)
	{
		$rows = [];
		while ($row = oci_fetch_array($this->statement, $mode))
		{
			$rows[] = $row;
		}
		return $rows;
	}

	public function rowCount()
	{
		return oci_num_rows($this->statement);
	}

	public function close()
	{
		oci_free_statement($this->statement);
	}
}
