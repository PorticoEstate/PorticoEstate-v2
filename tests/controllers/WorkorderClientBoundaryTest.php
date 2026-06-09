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
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/category'", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/unspsc-code'", $contents);
			$this->assertStringContainsString("phpGWLink('property/workorder/' + workorder_id + '/receive-order'", $contents);

			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_vendor_contract'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_eco_service'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_ecodimb'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_b_account'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.boworkorder.get_category'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_unspsc_code'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.receive_order'", $contents);
		}

		public function testWorkorderRelatedClientsUseRestDataEndpoints(): void
		{
			$addInvoice = (string)file_get_contents(__DIR__ . '/../../src/modules/property/js/base/workorder.add_invoice.js');
			$addDeviation = (string)file_get_contents(__DIR__ . '/../../src/modules/property/js/base/external_communication.add_deviation.js');
			$orderTemplate = (string)file_get_contents(__DIR__ . '/../../src/modules/property/js/base/order_template.edit.js');

			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/vendor-contract'", $addInvoice);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/category'", $addInvoice);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/vendor-contract'", $addDeviation);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/other-orders'", $addDeviation);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/vendor-contract'", $orderTemplate);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/category'", $orderTemplate);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/eco-service'", $orderTemplate);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/ecodimb'", $orderTemplate);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/b-account'", $orderTemplate);
			$this->assertStringContainsString("phpGWLink('property/project/lookups/external-project'", $orderTemplate);
			$this->assertStringContainsString("phpGWLink('property/workorder/lookups/unspsc-code'", $orderTemplate);

			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_vendor_contract'", $addInvoice);
			$this->assertStringNotContainsString("menuaction: 'property.boworkorder.get_category'", $addInvoice);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_vendor_contract'", $addDeviation);
			$this->assertStringNotContainsString("menuaction:'property.uiworkorder.get_other_orders'", $addDeviation);
			$this->assertStringNotContainsString("property.uitts.", $addDeviation);
			$this->assertStringNotContainsString("menuaction: 'property.boworkorder.get_category'", $addDeviation);
			$this->assertStringNotContainsString("menuaction: 'property.uiworkorder.get_vendor_contract'", $orderTemplate);
			$this->assertStringNotContainsString("menuaction: 'property.boworkorder.get_category'", $orderTemplate);
			$this->assertStringNotContainsString("property.uitts.", $orderTemplate);
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
			$this->assertStringContainsString('function enrichWorkorderRelationInfo(formData)', $contents);
			$this->assertStringContainsString("formData.set('RelationInfo[location_code]', locationCode);", $contents);
			$this->assertStringContainsString("var relationFields = ['tenant_id', 'p_num', 'p_entity_id', 'p_cat_id', 'origin', 'origin_id'];", $contents);
			$this->assertStringContainsString("'RelationInfo[' + field + ']';", $contents);
			$this->assertStringContainsString('enrichWorkorderRelationInfo(formData);', $contents);
			$this->assertStringContainsString("function submit_workorder_via_api_xhr", $contents);
			$this->assertStringContainsString("new XMLHttpRequest()", $contents);
			$this->assertStringNotContainsString("if (!form || !window.fetch)", $contents);
			$this->assertStringNotContainsString("if (!form || !window.fetch)\n\t{\n\t\tform.submit();", $contents);
		}

		public function testWorkorderCopyUsesCreateEndpointAndPostMethod(): void
		{
			$editPath = __DIR__ . '/../../src/modules/property/js/base/workorder.edit.js';
			$contents = (string)file_get_contents($editPath);

			$this->assertStringContainsString(
				"function isWorkorderCopyRequested(form)",
				$contents,
				'Workorder edit client should detect when copy_workorder is checked'
			);
			$this->assertStringContainsString(
				"method = 'POST';",
				$contents,
				'Workorder copy should force POST semantics to create a new workorder'
			);
			$this->assertStringContainsString(
				"url = phpGWLink('property/workorder/create', {});",
				$contents,
				'Workorder copy should force the create endpoint instead of updating the source workorder'
			);
			$this->assertStringContainsString(
				"formData.set('copy_workorder_from', String(order_id));",
				$contents,
				'Workorder copy should include the source workorder id for create-path budget fallback'
			);
		}

		public function testWorkorderRoutesExposeRestSaveEndpoints(): void
		{
			$routesPath = __DIR__ . '/../../src/modules/property/routes/Routes.php';
			$contents = (string)file_get_contents($routesPath);

			$this->assertStringContainsString('$group->post(\'/create\', [$controller, \'store\']);', $contents);
			$this->assertStringContainsString('$group->post(\'/{id:[0-9]+}\', [$controller, \'update\']);', $contents);
			$this->assertStringContainsString('$group->delete(\'/{id:[0-9]+}\', [$controller, \'destroy\']);', $contents);
			$this->assertStringContainsString('$group->get(\'/lookups/category\', [$controller, \'getCategory\']);', $contents);
			$this->assertStringContainsString('$group->post(\'/lookups/category\', [$controller, \'getCategory\']);', $contents);
			$this->assertStringContainsString('$group->get(\'/files/view\', [$controller, \'viewFile\']);', $contents);
			$this->assertStringContainsString('$group->get(\'/{id:[0-9]+}/files/image\', [$controller, \'viewImage\']);', $contents);
			$this->assertStringContainsString('$group->get(\'/{id:[0-9]+}/multi-upload\', [$controller, \'buildMultiUploadFile\']);', $contents);
			$this->assertStringContainsString('$group->map([\'POST\', \'PUT\', \'PATCH\', \'DELETE\', \'HEAD\', \'OPTIONS\'], \'/{id:[0-9]+}/multi-upload\', [$controller, \'handleMultiUploadFile\']);', $contents);
		}

		public function testWorkorderShellUsesRestFormActionUnconditionally(): void
		{
			$uiPath = __DIR__ . '/../../src/modules/property/inc/class.uiworkorder.inc.php';
			$contents = (string)file_get_contents($uiPath);

			$this->assertStringContainsString("phpgw::link('/property/workorder/create'", $contents);
			$this->assertStringContainsString('phpgw::link(\'/property/workorder/\' . (int)$id', $contents);
			$this->assertStringContainsString('phpgw::link(\'/property/workorder/\' . (int)$id . \'/multi-upload\')', $contents);
			$this->assertStringNotContainsString('workorder_rest_save_form_action', $contents);
			$this->assertStringNotContainsString("'property.uiworkorder.save'", $contents);
		}
	}
}
