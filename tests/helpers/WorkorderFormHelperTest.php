<?php

namespace
{
	require_once __DIR__ . '/../../vendor/autoload.php';

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

	if (!class_exists('phpgw'))
	{
		class phpgw
		{
			public static function is_repost(bool $display_error = false): bool
			{
				return false;
			}
		}
	}
}

namespace Tests\Helpers
{
	use App\modules\property\helpers\WorkorderFormHelper;
	use PHPUnit\Framework\TestCase;

	class WorkorderFormHelperTest extends TestCase
	{
		private function makeHelper(
			array $config = array(),
			array $projects = array(),
			array $genericByType = array(),
			array $categories = array(),
			array $invoiceRole = array(),
			bool $isManage = false,
			int $accountId = 0
		): WorkorderFormHelper
		{
			return new class($config, $projects, $genericByType, $categories, $invoiceRole, $isManage, $accountId) extends WorkorderFormHelper
			{
				private array $config;
				private array $projects;
				private array $genericByType;
				private array $categories;
				private array $invoiceRole;
				private bool $isManage;
				private int $accountId;

				public function __construct(array $config, array $projects, array $genericByType, array $categories, array $invoiceRole, bool $isManage, int $accountId)
				{
					$this->config = $config;
					$this->projects = $projects;
					$this->genericByType = $genericByType;
					$this->categories = $categories;
					$this->invoiceRole = $invoiceRole;
					$this->isManage = $isManage;
					$this->accountId = $accountId;
				}

				protected function getConfigData(): array
				{
					return $this->config;
				}

				protected function readProjectMini(int $projectId): array
				{
					return $this->projects[$projectId] ?? array();
				}

				protected function readGeneric(string $type, int $id): array
				{
					return $this->genericByType[$type][$id] ?? array();
				}

				protected function readCategory(int $catId): ?array
				{
					return $this->categories[$catId] ?? null;
				}

				protected function checkInvoiceRole(string $ecodimb): array
				{
					return $this->invoiceRole;
				}

				protected function hasManageAccess(): bool
				{
					return $this->isManage;
				}

				protected function getCurrentAccountId(): int
				{
					return $this->accountId;
				}
			};
		}

		public function testMapInputBuildsLocationFromLocationCodeInValues(): void
		{
			$helper = $this->makeHelper();

			$result = $helper->mapInput(array(
				'values' => array(
					'title' => 'WO',
					'location_code' => '5804-01-02',
				),
			));

			$this->assertSame(array('loc1' => '5804', 'loc2' => '01', 'loc3' => '02'), $result['values']['location']);
			$this->assertSame('5804-01-02', $result['values']['location_code']);
		}

		public function testMapInputBuildsLocationFromFlatLocFields(): void
		{
			$helper = $this->makeHelper();

			$result = $helper->mapInput(array(
				'values' => array(
					'title' => 'WO',
					'loc1' => '5804',
					'loc2' => '01',
				),
			));

			$this->assertSame(array('loc1' => '5804', 'loc2' => '01'), $result['values']['location']);
			$this->assertSame('5804-01', $result['values']['location_code']);
		}

