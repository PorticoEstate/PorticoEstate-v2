<?php

namespace App\modules\bookingfrontend\viewcontrollers;

use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;

class MenuController
{
	public static function applyOverrides(array $menus): array
	{
		$serverSettings = Settings::getInstance()->get('server');
		$templateSet = $serverSettings['template_set'] ?? 'digdir';

		if ($templateSet !== 'digdir') {
			return $menus;
		}

		foreach (self::getAppends() as $path => $items) {
			$ref = &self::resolve($menus, $path);
			if ($ref === null) {
				continue;
			}
			foreach ($items as $key => $item) {
				$ref[$key] = $item;
			}
		}

		return $menus;
	}

	private static function getAppends(): array
	{
		$appends = [];

		$acl = Acl::getInstance();
		if ($acl->check('run', Acl::READ, 'admin') || $acl->check('admin', Acl::ADD, 'bookingfrontend')) {
			$appends['admin'] = [
				'highlighted_buildings' => [
					'text' => lang('bookingfrontend.highlighted_buildings'),
					'url' => \phpgw::link('/booking/view/config/highlighted-buildings'),
				],
			];
		}

		return $appends;
	}

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
