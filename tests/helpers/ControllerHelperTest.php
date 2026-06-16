<?php

namespace
{
	require_once __DIR__ . '/../../vendor/autoload.php';
	require_once __DIR__ . '/../../src/helpers/Sanitizer.php';
	require_once __DIR__ . '/../../src/modules/property/inc/class.controller_helper.inc.php';

	if (!function_exists('lang'))
	{
		function lang(string $text, ...$args): string
		{
			if (!$args)
			{
				return $text;
			}

			foreach ($args as $i => $arg)
			{
				$text = str_replace('%' . ($i + 1), (string)$arg, $text);
			}

			return $text;
		}
	}

	if (!function_exists('CreateObject'))
	{
		function CreateObject(string $name, ...$args)
		{
			if ($name === 'controller.socheck_item')
			{
				return $GLOBALS['__controller_helper_check_item_stub'] ?? null;
			}

			if ($name === 'controller.socheck_list')
			{
				return $GLOBALS['__controller_helper_check_list_stub'] ?? null;
			}

			if ($name === 'controller.socontrol')
			{
				return $GLOBALS['__controller_helper_control_stub'] ?? null;
			}

			return null;
		}
	}
}

namespace Tests\Helpers
{
	use PHPUnit\Framework\TestCase;

	class ControllerHelperTest extends TestCase
	{
		protected function tearDown(): void
		{
			unset(
				$GLOBALS['__controller_helper_check_item_stub'],
				$GLOBALS['__controller_helper_check_list_stub'],
				$GLOBALS['__controller_helper_control_stub']
			);

			unset($_GET['check_list_id'], $_REQUEST['check_list_id']);
		}

		public function testGetCasesForChecklistUsesExplicitArgument(): void
		{
			$GLOBALS['__controller_helper_check_item_stub'] = new class
			{
				public ?int $receivedCheckListId = null;

				public function get_check_items_with_cases($checkListId, ...$args): array
				{
					$this->receivedCheckListId = (int)$checkListId;
					return [];
				}
			};

			$GLOBALS['__controller_helper_check_list_stub'] = new class
			{
				public function get_single($id)
				{
					return new class
					{
						public function get_control_id()
						{
							return 0;
						}
					};
				}
			};

			$GLOBALS['__controller_helper_control_stub'] = new class
			{
				public function get_single($id)
				{
					return new class
					{
						public function get_title()
						{
							return '';
						}
					};
				}
			};

			$_GET['check_list_id'] = '99';
			$_REQUEST['check_list_id'] = '99';

			$helper = new \property_controller_helper();
			$helper->get_cases_for_checklist(44);

			$this->assertSame(44, $GLOBALS['__controller_helper_check_item_stub']->receivedCheckListId);
		}
	}
}
