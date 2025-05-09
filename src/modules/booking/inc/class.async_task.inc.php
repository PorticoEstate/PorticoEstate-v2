<?php

use App\modules\phpgwapi\services\AsyncService;
use App\modules\phpgwapi\services\Settings;


phpgw::import_class('booking.async_task_update_reservation_state');

class booking_async_task
{

	protected static $task_instances = array();
	protected $asyncservice, $serverSettings, $userSettings, $flags;
	protected $global_lock = false;

	function __construct()
	{

		$this->asyncservice = AsyncService::getInstance();
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->flags = Settings::getInstance()->get('flags');
	}

	public function doRun($task_args)
	{
		$task_class = $task_args['task_class'];
		if (in_array($task_class, self::getAvailableTasks()))
		{
			$task = self::create($task_class);
			$task->run($task_args);
		}
	}

	public static function getAvailableTasks()
	{
		return array(
			'booking.async_task_update_reservation_state',
			'booking.async_task_send_reminder',
			'booking.async_task_send_access_request',
			'booking.async_task_delete_participants',
			'booking.async_task_delete_expired_blocks',
			'booking.async_task_delete_access_log',
			'booking.async_task_clean_up_old_posts',
			'booking.async_task_anonyminizer',
			'booking.async_task_postToAccountingSystem',
		);
	}

	public static function create($task_class)
	{
		$task = null;
		if (!isset(self::$task_instances[$task_class]))
		{
			$task = CreateObject($task_class);
			self::$task_instances[$task_class] = $task;
		}

		return self::$task_instances[$task_class];
	}

	public function get_task_id()
	{
		return get_class($this);
	}

	public function get_default_times()
	{
		/* array('min' => '1', 'hour'  => '0', 'dow'  => '*', 'day'  => '*', 'month' => '*', 'year' => '*'), */
		return array(
			'min' => '*',
			'hour' => '*',
			'dow' => '*',
			'day' => '*',
			'month' => '*',
			'year' => '*'
		);
	}

	public function is_enabled()
	{
		return is_array($this->asyncservice->read($this->get_task_id()));
	}

	public function disable()
	{
		$this->asyncservice->cancel_timer($this->get_task_id());
	}

	public function enable($times = null)
	{
		if ($times === null)
		{
			$times = $this->get_default_times();
		}

		list($task_appname, $task_class) = explode('_', get_class($this), 2);
		list($appname, $class) = explode('_', __CLASS__, 2);

		$this->asyncservice->set_timer(
			$times === null ? $this->get_default_times() : $times,
			$this->get_task_id(),
			"{$appname}.{$class}.doRun",
			array(
				'task_class' => "{$task_appname}.{$task_class}"
			)
		);
	}

	public function run($options = array())
	{
	}
}
