<?php

use App\modules\phpgwapi\services\Settings;

phpgw::import_class('booking.uicommon');
phpgw::import_class('booking.account_helper');
phpgw::import_class('booking.unauthorized_exception');

class booking_uiasync_settings extends booking_uicommon
{

	public $public_functions = array(
		'index' => true,
		'query' => true
	);
	protected $fields = array(
		'booking_async_task_update_reservation_state_enabled',
		'booking_async_task_send_reminder_enabled',
		'booking_async_task_send_access_request_enabled',
		'booking_async_task_delete_participants_enabled',
		'booking_async_task_delete_expired_blocks_enabled',
		'booking_async_task_delete_access_log_enabled',
		'booking_async_task_anonyminizer_enabled',
		'booking_async_task_clean_up_old_posts_enabled'
	);

	public function __construct()
	{
		parent::__construct();

		self::process_booking_unauthorized_exceptions();
		$this->bo = CreateObject('booking.boasync_settings');

		self::set_active_menu('booking::settings::async_settings');
		Settings::getInstance()->update('flags', ['app_header' => lang('booking') . '::' . lang('Asynchronous Tasks')]);
	}

	public function index()
	{
		$settings = $this->bo->read();

		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			$settings['booking_async_task_update_reservation_state_enabled'] = Sanitizer::get_var('booking_async_task_update_reservation_state_enabled', 'bool', 'POST');
			$settings['booking_async_task_send_reminder_enabled'] = Sanitizer::get_var('booking_async_task_send_reminder_enabled', 'bool', 'POST');
			$settings['booking_async_task_send_access_request_enabled'] = Sanitizer::get_var('booking_async_task_send_access_request_enabled', 'bool', 'POST');
			$settings['booking_async_task_delete_participants_enabled'] = Sanitizer::get_var('booking_async_task_delete_participants_enabled', 'bool', 'POST');
			$settings['booking_async_task_delete_expired_blocks_enabled'] = Sanitizer::get_var('booking_async_task_delete_expired_blocks_enabled', 'bool', 'POST');
			$settings['booking_async_task_delete_access_log_enabled'] = Sanitizer::get_var('booking_async_task_delete_access_log_enabled', 'bool', 'POST');
			$settings['booking_async_task_anonyminizer_enabled'] = Sanitizer::get_var('booking_async_task_anonyminizer_enabled', 'bool', 'POST');
			$settings['booking_async_task_clean_up_old_posts_enabled'] = Sanitizer::get_var('booking_async_task_clean_up_old_posts_enabled', 'bool', 'POST');
			$this->bo->update($settings);
		}

		$tabs = array();
		$tabs['generic'] = array('label' => lang('Asynchronous Tasks'), 'link' => '#async_settings');
		$active_tab = 'generic';


		$settings['tabs'] = phpgwapi_jquery::tabview_generate($tabs, $active_tab);

		self::render_template_xsl('async_settings_form', array('settings' => $settings));
	}

	public function query()
	{
	}
}
