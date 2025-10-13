<?php
phpgw::import_class('booking.socommon');

class booking_soapplication extends booking_socommon
{

	function __construct()
	{
		parent::__construct(
			'bb_application',
			array(
				'id'							 => array('type' => 'int'),
				'id_string'						 => array(
					'type'		 => 'string',
					'required'	 => false,
					'default'	 => '0',
					'query'		 => true
				),
				'active'						 => array('type' => 'int'),
				'display_in_dashboard'			 => array('type' => 'int'),
				'type'							 => array('type' => 'string'),
				'status'						 => array('type' => 'string', 'required' => true),
				'secret'						 => array('type' => 'string', 'required' => true),
				'created'						 => array('type' => 'timestamp'), //,'read_callback' => 'modify_by_timezone'),
				'modified'						 => array('type' => 'timestamp'), //,'read_callback' => 'modify_by_timezone'),
				'building_name'					 => array('type' => 'string', 'required' => true, 'query' => true),
				'building_id'					 => array('type' => 'int', 'required' => true),
				'frontend_modified'				 => array('type' => 'timestamp'), //,'read_callback' => 'modify_by_timezone'),
				'owner_id'						 => array('type' => 'int', 'required' => true),
				'parent_id'						 => array('type' => 'int', 'required' => false),
				'case_officer_id'				 => array('type' => 'int', 'required' => false),
				'activity_id'					 => array('type' => 'int', 'required' => true),
				'status'						 => array('type' => 'string', 'required' => true),
				'customer_identifier_type'		 => array('type' => 'string', 'required' => true),
				'customer_ssn'					 => array('type'			 => 'string', 'query'			 => true, 'sf_validator'	 => createObject('booking.sfValidatorNorwegianSSN', array(
					'full_required' => false
				)), 'required'		 => false),
				'customer_organization_number'	 => array(
					'type'			 => 'string',
					'query'			 => true,
					'sf_validator'	 => createObject('booking.sfValidatorNorwegianOrganizationNumber', array(), array(
						'invalid' => '%field% is invalid'
					))
				),
				'owner_name'					 => array(
					'type'	 => 'string',
					'query'	 => true,
					'join'	 => array(
						'table'	 => 'phpgw_accounts',
						'fkey'	 => 'owner_id',
						'key'	 => 'account_id',
						'column' => 'account_lid'
					)
				),
				'activity_name'					 => array(
					'type'	 => 'string',
					'join'	 => array(
						'table'	 => 'bb_activity',
						'fkey'	 => 'activity_id',
						'key'	 => 'id',
						'column' => 'name'
					)
				),
				'name'							 => array('type' => 'string', 'query' => true, 'required' => true),
				'organizer'						 => array('type' => 'string', 'query' => true, 'required' => true),
				'homepage'						 => array('type' => 'string', 'query' => true, 'required' => false, 'read_callback' => 'validate_url'),
				'description'					 => array('type' => 'string', 'query' => true, 'required' => false),
				'equipment'						 => array('type' => 'string', 'query' => true, 'required' => false),
				'contact_name'					 => array('type' => 'string', 'query' => true, 'required' => true),
				'contact_email'					 => array('type'			 => 'string', 'required'		 => true, 'sf_validator'	 => createObject('booking.sfValidatorEmail', array(), array(
					'invalid' => '%field% is invalid'
				))),
				'contact_phone'					 => array('type' => 'string', 'required' => true),
				'case_officer_name'				 => array(
					'type'	 => 'string',
					'query'	 => true,
					'join'	 => array(
						'table'	 => 'phpgw_accounts',
						'fkey'	 => 'case_officer_id',
						'key'	 => 'account_id',
						'column' => 'account_lid'
					)
				),
				'audience'						 => array(
					'type'		 => 'int',
					'required'	 => true,
					'manytomany' => array(
						'table'	 => 'bb_application_targetaudience',
						'key'	 => 'application_id',
						'column' => 'targetaudience_id'
					)
				),
				'agegroups'						 => array(
					'type'		 => 'int',
					'required'	 => true,
					'manytomany' => array(
						'table'	 => 'bb_application_agegroup',
						'key'	 => 'application_id',
						'column' => array(
							'agegroup_id'	 => array('type' => 'int', 'required' => true),
							'male'			 => array('type' => 'int', 'required' => true),
							'female'		 => array('type' => 'int', 'required' => true)
						),
					)
				),
				'dates'							 => array(
					'type'		 => 'timestamp',
					'required'	 => true,
					'manytomany' => array(
						'table'	 => 'bb_application_date',
						'key'	 => 'application_id',
						'column' => array('from_', 'to_', 'id')
					)
				),
				'comments'						 => array(
					'type'		 => 'string',
					'manytomany' => array(
						'table'	 => 'bb_application_comment',
						'key'	 => 'application_id',
						'column' => array('time' => array('type' => 'timestamp', 'read_callback' => 'modify_by_timezone'), 'author', 'comment', 'type'),
						'order'	 => array('sort' => 'time', 'dir' => 'ASC')
					)
				),
				'resources'						 => array(
					'type'		 => 'int',
					'required'	 => true,
					'manytomany' => array(
						'table'	 => 'bb_application_resource',
						'key'	 => 'application_id',
						'column' => 'resource_id'
					)
				),
				'responsible_street'			 => array('type' => 'string', 'required' => true),
				'responsible_zip_code'			 => array('type' => 'string', 'required' => true),
				'responsible_city'				 => array('type' => 'string', 'required' => true),
				'session_id'					 => array('type' => 'string', 'required' => false),
				'agreement_requirements'		 => array('type' => 'string', 'required' => false),
				'external_archive_key'			 => array('type' => 'string', 'required' => false),
				'recurring_info'				 => array('type' => 'json', 'required' => false),
				'customer_organization_name'	 => array(
					'type'		 => 'string',
					'required'	 => False,
					'query'		 => true
				),
				'customer_organization_id'		 => array('type' => 'int', 'required' => False),
			)
		);
	}

	/**
	 * FIXME
	 * Dummy for documents
	 * @see booking_bocommon_authorized
	 */
	public function get_subject_roles($for_object = null, $initial_roles = array())
	{
		return null;
	}

	function validate(&$entity)
	{
		$errors = parent::validate($entity);
		# Detect and prevent loop creation
		$node_id = $entity['parent_id'];
		while ($entity['id'] && $node_id)
		{
			$next = $this->read_single($node_id);
			if ($next['id'] == $entity['parent_id'])
			{
//				$errors['parent_id'] = lang('Invalid parent application');
				break;
			}
			$node_id = $next['parent_id'];
		}
		return $errors;
	}

