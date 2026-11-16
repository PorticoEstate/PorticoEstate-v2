<?php

/**
 * The read-only `tax_percent` on a stored application order line (task 39791, GH #1423): what the Min side
 * price summary labels «Mva x %» from.
 *
 *   php tests/incl-vat/run_order_line.php
 *
 * No database, no HTTP. It checks that the field is a READ-ONLY addition and that billing cannot have moved:
 *   1. the model: OrderLine and Order serialize `tax_percent` as 25 and as 0 (a real 0 % keeps its key), and a
 *      NULL (a code with no fm_ecomva row, which is NOT 0 %) is OMITTED, as the serializer omits every null: the
 *      client reads an absent field as «no percent» and never as 0;
 *   2. the query: fetchOrders() in bookingfrontend/ApplicationRepository.php is EXACTLY the old query plus one
 *      LEFT JOIN fm_ecomva on the id and one selected column. Take those two things out and what is left is the old
 *      statement, byte for byte (whitespace aside), so the FROM / JOIN / WHERE / ORDER BY, and with them the rows,
 *      are the ones billing has always seen; and the method has no INSERT / UPDATE / DELETE;
 *   3. the writer: savePurchaseOrderLine() names the stored columns explicitly and `tax_percent` is not one of
 *      them (it is read there only to work the tax out), so the field cannot reach bb_purchase_order_line;
 *   4. THE SECOND LANE: Min side reads the application over the WEBSOCKET, and the node server builds its order
 *      lines with its own SQL and its own OrderLineDto (src/WebSocket/node): both of its fetchOrders (the
 *      application's and the delivered application's) are the old statement plus the same join and column, and the
 *      DTO exposes `tax_percent`, so the field reaches the page the same way over both lanes.
 * The DB side (fm_ecomva.id is the primary key, so the join cannot change the number of lines; the old and the new
 * query return the same rows) was measured against the test database, not here.
 *
 * Environment (optional): HD_ROOT = a tree holding src/ (default: this repo), VENDOR_AUTOLOAD = composer autoload
 * (default: HD_ROOT/vendor/autoload.php; a worktree has none, point it at the main tree's).
 * Each check has a control that must NOT pass on the shape it exists to forbid.
 *
 * Exit code: 0 all pass, 1 any failure.
 */

date_default_timezone_set('Europe/Oslo');
error_reporting(E_ALL & ~E_DEPRECATED);

$root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
require_once getenv('VENDOR_AUTOLOAD') ?: $root . '/vendor/autoload.php';

foreach (['traits/SerializableTrait.php', 'modules/booking/models/OrderLine.php', 'modules/booking/models/Order.php'] as $file) {
    require_once $root . '/src/' . $file;
}

use App\modules\booking\models\Order;
use App\modules\booking\models\OrderLine;

$checks = 0;
$failures = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) {
        $failures[] = $detail === '' ? $name : "$name -- $detail";
    }
};

// ---- 1. the model --------------------------------------------------------------------------------------------
$line = static fn(array $extra): array => (new OrderLine(array_merge([
    'order_id' => 7, 'article_mapping_id' => 3, 'article_cat_id' => 2, 'quantity' => 1,
    'unit_price' => 100.0, 'amount' => 100.0, 'tax_code' => 9, 'tax' => 25.0, 'name' => 'Projektor',
], $extra)))->serialize();

foreach ([[25, 'a 25 % code'], [0, 'a 0 % code'], [12, 'a 12 % code']] as [$percent, $what]) {
    $out = $line(['tax_percent' => $percent]);
    $check("OrderLine serializes tax_percent for $what", array_key_exists('tax_percent', $out) && $out['tax_percent'] === $percent, json_encode($out['tax_percent'] ?? 'missing'));
}
$null = $line(['tax_percent' => null]);
$check('a NULL tax_percent is OMITTED (the key is absent), never turned into 0', !array_key_exists('tax_percent', $null), json_encode($null['tax_percent'] ?? 'absent'));
$absent = $line([]);
$check('a row without the column serializes no tax_percent either', !array_key_exists('tax_percent', $absent));
$zero = $line(['tax_percent' => 0]);
$check('control: a real 0 % is NOT omitted, so the instrument tells 0 and NULL apart', array_key_exists('tax_percent', $zero) && $zero['tax_percent'] === 0);
$check('the fields billing reads are untouched (tax_code, tax, amount, unit_price)',
    $line(['tax_percent' => 25])['tax_code'] === 9 && $line(['tax_percent' => 25])['tax'] === 25.0 && $line(['tax_percent' => 25])['amount'] === 100.0 && $line(['tax_percent' => 25])['unit_price'] === 100.0);

