<?php

namespace App\modules\property\models;

use App\models\GenericRegistry;

/**
 * Property Generic Registry Model
 * Provides property-specific registry definitions for various lookup tables
 * Based on property_sogeneric registry definitions
 */
class PropertyGenericRegistry extends GenericRegistry
{
	/**
	 * Load property-specific registry definitions
	 */
	protected static function loadRegistryDefinitions(): void
	{
		static::$registryDefinitions = [
			'part_of_town' => [
				'table' => 'fm_part_of_town',
				'name' => 'Part of Town',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::location::town',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'maxlength' => 20,
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'delivery_address',
						'type' => 'text',
						'nullable' => true
					],
					[
						'name' => 'external_id',
						'type' => 'int',
						'nullable' => true
					],
					[
						'name' => 'district_id',
						'type' => 'select',
						'nullable' => false,
						'validator' => function ($value)
						{
							return empty($value) || !is_numeric($value);
						},
						'filter' => true,
						'values_def' => [
							'valueset' => false,
							'method' => 'property.bogeneric.get_list',
							'get_single_value' => 'property.sogeneric.get_name',
							'method_input' => ['type' => 'district', 'selected' => '##district_id##']
						]
					]
				]
			],

			'district' => [
				'table' => 'fm_district',
				'name' => 'District',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::location::district',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'maxlength' => 20,
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'street' => [
				'table' => 'fm_streetaddress',
				'name' => 'Street',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::location::street',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'dimb' => [
				'table' => 'fm_ecodimb',
				'name' => 'Economic Dimension B',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::accounting_dimb',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'org_unit_id',
						'type' => 'select',
						'nullable' => false,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => false,
							'method' => 'property.bogeneric.get_list',
							'get_single_value' => 'property.sogeneric.get_name',
							'method_input' => ['type' => 'org_unit', 'selected' => '##org_unit_id##']
						]
					],
					[
						'name' => 'active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => [['id' => 1, 'name' => 'Active']]
						]
					]
				]
			],

			'dimd' => [
				'table' => 'fm_ecodimd',
				'name' => 'Economic Dimension D',
				'id' => ['name' => 'id', 'type' => 'varchar'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::accounting_dimd',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'periodization' => [
				'table' => 'fm_eco_periodization',
				'name' => 'Periodization',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::periodization',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => [['id' => 1, 'name' => 'Active']]
						]
					]
				]
			],

			'tax' => [
				'table' => 'fm_eco_tax',
				'name' => 'Tax',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::tax',
				'fields' => [
					[
						'name' => 'percent',
						'type' => 'float',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => true
					]
				]
			],

			'voucher_cat' => [
				'table' => 'fm_eco_voucher_cat',
				'name' => 'Voucher Category',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::voucher_cat',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'voucher_type' => [
				'table' => 'fm_eco_voucher_type',
				'name' => 'Voucher Type',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::voucher_type',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'tender_chapter' => [
				'table' => 'fm_tender_chapter',
				'name' => 'Tender Chapter',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::tender',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'owner_cats' => [
				'table' => 'fm_owner_category',
				'name' => 'Owner Categories',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::owner::owner_cats',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'tenant_cats' => [
				'table' => 'fm_tenant_category',
				'name' => 'Tenant Categories',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::tenant::tenant_cats',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'vendor_cats' => [
				'table' => 'fm_vendor_category',
				'name' => 'Vendor Categories',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::vendor::vendor_cats',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'vendor' => [
				'table' => 'fm_vendor',
				'name' => 'Vendor',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.vendor',
				'system_location' => '.vendor',
				'menu_selection' => 'property::economy::vendor',
				'fields' => [
					[
						'name' => 'active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => [['id' => 1, 'name' => 'Active']]
						]
					],
					[
						'name' => 'contact_phone',
						'type' => 'varchar',
						'nullable' => true
					],
					[
						'name' => 'category',
						'type' => 'select',
						'nullable' => false,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => false,
							'method' => 'property.bogeneric.get_list',
							'get_single_value' => 'property.sogeneric.get_name',
							'method_input' => ['type' => 'vendor_cats', 'selected' => '##category##']
						]
					]
				]
			],

			'owner' => [
				'table' => 'fm_owner',
				'name' => 'Owner',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.owner',
				'menu_selection' => 'property::economy::owner',
				'fields' => [
					[
						'name' => 'active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => [['id' => 1, 'name' => 'Active']]
						]
					],
					[
						'name' => 'contact_phone',
						'type' => 'varchar',
						'nullable' => true
					],
					[
						'name' => 'category',
						'type' => 'select',
						'nullable' => false,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => false,
							'method' => 'property.bogeneric.get_list',
							'get_single_value' => 'property.sogeneric.get_name',
							'method_input' => ['type' => 'owner_cats', 'selected' => '##category##']
						]
					]
				]
			],

			'tenant' => [
				'table' => 'fm_tenant',
				'name' => 'Tenant',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.tenant',
				'menu_selection' => 'property::economy::tenant',
				'fields' => [
					[
						'name' => 'active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => [['id' => 1, 'name' => 'Active']]
						]
					],
					[
						'name' => 'contact_phone',
						'type' => 'varchar',
						'nullable' => true
					],
					[
						'name' => 'category',
						'type' => 'select',
						'nullable' => false,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => false,
							'method' => 'property.bogeneric.get_list',
							'get_single_value' => 'property.sogeneric.get_name',
							'method_input' => ['type' => 'tenant_cats', 'selected' => '##category##']
						]
					]
				]
			],

			's_agreement' => [
				'table' => 'fm_s_agreement',
				'name' => 'Service Agreement',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::agreement::service',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'tenant_claim' => [
				'table' => 'fm_tenant_claim',
				'name' => 'Tenant Claim',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::tenant::claim',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'wo_hours' => [
				'table' => 'fm_wo_hours',
				'name' => 'Work Order Hours',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::workorder::hours',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'r_condition_type' => [
				'table' => 'fm_request_condition_type',
				'name' => 'Request Condition Type',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::request::condition_type',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'r_probability' => [
				'table' => 'fm_request_probability',
				'name' => 'Request Probability',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::request::probability',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'r_consequence' => [
				'table' => 'fm_request_consequence',
				'name' => 'Request Consequence',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::request::consequence',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'authorities_demands' => [
				'table' => 'fm_authorities_demands',
				'name' => 'Authorities Demands',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::authorities::demands',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					],
					[
						'name' => 'location_level',
						'type' => 'int',
						'nullable' => true,
						'filter' => true
					]
				]
			],

			'b_account' => [
				'table' => 'fm_b_account',
				'name' => 'Budget Account',
				'id' => ['name' => 'id', 'type' => 'varchar'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::account',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'category',
						'type' => 'select',
						'nullable' => true,
						'filter' => true,
						'sortable' => true,
						'values_def' => [
							'valueset' => false,
							'method' => 'property.bogeneric.get_list',
							'get_single_value' => 'property.sogeneric.get_name',
							'method_input' => ['type' => 'b_account_category', 'selected' => '##category##']
						]
					]
				]
			],

			'b_account_category' => [
				'table' => 'fm_b_account_category',
				'name' => 'Budget Account Category',
				'id' => ['name' => 'id', 'type' => 'varchar'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::account_category',
				'fields' => [
					[
						'name' => 'descr',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'dimb_role' => [
				'table' => 'fm_ecodimb_role',
				'name' => 'Economic Dimension B Role',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::accounting::dimb_role',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'condition_survey_status' => [
				'table' => 'fm_condition_survey_status',
				'name' => 'Condition Survey Status',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::condition_survey::status',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'request_responsible_unit' => [
				'table' => 'fm_request_responsible_unit',
				'name' => 'Request Responsible Unit',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::request::responsible_unit',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			],

			'ticket_priority' => [
				'table' => 'fm_tts_priority',
				'name' => 'Ticket Priority',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.ticket',
				'menu_selection' => 'property::helpdesk::priority',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'rank',
						'type' => 'int',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					]
				]
			],

			'external_com_type' => [
				'table' => 'fm_external_com_type',
				'name' => 'External Communication Type',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'acl_app' => 'property',
				'acl_location' => '.admin',
				'menu_selection' => 'admin::property::communication::external_type',
				'fields' => [
					[
						'name' => 'name',
						'type' => 'varchar',
						'nullable' => false,
						'filter' => true,
						'sortable' => true
					],
					[
						'name' => 'descr',
						'type' => 'text',
						'nullable' => true
					]
				]
			]
		];
	}
}
