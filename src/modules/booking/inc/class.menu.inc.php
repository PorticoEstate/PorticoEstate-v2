<?php

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Translation;
use App\modules\booking\viewcontrollers\MenuController;

class booking_menu
{

	function get_menu()
	{
		$bodoc = CreateObject('booking.bodocumentation');
		$manual = $bodoc->so->getBackendDoc();
		$userSettings = Settings::getInstance()->get('user');
		$flags = Settings::getInstance()->get('flags');
		$location_obj = new Locations();
		$translation = Translation::getInstance();

		$incoming_app			 = $flags['currentapp'];
		Settings::getInstance()->update('flags', ['currentapp' => 'booking']);
		$acl					 =	Acl::getInstance();

		$menus = array();

		$menus['navbar'] = array(
			'booking' => array(
				'text' => lang('Booking'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uidashboard.index')),
				'image' => array('property', 'location'),
				'order' => 10,
				'group' => 'office'
			)
		);

		$menus['preferences'] = array(
			array(
				'text' => $translation->translate('Preferences', array(), true),
				'url'	 => phpgw::link('/preferences/section', array(
					'appname' => 'booking',
					'type' => 'user'
				))
			)
		);
		$menus['navigation'] = array(
			'dashboard' => array(
				'text' => lang('Dashboard'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uidashboard.index')),
				'image' => array('property', 'location'),
			),
			'messages' => array(
				'text' => lang('Messages'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uisystem_message.index')),
				'image' => array('property', 'location'),
			),
			// 'applications' => array
			// (
			// 	'text'	=> lang('Applications'),
			// 	'url'	=> phpgw::link('/index.php', array('menuaction'=> 'booking.uibooking.applications') ),
			//                     'image'	=> array('property', 'location'),
			// ),
			'applications' => array(
				'text' => lang('Applications'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiapplication.index')),
				'image' => array('property', 'project_request'),
				'children' => array(
					'applications' => array(
						'text' => lang('Applications'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiapplication.index')),
						'image' => array('property', 'project_request'),
						'icon' => 'fas fa-2x fa-file-alt'
					),
					'allocations' => array(
						'text' => lang('Allocations'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiallocation.index')),
						'image' => array('property', 'project_request'),
						'icon' => 'fas fa-2x fa-file-alt'
					),
					'bookings' => array(
						'text' => lang('Bookings'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uibooking.index')),
						'image' => array('property', 'project_request'),
						'icon' => 'fas fa-2x fa-file-alt'
					),
					'events' => array(
						'text' => lang('Events'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uievent.index')),
						'image' => array('property', 'project_request'),
						'icon' => 'fas fa-2x fa-file-alt'
					),
					'massbookings' => array(
						'text' => lang('Bookings and allocations'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uimassbooking.index')),
						'image' => array('property', 'project_request'),
					),
				)
			),
			'buildings' => array(
				'text' => lang('Buildings'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uibuilding.index')),
				'image' => array('property', 'location_1'),
				'icon' => 'fa fa-2x fa-building',
				'children' => array(
					'buildings' => array(
						'text' => lang('Buildings'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uibuilding.index')),
						'image' => array('property', 'location_1'),
						'icon' => 'fa fa-2x fa-building'
					),
					'documents' => array(
						'text' => lang('Documents'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uidocument_building.index')),
						'image' => array('property', 'documentation'),
						'icon' => 'fas fa-2x fa-file-alt'
					),
					'permissions' => array(
						'text' => lang('Permissions'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uipermission_building.index')),
						'image' => array('property', 'agreement'),
					),
					'resources' => array(
						'text' => lang('Resources'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiresource.index')),
						'image' => array('property', 'location'),
						'children' => array(
							'resources' => array(
								'text' => lang('Resources'),
								'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiresource.index')),
								'image' => array('property', 'location'),
							),
							'documents' => array(
								'text' => lang('Documents'),
								'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uidocument_resource.index')),
								'image' => array('property', 'documentation'),
								'icon' => 'fas fa-2x fa-file-alt'
							),
							'permissions' => array(
								'text' => lang('Permissions'),
								'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uipermission_resource.index')),
								'image' => array('property', 'agreement'),
							),
						)
					),
					'seasons' => array(
						'text' => lang('Seasons'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiseason.index')),
						'image' => array('property', 'location_gabnr'),
						'children' => array(
							'seasons' => array(
								'text' => lang('Seasons'),
								'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiseason.index')),
								'image' => array('property', 'location_gabnr'),
							),
							'permissions' => array(
								'text' => lang('Permissions'),
								'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uipermission_season.index')),
								'image' => array('property', 'agreement'),
							),
						),
					),
				)
			),
			// 'events' => array
			// (
			// 	'text'	=> lang('Events'),
			// 	'url'	=> phpgw::link('/index.php', array('menuaction'=> 'booking.uievent.index') ),
			//                     'image'	=> array('property', 'location'),
			// ),
			'organizations' => array(
				'text' => lang('Organizations'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiorganization.index')),
				'image' => array('property', 'location_tenant'),
				'children' => array(
					'organizations' => array(
						'text' => lang('Organizations'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiorganization.index')),
						'image' => array('property', 'location_tenant'),
						'icon' => 'fa fa-2x fa-sitemap'
					),
					'documents' => array(
						'text' => lang('Documents'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uidocument_organization.index')),
						'image' => array('property', 'documentation'),
					),
					'groups' => array(
						'text' => lang('Groups'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uigroup.index')),
						'image' => array('property', 'location_tenant'),
					),
					'delegates' => array(
						'text' => lang('delegates'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uidelegate.index')),
						'image' => array('property', 'location_tenant'),
					)
				)
			),
			'users' => array(
				'text' => lang('users'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiuser.index')),
				'image' => array('property', 'location_tenant'),
				'icon' => 'fas fa-2x fa-address-card',
				'children' => array(
					'collect_users' => array(
						'text' => lang('collect users'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiuser.collect_users')),
						'image' => array('property', 'location_tenant'),
					),
					'update_user_address' => array(
						'text' => lang('update user address'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiuser.update_user_address')),
						'image' => array('property', 'location_tenant'),
					),
					'export_customer' => array(
						'text' => lang('export customer'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiuser.export_customer')),
						'image' => array('property', 'location_tenant'),
					),
				)
			),
			'commerce' => array(
				'text'	=> lang('commerce'),
				'url'	=> phpgw::link('/index.php', array('menuaction' => 'booking.uiarticle_mapping.index')),
				'image'	=> array('property', 'article'),
				'children'	=> array(
					'article' => array(
						'text'	=> lang('article'),
						'url'	=> phpgw::link('/index.php', array('menuaction' => 'booking.uiarticle_mapping.index')),
						'image'	=> array('property', 'article'),
					),
					'service' => array(
						'text'	=> lang('service'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiservice.index')),
						'image'	=> array('property', 'service'),
					),
					'article_group'		 => array(
						'text'	 => lang('article group'),
						'url'	 => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uigeneric.index',
							'type'		 => 'article_group'
						))
					),
					'accounting_tax'		 => array(
						'text'	 => lang('tax code'),
						'url'	 => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uigeneric.index',
							'type'		 => 'tax'
						))
					),

				)
			),
			// 'costs' => array
			// (
			// 	'text'	=> lang('Costs'),
			// 	'url'	=> phpgw::link('/index.php', array('menuaction'=> 'booking.uicost.index') ),
			//                     'image'	=> array('property', 'location'),
			// )
			'invoice_center' => array(
				'text' => lang('Invoice Data Exports'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uicompleted_reservation.index')),
				'image' => array('property', 'invoice'),
				'children' => array(
					'completed_reservations' => array(
						'text' => lang('completed reservations'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uicompleted_reservation.index')),
						'image' => array('property', 'invoice'),
					),
					'exported_files' => array(
						'text' => lang('Exported Files'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uicompleted_reservation_export.index')),
						'image' => array('property', 'invoice'),
					),
					'generated_files' => array(
						'text' => lang('Generated Files'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uicompleted_reservation_export_file.index')),
						'image' => array('property', 'invoice'),
					)
				)
			),
			'mailing' => array(
				'text' => lang('Send e-mail'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uisend_email.index')),
				'image' => array('property', 'helpdesk'),
			),
			'reportcenter' => array(
				'text' => lang('Reports'),
				'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uireports.index')),
				'image' => array('property', 'report'),
				'children' => array(
					'reports' => array(
						'text' => lang('Reports'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uireports.index')),
						'image' => array('property', 'report'),
					),
					'participants' => array(
						'text' => lang('Participants'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uireports.participants')),
						'image' => array('property', 'report'),
					),
					'free_time' => array(
						'text' => lang('Free time'),
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uireports.freetime')),
						'image' => array('property', 'report'),
					),
					'add_generic' => array(
						'text' => 'TESTING::add_generic',
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uireports.add')),
						'image' => array('property', 'report'),
					)
				)
			),
		);
		if (isset($userSettings['apps']['admin']))
		{
			$menus['navigation']['settings'] = array(
				'text' => lang('Settings'),
				'url' => phpgw::link('/index.php', array(
					'menuaction' => 'booking.uipermission_root.index',
					'appname' => 'booking'
				)),
				'image' => array('admin', 'navbar'),
				'children' => array(
					'permissions' => array(
						'text' => lang('Root Permissions'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uipermission_root.index',
							'appname' => 'booking'
						))
					),
					'rescategory' => array(
						'text' => lang('Resource categories'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uirescategory.index',
							'appname' => 'booking'
						))
					),
					'activity' => array(
						'text' => lang('Activity'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uiactivity.index',
							'appname' => 'booking'
						))
					),
					'facility' => array(
						'text' => lang('Facilities'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uifacility.index',
							'appname' => 'booking'
						))
					),
					'custom_fields_example' => array(
						'text' => 'TEMPORARY:custom fields example',
						'url' => phpgw::link('/index.php', array('menuaction' => 'booking.uiapplication.custom_fields_example'))
					),
					'custom_field_groups' => array(
						'text' => lang('custom field groups'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'admin.ui_custom.list_attribute_group',
							'appname' => 'booking', 'menu_selection' => 'booking::settings::custom_field_groups'
						))
					),
					'custom_fields' => array(
						'text' => lang('custom fields'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'admin.ui_custom.list_attribute',
							'appname' => 'booking', 'menu_selection' => 'booking::settings::custom_fields'
						))
					),
					'audience' => array(
						'text' => lang('Audience'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uiaudience.index',
							'appname' => 'booking'
						))
					),
					'agegroup' => array(
						'text' => lang('Age group'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uiagegroup.index',
							'appname' => 'booking'
						))
					),
					'account_code_sets' => array(
						'text' => lang('Account Codes'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uiaccount_code_set.index',
							'appname' => 'booking'
						))
					),
					'account_code_dimensions' => array(
						'text' => lang('Account Code Dimension'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uiaccount_code_dimension.index',
							'appname' => 'booking'
						))
					),
					'async_settings' => array(
						'text' => lang('Asynchronous Tasks'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uiasync_settings.index',
							'appname' => 'booking'
						))
					),
					'documentation' => array(
						'text' => lang('Documentation'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uidocumentation.index',
							'appname' => 'booking'
						))
					),
					'mail_settings' => array(
						'text' => lang('Mail Settings'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uimail_settings.index',
							'appname' => 'booking'
						))
					),
					'event_mail_settings' => array(
						'text' => lang('Event Mail Settings'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uievent_mail_settings.index',
							'appname' => 'booking'
						))
					),
					'application_settings' => array(
						'text' => lang('Application Settings'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uiapplication_settings.index',
							'appname' => 'booking'
						))
					),
					'e_lock_system' => array(
						'text' => lang('e_lock_system'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uigeneric.index',
							'type' => 'e_lock_system'
						)),
					),
					'office' => array(
						'text' => lang('office'),
						'url' => phpgw::link('/index.php', array(
							'menuaction' => 'booking.uigeneric.index',
							'type' => 'bb_office'
						)),
						'children' => array(
							'office' => array(
								'text' => lang('office'),
								'url' => phpgw::link('/index.php', array(
									'menuaction' => 'booking.uigeneric.index',
									'type' => 'bb_office'
								)),
							),
							'office_user' => array(
								'text' => lang('office user'),
								'url' => phpgw::link('/index.php', array(
									'menuaction' => 'booking.uigeneric.index',
									'type' => 'bb_office_user'
								))
							)
						)
					),
				)
			);
			if ($manual !== null)
			{
				$menus['navigation']['documentation'] = array(
					'text' => lang('Documentation'),
					'url' => $manual,
					'image' => array('property', 'documentation'),
				);
			}
		}

		if ($acl->check('run', Acl::READ, 'admin') || $acl->check('admin', Acl::ADD, 'booking'))
		{
			$menus['admin'] = array(
				'index' => array(
					'text' => lang('Configuration'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'admin.uiconfig.index',
						'appname' => 'booking'
					))
				),
				'customconfig' => array(
					'text'			 => lang('custom config'),
					'nav_location'	 => 'navbar#' . $location_obj->get_id('booking', 'run'),
					'url'			 => phpgw::link('/index.php', array(
						'menuaction'	 => 'admin.uiconfig2.index',
						'location_id'	 => $location_obj->get_id('booking', 'run')
					))
				),
				'permissions' => array(
					'text' => lang('Root Permissions'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uipermission_root.index',
						'appname' => 'booking'
					))
				),
				'acl' => array(
					'text' => lang('Configure Access Permissions'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'preferences.uiadmin_acl.list_acl',
						'acl_app' => 'booking'
					))
				),
				'rescategory' => array(
					'text' => lang('Resource categories'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uirescategory.index',
						'appname' => 'booking'
					))
				),
				'activity' => array(
					'text' => lang('Activity'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uiactivity.index',
						'appname' => 'booking'
					))
				),
				'facility' => array(
					'text' => lang('Facilities'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uifacility.index',
						'appname' => 'booking'
					))
				),
				'audience' => array(
					'text' => lang('Audience'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uiaudience.index',
						'appname' => 'booking'
					))
				),
				'agegroup' => array(
					'text' => lang('Agegroup'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uiagegroup.index',
						'appname' => 'booking'
					))
				),
				'async_settings' => array(
					'text' => lang('Asynchronous Tasks'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uiasync_settings.index',
						'appname' => 'booking'
					))
				),
				'settings' => array(
					'text' => lang('Settings'),
					'url' => phpgw::link('/index.php', array(
						'menuaction' => 'booking.uisettings.index',
						'appname' => 'booking'
					))
				),
			);
		}


		$menus['folders'] = phpgwapi_menu::get_categories('bergen');

		Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);

		return MenuController::applyOverrides($menus);
	}
}
