<?php

namespace App\modules\property\inc;

/**
 * Builds the XSL view-model payload for entity edit/view pages.
 */
class EntityEditPagePresenter
{
	/**
	 * Incremental presenter seam for property_uientity::edit().
	 *
	 * @param array $payload Existing legacy payload.
	 * @param array $context Minimal context used for derived values.
	 * @return array
	 */
	public function present(array $payload, array $context = array()): array
	{
		if (!isset($payload['lang_no_cat']))
		{
			$payload['lang_no_cat'] = lang('no category');
		}

		if (!isset($payload['lang_cat_statustext']))
		{
			$payload['lang_cat_statustext'] = lang('Select the category. To do not use a category select NO CATEGORY');
		}

		if (!isset($payload['lang_entity']))
		{
			$payload['lang_entity'] = lang('entity');
		}

		if (!isset($payload['lang_category']))
		{
			$payload['lang_category'] = lang('category');
		}

		if (!isset($payload['lang_none']))
		{
			$payload['lang_none'] = lang('None');
		}

		if (!isset($payload['lang_id']))
		{
			$payload['lang_id'] = lang('ID');
		}

		if (!isset($payload['lang_history']))
		{
			$payload['lang_history'] = lang('history');
		}

		if (!isset($payload['lang_history_help']))
		{
			$payload['lang_history_help'] = lang('history of this attribute');
		}

		if (!isset($payload['lang_history_date_statustext']))
		{
			$payload['lang_history_date_statustext'] = lang('Enter the date for this reading');
		}

		if (!isset($payload['lang_date']))
		{
			$payload['lang_date'] = lang('date');
		}

		if (!isset($payload['lang_start_project']))
		{
			$payload['lang_start_project'] = lang('start project');
		}

		if (!isset($payload['lang_start_ticket']))
		{
			$payload['lang_start_ticket'] = lang('start ticket');
		}

		if (!isset($payload['documents']) && array_key_exists('get_docs', $context))
		{
			$payload['documents'] = $context['get_docs'] ? 1 : 0;
		}

		if (!isset($payload['lean']) && array_key_exists('lean', $context))
		{
			$payload['lean'] = $context['lean'] ? 1 : 0;
		}

		if (!isset($payload['multiple_uploader']) && isset($context['id']))
		{
			$payload['multiple_uploader'] = $context['id'] ? true : '';
		}

		if (!isset($payload['multi_upload_parans'])
			&& isset($context['id'], $context['entity_id'], $context['cat_id'], $context['type']))
		{
			$payload['multi_upload_parans'] = "{menuaction:'property.uientity.build_multi_upload_file',"
				. "id:'{$context['id']}',"
				. "_entity_id:'{$context['entity_id']}',"
				. "_cat_id:'{$context['cat_id']}',"
				. "_type:'{$context['type']}'}";
		}

		if (!isset($payload['multi_upload_action'])
			&& isset($context['id'], $context['entity_id'], $context['cat_id'], $context['type']))
		{
			$payload['multi_upload_action'] = \phpgw::link(
				'/index.php',
				array(
					'menuaction' => 'property.uientity.handle_multi_upload_file',
					'id' => $context['id'],
					'entity_id' => $context['entity_id'],
					'cat_id' => $context['cat_id'],
					'type' => $context['type']
				)
			);
		}

		if (!isset($payload['cancel_url']) && isset($context['link_index']))
		{
			$payload['cancel_url'] = \phpgw::link('/index.php', $context['link_index']);
		}

		if (!isset($payload['done_action'])
			&& isset($context['entity_id'], $context['cat_id'], $context['type']))
		{
			$payload['done_action'] = \phpgw::link('/index.php', array(
				'menuaction' => 'property.uientity.index',
				'entity_id' => $context['entity_id'],
				'cat_id' => $context['cat_id'],
				'type' => $context['type']
			));
		}

		if (!isset($payload['get_files_java_url'])
			&& isset($context['type'], $context['entity_id'], $context['cat_id'], $context['id']))
		{
			$payload['get_files_java_url'] = '/property/entity/' . urlencode($context['type'])
				. '/' . (int) $context['entity_id']
				. '/' . (int) $context['cat_id']
				. '/' . (int) $context['id'] . '/files';
		}

		return $payload;
	}

