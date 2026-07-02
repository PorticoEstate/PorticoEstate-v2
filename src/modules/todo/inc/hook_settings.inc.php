<?php

/**
 * Todo settings hook
 *
 * @package todo
 */

use App\modules\preferences\helpers\PreferenceHelper;

$preferenceHelper = PreferenceHelper::getInstance();

$preferenceHelper->create_check_box(
	'Show ToDo items on main screen',
	'mainscreen_showevents',
	'Display your todo items on the main screen'
);
