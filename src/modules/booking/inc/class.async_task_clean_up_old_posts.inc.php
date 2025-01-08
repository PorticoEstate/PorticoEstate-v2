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

		$date_limit = date('Y-m-d', time() - 60 * 60 * 24 * 30 * 6); // 6 months
		//Application
		//SET ACCEPTED
		$sql = "UPDATE bb_application SET status = 'ACCEPTED' WHERE id IN (
		SELECT bb_application.id
		FROM bb_application JOIN bb_application_date ON bb_application.id = bb_application_date.application_id
		LEFT JOIN bb_application_association ON bb_application_association.application_id = bb_application.id
		WHERE bb_application_date.to_ < '{$date_limit}'
		AND status IN ('NEW', 'PENDING')
		AND bb_application_association.type IS NOT NULL
		ORDER BY bb_application.id DESC)";

		$this->db->query($sql);

		//SET REJECTED
		$sql = "UPDATE bb_application SET status = 'REJECTED' WHERE id IN (
		SELECT bb_application.id
		FROM bb_application JOIN bb_application_date ON bb_application.id = bb_application_date.application_id
		LEFT JOIN bb_application_association ON bb_application_association.application_id = bb_application.id
		WHERE bb_application_date.to_ < '{$date_limit}'
		AND status IN ('NEW', 'PENDING')
		AND bb_application_association.type IS NULL
		ORDER BY bb_application.id DESC)";

		$this->db->query($sql);

		//Seasons
		$sql = "UPDATE bb_season SET status = 'ARCHIVED', active = 0 WHERE to_ < '{$date_limit}'";
		$this->db->query($sql);
	}
}