$order = new Order(['order_id' => 7, 'sum' => 0, 'lines' => []]);
$order->lines[] = new OrderLine(['order_id' => 7, 'amount' => 100.0, 'tax' => 25.0, 'tax_code' => 9, 'tax_percent' => 25]);
$order->lines[] = new OrderLine(['order_id' => 7, 'amount' => 40.0, 'tax' => 8.0, 'tax_code' => 77, 'tax_percent' => null]);
$serialized = $order->serialize();
$check('an Order carries tax_percent on its lines: 25 on the first, none (absent) on the NULL one',
    ($serialized['lines'][0]['tax_percent'] ?? 'missing') === 25 && !array_key_exists('tax_percent', $serialized['lines'][1] ?? ['tax_percent' => 0]),
    json_encode(array_map(fn($l) => $l['tax_percent'] ?? 'absent', $serialized['lines'] ?? [])));

// ---- 2. the query --------------------------------------------------------------------------------------------
$normalise = static fn(string $sql): string => trim(preg_replace('/\s+/', ' ', $sql));

$repository = file_get_contents($root . '/src/modules/bookingfrontend/repositories/ApplicationRepository.php');
$check('can read ApplicationRepository.php', is_string($repository) && $repository !== '');
$method = '';
if (preg_match('/public function fetchOrders\(int \$application_id\): array\s*\{(.*?)\n    \}\n/s', (string)$repository, $m)) {
    $method = $m[1];
}
$check('control: fetchOrders() was found', $method !== '' && str_contains($method, 'bb_purchase_order'));
$sql = '';
if (preg_match('/\$sql = "(.*?)";/s', $method, $m)) {
    $sql = $m[1];
}
$check('control: the statement was found', str_contains($sql, 'SELECT po.*, pol.*'), substr($sql, 0, 60));

$JOIN = 'LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code';
$COLUMN = 'ev.percent_ AS tax_percent';
$check('the VAT join is on the primary key: LEFT JOIN fm_ecomva ev ON ev.id = pol.tax_code', substr_count($sql, $JOIN) === 1);
$check('the column is fm_ecomva.percent_ AS tax_percent', substr_count($sql, $COLUMN) === 1);

// The statement as it was before this task, from git history (7360c1809), kept here as the oracle.
$old = 'SELECT po.*, pol.*, am.unit, am.article_cat_id, '
    . 'CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS name '
    . 'FROM bb_purchase_order po '
    . 'JOIN bb_purchase_order_line pol ON po.id = pol.order_id '
    . 'JOIN bb_article_mapping am ON pol.article_mapping_id = am.id '
    . 'LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2) '
    . 'LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1) '
    . 'WHERE po.cancelled IS NULL AND po.application_id = :application_id '
    . 'ORDER BY pol.id';
$without = $normalise(str_replace([$JOIN, ', ' . $COLUMN], ['', ''], $normalise($sql)));
$without = $normalise(str_replace('am.article_cat_id,', 'am.article_cat_id, ', $without));
$check('take the join and the column out and the statement is the OLD one (rows, WHERE, ORDER BY unchanged)', $without === $old, $without);
// Controls: the same comparison must go red on a changed statement.
$changedWhere = $normalise(str_replace('po.cancelled IS NULL', 'po.cancelled IS NOT NULL', $normalise($sql)));
$check('control: a changed WHERE would NOT equal the old statement', $normalise(str_replace([$JOIN, ', ' . $COLUMN], ['', ''], $changedWhere)) !== $old);
$check('control: a second extra join would NOT equal the old statement',
    $normalise(str_replace([$JOIN, ', ' . $COLUMN], ['', ''], $normalise($sql) . ' LEFT JOIN bb_building b ON b.id = 1')) !== $old);
