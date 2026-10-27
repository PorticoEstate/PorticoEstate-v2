<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_base.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_wrappers.inc.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.mail_msg_display.inc.php';

use App\modules\phpgwapi\services\Settings;
use PHPUnit\Framework\TestCase;

class EmailDisplayHelpersTest extends TestCase
{
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testMultilineLinkificationBuildsComposeLinkOnce(): void
	{
		$linker = new class
		{
			public static int $calls = 0;

			public static function link($path, $args): string
			{
				self::$calls++;
				return $path . '?' . http_build_query($args);
			}
		};
		class_alias(get_class($linker), 'phpgw');
		$settings = new \ReflectionProperty(Settings::class, 'instance');
		$settings->setAccessible(true);
		$original = $settings->getValue();
		$settings->setValue(null, new class
		{
			public function get(string $key): array
			{
				return ['currentapp' => 'email'];
			}
		});
		try
		{
			$mail = $this->getMockBuilder(\mail_msg::class)->disableOriginalConstructor()
				->onlyMethods(['prep_folder_out'])->getMock();
			$mail->expects($this->once())->method('prep_folder_out')
				->with('Archive%2F2026')->willReturn('Archive/2026');
			$body = "alice@example.test\nhttps://example.test\nbob@example.test\n";
			$expected = '<a href="/email/view/compose?folder=Archive%2F2026&to=alice@example.test">alice@example.test</a>' . "\n"
				. '<A href="https://example.test" target="_new">https://example.test</A>' . "\n"
				. '<a href="/email/view/compose?folder=Archive%2F2026&to=bob@example.test">bob@example.test</a>' . "\n\n";
			$this->assertSame($expected, $mail->make_clickable($body, 'Archive%2F2026'));
			$this->assertSame(1, $linker::$calls);
			$this->assertSame('', $mail->make_clickable('', 'Archive%2F2026'));
			$this->assertSame(1, $linker::$calls);
		}
		finally
		{
			$settings->setValue(null, $original);
		}
	}
}
