<?php

namespace App\modules\phpgwapi\helpers;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;
use Twig\TwigFilter;

/**
 * Lightweight Twig helper for rendering email templates.
 *
 * No AssetService, no DesignSystem, no web/HTTP dependencies.
 * Registers only the functions needed for email rendering:
 * - lang() for translations
 * - format_nok filter for Norwegian currency formatting
 * - safe_html filter for trusted admin-configured text
 */
class EmailTwigHelper
{
	private Environment $twig;
	private FilesystemLoader $loader;

	public function __construct(string $appName = 'booking')
	{
		$this->loader = new FilesystemLoader();

		// Register app email templates path
		$appHtmlDir = PHPGW_SERVER_ROOT . '/' . $appName . '/html';
		if (is_dir($appHtmlDir)) {
			$this->loader->addPath($appHtmlDir, 'views');
		}

		$this->twig = new Environment($this->loader, [
			'cache' => false,
			'debug' => false,
			'auto_reload' => true,
			'strict_variables' => false,
		]);

		$this->registerFunctions();
		$this->registerFilters();
	}

	private function registerFunctions(): void
	{
		$this->twig->addFunction(new TwigFunction('lang', function (string $text, ...$args) {
			return lang($text, ...$args);
		}));
	}

	private function registerFilters(): void
	{
		$this->twig->addFilter(new TwigFilter('format_nok', function ($amount) {
			return 'kr ' . number_format((float)$amount, 2, ',', '.');
		}));

		$this->twig->addFilter(new TwigFilter('safe_html', function ($text) {
			return $text;
		}, ['is_safe' => ['html']]));
	}

	/**
	 * Render a Twig template and return the HTML string.
	 */
	public function render(string $template, array $data = []): string
	{
		return $this->twig->render($template, $data);
	}
}
