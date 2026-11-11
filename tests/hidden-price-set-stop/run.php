<?php

/**
 * Hermetic test: with the price category hidden (bookingfrontend hide_price_category, ref #1477), a submit stops
 * with 409 price_set when it prices a cart line the applicant has not seen, and goes on when it prices nothing.
 *
 *   php tests/hidden-price-set-stop/run.php
 *   (in the pe-api container: docker exec -w /var/www/html pe-api php tests/hidden-price-set-stop/run.php)
 *
 * What is real and what is replaced:
 *   - ArticleRepository::priceLinesPendingWhileHidden() and settlePendingPriceChoices() (with assertPriceChoicesMade,
 *     getEligibleResourcePrices and getArticleMappingById) are the REAL code, on an instance made without its
 *     constructor. Its database is a stand-in that answers the five queries they make and records every UPDATE;
 *     any other query is a failure. Whether the category is hidden is set on the repository's own cache of it.
 *   - ApplicationService::stopIfPriceSetBeforeSubmit() is REAL, on an instance made without its constructor, with a
 *     stand-in database (only the transaction state), application repository (the session's applications) and
 *     article repository (hidden or shown, and what pricing returns), each recording what it is asked.
 *   - CheckoutController::checkout() and ::initiateVippsPayment() are REAL, on a stand-in service that records the
 *     order of the calls and a stand-in Vipps service that records any payment started.
 *   - PriceSetException, PriceChoiceException and ResponseHelper are real. Sessions, WebSocketHelper, Cache,
 *     PriceCategoryRepository and lang() are stand-ins. Every other App\ class is loaded from PP_ROOT only.
 *
 * Groups:
 *   S  setup: the classes under test came from PP_ROOT.
 *   R  the repository: hidden, a waiting line is written at master's price and returned only when that price is not
 *      0 kr (a line master prices at 0 kr is written but not returned, so it cannot stop every submit); a line of a
 *      resource without a price is left alone; shown, nothing is read or written by the hidden pricing, and
 *      settlePendingPriceChoices still refuses the line (PriceChoiceException) as before.
 *   T  the stop: hidden and a line priced ⇒ PriceSetException naming each application once, the cart push sent;
 *      hidden and nothing priced ⇒ it returns; shown ⇒ it returns without reading the cart or pricing; inside a
 *      transaction ⇒ LogicException before anything is priced.
 *   A  the answer: checkout and the Vipps route answer the stop with 409 {error, code price_set, application_ids}
 *      and call nothing after it (no checkout, no read for payment, no payment started); without a stop both go on,
 *      the stop first; shown, a missing price choice is still 422 with the same body.
 *
 * Red first: tests/hidden-price-set-stop/mutate.py applies single defects to a copy and expects this harness to go
 * red for each.
 *
 * Env (optional): PP_ROOT = tree holding src/ (default: this repo). PP_AUTOLOAD = composer's vendor/autoload.php,
 * for the vendor packages only (default: <PP_ROOT>/vendor/autoload.php, then <this repo>/vendor/autoload.php).
 *
 * Exit code: 0 all pass, 1 any failure (an arm that throws something unexpected is a FAIL line, not a crash).
 */

namespace App\modules\phpgwapi\security
{
	/** Stand-in: the controller asks it for the session id and nothing else. */
	final class Sessions
	{
		private static ?Sessions $instance = null;

		public static function getInstance(): self
		{
			return self::$instance ??= new self();
		}

		public function get_session_id(): string
		{
			return 'hpss-harness-session';
		}
	}
}

namespace App\modules\phpgwapi\services
{
	/** Stand-in: a successful checkout stores its message here. */
	final class Cache
	{
		public static array $messages = [];

		public static function message_set($message, $type = 'message', $title = null, $id = null): void
		{
			self::$messages[] = $message;
		}
	}
}