$check('the method writes nothing (no INSERT / UPDATE / DELETE)', !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $method));
$check('control: the write pattern WOULD see an UPDATE', (bool)preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', 'UPDATE bb_purchase_order_line SET tax = 0'));

// ---- 3. the writer -------------------------------------------------------------------------------------------
$articles = file_get_contents($root . '/src/modules/bookingfrontend/repositories/ArticleRepository.php');
$insertColumns = '';
if (preg_match('/INSERT INTO bb_purchase_order_line \((.*?)\) VALUES/s', (string)$articles, $m)) {
    $insertColumns = $m[1];
}
$check('control: the purchase-line INSERT was found and names tax_code and tax', str_contains($insertColumns, 'tax_code') && str_contains($insertColumns, 'tax,'));
$check('tax_percent is NOT a column the purchase-line INSERT writes', !str_contains($insertColumns, 'tax_percent'), $insertColumns);
$check('control: the column check WOULD see tax_percent in a list that had it', str_contains('order_id, tax_code, tax_percent, tax', 'tax_percent'));

// ---- 4. the node lane ----------------------------------------------------------------------------------------
$nodeDir = $root . '/src/WebSocket/node/src/modules/application';
$dto = file_get_contents($nodeDir . '/dto/order-line.dto.ts');
$check('can read the node OrderLineDto', is_string($dto) && $dto !== '');
$check('the node OrderLineDto exposes tax_percent: number | null', (bool)preg_match('/@Expose\(\)\s*\n\s*tax_percent: number \| null;/', (string)$dto));
$check('control: the DTO pattern needs the @Expose (a bare property is recognised as NOT exposed)',
    !preg_match('/@Expose\(\)\s*\n\s*tax_percent: number \| null;/', "  tax: number;\n\n  tax_percent: number | null;\n"));

$nodeOld = [
    'application.service.ts' => 'SELECT po.*, pol.*, am.unit, am.article_cat_id, '
        . 'CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS name '
        . 'FROM bb_purchase_order po '
        . 'JOIN bb_purchase_order_line pol ON po.id = pol.order_id '
        . 'JOIN bb_article_mapping am ON pol.article_mapping_id = am.id '
        . 'LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2) '
        . 'LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1) '
        . 'WHERE po.cancelled IS NULL AND po.application_id = $1 '
        . 'ORDER BY pol.id',
    'delivered-application.service.ts' => 'SELECT po.*, pol.*, am.unit, '
        . 'CASE WHEN r.name IS NULL THEN s.name ELSE r.name END AS name '
        . 'FROM bb_purchase_order po '
        . 'JOIN bb_purchase_order_line pol ON po.id = pol.order_id '
        . 'JOIN bb_article_mapping am ON pol.article_mapping_id = am.id '
        . 'LEFT JOIN bb_service s ON (am.article_id = s.id AND am.article_cat_id = 2) '
        . 'LEFT JOIN bb_resource r ON (am.article_id = r.id AND am.article_cat_id = 1) '
        . 'WHERE po.cancelled IS NULL AND po.application_id = $1 '
        . 'ORDER BY pol.id',
];
foreach ($nodeOld as $file => $oldSql) {
    $source = (string)file_get_contents($nodeDir . '/' . $file);
    $nodeMethod = '';
    if (preg_match('/private async fetchOrders\(applicationId: number\): Promise<any\[\]> \{(.*?)\n  \}\n/s', $source, $m)) {
        $nodeMethod = $m[1];
    }
    $check("control: node $file fetchOrders() was found", $nodeMethod !== '' && str_contains($nodeMethod, 'bb_purchase_order'));
    $nodeSql = '';
    if (preg_match('/this\.db\.query\(\s*`(.*?)`/s', $nodeMethod, $m)) {
        $nodeSql = $m[1];
    }
    $check("node $file: the VAT join is on the primary key", substr_count($nodeSql, $JOIN) === 1);
    $check("node $file: the column is fm_ecomva.percent_ AS tax_percent", substr_count($nodeSql, $COLUMN) === 1);
    $nodeWithout = $normalise(str_replace([$JOIN, ', ' . $COLUMN], ['', ''], $normalise($nodeSql)));
    $check("node $file: take the join and the column out and the statement is the OLD one", $nodeWithout === $oldSql, $nodeWithout);
    $check("node $file: a changed WHERE would NOT equal the old statement (control)",
        $normalise(str_replace([$JOIN, ', ' . $COLUMN], ['', ''], str_replace('po.cancelled IS NULL', 'po.cancelled IS NOT NULL', $normalise($nodeSql)))) !== $oldSql);
    $check("node $file: fetchOrders writes nothing (no INSERT / UPDATE / DELETE)", !preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $nodeMethod));
}

if ($failures) {
    foreach ($failures as $f) {
        echo "FAIL $f\n";
    }
    echo 'Order-line harness: FAIL ' . count($failures) . " of $checks checks\n";
    exit(1);
}
echo "Order-line harness: PASS $checks checks on PHP " . PHP_VERSION . "\n";
