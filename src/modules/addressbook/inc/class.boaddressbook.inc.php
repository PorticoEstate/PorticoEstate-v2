<?php

/**************************************************************************\
 * phpGroupWare - boaddressbook                                             *
 * http://www.phpgroupware.org                                              *
 * This program is part of the GNU project, see http://www.gnu.org/         *
 *                                                                          *
 * Copyright 2003 Free Software Foundation, Inc.                            *
 *                                                                          *
 * Originally Written by Jonathan Alberto Rivera Gomez - jarg at co.com.mx  *
 * Current Maintained by Jonathan Alberto Rivera Gomez - jarg at co.com.mx  *
 * --------------------------------------------                             *
 * Development of this application was funded by http://www.sogrp.com       *
 * --------------------------------------------                             *
 *  This program is Free Software; you can redistribute it and/or modify it *
 *  under the terms of the GNU General Public License as published by the   *
 *  Free Software Foundation; either version 2 of the License, or (at your  *
 *  option) any later version.                                              *
  \**************************************************************************/

/* $Id$ */

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;



class boaddressbook
{
	var $debug = False;
	var $so;
	var $rights;
	var $grants;
	var $comm_type;
	var $addr_type;
	var $note_type;
	var $tab_main_persons;
	var $tab_main_organizations;
	var $use_session = False;
	var $start;
	var $limit;
	var $query;
	var $qfield;
	var $sort;
	var $order;
	var $filter;
	var $cat_id;
	var $total;
	var $bday_internformat, $contact_type, $comm_descr, $negative_responses;
	var $public_functions = array(
		'add_vcard' => true  // call from addressbook.uivcard.in to import a vcard
	);

	var $serverSettings;
	var $userSettings;
	function __construct($session = True)
	{
		$this->so = CreateObject('addressbook.soaddressbook');
		$this->rights = $this->so->rights;
		$this->grants = $this->so->grants;
		$this->contact_type = $this->so->contact_type;
		$this->comm_descr = $this->so->comm_descr;
		$this->comm_type = $this->so->comm_type;
		$this->addr_type = $this->so->addr_type;
		$this->note_type = $this->so->note_type;
		$this->tab_main_persons = $this->so->tab_main_persons;
		$this->tab_main_organizations = $this->so->tab_main_organizations;
		$this->bday_internformat = 'Y-m-d'; // use ISO 8601 for internal bday represantation
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');

	}

	function save_organizations($values)
	{
		if ($values['action'] == 'edit')
		{
			foreach ($values['comm_data'] as $k => $v)
			{
				if (trim($v) != '')
				{
					$new_comm[$k] = $v;
				}
			}

			$current_comm_data = $this->get_comm_contact_data($values['org_data']['contact_id'], '', true);

			foreach ($current_comm_data as $k => $v)
			{
				$old_comm[$v['comm_description']] =  $v['comm_data'];
			}

			$values['edit_comms'] = $this->diff_arrays($old_comm, $new_comm, 'keys');
			$values['comm_data'] = $current_comm_data;

			$current_persons = $this->get_person_orgs_data($values['org_data']['contact_id']);

			$old_persons = isset($current_persons['my_person']) ? $current_persons['my_person'] : array();
			$new_persons = isset($values['persons']) ? $values['persons'] : array();
			$values['edit_persons'] = $this->diff_arrays($old_persons, $new_persons);

			$receipt = $this->so->edit_org($values);
		}
		else
		{
			$receipt = $this->so->add_org($values);
		}

		return $receipt;
	}

	function save_persons($values)
	{
		if ($values['action'] == 'edit')
		{
			foreach ($values['comm_data'] as $k => $v)
			{
				if (trim($v) != '')
				{
					$new_comm[$k] = $v;
				}
			}

			$current_comm_data = $this->get_comm_contact_data($values['person_data']['contact_id'], '', true);

			foreach ($current_comm_data as $k => $v)
			{
				$old_comm[$v['comm_description']] =  $v['comm_data'];
			}

			$values['edit_comms'] = $this->diff_arrays($old_comm, $new_comm, 'keys');
			$values['comm_data'] = $current_comm_data;

			$current_orgs = $this->get_orgs_person_data($values['person_data']['contact_id']);

			$old_orgs = isset($current_orgs['my_orgs']) ? $current_orgs['my_orgs'] : array();
			$new_orgs = isset($values['orgs']) ? $values['orgs'] : array();
			$values['edit_orgs'] = $this->diff_arrays($old_orgs, $new_orgs);

			$receipt = $this->so->edit_person($values);
		}
		else
		{
			$receipt = $this->so->add_person($values);
		}

		return $receipt;
	}

