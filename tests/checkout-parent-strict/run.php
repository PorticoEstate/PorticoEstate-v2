<?php

/**
 * Hermetic test: checkout (POST /bookingfrontend/applications/partials/checkout) combines a building's applications
 * only under one of the applications in that checkout, named by its id, and stores the id it checked.
 *
 *   php tests/checkout-parent-strict/run.php
 *   (in the pe-api container: docker exec -w /var/www/html pe-api php tests/checkout-parent-strict/run.php)
 *
 * What is real and what is replaced:
 *   - ApplicationService::checkoutPartials() and what it calls inside the service (updateApplicationsWithContactInfo,
 *     validateBuildingParentIdConstraints, the direct-booking checks, createEventForApplication, the notifications)
 *     are the REAL code of the tree under test, on an instance made without its constructor (which opens the database).
 *   - Its database is a stand-in that keeps the transaction state and answers the two resource queries the
 *     direct-booking checks make; any other query is a failure. Its repository is a recorder that holds the session's
 *     applications and keeps every write it is handed. The article repository, the user and the mailer are stand-ins,
 *     and so is EventService, which createEventForApplication() makes with new.
 *   - CheckoutController::checkout() is real, on that service, for the answer a request gets. Sessions is a stand-in
 *     that holds one session id.
 *   - ApplicationService requires a legacy include at SRC_ROOT_PATH; SRC_ROOT_PATH is a temporary directory where that
 *     include is empty, and createObject() answers only the e-mail validator checkout asks for.
 *   - Every other App\ class is loaded from PP_ROOT and from nowhere else.
 *
 * The session's applications: A and B in building 10, C in building 20, and RC in building 10 with recurring_info.
 * NOT_IN_CHECKOUT and MISSING are ids that are not among them.
 *
 * Groups:
 *   S  setup: the service and the controller came from PP_ROOT.
 *   C  the stand-ins: the repository holds the four applications (as ints, or as strings of digits), and the
 *      database answers the direct-booking queries as each run asks; the refusal is an Exception. Without these a
 *      green row could be green for nothing.
 *   G  accepted, on the case-handled path (NEW), the direct path (ACCEPTED) and the collision path (REJECTED), with
 *      the repository's ids as ints and as strings of digits (a driver may hand out either):
 *      building_parent_ids naming the session's own applications as ints and as strings of digits, absent, empty or
 *      null (each building falls back to its first application), and the legacy parent_id key, which uses an own
 *      application and is otherwise ignored. Every parent_id written is the int that was checked, or null; recurring
 *      applications get null; the transaction is committed and the mail goes out.
 *   R  refused, nothing written: a parent that is not one of the session's applications (an id from elsewhere, an
 *      unknown id, 0, negative, beyond the integer column), a value that is not an id (bool, null, float, padded,
 *      signed, leading zero, decimal, exponent, empty, array), a parent in another building, and a building_parent_ids
 *      that is not a map. The refusal is a ParentApplicationChoiceException, no status or parent_id is written, the
 *      transaction is rolled back, and no mail is sent and no event made.
 *   A  the answer: through the controller every R row gets 400; the id refusals get one byte-identical body that does
 *      not repeat the value sent.
 *   X  unchanged: a missing price choice is still 422, a checkout without applications still 500, several
 *      validation errors still 400 with the list.
 *
 * Red first: against a tree without the check the R and G rows fail:
 *   git archive <a commit before the change> src | tar -x -C /tmp/cps-old
 *   PP_ROOT=/tmp/cps-old php tests/checkout-parent-strict/run.php
 * mutate.py applies single defects to a copy and expects this harness to go red for each.
 *
 * Env (optional): PP_ROOT = tree holding src/ (default: this repo). PP_AUTOLOAD = composer's vendor/autoload.php,
 * for the vendor packages only (default: <PP_ROOT>/vendor/autoload.php, then <this repo>/vendor/autoload.php).
 *
 * Exit code: 0 all pass, 1 any failure (an arm that throws something unexpected is a FAIL line, not a crash).
 */

