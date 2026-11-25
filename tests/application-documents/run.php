<?php

/**
 * Hermetic tests for who may list and download an application's documents through the citizen API (task 48864; facts
 * 48858, 48927; declaration forum/pe-general/48928):
 *   GET /bookingfrontend/applications/{id}/documents          ApplicationController::getDocuments
 *   GET /bookingfrontend/applications/document/{id}/download  ApplicationController::downloadDocument
 *
 * ApplicationController inherited both from DocumentController, which checks only that the document exists, so anyone,
 * without a session too, could list and download every application's attachments by id. They now apply the rule of
 * GET /bookingfrontend/applications/{id}: ApplicationHelper::canViewApplication (the application's secret, the session
 * of its partial, or the logged-in applicant or a delegate of its organisation).
 *
 *   php tests/application-documents/run.php
 *
 * The classes under test are the REAL code from HD_ROOT: ApplicationController and DocumentController (bookingfrontend),
 * ApplicationHelper, DocumentService, DocumentRepository and the Document model (booking), SerializableTrait.
 * ApplicationController is built without its constructor (which opens the container's services) and given: a stand-in
 * for ApplicationService::getApplicationById (the fixture applications, as arrays), the real ApplicationHelper and the
 * real DocumentService. Stubs: App\Database\Db (an in-memory SQLite bb_document_application / bb_document_building
 * under the real DocumentRepository), Sessions (the session id), ServerSettings (files_dir: a temporary directory with
 * the fixture files) and Translation. The viewer is a UserHelper subclass that holds ssn, orgnr and organizations as a
 * logged-in UserHelper does, without reading a session.
 *
 * P   every class under test is the HD_ROOT copy.
 * NO-BREAK rows (pass on the base tree AND on the fix):
 *   N1  the applicant logged in with the application's ssn lists and downloads its document (the file's bytes)
 *   N2  the session that made a partial (NEWPARTIAL1) lists and downloads its document
 *   N3  the application's secret (?secret=), from another guest session, lists and downloads
 *   N4  a delegate of the application's organisation (logged in) lists and downloads
 *   N5  a building's documents are unchanged: a guest lists and downloads them (DocumentController itself)
 *   N6  a document id that does not exist: 404
 * FIX rows (fail on the base tree, pass on the fix):
 *   F1  a guest from another session: 403 for the list and for the download
 *   F2  no session at all: 403 for both
 *   F3  another logged-in citizen: 403 for both
 *   F4  a wrong secret: 403 for both
 *   F5  another guest session than the one that made the partial: 403 for both
 *   F6  the list for an application that does not exist: 404 (base: 200 with an empty list)
 *   F7  a document whose application does not exist: 404 (base: the file)
 *
 * Red first: git archive <base> src | tar -x -C /tmp/ad-base && HD_ROOT=/tmp/ad-base php tests/application-documents/run.php
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo). vendor/ comes from HD_ROOT if it has one,
 * else from this repo; App\ classes always load from HD_ROOT.
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, never a crash).
 */

namespace App\Database {
    /** Stand-in for App\Database\Db: prepare() on an in-memory SQLite database holding the two document tables. */
    final class Db
    {
        private static ?self $instance = null;
        public \PDO $pdo;

        public static function getInstance($dsn = '', $username = null, $password = null, $options = null): self
        {
            return self::$instance ??= new self();
        }

        private function __construct()
        {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach (['bb_document_application', 'bb_document_building'] as $table) {
                $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, name TEXT NOT NULL, owner_id INTEGER NOT NULL,"
                    . " category TEXT NOT NULL, description TEXT, metadata TEXT)");
            }
            $this->pdo = $pdo;
        }

        public function prepare($sql, $options = [])
        {
            return $this->pdo->prepare($sql, $options);
        }

        public function __call($method, $arguments)
        {
            return $this->pdo->$method(...$arguments);
        }
    }
}

namespace App\modules\phpgwapi\security {
    final class Sessions
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public function get_session_id()
        {
            return $GLOBALS['HARNESS_SESSION_ID'];
        }
    }
}

namespace App\modules\phpgwapi\models {
    final class ServerSettings
    {
        private static ?self $instance = null;
        public $files_dir;

