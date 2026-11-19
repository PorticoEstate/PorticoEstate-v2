<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;

final class BoeiImportViewTest extends TestCase
{
	public function testEditableAndImportedCandidatesHaveSafeIndependentForms(): void
	{
		$source = new DOMDocument();
		$source->loadXML('<data><boei><loc1>5000</loc1><token>test-token</token>
			<action_url>/portico/?menuaction=property.uianalyze_location.boei&amp;loc1=5000</action_url>
			<labels><title>BOEI candidates</title><building>Building</building></labels>
			<objects><objekt_id>5000</objekt_id><status>approved</status><navn>Test</navn></objects>
			<units><objekt_id>5000</objekt_id><leie_id>001</leie_id><status>pending</status><adf>&lt;test&gt;</adf>
				<source_street_name>Source &amp; road</source_street_name><source_address>10A</source_address><source_street_id>999</source_street_id></units>
			<units><objekt_id>5000</objekt_id><leie_id>002</leie_id><status>imported</status></units>
			</boei></data>');
		$stylesheet = new DOMDocument();
		$stylesheet->loadXML('<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">'
			. file_get_contents(__DIR__ . '/../../src/modules/property/templates/base/boei_import.xsl')
			. '</xsl:stylesheet>');
		$processor = new XSLTProcessor();
		$processor->importStylesheet($stylesheet);
		$html = $processor->transformToXML($source);
		$output = new DOMDocument();
		@$output->loadHTML($html);
		$xpath = new DOMXPath($output);
		$unit = '//form[input[@name="leie_id" and @value="001"]]';
		$this->assertSame(1, $xpath->query($unit . '/input[@name="token" and @value="test-token"]')->length);
		$this->assertSame(1, $xpath->query($unit . '//button[@value="approve_unit"]')->length);
		$this->assertSame('<test>', $xpath->evaluate('string(' . $unit . '//input[@name="adf"]/@value)'));
		$this->assertSame(': Source & road 10A (ID: 999)', $xpath->evaluate('string((//p[@class="boei-source-address"])[1])'));
		$this->assertSame(1, $xpath->query('//form[input[@name="leie_id" and @value="002"]]/fieldset[@disabled]')->length);
		$this->assertSame(0, $xpath->query('//form//form')->length);
		$this->assertSame(1, $xpath->query('//input[@name="confirm_import" and @required]')->length);
		foreach ($xpath->query('//form[@method="post"]') as $form)
		{
			$this->assertSame('/portico/?menuaction=property.uianalyze_location.boei&loc1=5000', $form->getAttribute('action'));
		}
	}
}
