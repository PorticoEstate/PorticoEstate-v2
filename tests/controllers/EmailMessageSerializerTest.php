<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/helpers/Sanitizer.php';

	use App\modules\phpgwapi\services\Settings;
	use App\modules\email\services\EmailMessageSerializer;
	use PHPUnit\Framework\TestCase;

	class EmailMessageSerializerTest extends TestCase
	{
		private $settingsInstanceProperty;
		private $originalSettingsInstance;

		protected function setUp(): void
		{
			$settingsReflection = new \ReflectionClass(Settings::class);
			$this->settingsInstanceProperty = $settingsReflection->getProperty('instance');
			$this->settingsInstanceProperty->setAccessible(true);
			$this->originalSettingsInstance = $this->settingsInstanceProperty->getValue();
			$this->settingsInstanceProperty->setValue(null, new class
			{
				public function get(string $key): array
				{
					return match ($key)
					{
						'server' => ['webserver_url' => 'https://example.test'],
						'flags' => [],
						default => [],
					};
				}
			});
		}

		protected function tearDown(): void
		{
			$this->settingsInstanceProperty->setValue(null, $this->originalSettingsInstance);
			parent::tearDown();
		}

		public function testSanitizesShownBodyPartsAndOmitsNonDisplayParts(): void
		{
			$result = EmailMessageSerializer::serialize([
				'account' => '2',
				'folder' => 'INBOX',
				'messageNumber' => '42',
				'message_subject' => 'Notice',
				'message_date' => 'Today',
			], [
				[
					'd_instructions' => 'show',
					'type' => 'text',
					'subtype' => 'html',
					'message_body' => '<div onclick="run()"><p>Safe</p><script>run()</script></div>',
			],
				[
					'd_instructions' => 'skip',
					'message_body' => '<p>Hidden</p>',
				],
				[
					'ex_attachment' => true,
					'm_part_num_mime' => '2',
					'ex_part_name' => 'report.txt',
					'bytes' => 128,
					'type' => 'text',
					'subtype' => 'plain',
					'encoding' => 'base64',
				],
			], [
				'from' => [['name' => 'Sender', 'address' => 'sender@example.test']],
			]);

			$this->assertSame('Notice', $result['subject']);
			$this->assertSame(2, $result['account']);
			$this->assertSame(42, $result['messageNumber']);
			$this->assertSame('Sender', $result['from'][0]['name']);
			$this->assertCount(1, $result['bodyParts']);
			$this->assertStringContainsString('<p>Safe</p>', $result['bodyParts'][0]['html']);
			$this->assertStringNotContainsString('<script', $result['bodyParts'][0]['html']);
			$this->assertStringNotContainsString('onclick', $result['bodyParts'][0]['html']);
			$this->assertSame('report.txt', $result['attachments'][0]['fileName']);
			$this->assertSame('base64', $result['attachments'][0]['encoding']);
		}
	}
}