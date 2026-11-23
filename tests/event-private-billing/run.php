<?php

/**
 * Hermetic tests for GH #1492 (task 48050): the officer's event form and «Fakturainformasjon».
 *
 *   php tests/event-private-billing/run.php
 *
 * The defect: an event saved with Fakturainformasjon = «Organisasjonsnummer» (or «Fødselsnummer») and NO number was
 * stored with that type and an empty number, and the edit form showed «Organisasjonsnummer» for an event with nothing
 * stored, so an untouched save stored that shape again. The front page's REST lane shows a private event's name to
 * a viewer it takes for the owner, and a stored type with an empty number is taken for the owner of an anonymous
 * viewer (that rule is not part of this fix: the issue says not to touch how private events are hidden).
 *
 * Every link is the REAL code, nothing is retyped:
 *   - booking_customer_identifier (class.customer_identifier.inc.php): extract_form_data() and
 *     add_current_identifier_info(), as edit() runs them;
 *   - three blocks of booking_uievent::edit(), CUT VERBATIM from class.uievent.inc.php at test time and evaluated
 *     with the file's own `use` lines: the render preparation («$customer = array();» up to the first POST check),
 *     the save («if ($_POST['customer_organization_name'])» up to «Maker sure»), and the render application
 *     («if ($customer['customer_identifier_type'])» up to «pretty_timestamp»);
 *   - the form control: customer_identifier.xsl and the call to it CUT VERBATIM from event_edit.xsl, run by PHP's
 *     XSLTProcessor on the real phpgwapi_xmlhelper::toXML() output, read back as HTML (the option a browser shows);
 *   - the front page's view: the real bookingfrontend Event model and SerializableTrait, serialised with the
 *     context ScheduleEntityService passes for a viewer with no session (no ssn, no organisations). It is the
 *     oracle here, not code under change.
 * Stubs: the organisation register, get_org(), lang(), Translation (the private-event placeholder) and
 * booking_uicommon::humanize(). A marker that is missing or not unique stops the harness with a FAIL line.
 *
 * What it proves:
 *   C1  the oracle can fail both ways in this run: a public event and a private one stored with a type and an
 *       empty number show their REAL name; a private one with no type shows the placeholder.
 *   C2  the render can show something other than «Organisasjonsnummer»: a stored ssn is shown as «Fødselsnummer».
 *   M1a «Organisasjonsnummer» with no number, private, saved: no type is stored, and the front page hides the name.
 *   M1b the same for «Fødselsnummer».
 *   M1c an event ALREADY stored with that shape is healed by opening it and saving it untouched.
 *   M2a nothing stored, no organisation: the form shows «Ikke angitt», not «Organisasjonsnummer».
 *   M2b the same for a stored ''.
 *   M2c a stored «Organisasjonsnummer» with no number is shown as stored.
 *   M2d nothing stored, the event's organisation HAS a number: that number is still suggested (option B).
 *   M2e nothing stored, the event's organisation has NO number: «Ikke angitt».
 *   R1  a number is still stored with its type (orgnr and ssn), and the owner of that number still sees the name.
 *   R2  «Ikke angitt» stores no type.
 *   R3  the organisation branch (an organisation named on the form) is unchanged: type and number from the register.
 *
 * Red first: against a tree without the fix, e.g.
 *   git archive 6ca0ca454 src | tar -x -C /tmp/pb-base && HD_ROOT=/tmp/pb-base php tests/event-private-billing/run.php
 * M1a, M1b, M1c, M2a, M2b and M2e fail there; the others pass.
 *
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo); mutate.py points it at a copy carrying
 * one deliberate defect and expects this harness to go red. vendor/ (HTMLPurifier, for the real Sanitizer) is taken
 * from HD_ROOT when it has one, else from this repo; App\ classes always load from HD_ROOT.
 *
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, never a crash).
 */

namespace App\modules\phpgwapi\services {
    /** The private-event placeholder (@Default(t"bookingfrontend.private event")) without a database. */
    final class Translation
    {
        private static ?self $instance = null;

        public static function getInstance(): self
        {
            return self::$instance ??= new self();
        }

        public function translate($key, $args = [], $force = false, $app = '')
        {
            return "[lang:{$app}.{$key}]";
        }
    }
}

