<?php
	phpgw::import_class('booking.bocommon_global_manager_authorized');

	class booking_boresource_activity_entityform extends booking_bocommon_global_manager_authorized
	{

		function __construct()
		{
			parent::__construct();
			$this->so = CreateObject('booking.soresource_activity_entityform');
		}

		public function set_active_session()
		{
			$_SESSION['ActiveSession'] = "ShowAll";
		}

		public function unset_active_session()
		{
			unset($_SESSION['ActiveSession']);
		}

	}