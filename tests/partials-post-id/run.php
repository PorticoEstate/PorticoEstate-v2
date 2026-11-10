<?php

/**
 * Hermetic test: POST /bookingfrontend/applications/partials creates a partial, an `id` in the body never makes it
 * update an existing application, and a `purchase_order` in the body never becomes the new application's order.
 *
 *   php tests/partials-post-id/run.php
 *   (in the pe-api container: docker exec -w /var/www/html pe-api php tests/partials-post-id/run.php)
 *
 * What is real and what is replaced:
 *   - ApplicationController::createPartial() is the REAL method of the tree under test, called on an instance made
 *     without its constructor (the constructor opens the database).
 *   - Its applicationService is a recorder: it keeps the array createPartial() hands to savePartialApplication()
 *     and returns a new id. Any other call on it is a failure.
 *   - Sessions is a stand-in that holds one session id (the real class reads settings from the database as it loads).
 *   - REDIS_HOST is set to disabled.invalid, so the notification createPartial() sends fails inside RedisService,
 *     which logs and swallows it as it does when Redis is down. Nothing is published.
 *   - Whether the repository would UPDATE an application is decided by the REAL condition: the `if` that sends
 *     ApplicationRepository::savePartialApplication() to updateApplication() is CUT VERBATIM from the tree under
 *     test and evaluated on the recorded array. A missing or ambiguous marker is a FAIL line, never a guess.
 *   - Whether the repository would write the body's order is decided the same way: the `if` around its only
 *     savePurchaseOrder() call is cut verbatim and evaluated on the recorded array.
 *   - Every App\ class is loaded from PP_ROOT and from nowhere else: a class missing there is a failure, so the
 *     result is about that tree only.
 *
 * Groups:
 *   C  the detectors: the cut conditions say UPDATE for an array with an id and INSERT for one without, and
 *      write-the-order for an array with purchase_order lines and not for one without. Without these, a green row
 *      below could be green because a condition can never say anything else.
 *   S  the tree under test: the update branch is updateApplication() with $id = $data['id']; savePurchaseOrder()
 *      is called once, under the cut `if`, and nothing else in savePartialApplication() reads purchase_order; the
 *      order the articles path makes (ArticleRepository::getOrCreatePurchaseOrder()) is inserted with
 *      application_id alone; the controller came from PP_ROOT.
 *   P  posted ids: for each spelling of an id (int, numeric string, 0, null, negative, array, float, bool, text)
 *      the response is 201 with the recorder's new id, savePartialApplication() is called once, and the repository
 *      would INSERT.
 *   O  posted orders: for each shape of a body purchase_order (customer_id and status with lines, lines alone,
 *      with articles beside it, with an id and application_id) the response is 201 and the repository would not
 *      write it.
 *   B  a body with ids and server fields (an id, a session_id, status and owner_id, and a purchase_order with ids,
 *      a customer_id and a status): every other key arrives as sent, the server's own fields win, the repository
 *      would not write the body's order, and nothing else is added except the fields the controller always adds.
 *   N  a create without an id is unchanged: 201, INSERT, the body passed on as sent.
 *   X  an empty or invalid body is still refused with 400, and nothing is saved.
 *
 * Red first: against a tree without the fix the P rows with a truthy id, the O rows and B fail:
 *   git archive <a commit before the fix> src/modules/bookingfrontend src/helpers src/WebSocket | tar -x -C /tmp/pp-old
 *   PP_ROOT=/tmp/pp-old php tests/partials-post-id/run.php
 * mutate.py applies single defects to a copy and expects this harness to go red for each.
 *
 * Env (optional): PP_ROOT = tree holding src/ (default: this repo). PP_AUTOLOAD = composer's vendor/autoload.php,
 * for the vendor packages only (default: <PP_ROOT>/vendor/autoload.php, then <this repo>/vendor/autoload.php).
 *
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, not a crash).
 */

namespace App\modules\phpgwapi\security
{
	/** Stand-in: createPartial() asks it for the session id and nothing else. */
	final class Sessions
	{
		public const SESSION_ID = 'pp-harness-session';
		private static ?Sessions $instance = null;

		public static function getInstance(): self
		{
			return self::$instance ??= new self();
		}

		public function get_session_id(): string
		{
			return self::SESSION_ID;
		}
	}
}

namespace
{
	use App\modules\bookingfrontend\controllers\applications\ApplicationController;
	use App\modules\phpgwapi\security\Sessions;
	use Slim\Psr7\Factory\ResponseFactory;
	use Slim\Psr7\Factory\ServerRequestFactory;
	use Slim\Psr7\Factory\StreamFactory;

