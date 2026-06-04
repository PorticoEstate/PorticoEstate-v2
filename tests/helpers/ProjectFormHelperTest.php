<?php

namespace Tests\Helpers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use App\modules\property\helpers\ProjectFormHelper;
	use PHPUnit\Framework\TestCase;

	class ProjectFormHelperTest extends TestCase
	{
		public function testMapInputBuildsLocationArrayFromRelationInfoLocationCode(): void
		{
			$helper = new ProjectFormHelper();

			$state = $helper->mapInput(array(
				'values' => array(
					'name' => 'Project A',
				),
				'values_attribute' => array(),
				'RelationInfo' => array(
					'location_code' => '5804-01-02',
				),
			));

			$this->assertSame(array('loc1' => '5804', 'loc2' => '01', 'loc3' => '02'), $state['values']['location']);
			$this->assertSame('5804-01-02', $state['values']['location_code']);
		}

		public function testMapInputKeepsProvidedLocationArrayAndBackfillsLocationCode(): void
		{
			$helper = new ProjectFormHelper();

			$state = $helper->mapInput(array(
				'values' => array(
					'name' => 'Project B',
					'location' => array('loc1' => '5810', 'loc2' => '03', 'loc3' => '15'),
				),
				'values_attribute' => array(),
			));

			$this->assertSame(array('loc1' => '5810', 'loc2' => '03', 'loc3' => '15'), $state['values']['location']);
			$this->assertSame('5810-03-15', $state['values']['location_code']);
		}
	}
}
