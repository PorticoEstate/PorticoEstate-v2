<?php

namespace Tests\Controllers
{
	require_once __DIR__ . '/../../vendor/autoload.php';

	use PHPUnit\Framework\TestCase;

	class EntityClientBoundaryTest extends TestCase
	{
		public function testEntityEditUsesRestSaveEndpoints(): void
		{
			$path = __DIR__ . '/../../src/modules/property/js/base/entity.edit.js';
			$contents = (string)file_get_contents($path);

			$this->assertStringContainsString('function createEntityApiClient(form)', $contents);
			$this->assertStringContainsString("var url = '/property/entity/' + encodeURIComponent(type) + '/' + entityId + '/' + catId;", $contents);
			$this->assertStringContainsString("method: isCreate ? 'POST' : 'PUT'", $contents);
			$this->assertStringContainsString('fetch(restRequest.url, fetchOptions)', $contents);
			$this->assertStringContainsString('appendRelationInfoToFormData(formData, form);', $contents);

			$this->assertStringNotContainsString("menuaction: 'property.uientity.save'", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.boentity.save'", $contents);
		}

		public function testEntityNavigationStillUsesLegacyMenuaction(): void
		{
			$path = __DIR__ . '/../../src/modules/property/js/base/entity.edit.js';
			$contents = (string)file_get_contents($path);

			$this->assertStringContainsString("menuaction: 'property.uientity.edit'", $contents);
			$this->assertStringContainsString("menuaction: 'property.uientity.index'", $contents);
		}

		public function testEntityInventoryPopupLinksUseRestRoutes(): void
		{
			$path = __DIR__ . '/../../src/modules/property/js/base/entity.edit.js';
			$contents = (string)file_get_contents($path);

			$this->assertStringContainsString("+ '/inventory/add?location_id=' + encodeURIComponent(location_id);", $contents);
			$this->assertStringContainsString("+ '/inventory/' + encodeURIComponent(inventory_id)", $contents);
			$this->assertStringContainsString("+ '/edit?location_id=' + encodeURIComponent(location_id);", $contents);
			$this->assertStringContainsString("+ '/inventory/' + encodeURIComponent(inventory_id)", $contents);
			$this->assertStringContainsString("+ '/calendar?location_id=' + encodeURIComponent(location_id);", $contents);
		}

		public function testEntitySummaryUsesServerProvidedRestItemsPerQrEndpoint(): void
		{
			$path = __DIR__ . '/../../src/modules/property/js/base/entity.summary.js';
			$contents = (string)file_get_contents($path);

			$this->assertStringContainsString('/* global get_items_per_qr_url */', $contents);
			$this->assertStringContainsString("var qr_code_infoURL = get_items_per_qr_url + '?qr_code=' + encodeURIComponent(qr_code);", $contents);
			$this->assertStringNotContainsString("menuaction: 'property.uientity.get_items_per_qr'", $contents);
		}

		public function testEntityRoutesExposeRestSaveEndpoints(): void
		{
			$routesPath = __DIR__ . '/../../src/modules/property/routes/Routes.php';
			$contents = (string)file_get_contents($routesPath);

			$this->assertStringContainsString("\$g->post('/create',        [\$controller, 'store']);", $contents);
			$this->assertStringContainsString("\$g->put('/{id:[0-9]+}',    [\$controller, 'update']);", $contents);
		}

		public function testEntityShellUsesRestFormAction(): void
		{
			$uiPath = __DIR__ . '/../../src/modules/property/inc/class.uientity.inc.php';
			$contents = (string)file_get_contents($uiPath);

			$this->assertStringContainsString("rest-client-utils.js", $contents);
			$this->assertStringContainsString("'form_action' => '/property/entity/' . urlencode(\$this->type)", $contents);
			$this->assertStringNotContainsString("'menuaction' => 'property.uientity.save'", $contents);
		}
	}
}