namespace App\modules\booking\repositories
{
	/** Stand-in: the real one asks the database whether the price category table exists. */
	final class PriceCategoryRepository
	{
		public static function isInstalled(): bool
		{
			return false;
		}
	}
}

namespace App\modules\bookingfrontend\helpers
{
	/** Stand-in: records the cart pushes instead of publishing them on Redis. */
	final class WebSocketHelper
	{
		public static array $pushes = [];

		public static function triggerPartialApplicationsUpdate(?string $sessionId = null): bool
		{
			self::$pushes[] = $sessionId;
			return true;
		}
	}
}

namespace
{
	use App\modules\bookingfrontend\controllers\applications\CheckoutController;
	use App\modules\bookingfrontend\helpers\PriceChoiceException;
	use App\modules\bookingfrontend\helpers\PriceSetException;
	use App\modules\bookingfrontend\helpers\WebSocketHelper;
	use App\modules\bookingfrontend\repositories\ArticleRepository;
	use App\modules\bookingfrontend\services\applications\ApplicationService;
	use Slim\Psr7\Factory\ServerRequestFactory;
	use Slim\Psr7\Factory\StreamFactory;

	const SESSION = 'hpss-harness-session';
	const MAPPING = 873;
	const FREE_MAPPING = 874;   // a resource without a price
	const APP_A = 800001;
	const APP_B = 800002;

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

	// Anything thrown outside an arm (a class or method the tree does not have) is a FAIL line, not a crash
	set_exception_handler(static function (Throwable $e): void {
		global $results;
		check('X', 'the harness ran to the end', false, get_class($e) . ': ' . $e->getMessage());
		printf("\n%d passed, %d failed\n", $results['pass'], $results['fail']);
		exit(1);
	});

	/** Stand-in for the translation function: the key, marked, so the body shows which key was asked for. */
	function lang($key, ...$args): string
	{
		return "lang($key)";
	}

	if (!is_dir("$root/src") || !is_file($autoload))
	{
		check('S', 'setup', false, "no $root/src or no autoload at $autoload");
		exit(1);
	}

	// The legacy include ApplicationService requires, empty
	$legacyRoot = sys_get_temp_dir() . '/hpss-harness-' . getmypid();
	@mkdir("$legacyRoot/helpers", 0700, true);
	file_put_contents("$legacyRoot/helpers/LegacyObjectHandler.php", "<?php\n");
	register_shutdown_function(static function () use ($legacyRoot): void {
		@unlink("$legacyRoot/helpers/LegacyObjectHandler.php");
		@rmdir("$legacyRoot/helpers");
		@rmdir($legacyRoot);
	});
	define('SRC_ROOT_PATH', $legacyRoot);

	// App\ from PP_ROOT only, put in front of composer's loader
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

	/**
	 * The repository's database: answers the queries of the pricing and the assert from the lines and prices it is
	 * given, records each UPDATE, and fails on any other query.
	 */
	final class RepoDb
	{
		/** @var array<int, array{id:int, article_mapping_id:int, quantity:string, application_id:int}> */
		public array $pending = [];
		/** @var array<int, array> eligible price rows by mapping */
		public array $eligible = [];
		/** @var array<int, ?string> master's price by mapping (null: the query found no price row) */
		public array $masterPrice = [];
		public array $updates = [];
		public array $queries = [];