	/*************************************************************\
	 * Person Functions Section                                    *
		\*************************************************************/

	/**
	 * Call to add_person function in soaddressbook object
	 *
	 * @param array $fields The array with all data of the person
	 * @return integer The person_id of the new person
	 */
	function add_person($fields)
	{
		return $this->so->add_person($fields);
	}

	/**
	 * Get the principal person data for the psrson_id what you want
	 *
	 * @param integer $person_id The person id what you want to find
	 * @param bolean $get_org Flag for get or not org_data for this person
	 * @return array The array with all data from person, this also 
	 * separate the cats and extra tab
	 */

	function get_principal_persons_data($person_id, $get_org = True)
	{
		$entry = $this->so->get_principal_persons_data($person_id, $get_org);

		$entry[0]['my_cats'] = explode(",", $entry[0]['cat_id']);

		return $entry[0];
	}

	/**
	 * Get the organizations for the  person what you want
	 *
	 * @param integer $person_i The person id what you want to find
	 * @return array The array with all organizations for this person,
	 * this also return in this array the preferred organization
	 */
	function get_orgs_person_data($person_id)
	{
		$entry = $this->so->get_organizations_by_person($person_id);
		if ($entry)
		{
			foreach ($entry as $k => $v)
			{
				if ($v['my_preferred'] == 'Y')
				{
					$entry['preferred_org'] = $v['my_org_id'];
				}
				$entry['my_orgs'][$k] = $v['my_org_id'];
			}
		}
		return $entry;
	}

	/**
	 * Get the the person data what you want
	 *
	 * @param array $fields The fields that you can see from person
	 * @param integer $limit Limit of records that you want
	 * @param integer $ofset Ofset of record that you want start
	 * @param string $orderby The field which you want order
	 * @param string $sort ASC | DESC depending what you want
	 * @param mixed $criteria All criterias what you want
	 * @param mixed $criteria_token same like $criteria but builded<br />with phpgwapi_sql_criteria class, more powerfull
	 * @return array with records
	 */
	function get_persons($fields, $start = '', $limit = '', $orderby = '', $sort = '', $criteria = '', $token_criteria = '')
	{
		$entries =  $this->so->get_persons($fields, $start, $limit, $orderby, $sort, $criteria, $token_criteria);
		$persons = array();
		if (is_array($entries))
		{
			foreach ($entries as $data)
			{
				$persons[$data['contact_id']] = $data;
			}
		}
		$this->total = $this->so->contacts->total_records;
		return $persons;
	}

	/**
	 * Edit the person data what you want
	 *
	 * @param integer $person_id The person what you want to edit
	 * @param array $fields The fields that you want
	 * @return 
	 */
	function edit_person($person_id, $fields)
	{
		$old_orgs = isset($fields['old_my_orgs']['my_orgs']) ? $fields['old_my_orgs']['my_orgs'] : array();
		$new_orgs = isset($fields['tab_orgs']['my_orgs']) ? $fields['tab_orgs']['my_orgs'] : array();
		$fields['edit_orgs'] = $this->diff_arrays($old_orgs, $new_orgs);

		$old_comm = $fields['old_comm'];
		$new_comm = $fields['tab_comms']['comm_data'];
		$fields['edit_comms'] = $this->diff_arrays($old_comm, $new_comm, 'keys');

		$old_others = $fields['old_others'];
		$new_others = $fields['others_data'];
		$fields['edit_others'] = $this->diff_arrays($old_others, $new_others, 'keys');

		return $this->so->edit_person($person_id, $fields);
	}

	//used
	function get_count_persons($criteria = '')
	{
		return $this->so->get_count_persons($criteria);
	}

	/*************************************************************\
	 * Organization Functions Section                              *
		\*************************************************************/