	const PP_ACCOUNT_ID = 4242;

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
	$loadedFromRoot = [];
	spl_autoload_register(static function (string $class) use ($root, &$loadedFromRoot): void {
		if (strncmp($class, 'App\\', 4) !== 0)
		{
			return;
		}
		$file = "$root/src/" . str_replace('\\', '/', substr($class, 4)) . '.php';
		if (!is_file($file))
		{
			throw new RuntimeException("$class is not in PP_ROOT ($file)");
		}
		$loadedFromRoot[] = substr($file, strlen($root) + 1);
		require $file;
	}, true, true);

	final class PartialRecorder
	{
		public array $saved = [];

		public function savePartialApplication(array $data): int
		{
			$this->saved[] = $data;
			return 700000 + count($this->saved);
		}

		public function __call(string $name, array $arguments)
		{
			throw new LogicException("createPartial() called applicationService->$name(), which the recorder does not know");
		}
	}

	/** One request through the real createPartial(): [status, decoded body, recorder]. */
	function post(string $rawBody): array
	{
		$recorder = new PartialRecorder();
		$class = new ReflectionClass(ApplicationController::class);
		$controller = $class->newInstanceWithoutConstructor();
		$class->getProperty('applicationService')->setValue($controller, $recorder);
		$class->getProperty('userSettings')->setValue($controller, ['account_id' => PP_ACCOUNT_ID]);

		$request = (new ServerRequestFactory())->createServerRequest('POST', '/bookingfrontend/applications/partials')
			->withHeader('Content-Type', 'application/json')
			->withBody((new StreamFactory())->createStream($rawBody));
		$response = $controller->createPartial($request, (new ResponseFactory())->createResponse());
		$response->getBody()->rewind();

		return [$response->getStatusCode(), json_decode((string)$response->getBody(), true), $recorder];
	}

	// ---------------------------------------------------------------- the cut condition
	$repositoryFile = "$root/src/modules/bookingfrontend/repositories/ApplicationRepository.php";
	$repository = is_file($repositoryFile) ? (string)file_get_contents($repositoryFile) : '';
	$decidesUpdate = null;
	$saveBody = '';
	if (preg_match('/public function savePartialApplication\(array \$data\): int\s*\{(.*?)\n    \}\n/s', $repository, $m) === 1)
	{
		$saveBody = $m[1];
	}
	check('S', 'savePartialApplication() found in ApplicationRepository.php', $saveBody !== '');

	$updateIf = '/if\s*\((?<cond>[^\n]+)\)\s*\{\s*\$this->updateApplication\(\$data\);\s*\$id = \$data\[\'id\'\];/';
	$found = preg_match_all($updateIf, $saveBody, $ifs);
	check('S', 'exactly one `if` leads to updateApplication() with $id = $data[\'id\']', $found === 1, "matches: $found");
	if ($found === 1)
	{
		$condition = $ifs['cond'][0];
		$decidesUpdate = eval('return static function (array $data): bool { return (bool)(' . $condition . '); };');
		printf("     cut condition: %s\n", $condition);
	}
	$repositoryUpdates = static function (array $data) use (&$decidesUpdate): bool {
		if ($decidesUpdate === null)
		{
			throw new RuntimeException('no cut condition: the S rows above say why');
		}
		return $decidesUpdate($data);
	};

	// the body's order: the `if` around the one savePurchaseOrder() call, and no other read of purchase_order
	$writesOrder = null;
	$orderIf = '/if\s*\((?<cond>[^\n]+)\)\s*\{(?<block>[^{}]*?\$this->savePurchaseOrder\([^{}]*?)\}/';
	$found = preg_match_all($orderIf, $saveBody, $orderIfs);
	check('S', 'exactly one `if` leads to savePurchaseOrder()', $found === 1, "matches: $found");
	check('S', 'savePurchaseOrder() is called once in savePartialApplication()',
		substr_count($saveBody, '$this->savePurchaseOrder(') === 1, 'calls: ' . substr_count($saveBody, '$this->savePurchaseOrder('));
	if ($found === 1)
	{
		$orderCondition = $orderIfs['cond'][0];
		$inIf = substr_count($orderCondition . $orderIfs['block'][0], 'purchase_order');
		check('S', 'savePartialApplication() reads purchase_order only in that `if`',
			substr_count($saveBody, 'purchase_order') === $inIf, 'in the body ' . substr_count($saveBody, 'purchase_order') . ", in the `if` $inIf");
		$writesOrder = eval('return static function (array $data): bool { return (bool)(' . $orderCondition . '); };');
		printf("     cut condition: %s\n", $orderCondition);
	}
	$repositoryWritesOrder = static function (array $data) use (&$writesOrder): bool {
		if ($writesOrder === null)
		{
			throw new RuntimeException('no cut order condition: the S rows above say why');
		}
		return $writesOrder($data);
	};