		public function testMapInputPromotesRelationInfoFieldsIntoValues(): void
		{
			$helper = $this->makeHelper();

			$result = $helper->mapInput(array(
				'values' => array(
					'title' => 'WO',
				),
				'RelationInfo' => array(
					'location_code' => '5804-77-09',
					'tenant_id' => '312',
					'p_entity_id' => 3,
					'p_cat_id' => 4,
					'p_num' => '55',
					'origin' => 'property.ticket',
					'origin_id' => 99,
				),
			));

			$this->assertSame('5804-77-09', $result['values']['location_code']);
			$this->assertSame(array('loc1' => '5804', 'loc2' => '77', 'loc3' => '09'), $result['values']['location']);
			$this->assertSame('312', $result['values']['tenant_id']);
			$this->assertSame(3, $result['values']['p_entity_id']);
			$this->assertSame(4, $result['values']['p_cat_id']);
			$this->assertSame('55', $result['values']['p_num']);
			$this->assertSame('property.ticket', $result['values']['origin']);
			$this->assertSame(99, $result['values']['origin_id']);
			$this->assertArrayNotHasKey('location_code', $result['values']['extra']);
			$this->assertSame('312', $result['values']['extra']['tenant_id']);
			$this->assertSame(3, $result['values']['extra']['p_entity_id']);
			$this->assertSame(4, $result['values']['extra']['p_cat_id']);
			$this->assertSame('55', $result['values']['extra']['p_num']);
			$this->assertArrayNotHasKey('origin', $result['values']['extra']);
			$this->assertArrayNotHasKey('origin_id', $result['values']['extra']);
		}

		public function testMapInputCopiesContactPhoneIntoExtra(): void
		{
			$helper = $this->makeHelper();

			$result = $helper->mapInput(array(
				'values' => array(
					'title' => 'WO',
					'contact_phone' => '55512345',
				),
			));

			$this->assertSame('55512345', $result['values']['extra']['contact_phone']);
		}

		public function testMapInputPromotesStreetAndLocationNameFromTopLevelPayload(): void
		{
			$helper = $this->makeHelper();

			$result = $helper->mapInput(array(
				'values' => array(
					'title' => 'WO',
					'location_code' => '5804-01-01',
				),
				'street_name' => 'Main Street',
				'street_number' => '12B',
				'location_name' => 'Building A',
			));

			$this->assertSame('Main Street', $result['values']['street_name']);
			$this->assertSame('12B', $result['values']['street_number']);
			$this->assertSame('Building A', $result['values']['location_name']);
		}

		public function testMapInputMergesAdditionalInfoFromPayload(): void
		{
			$helper = new class extends WorkorderFormHelper
			{
				protected function mergeAdditionalInfoFromPayload(array $values, array $requestData): array
				{
					$values['additional_info']['Room'] = $requestData['room_name'] ?? '';
					return $values;
				}
			};

			$result = $helper->mapInput(array(
				'values' => array(
					'title' => 'WO',
				),
				'room_name' => 'A-201',
			));

			$this->assertSame('A-201', $result['values']['additional_info']['Room']);
		}

		public function testMapInputPromotesCopyWorkorderFromFromTopLevelPayload(): void
		{
			$helper = $this->makeHelper();

			$result = $helper->mapInput(array(
				'values' => array(
					'title' => 'WO',
				),
				'copy_workorder_from' => '77',
			));

			$this->assertSame('77', (string)$result['values']['copy_workorder_from']);
		}

		public function testValidateUnsetsNewProjectWhenEqualProject(): void
		{
			$helper = $this->makeHelper();
			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'new_project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 101,
				),
			);