	/**
	 * Call to add_org function in soaddressbook object
	 *
	 * @param array $fields The array with all data of the org
	 * @return integer The org_id of the new org
	 */
	function add_org($fields)
	{
		return $this->so->add_org($fields);
	}

	/**
	 * Get the principal organization data for the org_id what you want
	 *
	 * @param integer $org_id The organization id what you want to find
	 * @return array The array with all data from person, this also 
	 * separate the cats and extra tab
	 */

	function get_principal_organizations_data($org_id)
	{
		$entry = $this->so->get_principal_organizations_data($org_id);

		$entry[0]['my_cats'] = explode(",", $entry[0]['cat_id']);

		return $entry[0];
	}
	/**
	 * Get the persons for the organization what you want
	 *
	 * @param integer $org_id The org id what you want to find
	 * @return array The array with all persons for this organization
	 */
	function get_person_orgs_data($org_id)
	{
		$entry = $this->so->get_people_by_organizations($org_id);
		if ($entry)
		{
			foreach ($entry as $k => $v)
			{
				$entry['my_person'][$k] = $v['my_person_id'];
			}
		}
		return $entry;
	}

	/**
	 * Retrieve all organizations data which you specify, this can use
	 * limit and order.
	 *
	 * @param array $fields The fields that you can see from person
	 * @param integer $limit Limit of records that you want
	 * @param integer $ofset Ofset of record that you want start
	 * @param string $orderby The field which you want order
	 * @param string $sort ASC | DESC depending what you want
	 * @param array $criteria All criterias what you want
	 * @param mixed $criteria_token same like $criteria but builded<br />with phpgwapi_sql_criteria class, more powerfull
	 * @return array with records
	 */
	function get_orgs($fields, $start = '', $limit = '', $orderby = '', $sort = '', $criteria = '', $token_criteria = '')
	{
		$orgs = array();
		$entries =  $this->so->get_orgs($fields, $start, $limit, $orderby, $sort, $criteria, $token_criteria);
		if (is_array($entries) && count($entries))
		{
			foreach ($entries as $data)
			{
				$orgs[$data['contact_id']] = $data;
			}
		}
		else
		{
			$orgs = array();
		}
		$this->total = $this->so->contacts->total_records;
		return $orgs;
	}

	/**
	 * Edit the org data what you want
	 *
	 * @param integer $org_id The org what you want to edit
	 * @param array $fields The fields that you want
	 * @return 
	 */
	function edit_org($org_id, $fields)
	{
		$old_person = $fields['old_my_person']['my_person'];
		$new_person = $fields['tab_persons']['my_person'];
		$fields['edit_persons'] = $this->diff_arrays($old_person, $new_person);

		$old_comm = $fields['old_comm'];
		$new_comm = $fields['tab_comms']['comm_data'];
		$fields['edit_comms'] = $this->diff_arrays($old_comm, $new_comm, 'keys');

		$old_others = $fields['old_others'];
		$new_others = $fields['others_data'];
		$fields['edit_others'] = $this->diff_arrays($old_others, $new_others, 'keys');

		return $this->so->edit_org($org_id, $fields);
	}

	//used
	function get_count_orgs($criteria = '')
	{
		return $this->so->get_count_orgs($criteria);
	}

	/*************************************************************\
	 * Retrive Contact Data Functions Section                      *
		\*************************************************************/

	/**
	 * Get the others fields data for this contact
	 *
	 * @param integer $contact_id The contact id what you want to find
	 * @return array The array with all others data for this contact
	 */
	function get_others_contact_data($contact_id)
	{
		return $this->so->get_others_contact_data($contact_id);
	}

	/**
	 * Get the addresses data for this contact
	 *
	 * @param integer $contact_id The contact id what you want to find
	 * @return array The array with all addresses data for this contact
	 */
	function get_addr_contact_data($contact_id, $criteria = '')
	{
		return $this->so->get_addr_contact_data($contact_id, $criteria);
	}

