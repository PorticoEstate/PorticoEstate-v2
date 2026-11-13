<?php

namespace
{
	function sticky_action_bar_test_lang($message)
	{
		return $message;
	}
}

namespace Tests\Controllers
{
	use DOMDocument;
	use DOMXPath;
	use PHPUnit\Framework\TestCase;
	use XSLTProcessor;

	class StickyActionBarTest extends TestCase
	{
		private function renderBar(string $module, string $template, string $data): DOMXPath
		{
			$source = file_get_contents(__DIR__ . '/../../src/modules/' . $module . '/templates/base/' . $template . '.xsl');
			$this->assertIsString($source);
			$source = str_replace("php:function('lang',", "php:function('sticky_action_bar_test_lang',", $source);

			$fragment = new DOMDocument();
			$this->assertTrue($fragment->loadXML(
				'<!DOCTYPE root [<!ENTITY nbsp "&#160;">]><root xmlns:xsl="http://www.w3.org/1999/XSL/Transform">' . $source . '</root>'
			));
			$bar = (new DOMXPath($fragment))->query('//*[@id="floating-box"]')->item(0);
			$this->assertNotNull($bar);

			$stylesheet = new DOMDocument();
			$variables = $template === 'workorder'
				? '<xsl:variable name="lang_save">save</xsl:variable><xsl:variable name="lang_done">done</xsl:variable>'
				: '';
			$this->assertTrue($stylesheet->loadXML(
				'<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:php="http://php.net/xsl">'
				. '<xsl:output method="html"/><xsl:template match="/*">'
				. $variables
				. $fragment->saveXML($bar)
				. '</xsl:template></xsl:stylesheet>'
			));
			$input = new DOMDocument();
			$this->assertTrue($input->loadXML($data));
			$processor = new XSLTProcessor();
			$processor->registerPHPFunctions('sticky_action_bar_test_lang');
			$this->assertTrue($processor->importStylesheet($stylesheet));
			$html = $processor->transformToXML($input);
			$this->assertIsString($html);

			$output = new DOMDocument();
			$this->assertTrue($output->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING));
			return new DOMXPath($output);
		}

		public function testWorkorderBarRespectsEditAndLeanModes(): void
		{
			foreach (array(
				array('edit', 0, 123, false, array('save', 'done', 'calculate', 'send')),
				array('edit', 0, 0, false, array('save', 'done')),
				array('edit', 1, 123, true, array()),
				array('view', 0, 123, true, array()),
			) as list($mode, $lean, $id, $hidden, $names))
			{
				$xpath = $this->renderBar('property', 'workorder', "<edit><mode>{$mode}</mode><lean>{$lean}</lean><value_workorder_id>{$id}</value_workorder_id></edit>");
				$box = $xpath->query('//*[@id="submitbox"]')->item(0);
				$this->assertSame($hidden, $box->getAttribute('style') === 'display: none');
				$actualNames = array();
				foreach ($xpath->query('//*[@id="submitbox"]//input[@type="button"]') as $button)
				{
					$actualNames[] = $button->getAttribute('name');
				}
				$this->assertSame($names, $actualNames);
			}
		}

		public function testTicketBarRespectsOrderAccessAndContainsAllActions(): void
		{
			foreach (array(
				array(1, '123', false),
				array(1, '', true),
				array(0, '123', true),
			) as list($access, $order, $hidden))
			{
				$xpath = $this->renderBar('property', 'tts', "<view><access_order>{$access}</access_order><value_order_id>{$order}</value_order_id></view>");
				$box = $xpath->query('//*[@id="submitbox"]')->item(0);
				$this->assertSame($hidden, $box->getAttribute('style') === 'display: none');
				$this->assertCount(1, $xpath->query('//*[@id="submitbox"]//td/input[@name="apply"]'));
				$this->assertCount($hidden ? 0 : 1, $xpath->query('//*[@id="submitbox"]//input[@id="send_order_button"]'));
			}
		}

		public function testOtherBarsRetainTheirActionControls(): void
		{
			foreach (array(
				array('eventplanner', 'application'),
				array('eventplanner', 'booking'),
				array('eventplannerfrontend', 'booking'),
				array('addressbook', 'persons'),
				array('addressbook', 'organizations'),
				array('addressbook', 'categorize'),
			) as list($module, $template))
			{
				$xpath = $this->renderBar($module, $template, '<edit><cancel_url>/list</cancel_url></edit>');
				$this->assertCount(1, $xpath->query('//*[@id="floating-box"]//input[@id="save_button" and @name="save" and @onclick="validate_submit();"]'));
				$this->assertCount(1, $xpath->query('//*[@id="floating-box"]//input[@name="done"]'));
			}
		}
	}
}
