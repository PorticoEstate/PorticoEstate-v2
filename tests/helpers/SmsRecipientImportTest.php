<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\property\helpers\SmsRecipientImport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class SmsRecipientImportTest extends TestCase
{
	public function testCsvImportsMoreThanFiveThousandRows(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'sms-csv-');
		try
		{
			$file = fopen($path, 'w');
			fwrite($file, "id;address;name;phonenumber;type\n");
			for ($i = 0; $i < 5001; $i++)
			{
				fwrite($file, "{$i};Address {$i};Name {$i};+47 " . (90000000 + $i) . ";Ignored\n");
			}
			fclose($file);
			$result = SmsRecipientImport::read($path, 'csv');
			$recipients = $result['recipients'];
			$this->assertCount(5001, $recipients);
			$this->assertSame(['label' => '5000 - Address 5000 - Name 5000', 'phone' => '90005000'], $recipients[5000]);
			$this->assertSame([], $result['invalid_rows']);
		}
		finally
		{
			unlink($path);
		}
	}

	public function testExcelPreservesFormattedNumbersAndRejectsFormulas(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'sms-xlsx-');
		$book = new Spreadsheet();
		try
		{
			$sheet = $book->getActiveSheet();
			$sheet->fromArray([['ID', 'Address', 'Name', 'Phone', 'Type'], [5432, 'Road', 'Example', 1234567, 'Ignored']]);
			$sheet->getStyle('D2')->getNumberFormat()->setFormatCode('00000000');
			(new Xlsx($book))->save($path);
			$this->assertSame(['label' => '5432 - Road - Example', 'phone' => '01234567'], SmsRecipientImport::read($path, 'xlsx')['recipients'][0]);

			$sheet->setCellValue('D2', '=91234567');
			(new Xlsx($book))->save($path);
			$this->assertSame(['recipients' => [], 'invalid_rows' => [2]], SmsRecipientImport::read($path, 'xlsx'));
		}
		finally
		{
			$book->disconnectWorksheets();
			unlink($path);
		}
	}

	public function testTabSeparatedRowsCombineFirstThreeColumnsAndIgnoreLast(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'sms-csv-');
		try
		{
			file_put_contents($path, "ID\tAddress\tName\tPhone\tType\n\"5432\"\t\"Example road 30\"\t\"Example, Person\"\t\"45861645\"\t\"RENTAL\"\n\"0\"\t\"\"\t\"Other\"\t\"91234567\"\t\"\"\n\t\t\t\tIgnored\n");
			$this->assertSame(['recipients' => [
				['label' => '5432 - Example road 30 - Example, Person', 'phone' => '45861645'],
				['label' => '0 - Other', 'phone' => '91234567'],
			], 'invalid_rows' => []], SmsRecipientImport::read($path, 'csv'));
		}
		finally
		{
			unlink($path);
		}
	}

	public function testInvalidRowsAreReportedWhileValidRowsAreImported(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'sms-csv-');
		try
		{
			file_put_contents($path, "ID,Address,Name,Phone,Type\n1,Road,One,004791234567,Ignored\n2,Road,Two,+44 12345678,Ignored\n3,Road,Three,,Ignored\n");
			$this->assertSame([
				'recipients' => [['label' => '1 - Road - One', 'phone' => '91234567']],
				'invalid_rows' => [3, 4],
			], SmsRecipientImport::read($path, 'csv'));
		}
		finally
		{
			unlink($path);
		}
	}

	public function testAllInvalidRowsAreReported(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'sms-csv-');
		try
		{
			$file = fopen($path, 'w');
			fwrite($file, "ID,Address,Name,Phone,Type\n");
			for ($row = 2; $row <= 26; $row++)
			{
				fwrite($file, "{$row},Road,Name,invalid,Ignored\n");
			}
			fclose($file);
			$this->assertSame(['recipients' => [], 'invalid_rows' => range(2, 26)], SmsRecipientImport::read($path, 'csv'));
		}
		finally
		{
			unlink($path);
		}
	}
}
