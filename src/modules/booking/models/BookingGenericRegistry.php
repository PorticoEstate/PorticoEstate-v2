<?php

namespace App\modules\booking\models;

use App\models\GenericRegistry;

/**
 * Booking-specific Generic Registry Model
 * Provides booking module registry definitions for the global GenericRegistry system
 */
class BookingGenericRegistry extends GenericRegistry
{
	/**
	 * Load booking-specific registry definitions
	 */
	protected static function loadRegistryDefinitions(): void
	{
		static::$registryDefinitions = [
			'office' => [
				'table' => 'bb_office',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					],
					[
						'name' => 'description',
						'descr' => 'Description',
						'type' => 'text',
						'nullable' => true
					]
				],
				'name' => 'Office',
				'acl_app' => 'booking',
				'acl_location' => '.office',
				'menu_selection' => 'booking::settings::office::office',
			],

			'office_user' => [
				'table' => 'bb_office_user',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'office',
						'descr' => 'Office',
						'type' => 'select',
						'filter' => true,
						'values_def' => [
							'method' => 'booking.bogeneric.get_list',
							'method_input' => ['type' => 'office']
						]
					]
				],
				'name' => 'Office User',
				'acl_app' => 'booking',
				'acl_location' => '.office.user',
			],

			'article_category' => [
				'table' => 'bb_article_category',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					]
				],
				'name' => 'Article Category',
				'acl_app' => 'booking',
				'acl_location' => '.admin',
			],

			'article_service' => [
				'table' => 'bb_service',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					],
					[
						'name' => 'description',
						'descr' => 'Description',
						'type' => 'text',
						'nullable' => true
					],
					[
						'name' => 'active',
						'descr' => 'Active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true
					]
				],
				'name' => 'Article Service',
				'acl_app' => 'booking',
				'acl_location' => '.admin',
			],

			'vendor' => [
				'table' => 'bb_vendor',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					],
					[
						'name' => 'org_name',
						'descr' => 'Organization name',
						'type' => 'varchar',
						'nullable' => true,
						'maxlength' => 255
					],
					[
						'name' => 'active',
						'descr' => 'Active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true
					]
				],
				'name' => 'Vendor',
				'acl_app' => 'booking',
				'acl_location' => '.vendor',
			],

			'document_vendor' => [
				'table' => 'bb_document_vendor',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					],
					[
						'name' => 'active',
						'descr' => 'Active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true
					]
				],
				'name' => 'Document vendor',
				'acl_app' => 'booking',
				'acl_location' => '.admin',
			],

			'permission_root' => [
				'table' => 'bb_permission_root',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'subject_name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					],
					[
						'name' => 'active',
						'descr' => 'Active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true
					]
				],
				'name' => 'Permission Subject',
				'acl_app' => 'booking',
				'acl_location' => '.admin',
			],

			'permission_role' => [
				'table' => 'bb_permission_role',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'role_name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					],
					[
						'name' => 'active',
						'descr' => 'Active',
						'type' => 'checkbox',
						'default' => 1,
						'filter' => true
					]
				],
				'name' => 'Permission Role',
				'acl_app' => 'booking',
				'acl_location' => '.admin',
			],

			'e_lock_system' => [
				'table' => 'bb_e_lock_system',
				'id' => ['name' => 'id', 'type' => 'int'],
				'fields' => [
					[
						'name' => 'name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					],
					[
						'name' => 'webservicehost',
						'descr' => 'WebService Host',
						'type' => 'varchar',
						'maxlength' => 255
					],
					[
						'name' => 'instruction',
						'descr' => 'Receipt',
						'type' => 'html',
						'nullable' => true
					],
					[
						'name' => 'sms_alert',
						'descr' => 'SMS Alert',
						'type' => 'checkbox',
						'default' => 1
					]
				],
				'name' => 'E-Lock System',
				'acl_app' => 'booking',
				'acl_location' => '.admin',
			],

			'multi_domain' => [
				'table' => 'bb_multi_domain',
				'id' => ['name' => 'id', 'type' => 'auto'],
				'fields' => [
					[
						'name' => 'name',
						'descr' => 'Name',
						'type' => 'varchar',
						'required' => true,
						'maxlength' => 255
					],
					[
						'name' => 'webservicehost',
						'descr' => 'WebService Host',
						'type' => 'varchar',
						'maxlength' => 255
					]
				],
				'name' => 'Multi Domain',
				'acl_app' => 'booking',
				'acl_location' => '.admin',
			]
		];
	}
}
