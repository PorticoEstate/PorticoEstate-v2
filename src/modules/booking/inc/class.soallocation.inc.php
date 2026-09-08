<?php
	phpgw::import_class('booking.socommon');

	class booking_soallocation extends booking_socommon
	{

		const ERROR_CONFLICTING_BOOKING = 'booking';
		const ERROR_CONFLICTING_EVENT = 'event';
		const ERROR_CONFLICTING_ALLOCATION = 'allocation';

		protected static $allocation_conflict_error_types = array(
			self::ERROR_CONFLICTING_BOOKING => true,
			self::ERROR_CONFLICTING_EVENT => true,
			self::ERROR_CONFLICTING_ALLOCATION => true,
		);

		function __construct()
		{
			parent::__construct('bb_allocation', array(
				'id' => array('type' => 'int'),
				'id_string' => array('type' => 'string', 'required' => false, 'default' => '0',
					'query' => true),
				'active' => array('type' => 'int', 'required' => true),
				'skip_bas' => array('type' => 'int', 'required' => false),
				'application_id' => array('type' => 'int', 'required' => false),
				'organization_id' => array('type' => 'int', 'required' => true),
				'building_name' => array('type' => 'string', 'required' => true, 'query' => true),
				'season_id' => array('type' => 'int', 'required' => 'true'),
				'from_' => array('type' => 'string', 'required' => true),
				'to_' => array('type' => 'string', 'required' => true),
				'cost' => array('type' => 'decimal', 'required' => true),
				'completed' => array('type' => 'int', 'required' => true, 'default' => '0'),
				'additional_invoice_information' => array('type' => 'string', 'required' => false),
				/**
				 * Recurrence group, minted once per run of the admin recurring wizard.
				 * NULL for allocations created before the wizard started minting them and
				 * for allocations that are not part of a recurrence.
				 */
				'allocation_group_id' => array('type' => 'int', 'required' => false),
				/**
				 * Raised when a case officer types a price on an individual allocation.
				 * Never a form input - see booking_uiallocation::$fields.
				 *
				 * It means "this price was set by hand AND STILL DIFFERS from the rest of
				 * the group", which is a statement about the row's present relationship to
				 * its series and not a record that somebody once typed here. The group
				 * price cascade leaves a locked member alone; an "Overskriv alle" writes
				 * over it and CLEARS the flag, because after that it no longer differs.
				 * See update_price_only().
				 */
				'price_locked' => array('type' => 'int', 'required' => false),
				'organization_name' => array('type' => 'string',
					'query' => true,
					'join' => array(
						'table' => 'bb_organization',
						'fkey' => 'organization_id',
						'key' => 'id',
						'column' => 'name'
					)),
				'organization_shortname' => array('type' => 'string',
					'query' => true,
					'join' => array(
						'table' => 'bb_organization',
						'fkey' => 'organization_id',
						'key' => 'id',
						'column' => 'shortname'
					)),
				'building_id' => array('type' => 'string',
					'join' => array(
						'table' => 'bb_season',
						'fkey' => 'season_id',
						'key' => 'id',
						'column' => 'building_id'
					)),
				'season_name' => array('type' => 'string',
					'query' => true,
					'join' => array(
						'table' => 'bb_season',
						'fkey' => 'season_id',
						'key' => 'id',
						'column' => 'name'
					)),
				'resources' => array('type' => 'int', 'required' => true,
					'manytomany' => array(
						'table' => 'bb_allocation_resource',
						'key' => 'allocation_id',
						'column' => 'resource_id'
					)),
				'costs' => array('type' => 'string',
					'manytomany' => array(
						'table' => 'bb_allocation_cost',
						'key' => 'allocation_id',
						'column' => array('time' => array('type' => 'timestamp', 'read_callback' => 'modify_by_timezone'), 'author', 'comment', 'cost'),
						'order' => array('sort' => 'time', 'dir' => 'ASC')
					)),
				)
			);
		}

		/**
		 * Filters out any errors having to do with reservation conflicts
		 * from an errors array leaving only errors of other types. If
		 * this function returns an empty array then the original errors
		 * array would have consisted of only reservation conflicts.
		 *
		 * @return array
		 */
		public function filter_conflict_errors( array $errors )
		{
			return array_diff_key($errors, self::$allocation_conflict_error_types);
		}

		function update( $entry )
		{
			$receipt = parent::update($entry);

			$cost = $this->_marshal((float)$entry['cost'], 'decimal');

			$id = (int)$entry['id'];

			$description = mb_substr($entry['from_'], 0, -3, 'UTF-8') . ' - ' . mb_substr($entry['to_'], 0, -3, 'UTF-8');

			$sql = "UPDATE bb_completed_reservation SET cost = '{$cost}', from_ = '{$entry['from_']}',"
			. " to_ = '{$entry['to_']}', description = '{$description}'"
			. " WHERE reservation_type = 'allocation'"
			. " AND reservation_id = {$id}"
			. " AND export_file_id IS NULL";

			$this->db->query($sql, __LINE__, __FILE__);

			return $receipt;
		}

		/**
		 * Mint a new recurrence group id. Called once per run of the recurring
		 * wizard, before the loop that creates the members.
		 *
		 * @return int
		 */
		public function next_allocation_group_id()
		{
			$this->db->query("SELECT nextval('seq_bb_allocation_group') AS allocation_group_id", __LINE__, __FILE__);
			$this->db->next_record();

			return (int)$this->db->f('allocation_group_id');
		}

		/**
		 * Every distinct recurrence group the application's allocations already
		 * carry, oldest first. Deactivated members are included on purpose: a
		 * member that was switched off still belongs to the series, and leaving
		 * it out would let a re-run mint a second id for a series that has one.
		 *
		 * @param int $application_id
		 * @return int[]
		 */
		public function application_allocation_group_ids( $application_id )
		{
			$application_id = (int)$application_id;

			if (!$application_id)
			{
				return array();
			}

			$this->db->query("SELECT DISTINCT allocation_group_id FROM bb_allocation"
				. " WHERE application_id = {$application_id}"
				. " AND allocation_group_id IS NOT NULL"
				. " ORDER BY allocation_group_id", __LINE__, __FILE__);

			$ids = array();
			while ($this->db->next_record())
			{
				$ids[] = (int)$this->db->f('allocation_group_id');
			}

			return $ids;
		}

		/**
		 * The recurrence group a run over this application should write.
		 *
		 * Reuse before mint: both application paths skip occurrences that already
		 * exist or that conflict, so a second run creates only the gaps. Minting
		 * unconditionally would give those gaps a second id and split one series
		 * across two groups - a later cascade would then reach only the half the
		 * dialog happened to count.
		 *
		 * More than one distinct id means the application is ALREADY split. There
		 * is no correct answer to pick from here, so the choice is the oldest
		 * group and it is written to the log rather than made silently.
		 *
		 * @param int $application_id
		 * @return int the group id to write, or 0 when there is no application
		 */
		public function find_or_mint_application_group_id( $application_id )
		{
			$application_id = (int)$application_id;

			if (!$application_id)
			{
				return 0;
			}

			$existing = $this->application_allocation_group_ids($application_id);

			if (count($existing) > 1)
			{
				error_log("booking: application {$application_id} carries more than one"
					. " allocation_group_id (" . implode(', ', $existing) . ");"
					. " reusing the oldest, {$existing[0]}");
			}

			if ($existing)
			{
				return $existing[0];
			}

			return $this->next_allocation_group_id();
		}

		/**
		 * The WHERE every scope query shares: one recurrence group, never the
		 * allocation the officer is editing, and only rows that are still live.
		 *
		 * $from_ is the "future only" bound - the occurrences that start at or
		 * after the edited one. Null means the whole group. The caller passes the
		 * start time as it STANDS IN THE DATABASE rather than the one just posted,
		 * so that the number shown in the dialog and the rows the cascade actually
		 * writes are decided by the same value.
		 */
		protected function group_scope_where( $allocation_group_id, $exclude_id, $from_ = null )
		{
			$where = " WHERE allocation_group_id = " . (int)$allocation_group_id
				. " AND id <> " . (int)$exclude_id
				. " AND active = 1";

			if ($from_)
			{
				$where .= " AND from_ >= '" . $this->db->db_addslashes($from_) . "'";
			}

			return $where;
		}

		/**
		 * The members of a recurrence group that a price change may move: everyone
		 * in scope except the allocation the officer edited, skipping the ones
		 * whose price was set individually.
		 *
		 * @return array of int
		 */
		public function get_unlocked_group_member_ids( $allocation_group_id, $exclude_id, $from_ = null )
		{
			return $this->get_group_member_ids_by_lock($allocation_group_id, $exclude_id, $from_, 0);
		}

		/**
		 * The members in scope that get_unlocked_group_member_ids() deliberately
		 * leaves behind. Only "Overskriv alle" writes these, and it writes them
		 * through update_price_only().
		 *
		 * @return array of int
		 */
		public function get_locked_group_member_ids( $allocation_group_id, $exclude_id, $from_ = null )
		{
			return $this->get_group_member_ids_by_lock($allocation_group_id, $exclude_id, $from_, 1);
		}

		/**
		 * The ids are collected before returning - the caller reads each allocation
		 * back through the same connection, which would otherwise discard the rows
		 * still waiting on this cursor.
		 *
		 * @return array of int
		 */
		protected function get_group_member_ids_by_lock( $allocation_group_id, $exclude_id, $from_, $price_locked )
		{
			if (!(int)$allocation_group_id)
			{
				return array();
			}

			$this->db->query("SELECT id FROM bb_allocation"
				. $this->group_scope_where($allocation_group_id, $exclude_id, $from_)
				. " AND price_locked = " . (int)$price_locked
				. " ORDER BY id", __LINE__, __FILE__);

			$ids = array();
			while ($this->db->next_record())
			{
				$ids[] = (int)$this->db->f('id');
			}

			return $ids;
		}

		/**
		 * How many occurrences a cascade of the given scope would reach, and how
		 * many of those carry a price somebody set by hand. This is the "%1 of %2"
		 * the conflict dialog shows, and it is answered here rather than counted in
		 * the browser because it is the number an officer decides an overwrite on.
		 *
		 * The edited allocation is excluded from both figures: it is the row he is
		 * already looking at, not one of the rows he is being warned about.
		 *
		 * @return array total and locked, both int
		 */
		public function get_group_scope_summary( $allocation_group_id, $exclude_id, $from_ = null )
		{
			$summary = array('total' => 0, 'locked' => 0);

			if (!(int)$allocation_group_id)
			{
				return $summary;
			}

			$this->db->query("SELECT COUNT(*) AS total, COALESCE(SUM(price_locked), 0) AS locked"
				. " FROM bb_allocation"
				. $this->group_scope_where($allocation_group_id, $exclude_id, $from_), __LINE__, __FILE__);

			if ($this->db->next_record())
			{
				$summary['total'] = (int)$this->db->f('total');
				$summary['locked'] = (int)$this->db->f('locked');
			}

			return $summary;
		}

		/**
		 * Write a price onto an allocation whose price was set by hand, and nothing
		 * else. This is the "Overskriv alle" override, and it is deliberately not
		 * bo->update(): that writes back every column of an entity re-read from the
		 * database, and these are rows the officer has not seen in a form. A
		 * statement naming two columns cannot move a date, a resource or an
		 * organization, and that is the only part of the narrowing enforced by
		 * something other than convention.
		 *
		 * Two of the side effects bo->update() provides therefore have to be
		 * carried here by hand:
		 *
		 *  - the cost history row, because an overwrite of a hand-set price is the
		 *    one event in this feature that most needs explaining afterwards;
		 *  - the cost on an un-exported bb_completed_reservation, because that row
		 *    is what gets invoiced, and leaving it behind would bill the old price.
		 *
		 * The third - the webhook notification - is not carried; the reason is in
		 * booking_uiallocation::cascade_group_price().
		 *
		 * price_locked is cleared, and that is the point rather than a detail:
		 *
		 *   price_locked = 1 MEANS: this price was set by hand AND STILL DIFFERS
		 *   from the group. After an overwrite it no longer differs, so THE MARK IS
		 *   NO LONGER TRUE and it is cleared.
		 *
		 * Leaving it standing would make the next "update all" warn about
		 * occurrences the officer had already deliberately resolved - the feature
		 * reporting its own output back to him as a conflict.
		 *
		 * @return bool
		 */
		public function update_price_only( $id, $cost, $author, $comment )
		{
			$id = (int)$id;

			if (!$id)
			{
				return false;
			}

			// bb_allocation.cost and bb_allocation_cost.cost are both numeric(10,2).
			// %F rather than %f: the conversion has to stay decimal-point regardless
			// of the locale the request happens to run under.
			$cost = sprintf('%.2F', (float)$cost);

			$this->db->query("INSERT INTO bb_allocation_cost (allocation_id, time, author, comment, cost)"
				. " VALUES ({$id}, now(), '" . $this->db->db_addslashes($author) . "',"
				. " '" . $this->db->db_addslashes($comment) . "', {$cost})", __LINE__, __FILE__);

			$this->db->query("UPDATE bb_allocation SET cost = {$cost}, price_locked = 0"
				. " WHERE id = {$id}", __LINE__, __FILE__);

			$this->db->query("UPDATE bb_completed_reservation SET cost = {$cost}"
				. " WHERE reservation_type = 'allocation'"
				. " AND reservation_id = {$id}"
				. " AND export_file_id IS NULL", __LINE__, __FILE__);

			return true;
		}

		protected function doValidate( $entity, booking_errorstack $errors )
		{
			set_time_limit(300);
			$allocation_id = !empty($entity['id']) ? $entity['id'] : -1;

			// FIXME: Validate: Season contains all resources

			if (count($errors) > 0)
			{
				return; /* Basic validation failed */
			}

			if (false == (bool)intval($entity['active']))
			{
				return; //Don't care about if allocation is within necessary boundaries if dealing with inactivated entity
			}

			$from_ = new DateTime($entity['from_']);
			$to_ = new DateTime($entity['to_']);
			$start = $from_->format('Y-m-d H:i');
			$end = $to_->format('Y-m-d H:i');

			if (strtotime($start) > strtotime($end) || strtotime($start) === strtotime($end))
			{
				$errors['from_'] = lang('Invalid from date');
				return; //No need to continue validation if dates are invalid
			}

			if ($entity['resources'])
			{
				$rids = join(',', array_map("intval", $entity['resources']));
				// Check if we overlap with any existing event
				$this->db->query("SELECT e.id FROM bb_event e
									WHERE e.active = 1 AND
									e.id IN (SELECT event_id FROM bb_event_resource WHERE resource_id IN ($rids)) AND
									((e.from_ >= '$start' AND e.from_ < '$end') OR
						 			 (e.to_ > '$start' AND e.to_ <= '$end') OR
						 			 (e.from_ < '$start' AND e.to_ > '$end'))", __LINE__, __FILE__);
				if ($this->db->next_record())
				{
					$existing_entity = $this->db->f('id');
					$errors[self::ERROR_CONFLICTING_EVENT] = lang('Overlaps with existing event') . " #" . $existing_entity;
				}
				// Check if we overlap with any existing allocation
				$this->db->query("SELECT a.id FROM bb_allocation a
									WHERE a.active=1 AND a.id<>$allocation_id AND
									a.id IN (SELECT allocation_id FROM bb_allocation_resource WHERE resource_id IN ($rids)) AND
									((a.from_ >= '$start' AND a.from_ < '$end') OR
						 			 (a.to_ > '$start' AND a.to_ <= '$end') OR
						 			 (a.from_ < '$start' AND a.to_ > '$end'))", __LINE__, __FILE__);
				if ($this->db->next_record())
				{
					$existing_entity = $this->db->f('id');
					$errors[self::ERROR_CONFLICTING_ALLOCATION] = lang('Overlaps with existing allocation') . " #" . $existing_entity;
				}
				// Check if we overlap with any existing booking
				$this->db->query("SELECT b.id FROM bb_booking b
									WHERE b.active=1 AND b.allocation_id<>$allocation_id AND
									b.id IN (SELECT booking_id FROM bb_booking_resource WHERE resource_id IN ($rids)) AND
									((b.from_ >= '$start' AND b.from_ < '$end') OR
						 			 (b.to_ > '$start' AND b.to_ <= '$end') OR
						 			 (b.from_ < '$start' AND b.to_ > '$end'))", __LINE__, __FILE__);
				if ($this->db->next_record())
				{
					$existing_entity = $this->db->f('id');
					$errors[self::ERROR_CONFLICTING_BOOKING] = lang('Overlaps with existing booking') . " #" . $existing_entity;
				}
			}

			if (!CreateObject('booking.soseason')->timespan_within_season($entity['season_id'], $from_, $to_))
			{
				$errors['season_boundary'] = lang("This booking is not within the selected season");
			}
		}

		function get_resource( $id )
		{
			$this->db->limit_query("SELECT name FROM bb_resource where id=" . intval($id), 0, __LINE__, __FILE__, 1);
			if (!$this->db->next_record())
			{
				return False;
			}
			return $this->db->f('name', true);
		}

		function get_building( $id )
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
			$results = array();
			$results[] = array('id' => 0, 'name' => lang('Not selected'));
			$this->db->query("SELECT id, name FROM bb_building WHERE active != 0 ORDER BY name ASC", __LINE__, __FILE__);
			while ($this->db->next_record())
			{
				$results[] = array('id' => $this->db->f('id', false),
					'name' => $this->db->f('name', true));
			}
			return $results;
		}

		function get_organization( $id )
		{
			$this->db->limit_query("SELECT id FROM bb_organization where id=" . intval($id), 0, __LINE__, __FILE__, 1);
			if (!$this->db->next_record())
			{
				return False;
			}
			return $this->db->f('id', false);
		}

		function get_organizations()
		{
			$results = array();
			$results[] = array('id' => 0, 'name' => lang('Not selected'));
			$this->db->query("SELECT id, name FROM bb_organization WHERE active = 1 ORDER BY name ASC", __LINE__, __FILE__);
			while ($this->db->next_record())
			{
				$results[] = array('id' => $this->db->f('id', false),
					'name' => $this->db->f('name', true));
			}
			return $results;
		}

		function get_season( $id )
		{
			$this->db->limit_query("SELECT id FROM bb_season where id=" . intval($id), 0, __LINE__, __FILE__, 1);
			if (!$this->db->next_record())
			{
				return False;
			}
			return $this->db->f('id', false);
		}

		function get_seasons( $build_id )
		{
			$results = array();
			$results[] = array('id' => 0, 'name' => lang('Not selected'));
			if (isset($build_id))
			{
				$this->db->query("SELECT id, name FROM bb_season WHERE status NOT IN ('ARCHIVED') AND building_id = ($build_id) ORDER BY name ASC", __LINE__, __FILE__);
			}
			else
			{
				$this->db->query("SELECT id, name FROM bb_season WHERE status NOT IN ('ARCHIVED') ORDER BY name ASC", __LINE__, __FILE__);
			}

			while ($this->db->next_record())
			{
				$results[] = array('id' => $this->db->f('id', false),
					'name' => $this->db->f('name', true));
			}
			return $results;
		}

		function get_allocation_id( $allocation )
		{

			$from = "'" . $allocation['from_'] . "'";
			$to = "'" . $allocation['to_'] . "'";
			$org_id = (int)$allocation['organization_id'];
			$season_id = $allocation['season_id'];
			$resources = implode(",", $allocation['resources']);

			if(empty($allocation['resources']))
			{
				return false;
			}

			$sql = "SELECT id FROM bb_allocation ba2 JOIN bb_allocation_resource bar2 ON (ba2.id = bar2.allocation_id) WHERE ba2.from_ = ($from) AND ba2.to_ = ($to) AND ba2.organization_id = ($org_id) AND ba2.season_id = ($season_id) AND  bar2.resource_id IN ($resources)";

			$this->db->limit_query($sql, 0, __LINE__, __FILE__, 1);
			if (!$this->db->next_record())
			{
				return False;
			}
			return $this->db->f('id', false);
		}

		function check_for_booking( $id )
		{
			$id = (int) $id;
			$sql = "SELECT id FROM bb_booking  WHERE allocation_id = ($id)";

			$this->db->limit_query($sql, 0, __LINE__, __FILE__, 1);
			if (!$this->db->next_record())
			{
				return False;
			}
			return $this->db->f('id', false);
		}

		function check_for_booking_between_date($id, $new_date_, $date_var )
		{
			$id = (int) $id;
			$sql = "SELECT id FROM bb_booking  WHERE allocation_id = ($id) AND '$new_date_' != $date_var AND '$new_date_' BETWEEN from_ AND to_";

			$this->db->limit_query($sql, 0, __LINE__, __FILE__, 1);
			if (!$this->db->next_record())
			{
				return False;
			}
			return $this->db->f('id', false);
		}

		public function delete_allocation( $id )
		{
			$id = (int) $id;
			$db = $this->db;
			$db->transaction_begin();

			$table_name = $this->table_name . '_cost';
			$sql = "DELETE FROM $table_name WHERE allocation_id = ($id)";
			$db->query($sql, __LINE__, __FILE__);

			$table_name = $this->table_name . '_resource';
			$sql = "DELETE FROM $table_name WHERE allocation_id = ($id)";
			$db->query($sql, __LINE__, __FILE__);

			$sql = "SELECT id FROM bb_completed_reservation WHERE reservation_id = $id AND reservation_type = 'allocation' AND export_file_id IS NULL";
			$db->query($sql, __LINE__, __FILE__);
			$db->next_record();
			$completed_reservation_id = (int)$db->f('id');
			if($completed_reservation_id)
			{
				$sql = "DELETE FROM bb_completed_reservation_resource WHERE completed_reservation_id = $completed_reservation_id";
				$db->query($sql, __LINE__, __FILE__);

				$sql = "DELETE FROM bb_completed_reservation WHERE id = $completed_reservation_id";
				$db->query($sql, __LINE__, __FILE__);	
			}

			$table_name = $this->table_name;
			$sql = "DELETE FROM $table_name WHERE id = ($id)";
			$db->query($sql, __LINE__, __FILE__);

			return	$db->transaction_commit();
		}

		public function update_id_string($allocation_id = null)
		{
			$db = $this->db;
			$table_name = $this->table_name;
			$sql = "UPDATE $table_name SET id_string = cast(id AS varchar)";
			if ($allocation_id)
			{
				$sql .= " WHERE id = " . (int)$allocation_id;
			}
			$db->query($sql, __LINE__, __FILE__);
		}

		/**
		 * Find list of orders related to allocations - without payments
		 * @return array
		 */
		public function find_expired_orders()
		{
			$sql = "SELECT bb_purchase_order.id"
				. " FROM bb_purchase_order"
				. " LEFT JOIN bb_payment ON bb_purchase_order.id = bb_payment.order_id"
				. " JOIN bb_allocation ON bb_purchase_order.reservation_type = 'allocation' AND bb_purchase_order.reservation_id = bb_allocation.id"
				. " WHERE bb_payment.id IS NULL AND bb_allocation.to_ < now()";

			$orders = array();
			$this->db->query($sql, __LINE__, __FILE__);
			while ($this->db->next_record())
			{
				$orders[] = (int)$this->db->f('id');
			}

			return $orders;
		}

		public function find_expired($update_reservation_time)
		{
			$table_name = $this->table_name;
			$db = $this->db;
			$expired_conditions = $this->find_expired_sql_conditions($update_reservation_time);
			return $this->read(array('filters' => array('where' => $expired_conditions), 'results' => 1000));
		}

		protected function find_expired_sql_conditions($update_reservation_time)
		{
			$table_name = $this->table_name;
//			$now = date('Y-m-d');
			return "({$table_name}.active != 0 AND {$table_name}.completed = 0 AND {$table_name}.to_ < '{$update_reservation_time}')";
		}

		public function complete_expired( &$allocations )
		{
			$table_name = $this->table_name;
			$db = $this->db;
			$ids = join(', ', array_map(array($this, 'select_id'), $allocations));
			$sql = "UPDATE $table_name SET completed = 1 WHERE {$table_name}.id IN ($ids);";
			$db->query($sql, __LINE__, __FILE__);
		}

		function get_ordered_costs( $id )
		{
			$results = array();
			$this->db->query("SELECT * FROM bb_allocation_cost WHERE allocation_id=($id) ORDER BY time DESC", __LINE__, __FILE__);
			while ($this->db->next_record())
			{
				$results[] = array(
					'time' => $this->db->f('time'),
					'author' => $this->db->f('author', true),
					'comment' => $this->db->f('comment', true),
					'cost' => $this->db->f('cost')
				);
			}
			return $results;
		}
	}