		public function prepare(string $sql): object
		{
			$db = $this;
			return new class($db, $sql) {
				private array $rows = [];

				public function __construct(private RepoDb $db, private string $sql)
				{
				}

				public function execute(array $params = []): bool
				{
					$sql = $this->sql;
					if (str_contains($sql, 'SELECT pol.id, pol.article_mapping_id, pol.quantity, po.application_id'))
					{
						$this->db->queries[] = 'pending';
						$this->rows = array_values(array_filter($this->db->pending,
							static fn($l) => in_array($l['application_id'], array_map('intval', $params), true)));
					}
					elseif (str_contains($sql, 'SELECT DISTINCT pol.article_mapping_id'))
					{
						$this->db->queries[] = 'assert';
						$ids = array_map('intval', $params);
						$this->rows = array_values(array_unique(array_map(static fn($l) => $l['article_mapping_id'],
							array_filter($this->db->pending, static fn($l) => in_array($l['application_id'], $ids, true)))));
					}
					elseif (str_contains($sql, 'SELECT p.id, p.price, p.remark, p.default_'))
					{
						$this->db->queries[] = 'eligible';
						$this->rows = $this->db->eligible[(int)$params[':mapping_id']] ?? [];
					}
					elseif (str_contains($sql, 'SELECT am.*, p.price, am.tax_code, e.percent_ AS tax_percent'))
					{
						$this->db->queries[] = 'mapping';
						$id = (int)$params['id'];
						$this->rows = [['id' => $id, 'price' => $this->db->masterPrice[$id] ?? null, 'tax_code' => 9, 'tax_percent' => 25]];
					}
					elseif (str_contains($sql, 'UPDATE bb_purchase_order_line'))
					{
						$this->db->queries[] = 'update';
						$this->db->updates[(int)$params[':id']] = [
							'unit_price' => (float)$params[':unit_price'],
							'amount' => (float)$params[':amount'],
							'tax' => (float)$params[':tax'],
							'tax_code' => $params[':tax_code'],
						];
					}
					else
					{
						throw new LogicException('unexpected query: ' . substr(preg_replace('/\s+/', ' ', $sql), 0, 80));
					}
					return true;
				}

				public function fetchAll($mode = null): array
				{
					return $this->rows;
				}

				public function fetch($mode = null)
				{
					return $this->rows[0] ?? false;
				}
			};
		}
	}

	/** A real ArticleRepository on RepoDb, hidden or shown. */
	function repository(RepoDb $db, bool $hidden): ArticleRepository
	{
		$class = new ReflectionClass(ArticleRepository::class);
		$repository = $class->newInstanceWithoutConstructor();
		$class->getProperty('db')->setValue($repository, $db);
		$class->getProperty('hidesPriceChoice')->setValue($repository, $hidden);
		return $repository;
	}

	/** The fixture of the live run: room 873, two prices and no default; master's query bills 50.00. */
	function waitingLineDb(?string $masterPrice = '50.00'): RepoDb
	{
		$db = new RepoDb();
		$db->pending = [
			['id' => 18937, 'article_mapping_id' => MAPPING, 'quantity' => '2.00', 'application_id' => APP_A],
		];
		$db->eligible[MAPPING] = [
			['id' => 35, 'price' => '50.00', 'remark' => null, 'default_' => null, 'category_id' => null, 'category_name' => null],
			['id' => 36, 'price' => '120.00', 'remark' => null, 'default_' => null, 'category_id' => null, 'category_name' => null],
		];
		$db->masterPrice[MAPPING] = $masterPrice;
		return $db;
	}

	// ---------------------------------------------------------------------------------------------------- S
	foreach ([ArticleRepository::class, ApplicationService::class, CheckoutController::class, PriceSetException::class] as $loaded)
	{
		$file = (new ReflectionClass($loaded))->getFileName();
		check('S', "$loaded is loaded from PP_ROOT", str_starts_with($file, "$root/src/"), $file);
	}

	// ---------------------------------------------------------------------------------------------------- R
	$db = waitingLineDb();
	$priced = repository($db, true)->priceLinesPendingWhileHidden([APP_A]);
	check('R', 'hidden: a waiting line is priced and returned (line => application)', $priced === [18937 => APP_A], show($priced));
	check('R', "hidden: it is written at master's price, 50.00 x 2 = 100.00 + 25.00 VAT",
		($db->updates[18937] ?? null) === ['unit_price' => 50.0, 'amount' => 100.0, 'tax' => 25.0, 'tax_code' => 9], show($db->updates));

