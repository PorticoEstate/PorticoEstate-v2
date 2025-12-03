<?php

use App\Database\Db;

phpgw::import_class('booking.async_task');

class booking_async_task_postToAccountingSystem extends booking_async_task
{

	private $db;

	public function __construct()
	{
		parent::__construct();
	}

	public function get_default_times()
	{
		return array('day' => '*/1');
	}

	public function run($options = array())
	{
		echo "(disabled): Running booking_async_task_postToAccountingSystem<br/>\n";
		//$vipps_helper = CreateObject('bookingfrontend.vipps_helper');
		//$vipps_helper->postToAccountingSystem();
	}

}