namespace {

    error_reporting(E_ALL & ~E_DEPRECATED);
    // A warning or notice from the code under test is a failure of the arm it happens in (an «Undefined array key»
    // on the officer's page is a regression even when the right option ends up selected). It is recorded, not thrown,
    // so the arm's own assertions still run and both show up.
    final class PbWarnings
    {
        /** @var list<string> */
        public static array $list = [];
    }
    set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0): bool {
        if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) {
            return true;
        }
        PbWarnings::$list[] = $message . ' @' . basename($file) . ':' . $line;
        return true;
    });

    $root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
    $repo = dirname(__DIR__, 2);

    // App\ classes from the tree under test, before composer (whose App\ points at this repo).
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
    require (is_file($root . '/vendor/autoload.php') ? $root : $repo) . '/vendor/autoload.php';

    const PB_PLACEHOLDER = '[lang:bookingfrontend.private event]';
    const PB_NAME = 'T1492 fixture event';

    function lang($key, ...$args)
    {
        return '[lang:' . $key . ']';
    }

    final class phpgw
    {
        public static function import_class($name): void
        {
        }
    }

    /** Only what booking_customer_identifier calls on it. */
    class booking_uicommon
    {
        public static function humanize($s)
        {
            return ucfirst(str_replace('_', ' ', (string)$s));
        }
    }

    /** The organisation register: read_single() by id, update() recorded. */
    final class PbOrganizations
    {
        /** @var array<int, array> */
        public array $rows = [];
        /** @var list<array> */
        public array $updates = [];

        public function read_single($id)
        {
            return $this->rows[(int)$id] ?? [];
        }

        public function update($organization)
        {
            $this->updates[] = $organization;
            return ['id' => $organization['id'] ?? null];
        }
    }

    final class PbSo
    {
        public function get_org($number)
        {
            return ['id' => null, 'name' => null];
        }
    }

    /** `$this` of the edit() blocks: only what they read. */
    final class PbUi
    {
        public PbOrganizations $organization_bo;
        public object $bo;

        public function __construct(PbOrganizations $organizations)
        {
            $this->organization_bo = $organizations;
            $this->bo = new class {
                public PbSo $so;

                public function __construct()
                {
                    $this->so = new PbSo();
                }
            };
        }
    }

    // --- bookkeeping -------------------------------------------------------------------------------
    $failures = [];
    $passed = 0;
    $armResult = [];
    $group = '-';
    $check = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$passed, &$armResult, &$group): void {
        $armResult[$group] ??= true;
        if ($ok) {
            $passed++;
            return;
        }
        $armResult[$group] = false;
        $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
    };
    $same = static function (string $name, mixed $got, mixed $want) use ($check): void {
        $check($name, $got === $want, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
    };
    $arm = static function (string $name, callable $body) use (&$failures, &$group, &$armResult): void {
        $group = $name;
        $armResult[$group] ??= true;
        PbWarnings::$list = [];
        try {
            $body();
        } catch (\Throwable $e) {
            $armResult[$group] = false;
            $failures[] = "{$name} threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        }
        foreach (array_unique(PbWarnings::$list) as $w) {
            $armResult[$group] = false;
            $failures[] = "{$name} warning: {$w}";
        }
    };
    $fatal = static function (string $why): never {
        echo 'FAIL harness cannot load the code under test — ', $why, "\n";
        echo "Event private billing harness: FAIL 1 of 1 checks\n";
        exit(1);
    };

    // --- the code under test, cut verbatim ------------------------------------------------------------
    $U = 'src/modules/booking/inc/class.uievent.inc.php';
    $CI = 'src/modules/booking/inc/class.customer_identifier.inc.php';
    $CIX = 'src/modules/booking/templates/base/customer_identifier.xsl';
    $EX = 'src/modules/booking/templates/base/event_edit.xsl';
    foreach ([$U, $CI, $CIX, $EX, 'src/helpers/Sanitizer.php', 'src/modules/phpgwapi/inc/class.xmlhelper.inc.php',
              'src/modules/bookingfrontend/models/Event.php', 'src/traits/SerializableTrait.php'] as $needed) {
        if (!is_file($root . '/' . $needed)) {
            $fatal($needed . ' is missing under ' . $root);
        }
    }
    require_once $root . '/src/helpers/Sanitizer.php';
    require_once $root . '/src/modules/phpgwapi/inc/class.xmlhelper.inc.php';
    require_once $root . '/' . $CI;

    $uiSource = (string)file_get_contents($root . '/' . $U);
    /** The text from $start up to (not including) the first $end after it; both must be where we think they are. */
    $cut = static function (string $start, string $end, string $what) use ($uiSource, $fatal): string {
        if (substr_count($uiSource, $start) !== 1) {
            $fatal("the start marker of the {$what} block («" . trim($start) . "») is missing or not unique in class.uievent.inc.php");
        }
        $from = strpos($uiSource, $start);
        $to = strpos($uiSource, $end, $from + strlen($start));
        if ($to === false) {
            $fatal("the end marker of the {$what} block («" . trim($end) . "») is missing after its start in class.uievent.inc.php");
        }
        return substr($uiSource, $from, $to - $from);
    };
    $editStart = strpos($uiSource, 'public function edit()');
    $prepare = $cut("\t\t\$customer = array();\n", "\t\tif (\$_SERVER['REQUEST_METHOD'] == 'POST')", 'render preparation');
    $save = $cut("if (\$_POST['customer_organization_name'])", "/**\n\t\t\t * Maker sure", 'save');
    // The guard of this block is the one line the fix changed («if ($customer[...])» became «if (!empty($customer[...]))»),
    // so it is found by either spelling; the base tree must stay testable for the red-first run.
    if (preg_match_all('/^\t\tif \((?:!empty\()?\$customer\[\'customer_identifier_type\'\]\)?\)\n/m', $uiSource, $applyStarts) !== 1) {
        $fatal('the start of the render application block («if ($customer[\'customer_identifier_type\'])») is missing or not unique in class.uievent.inc.php');
    }
    $apply = $cut($applyStarts[0][0], "\t\t\$event['from_'] = pretty_timestamp(", 'render application');
    foreach (['render preparation' => $prepare, 'save' => $save, 'render application' => $apply] as $what => $block) {
        $at = strpos($uiSource, $block);
        $next = strpos($uiSource, "\n\tpublic function ", $editStart + 1);
        if ($editStart === false || $at < $editStart || ($next !== false && $at > $next)) {
            $fatal("the {$what} block is not inside booking_uievent::edit()");
        }
    }
    foreach ([['render preparation', $prepare, "'organization_number'"], ['save', $save, "customer_identifier_type"],
              ['render application', $apply, "\$event['customer_identifier_type'] = \$customer['customer_identifier_type']"]] as [$what, $block, $must]) {
        if (!str_contains($block, $must)) {
            $fatal("the cut {$what} block does not hold «{$must}»: this is not that block");
        }
    }
    preg_match_all('/^use\s+[^;]+;\s*$/m', substr($uiSource, 0, (int)strpos($uiSource, 'class booking_uievent')), $useMatches);
    $uses = implode("\n", $useMatches[0]) . "\n";
    $runPrepare = eval($uses . "return function (array \$event) {\n" . $prepare . "\nreturn \$customer;\n};");
    $runSave = eval($uses . "return function (array \$event) {\n\$errors = array();\n" . $save . "\nreturn [\$event, \$errors];\n};");
    $runApply = eval($uses . "return function (array \$event, array \$customer) {\n" . $apply . "\nreturn \$event;\n};");

    // The form control: customer_identifier.xsl verbatim, called the way event_edit.xsl calls it.
    $callLine = '<xsl:copy-of select="phpgw:booking_customer_identifier(event, \'\')"/>';
    $editXsl = (string)file_get_contents($root . '/' . $EX);
    if (substr_count($editXsl, $callLine) !== 1) {
        $fatal('the call «' . $callLine . '» is missing or not unique in event_edit.xsl');
    }
    $controlXsl = (string)file_get_contents($root . '/' . $CIX);
    if (!str_contains($controlXsl, 'name="phpgw:booking_customer_identifier"')) {
        $fatal('customer_identifier.xsl does not define phpgw:booking_customer_identifier');
    }
    $sheetDoc = new \DOMDocument();
    // Namespaces and extension prefixes as class.xslttemplates.inc.php declares them.
    $sheetXml = '<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0"'
        . ' xmlns:phpgw="http://phpgroupware.org/functions" xmlns:func="http://exslt.org/functions"'
        . ' xmlns:php="http://php.net/xsl" extension-element-prefixes="func" exclude-result-prefixes="phpgw php">'
        . '<xsl:output method="html" encoding="UTF-8"/>' . $controlXsl
        . '<xsl:template match="/"><xsl:apply-templates select="PHPGW/data"/></xsl:template>'
        . '<xsl:template match="data"><form>' . $callLine . '</form></xsl:template></xsl:stylesheet>';
    if (!@$sheetDoc->loadXML($sheetXml)) {
        $fatal('the stylesheet around customer_identifier.xsl is not well-formed XML');
    }
    $sheet = new \XSLTProcessor();
    $sheet->registerPHPFunctions(['lang']);
    $sheet->importStylesheet($sheetDoc);

    $identifier = new \booking_customer_identifier();

    /**
     * What the officer sees for a stored event: the render blocks, add_current_identifier_info(), toXML, the control.
     * @return array{type:string,orgnr:string,ssn:string,selectedCount:int}
     */
    $render = static function (array $stored, PbOrganizations $orgs) use ($runPrepare, $runApply, $identifier, $sheet): array {
        $ui = new PbUi($orgs);
        $event = $stored;
        $customer = \Closure::bind($runPrepare, $ui, PbUi::class)($event);
        $event = \Closure::bind($runApply, $ui, PbUi::class)($event, $customer);
        $identifier->add_current_identifier_info($event);
        $doc = new \DOMDocument();
        $doc->loadXML(\phpgwapi_xmlhelper::toXML(['data' => ['event' => $event]], 'PHPGW'));
        $html = (string)$sheet->transformToXml($doc);
        $out = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $out->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $select = null;
        $inputs = [];
        foreach ($out->getElementsByTagName('select') as $s) {
            if ($s->getAttribute('name') === 'customer_identifier_type') {
                $select = $s;
            }
        }
        foreach ($out->getElementsByTagName('input') as $i) {
            $inputs[$i->getAttribute('name')] = $i->getAttribute('value');
        }
        if ($select === null) {
            throw new \RuntimeException('the rendered control has no customer_identifier_type select');
        }
        // What a browser shows: the first option marked selected, else the first option.
        $shown = null;
        $first = null;
        $selectedCount = 0;
        foreach ($select->getElementsByTagName('option') as $o) {
            $first ??= $o->getAttribute('value');
            if ($o->hasAttribute('selected')) {
                $selectedCount++;
                $shown ??= $o->getAttribute('value');
            }
        }
        return ['type' => $shown ?? (string)$first, 'orgnr' => $inputs['customer_organization_number'] ?? '(none)',
            'ssn' => $inputs['customer_ssn'] ?? '(none)', 'selectedCount' => $selectedCount];
    };

    /**
     * The officer saves: the posted billing fields through extract_form_data() and the save block.
     * @return array{0:array,1:array}
     */
    $saveForm = static function (array $stored, array $post, PbOrganizations $orgs) use ($runSave, $identifier): array {
        $_POST = $post;
        $_REQUEST = $post;
        $_GET = [];
        $event = $stored;
        if (array_key_exists('is_public', $post)) {
            $event['is_public'] = (int)$post['is_public'];
        }
        $identifier->extract_form_data($event);
        return \Closure::bind($runSave, new PbUi($orgs), PbUi::class)($event);
    };

    /** The name the front page's REST lane gives a viewer with no session (ScheduleEntityService's context). */
    $frontName = static function (array $row, $orgContext = null): mixed {
        $e = new \App\modules\bookingfrontend\models\Event($row + ['id' => 1492, 'name' => PB_NAME, 'organizer' => 'Org']);
        $out = $e->serialize(['user_ssn' => null, 'organization_number' => $orgContext]);
        return $out['name'] ?? null;
    };

    $base = ['id' => 1492, 'name' => PB_NAME, 'is_public' => 1, 'customer_identifier_type' => null,
        'customer_organization_number' => null, 'customer_ssn' => null, 'customer_internal' => 0,
        'customer_organization_id' => null, 'customer_organization_name' => null];
    $noOrgs = new PbOrganizations();
    $orgs = new PbOrganizations();
    // 91: a number in the register but none as an invoice customer (catches a guard on the wrong column).
    $orgs->rows[91] = ['id' => 91, 'name' => 'Org with number', 'organization_number' => '979654448',
        'customer_organization_number' => '', 'customer_identifier_type' => null, 'customer_internal' => 0];
    // 92: no number at all.
    $orgs->rows[92] = ['id' => 92, 'name' => 'Org without number', 'organization_number' => '',
        'customer_organization_number' => '', 'customer_identifier_type' => null, 'customer_internal' => 0];
    // 93: an invoice customer with its number (the organisation branch of the save).
    $orgs->rows[93] = ['id' => 93, 'name' => 'Invoice customer', 'organization_number' => '994239929',
        'customer_organization_number' => '994239929', 'customer_identifier_type' => 'organization_number', 'customer_internal' => 0];
    $privatePost = ['is_public' => '0', 'customer_organization_name' => '', 'customer_organization_id' => '',
        'customer_internal' => '0', 'customer_ssn' => '', 'customer_organization_number' => ''];

    // --- C: the instruments can fail ---------------------------------------------------------------
    $arm('C1', function () use ($same, $frontName, $base) {
        $same('C1 public, nothing stored: the real name', $frontName($base), PB_NAME);
        $same('C1 private, «Organisasjonsnummer» + empty number stored: the real name (the leak this oracle must see)',
            $frontName(['is_public' => 0, 'customer_identifier_type' => 'organization_number', 'customer_organization_number' => ''] + $base), PB_NAME);
        $same('C1 private, ssn + empty ssn stored: the real name',
            $frontName(['is_public' => 0, 'customer_identifier_type' => 'ssn', 'customer_ssn' => ''] + $base), PB_NAME);
        $same('C1 private, nothing stored: the placeholder', $frontName(['is_public' => 0] + $base), PB_PLACEHOLDER);
    });
    $arm('C2', function () use ($same, $render, $base, $noOrgs) {
        $r = $render(['customer_identifier_type' => 'ssn', 'customer_ssn' => '01010112345'] + $base, $noOrgs);
        $same('C2 stored ssn: shown as ssn', $r['type'], 'ssn');
        $same('C2 stored ssn: the number is in the field', $r['ssn'], '01010112345');
    });

    // --- M1: the save -------------------------------------------------------------------------------
    $arm('M1a', function () use ($same, $saveForm, $frontName, $base, $noOrgs, $privatePost) {
        [$e, $errors] = $saveForm($base, ['customer_identifier_type' => 'organization_number'] + $privatePost, $noOrgs);
        $same('M1a no error', $errors, []);
        $same('M1a is_public stored 0', $e['is_public'], 0);
        $same('M1a no type stored', $e['customer_identifier_type'] ?? null, null);
        $same('M1a front page: the placeholder', $frontName($e), PB_PLACEHOLDER);
    });
    $arm('M1b', function () use ($same, $saveForm, $frontName, $base, $noOrgs, $privatePost) {
        [$e, $errors] = $saveForm($base, ['customer_identifier_type' => 'ssn'] + $privatePost, $noOrgs);
        $same('M1b no error', $errors, []);
        $same('M1b no type stored', $e['customer_identifier_type'] ?? null, null);
        $same('M1b front page: the placeholder', $frontName($e), PB_PLACEHOLDER);
    });
    $arm('M1c', function () use ($same, $render, $saveForm, $frontName, $base, $noOrgs, $privatePost) {
        $stored = ['is_public' => 0, 'customer_identifier_type' => 'organization_number', 'customer_organization_number' => ''] + $base;
        $shown = $render($stored, $noOrgs);
        [$e] = $saveForm($stored, ['customer_identifier_type' => $shown['type'], 'customer_organization_number' => $shown['orgnr'],
            'customer_ssn' => $shown['ssn']] + $privatePost, $noOrgs);
        $same('M1c re-saved untouched: no type stored', $e['customer_identifier_type'] ?? null, null);
        $same('M1c front page: the placeholder', $frontName($e), PB_PLACEHOLDER);
    });

    // --- M2: the render ----------------------------------------------------------------------------
    $arm('M2a', function () use ($same, $render, $base, $noOrgs) {
        $r = $render($base, $noOrgs);
        $same('M2a nothing stored, no organisation: «Ikke angitt»', $r['type'], '');
    });
    $arm('M2b', function () use ($same, $render, $base, $noOrgs) {
        $r = $render(['customer_identifier_type' => ''] + $base, $noOrgs);
        $same("M2b stored '': «Ikke angitt»", $r['type'], '');
    });
    $arm('M2c', function () use ($same, $render, $base, $noOrgs) {
        $r = $render(['customer_identifier_type' => 'organization_number', 'customer_organization_number' => ''] + $base, $noOrgs);
        $same('M2c stored «Organisasjonsnummer» with no number: shown as stored', $r['type'], 'organization_number');
        $same('M2c exactly one option selected', $r['selectedCount'], 1);
    });
    $arm('M2d', function () use ($same, $render, $base, $orgs) {
        $r = $render(['customer_organization_id' => 91, 'customer_organization_name' => 'Org with number'] + $base, $orgs);
        $same("M2d nothing stored, the organisation has a number: «Organisasjonsnummer» suggested", $r['type'], 'organization_number');
        $same('M2d with its number', $r['orgnr'], '979654448');
    });
    $arm('M2e', function () use ($same, $render, $base, $orgs) {
        $r = $render(['customer_organization_id' => 92, 'customer_organization_name' => 'Org without number'] + $base, $orgs);
        $same('M2e nothing stored, the organisation has no number: «Ikke angitt»', $r['type'], '');
    });

    // --- R: what must not move -------------------------------------------------------------------
    $arm('R1', function () use ($same, $saveForm, $frontName, $base, $noOrgs, $privatePost) {
        [$e, $errors] = $saveForm($base, ['customer_identifier_type' => 'organization_number', 'customer_organization_number' => '999999999'] + $privatePost, $noOrgs);
        $same('R1 orgnr: no error', $errors, []);
        $same('R1 orgnr: type stored', $e['customer_identifier_type'], 'organization_number');
        $same('R1 orgnr: number stored', $e['customer_organization_number'], '999999999');
        $same('R1 orgnr: the front page hides it from a viewer with no session', $frontName($e), PB_PLACEHOLDER);
        $same('R1 orgnr: the owner of that number still sees the name', $frontName($e, ['999999999']), PB_NAME);
        [$s] = $saveForm($base, ['customer_identifier_type' => 'ssn', 'customer_ssn' => '01010112345'] + $privatePost, $noOrgs);
        $same('R1 ssn: type stored', $s['customer_identifier_type'], 'ssn');
        $same('R1 ssn: number stored', $s['customer_ssn'], '01010112345');
    });
    $arm('R2', function () use ($same, $saveForm, $frontName, $base, $noOrgs, $privatePost) {
        [$e] = $saveForm($base, ['customer_identifier_type' => ''] + $privatePost, $noOrgs);
        $same('R2 «Ikke angitt»: no type stored', $e['customer_identifier_type'] ?? null, null);
        $same('R2 front page: the placeholder', $frontName($e), PB_PLACEHOLDER);
    });
    $arm('R3', function () use ($same, $saveForm, $base, $orgs, $privatePost) {
        $orgs->updates = [];
        [$e, $errors] = $saveForm($base, ['customer_identifier_type' => '', 'customer_organization_name' => 'Invoice customer',
            'customer_organization_id' => '93'] + $privatePost, $orgs);
        $same('R3 organisation branch: no error', $errors, []);
        $same('R3 organisation branch: type from the register', $e['customer_identifier_type'], 'organization_number');
        $same('R3 organisation branch: number from the register', $e['customer_organization_number'], '994239929');
    });

    // --- report ----------------------------------------------------------------------------------
    foreach ($armResult as $name => $ok) {
        printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
    }
    foreach ($failures as $f) {
        echo 'FAIL ', $f, "\n";
    }
    $total = $passed + count($failures);
    if ($failures) {
        printf("Event private billing harness: FAIL %d of %d checks on PHP %s\n", count($failures), $total, PHP_VERSION);
        exit(1);
    }
    printf("Event private billing harness: PASS %d checks on PHP %s\n", $total, PHP_VERSION);
    exit(0);
}
