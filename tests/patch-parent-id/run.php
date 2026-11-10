<?php

/**
 * Hermetic test: PATCH /bookingfrontend/applications/partials/{id} accepts a parent_id only when it names an
 * application the caller may modify, or is null.
 *
 *   php tests/patch-parent-id/run.php
 *   (in the pe-api container: docker exec -w /var/www/html pe-api php tests/patch-parent-id/run.php)
 *
 * What is real and what is replaced:
 *   - ApplicationController::patchApplication() is the REAL method of the tree under test, called on an instance
 *     made without its constructor (the constructor opens the database).
 *   - Its applicationService is a recorder: getApplicationById() answers from a fixed table of three applications
 *     and patchApplication() keeps the array it is handed. Any other call on it is a failure.
 *   - Its applicationHelper is a stand-in whose canModifyApplication() allows two of the three applications.
 *     Its canViewApplication() allows all three, so a check that asks it instead of canModifyApplication() lets
 *     the third through and the R rows go red.
 *   - The table rejects an id beyond the integer column as the database does, by throwing.
 *   - Sessions is a stand-in that holds one session id, and REDIS_HOST is disabled.invalid, so the notification
 *     the method sends fails inside RedisService, which logs and swallows it. Nothing is published.
 *   - Every App\ class is loaded from PP_ROOT and from nowhere else.
 *
 * Groups:
 *   S  setup: the controller came from PP_ROOT.
 *   C  the stand-ins: canModifyApplication() refuses OTHER and allows OWN_OTHER, canViewApplication() allows OTHER,
 *      and the table answers null for an unknown id. Without these a green R row could be green for nothing.
 *   G  accepted: no parent_id, null, the application's own id, another application the caller may modify (as an
 *      int and as a string of digits), and the other fields of the body arrive as sent beside it.
 *   R  refused with 403 and nothing patched: an application the caller may not modify (int, string, with dates),
 *      an unknown id, 0, negative, beyond the column, and values that are not an id at all.
 *   A  every R row gets the same status and a byte-identical body that names no id, so the answer does not tell
 *      an application that exists from one that does not, or either from a value that is not an id.
 *   X  unchanged: the caller's own 403 and 404, an empty or invalid body (400), recurring_info still encoded.
 *
 * Red first: against a tree without the check the R rows fail:
 *   git archive <a commit before the change> src | tar -x -C /tmp/pp-old
 *   PP_ROOT=/tmp/pp-old php tests/patch-parent-id/run.php
 * mutate.py applies single defects to a copy and expects this harness to go red for each.
 *
 * Env (optional): PP_ROOT = tree holding src/ (default: this repo). PP_AUTOLOAD = composer's vendor/autoload.php,
 * for the vendor packages only (default: <PP_ROOT>/vendor/autoload.php, then <this repo>/vendor/autoload.php).
 *
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, not a crash).
 */

namespace App\modules\phpgwapi\security
{
	/** Stand-in: the notification patchApplication() sends asks it for the session id and nothing else. */
	final class Sessions
	{
		private static ?Sessions $instance = null;

		public static function getInstance(): self
		{
			return self::$instance ??= new self();
		}

		public function get_session_id(): string
		{
			return 'pp-harness-session';
		}
	}
}

namespace
{
	use App\modules\bookingfrontend\controllers\applications\ApplicationController;
	use App\modules\bookingfrontend\helpers\ApplicationHelper;
	use Psr\Http\Message\ServerRequestInterface;
	use Slim\Psr7\Factory\ResponseFactory;
	use Slim\Psr7\Factory\ServerRequestFactory;
	use Slim\Psr7\Factory\StreamFactory;