	/**
	 * Get the communications media data for this contact
	 *
	 * @param integer $contact_id The contact id what you want to find
	 * @return array The array with all communications media for this contact
	 */
	function get_comm_contact_data($contacts, $fields_comms = '', $simple = False)
	{
		$data = $this->so->get_comm_contact_data($contacts, $fields_comms);
		if ($simple == True)
		{
			return $data;
		}

		$comm_data = array();
		if (is_array($data))
		{
			foreach ($data as $key => $value)
			{
				$comm_data[$value['comm_contact_id']][$value['comm_description']] = $value['comm_data'];
				if ($value['comm_preferred'] == 'Y')
				{
					$comm_data[$value['comm_contact_id']]['preferred'] = $value['comm_description'];
				}
			}
		}
		return $comm_data;
	}

	//used
	function get_sub_cats($cat_to_find)
	{
		return $this->so->get_sub_cats($cat_to_find);
	}

	//used
	function get_persons_by_cat($cats)
	{
		return $this->so->get_persons_by_cat($cats);
	}

	//used
	function get_type_contact($contact_id)
	{
		return $this->so->get_type_contact($contact_id);
	}

	/*************************************************************\
	 * Others Contacts Actions Functions Section                   *
		\*************************************************************/

	//used
	function delete($contact_id, $contact_type)
	{
		return $this->so->delete($contact_id, $contact_type);
	}

	//used
	function copy_contact($contact_id)
	{
		return $this->so->copy_contact($contact_id);
	}

	/**
	 * Criteria for index primordially
	 *
	 * return string criteria for search.
	 */
	function criteria_contacts($access, $category, $field, $pattern, $show_fields)
	{
		$fields = array();
		if ($pattern)
		{
			switch ($field)
			{
				case 'person':
					$fields = array(
						'per_full_name',
						'per_prefix',
						'per_suffix',
						'per_initials'
					);
					break;

				case 'org':
					$fields = array('org_name');
					break;

				case 'comms':
					$fields['comm_media'] = array();
					foreach ($this->comm_descr as $data)
					{
						$fields['comm_media'][] = $data['comm_description'];
					}
					break;

				case 'location':
					$fields = array(
						'addr_add1',
						'addr_add2',
						'addr_add3',
						'addr_city',
						'addr_state',
						'addr_postal_code',
						'addr_country'
					);
					break;

				case 'other':
					$fields = array('other_value');
					break;

				case 'note':
					$fields = array('note_text');
					break;

				default:
					$fields = array();
			}
		}
		return $this->so->criteria_contacts($this->userSettings['account_id'], $access, $category, $fields, $pattern, $show_fields);
	}

	/**
	 * Delete the specified communication media.
	 * 
	 * @param integer|array $id Key of the comm media what you want
	 */
	function delete_specified_comm($id)
	{
		return $this->so->delete_specified_comm($id);
	}

	/**
	 * Delete the specified address.
	 * 
	 * @param integer|array $id Key of the address what you want
	 */
	function delete_specified_location($id)
	{
		return $this->so->delete_specified_location($id);
	}
	/**
	 * Delete the specified others field.
	 * 
	 * @param integer|array $id Key of the other field what you want
	 */
	function delete_specified_other($id, $action = '')
	{
		return $this->so->delete_specified_other($id, $action);
	}

	/**
	 * Delete the specified note.
	 * 
	 * @param integer|array $id Key of the note what you want
	 */
	function delete_specified_note($id)
	{
		return $this->so->delete_specified_note($id);
	}

	function get_insert_others($contact_id, $fields, $action = '')
	{
		return $this->so->add_others($fields, $contact_id, $action);
	}

	function get_update_others($contact_id, $fields)
	{
		unset($fields['key_other_id']);
		return $this->so->edit_other($contact_id, $fields);
	}

	function get_insert_comm($contact_id, $fields)
	{
		return $this->so->add_communication_media($fields, $contact_id);
	}

	function get_update_comm($contact_id, $fields)
	{
		unset($fields['key_comm_id']);
		return $this->so->edit_comms($contact_id, $fields);
	}

	function get_insert_addr($contact_id, $fields)
	{
		return $this->so->add_location($fields, $contact_id);
	}

	function get_update_addr($contact_id, $fields)
	{
		unset($fields['key_addr_id']);
		return $this->so->edit_location($contact_id, $fields);
	}

	/*************************************************************\
	 * Search Functions Section                                    *
		\*************************************************************/

