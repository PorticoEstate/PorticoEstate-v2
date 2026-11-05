<?php

namespace Tests\Controllers;

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class InformationScreenTemplateTest extends TestCase
{
	/** @dataProvider languages */
	public function testRendersStandalonePageWithTranslatedLabelsAndEscapedApiUrls(string $language): void
	{
		$module = __DIR__ . '/../../src/modules/bookingfrontend';
		$catalog = [];
		foreach (file($module . '/setup/phpgw_' . $language . '.lang', FILE_IGNORE_NEW_LINES) as $line)
		{
			if (trim($line) === '') continue;
			$fields = explode("\t", $line);
			$this->assertCount(4, $fields);
			$catalog[strtolower(trim($fields[0]))] = $fields[3];
		}
		$catalog['today'] = $language === 'en' ? 'Today' : 'I dag';
		$loader = new FilesystemLoader();
		$loader->addPath($module . '/html/base', 'views');
		$twig = new Environment($loader, ['strict_variables' => true]);
		$twig->addFunction(new TwigFunction('lang', function (string $key) use ($catalog): string
		{
			$key = strtolower($key);
			$this->assertArrayHasKey($key, $catalog);
			return $catalog[$key];
		}));

		$html = $twig->render('@views/information_screen/bookingfrontend_information_screen.twig', [
			'language' => $language,
			'building_search_url' => '/bookingfrontend/buildings/search',
			'resource_list_url' => '/bookingfrontend/buildings/__BUILDING_ID__/resources?results=-1&sort=name&short=1',
			'information_screen_url' => '/bookingfrontend/?domain=test&sessionid=temporary',
		]);

		$this->assertStringContainsString('<!DOCTYPE html>', $html);
		$this->assertStringContainsString('<html lang="' . $language . '">', $html);
		$this->assertStringContainsString($catalog['info skjerm - ressursvelger'], $html);
		$this->assertStringContainsString('results=-1&amp;sort=name&amp;short=1', $html);
		$this->assertStringContainsString('id="information-screen-resource-list"', $html);
		$this->assertStringContainsString('id="information-screen-copy-link"', $html);
		$this->assertStringNotContainsString('xsl:', $html);
	}

	public static function languages(): array
	{
		return [['no'], ['nn'], ['en']];
	}
}