	$db = waitingLineDb(null);
	$priced = repository($db, true)->priceLinesPendingWhileHidden([APP_A]);
	check('R', 'loop hazard: a line master prices at 0 kr (no price row today) is written but NOT returned',
		$priced === [] && ($db->updates[18937]['amount'] ?? null) === 0.0, show(['returned' => $priced, 'updates' => $db->updates]));
	$db = waitingLineDb('0.00');
	$priced = repository($db, true)->priceLinesPendingWhileHidden([APP_A]);
	check('R', 'loop hazard: a line master prices at 0.00 is not returned', $priced === [], show($priced));

	$db = waitingLineDb();
	$db->pending[] = ['id' => 18990, 'article_mapping_id' => FREE_MAPPING, 'quantity' => '1.00', 'application_id' => APP_A];
	$priced = repository($db, true)->priceLinesPendingWhileHidden([APP_A]);
	check('R', 'hidden: a line of a resource without a price is neither written nor returned',
		$priced === [18937 => APP_A] && !isset($db->updates[18990]), show(['returned' => $priced, 'updates' => array_keys($db->updates)]));

	$db = waitingLineDb();
	$priced = repository($db, true)->priceLinesPendingWhileHidden([APP_B]);
	check('R', "hidden: another application's line is not touched", $priced === [] && $db->updates === [], show($db->updates));

	$db = waitingLineDb();
	$priced = repository($db, false)->priceLinesPendingWhileHidden([APP_A]);
	check('R', 'shown: the hidden pricing reads and writes nothing', $priced === [] && $db->queries === [], show($db->queries));

	$db = waitingLineDb();
	check('R', 'hidden: settlePendingPriceChoices counts the line given a price', repository($db, true)->settlePendingPriceChoices([APP_A]) === 1);
	$db = waitingLineDb(null);
	check('R', 'hidden: settlePendingPriceChoices does not count a 0 kr line', repository($db, true)->settlePendingPriceChoices([APP_A]) === 0);

	$db = waitingLineDb();
	try
	{
		repository($db, false)->settlePendingPriceChoices([APP_A]);
		check('R', 'shown: settlePendingPriceChoices still refuses the waiting line', false, 'no exception');
	}
	catch (PriceChoiceException $e)
	{
		check('R', 'shown: settlePendingPriceChoices still refuses the waiting line (PriceChoiceException, mapping 873)',
			$e->getReason() === PriceChoiceException::REQUIRED && $e->getArticleMappingId() === MAPPING && $db->updates === [],
			show([$e->getReason(), $e->getArticleMappingId(), $db->updates]));
	}

	// ---------------------------------------------------------------------------------------------------- T
	final class TxDb
	{
		public function __construct(public bool $open = false)
		{
		}

		public function inTransaction(): bool
		{
			return $this->open;
		}
	}

	final class AppRepo
	{
		public int $reads = 0;

		public function __construct(private array $ids)
		{
		}

		public function getPartialApplications(string $sessionId): array
		{
			$this->reads++;
			return array_map(static fn($id) => ['id' => $id], $this->ids);
		}
	}

	final class PricingRepo
	{
		public array $asked = [];

		public function __construct(private bool $hidden, private array $priced)
		{
		}

		public function hidesPriceChoice(): bool
		{
			return $this->hidden;
		}

		public function priceLinesPendingWhileHidden(array $applicationIds): array
		{
			$this->asked[] = $applicationIds;
			return $this->priced;
		}
	}

	/** @return array{0: ?Throwable, 1: AppRepo, 2: PricingRepo} */
	function stop(bool $hidden, array $priced, bool $inTransaction = false, array $cart = [APP_A, APP_B]): array
	{
		WebSocketHelper::$pushes = [];
		$class = new ReflectionClass(ApplicationService::class);
		$service = $class->newInstanceWithoutConstructor();
		$apps = new AppRepo($cart);
		$pricing = new PricingRepo($hidden, $priced);
		$class->getProperty('db')->setValue($service, new TxDb($inTransaction));
		$class->getProperty('applicationRepository')->setValue($service, $apps);
		$class->getProperty('articleRepository')->setValue($service, $pricing);
		try
		{
			$service->stopIfPriceSetBeforeSubmit(SESSION);
			return [null, $apps, $pricing];
		}
		catch (Throwable $e)
		{
			return [$e, $apps, $pricing];
		}
	}

