<?php

namespace Tests\Controllers;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use XSLTProcessor;

class InspectionModalTemplateTest extends TestCase
{
	public function testInspectionFieldsHaveAssociatedLabelsForEveryControlType(): void
	{
		$fragment = file_get_contents(__DIR__ . '/../../src/modules/controller/templates/base/case/add_case.xsl');
		$this->assertIsString($fragment);
		$fragment = preg_replace('/php:function\([^)]*\)/', "''", $fragment);
		$this->assertIsString($fragment);

		$stylesheet = new DOMDocument();
		$this->assertTrue($stylesheet->loadXML(
			'<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:php="http://php.net/xsl">'
			. $fragment
			. '<xsl:template name="check_list_top_section"/><xsl:template name="check_list_change_status"/>'
			. '</xsl:stylesheet>'
		));
		$data = new DOMDocument();
		$items = '';
		for ($type = 1; $type <= 5; $type++)
		{
			$items .= "<control_items><id>{$type}</id><title>Item {$type}</title>"
				. "<type>control_item_type_{$type}</type><include_condition_degree>1</include_condition_degree>"
				. '<include_counter_measure>1</include_counter_measure><include_regulation_reference>1</include_regulation_reference>'
				. '<options_array><option_value>Option</option_value></options_array></control_items>';
		}
		$this->assertTrue($data->loadXML(
			'<data><control_groups_with_items_array><control_group><id>42</id><group_name>Group</group_name></control_group>'
			. $items . '</control_groups_with_items_array></data>'
		));

		$processor = new XSLTProcessor();
		$this->assertTrue($processor->importStylesheet($stylesheet));
		$html = $processor->transformToXML($data);
		$this->assertIsString($html);
		$output = new DOMDocument();
		$this->assertTrue($output->loadXML($html));
		$xpath = new DOMXPath($output);

		$forms = $xpath->query('//form[contains(concat(" ", normalize-space(@class), " "), " frm_register_case ")]');
		$this->assertCount(5, $forms);
		foreach ($forms as $form)
		{
			$fields = $xpath->query('.//select | .//textarea | .//input[@type="text" or @type="checkbox"]', $form);
			foreach ($fields as $field)
			{
				$id = $field->getAttribute('id');
				$labels = $id !== '' ? $xpath->query('.//label[@for="' . $id . '"]', $form)->length : 0;
				$this->assertTrue($labels > 0 || $xpath->query('ancestor::label', $field)->length > 0, $field->getAttribute('name'));
			}
		}
	}
}