	protected function doValidate($entity, booking_errorstack $errors)
	{
		$now = new DateTime('now');
		$valid_dates = array();
		$valid_timespan = 0;
		$soseason = CreateObject('booking.soseason');
		// Make sure to_ > from_
		foreach ($entity['dates'] as $date)
		{
			$from_	 = new DateTime($date['from_']);
			$to_	 = new DateTime($date['to_']);

			if ($from_ < $now)
			{
				$errors['from_'] = lang('Invalid from date');
			}

			if ($from_ > $to_ || $from_ == $to_)
			{
				$errors['from_'] = lang('Invalid from date');
			}
			else if (empty($date['from_']) || empty($date['to_']))
			{
				$errors['dates'] = lang('date is required');
			}
			else
			{
				$valid_dates[] = array('from_' => $from_, 'to_' => $to_);
			}
		}
		if (strlen($entity['contact_name']) > 50)
		{
			$errors['contact_name'] = lang('Contact information name is to long. max 50 characters');
		}

		foreach ($entity['resources'] as $resource_id)
		{
			if ((int)$resource_id < 0)
			{
				continue;
			}
			$this->db->query("SELECT direct_booking FROM bb_resource WHERE id = " . (int)$resource_id, __LINE__, __FILE__);
			$this->db->next_record();
			$direct_booking = $this->db->f('direct_booking');

			if ($direct_booking && $direct_booking < time())
			{
				foreach ($valid_dates as $valid_date)
				{
					$seasons = $soseason->get_resource_seasons((int)$resource_id, $valid_date['from_']->format('Y-m-d'), $valid_date['to_']->format('Y-m-d'));

					foreach ($seasons as $season_id)
					{
						if ($soseason->timespan_within_season($season_id, $valid_date['from_'], $valid_date['to_']))
						{
							$valid_timespan++;
						}
					}
				}

				if (!$valid_timespan)
				{
					$errors['season_boundary'] = lang("This application is not within a valid season");
				}
			}
		}
	}

	function get_user_list()
	{
		$sql = "SELECT DISTINCT account_id, account_lastname, account_firstname FROM phpgw_accounts
			JOIN bb_application ON bb_application.case_officer_id = phpgw_accounts.account_id";
		$this->db->query($sql, __LINE__, __FILE__);
		$user_list = array();
		while ($this->db->next_record())
		{
			$user_list[] = array(
				'id' =>  $this->db->f('account_id'),
				'name' =>  $this->db->f('account_lastname', true) . ', ' . $this->db->f('account_firstname', true),
			);
		}
		return $user_list;
	}

	function get_building_info($id)
	{
		$id	 = (int)$id;
		$sql = "SELECT bb_building.id, bb_building.name"
			. " FROM bb_building, bb_resource, bb_application_resource, bb_building_resource"
			. " WHERE bb_building.id= bb_building_resource.building_id AND  bb_resource.id = bb_building_resource.resource_id AND bb_resource.id=bb_application_resource.resource_id AND bb_application_resource.application_id=({$id})";

		$this->db->limit_query($sql, 0, __LINE__, __FILE__, 1);
		if (!$this->db->next_record())
		{
			return False;
		}
		return array(
			'id'	 => $this->db->f('id', false),
			'name'	 => $this->db->f('name', true)
		);
	}

