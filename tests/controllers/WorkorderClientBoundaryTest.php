<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use PHPUnit\Framework\TestCase;

	class WorkorderClientBoundaryTest extends TestCase
	{
		public function testWorkorderEditUsesRestDataEndpoints(): void
		{
			$path = __DIR__ . '/../../src/modules/property/js/base/workorder.edit.js';
			$contents = file_get_contents($path);

			$this->assertIsString($contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/vendor-contract'", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/eco-service'", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/ecodimb'", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/b-account'", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/unspsc-code'", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/' + workorder_id + '/receive-order'", $contents);

			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_vendor_contract'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_eco_service'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_ecodimb'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_b_account'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_unspsc_code'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.receive_order'", $contents);
		}

		public function testWorkorderRelatedClientsUseRestDataEndpoints(): void
		{
			$addInvoice = (string)file_get_contents(__DIR__ . '/../../src/modules/property/js/base/workorder.add_invoice.js');
			$addDeviation = (string)file_get_contents(__DIR__ . '/../../src/modules/property/js/base/external_communication.add_deviation.js');
			$orderTemplate = (string)file_get_contents(__DIR__ . '/../../src/modules/property/js/base/order_template.edit.js');

			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/vendor-contract'", $addInvoice);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/vendor-contract'", $addDeviation);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/other-orders'", $addDeviation);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/vendor-contract'", $orderTemplate);

			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_vendor_contract'", $addInvoice);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_vendor_contract'", $addDeviation);
			$this->assertStringNotContainsString("menuaction:'property.uiworkorder.get_other_orders'", $addDeviation);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_vendor_contract'", $orderTemplate);
		}

		public function testWorkorderNavigationStillUsesLegacyMenuaction(): void
		{
			$indexPath = __DIR__ . '/../../src/modules/property/js/base/workorder.index.js';
			$contents = file_get_contents($indexPath);

			$this->assertIsString($contents);
			$this->assertStringContainsString("menuaction: 'property.uiworkorder.edit'", $contents);
		}

		public function testWorkorderEditUsesRestSaveEndpoints(): void
		{
			$editPath = __DIR__ . '/../../src/modules/property/js/base/workorder.edit.js';
			$contents = (string)file_get_contents($editPath);

			$this->assertStringContainsString("function createWorkorderApiClient(form)", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/create', {})", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/' + parsedOrderId, {})", $contents);
			$this->assertStringContainsString("submit_workorder_via_api('save')", $contents);
			$this->assertStringContainsString("submit_workorder_via_api('send')", $contents);
			$this->assertStringContainsString("submit_workorder_via_api('calculate')", $contents);
		}

		public function testWorkorderRoutesExposeRestSaveEndpoints(): void
		{
			$routesPath = __DIR__ . '/../../src/modules/property/routes/Routes.php';
			$contents = (string)file_get_contents($routesPath);

			$this->assertStringContainsString('$group->post(\'/create\', [$controller, \'store\']);', $contents);
			$this->assertStringContainsString('$group->post(\'/{id:[0-9]+}\', [$controller, \'update\']);', $contents);
		}
	}
}
