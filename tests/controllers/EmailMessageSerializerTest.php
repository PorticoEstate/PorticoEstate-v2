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
						'server' => ['webserver_url' => 'https://example.test', 'usecookies' => true],
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

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function testRawEmailUrlsPreserveQueryParameters(): void
		{
			require_once __DIR__ . '/../../src/helpers/phpgw.php';
			$url = \phpgw::link('/email/view/inbox', [
				'fldball[acctnum]' => 0,
				'fldball[folder]' => 'Inbox & Sent',
				'click_history' => 'history-test',
			], true, false, false, true);
			$this->assertStringNotContainsString('&amp;', $url);
			$this->assertStringContainsString('&fldball%5Bfolder%5D=', $url);
			parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
			$this->assertSame(['fldball' => ['acctnum' => '0', 'folder' => 'Inbox & Sent'], 'click_history' => 'history-test'], $query);
			$this->assertSame($url, json_decode(json_encode($url, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
		}

		/**
		 * @runInSeparateProcess
		 * @preserveGlobalState disabled
		 */
		public function testTwigEscapesEmailUrlsOnlyOnce(): void
		{
			require_once __DIR__ . '/../../src/helpers/phpgw.php';
			$url = \phpgw::link('/email/view/inbox', ['fldball[acctnum]' => 0, 'fldball[folder]' => 'INBOX', 'click_history' => 'history-test'], true, false, false, true);
			$twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
				'link' => '<a href="{{ url|e }}" data-return-url="{{ url|e(\'html_attr\') }}">Inbox</a>',
			]));
			$html = $twig->render('link', ['url' => $url]);
			$this->assertStringNotContainsString('&amp;amp;', $html);
			$document = new \DOMDocument();
			$this->assertTrue($document->loadHTML($html));
			$link = $document->getElementsByTagName('a')->item(0);
			$this->assertInstanceOf(\DOMElement::class, $link);
			$this->assertSame($url, $link->getAttribute('href'));
			$this->assertSame($url, $link->getAttribute('data-return-url'));
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