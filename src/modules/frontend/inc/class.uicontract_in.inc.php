<?php

use App\modules\phpgwapi\services\Cache;

phpgw::import_class('frontend.uicontract');

class frontend_uicontract_in extends frontend_uicontract
{

	public function __construct()
	{
		$this->contract_state_identifier = "contract_state_in";
		$this->contracts_per_location_identifier = "contracts_in_per_location";
		//		$this->form_url = "index.php?menuaction=frontend.uicontract_in.index";
		$this->form_url = phpgw::link('/', array('menuaction' => 'frontend.uicontract_in.index'));
		parent::__construct();
		Cache::session_set('frontend', 'tab', $this->locations->get_id('frontend', '.rental.contract_in'));
	}
}