	// the order a new application gets from `articles`: the status and customer are the table's, not the caller's
	$articleFile = "$root/src/modules/bookingfrontend/repositories/ArticleRepository.php";
	$articles = is_file($articleFile) ? (string)file_get_contents($articleFile) : '';
	$getOrCreate = preg_match('/private function getOrCreatePurchaseOrder\(int \$applicationId\): int\s*\{(.*?)\n    \}\n/s', $articles, $goc) === 1 ? $goc[1] : '';
	$inserts = preg_match_all('/INSERT INTO bb_purchase_order\b[^"]*/', $getOrCreate, $ins) ? $ins[0] : [];
	check('S', 'ArticleRepository::getOrCreatePurchaseOrder() inserts the order with application_id alone',
		$inserts === ['INSERT INTO bb_purchase_order (application_id) VALUES (:application_id)'],
		$getOrCreate === '' ? 'getOrCreatePurchaseOrder() not found' : 'inserts: ' . show($inserts));

	// ---------------------------------------------------------------- C: the detectors themselves
	try
	{
		check('C', 'cut condition: an array with id 84887 would UPDATE', $repositoryUpdates(['id' => 84887]) === true);
		check('C', 'cut condition: an array without id would INSERT', $repositoryUpdates(['name' => 'x']) === false);
	}
	catch (Throwable $e)
	{
		check('C', 'cut condition evaluates', false, get_class($e) . ': ' . $e->getMessage());
	}
	try
	{
		check('C', 'cut order condition: an array with purchase_order lines would write the order',
			$repositoryWritesOrder(['purchase_order' => ['customer_id' => 1, 'lines' => [['article_mapping_id' => 877, 'quantity' => 1]]]]) === true);
		check('C', 'cut order condition: an array without purchase_order would not',
			$repositoryWritesOrder(['articles' => [['id' => 877, 'quantity' => 1]]]) === false);
	}
	catch (Throwable $e)
	{
		check('C', 'cut order condition evaluates', false, get_class($e) . ': ' . $e->getMessage());
	}

	// ---------------------------------------------------------------- P, B, N, X
	$body = [
		'name' => 'B',
		'building_id' => 10,
		'activity_id' => 1,
		'resources' => [952],
		'dates' => [['from_' => '2027-03-01T10:00:00.000Z', 'to_' => '2027-03-01T11:00:00.000Z']],
		'articles' => [['id' => 877, 'quantity' => 60, 'parent_id' => null, 'price_id' => 56]],
		'agegroups' => [['agegroup_id' => 2, 'male' => 3, 'female' => 0]],
		'audience' => [1],
		'organizer' => 'B org',
	];

	$ids = [84887, '84887', 1, '1', 0, '0', null, -5, 'abc', '84887abc', ' 84887', [84887], 84887.0, true];
	foreach ($ids as $id)
	{
		$label = 'body id ' . show($id);
		try
		{
			[$status, $json, $recorder] = post(json_encode($body + ['id' => $id]));
			$saved = $recorder->saved;
			$ok = $status === 201 && count($saved) === 1 && ($json['id'] ?? null) === 700001 && $repositoryUpdates($saved[0]) === false;
			check('P', "$label -> 201, a new id, the repository would INSERT", $ok,
				"status $status, saves " . count($saved) . ', response id ' . show($json['id'] ?? null)
				. (count($saved) === 1 ? ', reaches the repository as ' . show($saved[0]['id'] ?? '(absent)') . ', update=' . show($repositoryUpdates($saved[0])) : ''));
		}
		catch (Throwable $e)
		{
			check('P', $label, false, get_class($e) . ': ' . $e->getMessage());
		}
	}

	$withoutArticles = array_diff_key($body, ['articles' => true]);
	$lines = [['article_mapping_id' => 877, 'quantity' => 60]];
	$orders = [
		'customer_id and status with lines' => $withoutArticles + ['purchase_order' => ['customer_id' => 1, 'status' => 3, 'lines' => $lines]],
		'lines alone' => $withoutArticles + ['purchase_order' => ['lines' => $lines]],
		'customer_id and status with lines, articles beside it' => $body + ['purchase_order' => ['customer_id' => 1, 'status' => 3, 'lines' => $lines]],
		'an id, application_id, customer_id and status with lines' => $withoutArticles
			+ ['purchase_order' => ['id' => 18734, 'application_id' => 84887, 'customer_id' => 1, 'status' => 3, 'lines' => $lines]],
	];
	foreach ($orders as $shape => $sent)
	{
		$label = "body purchase_order: $shape";
		try
		{
			[$status, $json, $recorder] = post(json_encode($sent));
			$saved = $recorder->saved;
			$ok = $status === 201 && count($saved) === 1 && ($json['id'] ?? null) === 700001 && $repositoryWritesOrder($saved[0]) === false;
			check('O', "$label -> 201, the repository would not write it", $ok,
				"status $status, saves " . count($saved)
				. (count($saved) === 1 ? ', reaches the repository as ' . show($saved[0]['purchase_order'] ?? '(absent)') . ', writes=' . show($repositoryWritesOrder($saved[0])) : ''));
		}
		catch (Throwable $e)
		{
			check('O', $label, false, get_class($e) . ': ' . $e->getMessage());
		}
	}

