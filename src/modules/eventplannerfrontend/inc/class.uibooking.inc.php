<?php

use App\modules\phpgwapi\services\Translation;

phpgw::import_class('eventplanner.uibooking');
include_class('eventplanner', 'application', 'inc/model/');
class eventplannerfrontend_uibooking extends eventplanner_uibooking
{

	public function __construct()
	{
		Translation::getInstance()->add_app('eventplanner');
		parent::__construct();
	}

	public function index()
	{
		if (empty($this->permissions[ACL_READ]))
		{
			phpgw::no_access();
		}

		if (Sanitizer::get_var('phpgw_return_as') == 'json')
		{
			return $this->query();
		}

		self::set_active_menu("{$this->currentapp}::customer::booking");
		parent::index();
	}

	public function query($relaxe_acl = false)
	{
		$params = $this->bo->build_default_read_params();
		//		$params['filters']['status'] = eventplanner_application::STATUS_APPROVED;
		$values = $this->bo->read($params);
		array_walk($values["results"], array($this, "_add_links"), "eventplannerfrontend.uibooking.edit");

		return $this->jquery_results($values);
	}

	public function query_relaxed()
	{
		$params = $this->bo->build_default_read_params();
		$params['relaxe_acl'] = true;
		$params['filters']['status'] = eventplanner_application::STATUS_APPROVED;
		$values = $this->bo->read($params);
		array_walk($values["results"], array($this, "_add_links"), "eventplannerfrontend.uibooking.edit");

		return $this->jquery_results($values);
	}
}