	const OWN = 500001;        // the application being patched; the caller may modify it
	const OWN_OTHER = 500002;  // another application the caller may modify
	const OTHER = 500003;      // an application the caller may see but not modify
	const UNKNOWN = 599999;    // no such application
	const INT_COLUMN_MAX = 2147483647;

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
		return str_replace("\n", '', var_export($value, true)) . ' (' . get_debug_type($value) . ')';
	}

	if (!is_dir("$root/src") || !is_file($autoload))
	{
		check('S', 'setup', false, "no $root/src or no autoload at $autoload");
		exit(1);
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

	final class ServiceRecorder
	{
		public array $patched = [];
		public array $lookedUp = [];

		public function getApplicationById(int $id): ?array
		{
			$this->lookedUp[] = $id;
			if ($id > INT_COLUMN_MAX)
			{
				// as PostgreSQL answers a bigger value for an integer column
				throw new Exception('SQLSTATE[22003]: Numeric value out of range');
			}
			$table = [
				OWN => ['id' => OWN, 'status' => 'NEWPARTIAL1'],
				OWN_OTHER => ['id' => OWN_OTHER, 'status' => 'NEWPARTIAL1'],
				OTHER => ['id' => OTHER, 'status' => 'NEWPARTIAL1'],
			];
			return $table[$id] ?? null;
		}

		public function patchApplication(array $data): void
		{
			$this->patched[] = $data;
		}

		public function __call(string $name, array $arguments)
		{
			throw new LogicException("patchApplication() called applicationService->$name(), which the recorder does not know");
		}
	}

	final class HelperStandIn extends ApplicationHelper
	{
		public array $modifyAsked = [];

		public function __construct()
		{
		}

		public function canModifyApplication(array $application, ServerRequestInterface $request): bool
		{
			$this->modifyAsked[] = $application['id'];
			return in_array($application['id'], [OWN, OWN_OTHER], true);
		}

		public function canViewApplication(array $application, ServerRequestInterface $request): bool
		{
			return true;
		}
	}

	/** One request through the real patchApplication(): [status, decoded body, recorder, helper, raw body]. */
	function patch(string $rawBody, int $pathId = OWN): array
	{
		$recorder = new ServiceRecorder();
		$helper = new HelperStandIn();
		$class = new ReflectionClass(ApplicationController::class);
		$controller = $class->newInstanceWithoutConstructor();
		$class->getProperty('applicationService')->setValue($controller, $recorder);
		$class->getProperty('applicationHelper')->setValue($controller, $helper);

		$request = (new ServerRequestFactory())->createServerRequest('PATCH', "/bookingfrontend/applications/partials/$pathId")
			->withHeader('Content-Type', 'application/json')
			->withBody((new StreamFactory())->createStream($rawBody));
		$response = $controller->patchApplication($request, (new ResponseFactory())->createResponse(), ['id' => (string)$pathId]);
		$response->getBody()->rewind();
		$raw = (string)$response->getBody();

		return [$response->getStatusCode(), json_decode($raw, true), $recorder, $helper, $raw];
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
	$file = (new ReflectionClass(ApplicationController::class))->getFileName();
	check('S', 'ApplicationController is loaded from PP_ROOT', str_starts_with((string)$file, "$root/src/"), (string)$file);

	// ---------------------------------------------------------------- C
	arm('C', 'stand-ins', static function (): void {
		$helper = new HelperStandIn();
		$request = (new ServerRequestFactory())->createServerRequest('PATCH', '/');
		check('C', 'canModifyApplication() refuses OTHER', $helper->canModifyApplication(['id' => OTHER], $request) === false);
		check('C', 'canModifyApplication() allows OWN_OTHER', $helper->canModifyApplication(['id' => OWN_OTHER], $request) === true);
		check('C', 'canViewApplication() allows OTHER', $helper->canViewApplication(['id' => OTHER], $request) === true);
		$recorder = new ServiceRecorder();
		check('C', 'the table has no UNKNOWN', $recorder->getApplicationById(UNKNOWN) === null);
		check('C', 'the table has OTHER', $recorder->getApplicationById(OTHER) !== null);
	});

	// ---------------------------------------------------------------- G
	$accepted = [
		'no parent_id' => [['name' => 'x'], ['name' => 'x', 'id' => OWN]],
		'parent_id null' => [['parent_id' => null], ['parent_id' => null, 'id' => OWN]],
		'parent_id the own id' => [['parent_id' => OWN], ['parent_id' => OWN, 'id' => OWN]],
		'parent_id OWN_OTHER (int)' => [['parent_id' => OWN_OTHER], ['parent_id' => OWN_OTHER, 'id' => OWN]],
		'parent_id OWN_OTHER (string of digits), passed on as int' => [['parent_id' => (string)OWN_OTHER], ['parent_id' => OWN_OTHER, 'id' => OWN]],
	];
	$beside = [
		'status' => 'NEW', 'session_id' => 'session-in-body', 'customer_ssn' => '01010112345',
		'customer_organization_id' => 7, 'name' => 'n',
		'dates' => [['from_' => '2027-08-01T06:00:00.000Z', 'to_' => '2027-08-01T07:00:00.000Z']],
		'resources' => [952], 'parent_id' => OWN_OTHER,
	];
	$accepted['the other fields arrive as sent beside parent_id OWN_OTHER'] = [$beside, $beside + ['id' => OWN]];

	foreach ($accepted as $label => [$body, $expected])
	{
		arm('G', $label, static function () use ($label, $body, $expected): void {
			[$status, , $recorder] = patch(json_encode($body));
			$ok = $status === 200 && count($recorder->patched) === 1 && $recorder->patched[0] === $expected;
			check('G', "$label: 200, patched once with the body as expected", $ok,
				"status $status, patched " . show($recorder->patched));
		});
	}
	arm('G', 'OWN_OTHER asks canModifyApplication() about OWN_OTHER', static function (): void {
		[, , , $helper] = patch(json_encode(['parent_id' => OWN_OTHER]));
		check('G', 'OWN_OTHER asks canModifyApplication() about OWN_OTHER', in_array(OWN_OTHER, $helper->modifyAsked, true),
			'asked about ' . show($helper->modifyAsked));
	});

	// ---------------------------------------------------------------- R
	// the value as it is written in the request body (raw JSON, so 500002.0 stays a float)
	$refused = [
		OTHER,
		'"' . OTHER . '"',
		UNKNOWN,
		'0',
		'-1',
		'-' . OWN_OTHER,
		'"0"',
		(string)(INT_COLUMN_MAX + 1),
		'"' . (INT_COLUMN_MAX + 1) . '"',
		'"abc"',
		'""',
		'true',
		'false',
		'1.5',
		OWN_OTHER . '.0',
		'[' . OWN_OTHER . ']',
		'{"id":' . OWN_OTHER . '}',
		'" ' . OWN_OTHER . '"',
		'"' . OWN_OTHER . ' "',
		'"0' . OWN_OTHER . '"',
		'"' . OWN_OTHER . ';"',
		'"' . OWN_OTHER . '\n"',
	];
	$answers = [];
	foreach ($refused as $raw)
	{
		$raw = (string)$raw;
		arm('R', $raw, static function () use ($raw, &$answers): void {
			$rawBody = '{"parent_id":' . $raw . ',"name":"x"}';
			check('C', "the body $rawBody is valid JSON", is_array(json_decode($rawBody, true)));
			[$status, , $recorder, , $answer] = patch($rawBody);
			$answers[$raw] = "$status $answer";
			check('R', "parent_id $raw: 403 and nothing patched", $status === 403 && $recorder->patched === [],
				"status $status, patched " . show($recorder->patched));
		});
	}

	// ---------------------------------------------------------------- A: one answer for every refusal
	$distinct = array_values(array_unique($answers));
	check('A', 'every refused parent_id gets the same status and byte-identical body', count($answers) === count($refused) && count($distinct) === 1,
		count($answers) . ' answers, distinct: ' . show($distinct));
	foreach (['an application the caller may not modify' => (string)OTHER, 'no such application' => (string)UNKNOWN, 'not an id' => '"abc"'] as $label => $raw)
	{
		check('A', "$label answers as the others", isset($answers[$raw]) && $answers[$raw] === ($distinct[0] ?? null), show($answers[$raw] ?? null));
	}
	$answer = $distinct[0] ?? '';
	check('A', 'the refusal names no id', preg_match('/[0-9]{3,}/', substr($answer, 4)) === 0, $answer);
	check('A', 'the refusal is not the answer for the patched application itself',
		$answer !== '403 {"error":"Unauthorized to modify this application"}', $answer);
	arm('R', 'OTHER with dates and resources', static function (): void {
		$body = ['parent_id' => OTHER, 'dates' => [['from_' => '2027-08-01T06:00:00.000Z', 'to_' => '2027-08-01T07:00:00.000Z']], 'resources' => [952]];
		[$status, , $recorder] = patch(json_encode($body));
		check('R', 'parent_id OTHER with dates and resources: 403 and nothing patched', $status === 403 && $recorder->patched === [],
			"status $status, patched " . show($recorder->patched));
	});
	arm('R', 'OTHER asks canModifyApplication() about OTHER', static function (): void {
		[, , , $helper] = patch(json_encode(['parent_id' => OTHER]));
		check('R', 'parent_id OTHER asks canModifyApplication() about OTHER', in_array(OTHER, $helper->modifyAsked, true),
			'asked about ' . show($helper->modifyAsked));
	});

	// ---------------------------------------------------------------- X
	arm('X', 'the caller may not modify the patched application', static function (): void {
		[$status, $body, $recorder] = patch(json_encode(['parent_id' => OWN_OTHER]), OTHER);
		check('X', 'PATCH on OTHER: 403 "Unauthorized to modify this application", nothing patched',
			$status === 403 && ($body['error'] ?? null) === 'Unauthorized to modify this application' && $recorder->patched === [],
			"status $status, body " . show($body));
	});
	arm('X', 'the patched application does not exist', static function (): void {
		[$status, , $recorder] = patch(json_encode(['name' => 'x']), UNKNOWN);
		check('X', 'PATCH on UNKNOWN: 404, nothing patched', $status === 404 && $recorder->patched === [], "status $status");
	});
	foreach (['empty body' => '', 'invalid JSON' => '{', 'empty object' => '{}'] as $label => $raw)
	{
		arm('X', $label, static function () use ($label, $raw): void {
			[$status, , $recorder] = patch($raw);
			check('X', "$label: 400, nothing patched", $status === 400 && $recorder->patched === [], "status $status");
		});
	}
	arm('X', 'recurring_info', static function (): void {
		$info = ['repeat_until' => '2027-12-01', 'field_interval' => 1];
		[$status, , $recorder] = patch(json_encode(['recurring_info' => $info, 'parent_id' => OWN_OTHER]));
		$ok = $status === 200 && count($recorder->patched) === 1
			&& $recorder->patched[0] === ['recurring_info' => json_encode($info), 'parent_id' => OWN_OTHER, 'id' => OWN];
		check('X', 'recurring_info is still stored as JSON beside an accepted parent_id', $ok, "status $status, patched " . show($recorder->patched));
	});

	printf("\n%d passed, %d failed\n", $results['pass'], $results['fail']);
	exit($results['fail'] === 0 ? 0 : 1);
}