			$result = $helper->validate($state);
			$this->assertArrayNotHasKey('new_project_id', $result['values']);
		}

		public function testValidateRejectsUnknownNewProject(): void
		{
			$helper = $this->makeHelper();
			$state = array(
				'is_edit' => false,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'new_project_id' => 999,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 101,
					'budget' => '10',
				),
			);

			$result = $helper->validate($state);
			$this->assertContains('the project 999 does not exist', $result['errors']);
		}

		public function testValidateRejectsInactiveBudgetAccountAndClearsFields(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(),
				array('budget_account' => array(8 => array('active' => 0)))
			);

			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'b_account_name' => 'old',
					'ecodimb' => 101,
				),
			);

			$result = $helper->validate($state);
			$this->assertSame('', $result['values']['b_account_id']);
			$this->assertSame('', $result['values']['b_account_name']);
			$this->assertContains('Please select a valid budget account !', $result['errors']);
		}

		public function testValidateAppliesBudgetAccountBoundEcodimbAndAddsMessage(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(),
				array(
					'budget_account' => array(8 => array('active' => 1, 'ecodimb' => '444')),
					'dimb' => array(444 => array('active' => 1)),
				)
			);

			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => '333',
				),
			);

			$result = $helper->validate($state);
			$this->assertSame('444', $result['values']['ecodimb']);
			$this->assertContains('Ansvar er overstyrt av binding til art: 333 -> 444', $result['messages']);
		}

		public function testValidateFallsBackToProjectEcodimbAndRequiresActiveDimb(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(20 => array('ecodimb' => '121')),
				array(
					'budget_account' => array(8 => array('active' => 1)),
					'dimb' => array(121 => array('active' => 1)),
				)
			);

			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => '',
				),
			);

			$result = $helper->validate($state);
			$this->assertSame('121', $result['values']['ecodimb']);
			$this->assertNotContains('Please select dimb!', $result['errors']);
		}

		public function testValidateRequiresBudgetOrContractOnCreate(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(),
				array(
					'budget_account' => array(8 => array('active' => 1)),
					'dimb' => array(123 => array('active' => 1)),
				)
			);

			$state = array(
				'is_edit' => false,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 123,
					'budget' => '',
					'contract_sum' => '',
				),
			);

			$result = $helper->validate($state);
			$this->assertContains('please enter either a budget or contrakt sum', $result['errors']);
		}

		public function testValidatePromotesBudgetToContractSumWhenContractIsHigher(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(),
				array(
					'budget_account' => array(8 => array('active' => 1)),
					'dimb' => array(123 => array('active' => 1)),
				)
			);

			$state = array(
				'is_edit' => false,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 123,
					'budget' => '100',
					'contract_sum' => '200',
				),
			);

			$result = $helper->validate($state);
			$this->assertSame('200', $result['values']['budget']);
		}

		public function testValidateRejectsInactiveCategory(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(),
				array(
					'budget_account' => array(8 => array('active' => 1)),
					'dimb' => array(123 => array('active' => 1)),
				),
				array(99 => array('active' => 0))
			);

			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 123,
					'cat_id' => 99,
				),
			);

			$result = $helper->validate($state);
			$this->assertContains('invalid category', $result['errors']);
		}

		public function testValidateMutatesStatusOnApprovalConfigAndEnforcesInvoiceRole(): void
		{
			$helper = $this->makeHelper(
				array(
					'workorder_approval' => 1,
					'workorder_approval_status' => 'approval_pending',
					'invoice_acl' => 'dimb',
				),
				array(20 => array('ecodimb' => '777')),
				array(
					'budget_account' => array(8 => array('active' => 1)),
					'dimb' => array(777 => array('active' => 1)),
				),
				array(),
				array('is_janitor' => 0, 'is_supervisor' => 0, 'is_budget_responsible' => 0),
				false,
				12
			);

			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 777,
					'approval' => array(12 => 'mail@example.com'),
					'do_approve' => array(12 => 1),
				),
			);

			$result = $helper->validate($state);
			$this->assertSame('approval_pending', $result['values']['status']);
			$this->assertFalse($result['values']['approved']);
			$this->assertContains('you are not approved for this dimb: 777', $result['errors']);
			$this->assertContains('you do not have permission to approve this order', $result['errors']);
		}

		public function testValidateRejectsNegativeAdditionPercentage(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(),
				array(
					'budget_account' => array(8 => array('active' => 1)),
				),
				array(),
				array(),
				false,
				0
			);

			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 123,
					'addition_percentage' => '-1',
				),
			);

			$result = $helper->validate($state);
			$this->assertContains('Percentage addition: Please enter an integer !', $result['errors']);
		}

		public function testValidateDoesNotCheckActiveDimbWhenProvidedDirectly(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(20 => array('ecodimb' => '777')),
				array(
					'budget_account' => array(8 => array('active' => 1)),
					'dimb' => array(555 => array('active' => 0)),
				)
			);

			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 555,
				),
			);

			$result = $helper->validate($state);
			$this->assertNotContains('Please select a valid dimb!', $result['errors']);
		}

		public function testValidateCopyWorkorderInheritsBudgetFromSourceId(): void
		{
			$helper = new class extends WorkorderFormHelper
			{
				protected function getConfigData(): array
				{
					return array();
				}

				protected function readProjectMini(int $projectId): array
				{
					return array('ecodimb' => '123');
				}

				protected function readGeneric(string $type, int $id): array
				{
					if ($type === 'budget_account')
					{
						return array('active' => 1);
					}

					if ($type === 'dimb')
					{
						return array('active' => 1);
					}

					return array();
				}

				protected function readWorkorderSingle(int $workorderId): array
				{
					return array('budget' => 3500);
				}
			};

			$state = array(
				'is_edit' => false,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => 123,
					'copy_workorder' => 1,
					'copy_workorder_from' => 77,
					'budget' => '',
					'contract_sum' => '',
				),
			);

			$result = $helper->validate($state);
			$this->assertSame('3500', $result['values']['budget']);
			$this->assertNotContains('please enter either a budget or contrakt sum', $result['errors']);
		}

		public function testValidateChecksActiveDimbOnlyOnFallbackPath(): void
		{
			$helper = $this->makeHelper(
				array(),
				array(20 => array('ecodimb' => '777')),
				array(
					'budget_account' => array(8 => array('active' => 1)),
					'dimb' => array(777 => array('active' => 0)),
				)
			);

			$state = array(
				'is_edit' => true,
				'values' => array(
					'title' => 'WO',
					'project_id' => 20,
					'status' => 'open',
					'b_account_id' => 8,
					'ecodimb' => '',
				),
			);

			$result = $helper->validate($state);
			$this->assertContains('Please select a valid dimb!', $result['errors']);
			$this->assertSame('', $result['values']['ecodimb']);
		}

		public function testPersistSaveAppendsMessagesToSuccessfulReceipt(): void
		{
			$helper = new WorkorderFormHelper();
			$bo = new class
			{
				public function save(array $values, string $action, array $valuesAttribute): array
				{
					return array(
						'id' => 501,
						'message' => array(array('msg' => 'existing receipt message')),
					);
				}
			};

			$state = array(
				'values' => array('title' => 'WO', 'id' => 0),
				'values_attribute' => array(),
				'is_edit' => false,
				'errors' => array(),
				'messages' => array('Ansvar er overstyrt av binding til art: 333 -> 444'),
			);

			$result = $helper->persistSave($state, $bo);
			$this->assertSame(501, $result['id']);
			$this->assertCount(2, $result['receipt']['message']);
			$this->assertSame('existing receipt message', $result['receipt']['message'][0]['msg']);
			$this->assertSame('Ansvar er overstyrt av binding til art: 333 -> 444', $result['receipt']['message'][1]['msg']);
		}

		public function testPersistSaveIncludesMessagesInErrorReceipt(): void
		{
			$helper = new WorkorderFormHelper();
			$bo = new class
			{
				public function save(array $values, string $action, array $valuesAttribute): array
				{
					return array('id' => 999);
				}
			};

			$state = array(
				'values' => array('title' => 'WO'),
				'values_attribute' => array(),
				'is_edit' => false,
				'errors' => array('Please select a valid dimb!'),
				'messages' => array('Ansvar er overstyrt av binding til art: 333 -> 444'),
			);

			$result = $helper->persistSave($state, $bo);
			$this->assertSame('error', $result['receipt']['status']);
			$this->assertSame('Please select a valid dimb!', $result['receipt']['error'][0]['msg']);
			$this->assertSame('Ansvar er overstyrt av binding til art: 333 -> 444', $result['receipt']['message'][0]['msg']);
		}
	}
}
