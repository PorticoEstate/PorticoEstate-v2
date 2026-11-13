<?php

namespace Tests\Controllers;

use PHPUnit\Framework\TestCase;

class TemplateStylesheetOrderTest extends TestCase
{
	/** @dataProvider templateAndModule */
	public function testSharedStylesLoadBeforeTemplateOverrides(string $template, string $module, string $framework): void
	{
		$stylesheets = $this->loadStylesheets($template, $module, $framework);
		$prefix = '/portico/src/modules';
		$sharedApi = "{$prefix}/phpgwapi/templates/base/css/base.css";
		$templateApi = "{$prefix}/phpgwapi/templates/{$template}/css/base.css";
		$this->assertSame(1, count(array_keys($stylesheets, $sharedApi, true)));
		if ($module === 'frontend' && $template !== 'portico')
		{
			$this->assertNotContains($templateApi, $stylesheets);
		}
		else
		{
			$this->assertSame(1, count(array_keys($stylesheets, $templateApi, true)));
			$this->assertLessThan(array_search($templateApi, $stylesheets, true), array_search($sharedApi, $stylesheets, true));
		}

		if ($module !== 'phpgwapi')
		{
			$sharedModule = "{$prefix}/{$module}/templates/base/css/base.css";
			$templateModule = "{$prefix}/{$module}/templates/{$template}/css/base.css";
			if (is_file(__DIR__ . "/../../src/modules/{$module}/templates/base/css/base.css"))
			{
				$this->assertSame(1, count(array_keys($stylesheets, $sharedModule, true)));
				$this->assertLessThan(array_search($templateApi, $stylesheets, true), array_search($sharedModule, $stylesheets, true));
			}
			else
			{
				$this->assertNotContains($sharedModule, $stylesheets);
			}
			if (is_file(__DIR__ . "/../../src/modules/{$module}/templates/{$template}/css/base.css"))
			{
				$this->assertSame(1, count(array_keys($stylesheets, $templateModule, true)));
				if (in_array($sharedModule, $stylesheets, true))
				{
					$this->assertLessThan(array_search($templateModule, $stylesheets, true), array_search($sharedModule, $stylesheets, true));
				}
			}
			else
			{
				$this->assertNotContains($templateModule, $stylesheets);
			}
		}
	}

	/** @dataProvider multiselectTemplates */
	public function testBootstrapMultiselectDoesNotLoadGlobalBootstrapIntoModernShells(string $template, bool $loadsBootstrap): void
	{
		$process = proc_open(
			array(PHP_BINARY, __DIR__ . '/../fixtures/bootstrap-multiselect-assets.php', $template),
			array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')),
			$pipes
		);
		$this->assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $errors);
		$stylesheets = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
		$bootstrapCss = 'phpgwapi/js/bootstrap5/vendor/twbs/bootstrap/dist/css/bootstrap.min.css';
		$pluginCss = 'phpgwapi/js/bootstrap-multiselect/css/bootstrap-multiselect.min.css';

		$this->assertSame($loadsBootstrap, in_array($bootstrapCss, $stylesheets, true));
		$this->assertContains($pluginCss, $stylesheets);
	}

	public function testPorticoWidgetStylesPrecedeTemplateOverridesAndDataTablesIsNotDuplicated(): void
	{
		$headTemplate = file_get_contents(__DIR__ . '/../../src/modules/phpgwapi/templates/portico/head.tpl');
		$headLoader = file_get_contents(__DIR__ . '/../../src/modules/phpgwapi/templates/portico/head.inc.php');
		$widgetLoader = file_get_contents(__DIR__ . '/../../src/modules/phpgwapi/inc/class.uicommon_jquery.inc.php');

		$this->assertIsString($headTemplate);
		$this->assertIsString($headLoader);
		$this->assertIsString($widgetLoader);
		$this->assertLessThan(strpos($headTemplate, '<!-- BEGIN stylesheet -->'), strpos($headTemplate, '{css}'));
		$this->assertStringNotContainsString('DataTables3/vendor/datatables.net/datatables.net-dt/css/dataTables.dataTables.min.css', $headLoader);
		$this->assertStringContainsString('DataTables3/vendor/datatables.net/datatables.net-dt/css/dataTables.dataTables.min.css', $widgetLoader);
	}

	/** @dataProvider templatesWithTheme */
	public function testThemeFollowsTemplateBase(string $template, string $module): void
	{
		$stylesheets = $this->loadStylesheets($template, $module, 'framework', 'navbar_bootstrap');
		$base = "/portico/src/modules/phpgwapi/templates/{$template}/css/base.css";
		$theme = "/portico/src/modules/phpgwapi/templates/{$template}/css/navbar_bootstrap.css";
		$this->assertSame(1, count(array_keys($stylesheets, $theme, true)));
		$this->assertLessThan(array_search($theme, $stylesheets, true), array_search($base, $stylesheets, true));
	}

	/** @dataProvider templates */
	public function testMissingThemeStylesAreSkipped(string $template): void
	{
		$stylesheets = $this->loadStylesheets($template, 'property', 'framework', 'missing-theme');
		$this->assertNotContains("/portico/src/modules/phpgwapi/templates/{$template}/css/missing-theme.css", $stylesheets);
		$this->assertNotContains("/portico/src/modules/property/templates/{$template}/css/missing-theme.css", $stylesheets);
		$this->assertContains('/portico/src/modules/property/templates/base/css/base.css', $stylesheets);
	}

	private function loadStylesheets(string $template, string $module, string $framework, ?string $theme = null): array
	{
		$command = array(PHP_BINARY, __DIR__ . '/../fixtures/template-stylesheet-head.php', $template, $module, $framework);
		if ($theme !== null)
		{
			$command[] = $theme;
		}
		$process = proc_open(
			$command,
			array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')),
			$pipes
		);
		$this->assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $errors);
		return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	}

	public static function templateAndModule(): array
	{
		$cases = array();
		foreach (array('bootstrap', 'digdir', 'portico') as $template)
		{
			foreach (array('property', 'booking', 'phpgwapi', 'frontend') as $module)
			{
				foreach (array('framework', 'no-framework') as $framework)
				{
					$cases[] = array($template, $module, $framework);
				}
			}
		}
		return $cases;
	}

	public static function templates(): array
	{
		return array(array('bootstrap'), array('digdir'), array('portico'));
	}

	public static function templatesWithTheme(): array
	{
		return array(
			array('bootstrap', 'property'),
			array('bootstrap', 'phpgwapi'),
			array('digdir', 'property'),
			array('digdir', 'phpgwapi'),
		);
	}

	public static function multiselectTemplates(): array
	{
		return array(
			array('bootstrap', false),
			array('digdir', false),
			array('portico', false),
			array('simple', true),
		);
	}
}