	/**
	 * Build the payload used by entity.xsl for edit/view rendering.
	 *
	 * @param array $input Precomputed page context.
	 * @return array
	 */
	public function build(array $input): array
	{
		return array(
			'location_checklists' => $input['location_checklists'] ?? array(),
			'datatable_def' => $input['datatable_def'] ?? array(),
			'repeat_types' => array('options' => $input['repeat_types'] ?? array()),
			'controller' => !empty($input['enable_controller']) && !empty($input['id']),
			'check_lst_time_span' => array('options' => $input['check_lst_time_span'] ?? array()),
			'cancel_url' => $input['cancel_url'] ?? '',
			'enable_bulk' => $input['category']['enable_bulk'] ?? null,
			'org_unit' => $input['category']['org_unit'] ?? null,
			'value_org_unit_id' => $input['values']['org_unit_id'] ?? null,
			'value_org_unit_name' => $input['values']['org_unit_name'] ?? null,
			'value_org_unit_name_path' => $input['values']['org_unit_name_path'] ?? null,
			'value_location_id' => $input['value_location_id'] ?? null,
			'link_pdf' => $input['link_pdf'] ?? '',
			'start_project' => $input['category']['start_project'] ?? null,
			'lang_start_project' => lang('start project'),
			'project_link' => $input['project_link'] ?? '',
			'add_to_project_link' => $input['add_to_project_link'] ?? '',
			'start_ticket' => $input['category']['start_ticket'] ?? null,
			'lang_start_ticket' => lang('start ticket'),
			'ticket_link' => $input['ticket_link'] ?? '',
			'fileupload' => $input['category']['fileupload'] ?? null,
			'checklist_count' => $input['category']['checklist_count'] ?? null,
			'link_view_file' => $input['link_view_file'] ?? '',
			'files' => $input['values']['files'] ?? '',
			'multiple_uploader' => !empty($input['id']) ? true : '',
			'multi_upload_parans' => "{menuaction:'property.uientity.build_multi_upload_file',"
				. "id:'{$input['id']}',"
				. "_entity_id:'{$input['entity_id']}',"
				. "_cat_id:'{$input['cat_id']}',"
				. "_type:'{$input['type']}'}",
			'multi_upload_action' => \phpgw::link(
				'/index.php',
				array(
					'menuaction' => 'property.uientity.handle_multi_upload_file',
					'id' => $input['id'],
					'entity_id' => $input['entity_id'],
					'cat_id' => $input['cat_id'],
					'type' => $input['type']
				)
			),
			'value_origin' => $input['values']['origin_data'] ?? '',
			'value_origin_type' => $input['origin'] ?? '',
			'value_origin_id' => $input['origin_id'] ?? '',
			'lang_no_cat' => lang('no category'),
			'lang_cat_statustext' => lang('Select the category. To do not use a category select NO CATEGORY'),
			'select_name' => 'cat_id',
			'cat_list' => $input['cat_list'] ?? '',
			'location_code' => $input['location_code'] ?? '',
			'lookup_tenant' => $input['lookup_tenant'] ?? false,
			'lang_entity' => lang('entity'),
			'entity_name' => $input['entity']['name'] ?? '',
			'lang_category' => lang('category'),
			'category_name' => $input['category']['name'] ?? '',
			'msgbox_data' => $input['msgbox_html'] ?? '',
			'attributes_group' => $input['attributes'] ?? array(),
			'attributes_general' => array('attributes' => $input['attributes_general'] ?? array()),
			'lookup_functions' => $input['values']['lookup_functions'] ?? '',
			'lang_none' => lang('None'),
			'location_data2' => $input['location_data'] ?? array(),
			'lookup_type' => $input['lookup_type'] ?? '',
			'mode' => $input['mode'] ?? 'edit',
			'form_action' => $input['form_action'] ?? '',
			'done_action' => \phpgw::link('/index.php', array(
				'menuaction' => 'property.uientity.index',
				'entity_id' => $input['entity_id'],
				'cat_id' => $input['cat_id'],
				'type' => $input['type']
			)),
			'lang_id' => lang('ID'),
			'value_id' => $input['values']['id'] ?? null,
			'value_num' => $input['values']['num'] ?? null,
			'error_flag' => $input['error_id'] ?? '',
			'lang_history' => lang('history'),
			'lang_history_help' => lang('history of this attribute'),
			'lang_history_date_statustext' => lang('Enter the date for this reading'),
			'lang_date' => lang('date'),
			'textareacols' => $input['textareacols'] ?? 40,
			'textarearows' => $input['textarearows'] ?? 6,
			'tabs' => $input['tabs'] ?? '',
			'active_tab' => $input['active_tab'] ?? '',
			'integration' => $input['integration'] ?? array(),
			'doc_type_filter' => array('options' => $input['doc_type_filter'] ?? array()),
			'documents' => !empty($input['get_docs']) ? 1 : 0,
			'lean' => !empty($input['lean']) ? 1 : 0,
			'entity_group_list' => array('options' => $input['entity_group_list'] ?? array()),
			'entity_group_name' => $input['entity_group_name'] ?? '',
			'validator' => $input['validator'] ?? '',
			'content_images' => $input['content_images'] ?? array(),
			'get_files_java_url' => '/property/entity/' . urlencode($input['type'])
				. '/' . (int) $input['entity_id']
				. '/' . (int) $input['cat_id']
				. '/' . (int) $input['id'] . '/files',
		);
	}
}