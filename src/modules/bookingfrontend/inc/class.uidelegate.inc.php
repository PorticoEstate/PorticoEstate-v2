<?php

use App\modules\bookingfrontend\helpers\UserHelper;
	phpgw::import_class('booking.uidelegate');

	class bookingfrontend_uidelegate extends booking_uidelegate
	{

		public $public_functions = array
			(
			'index' => true,
			'edit' => true,
			'show' => true,
			'toggle_show_inactive' => true,
		);
		protected $module;

		public function __construct()
		{
			parent::__construct();
			$this->module = "bookingfrontend";
		}

		public function show()
		{
			$delegate = $this->bo->read_single(Sanitizer::get_var('id', 'int'));
			$delegate['organizations_link'] = self::link(array('menuaction' => $this->module . '.uiorganization.index'));
			$delegate['organization_link'] = self::link(array('menuaction' => $this->module . '.uiorganization.show',
					'id' => $delegate['organization_id']));
			$delegate['edit_link'] = self::link(array('menuaction' => $this->module . '.uidelegate.edit',
					'id' => $delegate['id']));

			$data = array(
				'delegate' => $delegate
			);

			$edit_self_link = self::link(array('menuaction' => 'bookingfrontend.uidelegate.edit',
					'id' => $delegate['id']));

			$bouser = new UserHelper();
			$auth_forward = "?redirect_menuaction={$this->module}.uidelegate.show&redirect_id={$delegate['id']}";
			$delegate['login_link'] = 'login/' . $auth_forward;
			$delegate['logoff_link'] = 'logoff.php' . $auth_forward;
			if ($bouser->is_organization_admin())
			{
				$delegate['logged_on'] = true;
			}

			self::render_template_xsl('delegate', array('delegate' => $delegate, 'loggedin' => $delegate['logged_on'],
				'edit_self_link' => $edit_self_link));
		}
		public function index()
		{
			if (Sanitizer::get_var('phpgw_return_as') == 'json')
			{
				return $this->query();
			}

			phpgw::no_access();
		}
	}