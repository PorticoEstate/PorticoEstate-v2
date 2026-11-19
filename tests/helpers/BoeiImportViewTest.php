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
		$xpath = $this->render($source);
		$this->assertSame(0, $xpath->query('//progress')->length);
		$unit = '//form[input[@name="leie_id" and @value="001"]]';
		$this->assertSame(1, $xpath->query($unit . '/input[@name="token" and @value="test-token"]')->length);
		$this->assertSame(1, $xpath->query($unit . '//button[@value="approve_unit"]')->length);
		$this->assertSame(0, $xpath->query($unit . '//input[@name="street_id"]')->length);
		$this->assertSame(1, $xpath->query($unit . '//input[@name="propagate" and @value="1" and not(@checked)]')->length);
		$this->assertSame(1, $xpath->query('//input[@name="propagate"]')->length);
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

	public function testOverviewShowsCompletionAndStatusCountsIncludingEmptyObjects(): void
	{
		$source = new DOMDocument();
		$source->loadXML('<data><boei><loc1/>
			<labels><completion>Completion</completion><pending>Pending</pending><approved>Approved</approved><imported>Imported</imported></labels>
			<objects><objekt_id>5000</objekt_id><total_units>4</total_units><completed_units>3</completed_units>
				<completion_percent>75</completion_percent><pending_units>1</pending_units><approved_units>2</approved_units><imported_units>1</imported_units></objects>
			<objects><objekt_id>6000</objekt_id><total_units>0</total_units><completed_units>0</completed_units>
				<completion_percent>0</completion_percent><pending_units>0</pending_units><approved_units>0</approved_units><imported_units>0</imported_units></objects>
			</boei></data>');
		$xpath = $this->render($source);
		$this->assertSame('Completion: 3 / 4 (75%)', $xpath->evaluate('normalize-space((//p[@class="boei-completion"])[1])'));
		$this->assertSame('Pending: 1 / Approved: 2 / Imported: 1', $xpath->evaluate('normalize-space((//p[@class="boei-completion-status"])[1])'));
		$this->assertSame('Completion: 0 / 0', $xpath->evaluate('normalize-space((//p[@class="boei-completion"])[2])'));
		$this->assertSame(1, $xpath->query('//progress[@value="3" and @max="4" and @aria-label="Completion"]')->length);
		$this->assertSame(1, $xpath->query('//progress')->length);
	}

	private function render(DOMDocument $source): DOMXPath
	{
		$stylesheet = new DOMDocument();
		$stylesheet->loadXML('<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">'
			. file_get_contents(__DIR__ . '/../../src/modules/property/templates/base/boei_import.xsl')
			. '</xsl:stylesheet>');
		$processor = new XSLTProcessor();
		$processor->importStylesheet($stylesheet);
		$html = $processor->transformToXML($source);
		$output = new DOMDocument();
		@$output->loadHTML($html);
		return new DOMXPath($output);
	}
}
