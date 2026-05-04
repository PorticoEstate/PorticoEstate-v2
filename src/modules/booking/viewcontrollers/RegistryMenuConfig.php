<?php

namespace App\modules\booking\viewcontrollers;

/**
 * Single source of truth for registry type → menu/navigation mappings.
 *
 * Used by:
 *  - MenuController::applyOverrides()  — to rewrite legacy sidebar URLs to modern routes
 *  - RegistryViewController            — to set breadcrumb heading + sidebar selection
 *
 * Each entry maps a registry type key to:
 *  - menu_path       Dot-path into the sidebar menu tree (for MenuController URL override)
 *  - menu_selection  The :: delimited path used for sidebar highlighting
 *  - text_key        Lang key for the translated display name (Norwegian sidebar label)
 */
class RegistryMenuConfig
{
	/**
	 * @return array<string, array{menu_path: string, menu_selection: string, text_key: string}>
	 */
	public static function getEntries(): array
	{
		return [
			// -- Settings --
			'e_lock_system' => [
				'menu_path' => 'navigation.settings.children.e_lock_system',
				'menu_selection' => 'booking::settings::e_lock_system',
				'text_key' => 'e_lock_system',
			],
			'office' => [
				'menu_path' => 'navigation.settings.children.office.children.office',
				'menu_selection' => 'booking::settings::office::office',
				'text_key' => 'office',
			],
			'office_user' => [
				'menu_path' => 'navigation.settings.children.office.children.office_user',
				'menu_selection' => 'booking::settings::office::office_user',
				'text_key' => 'office user',
			],

			// -- Commerce --
			'article_group' => [
				'menu_path' => 'navigation.commerce.children.article_group',
				'menu_selection' => 'booking::commerce::article_group',
				'text_key' => 'article group',
			],
			'tax' => [
				'menu_path' => 'navigation.commerce.children.accounting_tax',
				'menu_selection' => 'booking::commerce::accounting_tax',
				'text_key' => 'tax code',
			],
		];
	}

	/**
	 * Lookup a single registry type. Returns null if not mapped.
	 */
	public static function get(string $type): ?array
	{
		return self::getEntries()[$type] ?? null;
	}

	/**
	 * Build the modern URL for a registry type.
	 */
	public static function url(string $type): string
	{
		return '/booking/view/registry/' . $type;
	}
}
