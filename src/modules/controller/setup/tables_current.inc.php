<?php
$phpgw_baseline = array(
	'controller_control_category' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => False),
			'control_id' => array('type' => 'int', 'precision' => 4, 'nullable' => False),
			'name' => array('type' => 'varchar', 'precision' => '255', 'nullable' => False),
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => False),
			'title' => array('type' => 'varchar', 'precision' => '100', 'nullable' => False),
			'description' => array('type' => 'text', 'nullable' => True),
			'start_date' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'end_date' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'procedure_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'requirement_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'costResponsibility_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'responsibility_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'control_area_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'repeat_type' => array('type' => 'int', 'precision' => 2, 'nullable' => True),
			'repeat_interval' => array('type' => 'int', 'precision' => 2, 'nullable' => True),
			'enabled' => array('type' => 'int', 'precision' => 2, 'nullable' => True),
			'ticket_cat_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'report_intro' => array('type' => 'text', 'nullable' => True),
			'send_notification_subject' => array('type' => 'text', 'nullable' => True),
			'send_notification_content' => array('type' => 'text', 'nullable' => True),
			'responsible_organization' => array('type' => 'text', 'nullable' => True),
			'responsible_logo' => array('type' => 'text', 'nullable' => True),
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_item_list' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => False),
			'control_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'control_item_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'order_nr' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_item' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => False),
			'title' => array('type' => 'varchar', 'precision' => '255', 'nullable' => false),
			'required' => array('type' => 'bool', 'nullable' => true, 'default' => 'false'),
			'what_to_do' => array('type' => 'text', 'nullable' => true),
			'how_to_do' => array('type' => 'text', 'nullable' => true),
			'control_group_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'control_area_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'type' => array('type' => 'varchar', 'precision' => 255, 'nullable' => True),
			'include_condition_degree' => array('type' => 'bool', 'nullable' => true, 'default' => 'false'),
			'include_counter_measure' => array('type' => 'bool', 'nullable' => true, 'default' => 'false'),
			'report_summary' => array('type' => 'bool', 'nullable' => true, 'default' => 'false'),
			'include_regulation_reference' => array('type' => 'bool', 'nullable' => true, 'default' => 'false'),
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_check_item' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => False),
			'control_item_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'check_list_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True)
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_check_list' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => False),
			'control_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'status' => array('type' => 'int', 'precision' => '2', 'nullable' => false),
			'comment' => array('type' => 'text', 'nullable' => True),
			'deadline' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'original_deadline' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'planned_date' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'completed_date' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'component_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'serie_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'location_code' => array('type' => 'varchar', 'precision' => 30, 'nullable' => True),
			'location_id' => array('type' => 'int', 'precision' => 4, 'nullable' => true),
			'num_open_cases' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'num_pending_cases' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'num_corrected_cases' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'assigned_to' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'billable_hours' => array('type' => 'decimal', 'precision' => '20', 'scale' => '2', 'nullable' => True),
			'cat_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'dispatched' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_check_list_completed_item' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => false),
			'check_list_id' => array('type' => 'int', 'precision' => 4, 'nullable' => false),
			'location_id' => array('type' => 'int', 'precision' => 4, 'nullable' => false),
			'item_id' => array('type' => 'int', 'precision' => 4, 'nullable' => false),
			'completed_ts' => array('type' => 'int', 'precision' => 8, 'nullable' => false),
			'modified_by' 	=> array('type' => 'int', 'precision' => 4, 'nullable' => True),
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_procedure' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => False),
			'title' => array('type' => 'varchar', 'precision' => 255, 'nullable' => False),
			'purpose' => array('type' => 'text', 'nullable' => True),
			'responsibility' => array('type' => 'text', 'nullable' => True),
			'description' => array('type' => 'text', 'nullable' => True),
			'reference' => array('type' => 'text', 'nullable' => True),
			'attachment' => array('type' => 'varchar', 'precision' => 255, 'nullable' => True),
			'start_date' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'end_date' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'procedure_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'revision_no' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'revision_date' => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'control_area_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'modified_date'	 => array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'modified_by' 	=> array('type' => 'int', 'precision' => 4, 'nullable' => True),
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_group' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'precision' => 4, 'nullable' => False),
			'group_name' => array('type' => 'varchar', 'precision' => '255', 'nullable' => false),
			'procedure_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'control_area_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'building_part_id' => array('type' => 'varchar', 'precision' => 30, 'nullable' => True),
			'component_location_id' => array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'component_criteria'   => array('type' => 'text', 'nullable' => true)
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_group_list' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'nullable' => false),
			'control_id' => array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'control_group_id' => array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'order_nr' => array('type' => 'int', 'precision' => 4, 'nullable' => true),
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_location_list' => array(
		'fd' => array(
			'id' => array('type' => 'auto', 'nullable' => false),
			'control_id' => array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'location_code' => array('type' => 'varchar', 'precision' => '30', 'nullable' => false)
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_component_list' => array(
		'fd' => array(
			'id' 				=> array('type' => 'auto', 'nullable' => false),
			'control_id' 		=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'location_id'		=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'component_id'		=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'enabled'			=> array('type' => 'int', 'precision' => 2, 'nullable' => True)

		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_serie' => array(
		'fd' => array(
			'id'					=> array('type' => 'auto', 'nullable' => false),
			'control_relation_id'	=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'control_relation_type'	=> array('type' => 'varchar', 'precision' => '10', 'nullable' => false),
			'assigned_to'			=> array('type' => 'int', 'precision' => '4', 'nullable' => true),
			'start_date'			=> array('type' => 'int', 'precision' => '8', 'nullable' => true),
			'repeat_type'			=> array('type' => 'int', 'precision' => '2', 'nullable' => true),
			'repeat_interval'		=> array('type' => 'int', 'precision' => '4', 'nullable' => true),
			'service_time'			=> array('type' => 'decimal', 'precision' => '20', 'scale' => '2', 'nullable' => True, 'default' => '0.00'),
			'controle_time'			=> array('type' => 'decimal', 'precision' => '20', 'scale' => '2', 'nullable' => True, 'default' => '0.00'),
			'enabled'				=> array('type' => 'int', 'precision' => 2, 'nullable' => True, 'default' => 1)
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_serie_history' => array(
		'fd' => array(
			'id'					=> array('type' => 'auto', 'nullable' => false),
			'serie_id'				=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'assigned_to'			=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'assigned_date'			=> array('type' => 'int', 'precision' => '8', 'nullable' => false),
		),
		'pk' => array('id'),
		'fk' => array('controller_control_serie' => array('serie_id' => 'id')),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_group_component_list' => array(
		'fd' => array(
			'id' 								=> array('type' => 'auto', 'nullable' => false),
			'control_group_id' 	=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'location_id' 			=> array('type' => 'int', 'precision' => '4', 'nullable' => false)
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_document_types' => array(
		'fd' => array(
			'id' 		=> array('type' => 'auto', 'nullable' => false),
			'title' => array('type' => 'varchar', 'precision' => '255', 'nullable' => false)
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_document' => array(
		'fd' => array(
			'id'			=> array('type' => 'auto', 'nullable' => false),
			'name'			=> array('type' => 'varchar', 'precision' => '255', 'nullable' => false),
			'procedure_id'  => array('type' => 'int', 'precision' => '4', 'nullable' => true),
			'title'			=> array('type' => 'varchar', 'precision' => '255', 'nullable' => true),
			'description'   => array('type' => 'text', 'nullable' => true),
			'type_id'       => array('type' => 'int', 'precision' => '4', 'nullable' => false)
		),
		'pk' => array('id'),
		'fk' => array(
			'controller_procedure'   => array('procedure_id' => 'id'),
			'controller_document_types' => array('type_id' => 'id')
		),
		'ix' => array(),
		'uc' => array()
	),
	'controller_check_item_case' => array(
		'fd' => array(
			'id'							=> array('type' => 'auto', 'nullable' => false),
			'check_item_id'					=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'status'						=> array('type' => 'int', 'precision' => '4', 'nullable' => false),
			'measurement'					=> array('type' => 'text', 'nullable' => true),
			'regulation_reference'			=> array('type' => 'text', 'nullable' => true), //hjemmel
			'location_id'					=> array('type' => 'int', 'precision' => '4', 'nullable' => true), // representer meldingsfregisteret
			'location_item_id'				=> array('type' => 'int', 'precision' => '8', 'nullable' => true), //meldings id
			'descr'							=> array('type' => 'text', 'nullable' => true),
			'proposed_counter_measure'		=> array('type' => 'text', 'nullable' => true),
			'user_id'						=> array('type' => 'int', 'precision' => '4', 'nullable' => true),
			'entry_date'					=> array('type' => 'int', 'precision' => 8, 'nullable' => false),
			'modified_date'					=> array('type' => 'int', 'precision' => 8, 'nullable' => True),
			'modified_by'					=> array('type' => 'int', 'precision' => 4, 'nullable' => True),
			'location_code'					=> array('type' => 'varchar', 'precision' => 30, 'nullable' => True),
			'component_location_id'			=> array('type' => 'int', 'precision' => 4, 'nullable' => True), // register type
			'component_id'					=> array('type' => 'int', 'precision' => 4, 'nullable' => true), // forekomst av type
			'component_child_location_id'	=> array('type' => 'int', 'precision' => 4, 'nullable' => true), // register type
			'component_child_item_id' 		=> array('type' => 'int', 'precision' => 4, 'nullable' => true), // forekomst av type
			'condition_degree'				=> array('type' => 'int', 'precision' => 2, 'nullable' => true, 'default' => 2),
			'consequence'					=> array('type' => 'int', 'precision' => 2, 'nullable' => true, 'default' => 2),
		),
		'pk' => array('id'),
		'fk' => array('controller_check_item' => array('check_item_id' => 'id')),
		'ix' => array(),
		'uc' => array()
	),
	'controller_check_item_status' => array(
		'fd' => array(
			'id' 			=> array('type' => 'auto', 'nullable' => False),
			'name' 		=> array('type' => 'varchar', 'precision' => '50', 'nullable' => False),
			'open_' 		=> array('type' => 'int', 'precision' => '2', 'nullable' => True),
			'closed' 	=> array('type' => 'int', 'precision' => '2', 'nullable' => True),
			'pending' => array('type' => 'int', 'precision' => '2', 'nullable' => True),
			'sorting' => array('type' => 'int', 'precision' => '4', 'nullable' => True)
		),
		'pk' => array('id'),
		'ix' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_item_option' => array(
		'fd' => array(
			'id' 							=> array('type' => 'auto', 'precision' =>  4, 'nullable' => false),
			'option_value' 		=>  array('type' =>  'varchar', 'precision' =>  '255', 'nullable' =>  False),
			'control_item_id' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  True)
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_item_regulation_reference_option' => array(
		'fd' => array(
			'id'				=> array('type' => 'auto', 'precision' => 4, 'nullable' => false),
			'option_value'		=>  array('type' => 'varchar', 'precision' => '255', 'nullable' => false),
			'control_item_id'	=>  array('type' => 'int', 'precision' => 4, 'nullable' => true)
		),
		'pk' => array('id'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	),
	'controller_control_user_role' => array(
		'fd' => array(
			'control_id' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
			'part_of_town_id' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
			'user_id' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
			'roles' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
			'modified_on' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
			'modified_by' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
		),
		'pk' => array('control_id', 'user_id', 'part_of_town_id'),
		'fk' => array(
			'controller_control'   => array('control_id' => 'id'),
			'fm_part_of_town' => array('part_of_town_id' => 'id'),
			'phpgw_accounts' => array('user_id' => 'account_id')
		),
		'ix' => array(),
		'uc' => array()
	),
	'controller_check_list_inspector' => array(
		'fd' => array(
			'check_list_id' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
			'user_id' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
			'modified_on' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
			'modified_by' =>  array('type' =>  'int', 'precision' =>  4, 'nullable' =>  false),
		),
		'pk' => array('check_list_id', 'user_id'),
		'fk' => array(
			'controller_check_list'   => array('check_list_id' => 'id'),
			'phpgw_accounts' => array('user_id' => 'account_id')
		),
		'ix' => array(),
		'uc' => array()
	),
);
