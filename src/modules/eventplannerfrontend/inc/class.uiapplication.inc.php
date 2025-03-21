<?php

use App\modules\phpgwapi\services\Translation;

phpgw::import_class('eventplanner.uiapplication');

class eventplannerfrontend_uiapplication extends eventplanner_uiapplication
{

	public function __construct()
	{
		Translation::getInstance()->add_app('eventplanner');
		parent::__construct();
		unset($this->fields['modified']);
		unset($this->fields['created']);
		unset($this->fields['contact_email']);
		unset($this->fields['case_officer_name']);
	}

	public function query($relaxe_acl = false)
	{
		$params = $this->bo->build_default_read_params();
		//		$params['filters']['status'] = eventplanner_application::STATUS_APPROVED;
		$values = $this->bo->read($params);
		array_walk($values["results"], array($this, "_add_links"), "eventplannerfrontend.uiapplication.edit");

		return $this->jquery_results($values);
	}
}