namespace App\modules\phpgwapi\security
{
	/** Stand-in: checkout() asks it for the session id and nothing else. */
	final class Sessions
	{
		private static ?Sessions $instance = null;

		public static function getInstance(): self
		{
			return self::$instance ??= new self();
		}

		public function get_session_id(): string
		{
			return 'cps-harness-session';
		}
	}
}

namespace App\modules\bookingfrontend\services
{
	/** Stand-in: createEventForApplication() makes one with new; the real one opens the database. */
	final class EventService
	{
		public static array $made = [];
		public object $repository;

		public function __construct()
		{
			$this->repository = new class {
				public function updateIdString(): void
				{
				}
			};
		}

		public function createFromApplication(array $application, $date)
		{
			self::$made[] = $application['id'];
			return null;
		}
	}
}

namespace
{
	use App\modules\bookingfrontend\controllers\applications\CheckoutController;
	use App\modules\bookingfrontend\helpers\PriceChoiceException;
	use App\modules\bookingfrontend\models\Application;
	use App\modules\bookingfrontend\services\applications\ApplicationService;
	use App\modules\bookingfrontend\services\EventService;
	use Slim\Psr7\Factory\ResponseFactory;
	use Slim\Psr7\Factory\ServerRequestFactory;
	use Slim\Psr7\Factory\StreamFactory;

	const A = 700001;        // building 10
	const B = 700002;        // building 10
	const C = 700003;        // building 20
	const RC = 700004;       // building 10, recurring
	const NOT_IN_CHECKOUT = 700099;  // an application id that is not one of this checkout's
	const MISSING = 2147483000;
	const INT_COLUMN_MAX = 2147483647;
	const REFUSAL = 'ParentApplicationChoiceException';

	putenv('REDIS_HOST=disabled.invalid');
	ini_set('error_log', '/dev/null');

	$root = rtrim(getenv('PP_ROOT') ?: dirname(__DIR__, 2), '/');
	$here = dirname(__DIR__, 2);
	$autoload = getenv('PP_AUTOLOAD') ?: (is_file("$root/vendor/autoload.php") ? "$root/vendor/autoload.php" : "$here/vendor/autoload.php");

	$results = ['pass' => 0, 'fail' => 0];
	function check(string $group, string $label, bool $ok, string $detail = ''): void
	{
		global $results;
		$results[$ok ? 'pass' : 'fail']++;
		printf("%s %-2s %s%s\n", $ok ? 'PASS' : 'FAIL', $group, $label, $detail === '' ? '' : "  [$detail]");
	}

	function show($value): string
	{
		return str_replace("\n", '', var_export($value, true));
	}

	if (!is_dir("$root/src") || !is_file($autoload))
	{
		check('S', 'setup', false, "no $root/src or no autoload at $autoload");
		exit(1);
	}

	// The legacy include ApplicationService requires, empty
	$legacyRoot = sys_get_temp_dir() . '/cps-harness-' . getmypid();
	@mkdir("$legacyRoot/helpers", 0700, true);
	file_put_contents("$legacyRoot/helpers/LegacyObjectHandler.php", "<?php\n");
	register_shutdown_function(static function () use ($legacyRoot): void {
		@unlink("$legacyRoot/helpers/LegacyObjectHandler.php");
		@rmdir("$legacyRoot/helpers");
		@rmdir($legacyRoot);
	});
	define('SRC_ROOT_PATH', $legacyRoot);

	/** Stand-in for the legacy factory: checkout's data check asks it for the e-mail validator only. */
	function createObject(string $class, ...$args)
	{
		if ($class !== 'booking.sfValidatorEmail')
		{
			throw new LogicException("createObject('$class') is not expected here");
		}
		return new class {
			public function clean($value)
			{
				return $value;
			}
		};
	}

