<?php

// Run with: php tests/information-screen/run.php
class phpgw
{
	public static function import_class($class) {}
}

class booking_uibuilding
{
	public $bo;
	public $phpgwapi_common;
}

class booking_bocommon_authorized
{
	public $so;
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers/Sanitizer.php';
require_once __DIR__ . '/../../src/modules/booking/inc/class.bobooking.inc.php';
require_once __DIR__ . '/../../src/modules/bookingfrontend/inc/class.uibuilding.inc.php';

class ScreenTestController extends bookingfrontend_uibuilding
{
	public function __construct() {}

	public function scheduleFor($ids, $resources = false)
	{
		return $this->get_information_screen_schedule($ids, new DateTime('2026-03-16'), false, $resources);
	}
}

class ScreenTestStorage
{
	public array $calls = [];

	public function get_screen_allocation($id, $from, $to, $resources)
	{
		$this->calls[] = [$id, $resources];
		if ($id >= 30) return [];
		return [[
			'id' => $id * 10, 'resource_id' => $id * 10, 'resource_name' => 'Hall',
			'organization_name' => 'Allocation', 'organization_shortname' => 'A',
			'from_' => '2026-03-16 08:00:00', 'to_' => '2026-03-16 12:00:00',
		]];
	}

	public function get_screen_booking($id, $from, $to, $resources)
	{
		if ($id >= 30) return [];
		return [[
			'id' => $id * 100, 'allocation_id' => $id * 10,
			'resource_id' => $id * 10, 'resource_name' => 'Hall',
			'group_name' => 'Booking', 'group_shortname' => 'B',
			'from_' => '2026-03-16 09:00:00', 'to_' => '2026-03-16 10:00:00',
		]];
	}

	public function get_screen_event($id, $from, $to, $resources)
	{
		if ($id >= 30) return [];
		return [[
			'id' => $id * 1000, 'resource_id' => 999, 'resource_name' => 'Shared room',
			'name' => 'Shared event', 'from_' => '2026-03-17 10:00:00', 'to_' => '2026-03-18 11:00:00',
		]];
	}
}

function controller(): ScreenTestController
{
	$controller = new ScreenTestController();
	$controller->bo = new class
	{
		public function read_single($id) { return ['name' => 'Building <Hall>']; }
	};
	$controller->booking_bo = (new ReflectionClass(booking_bobooking::class))->newInstanceWithoutConstructor();
	$controller->booking_bo->so = new ScreenTestStorage();
	return $controller;
}

function same($expected, $actual): void
{
	if ($expected !== $actual)
	{
		throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

$tests = [
	'single building preserves the legacy schedule shape' => function ()
	{
		$controller = controller();
		$result = $controller->scheduleFor(10, [100]);
		same('Building <Hall>', $result['name']);
		same(['Hall'], array_keys($result['schedule']['results']['Mon']));
		same([[10, 'AND bb_resource.id IN (100)']], $controller->booking_bo->so->calls);
		same($result, $controller->scheduleFor([10], [100]));
	},
	'multiple buildings keep same-named resources separate and split allocations repeatedly' => function ()
	{
		$controller = controller();
		$result = $controller->scheduleFor([10, 20], [100, 200, 999])['schedule'];
		same(3, $result['total_records']);
		same(['Building <Hall> (#10) / Hall', 'Building <Hall> (#20) / Hall'], array_keys($result['results']['Mon']));
		foreach ($result['results']['Mon'] as $bookings)
		{
			same(['08:00:00', '09:00:00', '10:00:00'], array_keys($bookings));
			same(['allocation', 'booking', 'allocation'], array_column($bookings, 'type'));
			same([2, 2, 4], array_column($bookings, 'colspan'));
		}
		same([[10, 'AND bb_resource.id IN (100,200,999)'], [20, 'AND bb_resource.id IN (100,200,999)']],
			$controller->booking_bo->so->calls);
	},
	'shared resources appear once and multiday events retain all days' => function ()
	{
		$result = controller()->scheduleFor([10, 20])['schedule']['results'];
		foreach (['Tue', 'Wed'] as $day)
		{
			same(['Building <Hall> (#10) / Shared room'], array_keys($result[$day]));
			$booking = reset($result[$day]['Building <Hall> (#10) / Shared room']);
			same(999, $booking['resource_id']);
			same('event', $booking['type']);
		}
	},
	'duplicate buildings are read once and empty schedules are allowed' => function ()
	{
		$controller = controller();
		$result = $controller->scheduleFor([30, 30]);
		same(['total_records' => 0, 'results' => []], $result['schedule']);
		same([[30, '']], $controller->booking_bo->so->calls);
		same(['total_records' => 0, 'results' => []], $controller->scheduleFor([30, 31])['schedule']);
	},
	'invalid or nested building IDs are rejected before accessing data' => function ()
	{
		foreach ([[], null, 0, -1, [10, [20]], [10, 0]] as $ids)
		{
			$controller = controller();
			try
			{
				$controller->scheduleFor($ids);
				throw new RuntimeException('Invalid building IDs accepted');
			}
			catch (InvalidArgumentException $expected)
			{
				same([], $controller->booking_bo->so->calls);
			}
		}
	},
	'HTML output escapes building labels and tolerates empty days' => function ()
	{
		$_GET = $_REQUEST = ['id' => [10, 20], 'date' => '2026-03-16', 'weekend' => '2'];
		$controller = controller();
		$controller->phpgwapi_common = new class { public function phpgw_exit() {} };
		ob_start();
		try
		{
			$controller->information_screen();
			$html = ob_get_contents();
		}
		finally
		{
			ob_end_clean();
		}
		same(true, str_contains($html, 'Building &lt;Hall&gt; (#10) / Hall'));
		same(true, str_contains($html, 'Building &lt;Hall&gt; (#20) / Hall'));
		same(false, str_contains($html, '<Hall>'));
	},
	'JSON request consumes building and resource arrays through the real sanitizer' => function ()
	{
		$_GET = $_REQUEST = ['id' => ['10', '20'], 'date' => '2026-03-16', 'resource_id' => ['100', '200'],
			'phpgw_return_as' => 'json'];
		$controller = controller();
		$result = $controller->information_screen();
		same(['Building <Hall> (#10) / Hall', 'Building <Hall> (#20) / Hall'], array_keys($result['results']['Mon']));
		same([[10, 'AND bb_resource.id IN (100,200)'], [20, 'AND bb_resource.id IN (100,200)']],
			$controller->booking_bo->so->calls);
	},
];

$failures = 0;
set_error_handler(static function ($severity, $message, $file, $line)
{
	if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
	return false;
});
ob_start();
foreach ($tests as $name => $test)
{
	try
	{
		$test();
		echo "PASS: $name\n";
	}
	catch (Throwable $error)
	{
		$failures++;
		echo "FAIL: $name: {$error->getMessage()}\n";
	}
}
echo count($tests) . " tests, $failures failures\n";
ob_end_flush();
exit($failures ? 1 : 0);