	[$e, $apps, $pricing] = stop(true, [18937 => APP_A]);
	check('T', 'hidden, a line priced: settled > 0 => STOP (PriceSetException)', $e instanceof PriceSetException, $e ? get_class($e) : 'returned');
	check('T', "hidden, a line priced: the session's cart is what is priced", $pricing->asked === [[APP_A, APP_B]] && $apps->reads === 1, show($pricing->asked));
	check('T', 'hidden, a line priced: the cart push is sent for the session', WebSocketHelper::$pushes === [SESSION], show(WebSocketHelper::$pushes));
	if ($e instanceof PriceSetException)
	{
		$body = $e->toResponseBody();
		check('T', 'the body: translated text, code price_set, the application priced',
			$body === ['error' => 'lang(bookingfrontend.price_set_check_amount)', 'code' => 'price_set', 'application_ids' => [APP_A]], show($body));
	}

	[$e] = stop(true, [18937 => APP_A, 18938 => APP_A, 18940 => APP_B]);
	check('T', 'hidden, three lines in two applications: each application named once',
		$e instanceof PriceSetException && $e->getApplicationIds() === [APP_A, APP_B], $e instanceof PriceSetException ? show($e->getApplicationIds()) : 'no stop');

	[$e, , $pricing] = stop(true, []);
	check('T', 'hidden, nothing priced: settled = 0 => CONTINUE (returns)', $e === null && count($pricing->asked) === 1 && WebSocketHelper::$pushes === [],
		$e ? get_class($e) : show(WebSocketHelper::$pushes));

	[$e, $apps, $pricing] = stop(false, [18937 => APP_A]);
	check('T', 'shown: returns without reading the cart or pricing', $e === null && $apps->reads === 0 && $pricing->asked === [],
		show(['e' => $e ? get_class($e) : null, 'reads' => $apps->reads, 'asked' => $pricing->asked]));

	[$e, $apps, $pricing] = stop(true, [18937 => APP_A], true);
	check('T', 'inside a transaction: LogicException before anything is priced', $e instanceof LogicException && $pricing->asked === [] && $apps->reads === 0,
		show(['e' => $e ? get_class($e) : null, 'asked' => $pricing->asked]));

	// ---------------------------------------------------------------------------------------------------- A
	final class FakeService
	{
		public array $calls = [];

		public function __construct(private ?Throwable $stop = null, private ?Throwable $checkout = null)
		{
		}

		public function stopIfPriceSetBeforeSubmit(string $sessionId): void
		{
			$this->calls[] = 'stop';
			if ($this->stop)
			{
				throw $this->stop;
			}
		}

		public function checkoutPartials(string $sessionId, array $data): array
		{
			$this->calls[] = 'checkoutPartials';
			if ($this->checkout)
			{
				throw $this->checkout;
			}
			return ['updated' => [['id' => APP_A, 'status' => 'ACCEPTED']], 'skipped' => [], 'debug_collisions' => []];
		}

		public function getPartialApplications(string $sessionId): array
		{
			$this->calls[] = 'getPartialApplications';
			return [['id' => APP_A, 'recurring_info' => null, 'resources' => [['id' => 951, 'direct_booking' => null]]]];
		}

		public function updateApplicationsWithContactInfo(string $sessionId, array $data, bool $finalize = true): array
		{
			$this->calls[] = 'updateApplicationsWithContactInfo';
			return $this->getPartialApplications($sessionId);
		}

		public function calculateTotalSum(array $applications): float
		{
			return 125.0;
		}
	}

	final class FakeVipps
	{
		public array $calls = [];