	//		function get_accepted($id)
	function get_rejected($id)
	{
		$sql	 = "SELECT bad.from_, bad.to_
					FROM bb_application ba, bb_application_date bad, bb_event be
					WHERE ba.id=($id)
					AND ba.id=bad.application_id
					AND ba.id=be.application_id
					AND be.from_=bad.from_
					AND be.to_=bad.to_";
		$results = array();
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$results[] = array(
				'from_'	 => $this->db->f('from_', false),
				'to_'	 => $this->db->f('to_', false)
			);
		}
		return $results;
	}

	//		function get_rejected($id)
	function get_accepted($id)
	{
		$sql	 = "SELECT bad.from_, bad.to_ FROM bb_application ba, bb_application_date bad
					WHERE ba.id=($id)
					AND ba.id=bad.application_id
					AND bad.id NOT IN (SELECT bad.id
					FROM bb_application ba, bb_application_date bad, bb_event be
					WHERE ba.id=($id)
					AND ba.id=bad.application_id
					AND ba.id=be.application_id
					AND be.from_=bad.from_
					AND be.to_=bad.to_)";
		$results = array();
		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$results[] = array(
				'from_'	 => $this->db->f('from_', false),
				'to_'	 => $this->db->f('to_', false)
			);
		}
		return $results;
	}

	function get_tilsyn_email($id)
	{
		$sql = "SELECT tilsyn_email, tilsyn_email2, email FROM bb_building where id=(select id from bb_building where name = '$id' AND active = 1)";
		$this->db->limit_query($sql, 0, __LINE__, __FILE__, 1);
		if (!$this->db->next_record())
		{
			return False;
		}
		return array(
			'email1' => $this->db->f('tilsyn_email', false),
			'email2' => $this->db->f('tilsyn_email2', false),
			'email3' => $this->db->f('email', false)
		);
	}

	function get_resource_name($ids)
	{
		// Handle both array of numbers and array of objects
		$clean_ids = array();

		if (empty($ids))
		{
			return array();
		}

		foreach ($ids as $id)
		{
			if (is_object($id))
			{
				$clean_ids[] = (int)$id->id;
			}
			else
			{
				$clean_ids[] = (int)$id;
			}
		}

		// If all IDs were invalid, return empty array
		if (empty($clean_ids))
		{
			return array();
		}

		$placeholders = str_repeat('?,', count($clean_ids) - 1) . '?';

		try
		{
			$query = "SELECT name FROM bb_resource WHERE id IN ($placeholders)";
			$stmt = $this->db->prepare($query);
			$stmt->execute($clean_ids);

			$results = array();
			while ($row = $stmt->fetch())
			{
				$results[] = $this->db->unmarshal($row['name'], 'string');
			}

			return $results;
		}
		catch (Exception $e)
		{
			// Handle or log error appropriately
			return array();
		}
	}

	function get_building($id)
	{
		$this->db->limit_query("SELECT name FROM bb_building where id=" . intval($id), 0, __LINE__, __FILE__, 1);
		if (!$this->db->next_record())
		{
			return False;
		}
		return $this->db->f('name', true);
	}

	function get_buildings()
	{
		$results	 = array();
		$results[]	 = array('id' => 0, 'name' => lang('Not selected'));
		$this->db->query("SELECT id, name FROM bb_building WHERE active != 0 ORDER BY name ASC", __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$results[] = array(
				'id'	 => $this->db->f('id', false),
				'name'	 => $this->db->f('name', true)
			);
		}
		return $results;
	}

	function set_inactive($id, $type)
	{
		if ($type == 'event')
		{
			$sql = "UPDATE bb_event SET active = 0 where id = ($id)";
		}
		elseif ($type == 'allocation')
		{
			$sql = "UPDATE bb_allocation SET active = 0 where id = ($id)";
		}
		elseif ($type == 'booking')
		{
			$sql = "UPDATE bb_booking SET active = 0 where id = ($id)";
		}
		else
		{
			throw new UnexpectedValueException('Encountered an unexpected error');
		}
		$this->db->query($sql, __LINE__, __FILE__);
		return;
	}

	function set_active($id, $type)
	{
		if ($type == 'event')
		{
			$sql = "UPDATE bb_event SET active = 1 where id = ($id)";
		}
		elseif ($type == 'allocation')
		{
			$sql = "UPDATE bb_allocation SET active = 1 where id = ($id)";
		}
		elseif ($type == 'booking')
		{
			$sql = "UPDATE bb_booking SET active = 1 where id = ($id)";
		}
		else
		{
			throw new UnexpectedValueException('Encountered an unexpected error');
		}
		$this->db->query($sql, __LINE__, __FILE__);
		return;
	}

	function get_activities_main_level()
	{
		$results	 = array();
		$results[]	 = array('id' => 0, 'name' => lang('Not selected'));
		$this->db->query("SELECT id,name FROM bb_activity WHERE parent_id is NULL", __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$results[] = array('id' => $this->db->f('id', false), 'name' => $this->db->f('name', true));
		}
		return $results;
	}

	function get_activities($id)
	{
		$results = array();
		$this->db->query("select id from bb_activity where id = ($id) or  parent_id = ($id) or parent_id in (select id from bb_activity where parent_id = ($id))", __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$results[] = $this->_unmarshal($this->db->f('id', false), 'int');
		}
		return $results;
	}

	public function update_id_string()
	{
		$table_name	 = $this->table_name;
		$db			 = $this->db;
		$sql		 = "UPDATE $table_name SET id_string = cast(id AS varchar)";
		$db->query($sql, __LINE__, __FILE__);
	}

	public function update_from_field(int|null $application_id)
	{
		$db = $this->db;

		// Build WHERE clauses for filtering
		$filter = $application_id ? " AND id = {$application_id}" : '';
		$parent_filter = $application_id ? " AND parent_app.id = {$application_id}" : '';

		// Update applications that are not parents (no children referencing them AND not self-referencing)
		$sql = "UPDATE bb_application 
            SET from_ = (SELECT min(from_) FROM bb_application_date WHERE application_id = bb_application.id)
            WHERE id NOT IN (
                -- Applications referenced by children
                SELECT DISTINCT parent_id FROM bb_application 
                WHERE parent_id IS NOT NULL AND parent_id != id
                UNION
                -- Self-referencing applications (parent_id = id)
                SELECT DISTINCT id FROM bb_application 
                WHERE parent_id = id
            )
            $filter";
		$db->query($sql, __LINE__, __FILE__);

		// Update parent applications (traditional parent_id = NULL pattern)
		$sql = "UPDATE bb_application parent_app
            SET from_ = (
                SELECT MIN(all_dates.from_)
                FROM (
                    -- Parent's own dates
                    SELECT from_ FROM bb_application_date 
                    WHERE application_id = parent_app.id
                    UNION ALL
                    -- Children's dates
                    SELECT child_dates.from_
                    FROM bb_application child_app
                    JOIN bb_application_date child_dates ON child_app.id = child_dates.application_id
                    WHERE child_app.parent_id = parent_app.id
                    AND child_app.active = 1
                    AND child_app.parent_id != child_app.id  -- Exclude self-referencing records
                ) as all_dates
            )
            WHERE parent_app.id IN (
                SELECT DISTINCT parent_id 
                FROM bb_application 
                WHERE parent_id IS NOT NULL AND parent_id != id
            )
            $parent_filter";
		$db->query($sql, __LINE__, __FILE__);

		// Update self-referencing parent applications (parent_id = id pattern)
		$sql = "UPDATE bb_application parent_app
            SET from_ = (SELECT min(from_) FROM bb_application_date WHERE application_id = parent_app.id)
            WHERE parent_app.parent_id = parent_app.id
            $parent_filter";
		$db->query($sql, __LINE__, __FILE__);
	}

	public function delete_application($id)
	{
		if ($this->db->get_transaction())
		{
			$this->global_lock = true;
		}
		else
		{
			$this->db->transaction_begin();
		}

		createObject('booking.sopurchase_order')->delete_purchase_order($id);

		$sql = "DELETE FROM bb_document_application WHERE owner_id=" . (int)$id;
		$this->db->query($sql, __LINE__, __FILE__);

		$tablesuffixes = array('agegroup', 'comment', 'date', 'resource', 'targetaudience');
		foreach ($tablesuffixes as $suffix)
		{
			$table_name	 = sprintf('%s_%s', $this->table_name, $suffix);
			$sql		 = "DELETE FROM $table_name WHERE application_id=" . (int)$id;
			$this->db->query($sql, __LINE__, __FILE__);
		}
		$table_name	 = $this->table_name;
		$sql		 = "DELETE FROM $table_name WHERE id=" . (int)$id;
		$this->db->query($sql, __LINE__, __FILE__);

		if (!$this->global_lock)
		{
			return $this->db->transaction_commit();
		}
	}

	function check_collision($resources, $from_, $to_, $session_id = null)
	{
		$filter_block = '';
		if ($session_id)
		{
			$filter_block = " AND session_id != '{$session_id}'";
		}

		$rids	 = join(',', array_map("intval", $resources));
		$sql	 = "SELECT bb_block.id, 'block' as type
                      FROM bb_block
                      WHERE  bb_block.resource_id in ($rids)
                      AND ((bb_block.from_ <= '$from_' AND bb_block.to_ > '$from_')
                      OR (bb_block.from_ >= '$from_' AND bb_block.to_ <= '$to_')
                      OR (bb_block.from_ < '$to_' AND bb_block.to_ >= '$to_')) AND active = 1 {$filter_block}
                      UNION
					  SELECT ba.id, 'allocation' as type
                      FROM bb_allocation ba, bb_allocation_resource bar
                      WHERE active = 1
                      AND ba.id = bar.allocation_id
                      AND bar.resource_id in ($rids)
                      AND ((ba.from_ <= '$from_' AND ba.to_ > '$from_')
                      OR (ba.from_ >= '$from_' AND ba.to_ <= '$to_')
                      OR (ba.from_ < '$to_' AND ba.to_ >= '$to_'))
                      UNION
                      SELECT be.id, 'event' as type
                      FROM bb_event be, bb_event_resource ber
                      WHERE active = 1
					  AND be.id = ber.event_id
                      AND ber.resource_id in ($rids)
                      AND ((be.from_ <= '$from_' AND be.to_ > '$from_')
                      OR (be.from_ >= '$from_' AND be.to_ <= '$to_')
                      OR (be.from_ < '$to_' AND be.to_ >= '$to_'))";

		$this->db->limit_query($sql, 0, __LINE__, __FILE__, 1);

		if (!$this->db->next_record())
		{
			return False;
		}
		return True;
	}

	/**
	 * Get detailed collision information - returns actual conflicting records
	 * Same logic as check_collision but returns the records instead of just true/false
	 */
	function get_collision_details($resources, $from_, $to_, $session_id = null)
	{
		$filter_block = '';
		if ($session_id)
		{
			$filter_block = " AND session_id != '{$session_id}'";
		}

		$rids = join(',', array_map("intval", $resources));
		// Get translated labels for conflict types
		$block_label = lang('conflict_block');
		$allocation_label = lang('conflict_allocation'); 
		$unknown_org_label = lang('conflict_unknown_org');
		$event_label = lang('conflict_event');

		$sql = "SELECT bb_block.id, bb_block.from_, bb_block.to_, 'block' as type, '{$block_label}' as name
                  FROM bb_block
                  WHERE bb_block.resource_id in ($rids)
                  AND ((bb_block.from_ <= '$from_' AND bb_block.to_ > '$from_')
                  OR (bb_block.from_ >= '$from_' AND bb_block.to_ <= '$to_')
                  OR (bb_block.from_ < '$to_' AND bb_block.to_ >= '$to_')) AND active = 1 {$filter_block}
                  UNION
                  SELECT ba.id, ba.from_, ba.to_, 'allocation' as type, 
                         CONCAT('{$allocation_label} (', COALESCE(bo.name, '{$unknown_org_label}'), ')') as name
                  FROM bb_allocation ba
                  INNER JOIN bb_allocation_resource bar ON ba.id = bar.allocation_id
                  LEFT JOIN bb_organization bo ON ba.organization_id = bo.id
                  WHERE ba.active = 1
                  AND bar.resource_id in ($rids)
                  AND ((ba.from_ <= '$from_' AND ba.to_ > '$from_')
                  OR (ba.from_ >= '$from_' AND ba.to_ <= '$to_')
                  OR (ba.from_ < '$to_' AND ba.to_ >= '$to_'))
                  UNION
                  SELECT be.id, be.from_, be.to_, 'event' as type, COALESCE(be.name, '{$event_label}') as name
                  FROM bb_event be
                  INNER JOIN bb_event_resource ber ON be.id = ber.event_id
                  WHERE be.active = 1
                  AND ber.resource_id in ($rids)
                  AND ((be.from_ <= '$from_' AND be.to_ > '$from_')
                  OR (be.from_ >= '$from_' AND be.to_ <= '$to_')
                  OR (be.from_ < '$to_' AND be.to_ >= '$to_'))
                  ORDER BY from_
                  LIMIT 10";

		$conflicts = array();
		$this->db->query($sql, __LINE__, __FILE__);
		
		while ($this->db->next_record())
		{
			$conflicts[] = array(
				'id' => $this->db->f('id'),
				'type' => $this->db->f('type'),
				'name' => $this->db->f('name'),
				'from_' => $this->db->f('from_'),
				'to_' => $this->db->f('to_')
			);
		}
		
		return $conflicts;
	}

	/**
	 * Check if a given timespan is available for bookings or allocations
	 *
	 * @param resources
	 * @param timespan start
	 * @param timespan end
	 *
	 * @return boolean
	 */
	function check_timespan_availability($resources, $from_, $to_)
	{
		$rids	 = join(',', array_map("intval", $resources));
		$nrids	 = count($resources);
		$this->db->query("SELECT id FROM bb_season
			                  WHERE id IN (SELECT season_id
							               FROM bb_season_resource
							               WHERE resource_id IN ($rids,-1)
							               GROUP BY season_id
							               HAVING count(season_id)=$nrids)", __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$season_id = $this->_unmarshal($this->db->f('id', false), 'int');
			if (CreateObject('booking.soseason')->timespan_within_season($season_id, new DateTime($from_), new DateTime($to_)))
			{
				return true;
			}
		}
		return false;
	}

	function update_external_archive_reference($id, $external_archive_key)
	{
		$external_archive_key = $this->db->db_addslashes($external_archive_key);
		return $this->db->query("UPDATE bb_application SET external_archive_key = '{$external_archive_key}' WHERE id =" . (int)$id, __LINE__, __FILE__);
	}

	function check_booking_limit($session_id, $resource_id, $ssn, $booking_limit_number_horizont, $booking_limit_number)
	{
		if (!$ssn || !$booking_limit_number_horizont || !$booking_limit_number)
		{
			return false;
		}


		$booking_horizont_seconds = (int)$booking_limit_number_horizont * 3600 * 24;

		$sql = "SELECT count(*) as cnt FROM"
			. " (SELECT bb_application.id FROM bb_application"
			. " JOIN bb_application_date ON bb_application.id = bb_application_date.application_id"
			. " JOIN bb_application_resource"
			. " ON bb_application.id = bb_application_resource.application_id AND bb_application_resource.resource_id = " . (int)$resource_id
			. " WHERE "
			. "( customer_ssn = '{$ssn}' AND status != 'REJECTED' "
			. " AND ((EXTRACT(EPOCH from (to_- current_date))) > -$booking_horizont_seconds"
			. " OR (EXTRACT(EPOCH from (current_date - from_))) < $booking_horizont_seconds)"
			. ")"
			. " OR (status = 'NEWPARTIAL1' AND session_id = '$session_id')"
			. " ) as t";

		$this->db->query($sql, __LINE__, __FILE__);
		$this->db->next_record();
		$cnt = (int)$this->db->f('cnt');

		$limit_reached = 0;
		if ($cnt > $booking_limit_number)
		{
			$limit_reached = $cnt;
		}
		return $limit_reached;
	}


	function get_application_payments($params)
	{
		$application_id	 = isset($params['application_id']) && $params['application_id'] ? (int)$params['application_id'] : null;
		$sort			 = isset($params['sort']) && $params['sort'] ? $params['sort'] : 'id';
		$dir			 = isset($params['dir']) && $params['dir'] ? $params['dir'] : 'asc';

		if (empty($application_id))
		{
			return array();
		}

		if (!in_array($sort, array('id', 'order_id', 'payment_method', 'amount')))
		{
			$sort = 'id';
		}

		$data	 = array();
		$sql	 = "SELECT bb_payment.* FROM bb_payment"
			. " JOIN bb_purchase_order ON bb_payment.order_id = bb_purchase_order.id"
			. " WHERE application_id = {$application_id}"
			. " ORDER BY {$sort} {$dir}";

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$payment_method_id =  $this->db->f('payment_method_id');
			$payment_method = $payment_method_id == 2 ? 'Etterfakturering' : 'Vipps';

			$data[] = array(
				'id'				 => (int)$this->db->f('id'),
				'remote_state'		 => $this->db->f('remote_state'),
				'amount'			 => (float)$this->db->f('amount'),
				'refunded_amount'	 => (float)$this->db->f('refunded_amount'),
				'currency'			 => $this->db->f('currency'),
				'status'			 => $this->db->f('status'),
				'order_id'			 => (int)$this->db->f('order_id'),
				'payment_method'	 => $payment_method,
				'created'			 => $this->db->f('created'),
			);
		}

		return array('data' => $data);
	}

	function get_application_articles($params)
	{
		$application_ids = array();

		// Handle both single application_id and multiple application_ids
		if (isset($params['application_id']) && $params['application_id']) {
			$application_ids = array((int)$params['application_id']);
		} elseif (isset($params['application_ids']) && $params['application_ids']) {
			$application_ids = array_map('intval', $params['application_ids']);
		}

		if (empty($application_ids))
		{
			return array('data' => array());
		}

		$application_ids_string = implode(',', $application_ids);

		// Get purchase order lines for these applications that have a cost > 0, aggregated by article_mapping_id
		$sql = "SELECT bb_purchase_order_line.article_mapping_id,
			SUM(bb_purchase_order_line.quantity) as total_quantity,
			SUM(bb_purchase_order_line.amount) as total_amount,
			bb_purchase_order_line.currency, bb_article_mapping.unit,
			CASE WHEN (bb_resource.name IS NULL)
				THEN bb_service.name
				ELSE bb_resource.name
			END AS article_name
			FROM bb_purchase_order
			JOIN bb_purchase_order_line ON bb_purchase_order.id = bb_purchase_order_line.order_id
			JOIN bb_article_mapping ON bb_purchase_order_line.article_mapping_id = bb_article_mapping.id
			LEFT JOIN bb_service ON (bb_article_mapping.article_id = bb_service.id AND bb_article_mapping.article_cat_id = 2)
			LEFT JOIN bb_resource ON (bb_article_mapping.article_id = bb_resource.id AND bb_article_mapping.article_cat_id = 1)
			WHERE bb_purchase_order.cancelled IS NULL
			AND bb_purchase_order.application_id IN (" . $application_ids_string . ")
			GROUP BY bb_purchase_order_line.article_mapping_id, bb_purchase_order_line.currency,
				bb_article_mapping.unit, article_name
			HAVING SUM(bb_purchase_order_line.amount) > 0
			ORDER BY article_name";

		$this->db->query($sql, __LINE__, __FILE__);

		$articles = array();
		while ($this->db->next_record()) {
			$articles[] = array(
				'article_mapping_id' => (int)$this->db->f('article_mapping_id'),
				'article' => $this->db->f('article_name', true),
				'quantity' => (float)$this->db->f('total_quantity'),
				'unit' => $this->db->f('unit', true),
				'cost' => number_format((float)$this->db->f('total_amount'), 2, '.', ' '),
				'currency' => $this->db->f('currency', true)
			);
		}

		return array('data' => $articles);
	}

	/**
	 *
	 * @param string $payment_order_id
	 * @param string $status: new, pending, completed, voided, partially_refunded, refunded
	 * @return bool
	 */
	function update_payment_status($payment_order_id, $status, $remote_state, $refunded_amount = 0)
	{
		$remote_id	 = $this->db->db_addslashes($payment_order_id);

		$value_set = array(
			'status'		 => $this->db->db_addslashes($status),
			'remote_state'	 => $remote_state
		);

		if ($refunded_amount && $status == 'refunded')
		{
			$value_set['refunded_amount'] = $refunded_amount;
		}

		$value_set = $this->db->validate_update($value_set);

		$sql = "UPDATE bb_payment SET {$value_set} WHERE remote_id= '{$remote_id}'";

		return $this->db->query($sql, __LINE__, __FILE__);
	}

	function get_purchase_order(&$applications)
	{
		if (!$applications['results'])
		{
			return;
		}

		$application_ids = array(-1);
		foreach ($applications['results'] as $application)
		{
			$application_ids[] = $application['id'];
		}

		$sql = "SELECT bb_purchase_order_line.* , bb_purchase_order.application_id, bb_article_mapping.unit,"
			. "CASE WHEN
					(
						bb_resource.name IS NULL
					)"
			. " THEN bb_service.name ELSE bb_resource.name END AS name"
			. " FROM bb_purchase_order JOIN bb_purchase_order_line ON bb_purchase_order.id = bb_purchase_order_line.order_id"
			. " JOIN bb_article_mapping ON bb_purchase_order_line.article_mapping_id = bb_article_mapping.id"
			. " LEFT JOIN bb_service ON (bb_article_mapping.article_id = bb_service.id AND bb_article_mapping.article_cat_id = 2)"
			. " LEFT JOIN bb_resource ON (bb_article_mapping.article_id = bb_resource.id AND bb_article_mapping.article_cat_id = 1)"
			. " WHERE bb_purchase_order.cancelled IS NULL AND bb_purchase_order.application_id IN (" . implode(',', $application_ids) . ")"
			. " AND (bb_purchase_order_line.amount > 0 OR bb_purchase_order_line.tax > 0)"
			. " ORDER BY bb_purchase_order_line.id";

		$this->db->query($sql, __LINE__, __FILE__);

		$orders		 = array();
		$sum		 = array();
		$total_sum	 = 0;
		while ($this->db->next_record())
		{
			$application_id	 = (int)$this->db->f('application_id');
			$order_id		 = (int)$this->db->f('order_id');
			if (!isset($sum[$order_id]))
			{
				$sum[$order_id] = 0;
			}

			$_sum			 = (float)$this->db->f('amount') + (float)$this->db->f('tax');
			$sum[$order_id]	 = (float)$sum[$order_id] + $_sum;
			$total_sum		 += $_sum;

			$orders[$application_id][$order_id]['lines'][] = array(
				'order_id'				 => $order_id,
				'status'				 => (int)$this->db->f('status'),
				'parent_mapping_id'		 => (int)$this->db->f('parent_mapping_id'),
				'article_mapping_id'	 => (int)$this->db->f('article_mapping_id'),
				'quantity'				 => (float)$this->db->f('quantity'),
				'unit_price'			 => (float)$this->db->f('unit_price'),
				'overridden_unit_price'	 => (float)$this->db->f('overridden_unit_price'),
				'currency'				 => $this->db->f('currency'),
				'amount'				 => (float)$this->db->f('amount'),
				'unit'					=>	$this->db->f('unit', true),
				'tax_code'				 => (int)$this->db->f('tax_code'),
				'tax'					 => (float)$this->db->f('tax'),
				'name'					 => $this->db->f('name', true),
			);

			$orders[$application_id][$order_id]['order_id']	 = $order_id;
			$orders[$application_id][$order_id]['sum']		 = $sum[$order_id];
		}

		foreach ($applications['results'] as &$application)
		{
			if (empty($orders[$application['id']]))
			{
				continue;
			}
			$application['orders'] = array_values($orders[$application['id']]);
		}

		$applications['total_sum'] = $total_sum;
		return $orders;
	}



	function get_payment($payment_id)
	{
		$sql	 = "SELECT * FROM bb_payment WHERE id =" . (int)$payment_id;
		$this->db->query($sql, __LINE__, __FILE__);
		$payment = array();
		if ($this->db->next_record())
		{
			$payment_method_id =  $this->db->f('payment_method_id');
			$payment_method = $payment_method_id == 2 ? 'Etterfakturering' : 'Vipps';

			$payment = array(
				'id'					 => $this->db->f('id'),
				'order_id'				 => $this->db->f('order_id'),
				'payment_method'		 => $payment_method,
				'payment_gateway_mode'	 => $this->db->f('payment_gateway_mode'),
				'remote_id'				 => $this->db->f('remote_id'),
				'remote_state'			 => $this->db->f('remote_state'),
				'amount'				 => (float)$this->db->f('amount'),
				'currency'				 => $this->db->f('currency'),
				'refunded_amount'		 => (float)$this->db->f('refunded_amount'),
				'refunded_currency'		 => $this->db->f('refunded_currency'),
				'status'				 => $this->db->f('status'), //'new', pending, completed, voided, partially_refunded, refunded
				'created'				 => $this->db->f('created'),
				'autorized'				 => $this->db->f('autorized'),
				'expires'				 => $this->db->f('expires'),
				'completet'				 => $this->db->f('completet'),
				'captured'				 => $this->db->f('captured'),
			);
		}
		return $payment;
	}

	function delete_payment($remote_order_id)
	{
		$remote_id = $this->db->db_addslashes($remote_order_id);
		$sql = "DELETE FROM bb_payment WHERE remote_id = '{$remote_id}'";
		return $this->db->query($sql, __LINE__, __FILE__);
	}

	function add_payment($order_id, $msn, $mode = 'live',  $payment_method_id = 1)
	{
		// Handle both single order_id and array of order_ids
		$order_ids = is_array($order_id) ? $order_id : [$order_id];
		$primary_order_id = $order_ids[0];

		$sql = "SELECT count(id) AS cnt FROM bb_payment WHERE order_id =" . (int)$primary_order_id;

		$this->db->query($sql, __LINE__, __FILE__);
		$this->db->next_record();
		$cnt		 = (int)$this->db->f('cnt');
		$payment_attempt = $cnt + 1;
		$random_hash = substr(bin2hex(random_bytes(2)), 0, 4);
		$remote_id	 = "{$msn}-{$primary_order_id}-order-{$primary_order_id}-{$payment_attempt}-{$random_hash}";

		$sopurchase_order = createObject('booking.sopurchase_order');

		// Create payment records for all orders with the same remote_id
		foreach ($order_ids as $single_order_id) {
			$order = $sopurchase_order->get_single_purchase_order($single_order_id);

			$value_set = array(
				'order_id' => $single_order_id,
				'payment_method_id'	 => (int) $payment_method_id,
				'payment_gateway_mode' => $mode, //test and live.
				'remote_id' => $remote_id,
				'remote_state' => null,
				'amount' => $order['sum'],
				'currency' => 'NOK',
				'refunded_amount' => '0.0',
				'refunded_currency' => 'NOK',
				'status' => 'new', // pending, completed, voided, partially_refunded, refunded
				'created' => time(),
				'autorized' => null,
				'expires' => null,
				'completet' => null,
				'captured' => null,
				//			'avs_response_code' => array('type' => 'varchar', 'precision' => '15', 'nullable' => true),
				//			'avs_response_code_label' => array('type' => 'varchar', 'precision' => '35', 'nullable' => true),
			);

			$this->db->query('INSERT INTO bb_payment (' . implode(',', array_keys($value_set)) . ') VALUES ('
				. $this->db->validate_insert(array_values($value_set)) . ')', __LINE__, __FILE__);
		}

		return $remote_id;
	}

	function get_application_from_payment_order($payment_order_id)
	{
		$remote_id	 = $this->db->db_addslashes($payment_order_id);
		$sql = "SELECT DISTINCT application_id FROM bb_payment"
			. " JOIN bb_purchase_order ON bb_payment.order_id = bb_purchase_order.id"
			. "	WHERE remote_id = '{$remote_id}'";

		$this->db->query($sql, __LINE__, __FILE__);
		
		$application_ids = array();
		while ($this->db->next_record())
		{
			$application_ids[] = (int)$this->db->f('application_id');
		}
		
		return $application_ids;
	}

	/**
	 * Retrieves transactions that have not been posted to the accounting system with pagination and filtering.
	 *
	 * @param array $params Parameters for filtering and pagination
	 *                     - status: Filter by status (e.g., 'completed')
	 *                     - limit: Number of records to return
	 *                     - offset: Starting offset for pagination
	 *                     - sort: Sort field
	 *                     - dir: Sort direction ('asc' or 'desc')
	 * @return array List of unposted transactions and total count
	 */
	public function get_unposted_transactions($params = array())
	{
		// Set default values
		$status = isset($params['status']) ? $this->db->db_addslashes($params['status']) : null;
		$limit = isset($params['limit']) && (int)$params['limit'] > 0 ? (int)$params['limit'] : null;
		$offset = isset($params['offset']) ? (int)$params['offset'] : 0;

		// Map frontend sort fields to database columns with table prefix
		$sortMap = [
			'remote_id' => 'bb_payment.remote_id',
			'amount' => 'bb_payment.amount',
			'created' => 'bb_payment.created',
			'date' => 'bb_payment.created', // Map 'date' to 'bb_payment.created'
			'description' => 'description' // This is an alias from the CASE statement
		];

		$sort = isset($params['sort']) && isset($sortMap[$params['sort']])
			? $sortMap[$params['sort']] : 'bb_payment.created';
		$dir = isset($params['dir']) && strtolower($params['dir']) === 'asc' ? 'ASC' : 'DESC';

		// Base query with payment_method join
		$sql_base = "FROM
                bb_payment
            JOIN
                bb_purchase_order ON bb_payment.order_id = bb_purchase_order.id
            LEFT JOIN
                bb_payment_method ON bb_payment.payment_method_id = bb_payment_method.id
            WHERE
                bb_payment.posted_to_accounting IS NULL AND bb_payment_method.id != 2"; // Exclude 'Etterfakturering' payment method

		// Add status filter if provided
		if ($status)
		{
			$sql_base .= " AND bb_payment.status = '{$status}'";
		}

		// Count total records
		$count_sql = "SELECT COUNT(*) AS total " . $sql_base;
		$this->db->query($count_sql, __LINE__, __FILE__);
		$this->db->next_record();
		$total = (int)$this->db->f('total');

		// Get data with pagination
		$sql = "SELECT
                bb_payment.remote_id AS remote_order_id,
                bb_payment.amount,
                bb_payment.created AS date,
                bb_payment.status,
				bb_payment.remote_state,
                bb_payment_method.payment_gateway_name,
                bb_payment_method.payment_gateway_mode,
                CASE
                    WHEN bb_purchase_order.reservation_type = 'event' THEN (
                        SELECT string_agg(bb_event.name, ', ')
                        FROM bb_event
                        WHERE bb_event.id = bb_purchase_order.reservation_id
                    )
                    WHEN bb_purchase_order.reservation_type = 'allocation' THEN (
                        SELECT bb_allocation.building_name || ' ' || bb_allocation.from_ || ' - ' || bb_allocation.to_
                        FROM bb_allocation
                        WHERE bb_allocation.id = bb_purchase_order.reservation_id
                    )
                    ELSE NULL
                END AS description
            " . $sql_base . "
            ORDER BY " . $sort . " " . $dir;

		// Add limit and offset if provided
		if ($limit !== null)
		{
			$sql .= " LIMIT " . $limit . " OFFSET " . $offset;
		}

		$this->db->query($sql, __LINE__, __FILE__);

		$transactions = [];
		while ($this->db->next_record())
		{
			$transactions[] = [
				'remote_order_id' => $this->db->f('remote_order_id'),
				'amount'          => (float)$this->db->f('amount'),
				'date'            => $this->db->f('date'),
				'description'     => $this->db->f('description', true),
				'status'          => $this->db->f('status'),
				'remote_state'    => $this->db->f('remote_state'),
				'payment_method'  => $this->db->f('payment_gateway_name', true)
			];
		}

		return [
			'results' => $transactions,
			'total' => $total
		];
	}

	/**
	 * Marks a transaction as posted to the accounting system.
	 *
	 * @param string $remote_order_id The remote order ID of the transaction to mark as posted.
	 * @return bool True if the update was successful, false otherwise.
	 */
	public function mark_as_posted($remote_order_id)
	{
		$remote_id = $this->db->db_addslashes($remote_order_id);
		$timestamp = time(); // Current Unix timestamp

		$sql = "UPDATE bb_payment
            SET posted_to_accounting = {$timestamp}
            WHERE remote_id = '{$remote_id}'";

		return $this->db->query($sql, __LINE__, __FILE__);
	}

	/**
	 * Retrieves refund transactions that have not been posted to the accounting system with pagination and filtering.
	 *
	 * @param array $params Parameters for filtering and pagination
	 *                     - limit: Number of records to return
	 *                     - offset: Starting offset for pagination
	 *                     - sort: Sort field
	 *                     - dir: Sort direction ('asc' or 'desc')
	 * @return array List of unposted refund transactions and total count
	 */
	public function get_unposted_refund_transactions($params = array())
	{
		// Set default values
		$limit = isset($params['limit']) && (int)$params['limit'] > 0 ? (int)$params['limit'] : null;
		$offset = isset($params['offset']) ? (int)$params['offset'] : 0;

		// Map frontend sort fields to database columns with table prefix
		$sortMap = [
			'remote_id' => 'bb_payment.remote_id',
			'amount' => 'bb_payment.refunded_amount',
			'created' => 'bb_payment.created',
			'date' => 'bb_payment.created',
			'description' => 'description'
		];

		$sort = isset($params['sort']) && isset($sortMap[$params['sort']])
			? $sortMap[$params['sort']] : 'bb_payment.created';
		$dir = isset($params['dir']) && strtolower($params['dir']) === 'asc' ? 'ASC' : 'DESC';

		// Base query with payment_method join
		$sql_base = "FROM
            bb_payment
        JOIN
            bb_purchase_order ON bb_payment.order_id = bb_purchase_order.id
        LEFT JOIN
            bb_payment_method ON bb_payment.payment_method_id = bb_payment_method.id
        WHERE
            (bb_payment.status = 'refunded' OR bb_payment.status = 'partially_refunded')
            AND bb_payment.refunded_amount > 0
            AND bb_payment.refund_posted_to_accounting IS NULL AND bb_payment_method.id != 2";

		// Count total records
		$count_sql = "SELECT COUNT(*) AS total " . $sql_base;
		$this->db->query($count_sql, __LINE__, __FILE__);
		$this->db->next_record();
		$total = (int)$this->db->f('total');

		// Get data with pagination
		$sql = "SELECT
            bb_payment.remote_id AS remote_order_id,
            bb_payment.refunded_amount AS amount,
            bb_payment.created AS date,
            bb_payment.status,
            bb_payment.remote_state,
            bb_payment_method.payment_gateway_name,
            bb_payment_method.payment_gateway_mode,
            CASE
                WHEN bb_purchase_order.reservation_type = 'event' THEN (
                    SELECT string_agg(bb_event.name, ', ')
                    FROM bb_event
                    WHERE bb_event.id = bb_purchase_order.reservation_id
                )
                WHEN bb_purchase_order.reservation_type = 'allocation' THEN (
                    SELECT bb_allocation.building_name || ' ' || bb_allocation.from_ || ' - ' || bb_allocation.to_
                    FROM bb_allocation
                    WHERE bb_allocation.id = bb_purchase_order.reservation_id
                )
                ELSE NULL
            END AS description
        " . $sql_base . "
        ORDER BY " . $sort . " " . $dir;

		// Add limit and offset if provided
		if ($limit !== null)
		{
			$sql .= " LIMIT " . $limit . " OFFSET " . $offset;
		}

		$this->db->query($sql, __LINE__, __FILE__);

		$transactions = [];
		while ($this->db->next_record())
		{
			$transactions[] = [
				'remote_order_id' => $this->db->f('remote_order_id'),
				'amount' => (float)$this->db->f('amount'),
				'date' => $this->db->f('date'),
				'description' => $this->db->f('description', true),
				'status' => $this->db->f('status'),
				'remote_state' => $this->db->f('remote_state'),
				'payment_method' => $this->db->f('payment_gateway_name', true),
				'original_transaction_id' => $this->db->f('remote_order_id') // For refunds, original_transaction_id is the same as remote_order_id
			];
		}

		return [
			'results' => $transactions,
			'total' => $total
		];
	}

	/**
	 * Marks a refund transaction as posted to the accounting system.
	 *
	 * @param string $remote_order_id The remote order ID of the refund transaction to mark as posted.
	 * @return bool True if the update was successful, false otherwise.
	 */
	public function mark_refund_as_posted($remote_order_id)
	{
		$remote_id = $this->db->db_addslashes($remote_order_id);
		$timestamp = time(); // Current Unix timestamp

		$sql = "UPDATE bb_payment
        SET refund_posted_to_accounting = {$timestamp}
        WHERE remote_id = '{$remote_id}'
        AND (status = 'refunded' OR status = 'partially_refunded')
        AND refunded_amount > 0";

		return $this->db->query($sql, __LINE__, __FILE__);
	}

	/**
	 * Retrieves payments that have been posted to accounting but later refunded and need refund posting.
	 *
	 * @param array $params Optional parameters for filtering
	 * @return array List of posted payments that need refund posting
	 */
	public function get_refunded_posted_payments($params = array())
	{
		$sql = "SELECT
					bp.remote_id AS remote_order_id,
					bp.refunded_amount AS amount,
					bp.created AS date,
					bp.status,
					bp.remote_state,
					bpm.payment_gateway_name,
					bpm.payment_gateway_mode,
					CASE
						WHEN bpo.reservation_type = 'event' THEN (
							SELECT string_agg(bb_event.name, ', ')
							FROM bb_event
							WHERE bb_event.id = bpo.reservation_id
						)
						WHEN bpo.reservation_type = 'allocation' THEN (
							SELECT bb_allocation.building_name || ' ' || bb_allocation.from_ || ' - ' || bb_allocation.to_
							FROM bb_allocation
							WHERE bb_allocation.id = bpo.reservation_id
						)
						ELSE NULL
					END AS description
				FROM
					bb_payment bp
				JOIN
					bb_purchase_order bpo ON bp.order_id = bpo.id
				LEFT JOIN
					bb_payment_method bpm ON bp.payment_method_id = bpm.id
				WHERE
					bp.posted_to_accounting IS NOT NULL
					AND (bp.status = 'refunded' OR bp.status = 'partially_refunded')
					AND bp.refunded_amount > 0
					AND bp.refund_posted_to_accounting IS NULL
					AND bp.payment_method_id != 2"; // Exclude 'Etterfakturering' payment method

		$this->db->query($sql, __LINE__, __FILE__);

		$refunds_needing_posting = [];
		while ($this->db->next_record()) {
			$refunds_needing_posting[] = [
				'remote_order_id' => $this->db->f('remote_order_id'),
				'amount' => (float)$this->db->f('amount'),
				'date' => $this->db->f('date'),
				'description' => $this->db->f('description', true) ?
					"Refusjon: " . $this->db->f('description', true) :
					"Refusjon av betaling",
				'original_transaction_id' => $this->db->f('remote_order_id'),
				'payment_method' => $this->db->f('payment_gateway_name', true)
			];
		}

		return $refunds_needing_posting;
	}

	/**
	 * Get child applications by parent_id
	 *
	 * @param int $parent_id The parent application ID
	 * @return array Array of child application IDs
	 */
	public function get_child_applications($parent_id)
	{
		$parent_id = (int)$parent_id;
		$sql = "SELECT id FROM bb_application WHERE parent_id = {$parent_id} AND active = 1";

		$this->db->query($sql, __LINE__, __FILE__);
		$child_ids = array();

		while ($this->db->next_record())
		{
			$child_ids[] = (int)$this->db->f('id');
		}

		return $child_ids;
	}

	/**
	 * Get all related applications (parent + children) for a given application ID
	 *
	 * @param int $application_id The application ID to find related applications for
	 * @return array Array containing parent_id and all related application IDs
	 */
	public function get_related_applications($application_id)
	{
		$application_id = (int)$application_id;

		// First, get the current application to check if it has a parent
		$sql = "SELECT parent_id FROM bb_application WHERE id = {$application_id}";
		$this->db->query($sql, __LINE__, __FILE__);
		$this->db->next_record();
		$parent_id = $this->db->f('parent_id');

		$related_ids = array();

		if ($parent_id)
		{
			// This is a child application - get parent and all siblings
			$parent_id = (int)$parent_id;
			$related_ids[] = $parent_id;

			// Get all children of the parent (including current application)
			$child_ids = $this->get_child_applications($parent_id);
			$related_ids = array_merge($related_ids, $child_ids);
		}
		else
		{
			// This is a parent application - get current + all children
			$related_ids[] = $application_id;
			$child_ids = $this->get_child_applications($application_id);
			$related_ids = array_merge($related_ids, $child_ids);
		}

		return array(
			'parent_id' => $parent_id ?: $application_id,
			'application_ids' => array_unique($related_ids),
			'total_count' => count(array_unique($related_ids))
		);
	}
}

class booking_soapplication_association extends booking_socommon
{

	function __construct()
	{
		parent::__construct('bb_application_association', array(
			'id'			 => array('type' => 'int'),
			'application_id' => array('type' => 'int'),
			'type'			 => array('type' => 'string', 'required' => true),
			'from_'			 => array('type' => 'timestamp', 'query' => true),
			'to_'			 => array('type' => 'timestamp'),
			'cost'			 => array('type' => 'decimal'),
			'active'		 => array('type' => 'int')
		));
	}
}