	// App\ from PP_ROOT only. Composer registers its loader in FRONT of the queue, so it is loaded first and this
	// one is then put in front of it.
	require $autoload;
	spl_autoload_register(static function (string $class) use ($root): void {
		if (strncmp($class, 'App\\', 4) !== 0)
		{
			return;
		}
		$file = "$root/src/" . str_replace('\\', '/', substr($class, 4)) . '.php';
		if (!is_file($file))
		{
			throw new RuntimeException("$class is not in PP_ROOT ($file)");
		}
		require $file;
	}, true, true);

	final class FakeDb
	{
		public bool $direct = false;
		public int $begun = 0;
		public int $committed = 0;
		public int $rolledBack = 0;
		private bool $open = false;

		public function beginTransaction(): bool
		{
			if ($this->open)
			{
				throw new LogicException('beginTransaction() inside a transaction');
			}
			$this->open = true;
			$this->begun++;
			return true;
		}

		public function inTransaction(): bool
		{
			return $this->open;
		}

		public function commit(): bool
		{
			$this->open = false;
			$this->committed++;
			return true;
		}

		public function rollBack(): bool
		{
			$this->open = false;
			$this->rolledBack++;
			return true;
		}

		public function prepare(string $sql): object
		{
			$db = $this;
			return new class($db, $sql) {
				public function __construct(private FakeDb $db, private string $sql)
				{
				}

				public function bindValue($key, $value, $type = null): bool
				{
					return true;
				}

				public function bindParam($key, &$value, $type = null): bool
				{
					return true;
				}

				public function execute(?array $params = null): bool
				{
					return true;
				}

				public function fetchAll($mode = null): array
				{
					if (str_contains($this->sql, 'JOIN bb_building_resource'))
					{
						// isEligibleForDirectBooking(): one resource, direct-bookable since 1970 or not at all
						return [['id' => 950, 'direct_booking' => $this->db->direct ? 1 : null,
							'booking_limit_number' => 0, 'booking_limit_number_horizont' => 0]];
					}
					throw new LogicException('unexpected query: ' . preg_replace('/\s+/', ' ', $this->sql));
				}

				public function fetchColumn()
				{
					if (str_contains($this->sql, 'r.direct_booking IS NULL'))
					{
						// applicationHasCaseManagedResource()
						return $this->db->direct ? false : 1;
					}
					throw new LogicException('unexpected query: ' . preg_replace('/\s+/', ' ', $this->sql));
				}

				public function fetch($mode = null)
				{
					throw new LogicException('unexpected query: ' . preg_replace('/\s+/', ' ', $this->sql));
				}
			};
		}
	}

	final class RepositoryRecorder
	{
		/** @var array<int, array{0: int|null, 1: array}> every patchApplicationMainData() call: [id, data] */
		public array $writes = [];
		public bool $collision = false;
		public array $applications;

		public function __construct(array $applications)
		{
			$this->applications = $applications;
		}

		public function getPartialApplications(string $session_id): array
		{
			return $this->applications;
		}

		public function patchApplicationMainData(array $data, ?int $id = null): void
		{
			$this->writes[] = [$id, $data];
		}

		public function checkCollisionWithDebug($resources, $from, $to, $session_id): array
		{
			return ['has_collision' => $this->collision];
		}

		public function getFullApplication(int $id): ?Application
		{
			$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$application->id = $id;
			$application->status = 'NEW';
			$application->dates = [['from_' => '2027-11-02 08:00:00', 'to_' => '2027-11-02 09:00:00']];
			$application->resources = [['id' => 950]];
			return $application;
		}

		/** The parent_id each application got, by id; an application written without one is absent. */
		public function parents(): array
		{
			$parents = [];
			foreach ($this->writes as [$id, $data])
			{
				if (array_key_exists('parent_id', $data))
				{
					$parents[$id] = $data['parent_id'];
				}
			}
			ksort($parents);
			return $parents;
		}