		public function isConfigured(): bool
		{
			$this->calls[] = 'isConfigured';
			return true;
		}

		public function initiatePayment(array $ids): array
		{
			$this->calls[] = 'initiatePayment';
			return ['url' => 'https://vipps.invalid/'];
		}
	}

	/** @return array{0: int, 1: array, 2: FakeService, 3: FakeVipps} */
	function answer(string $method, string $path, FakeService $service): array
	{
		$class = new ReflectionClass(CheckoutController::class);
		$controller = $class->newInstanceWithoutConstructor();
		$vipps = new FakeVipps();
		$class->getProperty('applicationService')->setValue($controller, $service);
		$class->getProperty('vippsService')->setValue($controller, $vipps);
		$body = (new StreamFactory())->createStream(json_encode([
			'organizerName' => 'T42662', 'customerType' => 'ssn', 'contactName' => 'T', 'contactEmail' => 't@example.invalid',
			'contactPhone' => '1', 'street' => 'S', 'zipCode' => '5000', 'city' => 'C', 'documentsRead' => true,
		]));
		$request = (new ServerRequestFactory())->createServerRequest('POST', $path)->withBody($body);
		$response = $controller->$method($request, new \Slim\Psr7\Response());
		return [$response->getStatusCode(), json_decode((string)$response->getBody(), true) ?? [], $service, $vipps];
	}

	$stopped = new PriceSetException([APP_A]);
	[$status, $body, $service] = answer('checkout', '/bookingfrontend/applications/partials/checkout', new FakeService($stopped));
	check('A', 'checkout, stopped: 409 with code price_set and the application', $status === 409 && ($body['code'] ?? null) === 'price_set'
		&& ($body['application_ids'] ?? null) === [APP_A] && ($body['error'] ?? null) === 'lang(bookingfrontend.price_set_check_amount)', "$status " . show($body));
	check('A', 'checkout, stopped: nothing after the stop (no checkoutPartials)', $service->calls === ['stop'], show($service->calls));

	[$status, , $service] = answer('checkout', '/bookingfrontend/applications/partials/checkout', new FakeService());
	check('A', 'checkout, not stopped: 200, the stop first, then checkoutPartials', $status === 200 && $service->calls === ['stop', 'checkoutPartials'],
		"$status " . show($service->calls));

	[$status, $body, $service, $vipps] = answer('initiateVippsPayment', '/bookingfrontend/applications/partials/vipps-payment', new FakeService($stopped));
	check('A', 'Vipps route, stopped: 409 with code price_set', $status === 409 && ($body['code'] ?? null) === 'price_set', "$status " . show($body));
	check('A', 'Vipps route, stopped: nothing after the stop (no read for payment, no contact update)', $service->calls === ['stop'], show($service->calls));
	check('A', 'Vipps route, stopped: no payment is started', $vipps->calls === [], show($vipps->calls));

	[$status, , $service] = answer('initiateVippsPayment', '/bookingfrontend/applications/partials/vipps-payment', new FakeService());
	check('A', 'Vipps route, not stopped: the stop first, then the read for payment', $status === 200
		&& array_slice($service->calls, 0, 2) === ['stop', 'getPartialApplications'], "$status " . show($service->calls));

	[$status, $body] = answer('checkout', '/bookingfrontend/applications/partials/checkout',
		new FakeService(null, new PriceChoiceException(PriceChoiceException::REQUIRED, MAPPING)));
	check('A', 'shown, unchanged: a missing price choice is still 422 with the same body', $status === 422 && $body === [
		'error' => 'A price choice is required for article mapping 873',
		'errors' => ['A price choice is required for article mapping 873'],
		'code' => 'price_choice_required',
		'article_mapping_id' => MAPPING,
	], "$status " . show($body));

	printf("\n%d passed, %d failed\n", $results['pass'], $results['fail']);
	exit($results['fail'] === 0 ? 0 : 1);
}