        public static function getInstance(bool $includeConfigs = false): self
        {
            if (!self::$instance) {
                self::$instance = new self();
                self::$instance->files_dir = $GLOBALS['HARNESS_FILES_DIR'];
            }
            return self::$instance;
        }
    }
}

namespace App\modules\phpgwapi\services {
    final class Translation
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public function translate($key, $args = [], $force = false, $app = '')
        {
            return (string)$key;
        }
    }
}

namespace {
    use App\modules\bookingfrontend\controllers\applications\ApplicationController;
    use App\modules\bookingfrontend\controllers\DocumentController;
    use App\modules\bookingfrontend\helpers\ApplicationHelper;
    use App\modules\bookingfrontend\helpers\UserHelper;
    use App\modules\booking\models\Document;
    use Slim\Psr7\Factory\ServerRequestFactory;
    use Slim\Psr7\Response;

    $HD_ROOT = rtrim(getenv('HD_ROOT') ?: dirname(__DIR__, 2), '/');
    // Warnings and deprecations are not failures here (SerializableTrait calls setAccessible, deprecated in PHP 8.5);
    // each distinct one is listed as a NOTE at the end, with its file and line, so a new one is visible.
    $GLOBALS['HARNESS_NOTES'] = [];
    set_error_handler(function ($no, $msg, $file, $line) {
        $GLOBALS['HARNESS_NOTES'][basename($file) . ":{$line} {$msg}"] = true;
        return true;
    }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
    $vendor = is_file("{$HD_ROOT}/vendor/autoload.php") ? "{$HD_ROOT}/vendor/autoload.php" : dirname(__DIR__, 2) . '/vendor/autoload.php';
    require $vendor;
    // App\ from HD_ROOT, ahead of composer's map (which points at the repo the vendor/ belongs to)
    spl_autoload_register(function ($class) use ($HD_ROOT) {
        if (strncmp($class, 'App\\', 4) === 0) {
            $file = "{$HD_ROOT}/src/" . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);

    $GLOBALS['HARNESS_FILES_DIR'] = sys_get_temp_dir() . '/ad-harness-' . getmypid();
    $GLOBALS['HARNESS_SESSION_ID'] = '';

    /** A UserHelper as a request has it: ssn and orgnr when logged in, the delegations in organizations. */
    final class HarnessViewer extends UserHelper
    {
        public function __construct(?string $ssn = null, ?string $orgnr = null, ?array $organizations = null)
        {
            $this->ssn = $ssn;
            $this->orgnr = $orgnr;
            $this->organizations = $organizations;
        }

        public function get_user_orgnr()
        {
            return $this->orgnr;
        }
    }

    // The fixture: three applications and their documents, a fourth document without an application, a building's.
    $APPS = [
        501 => ['id' => 501, 'status' => 'PENDING', 'session_id' => 'sess-owner', 'secret' => 'secret-501-abcdef',
            'customer_identifier_type' => 'ssn', 'customer_ssn' => '01010112345', 'customer_organization_number' => null,
            'customer_organization_id' => null],
        502 => ['id' => 502, 'status' => 'NEWPARTIAL1', 'session_id' => 'sess-A', 'secret' => 'secret-502-abcdef',
            'customer_identifier_type' => null, 'customer_ssn' => null, 'customer_organization_number' => null,
            'customer_organization_id' => null],
        503 => ['id' => 503, 'status' => 'PENDING', 'session_id' => 'sess-org', 'secret' => 'secret-503-abcdef',
            'customer_identifier_type' => 'organization_number', 'customer_ssn' => null,
            'customer_organization_number' => '999888777', 'customer_organization_id' => 77],
    ];
    $DOCS = [1 => 501, 2 => 502, 3 => 503, 4 => 999];

    $dir = $GLOBALS['HARNESS_FILES_DIR'];
    @mkdir("{$dir}/booking/application", 0700, true);
    @mkdir("{$dir}/booking/building", 0700, true);
    $db = App\Database\Db::getInstance();
    foreach ($DOCS as $docId => $ownerId) {
        $db->pdo->prepare('INSERT INTO bb_document_application (id, name, owner_id, category) VALUES (?, ?, ?, ?)')
            ->execute([$docId, "doc{$docId}.txt", $ownerId, 'other']);
        file_put_contents("{$dir}/booking/application/{$docId}_doc{$docId}.txt", "application document {$docId}\n");
    }
    $db->pdo->prepare('INSERT INTO bb_document_building (id, name, owner_id, category) VALUES (?, ?, ?, ?)')
        ->execute([7, 'plan.txt', 10, 'drawing']);
    file_put_contents("{$dir}/booking/building/7_plan.txt", "building document 7\n");
    register_shutdown_function(function () use ($dir) {
        foreach (glob("{$dir}/booking/*/*") as $f) {
            @unlink($f);
        }
        @rmdir("{$dir}/booking/application");
        @rmdir("{$dir}/booking/building");
        @rmdir("{$dir}/booking");
        @rmdir($dir);
    });

    /** ApplicationController without its constructor, with the fixture applications and the given viewer. */
    function controller(HarnessViewer $viewer): ApplicationController
    {
        global $APPS;
        $c = (new \ReflectionClass(ApplicationController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(DocumentController::class, 'documentService'))
            ->setValue($c, new App\modules\booking\services\DocumentService(Document::OWNER_APPLICATION));
        (new \ReflectionProperty(ApplicationController::class, 'applicationService'))->setValue($c, new class($APPS) {
            public function __construct(private array $apps)
            {
            }

            public function getApplicationById(int $id): ?array
            {
                return $this->apps[$id] ?? null;
            }
        });
        $helper = (new \ReflectionClass(ApplicationHelper::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ApplicationHelper::class, 'userHelper'))->setValue($helper, $viewer);
        (new \ReflectionProperty(ApplicationController::class, 'applicationHelper'))->setValue($c, $helper);
        return $c;
    }

    /** @return array{0: int, 1: string} status and body */
    function call(string $method, HarnessViewer $viewer, string $session, int $id, array $query = []): array
    {
        $GLOBALS['HARNESS_SESSION_ID'] = $session;
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://pe-api.test/')->withQueryParams($query);
        $response = controller($viewer)->$method($request, new Response(), ['id' => (string)$id]);
        return [$response->getStatusCode(), (string)$response->getBody()];
    }

    /** The list's ids, or null when the body is not a list. */
    function listed(string $body): ?array
    {
        $data = json_decode($body, true);
        return is_array($data) && array_is_list($data) ? array_map(fn($d) => (int)$d['id'], $data) : null;
    }

    $pass = 0;
    $fail = 0;
    function check(string $row, bool $ok, string $what, string $detail = ''): void
    {
        global $pass, $fail;
        $ok ? $pass++ : $fail++;
        echo ($ok ? 'PASS ' : 'FAIL ') . "{$row}  {$what}" . ($ok || $detail === '' ? '' : "  [{$detail}]") . "\n";
    }

    function arm(string $row, string $what, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            check($row, false, $what, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }

    /** The viewer may list application $app and download document $doc, and gets the document's bytes. */
    function allowed(string $row, string $what, HarnessViewer $viewer, string $session, int $app, int $doc, array $query = []): void
    {
        arm($row, $what, function () use ($row, $what, $viewer, $session, $app, $doc, $query) {
            [$ls, $lb] = call('getDocuments', $viewer, $session, $app, $query);
            [$ds, $db] = call('downloadDocument', $viewer, $session, $doc, $query);
            check($row, $ls === 200 && listed($lb) === [$doc] && $ds === 200 && $db === "application document {$doc}\n",
                $what, "list {$ls} " . substr($lb, 0, 80) . " | download {$ds} " . substr($db, 0, 60));
        });
    }

    /** The viewer gets 403 for the list of application $app and the download of document $doc, and no document data. */
    function refused(string $row, string $what, HarnessViewer $viewer, string $session, int $app, int $doc, array $query = []): void
    {
        arm($row, $what, function () use ($row, $what, $viewer, $session, $app, $doc, $query) {
            [$ls, $lb] = call('getDocuments', $viewer, $session, $app, $query);
            [$ds, $db] = call('downloadDocument', $viewer, $session, $doc, $query);
            $leak = strpos($lb, "doc{$doc}") !== false || strpos($db, "application document") !== false;
            check($row, $ls === 403 && $ds === 403 && !$leak, $what,
                "list {$ls} " . substr($lb, 0, 80) . " | download {$ds} " . substr($db, 0, 60));
        });
    }

    $guest = new HarnessViewer();
    $owner = new HarnessViewer('01010112345', '000000000', []);
    $other = new HarnessViewer('02020212345', '000000000', []);
    $delegate = new HarnessViewer('03030312345', '000000000', [['orgnr' => '999888777', 'org_id' => 77]]);

    // ---------------------------------------------------------------------------------------------------- P
    $real = true;
    foreach ([ApplicationController::class, DocumentController::class, ApplicationHelper::class, UserHelper::class,
                 App\modules\booking\services\DocumentService::class, App\modules\booking\repositories\DocumentRepository::class,
                 Document::class] as $class) {
        $file = (new \ReflectionClass($class))->getFileName();
        $real = $real && strpos(realpath($file), realpath($HD_ROOT) . '/src/') === 0;
    }
    check('P', $real, "every class under test is the HD_ROOT copy ({$HD_ROOT})");

    allowed('N1', 'the applicant logged in with its ssn lists and downloads', $owner, 'sess-x', 501, 1);
    allowed('N2', 'the session that made the partial lists and downloads', $guest, 'sess-A', 502, 2);
    allowed('N3', "the application's secret, from another guest session, lists and downloads", $guest, 'sess-B', 501, 1,
        ['secret' => 'secret-501-abcdef']);
    allowed('N4', "a delegate of the application's organisation lists and downloads", $delegate, 'sess-y', 503, 3);
    arm('N5', "a building's documents", function () use ($guest) {
        $GLOBALS['HARNESS_SESSION_ID'] = '';
        $c = new DocumentController(Document::OWNER_BUILDING);
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://pe-api.test/');
        $l = $c->getDocuments($request, new Response(), ['id' => '10']);
        $d = $c->downloadDocument($request, new Response(), ['id' => '7']);
        check('N5', $l->getStatusCode() === 200 && listed((string)$l->getBody()) === [7] && $d->getStatusCode() === 200
            && (string)$d->getBody() === "building document 7\n",
            "a building's documents: a guest lists and downloads them (DocumentController unchanged)");
    });
    arm('N6', 'unknown document', function () use ($owner) {
        [$s] = call('downloadDocument', $owner, 'sess-x', 12345);
        check('N6', $s === 404, 'a document id that does not exist: 404', "got {$s}");
    });

    refused('F1', 'a guest from another session: 403 for the list and the download', $guest, 'sess-B', 501, 1);
    refused('F2', 'no session at all: 403 for both', $guest, '', 501, 1);
    refused('F3', 'another logged-in citizen: 403 for both', $other, 'sess-z', 501, 1);
    refused('F4', 'a wrong secret: 403 for both', $guest, 'sess-B', 501, 1, ['secret' => 'secret-501-abcdeX']);
    refused('F5', 'another guest session than the partial\'s: 403 for both', $guest, 'sess-B', 502, 2);
    arm('F6', 'list for a missing application', function () use ($owner) {
        [$s, $b] = call('getDocuments', $owner, 'sess-x', 777);
        check('F6', $s === 404, 'the list for an application that does not exist: 404', "got {$s} " . substr($b, 0, 60));
    });
    arm('F7', 'document without application', function () use ($owner) {
        [$s, $b] = call('downloadDocument', $owner, 'sess-x', 4);
        check('F7', $s === 404 && strpos($b, 'application document') === false,
            'a document whose application does not exist: 404', "got {$s} " . substr($b, 0, 60));
    });

    if (getenv('HARNESS_LIST_FILES')) {
        foreach (get_included_files() as $file) {
            echo "FILE {$file}\n";
        }
    }
    foreach (array_keys($GLOBALS['HARNESS_NOTES']) as $note) {
        echo "NOTE {$note}\n";
    }
    echo "\n" . ($fail === 0 ? "ALL PASS {$pass}" : "FAIL {$fail} of " . ($pass + $fail)) . "\n";
    exit($fail === 0 ? 0 : 1);
}
