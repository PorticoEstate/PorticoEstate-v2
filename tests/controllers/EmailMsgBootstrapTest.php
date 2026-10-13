<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/modules/email/inc/class.msg_bootstrap.inc.php';

	use PHPUnit\Framework\TestCase;

	class EmailMsgBootstrapTest extends TestCase
	{
		public function testIntegerLoginModesArePreserved(): void
		{
			$bootstrap = new \email_msg_bootstrap();

			$this->assertSame(\email_msg_bootstrap::LOGIN_NEVER, $bootstrap->set_do_login(\email_msg_bootstrap::LOGIN_NEVER));
			$this->assertFalse($bootstrap->get_do_login());
			$this->assertSame(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED, $bootstrap->set_do_login(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED));
			$this->assertTrue($bootstrap->get_do_login());
			$this->assertSame(\email_msg_bootstrap::LOGIN_YES, $bootstrap->set_do_login(\email_msg_bootstrap::LOGIN_YES));
			$this->assertTrue($bootstrap->get_do_login());
		}

		public function testBooleanLoginModeCompatibilityIsPreserved(): void
		{
			$bootstrap = new \email_msg_bootstrap();

			$this->assertFalse($bootstrap->set_do_login(false));
			$this->assertSame(\email_msg_bootstrap::LOGIN_NEVER, $bootstrap->get_do_login_ex());
			$this->assertTrue($bootstrap->set_do_login(true));
			$this->assertSame(\email_msg_bootstrap::LOGIN_ONLY_IF_NEEDED, $bootstrap->get_do_login_ex());
		}
	}
}