<?php

namespace App\modules\phpgwapi\services;

/**
 * Accumulates CSS/JS assets during Twig rendering so they can be
 * output in the correct location (<head> / end of <body>) by the layout.
 *
 * Twig renders the parent layout top-to-bottom, so render_*() may execute
 * before the content block that calls add_*(). To handle this, render_*()
 * outputs placeholder markers. After Twig finishes, call resolvePlaceholders()
 * to replace them with the actual collected assets.
 */
class AssetService
{
	private const STYLES_PLACEHOLDER = '<!-- __ASSET_STYLES__ -->';
	private const SCRIPTS_PLACEHOLDER = '<!-- __ASSET_SCRIPTS__ -->';

	private static ?self $instance = null;

	/** @var array<string, string> url|hash => html */
	private array $styles = [];
	/** @var array<string, string> url|hash => html */
	private array $scripts = [];
	/** @var array<string, true> dedup set */
	private array $registered = [];

	private function __construct() {}

	public static function getInstance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function addStyle(string $url): string
	{
		if (!isset($this->registered[$url])) {
			$this->registered[$url] = true;
			$this->styles[$url] = '<link href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet">';
		}
		return '';
	}

	public function addInlineStyle(string $content): string
	{
		$key = 'inline_css_' . md5($content);
		if (!isset($this->registered[$key])) {
			$this->registered[$key] = true;
			$this->styles[$key] = '<style>' . $content . '</style>';
		}
		return '';
	}

	public function addScript(string $url): string
	{
		if (!isset($this->registered[$url])) {
			$this->registered[$url] = true;
			$this->scripts[$url] = '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
		}
		return '';
	}

	public function addInlineScript(string $content): string
	{
		$key = 'inline_js_' . md5($content);
		if (!isset($this->registered[$key])) {
			$this->registered[$key] = true;
			$this->scripts[$key] = '<script>' . $content . '</script>';
		}
		return '';
	}

	/**
	 * Return a placeholder that will be replaced after rendering completes.
	 */
	public function renderStyles(): string
	{
		return self::STYLES_PLACEHOLDER;
	}

	/**
	 * Return a placeholder that will be replaced after rendering completes.
	 */
	public function renderScripts(): string
	{
		return self::SCRIPTS_PLACEHOLDER;
	}

	/**
	 * Replace placeholders in the rendered HTML with actual collected assets.
	 * Call this after Twig::render() completes.
	 */
	public function resolvePlaceholders(string $html): string
	{
		$html = str_replace(self::STYLES_PLACEHOLDER, implode("\n", $this->styles), $html);
		$html = str_replace(self::SCRIPTS_PLACEHOLDER, implode("\n", $this->scripts), $html);
		return $html;
	}

	public function clear(): void
	{
		$this->styles = [];
		$this->scripts = [];
		$this->registered = [];
	}
}
