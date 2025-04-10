<?php

use App\modules\phpgwapi\services\Translation;

phpgw::import_class('eventplanner.uicalendar');
include_class('eventplanner', 'application', 'inc/model/');
class eventplannerfrontend_uicalendar extends eventplanner_uicalendar
{

	public function __construct()
	{
		Translation::getInstance()->add_app('eventplanner');
		parent::__construct();
	}

	public function query($relaxe_acl = false)
	{
		$params = $this->bo->build_default_read_params();
		$params['filters']['status'] = eventplanner_application::STATUS_APPROVED;
		$values = $this->bo->read($params);
		array_walk($values["results"], array($this, "_add_links"), "eventplannerfrontend.uicalendar.edit");

		return $this->jquery_results($values);
	}

	public function query_relaxed()
	{
		$params = $this->bo->build_default_read_params();
		$params['relaxe_acl'] = true;
		$params['filters']['status'] = eventplanner_application::STATUS_APPROVED;
		$values = $this->bo->read($params);
		$redirect = Sanitizer::get_var('redirect');
		if ($redirect == 'booking')
		{
			array_walk($values["results"], array($this, "_add_links2"), "{$this->currentapp}.uibooking.edit");
		}
		else
		{
			array_walk($values["results"], array($this, "_add_links"), "{$this->currentapp}.uicalendar.edit");
		}

		return $this->jquery_results($values);
	}
}
