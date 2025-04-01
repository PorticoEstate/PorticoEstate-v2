<?php
	/**
	 * property - Menus
	 *
	 * @author Dave Hall <skwashd@phpgroupware.org>
	 * @author Sigurd Nes <sigurdne@online.no>
	 * @copyright Copyright (C) 2007,2008 Free Software Foundation, Inc. http://www.fsf.org/
	 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
	 * @package property
	 * @version $Id$
	 */
/*
	  This program is free software: you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation, either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

	use App\modules\phpgwapi\services\Settings;
	use App\modules\phpgwapi\security\Acl;
	use App\modules\phpgwapi\controllers\Locations;
	use App\modules\phpgwapi\services\Translation;

	/**
	 * Menus
	 *
	 * @package property
	 */
	class property_menu
	{

		/**
		 * Get the menus for the property
		 *
		 * @return array available menus for the current user
		 */
		public function get_menu( $type = '' )
		{
			$userSettings = Settings::getInstance()->get('user');
			$flags = Settings::getInstance()->get('flags');
			$location_obj = new Locations();
			$translation = Translation::getInstance();
			
			$incoming_app			 = $flags['currentapp'];
			Settings::getInstance()->update('flags', ['currentapp' => 'property']);
			$acl					 =	Acl::getInstance();
			$menus					 = array();

			$entity		 = CreateObject('property.soadmin_entity');
			$entity_list = $entity->read(array('allrows' => true));

			$start_page = 'location';
			if (isset($userSettings['preferences']['property']['default_start_page']) && $userSettings['preferences']['property']['default_start_page'])
			{
				$start_page = $userSettings['preferences']['property']['default_start_page'];
			}

			$config = CreateObject('phpgwapi.config', 'property')->read();
			if (!empty($config['app_name']))
			{
				$lang_app_name = $config['app_name'];
			}
			else
			{
				$lang_app_name = lang('property');
			}

			$menus['navbar'] = array
				(
				'property' => array
					(
					'text'	 => $lang_app_name,
					'url'	 => phpgw::link('/index.php', array('menuaction' => "property.ui{$start_page}.index")),
					'image'	 => array('property', 'navbar'),
					'order'	 => 35,
					'group'	 => 'facilities management'
				),
			);

			$menus['toolbar'] = array();

			$soadmin_location	 = CreateObject('property.soadmin_location');
			$locations			 = $soadmin_location->select_location_type();

			$sysadmin		 = $acl->check('run', Acl::READ, 'admin');
			$local_admin	 = $acl->check('admin', Acl::ADD, 'property');
			$admin_booking	 = $acl->check('.admin_booking', Acl::ADD, 'property');

			if ($sysadmin || $local_admin)
			{
				if (is_array($entity_list) && count($entity_list))
				{
					foreach ($entity_list as $entry)
					{
						$admin_children_entity["entity_{$entry['id']}"] = array
							(
							'url'			 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_entity.category',
								'entity_id'	 => $entry['id'])),
							'text'			 => $entry['name'],
							'image'			 => array('property', 'entity_' . $entry['id']),
							'nav_location'	 => "admin#" . $location_obj->get_id('property', ".entity.{$entry['id']}")
						);

						$admin_children_entity["entity_{$entry['id']}"]['children'] = $entity->read_category_tree($entry['id'], 'property.uiadmin_entity.list_attribute', false, 'admin#');
					}
				}
				$admin_children_entity['convert_to_eav'] = array
					(
					'text'	 => lang('convert to eav'),
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_entity.convert_to_eav'))
				);

				$admin_children_entity['entity_group'] = array
					(
					'text'	 => lang('entity group'),
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
						'type'		 => 'entity_group'))
				);

				$admin_children_tenant = array
					(
					'tenant_cats'		 => array
						(
						'text'	 => lang('Tenant Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'tenant_cats'))
					),
					'tenant_global_cats' => array
						(
						'text'	 => lang('Tenant Global Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.tenant', 'global_cats'	 => 'true',
							'menu_selection' => 'admin::property::tenant::tenant_global_cats'))
					),
					'tenant_attribs'	 => array
						(
						'text'	 => lang('Tenant Attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.tenant', 'menu_selection' => 'admin::property::tenant::tenant_attribs'))
					),
					'claims_cats'		 => array
						(
						'text'	 => lang('Tenant Claim Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'tenant_claim'))
					)
				);

				$admin_children_vendor = array
					(
					'vendor_cats'		 => array
						(
						'text'	 => lang('Vendor Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'vendor_cats'))
					),
					'vendor_global_cats' => array
						(
						'text'	 => lang('Vendor Global Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.vendor', 'global_cats'	 => 'true',
							'menu_selection' => 'admin::property::vendor::vendor_global_cats'))
					),
					'vendor_attribs'	 => array
						(
						'text'	 => lang('Vendor Attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.vendor', 'menu_selection' => 'admin::property::vendor::vendor_attribs'))
					)
				);

				$admin_children_project = array
					(
					'project_cats'					 => array
						(
						'text'	 => lang('project categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.project', 'global_cats'	 => 'true',
							'menu_selection' => 'admin::property::project::project_cats'))
					),
					'project_attribs'				 => array
						(
						'text'	 => lang('project attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.project', 'menu_selection' => 'admin::property::project::project_attribs'))
					),
					'workorder_status'				 => array
						(
						'text'	 => lang('Workorders status'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'workorder_status'))
					),
					'project_status'				 => array
						(
						'text'	 => lang('project status'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'project_status'))
					),
					'external_project'				 => array
						(
						'text'	 => lang('external project'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'external_project'))
					),
					'unspsc_code'					 => array
						(
						'text'	 => lang('unspsc code'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'unspsc_code'))
					),
					'workorder_detail'				 => array
						(
						'text'	 => lang('Workorder Detail Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'wo_hours'))
					),
					'workorder_recalculate'			 => array
						(
						'text'	 => lang('Workorder recalculate actual cost'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiworkorder.recalculate'))
					),
					'project_functions'				 => array
						(
						'text'	 => lang('custom functions'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_custom_function',
							'appname'		 => 'property', 'location'		 => '.project', 'menu_selection' => 'admin::property::project::project_functions'))
					),
					'check_missing_project_budget'	 => array
						(
						'text'	 => lang('check missing project budget'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiproject.check_missing_project_budget'))
					)
				);

				$admin_children_ticket = array
					(
					'ticket_cats'		 => array
						(
						'text'	 => lang('Ticket Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.ticket', 'global_cats'	 => 'true',
							'menu_selection' => 'admin::property::ticket::ticket_cats'))
					),
					'ticket_status'		 => array
						(
						'text'	 => lang('Ticket status'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'ticket_status'))
					),
					'ticket_priority'	 => array
						(
						'text'	 => lang('Ticket priority'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'ticket_priority'))
					),
					'external_com_type'	 => array
						(
						'text'	 => lang('external communication type'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'external_com_type'))
					),
					'ticket_config'		 => array
						(
						'text'	 => lang('ticket config'),
						'nav_location' => 'navbar#' . $location_obj->get_id('property', '.ticket'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uiconfig2.index',
							'location_id'	 => $location_obj->get_id('property', '.ticket')))
					),
					'ticket_attribs'	 => array
						(
						'text'	 => lang('ticket Attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.ticket', 'menu_selection' => 'admin::property::ticket::ticket_attribs'))
					),
					'ticket_functions'	 => array
						(
						'text'	 => lang('custom functions'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_custom_function',
							'appname'		 => 'property', 'location'		 => '.ticket', 'menu_selection' => 'admin::property::ticket::ticket_functions'))
					),
				);


				$admin_children_accounting = array
					(
					'accounting_cats'		 => array
						(
						'text'	 => lang('Accounting Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'b_account'))
					),
					'budget_account'		 => array
						(
						'text'	 => lang('budget account'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'budget_account'))
					),
					'eco_service'			 => array
						(
						'text'	 => lang('service'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'eco_service'))
					),
					'org_unit'				 => array
						(
						'text'	 => lang('department'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'org_unit'))
					),
					'accounting_dimb'		 => array
						(
						'text'	 => lang('Accounting dim b'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'dimb'))
					),
					/*
					  'dimb_roles'	=> array
					  (
					  'text'	=> lang('dimb roles'),
					  'url'	=> phpgw::link('/index.php', array('menuaction' => 'admin.uicategories.index', 'appname' => 'property', 'location' => '.invoice.dimb', 'global_cats' => 'true', 'menu_selection' => 'admin::property::accounting::dimb_roles') )
					  ),
					 */
					'dimb_role'				 => array
						(
						'text'	 => lang('dimb roles'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'dimb_role'))
					), /*
					  'dimb_role_user' => array
					  (
					  'text'	=> lang('dimb role user'),
					  'url'	=> phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index', 'type' => 'dimb_role_user') )
					  ),
					 */
					'dimb_role_user2'		 => array
						(
						'text'		 => lang('dimb role user'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uidimb_role_user.index')),
						'children'	 => array(
							'substitute' => array
								(
								'text'	 => lang('substitute'),
								'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uisubstitute.index')),
							)
						)
					),
					'accounting_dimd'		 => array
						(
						'text'	 => lang('Accounting dim d'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'dimd'))
					),
					'periodization'			 => array
						(
						'text'	 => lang('periodization'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'periodization'))
					),
					'periodization_outline'	 => array
						(
						'text'	 => lang('periodization outline'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'periodization_outline'))
					),
					'period_transition'		 => array
						(
						'text'	 => lang('period transition'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'period_transition'))
					),
					'accounting_config'		 => array
						(
						'text'	 => lang('Configuration'),
						'nav_location' => 'navbar#' . $location_obj->get_id('property', '.invoice'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uiconfig2.index',
							'location_id'	 => $location_obj->get_id('property', '.invoice')))
					),
					'accounting_tax'		 => array
						(
						'text'	 => lang('Accounting tax'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'tax'))
					),
					'process_code'			 => array
						(
						'text'	 => lang('voucher process code'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'voucher_process_code'))
					),
					'voucher_cats'			 => array
						(
						'text'	 => lang('Accounting voucher category'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'voucher_cat'))
					),
					'voucher_type'			 => array
						(
						'text'	 => lang('Accounting voucher type'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'voucher_type'))
					),
					'quick_order_delivery_type'			 => array
						(
						'text'	 => lang('delivery type'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'order_template_delivery_type'))
					),
					'quick_order_payment_type'	=> array
						(
						'text'	 => lang('payment type'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'order_template_payment_type'))
					),
				);

				$admin_children_agreement = array
					(
					'agreement_status'			 => array
						(
						'text'	 => lang('Agreement status'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'agreement_status'))
					),
					'agreement_attribs'			 => array
						(
						'text'	 => lang('Agreement Attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.agreement', 'menu_selection' => 'admin::property::agreement::agreement_attribs'))
					),
					'service_agree_cats'		 => array
						(
						'text'	 => lang('service agreement categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 's_agreement'))
					),
					'service_agree_attribs'		 => array
						(
						'text'	 => lang('service agreement Attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.s_agreement', 'menu_selection' => 'admin::property::agreement::service_agree_attribs'))
					),
					'service_agree_item_attribs' => array
						(
						'text'	 => lang('service agreement item Attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.s_agreement.detail', 'menu_selection' => 'admin::property::agreement::service_agree_item_attribs'))
					)
				);

				foreach ($locations as $location)
				{
					$admin_children_location_children["attribute_loc_{$location['id']}"] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_location.list_attribute',
							'type_id'	 => $location['id'])),
						'text'	 => $location['name'] . ' ' . lang('attributes'),
					);
					$admin_children_location_children["category_{$location['id']}"]		 = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'location', 'type_id'	 => $location['id'])),
						'text'	 => $location['name'] . ' ' . lang('categories'),
					);
				}

				$location_exception_children = array
					(
					'severity'	 => array
						(
						'text'	 => lang('severity'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'location_exception_severity'))
					),
					'category'	 => array
						(
						'text'		 => lang('category'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'location_exception_category')),
						'children'	 => array(
							'category_text' => array
								(
								'text'	 => lang('text'),
								'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
									'type'		 => 'location_exception_category_text'))
							)
						)
					)
				);
			}

			if ($sysadmin || $local_admin || $admin_booking)
			{
				foreach ($locations as $location)
				{
					if ($sysadmin || $local_admin)
					{
						$admin_children_location_children["attribute_loc_{$location['id']}"] = array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_location.list_attribute',
								'type_id'	 => $location['id'])),
							'text'	 => $location['name'] . ' ' . lang('attributes'),
						);
					}

					$admin_children_location_children["category_{$location['id']}"] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'location', 'type_id'	 => $location['id'])),
						'text'	 => $location['name'] . ' ' . lang('categories'),
					);
				}
				$admin_children_owner = array
					(
					'owner_cats'	 => array
						(
						'text'	 => lang('Owner Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'owner_cats'))
					),
					'owner_attribs'	 => array
						(
						'text'	 => lang('Owner Attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.owner', 'menu_selection' => 'admin::property::owner::owner_attribs'))
					)
				);
			}

			if ($sysadmin || $local_admin || $admin_booking)
			{
				$admin_children_location = array
					(
					'district'	 => array
						(
						'text'	 => lang('District'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'district'))
					),
					'town'		 => array
						(
						'text'	 => lang('Part of town'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'part_of_town'))
					),
					'street'	 => array
						(
						'text'	 => lang('Street'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'street'))
					),
					'zip_code'	 => array
						(
						'text'	 => lang('zip code'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'zip_code'))
					),
					'location'	 => array
						(
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_location.index')),
						'text'		 => lang('Location type'),
						'children'	 => $admin_children_location_children
					)
				);
			}

			if ($sysadmin || $local_admin)
			{

				$admin_children_location['update_location']		 = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uilocation.update_location')),
					'text'	 => lang('update location')
				);
				$admin_children_location['location_contact']	 = array
					(
					'text'	 => lang('location contact'),
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
						'type'		 => 'location_contact'))
				);
				$admin_children_location['cadastre']			 = array
					(
					'text'	 => lang('cadastre'),
					'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
						'appname'		 => 'property', 'location'		 => '.location.gab', 'menu_selection' => 'admin::property::location::cadastre'))
				);
				$admin_children_location['location_exception']	 = array
					(
					'text'		 => lang('location exception'),
					'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
						'type'		 => 'location_exception')),
					'children'	 => $location_exception_children
				);

			$admin_children_location['config'] = array(
				'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_location.config')),
				'text'	 => lang('Config')
			);
			$admin_children_location['analyze_location'] = array(
				'text'	=> lang('Analyze Location Hierarchy'),
				'url'	=> phpgw::link('/index.php', array('menuaction' => 'property.uianalyze_location.index')),
			);

			$menus['admin'] = array(
					'index'			 => array(
						'text'		 => lang('Configuration'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'admin.uiconfig.index',	'appname'	 => 'property')),
						'children'	 => array(
							'custom_config'		 => array(
								'text'			 => lang('custom config'),
								'nav_location'	 => 'navbar#' . $location_obj->get_id('property', '.admin'),
								'url'			 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uiconfig2.index',
									'location_id'	 => $location_obj->get_id('property', '.admin')))
							),
							'klassifikasjonssystemet'	 => array(
								'text'	 => 'Klassifikasjonssystemet',
								'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiklassifikasjonssystemet.login'))
							),
						)
					),
					'import'	 => array(
						'text'	 => lang('Generic import'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiimport.index'))
					),
					'import_components'			 => array
						(
						'text'	 => lang('import components') . ' (TIDA)',
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiimport_components.index'))
					),
					'qr_generator'			 => array
						(
						'text'	 => lang('qr-generator'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiqr_generator.index'))
					),
					'qrcode_scanner'		 => array
						(
						'text'	 => lang('qrcode scanner'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiscanner.index'))
					),
					'entity'					 => array
						(
						'text'		 => lang('Admin entity'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_entity.index')),
						'children'	 => $admin_children_entity,
						'nav_location' => "admin#" . $location_obj->get_id('property', '.admin.entity')
					),
					'location'					 => array
						(
						'text'		 => lang('Admin Location'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_location.index')),
						'image'		 => array('property', 'location'),
						'children'	 => $admin_children_location
					),
					'inactive_cats'				 => array
						(
						'text'	 => lang('Update the not active category for locations'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uilocation.update_cat'))
					),
					'project'					 => array
						(
						'text'		 => lang('project'),
						'url'		 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.project', 'global_cats'	 => 'true',
							'menu_selection' => 'admin::property::project::project_cats')),
						'children'	 => $admin_children_project
					),
					'ticket'					 => array
						(
						'text'		 => lang('helpdesk'),
						'url'		 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.ticket', 'global_cats'	 => 'true',
							'menu_selection' => 'admin::property::ticket::ticket_cats')),
						'children'	 => $admin_children_ticket
					),
					'tenant'					 => array
						(
						'text'		 => lang('Tenant'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'tenant', 'admin'		 => true)),
						'image'		 => array('property', 'location_tenant'),
						'children'	 => $admin_children_tenant
					),
					'owner'						 => array
						(
						'text'		 => lang('Owner'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'owner', 'admin'		 => true)),
						'children'	 => $admin_children_owner
					),
					'vendor'					 => array
						(
						'text'		 => lang('Vendor'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'vendor', 'admin'		 => true)),
						'children'	 => $admin_children_vendor
					),
					'doc_cats'					 => array
						(
						'text'	 => lang('document categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.document', 'global_cats'	 => 'true'))
					),
					'building_part'				 => array
						(
						'text'	 => lang('Building Part'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'building_part'))
					),
					'tender'					 => array
						(
						'text'	 => lang('Tender chapter'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'tender_chapter'))
					),
					'id_control'				 => array
						(
						'text'	 => lang('ID Control'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin.edit_id'))
					),
					'permissions'				 => array
						(
						'text'	 => lang('Permissions'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin.list_acl'))
					),
					'user_contact'				 => array
						(
						'text'	 => lang('User contact info'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin.contact_info'))
					),
					'request_cats'				 => array
						(
						'text'	 => lang('request categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.project.request', 'global_cats'	 => 'true',
							'menu_selection' => 'admin::property::request_cats'))
					),
					'request_status'			 => array
						(
						'text'	 => lang('Request status'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'request_status'))
					),
					'request_responsible_unit'	 => array
						(
						'text'	 => lang('responsible unit'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'request_responsible_unit'))
					),
					'request_condition'			 => array
						(
						'text'	 => lang('Request condition type'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'r_condition_type'))
					),
					'request_probability'	=> array
						(
						'text'	 => lang('Request probability'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'r_probability'))
					),
					'request_consequence'	 => array
						(
						'text'	 => lang('Request consequence'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'r_consequence'))
					),
					'condition_survey_cats'		 => array
						(
						'text'	 => lang('condition survey Categories'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.uicategories.index',
							'appname'		 => 'property', 'location'		 => '.project.condition_survey', 'global_cats'	 => 'true',
							'menu_selection' => 'admin::property::condition_survey_cats'))
					),
					'condition_survey_status'	 => array
						(
						'text'	 => lang('condition survey status'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'condition_survey_status'))
					),
					'authorities_demands'		 => array
						(
						'text'	 => lang('authorities demands'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'authorities_demands'))
					),
					'regulations'				 => array
						(
						'text'	 => lang('regulations'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'regulations'))
					),
					'request_attribs'			 => array
						(
						'text'	 => lang('request attributes'),
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'admin.ui_custom.list_attribute',
							'appname'		 => 'property', 'location'		 => '.project.request', 'menu_selection' => 'admin::property::request_attribs'))
					),
					'order_dim1'				 => array
						(
						'text'	 => lang('order_dim1'), //translation have to refeflect the (local) meaning
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'order_dim1'))
					),
					'agreement'					 => array
						(
						'text'		 => lang('Agreement'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'agreement_status')),
						'image'		 => array('property', 'agreement'),
						'children'	 => $admin_children_agreement
					),
					'document_status'			 => array
						(
						'text'	 => lang('Document Status'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'document_status'))
					),
					'unit'						 => array
						(
						'text'	 => lang('Unit'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'unit'))
					),
					'ns3420'					 => array
						(
						'text'	 => lang('ns3420'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'ns3420'))
					),
					'key_location'				 => array
						(
						'text'	 => lang('Key location'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'key_location'))
					),
					'branch'					 => array
						(
						'text'	 => lang('Branch'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'branch'))
					),
					'accounting'				 => array
						(
						'text'		 => lang('Accounting'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'budget_account')),
						'children'	 => $admin_children_accounting
					),
					'admin_async'				 => array
						(
						'text'	 => lang('Admin Async services'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uialarm.index'))
					),
					'async'						 => array
						(
						'text'	 => lang('Async services'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiasync.index'))
					),
					'event_action'				 => array
						(
						'text'	 => lang('event action'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'event_action'))
					),
					'list_functions'			 => array
						(
						'text'	 => lang('Admin custom functions'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'admin.ui_custom.list_custom_function',
							'appname'	 => 'property'))
					),
					'migrate_db'				 => array
						(
						'text'	 => lang('Migrate to alternative db'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uimigrate.index'))
					),
					'custom_menu_items'			 => array
						(
						'text'	 => lang('custom menu items'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'custom_menu_items'))
					),
					'responsibility_role'		 => array
						(
						'text'	 => lang('responsibility role'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'responsibility_role'))
					),
					'responsible_matrix'		 => array
						(
						'text'	 => lang('responsible matrix'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiresponsible.index')),
					),
					'pending_action_type'		 => array
						(
						'text'	 => lang('pending action type'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'pending_action_type'))
					)
				);
			}
			else if ($admin_booking)
			{
				$menus['admin'] = array
					(
					'import'	 => array
						(
						'text'	 => lang('Generic import'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiimport.index'))
					),
					'location'	 => array
						(
						'text'		 => lang('Admin Location'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin_location.index')),
						'image'		 => array('property', 'location'),
						'children'	 => $admin_children_location
					),
					'owner'		 => array
						(
						'text'		 => lang('Owner'),
						'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'owner', 'admin'		 => true)),
						'children'	 => $admin_children_owner
					),
				);
			}

			if (isset($userSettings['apps']['preferences']))
			{
				$menus['preferences'] = array
					(
					array(
						'text'	 => $translation->translate('Preferences', array(), true),
						'url'	 => phpgw::link('/preferences/section', array('appname'	 => 'property',
							'type'		 => 'user'))
					),
					array(
						'text'	 => $translation->translate('Grant Access', array(), true),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiadmin.aclprefs',
							'acl_app'	 => 'property'))
					),
					array(
						'text'	 => lang('substitute'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uisubstitute.edit',
							'acl_app'	 => 'property'))
					),
					'b_account_user' => array
						(
						'text'	 => lang('budget account user'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uib_account_user.index'))
					),
				);

				$menus['toolbar'][] = array
					(
					'text'	 => $translation->translate('Preferences', array(), true),
					'url'	 => phpgw::link('/preferences/preferences.php', array('appname' => 'property')),
					'image'	 => array('property', 'preferences')
				);
			}

			$menus['navigation'] = array();

			if ($acl->check('.location', ACL_READ, 'property'))
			{
				$children = array();

				foreach ($locations as $location)
				{
					if ($acl->check(".location.{$location['id']}", ACL_READ, 'property'))
					{
						$children["loc_{$location['id']}"] = array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uilocation.index',
								'type_id'	 => $location['id'])),
							'text'	 => $location['name'],
							'image'	 => array('property', 'location_' . $location['id'])
						);
					}
				}

				if (!isset($config['suppress_tenant']) || !$config['suppress_tenant'])
				{
					$children['tenant'] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'property.uilocation.index',
							'lookup_tenant'	 => 1, 'type_id'		 => $soadmin_location->read_config_single('tenant_id'))),
						'text'	 => lang('Tenant'),
						'image'	 => array('property', 'location_tenant')
					);
				}

				$children['gabnr'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigab.index')),
					'text'	 => lang('gabnr'),
					'image'	 => array('property', 'location_gabnr')
				);

				if (!isset($config['suppress_location_summary']) || !$config['suppress_location_summary'])
				{
					$children['summary'] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uilocation.summary')),
						'text'	 => lang('Summary'),
						'image'	 => array('property', 'location_summary')
					);
				}

				$children['responsibility_role'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uilocation.responsiblility_role')),
					'text'	 => lang('responsibility role'),
					'image'	 => array('property', 'responsibility_role')
				);

				$children['location_exception'] = array
					(
					'text'	 => lang('location exception'),
					'url'	 => phpgw::link('/index.php', array('menuaction'	 => 'property.uigeneric.index',
						'type'			 => 'location_exception',
						'menu_selection' => 'property::location::location_exception')),
				);


				$menus['navigation']['location'] = array
					(
					'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uilocation.index',
						'type_id'	 => 1)),
					'text'		 => lang('Location'),
					'image'		 => array('property', 'location'),
					'children'	 => $children
				);
			}

			if ($acl->check('.ifc', ACL_READ, 'property'))
			{
				$menus['navigation']['ifc'] = array
					(
					'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiifc.import')),
					'text'		 => lang('IFC'),
					'image'		 => array('property', 'ifc'),
					'children'	 => array
						(
						'import' => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiifc.import')),
							'text'	 => lang('import'),
							'image'	 => array('property', 'ifc_import'),
						)
					)
				);
			}

			if ($acl->check('.ticket', ACL_READ, 'property'))
			{
				$menus['navigation']['helpdesk'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uitts.index')),
					'text'	 => lang('Helpdesk'),
					'image'	 => array('property', 'helpdesk')
				);

				$menus['navigation']['helpdesk']['children'] = array
				(
					'deviation'	 => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiexternal_communication.index')),
						'text'	 => lang('deviation'),
						'image'	 => array('property', 'helpdesk'),
						'children'	 => array
						(
							'list_deviation' => array
							(
								'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiexternal_communication.index')),
								'text'	 => lang('list deviation'),
								'image'	 => array('property', 'helpdesk'),
							),
							'add_deviation' => array
							(
								'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiexternal_communication.add_deviation')),
								'text'	 => lang('add'),
								'image'	 => array('property', 'helpdesk'),
							),
						)
					),
					'report'	 => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uitts.report')),
						'text'	 => lang('report'),
						'image'	 => array('property', 'helpdesk')
					)
				);
			}

			if ($acl->check('run', ACL_READ, 'sms'))
			{
				$menus['navigation']['helpdesk']['children']['send_sms'] = array(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiexternal_communication.send_sms')),
						'text'	 => lang('send sms'),
						'image'	 => array('property', 'helpdesk'),
					);
			}


			if ($acl->check('.report', ACL_READ, 'property'))
			{
				$menus['navigation']['report'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uireport.index')),
					'text'	 => lang('report'),
					'image'	 => array('property', 'report')
				);
			}

			if ($acl->check('.ticket.order', ACL_ADD, 'property'))
			{
				$menus['navigation']['helpdesk']['children']['quick_order_template']	 = array
				(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiorder_template.index')),
					'text'	 => lang('quick order template'),
					'image'	 => array('property', 'helpdesk')
				);
				$menus['navigation']['helpdesk']['children']['order_template'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
						'type'		 => 'order_template')),
					'text'	 => lang('order template'),
					'image'	 => array('property', 'helpdesk')
				);
			}

			if (isset($userSettings['apps']['sms']))
			{
				$menus['navigation']['helpdesk']['children']['response_template'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
						'type'		 => 'response_template')),
					'text'	 => lang('response template'),
					'image'	 => array('property', 'helpdesk')
				);
			}

			if ($acl->check('.project', ACL_READ, 'property'))
			{
				/*
				  $cats	= CreateObject('phpgwapi.categories', -1,  'property', '.project');
				  $cats->supress_info	= true;

				  $project_cats = $cats->formatted_xslt_list(array('format'=>'filter','globals' => True));
				  //_debug_array($project_cats);die();
				  $project_children = array();
				  foreach($project_cats['cat_list'] as $dummy => $project_cat)
				  {
				  $project_children[$project_cat['cat_id']] = array
				  (
				  'url'		=> phpgw::link('/index.php',array('menuaction'=> 'property.uiproject.index', 'cat_id' => $project_cat['cat_id'])),
				  'text'		=> $project_cat['name'],
				  'image'		=> array('property', 'project'),

				  );
				  }
				 */
				$menus['navigation']['project'] = array
					(
					'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiproject.index')),
					'text'		 => lang('Project'),
					'image'		 => array('property', 'project'),
					'children'	 => array
						(
						'project'					 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiproject.index')),
							'text'	 => lang('Project'),
							'image'	 => array('property', 'project'),
						//		'children'	=> $project_children
						),
						'workorder'					 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiworkorder.index')),
							'text'	 => lang('Workorder'),
							'image'	 => array('property', 'project_workorder')
						),
						'condition_survey'			 => array
							(
							'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uicondition_survey.index')),
							'text'		 => lang('condition survey'),
							'image'		 => array('property', 'condition_survey'),
							'children'	 => array
								(
								'summation' => array
									(
									'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uicondition_survey.summation')),
									'text'	 => lang('summation'),
									'image'	 => array('property', 'invoice')
								)
							)
						),
						'request'					 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uirequest.index')),
							'text'	 => lang('Request'),
							'image'	 => array('property', 'project_request')
						),
						'template'					 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uitemplate.index')),
							'text'	 => lang('template'),
							'image'	 => array('property', 'project_template')
						),
						'project_bulk_update_status' => array
							(
							'text'	 => lang('bulk update status'),
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiproject.bulk_update_status'))
						)
					)
				);
			}

			if ($acl->check('.scheduled_events', ACL_READ, 'property'))
			{
				$menus['navigation']['scheduled_events'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uievent.index')),
					'text'	 => lang('scheduled events'),
					'image'	 => array('scheduled_events', 'project')
				);
			}

			$invoicehandler = isset($config['invoicehandler']) && $config['invoicehandler'] == 2 ? 'uiinvoice2' : 'uiinvoice';
			$invoice = array();

			if ($acl->check('.invoice', ACL_READ, 'property'))
			{
				$children			 = array();
				$children_invoice	 = array();
				if ($acl->check('.invoice', ACL_PRIVATE, 'property'))
				{
					$children['investment'] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiinvestment.index')),
						'text'	 => lang('Investment value')
					);

					$children_invoice['invoice'] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => "property.{$invoicehandler}.index")),
						'text'	 => lang('Invoice'),
						'image'	 => array('property', 'invoice'),
					);

					$children_invoice['import'] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiXport.import')),
						'text'	 => lang('Import invoice')
					);

					$children_invoice['export']		 = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiXport.export')),
						'text'	 => lang('Export invoice')
					);
					$children_invoice['rollback']	 = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiXport.rollback')),
						'text'	 => lang('Roll back')
					);
				}

				if ($acl->check('.invoice', ACL_ADD, 'property'))
				{
					$children_invoice['add'] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiinvoice.add')),
						'text'	 => lang('Add')
					);
				}


				$invoice = array_merge(array
					(
					'invoice'		 => array
						(
						'url'		 => phpgw::link('/index.php', array('menuaction' => "property.{$invoicehandler}.index")),
						'text'		 => lang('Invoice'),
						'image'		 => array('property', 'invoice'),
						'children'	 => $children_invoice,
					),
					'deposition'	 => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiinvoice.reporting',
							'type'		 => 'deposition')),
						'text'	 => lang('deposition')
					),
					'reconciliation' => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiinvoice.reporting',
							'type'		 => 'reconciliation')),
						'text'	 => lang('reconciliation')
					),
					'paid'			 => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiinvoice.index',
							'paid'		 => true)),
						'text'	 => lang('Paid')
					),
					'consume'		 => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiinvoice.consume')),
						'text'	 => lang('consume')
					),
					'budget_account' => array
						(
						'text'	 => lang('budget account'),
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'budget_account'))
					),
					'vendor'		 => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'vendor')),
						'text'	 => lang('Vendor')
					),
					'tenant'		 => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric.index',
							'type'		 => 'tenant')),
						'text'	 => lang('Tenant')
					),
					'claim'			 => array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uitenant_claim.index')),
						'text'	 => lang('Tenant claim'),
						'image'	 => array('property', 'project_tenant_claim')
					)
					), $children);
			}

			$budget = array();
			if ($acl->check('.budget', ACL_READ, 'property'))
			{
				$budget['budget'] = array
					(
					'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uibudget.index')),
					'text'		 => lang('Budget'),
					'image'		 => array('property', 'budget'),
					'children'	 => array
						(
						'budget'		 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uibudget.index')),
							'text'	 => lang('budget')
						),
						'obligations'	 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uibudget.obligations')),
							'text'	 => lang('obligations')
						)
					)
				);

				if ($acl->check('.budget.basis', ACL_READ, 'property'))
				{
					$budget['budget']['children']['basis'] = array
						(
						'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uibudget.basis')),
						'text'	 => lang('basis')
					);
				}
			}

			if ($invoice || $budget)
			{
				$menus['navigation']['economy'] = array
					(
					'url'		 => phpgw::link('/index.php', array('menuaction' => "property.{$invoicehandler}.index")),
					'text'		 => lang('economy'),
					'image'		 => array('property', 'invoice'),
					'children'	 => array_merge($invoice, $budget)
				);
			}

			if ($acl->check('.agreement', ACL_READ, 'property'))
			{
				$admin_menu = array();
				if ($acl->check('.agreement', 16, 'property'))
				{
					$admin_menu = array
						(
						'group'		 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uipricebook.agreement_group')),
							'text'	 => lang('Agreement group')
						),
						'activities' => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uipricebook.activity')),
							'text'	 => lang('Activities')
						),
						'agreement'	 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiagreement.index')),
							'text'	 => lang('Agreement')
						)
					);
				}

				$menus['navigation']['agreement'] = array
					(
					'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiagreement.index')),
					'text'		 => lang('Agreement'),
					'image'		 => array('property', 'agreement'),
					'children'	 => array
						(
						'pricebook'	 => array
							(
							'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uiagreement.index')),
							'text'		 => lang('Pricebook'),
							'children'	 => $admin_menu
						),
						'service'	 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uis_agreement.index')),
							'text'	 => lang('Service')
						),
						'alarm'		 => array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uialarm.list_alarm')),
							'text'	 => lang('alarm')
						)
					)
				);
			}

			$custom_menus = CreateObject('property.sogeneric');
			$custom_menus->get_location_info('custom_menu_items', false);

			if ($acl->check('.document', ACL_READ, 'property'))
			{
//				$laws_url								 = phpgw::link('/redirect.php', array('go' => urlencode('http://www.regelhjelp.no/')));
				$menus['navigation']['documentation']	 = array
				(
					'url'		 => phpgw::link('/index.php', array('menuaction' => 'property.uidocument.index')),
					'text'		 => lang('Documentation'),
					'image'		 => array('property', 'documentation'),
					'children'	 => array
					(
						'generic'		 => array(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigeneric_document.index')),
							'text'	 => lang('generic document')
						),
//						'legislation'	 => array
//							(
//							'text'	 => $translation->translate('laws and regulations', array(), true),
//							'url'	 => $laws_url,
//						),
						'location'		 => array(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uidocument.index')),
							'text'	 => lang('location')
						)
					)
				);

				if ($acl->check('.document.import', ACL_PRIVATE, 'property'))//acl_manage
				{
						$menus['navigation']['documentation']['children']['import_documents']	 = array
							(
							'text'	 => lang('import documents'),
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uiimport_documents.index'))
						);
				}

				if (is_array($entity_list) && count($entity_list))
				{
					foreach ($entity_list as $entry)
					{
						if ($entry['documentation'] && $acl->check(".entity.{$entry['id']}", ACL_READ, 'property'))
						{
							$menus['navigation']['documentation']['children']["entity_{$entry['id']}"] = array
								(
								'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uidocument.index',
									'entity_id'	 => $entry['id'])),
								'text'	 => $entry['name']
							);
						}
					}
				}

				$menus['navigation']['documentation']['children']['gallery'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uigallery.index')),
					'text'	 => lang('gallery')
				);

				$custom_menu_items = $custom_menus->read(array('type' => 'custom_menu_items'), array(
					'location' => '.document'));
				foreach ($custom_menu_items as $item)
				{
					$menus['navigation']['documentation']['children'][] = array
						(
						'url'			 => $item['url']	 .= !empty($item['local_files']) ? '' : '&' . get_phpgw_session_url(),
						'text'			 => $item['text'],
						'target'		 => $item['target'] ? $item['target'] : '_blank',
						'local_files'	 => $item['local_files']
					);
				}
				unset($item);
			}

			if ($acl->check('.custom', ACL_READ, 'property'))
			{
				$menus['navigation']['custom'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uicustom.index')),
					'text'	 => lang('Custom'),
					'image'	 => array('property', 'custom')
				);
			}

			if (is_array($entity_list) && count($entity_list))
			{
				foreach ($entity_list as $entry)
				{
					if ($acl->check(".entity.{$entry['id']}", ACL_READ, 'property'))
					{

						if ($type != 'horisontal')
						{
							//bypass_acl_at_entity
							$_required		 = !empty($config['bypass_acl_at_entity']) && is_array($config['bypass_acl_at_entity']) && in_array($entry['id'], $config['bypass_acl_at_entity']) ? '' : ACL_READ;
							$entity_children = $entity->read_category_tree($entry['id'], 'property.uientity.index', $_required, 'navbar#');

							if (!$entity_children)
							{
								continue;
							}
						}
						else
						{
							$entity_children = array();
						}

						$menus['navigation']["entity_{$entry['id']}"] = array
							(
							'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uientity.summary',
								'entity_id'	 => $entry['id'])),
							'text'	 => $entry['name'],
							'image'	 => array('property', 'entity_' . $entry['id'])
						);

//						array_unshift($entity_children, array(
//								'url'	 => phpgw::link('/index.php', array(
//									'menuaction' => 'property.uientity.summary',
//									'entity_id'	 => $entry['id'])),
//								'text'	 => $entry['name'] . ' summary',
//								'image'	 => array('property', 'entity_' . $entry['id'])
//							)
//						);



						if ($type != 'horisontal')
						{
							$menus['navigation']["entity_{$entry['id']}"]['children'] = $entity_children;
						}

						$custom_menu_items = $custom_menus->read_tree(array('type'	 => 'custom_menu_items',
							'filter' => array('location' => ".entity.{$entry['id']}")));

						if ($custom_menu_items)
						{
							foreach ($custom_menu_items as $item)
							{
								if (empty($item['local_files']))
								{
									$item['url'] .= '&' . get_phpgw_session_url();
								}
								else if ($item['local_files'])
								{
									$item['url'] = 'file:///' . str_replace(':', '|', $item['url']);
								}

								$menus['navigation']["entity_{$entry['id']}"]['children'][] = $item;
							}
							unset($item);
						}
					}
				}
			}
			unset($entity_list);
			unset($entity);

			if ($acl->check('.jasper', ACL_READ, 'property'))
			{
				$menus['navigation']['jasper'] = array
					(
					'url'	 => phpgw::link('/index.php', array('menuaction' => 'property.uijasper.index')),
					'text'	 => 'JasperReports',
					'image'	 => array('property', 'report')
				);
			}

			Settings::getInstance()->update('flags', ['currentapp' => $incoming_app]);
			return $menus;
		}
	}	