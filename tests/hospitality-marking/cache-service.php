<?php

/**
 * #1424 (Q6): what CacheService::invalidateHospitality() really sends.
 *
 *   php tests/hospitality-marking/cache-service.php
 *
 * The REAL CacheService, with its two outward channels replaced by recorders:
 *   - the HTTP request to Next.js' /api/cache-reset (an `http` stream wrapper that records the URL and answers 200, nothing leaves the process),
 *   - WebSocketHelper::sendCacheInvalidation (the React Query keys the open browsers are told to drop).
 * Held: the request revalidates exactly the tags the search data and the resource fetches carry (`search-data`, `resources`: see
 * api-utils-static.ts and building-ssr.ts), and the browsers drop exactly the three queries that carry has_hospitality, by key PREFIX
 * (`searchData`, `resource`, `buildingResources`).
 * A control row first: invalidateSeason() is recorded the same way, so a recorder that saw nothing cannot pass for a clean method.
 *
 * Needs no database. CACHE_SERVICE_FILE (optional) points at an alternative copy of CacheService, which mutate-pg.py uses to show each
 * deliberately broken copy turns this runner red.
 * Exit: 0 all rows pass, 1 a row failed, 2 nothing could be measured.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__, 2);
putenv('NEXTJS_SERVER=cache-test.invalid:3000');
putenv('NEXTJS_HOST');

require $root . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
}, true, true);

final class RecordingWebSocketHelper
{
    public static array $sent = [];

    public static function sendCacheInvalidation(array $queryKeys, ?array $debug = null): bool
    {
        self::$sent[] = $queryKeys;
        return true;
    }
}
class_alias(RecordingWebSocketHelper::class, 'App\modules\bookingfrontend\helpers\WebSocketHelper');

/** A stand-in for the `http` stream wrapper: records the URL, answers a body, and never opens a socket. */
final class RecordingHttp
{
    private const BODY = '{"message":"ok"}';

    public static array $urls = [];
    public $context;
    private bool $done = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        self::$urls[] = $path;
        return true;
    }

    public function stream_read(int $count): string
    {
        // PHP reads once more after the body: an empty string ends it (answering the body again never ends)
        if ($this->done) {
            return '';
        }
        $this->done = true;
        return self::BODY;
    }

    public function stream_eof(): bool
    {
        return $this->done;
    }

    /** A complete stat: file_get_contents() sizes its buffer from it, and a missing or partial one makes PHP allocate garbage. */
    public function stream_stat(): array
    {
        return ['dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1, 'uid' => 0, 'gid' => 0, 'rdev' => 0,
            'size' => strlen(self::BODY), 'atime' => 0, 'mtime' => 0, 'ctime' => 0, 'blksize' => 0, 'blocks' => 0];
    }
}
if (!stream_wrapper_unregister('http') || !stream_wrapper_register('http', RecordingHttp::class)) {
    fwrite(STDERR, "CANNOT MEASURE: could not put the recording http wrapper in place\n");
    exit(2);
}

require_once getenv('CACHE_SERVICE_FILE') ?: $root . '/src/modules/bookingfrontend/services/CacheService.php';

use App\modules\bookingfrontend\services\CacheService;

$rows = [];
$expect = static function (string $name, $actual, $expected) use (&$rows): void {
    $rows[] = [$name, $actual, $expected];
};
$expect('env/the CacheService that runs is CACHE_SERVICE_FILE or this tree\'s',
    realpath((new ReflectionClass(CacheService::class))->getFileName()) === realpath(getenv('CACHE_SERVICE_FILE') ?: $root . '/src/modules/bookingfrontend/services/CacheService.php'), true);

$base = 'http://cache-test.invalid:3000/bookingfrontend/client/api/cache-reset?';
$service = new CacheService();
$expect('env/the service sees the test Next.js server (so the recorders are in the path)', $service->getServerUrl(), 'cache-test.invalid:3000');

// control: a method that already exists is recorded by the same instruments
RecordingHttp::$urls = [];
RecordingWebSocketHelper::$sent = [];
$seasonResult = $service->invalidateSeason(7);
$expect('control/invalidateSeason(7) is seen by the HTTP recorder', RecordingHttp::$urls, [$base . 'tag=search-data']);
$expect('control/invalidateSeason(7) is seen by the WebSocket recorder', RecordingWebSocketHelper::$sent, [[['searchData'], ['building_seasons'], ['building_seasons', '7']]]);
$expect('control/invalidateSeason(7) reports success', $seasonResult, true);

// under test
RecordingHttp::$urls = [];
RecordingWebSocketHelper::$sent = [];
$result = $service->invalidateHospitality();
$expect('hospitality/one request to Next.js, revalidating the tags search-data and resources', RecordingHttp::$urls, [$base . 'tag=search-data&tag=resources']);
$expect('hospitality/one broadcast: the queries searchData, resource and buildingResources (key prefixes, so every id under them)',
    RecordingWebSocketHelper::$sent, [[['searchData'], ['resource'], ['buildingResources']]]);
$expect('hospitality/it reports success', $result, true);

$failures = 0;
foreach ($rows as [$name, $actual, $expected]) {
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL [{$name}] got " . json_encode($actual) . ' want ' . json_encode($expected) . "\n";
    }
}
$total = count($rows);
if ($failures > 0) {
    echo "RED: {$failures} of {$total} rows failed\n";
    exit(1);
}
echo "GREEN: all {$total} rows pass (the real CacheService, recorded HTTP and WebSocket)\n";
exit(0);