	//used
	function search_contact_type_id($id)
	{
		return $this->so->search_contact_type_id($id);
	}

	/**
	 * Search location id in location catalog
	 *
	 * @param integer $id The location id to find
	 * @return string The description of id
	 */
	function search_location_type_id($id)
	{
		return $this->so->search_location_type_id($id);
	}

	/*************************************************************\
	 * Check ACL Functions Section                                 *
		\*************************************************************/

	/**
	 * Check if the contact has add permissions.
	 * 
	 * @param integer $contact_id The contact_id which you want to check
	 * @param integer $owner_id The owner_id of the contact which you want to check
	 */
	function check_add($contact_id, $owner_id = '')
	{
		return $this->so->check_add($contact_id, $owner_id);
	}

	/**
	 * Check if the contact has edit permissions.
	 * 
	 * @param integer $contact_id The contact_id which you want to check
	 * @param integer $owner_id The owner_id of the contact which you want to check
	 */
	function check_edit($contact_id, $owner_id = '')
	{
		return $this->so->check_edit($contact_id, $owner_id);
	}

	/**
	 * Check if the contact has read permissions.
	 * 
	 * @param integer $contact_id The contact_id which you want to check
	 * @param integer $owner_id The owner_id of the contact which you want to check
	 */
	function check_read($contact_id, $owner_id = '')
	{
		return $this->so->check_read($contact_id, $owner_id);
	}

	/**
	 * Check if the contact has delete permissions.
	 * 
	 * @param integer $contact_id The contact_id which you want to check
	 * @param integer $owner_id The owner_id of the contact which you want to check
	 */
	function check_delete($contact_id, $owner_id = '')
	{
		return $this->so->check_delete($contact_id, $owner_id);
	}

	/*************************************************************\
	 * Others Functions Section                                    *
		\*************************************************************/

	//used
	function add_vcard()
	{
		if (!is_array($_FILES['uploadedfile']) || ($_FILES['uploadedfile']['error'] != UPLOAD_ERR_OK))
		{
			Cache::message_set('You must select a vcard. (*.vcf)', 'error');

			phpgw::redirect_link('/index.php', array('menuaction' => 'addressbook.uivcard.in'));
		}
		else
		{
			$uploadedfile = $_FILES['uploadedfile']['tmp_name'];
			$uploaddir = $this->serverSettings['temp_dir'] . '/';

			srand((float)microtime() * 1000000);
			$random_number = rand(100000000, 999999999);
			$newfilename = md5($_FILES['uploadedfile'] . $_FILES['uploadedfile']['name']
				. time() . $_SERVER['REMOTE_ADDR'] . $random_number);

			move_uploaded_file($uploadedfile, $uploaddir . $newfilename);
			$ftp = fopen($uploaddir . $newfilename . '.info', 'w');
			fputs($ftp, $_FILES['uploadedfile']['type'] . "\n" . $_FILES['uploadedfile']['name'] . "\n");
			fclose($ftp);

			$filename = $uploaddir . $newfilename;

			$vcard = CreateObject('phpgwapi.vcard');
			$entry = $vcard->in_file($filename);
			/* _debug_array($entry);exit; */
			$entry['owner'] = $this->userSettings['account_id'];
			$entry['access'] = 'private';
			/* _debug_array($entry);exit; */

			$ab_id = $this->so->contact_import($entry);

			/* Delete the temp file. */
			unlink($filename);
			unlink($filename . '.info');

			Cache::message_set('vcard has been added', 'message');

			phpgw::redirect_link('/index.php', array('menuaction' => 'addressbook.uiaddressbook_persons.view', 'ab_id' => $ab_id, 'vcard' => 1));
		}
	}

	//used
	function add_email($name, $email)
	{
		return $this->so->add_contact_with_email($name, $email);
	}

	/*************************************************************\
	 * Preferences Functions Section                               *
		\*************************************************************/