		/** The statuses written, by id. */
		public function statuses(): array
		{
			$statuses = [];
			foreach ($this->writes as [$id, $data])
			{
				if (array_key_exists('status', $data))
				{
					$statuses[$id] = $data['status'];
				}
			}
			ksort($statuses);
			return $statuses;
		}

		public function __call(string $name, array $arguments)
		{
			throw new LogicException("the repository recorder does not know $name()");
		}
	}

	final class Mailer
	{
		public array $sent = [];

		public function __call(string $name, array $arguments)
		{
			$this->sent[] = $name;
		}
	}

	function application(int $id, int $buildingId, bool $recurring = false): array
	{
		return [
			'id' => $id,
			'building_id' => $buildingId,
			'building_name' => "Building $buildingId",
			'recurring_info' => $recurring ? '{"field_interval":1}' : null,
			'session_id' => 'cps-harness-session',
			'customer_identifier_type' => 'ssn',
			'resources' => [['id' => 950]],
			'dates' => [['from_' => '2027-11-02 08:00:00', 'to_' => '2027-11-02 09:00:00']],
		];
	}

	/**
	 * A service on the stand-ins: [service, db, repository, mailer]. With $stringIds the repository hands out the ids
	 * and building ids as strings of digits, as a database driver may.
	 */
	function service(string $path = 'case', ?Throwable $priceRefusal = null, bool $stringIds = false): array
	{
		$db = new FakeDb();
		$db->direct = $path !== 'case';
		$applications = [application(A, 10), application(B, 10), application(C, 20), application(RC, 10, true)];
		if ($stringIds)
		{
			foreach ($applications as &$application)
			{
				$application['id'] = (string)$application['id'];
				$application['building_id'] = (string)$application['building_id'];
			}
			unset($application);
		}
		$repository = new RepositoryRecorder($applications);
		$repository->collision = $path === 'collision';
		$mailer = new Mailer();
		$articles = new class($priceRefusal) {
			public function __construct(private ?Throwable $refusal)
			{
			}

			public function assertPriceChoicesMade(array $applicationIds): void
			{
				if ($this->refusal)
				{
					throw $this->refusal;
				}
			}
		};
		$user = new class {
			public $ssn = null;

			public function get_session_id()
			{
				return null;
			}
		};

		$class = new ReflectionClass(ApplicationService::class);
		$service = $class->newInstanceWithoutConstructor();
		foreach (['db' => $db, 'applicationRepository' => $repository, 'articleRepository' => $articles,
					 'emailService' => $mailer, 'userHelper' => $user, 'userSettings' => []] as $property => $value)
		{
			$class->getProperty($property)->setValue($service, $value);
		}
		return [$service, $db, $repository, $mailer];
	}

	function body(array $extra = []): array
	{
		return $extra + [
			'organizerName' => 'cps', 'customerType' => 'ssn', 'contactName' => 'cps tester',
			'contactEmail' => 'cps@example.com', 'contactPhone' => '12345678', 'street' => 'Testveien 1',
			'zipCode' => '0150', 'city' => 'Oslo', 'documentsRead' => true, 'eventTitle' => 'cps',
		];
	}

	/** checkoutPartials() as the controller calls it, on a raw JSON body: [thrown or null, db, repository, mailer]. */
	function checkout(string $rawExtra, string $path = 'case', bool $stringIds = false): array
	{
		[$service, $db, $repository, $mailer] = service($path, null, $stringIds);
		$data = json_decode('{' . $rawExtra . '}', true, 512, JSON_THROW_ON_ERROR) + body();
		EventService::$made = [];
		try
		{
			$service->checkoutPartials('cps-harness-session', $data);
			$thrown = null;
		}
		catch (Throwable $e)
		{
			$thrown = $e;
		}
		return [$thrown, $db, $repository, $mailer];
	}

