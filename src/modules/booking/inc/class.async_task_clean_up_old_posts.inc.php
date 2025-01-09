<?php

use App\Database\Db;
use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.async_task');

/**
 * Delete the Anonymous frontend-user from access-log
 */
class booking_async_task_clean_up_old_posts extends booking_async_task
{
	var $db;
	public function __construct()
	{
		parent::__construct();
		$this->db = Db::getInstance();
	}

	public function get_default_times()
	{
		return array('day' => '*/1');
	}

	public function run($options = array())
	{

		$date_limit = date('Y-m-d', time() - 60 * 60 * 24 * 30 * 4); // 4 months

		// Fetch all IDs into an array
		$sql_fetch_ids = "SELECT DISTINCT bb_application.id
		FROM bb_application
		JOIN bb_application_date ON bb_application.id = bb_application_date.application_id
		LEFT JOIN bb_application_association ON bb_application_association.application_id = bb_application.id
		WHERE bb_application_date.to_ < '{$date_limit}'
		AND status IN ('NEW', 'PENDING')
		AND bb_application_association.type IS NOT NULL
		ORDER BY bb_application.id DESC";

		$this->db->query($sql_fetch_ids);
		$ids = [];

		while ($this->db->next_record())
		{
			$ids[] = $this->db->f('id');
		}

		// Loop through the array and perform the update
		foreach ($ids as $id)
		{
			$sql_update = "UPDATE bb_application SET status = 'ACCEPTED' WHERE id = {$id}";
			$this->db->query($sql_update);

			$value_set = array(
				'application_id' => $id,
				'time'			 => date('Y-m-d H:i:s'),
				'author'		 => 'Cronjob',
				'comment'		 => 'Status changed to ACCEPTED by cronjob',
				'type'			 => 'comment',
			);

			$this->db->query('INSERT INTO bb_application_comment (' . implode(',', array_keys($value_set)) . ') VALUES ('
				. $this->db->validate_insert(array_values($value_set)) . ')', __LINE__, __FILE__);
		}

		// Fetch all IDs into an array for REJECTED status
		$sql_fetch_ids_rejected = "SELECT DISTINCT bb_application.id
		FROM bb_application
		JOIN bb_application_date ON bb_application.id = bb_application_date.application_id
		LEFT JOIN bb_application_association ON bb_application_association.application_id = bb_application.id
		WHERE bb_application_date.to_ < '{$date_limit}'
		AND status IN ('NEW', 'PENDING')
		AND bb_application_association.type IS NULL
		ORDER BY bb_application.id DESC";

		$this->db->query($sql_fetch_ids_rejected);
		$ids_rejected = [];

		while ($this->db->next_record())
		{
			$ids_rejected[] = $this->db->f('id');
		}


		// Loop through the array and perform the update for REJECTED status
		foreach ($ids_rejected as $id)
		{
			$sql_update_rejected = "UPDATE bb_application SET status = 'REJECTED' WHERE id = {$id}";
			$this->db->query($sql_update_rejected);

			$value_set = array(
				'application_id' => $id,
				'time'			 => date('Y-m-d H:i:s'),
				'author'		 => 'Cronjob',
				'comment'		 => 'Status changed to REJECTED by cronjob',
				'type'			 => 'comment',
			);

			$this->db->query('INSERT INTO bb_application_comment (' . implode(',', array_keys($value_set)) . ') VALUES ('
				. $this->db->validate_insert(array_values($value_set)) . ')', __LINE__, __FILE__);
		}

		//Seasons
		$sql = "UPDATE bb_season SET status = 'ARCHIVED', active = 0 WHERE to_ < '{$date_limit}'";
		$this->db->query($sql);
	}
}
