<?php

namespace App\modules\property\helpers;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class SmsRecipientImport
{
	public static function read(string $path, string $extension): array
	{
		$extension = strtolower($extension);
		if (!in_array($extension, ['csv', 'xls', 'xlsx'], true))
		{
			throw new RuntimeException('Choose a CSV, XLS or XLSX file.');
		}

		$type = $extension === 'csv' ? 'Csv' : IOFactory::identify($path, ['Xls', 'Xlsx']);
		$reader = IOFactory::createReader($type);
		$spreadsheet = $reader->load($path);
		$sheet = $spreadsheet->getSheet(0);
		$recipients = [];
		$invalidRows = [];
		$lastRow = $sheet->getHighestDataRow();

		try
		{
			for ($row = 2; $row <= $lastRow; $row++)
			{
				$parts = [];
				for ($column = 1; $column <= 3; $column++)
				{
					$part = trim((string)$sheet->getCell([$column, $row])->getValue());
					if ($part !== '')
					{
						$parts[] = $part;
					}
				}
				$phoneCell = $sheet->getCell([4, $row]);
				$isFormula = $phoneCell->getDataType() === DataType::TYPE_FORMULA;
				$phone = trim((string)($isFormula ? $phoneCell->getValue() : $phoneCell->getFormattedValue()));
				if (!$parts && $phone === '')
				{
					continue;
				}

				$number = $isFormula ? null : self::phoneNumber($phone);
				if ($number === null)
				{
					$invalidRows[] = $row;
					continue;
				}
				$recipients[] = ['label' => implode(' - ', $parts), 'phone' => $number];
			}
		}
		finally
		{
			$spreadsheet->disconnectWorksheets();
		}

		if (!$recipients && !$invalidRows)
		{
			throw new RuntimeException('No recipients found after the header row.');
		}
		return ['recipients' => $recipients, 'invalid_rows' => $invalidRows];
	}

	private static function phoneNumber(string $phone): ?string
	{
		if (!preg_match('/^\+?[0-9\s()\-]+$/u', $phone))
		{
			return null;
		}
		$number = preg_replace('/[\s()\-]/u', '', $phone);
		if (str_starts_with($number, '+47'))
		{
			$number = substr($number, 3);
		}
		elseif (str_starts_with($number, '0047'))
		{
			$number = substr($number, 4);
		}
		return preg_match('/^[0-9]{8}$/D', $number) ? $number : null;
	}
}
