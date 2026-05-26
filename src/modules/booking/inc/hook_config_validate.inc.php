<?php

use App\modules\phpgwapi\services\Settings;

Settings::getInstance()->update('server', ['found_validation_hook' => true]);

function final_validation($value = '')
{
	$GLOBALS['config_error'] = '';

	// Bust menu caches so config-driven menu changes take effect immediately
	\phpgwapi_menu::clearAllMenuCaches();
}
