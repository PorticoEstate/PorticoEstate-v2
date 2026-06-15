<?php
	/**************************************************************************\
	* phpGroupWare - Admin                                                     *
	* http://www.phpgroupware.org                                              *
	* This application written by Miles Lott <milosch@phpgroupware.org>        *
	* --------------------------------------------                             *
	*  This program is free software; you can redistribute it and/or modify it *
	*  under the terms of the GNU General Public License as published by the   *
	*  Free Software Foundation; either version 2 of the License, or (at your  *
	*  option) any later version.                                              *
	\**************************************************************************/

	/* $Id$ */

	use App\modules\phpgwapi\services\Settings;
	use App\Database\Db;
	/* Check currentapp and API upgrade status */

	$flags = Settings::getInstance()->get('flags');
	$userSettings = Settings::getInstance()->get('user');
	$serverSettings = Settings::getInstance()->get('server');
	$db = Db::getInstance();
	$phpgwapi_common = new phpgwapi_common();

	if ($flags['currentapp'] != 'home'
		&& $flags['currentapp'] != 'welcome'
		&& $flags['currentapp'] != 'about'
//		&& (isset($serverSettings['checkappversions']) &&	$serverSettings['checkappversions'])
		)
	{
		if ((isset($userSettings['apps']['admin']) &&
			$userSettings['apps']['admin']) ||
			$serverSettings['checkappversions'] == 'All')
		{
			$require_upgrade = false;
			$_current = array();
			$app_name = $flags['currentapp'];
			$db->query("SELECT app_name,app_version FROM phpgw_applications WHERE app_name='$app_name' OR app_name='phpgwapi'",__LINE__,__FILE__);
			$app_info = array();
			while($db->next_record())
			{
				$app_info[] = array
				(
					'db_version'	=> $db->f('app_version'),
					'app_name'		=> $db->f('app_name')
				); 
			}
			
			$migrationService = null;
			foreach($app_info as $app)
			{
				$_db_version  = $app['db_version'];
				$app_name	  = $app['app_name'];

				/*
				 * Migration-based modules track their version as a migration count,
				 * not a legacy x.y.z string, so cmp_version_long() against
				 * setup.inc.php's version would always report a mismatch. For these,
				 * "current" means there are no pending migrations.
				 */
				$migrationService = $migrationService ?? new \App\modules\phpgwapi\services\Migration\MigrationService();
				if($migrationService->moduleHasMigrations($app_name))
				{
					if(!$migrationService->hasPendingMigrations($app_name))
					{
						$_current[$app_name] = True;
					}
					continue;
				}

				$_versionfile = $phpgwapi_common->get_app_dir($app_name) . '/setup/setup.inc.php';
				if(file_exists($_versionfile))
				{
					include($_versionfile);
					/* echo '<br>' . $_versionfile . ','; */
					$_file_version = $setup_info[$app_name]['version'];
					$_app_title    = str_replace('- ','-',ucwords(str_replace('_','- ',$setup_info[$app_name]['name'])));
					unset($setup_info);

					/* echo '<br>' . $app_name . ',' . $_db_version . ',' . $_file_version; */
					$test = $phpgwapi_common->cmp_version_long($_db_version,$_file_version);
					if($test == '')
					{
						$_current[$app_name] = True;
						if($app_name == 'phpgwapi')
						{
							$api_str = '<li>' . lang('The API is current') . ': OK</li>';
						}
					}
					else
					{
						if($app_name == 'phpgwapi')
						{
							$api_str = '<li>' . lang('The API requires an upgrade') . '</li>';
							$require_upgrade = true;
						}
					}
					unset($test);
					unset($_file_version);
					unset($_app_title);
				}
				unset($_db_version);
				unset($_versionfile);
			}
			if(!isset($_current[$flags['currentapp']]))
			{
				$app_str  = '<li>' . lang('This application requires an upgrade') . ": {$flags['currentapp']}</li>";
//				$app_str .= '<br>' . lang('Please run setup to become current') . '.' . "\n";
				$require_upgrade = true;
			}
			else
			{
	//			$app_str  =  '<br>' . lang('This application is current') . "\n";
			}
		
			if($require_upgrade)
			{
				echo '<div class="error"><ul>';
				echo $api_str;
				echo $app_str;
				echo '</ul></div>';
			}
		}
	}
	
/*
	if( Sanitizer::get_var('phpgw_return_as') != 'json' && $receipt = Cache::session_get('phpgwapi', 'phpgw_messages'))
	{
		Cache::session_clear('phpgwapi', 'phpgw_messages');
		$msgbox_data = $phpgwapi_common->msgbox_data($receipt);
		$msgbox_data = $phpgwapi_common->msgbox($msgbox_data);
		foreach($msgbox_data as & $message)
		{
			echo "<div class='{$message['msgbox_class']}'>";
			echo $message['msgbox_text'];
			echo '</div>';
		}
	}
*/