	/** One request through the real CheckoutController::checkout(): [status, raw body]. */
	function request(string $rawBody, ?Throwable $priceRefusal = null, bool $noApplications = false): array
	{
		[$service, , $repository] = service('case', $priceRefusal);
		if ($noApplications)
		{
			$repository->applications = [];
		}
		$class = new ReflectionClass(CheckoutController::class);
		$controller = $class->newInstanceWithoutConstructor();
		$class->getProperty('applicationService')->setValue($controller, $service);

		$request = (new ServerRequestFactory())->createServerRequest('POST', '/bookingfrontend/applications/partials/checkout')
			->withHeader('Content-Type', 'application/json')
			->withBody((new StreamFactory())->createStream($rawBody));
		$response = $controller->checkout($request, (new ResponseFactory())->createResponse());
		$response->getBody()->rewind();
		return [$response->getStatusCode(), (string)$response->getBody()];
	}

	function shortName(?Throwable $e): string
	{
		return $e === null ? 'nothing' : (new ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
	}

	function arm(string $group, string $label, callable $body): void
	{
		try
		{
			$body();
		}
		catch (Throwable $e)
		{
			check($group, $label, false, get_class($e) . ': ' . $e->getMessage());
		}
	}

	// ---------------------------------------------------------------- S
	foreach ([ApplicationService::class, CheckoutController::class] as $loaded)
	{
		$file = (string)(new ReflectionClass($loaded))->getFileName();
		check('S', "$loaded is loaded from PP_ROOT", str_starts_with($file, "$root/src/"), $file);
	}

	// ---------------------------------------------------------------- C
	arm('C', 'stand-ins', static function (): void {
		[, $db, $repository] = service('case');
		check('C', 'the repository holds A, B, C and RC', array_column($repository->getPartialApplications('x'), 'id') === [A, B, C, RC]);
		check('C', 'NOT_IN_CHECKOUT and MISSING are not among them', !array_intersect([NOT_IN_CHECKOUT, MISSING], array_column($repository->applications, 'id')));
		check('C', 'case path: the resource is case-handled', $db->prepare('r.direct_booking IS NULL')->fetchColumn() === 1);
		[, $db] = service('direct');
		check('C', 'direct path: the resource is direct-bookable', $db->prepare('JOIN bb_building_resource')->fetchAll()[0]['direct_booking'] === 1);
		[, , $repository] = service('case', null, true);
		check('C', 'ids as strings: the repository hands out strings of digits',
			array_column($repository->applications, 'id') === [(string)A, (string)B, (string)C, (string)RC]);
		check('C', 'the refusal is an Exception, so the generic catches still hold it',
			is_subclass_of('App\\modules\\bookingfrontend\\helpers\\' . REFUSAL, Exception::class));
	});

	// ---------------------------------------------------------------- G
	$byPath = ['case' => 'NEW', 'direct' => 'ACCEPTED', 'collision' => 'REJECTED'];
	$accepted = [
		'own ids as ints' => ['"building_parent_ids":{"10":' . B . ',"20":' . C . '}', [A => B, B => B, C => C, RC => null]],
		'own ids as strings of digits' => ['"building_parent_ids":{"10":"' . B . '","20":"' . C . '"}', [A => B, B => B, C => C, RC => null]],
		'one building only' => ['"building_parent_ids":{"10":' . A . '}', [A => A, B => A, C => null, RC => null]],
		'absent: first own application per building' => ['', [A => A, B => A, C => C, RC => null]],
		'empty map: first own application per building' => ['"building_parent_ids":{}', [A => A, B => A, C => C, RC => null]],
		'null: first own application per building' => ['"building_parent_ids":null', [A => A, B => A, C => C, RC => null]],
		'legacy parent_id, own, int' => ['"parent_id":' . B, [A => B, B => B, C => null, RC => null]],
		'legacy parent_id, own, string of digits' => ['"parent_id":"' . B . '"', [A => B, B => B, C => null, RC => null]],
		'legacy parent_id true is ignored' => ['"parent_id":true', [A => A, B => A, C => C, RC => null]],
		'legacy parent_id 1.0 is ignored' => ['"parent_id":1.0', [A => A, B => A, C => C, RC => null]],
		'legacy parent_id of the own id as a float is ignored' => ['"parent_id":' . B . '.0', [A => A, B => A, C => C, RC => null]],
		'legacy parent_id " id" is ignored' => ['"parent_id":" ' . B . '"', [A => A, B => A, C => C, RC => null]],
		'legacy parent_id NOT_IN_CHECKOUT is ignored' => ['"parent_id":' . NOT_IN_CHECKOUT, [A => A, B => A, C => C, RC => null]],
		'legacy parent_id of the recurring application is ignored' => ['"parent_id":' . RC, [A => A, B => A, C => C, RC => null]],
	];
	// run => [path, the repository's ids as strings]
	$runs = ['case' => ['case', false], 'direct' => ['direct', false], 'collision' => ['collision', false],
		'case, ids as strings' => ['case', true], 'direct, ids as strings' => ['direct', true]];
	foreach ($accepted as $label => [$raw, $expected])
	{
		foreach ($runs as $run => [$path, $stringIds])
		{
			$status = $byPath[$path];
			arm('G', "$label ($run)", static function () use ($label, $raw, $expected, $run, $path, $stringIds, $status): void {
				[$thrown, $db, $repository, $mailer] = checkout($raw, $path, $stringIds);
				$parents = $repository->parents();
				$statuses = $repository->statuses();
				// a recurring application is case-handled unless it collides; a rejected one is not mailed
				$expectedStatuses = [A => $status, B => $status, C => $status, RC => $path === 'collision' ? 'REJECTED' : 'NEW'];
				$mailed = $path !== 'collision';
				// === on the whole map: an id written as a string, a float or true is not the int that was checked
				$ok = $thrown === null && $parents === $expected && $statuses === $expectedStatuses
					&& $db->committed === 1 && $db->rolledBack === 0 && ($mailer->sent !== []) === $mailed;
				check('G', "$label ($run): parents " . show($expected) . ', committed' . ($mailed ? ', mailed' : ''), $ok,
					'thrown ' . shortName($thrown) . ', parents ' . show($parents) . ', statuses ' . show($statuses)
					. ", commit {$db->committed}, rollback {$db->rolledBack}, mail " . count($mailer->sent));
			});
		}
	}
	arm('G', 'the direct path makes the events', static function (): void {
		checkout('"building_parent_ids":{"10":' . B . ',"20":' . C . '}', 'direct');
		check('G', 'the direct path makes the events of A, B and C', EventService::$made === [A, B, C], show(EventService::$made));
	});

	// ---------------------------------------------------------------- R
	// The value for building 10, as it is written in the request body (raw JSON, so 700002.0 stays a float)
	$refusedValues = [
		'NOT_IN_CHECKOUT' => (string)NOT_IN_CHECKOUT,
		'NOT_IN_CHECKOUT as a string' => '"' . NOT_IN_CHECKOUT . '"',
		'MISSING' => (string)MISSING,
		'1' => '1',
		'"1"' => '"1"',
		'0' => '0',
		'"0"' => '"0"',
		'-1' => '-1',
		'INT_COLUMN_MAX + 1' => (string)(INT_COLUMN_MAX + 1),
		'"2147483648"' => '"2147483648"',
		'"99999999999"' => '"99999999999"',
		'true' => 'true',
		'false' => 'false',
		'null' => 'null',
		'1.0' => '1.0',
		'own id as a float' => B . '.0',
		'own id as "id.0"' => '"' . B . '.0"',
		'own id as " id"' => '" ' . B . '"',
		'own id as "id "' => '"' . B . ' "',
		'own id as "0id"' => '"0' . B . '"',
		'own id as "+id"' => '"+' . B . '"',
		'own id as "-id"' => '"-' . B . '"',
		'own id as an exponent' => '"7.00002e5"',
		'own id with a newline' => '"' . B . '\n"',
		'"abc"' => '"abc"',
		'""' => '""',
		'[own id]' => '[' . B . ']',
		'{"id": own id}' => '{"id":' . B . '}',
	];
	$refused = [];
	foreach ($refusedValues as $label => $value)
	{
		$refused["building 10: $label"] = '"building_parent_ids":{"10":' . $value . ',"20":' . C . '}';
	}
	$refused['a parent in another building'] = '"building_parent_ids":{"10":' . C . ',"20":' . C . '}';
	$refused['building_parent_ids true'] = '"building_parent_ids":true';
	$refused['building_parent_ids "x"'] = '"building_parent_ids":"x"';
	$refused['building_parent_ids "0"'] = '"building_parent_ids":"0"';
	$refused['building_parent_ids own id'] = '"building_parent_ids":' . B;
	$refused['building_parent_ids a string of ones'] = '"building_parent_ids":"1111111111111111111111"';
	foreach ($refused as $label => $raw)
	{
		foreach (['case' => ['case', false], 'direct' => ['direct', false], 'case, ids as strings' => ['case', true]] as $run => [$path, $stringIds])
		{
			arm('R', "$label ($run)", static function () use ($label, $raw, $run, $path, $stringIds): void {
				[$thrown, $db, $repository, $mailer] = checkout($raw, $path, $stringIds);
				$ok = $thrown !== null && (new ReflectionClass($thrown))->getShortName() === REFUSAL
					&& $repository->parents() === [] && $repository->statuses() === []
					&& $db->committed === 0 && $db->rolledBack === $db->begun && !$db->inTransaction()
					&& $mailer->sent === [] && EventService::$made === [];
				check('R', "$label ($run): refused, nothing written, rolled back, no mail", $ok,
					'thrown ' . shortName($thrown) . ', parents ' . show($repository->parents()) . ', statuses '
					. show($repository->statuses()) . ", begin {$db->begun}, commit {$db->committed}, rollback {$db->rolledBack}, mail "
					. count($mailer->sent) . ', events ' . count(EventService::$made));
			});
		}
	}

	// ---------------------------------------------------------------- A
	$idBodies = [];
	foreach ($refused as $label => $raw)
	{
		arm('A', $label, static function () use ($label, $raw, &$idBodies): void {
			// the raw value first, then the rest of the body
			[$status, $answer] = request('{' . $raw . ',' . substr(json_encode(body()), 1));
			$decoded = json_decode($answer, true);
			check('A', "$label: 400 with an error", $status === 400 && is_string($decoded['error'] ?? null), "status $status, body $answer");
			if ($label !== 'a parent in another building')
			{
				$idBodies[$answer] = true;
			}
		});
	}
	check('A', 'every refusal of the value gets one byte-identical body', count($idBodies) === 1, show(array_keys($idBodies)));
	check('A', 'that body does not repeat a value sent', $idBodies !== [] && !preg_match('/[0-9]/', (string)array_key_first($idBodies)),
		show(array_key_first($idBodies)));

	// ---------------------------------------------------------------- X
	arm('X', 'missing price choice', static function (): void {
		[$status, $raw] = request(json_encode(body()), new PriceChoiceException(PriceChoiceException::REQUIRED, 872));
		check('X', 'a missing price choice is still 422', $status === 422, "status $status, body $raw");
	});
	arm('X', 'no applications', static function (): void {
		[$status, $raw] = request(json_encode(body()), null, true);
		check('X', 'a checkout without applications is still 500', $status === 500 && str_contains($raw, 'No partial applications'), "status $status, body $raw");
	});
	arm('X', 'validation errors', static function (): void {
		[$status, $raw] = request(json_encode(['contactName' => '', 'zipCode' => '12'] + body()));
		$decoded = json_decode($raw, true);
		check('X', 'several validation errors are still 400 with the list', $status === 400 && count($decoded['errors'] ?? []) >= 2, "status $status, body $raw");
	});

	printf("%d passed, %d failed\n", $results['pass'], $results['fail']);
	exit($results['fail'] === 0 ? 0 : 1);
}
