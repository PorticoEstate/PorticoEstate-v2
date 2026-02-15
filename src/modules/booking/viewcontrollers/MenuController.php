<?php

namespace App\modules\booking\viewcontrollers;

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;

/**
 * Overrides menu URLs when the digdir template is active, pointing
 * migrated pages to new Slim4 routes instead of legacy ?menuaction= URLs.
 *
 * Other templates (bootstrap, portico) keep the original menu unchanged.
 */
class MenuController
{
	/**
	 * Apply digdir-specific menu overrides.
	 * Returns the menu unmodified for other templates.
	 */
	public static function applyOverrides(array $menus): array
	{
		$serverSettings = Settings::getInstance()->get('server');
		$templateSet = $serverSettings['template_set'] ?? 'digdir';

		if ($templateSet !== 'digdir') {
			return $menus;
		}

		foreach (self::getReplacements() as $path => $overrides) {
			self::replaceAtPath($menus, $path, $overrides);
		}

		foreach (self::getAppends() as $path => $items) {
			self::appendAtPath($menus, $path, $items);
		}

		foreach (self::getRemovals() as $path) {
			self::removeAtPath($menus, $path);
		}

		return $menus;
	}

	/**
	 * Dot-path → partial item array. Shallow-merged into the existing item,
	 * so only the specified keys are overridden (text, icon etc. are kept).
	 */
	private static function getReplacements(): array
	{
		return [
			'navigation.buildings.children.documents' => [
				'url' => \phpgw::link('/booking/view/buildings/documents'),
			],
		];
	}

	/**
	 * Dot-path → array of new items to append at that location.
	 */
	private static function getAppends(): array
	{
		$appends = [];

		$acl = Acl::getInstance();
		if ($acl->check('run', Acl::READ, 'admin') || $acl->check('admin', Acl::ADD, 'booking')) {
//			$appends['admin'] = [
//				'highlighted_buildings' => [
//					'text' => lang('booking.highlighted_buildings'),
//					'url' => \phpgw::link('/booking/view/config/highlighted-buildings'),
//				],
//			];
		}

		return $appends;
	}

	/**
	 * Dot-paths of items to remove entirely.
	 */
	private static function getRemovals(): array
	{
		return [];
	}

	// ------------------------------------------------------------------
	// Path-based merge utilities
	// ------------------------------------------------------------------

	/**
	 * Navigate to dot-path in $menu and shallow-merge $overrides into
	 * the item found there.
	 */
	private static function replaceAtPath(array &$menu, string $dotPath, array $overrides): void
	{
		$ref = &self::resolve($menu, $dotPath);
		if ($ref === null) {
			return;
		}
		$ref = array_merge($ref, $overrides);
	}

	/**
	 * Navigate to dot-path and append $items as additional children.
	 */
	private static function appendAtPath(array &$menu, string $dotPath, array $items): void
	{
		$ref = &self::resolve($menu, $dotPath);
		if ($ref === null) {
			return;
		}
		foreach ($items as $key => $item) {
			$ref[$key] = $item;
		}
	}

	/**
	 * Remove the item at dot-path from $menu.
	 */
	private static function removeAtPath(array &$menu, string $dotPath): void
	{
		$segments = explode('.', $dotPath);
		$last = array_pop($segments);
		$parentPath = implode('.', $segments);

		if (empty($segments)) {
			unset($menu[$last]);
			return;
		}

		$ref = &self::resolve($menu, $parentPath);
		if ($ref === null) {
			return;
		}
		unset($ref[$last]);
	}

	/**
	 * Walk the menu array following dot-separated segments.
	 * Returns a reference to the target, or null if not found.
	 */
	private static function &resolve(array &$menu, string $dotPath)
	{
		$segments = explode('.', $dotPath);
		$current = &$menu;

		foreach ($segments as $segment) {
			if (!is_array($current) || !array_key_exists($segment, $current)) {
				$null = null;
				return $null;
			}
			$current = &$current[$segment];
		}

		return $current;
	}
}