	// server-owned fields the controller sets, and the fields it fills in when absent (taken from the real
	// populateDummyData(), so this list is not retyped)
	$serverFields = ['owner_id', 'session_id', 'status', 'active', 'created'];
	$dummy = [];
	try
	{
		$fill = new ReflectionMethod(ApplicationController::class, 'populateDummyData');
		$controller = (new ReflectionClass(ApplicationController::class))->newInstanceWithoutConstructor();
		$args = [&$dummy];
		$fill->invokeArgs($controller, $args);
	}
	catch (Throwable $e)
	{
		check('S', 'populateDummyData() runs', false, get_class($e) . ': ' . $e->getMessage());
	}
	$added = array_merge($serverFields, array_keys($dummy));

	$foreign = $body + [
		'id' => 84887,
		'session_id' => 'session-of-another-applicant',
		'status' => 'ACCEPTED',
		'owner_id' => 1,
		'recurring_info' => ['repeat_until' => '2027-06-01', 'field_interval' => 1, 'outseason' => false],
		'purchase_order' => ['id' => 18734, 'application_id' => 84887, 'customer_id' => 1, 'status' => 3, 'lines' => $lines],
	];
	foreach (['B' => $foreign, 'N' => $body] as $group => $sent)
	{
		try
		{
			[$status, , $recorder] = post(json_encode($sent));
			$saved = $recorder->saved[0] ?? null;
			check($group, ($group === 'B' ? 'a body with ids and server fields' : 'a create without id') . ' -> 201 and INSERT',
				$status === 201 && $saved !== null && $repositoryUpdates($saved) === false, "status $status");
			if ($saved === null)
			{
				continue;
			}

			$changed = [];
			foreach ($sent as $key => $value)
			{
				if (in_array($key, ['id', 'session_id', 'status', 'owner_id', 'purchase_order'], true))
				{
					continue;
				}
				$expected = $key === 'recurring_info' ? json_encode($value) : $value;
				if (!array_key_exists($key, $saved) || $saved[$key] !== $expected)
				{
					$changed[] = $key;
				}
			}
			check($group, 'every other body key reaches the repository as sent', $changed === [], implode(', ', $changed));

			$extra = array_values(array_diff(array_keys($saved), array_keys($sent), $added));
			check($group, 'nothing reaches the repository that was not sent, except the fields the controller adds', $extra === [], implode(', ', $extra));

			check($group, "the server's session_id, status and owner_id win",
				($saved['session_id'] ?? null) === Sessions::SESSION_ID && ($saved['status'] ?? null) === 'NEWPARTIAL1'
				&& ($saved['owner_id'] ?? null) === PP_ACCOUNT_ID,
				'session_id ' . show($saved['session_id'] ?? null) . ', status ' . show($saved['status'] ?? null) . ', owner_id ' . show($saved['owner_id'] ?? null));

			check($group, "the repository would not write the body's order", $repositoryWritesOrder($saved) === false,
				'purchase_order reaches the repository as ' . show($saved['purchase_order'] ?? '(absent)'));
		}
		catch (Throwable $e)
		{
			check($group, 'request', false, get_class($e) . ': ' . $e->getMessage());
		}
	}

	foreach (['{}', 'null', 'not json'] as $raw)
	{
		try
		{
			[$status, , $recorder] = post($raw);
			check('X', 'body ' . show($raw) . ' -> 400, nothing saved', $status === 400 && $recorder->saved === [], "status $status, saves " . count($recorder->saved));
		}
		catch (Throwable $e)
		{
			check('X', 'body ' . show($raw), false, get_class($e) . ': ' . $e->getMessage());
		}
	}

	// ---------------------------------------------------------------- S: which tree answered
	$controllerFile = (string)(new ReflectionClass(ApplicationController::class))->getFileName();
	check('S', 'ApplicationController came from PP_ROOT', str_starts_with(realpath($controllerFile) ?: '', (realpath($root) ?: $root) . '/'), $controllerFile);
	printf("     App files loaded from %s: %s\n", $root, implode(' ', array_unique($loadedFromRoot)));

	printf("%d passed, %d failed\n", $results['pass'], $results['fail']);
	exit($results['fail'] === 0 ? 0 : 1);
}
