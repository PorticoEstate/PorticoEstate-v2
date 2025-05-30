<?php

use App\modules\phpgwapi\security\Acl;

phpgw::import_class('booking.uicommon');

class booking_uimetasettings extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
	);

	var $fields;
	public function __construct()
	{
		parent::__construct();
		$is_admin = $this->acl->check('run', Acl::READ, 'admin');
		$local_admin = false;
		if (!$is_admin)
		{
			if ($this->acl->check('admin', Acl::ADD, 'bookingfrontend'))
			{
				$local_admin = true;
			}
		}

		if (!$is_admin && !$local_admin)
		{
			phpgw::no_access();
		}

		parent::__construct();
		self::set_active_menu('admin::bookingfrontend::metasettings');
		$this->fields = array(
			'metatag_author' => 'string',
			'metatag_robots' => 'string',
			'frontpagetitle' => 'string',
			'frontpagetext' => 'html',
			'frontimagetext' => 'html',
			'participanttext' => 'html'
		);
	}

	public function index()
	{
		$appname = Sanitizer::get_var('appname');
		$appname = $appname ? $appname : 'booking';
		if (!$this->acl->check('admin', Acl::ADD, $appname))
		{
			phpgw::no_access();
		}

		$config = CreateObject('phpgwapi.config', $appname);
		$config->read();

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$metasettings = extract_values($_POST, $this->fields);

			foreach ($metasettings as $dim => $value)
			{
				if (strlen(trim($value)) > 0)
				{
					$config->value($dim, $value);
				}
				else
				{
					unset($config->config_data[$dim]);
				}
			}
			$config->save_repository();
		}

		$tabs = array();
		$tabs['meta'] = array('label' => lang('metadata settings'), 'link' => '#meta');
		$active_tab = 'meta';

		$meta['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);
		self::rich_text_editor('field_frontpagetext');
		self::rich_text_editor('field_frontimagetext');
		self::rich_text_editor('field_participanttext');

		self::render_template_xsl('metasettings', array(
			'config_data' => $config->config_data,
			'meta' => $meta
		));
	}

	function query()
	{
	}
}
