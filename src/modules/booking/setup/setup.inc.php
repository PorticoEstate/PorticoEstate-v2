<?php
$setup_info['booking']['name'] = 'booking';
$setup_info['booking']['version'] = '0.2.114';
$setup_info['booking']['app_order'] = 9;
$setup_info['booking']['enable'] = 1;
$setup_info['booking']['app_group'] = 'office';

$setup_info['booking']['views'] = [
	'bb_document_view',
	'bb_application_association',
	'bb_article_view',
];

$setup_info['booking']['tables'] = [
	'bb_multi_domain',
	'bb_payment_method',
	'bb_article_category',
	'bb_service',
	'bb_facility',
	'bb_activity',
	'bb_agegroup',
	'bb_targetaudience',
	'bb_rescategory',
	'bb_rescategory_activity',
	'bb_e_lock_system',
	'bb_building',
	'bb_organization',
	'bb_group',
	'bb_user',
	'bb_office',
	'bb_documentation',
	'bb_system_message',
	'bb_account_code_set',
	'bb_customer',
	'bb_resource',
	'bb_delegate',
	'bb_season',
	'bb_application',
	'bb_event',
	'bb_booking',
	'bb_block',
	'bb_allocation',
	'bb_article_mapping',
	'bb_article_group',
	'bb_article_price',
	'bb_article_price_reduction',
	'bb_purchase_order',
	'bb_payment',
	'bb_completed_reservation_export',
	'bb_completed_reservation_export_file',
	'bb_completed_reservation',
	'bb_participant',
	'bb_participant_limit',
	'bb_application_comment',
	'bb_application_date',
	'bb_application_targetaudience',
	'bb_application_agegroup',
	'bb_application_resource',
	'bb_booking_targetaudience',
	'bb_booking_agegroup',
	'bb_booking_resource',
	'bb_booking_cost',
	'bb_season_boundary',
	'bb_season_resource',
	'bb_event_comment',
	'bb_event_date',
	'bb_event_targetaudience',
	'bb_event_agegroup',
	'bb_event_resource',
	'bb_event_cost',
	'bb_allocation_cost',
	'bb_allocation_resource',
	'bb_wtemplate_alloc',
	'bb_wtemplate_alloc_resource',
	'bb_resource_activity',
	'bb_resource_facility',
	'bb_resource_e_lock',
	'bb_building_resource',
	'bb_document_building',
	'bb_document_resource',
	'bb_document_application',
	'bb_document_organization',
	'bb_permission',
	'bb_permission_root',
	'bb_organization_contact',
	'bb_group_contact',
	'bb_contact_person',
	'bb_completed_reservation_resource',
	'bb_completed_reservation_export_configuration',
	'bb_billing_sequential_number_generator',
	'bb_office_user',
	'bb_resource_service',
	'bb_purchase_order_line'
];

$setup_info['booking']['description'] = 'Bergen kommune booking';

$setup_info['booking']['author'][] = [
	'name' => 'Redpill Linpro',
	'email' => 'info@redpill-linpro.com'
];

/* Dependencies for this app to work */
$setup_info['booking']['depends'][] = [
	'appname' => 'phpgwapi',
	'versions' => ['0.9.17', '0.9.18']
];

$setup_info['booking']['depends'][] = [
	'appname' => 'property',
	'versions' => ['0.9.17']
];

/* The hooks this app includes, needed for hooks registration */
$setup_info['booking']['hooks'] = [
	'settings',
	'menu' => 'booking.menu.get_menu',
	'activity_add' => 'booking.hook_helper.activity_add',
	'activity_delete' => 'booking.hook_helper.activity_delete',
	'activity_edit' => 'booking.hook_helper.activity_edit',
	'after_navbar'	=> 'booking.hook_helper.after_navbar',
	'resource_add' => 'booking.hook_helper.resource_add',
	'home' => 'booking.hook_helper.home',
	'config'
];