	//used
	function save_preferences($prefs, $other, $qfields, $fcat_id)
	{
		$preferences = createObject('phpgwapi.preferences');

		if (is_array($prefs))
		{
			if (is_array($qfields))
			{
				foreach ($qfields as $pref => $x)
				{
					/* echo '<br />checking: ' . $pref . '=' . $prefs[$pref]; */
					if ($prefs[$pref] == 'on')
					{
						$preferences->add('addressbook', $pref, 'addressbook_on');
					}
					else
					{
						$preferences->delete('addressbook', $pref);
					}
				}
			}
		}

		if (is_array($other))
		{
			$preferences->delete('addressbook', 'mainscreen_showbirthdays');
			if ($other['mainscreen_showbirthdays'])
			{
				$preferences->add('addressbook', 'mainscreen_showbirthdays', True);
			}

			$preferences->delete('addressbook', 'default_filter');
			if ($other['default_filter'])
			{
				$preferences->add('addressbook', 'default_filter', $other['default_filter']);
			}

			$preferences->delete('addressbook', 'autosave_category');
			if ($other['autosave_category'])
			{
				$preferences->add('addressbook', 'autosave_category', True);
			}
		}

		$preferences->delete('addressbook', 'default_category');
		$preferences->add('addressbook', 'default_category', $fcat_id);

		$preferences->save_repository(True);
		/* _debug_array($prefs);exit; */
		phpgw::redirect_link('/preferences/index.php');
	}

	//used
	function get_preferences_for_organizations()
	{
		return $this->so->read_preferences($this->tab_main_organizations);
	}

	//used
	function get_preferences_for_persons()
	{
		return $this->so->read_preferences($this->tab_main_persons);
	}

	//used
	function get_generic_preferences()
	{
		return false;
	}

	/*************************************************************\
	 * Misc Functions Section                                      *
		\*************************************************************/

	//used
	function get_columns_to_display($contact_type)
	{
		return $this->so->read_preferences($contact_type);
	}

	//used
	function display_name($column)
	{
		$newcol = $this->so->display_name($column);
		return $newcol != '*' ? $newcol : $column;
	}

	//used
	function execute_queries($queries)
	{
		return $this->so->execute_queries($queries);
	}

	//used
	function diff_arrays($old_array = array(), $new_array = array(), $type = 'values')
	{
		$result = array();
		if (!is_array($old_array))
		{
			$old_array =  array();
		}

		if (!is_array($new_array))
		{
			$new_array =  array();
		}

		if ($type == 'values')
		{
			$result['delete'] = array_diff($old_array, $new_array);
			$result['insert'] = array_diff($new_array, $old_array);
			$result['edit'] = array_intersect($old_array, $new_array);
		}
		elseif ($type == 'keys')
		{
			$bc_old_array = $old_array;
			$bc_new_array = $new_array;

			$delete = array_diff(array_keys($old_array), array_keys($new_array));
			$insert = array_diff(array_keys($new_array), array_keys($old_array));
			$edit = array_intersect(array_keys($old_array), array_keys($new_array));

			foreach ($delete as $key)
			{
				$result['delete'][$key] = $bc_old_array[$key];
			}
			foreach ($insert as $key)
			{
				$result['insert'][$key] = $bc_new_array[$key];
			}
			foreach ($edit as $key)
			{
				$result['edit'][$key] = $bc_new_array[$key];
			}
		}

		return $result;
	}

	function _debug_sqsof()
	{
		$data = array(
			'start'  => $this->start,
			'limit'  => $this->limit,
			'query'  => $this->query,
			'sort'   => $this->sort,
			'order'  => $this->order,
			'filter' => $this->filter,
			'cat_id' => $this->cat_id,
			'qfield' => $this->qfield
		);
		echo '<br />BO:';
		_debug_array($data);
	}

	//used
	function can_delete($contact_id, $owner = '')
	{
		if (
			$this->so->contacts->check_perms($this->grants[$owner], ACL_DELETE) ||
			$owner == $this->userSettings['account_id']
		)
		{
			return True;
		}
		else
		{
			return False;
		}
	}

	//used
	function can_delete_hooks($hook_response)
	{
		$negative_apps = false;
		foreach ($hook_response as $application => $response)
		{
			if (is_array($response))
			{
				if (!$response['can_delete'])
				{
					$negative_apps[$application] = $response['reason'];
				}
			}
		}
		if (!$negative_apps)
		{
			return true;
		}

		$this->negative_responses = $negative_apps;
	}
}
