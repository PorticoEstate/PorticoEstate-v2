<?php

/**
 * Hermetic tests for how the officer's event page prints «History and comments» (declaration 36219, task 36226):
 *
 *   booking_soevent::get_ordered_comments()  ->  the comment block in booking_uievent::edit()
 *     ->  phpgwapi_xmlhelper::toXML()  ->  the history block of booking/templates/base/event_edit.xsl
 *
 *   php tests/event-history-xss/run.php
 *
 * Every link of that chain is the REAL code. Nothing is retyped:
 *   - the feeder is the real get_ordered_comments() on the real App\Database\Db::f() (only query()/next_record()
 *     are replaced, so the rows come from the fixtures instead of PostgreSQL),
 *   - the edit() block is CUT VERBATIM from class.uievent.inc.php at test time (from the external_site_address
 *     line to the «End hack» comment) and evaluated with the file's own `use` lines,
 *   - the XSL is CUT VERBATIM from event_edit.xsl at test time (the for-each over comments[author], nesting
 *     respected) and run by PHP's XSLTProcessor under the output method the framework uses,
 *   - toXML is the real phpgwapi_xmlhelper::toXML().
 * If a marker is missing or not unique, the harness stops with a FAIL line instead of testing something else.
 *
 * What it proves (the groups of the table it prints):
 *   C   the detector's own controls: it parses the OUTPUT as HTML and must call a payload LIVE and escaped text
 *       inert, in this very run. A detector that cannot fail makes every «inert» below worthless.
 *   R   reachability: through an embedded copy of the OLD render (d-o-e on the raw comment) every payload class
 *       is LIVE at raw, 1x, 2x and 3x and inert at 4x. Without this, an «inert after» arm could be green because
 *       the payload never worked.
 *   P   payload classes x encodings raw/1x/2x/3x/4x through the real path: no active element, no on* attribute,
 *       no javascript:/data: URL, nothing but div/label/span/br/a(href, rel) in the output, and the VISIBLE text
 *       is the expected text (dropped elements take their text with them; at 4x the old page showed the payload
 *       as literal text and so must this one).
 *   L   legitimate content survives: <br>, https links with & in the query (stored raw and encoded once), the
 *       host rewrite, newline, æøå, apostrophe, phone text, empty, formatting tags (text kept), links that are
 *       not http(s) (text kept, no link).
 *   S   structure: edit() leaves no `comment` on any row and none reaches the XML, the history block holds no
 *       disable-output-escaping and never selects `comment`, and event_edit.xsl holds no other d-o-e than the
 *       system-built tab strip.
 *   H   the helper itself (App\modules\booking\helpers\CommentParts): only the closed vocabulary comes out, for
 *       any input, including invalid UTF-8 and NUL.
 *   X   deep nesting and a large comment finish, and stay inert.
 *   A   the apostrophe (commit b): booking_soevent::add_single_comment() binds the comment exactly as given. The
 *       real Db::insert() and Db::db_addslashes() run against a recording PDO whose quote() doubles an apostrophe
 *       like PostgreSQL's does, so «It's» is bound as «It's» and read back by the real Db::f() as «It's», not «It''s».
 *   F   U+FFFE and U+FFFF (the follow-up, task 36276): the page loads the XML that toXML() gives with the framework's
 *       own steps (class.xslttemplates.inc.php:436-438: strip the control characters, then DOMDocument::loadXML; a
 *       failed load is the «Systemfeil» page). A reference to either character, stored at 0 to 6 levels of entity
 *       encoding, as decimal or hexadecimal with leading zeros, in text, in a link text and in an href, split by a
 *       comment or an element, or around another one that would rebuild it if it were removed, must not fail that
 *       load; the characters next to them (U+FFFD, U+1FFFE, ...) must stay. Task 36448 adds the spellings only strtol
 *       reads («&#x0xFFFE;»: a «0x» after the «x»), a sweep of 6,240 spellings sorted by the decoder itself, and the
 *       two-decode shape («&amp;#xFFFE;», which only toXML's single decode keeps from being the character). Task
 *       36487 adds the characters themselves when the HTML step makes them after the strip has run (a «<meta
 *       charset="utf-7">» and «+//4-» or «+//8-» in an href, stored at 0 to 6 levels, read through the real
 *       Db::stripslashes) and, for the v of a part, which libxml2 2.9 never lets them reach, parts built by hand.
 *   K   (HD_DB_HEADER set) the corpus: every stored bb_event_comment row of the local database through the real
 *       path; counts only, no content is printed. Visible text and links are unchanged against the old page's
 *       view of the same row (script/style text excluded), and nothing is LIVE afterwards.
 *
 * Red first: run it against a tree that does not have the fix, e.g.
 *   git archive 1116d6db2 src | tar -x -C /tmp/hd-base && HD_ROOT=/tmp/hd-base php tests/event-history-xss/run.php
 * (the F arm against d806848ff, the tree before the follow-up).
 *
 * Environment (all optional): HD_ROOT = tree holding src/ (default: this repo); mutate.py points it at a copy
 * carrying one deliberate defect and expects this harness to go red. HD_DB_HEADER = path to config/header.inc.php
 * (switches the K arm on; HD_DB_HOST / HD_DB_PORT override the host the header names, e.g. 127.0.0.1 / 5435 from
 * the Mac). K only SELECTs.
 *
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, never a crash).
 */

namespace {

    date_default_timezone_set('Europe/Oslo');
    error_reporting(E_ALL & ~E_DEPRECATED);

    $root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);

    // The real classes load from the tree under test. The helper is only ever found if production code names it.
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });

    const EVENT_ID = 84227;
    const HELPER = 'App\\modules\\booking\\helpers\\CommentParts';
    const HD_INTERNAL = 'https://internal.test';
    const HD_EXTERNAL = 'https://external.test';

    /** Elements that run or load something. The detector flags every one of them in the OUTPUT. */
    const HD_ACTIVE = ['script', 'img', 'svg', 'iframe', 'object', 'embed', 'style', 'link', 'meta', 'form', 'input',
        'math', 'base', 'video', 'audio', 'source', 'frame', 'frameset', 'applet', 'button', 'select', 'template'];

    // --- stubs: only what the real soevent / uievent code inherits ---------------------------------------
    final class phpgw
    {
        public static function import_class($name): void
        {
        }
    }

    class booking_socommon
    {
        protected $db;
        protected $userSettings = [];

        protected function modify_by_timezone(&$value, $reverse = false)
        {
        }
    }

    /** `$this` of the edit() block: only the setting it reads. */
    final class HdUi
    {
        public array $serverSettings = ['webserver_url' => HD_INTERNAL];
    }

    /**
     * The real Db, except that query() and next_record() serve fixtures. f() (the decode under test) is inherited.
     */
    final class HdRowDb extends \App\Database\Db
    {
        /** @var list<array> */
        public array $rows = [];
        /** @var list<string> */
        public array $queries = [];
        private int $at = 0;

        public function __construct()
        {
        }

        public function query($sql, $line = '', $file = '', $exec = false, $_fetch_single = false)
        {
            $this->queries[] = (string)$sql;
            $this->at = 0;
            return true;
        }

        public function next_record()
        {
            if ($this->at < count($this->rows)) {
                $this->Record = $this->rows[$this->at++];
                return true;
            }
            return false;
        }
    }

    /** A statement that records what was bound to it (Db::insert() binds by reference, then executes). */
    final class HdFakeStatement
    {
        /** @var array<int,array{0:mixed,1:int}> slot => [value, type] */
        public array $bound = [];

        public function __construct(public string $sql)
        {
        }

        public function bindParam($field, &$value, $type = \PDO::PARAM_STR): bool
        {
            $this->bound[$field] = [$value, $type];
            return true;
        }

        public function execute(): bool
        {
            return true;
        }
    }

    /** The PDO as far as Db::insert() and Db::db_addslashes() can see it. quote() is PostgreSQL's: a quote doubles. */
    final class HdFakePdo
    {
        /** @var list<HdFakeStatement> */
        public array $statements = [];

        public function prepare($sql): HdFakeStatement
        {
            return $this->statements[] = new HdFakeStatement((string)$sql);
        }

        public function quote($string): string
        {
            return "'" . str_replace("'", "''", (string)$string) . "'";
        }
    }

    /** The template's php:function('pretty_timestamp', time); the time is not under test. */
    function pretty_timestamp($t)
    {
        return is_array($t) ? (string)($t[0]->textContent ?? '') : (string)$t;
    }

    // --- bookkeeping ---------------------------------------------------------------------------------
    $failures = [];
    $passed = 0;
    $stats = [];
    $group = '-';
    $check = static function (string $name, bool $ok, string $detail = '') use (&$failures, &$passed, &$stats, &$group): void {
        $stats[$group] ??= [0, 0];
        if ($ok) {
            $passed++;
            $stats[$group][0]++;
            return;
        }
        $stats[$group][1]++;
        $failures[] = $name . ($detail !== '' ? " — {$detail}" : '');
    };
    $same = static function (string $name, mixed $got, mixed $want) use ($check): void {
        $check($name, $got === $want, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
    };
    $arm = static function (string $name, callable $body) use (&$failures, &$group, &$stats): void {
        $group = $name;
        $stats[$group] ??= [0, 0];
        try {
            $body();
        } catch (\Throwable $e) {
            $stats[$group][1]++;
            $failures[] = "{$name} threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        }
    };
    $fatal = static function (string $why): never {
        echo 'FAIL harness cannot load the code under test — ', $why, "\n";
        echo "Event history XSS harness: FAIL 1 of 1 checks\n";
        exit(1);
    };

    // --- the code under test, cut verbatim ----------------------------------------------------------
    foreach (['src/Database/Db.php', 'src/modules/phpgwapi/inc/class.xmlhelper.inc.php',
              'src/modules/booking/inc/class.soevent.inc.php', 'src/modules/booking/inc/class.uievent.inc.php',
              'src/modules/booking/templates/base/event_edit.xsl'] as $needed) {
        if (!is_file($root . '/' . $needed)) {
            $fatal($needed . ' is missing under ' . $root);
        }
    }
    require_once $root . '/src/modules/phpgwapi/inc/class.xmlhelper.inc.php';
    require_once $root . '/src/modules/booking/inc/class.soevent.inc.php';

    // the edit() block
    $uiSource = (string)file_get_contents($root . '/src/modules/booking/inc/class.uievent.inc.php');
    $startMark = "\$external_site_address = !empty(\$config['external_site_address'])";
    $endMark = "\t\t/**\n\t\t * End hack";
    if (substr_count($uiSource, $startMark) !== 1 || substr_count($uiSource, $endMark) !== 1) {
        $fatal('the start («external_site_address») or end («End hack») marker of the edit() comment block is missing or not unique in class.uievent.inc.php');
    }
    $from = strpos($uiSource, $startMark);
    $to = strpos($uiSource, $endMark);
    if ($to < $from) {
        $fatal('the edit() block markers are in the wrong order');
    }
    $blockSource = substr($uiSource, $from, $to - $from);
    if (!str_contains($blockSource, 'foreach ($comments as')) {
        $fatal('the cut edit() block does not loop over $comments');
    }
    preg_match_all('/^use\s+[^;]+;\s*$/m', substr($uiSource, 0, (int)strpos($uiSource, 'class booking_uievent')), $useMatches);
    $runBlock = eval(implode("\n", $useMatches[0]) . "\nreturn function (array \$comments, array \$config) {\n"
        . $blockSource . "\nreturn \$comments;\n};");

    // the history block of the template
    $xslSource = (string)file_get_contents($root . '/src/modules/booking/templates/base/event_edit.xsl');
    $open = '<xsl:for-each select="comments[author]">';
    if (substr_count($xslSource, $open) !== 1) {
        $fatal('the history block («' . $open . '») is missing or not unique in event_edit.xsl');
    }
    $fragStart = strpos($xslSource, $open);
    $depth = 0;
    $fragEnd = null;
    preg_match_all('#<xsl:for-each\b|</xsl:for-each>#', $xslSource, $tags, PREG_OFFSET_CAPTURE, $fragStart);
    foreach ($tags[0] as [$tag, $at]) {
        $depth += $tag === '</xsl:for-each>' ? -1 : 1;
        if ($depth === 0) {
            $fragEnd = $at + strlen($tag);
            break;
        }
    }
    if ($fragEnd === null) {
        $fatal('the history block of event_edit.xsl is not closed');
    }
    $fragment = substr($xslSource, $fragStart, $fragEnd - $fragStart);
    foreach (['<xsl:value-of select="author"', 'pretty_timestamp'] as $mustHave) {
        if (!str_contains($fragment, $mustHave)) {
            $fatal("the cut history block does not hold «{$mustHave}»: this is not the history block");
        }
    }

    $sheet = static function (string $fragmentXsl): \XSLTProcessor {
        $doc = new \DOMDocument();
        $xml = '<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:php="http://php.net/xsl">'
            . '<xsl:output method="html" encoding="UTF-8" indent="yes"/>'
            . '<xsl:template match="/"><xsl:apply-templates select="PHPGW/data"/></xsl:template>'
            . '<xsl:template match="data">' . $fragmentXsl . '</xsl:template></xsl:stylesheet>';
        if (!@$doc->loadXML($xml)) {
            throw new \RuntimeException('the stylesheet around the cut history block is not well-formed XML');
        }
        $p = new \XSLTProcessor();
        $p->registerPHPFunctions(['pretty_timestamp']);
        $p->importStylesheet($doc);
        return $p;
    };
    $realSheet = $sheet($fragment);
    // The OLD render, embedded: the raw comment printed with disable-output-escaping. It is the reference that
    // proves each payload class is reachable; it does not depend on what the tree under test looks like.
    $oldSheet = $sheet('<xsl:for-each select="comments[author]"><div class="pure-control-group"><label>'
        . '<xsl:value-of select="php:function(\'pretty_timestamp\', time)"/>: <xsl:value-of select="author"/></label>'
        . '<span><xsl:value-of select="comment" disable-output-escaping="yes"/></span></div></xsl:for-each>');

    // --- the output side: parse it as HTML, never grep it ----------------------------------------------
    $dom = static function (string $html): \DOMDocument {
        $d = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $d->loadHTML('<?xml encoding="UTF-8"><body>' . str_replace("\0", '', $html) . '</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $d;
    };

    /** The reason the OUTPUT would run something in a browser, or null. */
    $live = static function (string $html) use ($dom): ?string {
        foreach ($dom($html)->getElementsByTagName('*') as $el) {
            $name = strtolower($el->nodeName);
            if (in_array($name, HD_ACTIVE, true)) {
                return "element <{$name}>";
            }
            foreach ($el->attributes as $at) {
                $an = strtolower($at->nodeName);
                $av = strtolower((string)preg_replace('/[\x00-\x20\x7f]+/', '', $at->nodeValue));
                if (str_starts_with($an, 'on')) {
                    return "attribute {$an}";
                }
                if ($an === 'srcdoc') {
                    return 'attribute srcdoc';
                }
                if (in_array($an, ['href', 'src', 'action', 'formaction', 'xlink:href', 'data', 'poster', 'background', 'codebase', 'ping'], true)
                    && preg_match('/^(javascript|data|vbscript|livescript):/', $av, $m)) {
                    return "{$an}={$m[1]}:";
                }
                if ($an === 'style' && preg_match('/javascript:|expression\(|behavior:|-moz-binding/', $av)) {
                    return 'attribute style with a script';
                }
            }
        }
        return null;
    };

    /** The closed vocabulary of the history block's output, or the first violation. */
    $closed = static function (string $html) use ($dom): ?string {
        foreach ($dom($html)->getElementsByTagName('*') as $el) {
            $name = strtolower($el->nodeName);
            if (!in_array($name, ['html', 'body', 'div', 'label', 'span', 'br', 'a'], true)) {
                return "element <{$name}> is outside div/label/span/br/a";
            }
            foreach ($el->attributes as $at) {
                $an = strtolower($at->nodeName);
                if ($name === 'div' && $an === 'class' && $at->nodeValue === 'pure-control-group') {
                    continue;
                }
                if ($name === 'a' && $an === 'href') {
                    if (!preg_match('#^https?://[^\s"<>]+$#i', $at->nodeValue)) {
                        return 'a href is not a plain http(s) URL';
                    }
                    continue;
                }
                if ($name === 'a' && $an === 'rel') {
                    if ($at->nodeValue !== 'noopener noreferrer') {
                        return 'a rel is not «noopener noreferrer»';
                    }
                    continue;
                }
                return "<{$name}> carries the attribute {$an}";
            }
            if ($name === 'a' && (!$el->hasAttribute('rel') || !$el->hasAttribute('href'))) {
                return 'an <a> without href and rel';
            }
        }
        return null;
    };

    $collapse = static fn(string $s): string => trim((string)(preg_replace('/\s+/u', ' ', $s) ?? preg_replace('/\s+/', ' ', $s)));
    /** span content as text: br = «⏎», a = «⟦text→href⟧», everything else flattened. */
    $canon = static function (\DOMNode $n) use (&$canon, $collapse): string {
        $out = '';
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMText) {
                $out .= $c->nodeValue;
            } elseif ($c instanceof \DOMElement) {
                $name = strtolower($c->nodeName);
                if ($name === 'br') {
                    $out .= '⏎';
                } elseif ($name === 'a') {
                    $out .= '⟦' . $collapse($canon($c)) . '→' . $c->getAttribute('href') . '⟧';
                } else {
                    $out .= $canon($c);
                }
            }
        }
        return $out;
    };

    /**
     * How the page loads the XML that toXML() gives (class.xslttemplates.inc.php:436-438): the same strip, then
     * DOMDocument::loadXML. When the load fails the document is empty, the stylesheet gives nothing and the page shows
     * «Systemfeil», so a failed load throws here. (Not SimpleXMLElement: it also refuses what the strip removes.)
     */
    $load = static function (string $xml): \DOMDocument {
        $doc = new \DOMDocument();
        $stripped = mb_ereg_replace('[\x00-\x09\x0B-\x0C\x0E-\x1F\x7F]', '', $xml); // remove unsupported characters but keep line breaks
        $prev = libxml_use_internal_errors(true);
        try {
            if (!is_string($stripped) || !$doc->loadXML($stripped)) {
                throw new \RuntimeException('the page would show «Systemfeil»: loadXML failed: ' . trim((string)(libxml_get_errors()[0]->message ?? 'mb_ereg_replace failed')));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        return $doc;
    };

    /**
     * Rows (stored strings) through the real feeder, the verbatim edit() block, the real toXML, the page's load and a sheet.
     *
     * @param list<array{comment:string,author?:string}> $rows
     * @return array{pre:array,post:array,xml:string,html:string,canon:list<string>,spans:list<string>}
     */
    $path = static function (array $rows, ?\XSLTProcessor $processor = null, array $config = [], bool $runBlockToo = true) use ($runBlock, $realSheet, $dom, $canon, $collapse, $load): array {
        $db = new HdRowDb();
        (new \ReflectionProperty(\App\Database\Db::class, 'resultSet'))->setValue($db, true);
        $db->rows = array_map(static fn(array $r) => [
            'time' => '2026-01-02 03:04', 'author' => $r['author'] ?? 'A', 'comment' => $r['comment'], 'type' => 'comment',
        ], $rows);
        $so = (new \ReflectionClass('booking_soevent'))->newInstanceWithoutConstructor();
        (new \ReflectionProperty('booking_socommon', 'db'))->setValue($so, $db);
        $pre = $so->get_ordered_comments(EVENT_ID);
        $post = $runBlockToo ? $runBlock->call(new HdUi(), $pre, $config) : $pre;
        $xml = \phpgwapi_xmlhelper::toXML(['data' => ['comments' => $post]], 'PHPGW');
        $tree = $load($xml);
        $html = (string)($processor ?? $realSheet)->transformToXml($tree);
        $canons = [];
        $spans = [];
        foreach ($dom($html)->getElementsByTagName('div') as $div) {
            foreach ($div->childNodes as $c) {
                if ($c instanceof \DOMElement && strtolower($c->nodeName) === 'span') {
                    $canons[] = $collapse($canon($c));
                    $spans[] = $c->textContent;
                }
            }
        }
        return ['pre' => $pre, 'post' => $post, 'xml' => $xml, 'html' => $html, 'canon' => $canons, 'spans' => $spans, 'db' => $db];
    };
    /** One stored string through the real path (and the OLD reference when $old). */
    $one = static fn(string $stored, array $config = [], bool $old = false) => $path([['comment' => $stored]], $old ? $oldSheet : null, $config, !$old);

    $E = static function (string $s, int $n): string {
        for ($i = 0; $i < $n; $i++) {
            $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        }
        return $s;
    };

    // --- fixtures -------------------------------------------------------------------------------------
    // [name, raw payload, expected visible text at raw/1x/2x/3x (null: not asserted: the parser decides), reachable under the old render]
    // «reachable» false: libxml2 (this detector's parser) and a browser read the payload differently, or the old page
    // does not render it at all (a control character makes toXML's XML unparseable). The new path is still asserted
    // inert and closed for every one of them; only the proof that the OLD page ran it is not claimed.
    $payloads = [
        ['img-onerror', 'x<img src=x onerror=alert(1)>y', 'xy', true],
        ['img-uppercase', '<IMG SRC=x ONERROR=alert(1)>t', 't', true],
        ['img-alt-breakout', '<img src="x" alt=\'y" onerror="alert(1)\'>t', 't', true],
        ['script', 'x<script>alert(1)</script>y', 'xy', true],
        ['script-mixedcase', '<ScRiPt>alert(1)</sCrIpT>t', 't', true],
        ['style', 'x<style>@import url(javascript:alert(1));</style>y', 'xy', true],
        ['svg-onload', 'x<svg onload=alert(1)>t</svg>y', 'xy', true],
        ['svg-script', '<svg><script>alert(1)</script></svg>t', 't', true],
        ['iframe-srcdoc', '<iframe srcdoc="<script>alert(1)</script>"></iframe>t', 't', true],
        ['object-embed', '<object data="javascript:alert(1)"></object><embed src="javascript:alert(1)"></embed>t', 't', true],
        ['base-form-math', '<base href="https://evil.test/"><form action="https://evil.test/"><input name=x value=y></form><math><mtext><script>alert(1)</script></mtext></math>t', 't', true],
        ['meta-refresh', '<meta http-equiv=refresh content="0;url=javascript:alert(1)">t', 't', true],
        ['link-stylesheet', '<link rel=stylesheet href=https://evil.test/x.css>t', 't', true],
        ['event-attrs', '<div onclick=alert(1)>t</div><details open ontoggle=alert(1)>u</details>', 'tu', true],
        ['style-attr', '<span style="background:url(javascript:alert(1))">t</span>', 't', true],
        ['a-javascript', '<a href="javascript:alert(1)">click</a>', 'click', true],
        ['a-javascript-case', '<a href="JaVaScRiPt:alert(1)">click</a>', 'click', true],
        ['a-tab-split', "<a href=\"java\tscript:alert(1)\">click</a>", 'click', true],
        ['a-newline-split', "<a href=\"java\nscript:alert(1)\">click</a>", 'click', true],
        ['a-entity-split', '<a href="java&#9;script:alert(1)">click</a>', 'click', true],
        ['a-entity-first-letter', '<a href="&#106;avascript:alert(1)">click</a>', 'click', true],
        ['a-leading-control', "<a href=\"\x01 javascript:alert(1)\">click</a>", 'click', false],
        ['a-vbscript', '<a href="vbscript:msgbox(1)">click</a>', 'click', true],
        ['a-data', '<a href="data:text/html,<script>alert(1)</script>">click</a>', 'click', true],
        ['a-javascript-with-url', '<a href="javascript:alert(\'https://x.test/\')">click</a>', 'click', true],
        ['a-href-space-breakout', '<a href="https://x.test/ onmouseover=alert(1)">hover</a>', 'hover', false],
        // markup that is only text on the old page (a link's words) and becomes live there only if a new renderer prints that text raw
        ['a-text-literal-markup', '<a href="https://x.test/">&lt;img src=x onerror=alert(1)&gt;</a>', null, false],
        ['a-named-colon', '<a href="javascript&colon;alert(1)">click</a>', 'click', false],
        ['a-onmouseover', '<a href="https://x.test/" onmouseover="alert(1)">hover</a>', '⟦hover→https://x.test/⟧', true],
        ['a-href-quote-breakout', '<a href=\'https://x.test/"onmouseover="alert(1)\'>hover</a>', 'hover', false],
        ['a-script-inside', '<a href="javascript:alert(1)"><script>alert(2)</script>t</a>', 't', true],
        ['malformed-a-img', '<a <img src=x onerror=alert(1)>t', null, true],
        ['malformed-lt', '<<img src=x onerror=alert(1)//>t', null, false],
        ['comment-wrapped', '<!--><img src=x onerror=alert(1)-->t', null, false],
        ['noscript-mxss', '<noscript><p title="</noscript><img src=x onerror=alert(1)>">t', null, false],
        ['textarea-breakout', '<textarea><img src=x onerror=alert(1)></textarea>t', null, true],
        ['nul-byte', "<img src=x on\0error=alert(1)>t<scr\0ipt>alert(1)</scr\0ipt>", 't', false],
    ];

    // =================================================================================================
    // C: the detector must be able to say LIVE, and inert, here and now
    // =================================================================================================
    $arm('C detector controls', function () use ($live, $closed, $check) {
        $positive = [
            'raw img onerror' => '<img src=x onerror=alert(1)>',
            'tab-split javascript: href' => "<a href=\"java\tscript:alert(1)\">x</a>",
            'a onmouseover' => '<a href="https://x.test/" onmouseover="alert(1)">x</a>',
            'svg onload' => '<svg onload=alert(1)>',
            'script element' => '<script>alert(1)</script>',
            'iframe srcdoc' => '<iframe srcdoc="x"></iframe>',
            'uppercase JaVaScRiPt: href' => '<a href="JaVaScRiPt:alert(1)">x</a>',
            'form' => '<form action="https://x.test/"></form>',
            'base' => '<base href="https://x.test/">',
            'meta refresh' => '<meta http-equiv=refresh content=0>',
            'data: href' => '<a href="data:text/html,x">x</a>',
            'style with a script url' => '<span style="background:url(javascript:alert(1))">x</span>',
            'control character before javascript:' => "<a href=\"\x01 javascript:alert(1)\">x</a>",
            'input autofocus onfocus' => '<input onfocus=alert(1) autofocus>',
        ];
        foreach ($positive as $what => $html) {
            $check("C detector calls LIVE: {$what}", $live($html) !== null, 'the detector missed a live payload');
        }
        $negative = [
            'escaped img' => '&lt;img src=x onerror=alert(1)&gt;',
            'a with https href and rel' => '<a href="https://x.test/?a=1&amp;b=2" rel="noopener noreferrer">t</a>',
            'br' => '<br>',
            'span with plain text' => '<span>plain</span>',
            'the word onerror=alert(1) as text' => '<span>onerror=alert(1)</span>',
            'javascript: only inside a query string' => '<a href="https://x.test/?next=javascript:alert(1)">t</a>',
        ];
        foreach ($negative as $what => $html) {
            $check("C detector calls inert: {$what}", $live($html) === null, 'false LIVE: ' . $live($html));
        }
        // the closed-vocabulary check has the same duty
        $ok = '<div class="pure-control-group"><label>T: A</label><span>x<br><a href="https://x.test/?a=1&amp;b=2" rel="noopener noreferrer">t</a></span></div>';
        $check('C closed vocabulary: a correct history block passes', $closed($ok) === null, (string)$closed($ok));
        foreach (['an img' => '<span><img src=x></span>', 'an onmouseover on a' => '<span><a href="https://x.test/" rel="noopener noreferrer" onmouseover="x">t</a></span>',
                  'a javascript: href' => '<span><a href="javascript:x" rel="noopener noreferrer">t</a></span>', 'an a without rel' => '<span><a href="https://x.test/">t</a></span>',
                  'a b element' => '<span><b>t</b></span>'] as $what => $html) {
            $check("C closed vocabulary rejects: {$what}", $closed($html) !== null, 'it let a violation through');
        }
    });

    // =================================================================================================
    // R: every payload class works under the old render (otherwise «inert after» proves nothing)
    // =================================================================================================
    $arm('R reachability (old render)', function () use ($payloads, $E, $one, $live, $check) {
        foreach ($payloads as [$name, $raw, , $reachable]) {
            if (!$reachable) {
                continue;
            }
            foreach ([0, 1, 2, 3, 4] as $n) {
                try {
                    $html = $one($E($raw, $n), [], true)['html'];
                } catch (\Throwable $e) {
                    $check("R {$name} @{$n}x renders under the old render", false, $e->getMessage());
                    continue;
                }
                $reason = $live($html);
                if ($n < 4) {
                    $check("R {$name} @{$n}x is LIVE under the old render", $reason !== null, 'it is inert there: this payload cannot fail anything');
                }
                if ($n === 4) {
                    // the documented limit of the old page: one layer more is shown as literal text
                    $check("R {$name} @4x is inert under the old render (literal text)", $reason === null, 'LIVE at 4x: the model of Db::f + toXML moved');
                }
            }
        }
    });

    // =================================================================================================
    // P: payload classes x encodings through the real path
    // =================================================================================================
    $arm('P payloads', function () use ($payloads, $E, $one, $path, $live, $closed, $check) {
        foreach ($payloads as [$name, $raw, $visible]) {
            foreach ([0, 1, 2, 3, 4] as $n) {
                $stored = $E($raw, $n);
                try {
                    $r = $one($stored);
                } catch (\Throwable $e) {
                    $check("P {$name} @{$n}x renders", false, $e->getMessage());
                    continue;
                }
                $reason = $live($r['html']);
                $check("P {$name} @{$n}x inert", $reason === null, 'LIVE: ' . $reason);
                $violation = $closed($r['html']);
                $check("P {$name} @{$n}x only div/label/span/br/a", $violation === null, (string)$violation);
                $got = $r['canon'][0] ?? null;
                if ($n < 4) {
                    if ($visible !== null) {
                        $check("P {$name} @{$n}x visible text", $got === $visible, 'got ' . var_export($got, true) . ' want ' . var_export($visible, true));
                    }
                } elseif (!str_contains($raw, '&') && !str_contains($raw, "\0")) {
                    // at 4x the old page printed the payload as literal text; so must this one. (A payload that itself
                    // holds an entity is left out: toXML decodes a text value once more, so «&#9;» shows as a blank.
                    // So is a NUL: SimpleXML cut the old page's text at it.)
                    try {
                        $want = $one($stored, [], true)['canon'][0] ?? null;
                    } catch (\Throwable $e) {
                        continue;
                    }
                    $check("P {$name} @4x shows what the old page showed (literal text)", $got === $want, 'got ' . var_export($got, true) . ' want ' . var_export($want, true));
                }
            }
        }
        // the author column was always escaped; it must stay so
        $r = $path([['comment' => 'x', 'author' => '<img src=x onerror=alert(1)>']]);
        $check('P author payload is inert (unchanged behaviour)', $live($r['html']) === null, (string)$live($r['html']));
    });

    // =================================================================================================
    // L: legitimate content
    // =================================================================================================
    $arm('L legitimate content', function () use ($E, $one, $path, $closed, $check, $same) {
        $edit = static fn(int $id, string $base = 'https://x.test') => $base . '/?menuaction=booking.uievent.edit&id=' . $id;
        $a = static fn(int $id, string $base = 'https://x.test') => '<a href="' . $base . '/?menuaction=booking.uievent.edit&id=' . $id . '">#' . $id . '</a>';
        $legit = [
            ['br variants', 'Line one<br />Line two<br/>Line three<BR>four', 'Line one⏎Line two⏎Line three⏎four'],
            ['multiple-events comment', 'Multiple Events was created,<br />Event ' . $a(7) . ', ' . $a(8) . '.',
                'Multiple Events was created,⏎Event ⟦#7→' . $edit(7) . '⟧, ⟦#8→' . $edit(8) . '⟧.'],
            ['https link with & in the query', 'Se ' . $a(7) . '.', 'Se ⟦#7→' . $edit(7) . '⟧.'],
            ['link already written as &amp;', 'Se <a href="https://x.test/?menuaction=booking.uievent.edit&amp;id=7">#7</a>.', 'Se ⟦#7→' . $edit(7) . '⟧.'],
            ['link text with an entity', '<a href="https://x.test/">A &amp; B</a>', '⟦A & B→https://x.test/⟧'],
            ['link text with markup inside', '<a href="https://x.test/"><b>bold</b> link</a>', '⟦bold link→https://x.test/⟧'],
            ['link with a script inside', '<a href="https://x.test/"><script>alert(1)</script>t</a>', '⟦t→https://x.test/⟧'],
            ['href with a newline and a tab inside', "<a href=\"https://x.test/pa\nth\tx\">x</a>", '⟦x→https://x.test/pathx⟧'],
            ['uppercase scheme', '<a href="HTTPS://X.TEST/A">x</a>', '⟦x→HTTPS://X.TEST/A⟧'],
            ['newline in the text', "first\nsecond", 'first second'],
            ['æøå', 'Blåbærsyltetøy ÆØÅ æøå', 'Blåbærsyltetøy ÆØÅ æøå'],
            ['apostrophe', "It's fine", "It's fine"],
            ['phone text', 'SMS: sendt til 91000001,91000002', 'SMS: sendt til 91000001,91000002'],
            ['formatting tags keep their text', '<b>bold</b> <i>it</i> <span style=\'color: green;\'>Sent</span>', 'bold it Sent'],
            ['p breaks', '<p>Body text</p>after', 'Body text⏎after'],
            ['an a without href is text', '<a name="x">t</a>', 't'],
            ['mailto is text, not a link', '<a href="mailto:a@b.no">a@b.no</a>', 'a@b.no'],
            ['ftp is text, not a link', '<a href="ftp://x.test/f">f</a>', 'f'],
            ['protocol-relative is text, not a link', '<a href="//x.test/f">f</a>', 'f'],
            ['empty', '', ''],
        ];
        foreach ($legit as [$name, $stored, $want]) {
            // a comment is stored raw (the async/SQL path) or entity-encoded once (add_single_comment's callers)
            foreach ([0, 1] as $n) {
                if ($n === 1 && $stored === '') {
                    continue;
                }
                $r = $one($E($stored, $n));
                $same("L {$name} @{$n}x", $r['canon'][0] ?? null, $want);
                $violation = $closed($r['html']);
                $check("L {$name} @{$n}x stays inside the closed vocabulary", $violation === null, (string)$violation);
            }
        }
        // the host rewrite: an external link is made internal BEFORE the string is split into parts
        $ext = 'Event <a href="' . HD_EXTERNAL . '/?menuaction=booking.uievent.edit&id=9">#9</a>';
        $r = $one($ext, ['external_site_address' => HD_EXTERNAL]);
        $same('L host rewrite: the link points at the internal address', $r['canon'][0] ?? null, 'Event ⟦#9→' . HD_INTERNAL . '/?menuaction=booking.uievent.edit&id=9⟧');
        $r = $one($ext, []);
        $same('L host rewrite: no external address configured, the link is left alone', $r['canon'][0] ?? null, 'Event ⟦#9→' . HD_EXTERNAL . '/?menuaction=booking.uievent.edit&id=9⟧');
        // known and kept: stored «It''s» (what add_single_comment wrote until the apostrophe fix) is shown as stored
        $same("L PIN stored It''s is shown as stored (the write is fixed, old rows are not rewritten)", $one("It''s")['canon'][0] ?? null, "It''s");
        // several comments in one page keep their order and are each their own block
        $r = $path([['comment' => 'one'], ['comment' => 'two<br>2'], ['comment' => '']]);
        $same('L three rows: three blocks, in order', $r['canon'], ['one', 'two⏎2', '']);
    });

    // =================================================================================================
    // S: structure
    // =================================================================================================
    $arm('S structure', function () use ($path, $fragment, $xslSource, $check, $same) {
        $r = $path([['comment' => 'a<br>b'], ['comment' => ''], ['comment' => 'c']]);
        $same('S the feeder asked bb_event_comment for this event', preg_match('/from bb_event_comment where event_id=\(' . EVENT_ID . '\)/', $r['db']->queries[0] ?? '') === 1, true);
        foreach ($r['post'] as $i => $row) {
            $check("S row {$i}: edit() leaves no `comment` on the row", !array_key_exists('comment', $row), 'the raw string is still on the row');
            $check("S row {$i}: edit() hands over `parts` as a list", isset($row['parts']) && is_array($row['parts']) && array_is_list($row['parts']), 'parts is missing or not a list');
            foreach ($row['parts'] ?? [] as $part) {
                $check("S row {$i}: a part holds only k, v, href", !array_diff(array_keys($part), ['k', 'v', 'href']), implode(',', array_keys($part)));
            }
        }
        $xml = new \SimpleXMLElement($r['xml']);
        $same('S no <comment> element reaches the XML that the template sees', count($xml->xpath('//comments/comment')), 0);
        $same('S the XML still holds the three history rows', count($xml->xpath('//comments')), 3);
        $check('S the history block has no disable-output-escaping', !str_contains($fragment, 'disable-output-escaping'), 'd-o-e is back in the block');
        $check('S the history block never selects `comment`', preg_match('/select\s*=\s*"[^"]*\bcomment\b[^"]*"/', preg_replace('#comments\[author\]#', '', $fragment)) !== 1, 'the block reads the raw comment');
        preg_match_all('/disable-output-escaping/', $xslSource, $m);
        $same('S event_edit.xsl has exactly one d-o-e left: the system-built tab strip', [count($m[0]), str_contains($xslSource, '<xsl:value-of disable-output-escaping="yes" select="event/tabs"/>')], [1, true]);
    });

    // =================================================================================================
    // H: the helper, directly
    // =================================================================================================
    $arm('H helper', function () use ($check, $same) {
        if (!class_exists(HELPER)) {
            $check('H the helper class ' . HELPER . ' exists', false, 'not found: the fix is not in this tree');
            return;
        }
        $helper = HELPER;
        $same('H the empty string gives no parts', $helper::parse(''), []);
        $inputs = ['plain', 'a<br>b', '<a href="https://x.test/">t</a>', '<script>x</script>', "\xff\xfe<b>ok</b>", "a\0b<img src=x onerror=1>",
            '<a href="javascript:x">t</a>', str_repeat('<b>', 50) . 'deep', '&lt;img&gt;', '<![CDATA[x]]>', '<?php echo 1; ?>t', '<!-- c -->t'];
        foreach ($inputs as $i => $in) {
            $parts = $helper::parse($in);
            $check("H input #{$i}: a list", array_is_list($parts), 'not a list');
            foreach ($parts as $part) {
                $k = $part['k'] ?? null;
                $check("H input #{$i}: k is t, br or a", in_array($k, ['t', 'br', 'a'], true), var_export($k, true));
                $check("H input #{$i}: only k, v, href", !array_diff(array_keys($part), ['k', 'v', 'href']), implode(',', array_keys($part)));
                if ($k === 'br') {
                    $check("H input #{$i}: a br carries nothing else", array_keys($part) === ['k'], implode(',', array_keys($part)));
                }
                if ($k === 'a') {
                    $check("H input #{$i}: an a has an http(s) href", preg_match('#^https?://[^\s"<>]+$#i', (string)($part['href'] ?? '')) === 1, (string)($part['href'] ?? ''));
                }
                foreach (['v', 'href'] as $f) {
                    if (isset($part[$f])) {
                        $check("H input #{$i}: {$f} is valid UTF-8", is_string($part[$f]) && mb_check_encoding($part[$f], 'UTF-8'), 'invalid UTF-8');
                        $check("H input #{$i}: {$f} holds no NUL", !str_contains($part[$f], "\0"), 'NUL');
                    }
                }
            }
        }
    });

    // =================================================================================================
    // X: robustness
    // =================================================================================================
    $arm('X robustness', function () use ($one, $live, $closed, $check) {
        $cases = [
            'deep nesting (3000 b)' => str_repeat('<b>', 3000) . 'deep' . str_repeat('</b>', 3000),
            'deep nesting (3000 links)' => str_repeat('<a href="https://x.test/">', 3000) . 'deep',
            'large comment (20k br + text)' => str_repeat('text<br>', 20000),
            'many unclosed tags' => str_repeat('<img src=x onerror=alert(1) <', 20000),
        ];
        foreach ($cases as $name => $stored) {
            $t = microtime(true);
            $r = $one($stored);
            $took = microtime(true) - $t;
            $check("X {$name}: finishes in under 30 s", $took < 30, sprintf('%.1f s', $took));
            $check("X {$name}: inert", $live($r['html']) === null, (string)$live($r['html']));
            $check("X {$name}: closed vocabulary", $closed($r['html']) === null, (string)$closed($r['html']));
        }
        // 60,000 references, each inside the next: a cleaning that removes them and goes on until none is left needs one
        // pass per level, about 14 s here; one pass over the text takes milliseconds
        $four = '&amp;amp;amp;amp;'; // the «&» of a reference at 4 levels, the one that reaches the helper as a literal «&#x»
        $t = microtime(true);
        $r = $one(str_repeat($four . '#x', 60000) . $four . '#xFFFE;' . str_repeat('FFFE;', 60000));
        $took = microtime(true) - $t;
        $check('X 60,000 nested references (960 KB): finishes in under 5 s', $took < 5, sprintf('%.1f s', $took));
        $check('X 60,000 nested references: inert', $live($r['html']) === null, (string)$live($r['html']));
        $check('X 60,000 nested references: closed vocabulary', $closed($r['html']) === null, (string)$closed($r['html']));
    });

    // =================================================================================================
    // F: U+FFFE and U+FFFF (the page's own load of the XML must not fail)
    // =================================================================================================
    $arm('F U+FFFE and U+FFFF', function () use ($one, $live, $closed, $check, $same, $E, $load) {
        // «&» and k times «amp;» before the rest of a reference: the same reference at k levels of encoding. The
        // markup around it is NOT encoded, so each level reaches the helper in another shape: a character the
        // first strip removes (k 0-2), text the HTML step drops (3), text the HTML step leaves and toXML decodes
        // (4: without the fix the page fails to load here), text that stays text (5 and more).
        $amp = static fn(int $k): string => '&' . str_repeat('amp;', $k);
        $refs = ['#xFFFE;', '#xFFFF;', '#xfffe;', '#XFFFF;', '#65534;', '#65535;', '#x00000FFFE;', '#0000065535;', '#x0fffF;'];
        // [shape => [stored string around a reference, what is shown when the reference shows as X]]
        $shapes = [
            'text' => [static fn(string $e): string => 'a' . $e . 'b', static fn(string $x): string => 'a' . $x . 'b'],
            'link text' => [static fn(string $e): string => '<a href="https://x.test/">p' . $e . 'q</a>', static fn(string $x): string => '⟦p' . $x . 'q→https://x.test/⟧'],
            // the stylesheet's HTML output writes a non-ASCII character of an href percent-encoded
            'href' => [static fn(string $e): string => '<a href="https://x.test/?q=' . $e . '">t</a>', static fn(string $x): string => '⟦t→https://x.test/?q=' . str_replace("\u{FFFD}", '%EF%BF%BD', $x) . '⟧'],
        ];
        $page = function (string $name, string $stored) use ($one, $live, $closed, $check): ?array {
            try {
                $r = $one($stored);
            } catch (\Throwable $e) {
                $check("{$name}: the page loads", false, $e->getMessage());
                return null;
            }
            $check("{$name}: the page loads", true);
            $check("{$name}: inert", $live($r['html']) === null, (string)$live($r['html']));
            $check("{$name}: closed vocabulary", $closed($r['html']) === null, (string)$closed($r['html']));
            return $r;
        };
        foreach ($shapes as $shape => [$build, $want]) {
            foreach ($refs as $ref) {
                foreach (range(0, 6) as $k) {
                    $name = "F {$shape} «{$ref}» @{$k}x";
                    $r = $page($name, $build($amp($k) . $ref));
                    if ($r !== null) {
                        // up to level 3 the strip or the HTML step has already dropped the reference; at 4 the helper replaces it by
                        // U+FFFD; from level 5 it is shown as typed, one level less encoded than stored
                        $same("{$name}: what is shown", $r['canon'][0] ?? null, $want($k <= 3 ? '' : ($k === 4 ? "\u{FFFD}" : $amp($k - 5) . $ref)));
                    }
                }
            }
        }

        // a reference whose pieces are only joined by the helper's own steps: text on both sides of a comment, a
        // tag, a script that is dropped (the pieces are one text only after the helper joins them: whether it does is
        // its business, so what is shown is not pinned, only that the page loads and holds neither character); a tab
        // inside an href; and a reference that another one would rebuild if it were removed instead of replaced
        $joined = [
            'split by a comment' => [static fn(string $a): string => 'a' . $a . '#xFF<!---->FE;b', null],
            'split by a tag' => [static fn(string $a): string => 'a' . $a . '#xFF<b>FE;</b>b', null],
            'split by a dropped script' => [static fn(string $a): string => 'a' . $a . '#xFF<script>1</script>FE;b', null],
            'split in a link text' => [static fn(string $a): string => '<a href="https://x.test/">' . $a . '#xFF<b>FE;</b></a>', null],
            'split by a tab in an href' => [static fn(string $a): string => '<a href="https://x.test/?q=' . $a . '#xFF&#9;FE;">t</a>', static fn(): string => '⟦t→https://x.test/?q=%EF%BF%BD⟧'],
            // with the inner reference gone the pieces around it must not be a reference: «&#x» + U+FFFD + «FFFE;»
            'rebuilt by the replacement of another (text)' => [static fn(string $a): string => 'a' . $a . '#x' . $a . '#xFFFE;FFFE;b', static fn(): string => "a&#x\u{FFFD}FFFE;b"],
            'rebuilt by the replacement of another (href)' => [static fn(string $a): string => '<a href="https://x.test/?q=' . $a . '#6' . $a . '#65534;5534;">t</a>', static fn(): string => '⟦t→https://x.test/?q=&#6%EF%BF%BD5534;⟧'],
        ];
        foreach ($joined as $shape => [$build, $want]) {
            foreach (range(0, 6) as $k) {
                $r = $page("F {$shape} @{$k}x", $build($amp($k)));
                if ($r === null) {
                    continue;
                }
                $check("F {$shape} @{$k}x: the output holds neither U+FFFE nor U+FFFF", preg_match('/\xEF\xBF[\xBE\xBF]/', $r['html']) !== 1, 'it is in the page');
                if ($want !== null && $k === 4) {
                    // at the other levels what the HTML step makes of a half reference is its own business; the page only has to load
                    $same("F {$shape} @4x: what is shown", $r['canon'][0] ?? null, $want());
                }
            }
        }

        // the characters themselves, not a reference to them: the strip before the HTML step takes them out
        foreach (["\xEF\xBF\xBE" => 'U+FFFE', "\xEF\xBF\xBF" => 'U+FFFF'] as $bytes => $label) {
            foreach ([
                'text' => ['a' . $bytes . 'b', 'ab'],
                'link text' => ['<a href="https://x.test/">p' . $bytes . 'q</a>', '⟦pq→https://x.test/⟧'],
                'href' => ['<a href="https://x.test/?q=' . $bytes . '">t</a>', '⟦t→https://x.test/?q=⟧'],
                'text beside a reference' => ['a' . $bytes . '&#xFFFE;b', 'ab'],
            ] as $shape => [$stored, $want]) {
                $r = $page("F the character {$label} in {$shape}", $stored);
                $same("F the character {$label} in {$shape}: what is shown", $r['canon'][0] ?? null, $want);
            }
        }

        // the characters made INSIDE the HTML step (task 36487): the strip above runs before it, and a «<meta charset="utf-7">»
        // makes loadHTML read the rest of the comment as UTF-7, where «+//4-» is U+FFFE and «+//8-» is U+FFFF. libxml2 2.9
        // keeps the character inside an attribute value (an href) and drops it from text and link text, so without the fix
        // only the href rows fail the page's load; the text rows are there for a libxml that keeps it in text too (and cannot
        // fail on this one: the parts built by hand below are what reaches a v). The meta works only while the tag reaches the
        // helper unencoded: Db::stripslashes decodes the stored value twice and the helper once more, so it works stored at 0
        // to 3 levels and from level 4 on the tag is text. At 0 to 3 the href must hold U+FFFD where the character was: that
        // pins that a character was there (a libxml that dropped it would leave none) and that it was replaced, neither kept
        // nor removed.
        $metas = [
            'charset' => '<meta charset="utf-7">',
            'unquoted' => '<meta charset=utf-7>',
            'http-equiv' => '<meta http-equiv="Content-Type" content="text/html; charset=utf-7">',
            'alias' => '<meta charset="UNICODE-1-1-UTF-7">',
        ];
        $utf7 = ['+//4-' => 'U+FFFE', '+//8-' => 'U+FFFF'];
        foreach ($metas as $metaName => $meta) {
            foreach ($utf7 as $coded => $label) {
                foreach (range(0, 6) as $k) {
                    $name = "F utf-7 {$metaName} {$label} in an href @{$k}x";
                    $r = $page($name, $E($meta . '<a href="https://e.test/' . $coded . '">x</a>', $k));
                    if ($r === null) {
                        continue;
                    }
                    $check("{$name}: the output holds neither U+FFFE nor U+FFFF", preg_match('/\xEF\xBF[\xBE\xBF]/', $r['html']) !== 1, 'it is in the page');
                    if ($k <= 3) {
                        $same("{$name}: the href holds U+FFFD where the character was", $r['post'][0]['parts'][0]['href'] ?? null, "https://e.test/\u{FFFD}");
                    }
                }
            }
        }
        foreach ($utf7 as $coded => $label) {
            foreach ([
                'text' => 'a' . $coded . 'b',
                'link text' => '<a href="https://e.test/">p' . $coded . 'q</a>',
            ] as $shape => $body) {
                foreach (range(0, 6) as $k) {
                    $name = "F utf-7 charset {$label} in {$shape} @{$k}x";
                    $r = $page($name, $E($metas['charset'] . $body, $k));
                    if ($r !== null) {
                        $check("{$name}: the output holds neither U+FFFE nor U+FFFF", preg_match('/\xEF\xBF[\xBE\xBF]/', $r['html']) !== 1, 'it is in the page');
                    }
                }
            }
        }

        // the same characters in a v and in an href without libxml: libxml2 2.9 never lets one reach a v (and no newer libxml2
        // was tried), so parts are built by hand and given to the two private steps that clean them. What the page depends on
        // is that cleaning, not the parser's habit of dropping the character from text. The controls: the same parts uncleaned,
        // a v alone and an href alone, make the page's load fail, or a cleaned part that loads would prove nothing.
        $step = static fn(string $name): \ReflectionMethod => new \ReflectionMethod(HELPER, $name);
        $page36487 = static function (array $parts) use ($load): ?string {
            $xml = \phpgwapi_xmlhelper::toXML(['data' => ['comments' => [['time' => 't', 'author' => 'A', 'type' => 'comment', 'parts' => $parts]]]], 'PHPGW');
            try {
                $load($xml);
                return null;
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
        };
        foreach (["\u{FFFE}" => 'U+FFFE', "\u{FFFF}" => 'U+FFFF'] as $char => $label) {
            $dirtyV = [['k' => 't', 'v' => "a{$char}b"], ['k' => 'a', 'href' => 'https://e.test/', 'v' => "p{$char}q"]];
            $dirtyHref = [['k' => 'a', 'href' => "https://e.test/?q={$char}z", 'v' => 'p']];
            $check("F synthetic: control: a v that holds a raw {$label} fails the page's load", $page36487($dirtyV) !== null, 'it loaded');
            $check("F synthetic: control: an href that holds a raw {$label} fails the page's load", $page36487($dirtyHref) !== null, 'it loaded');

            $cleanV = $step('withoutNonCharacterReferences')->invoke(null, $dirtyV);
            $same("F synthetic: a raw {$label} in a text v is replaced by U+FFFD", $cleanV[0]['v'] ?? null, "a\u{FFFD}b");
            $same("F synthetic: a raw {$label} in a link text v is replaced by U+FFFD", $cleanV[1]['v'] ?? null, "p\u{FFFD}q");
            $failed = $page36487($cleanV);
            $check("F synthetic: the page loads with the cleaned v of a raw {$label}", $failed === null, (string)$failed);

            $href = $step('httpHref')->invoke(null, $dirtyHref[0]['href']);
            $same("F synthetic: a raw {$label} in an href is replaced by U+FFFD", $href, "https://e.test/?q=\u{FFFD}z");
            $failed = $page36487([['k' => 'a', 'href' => $href, 'v' => 'p']]);
            $check("F synthetic: the page loads with the cleaned href of a raw {$label}", $failed === null, (string)$failed);
        }

        // what must NOT go: characters next to the two. They are fine in XML: levels 0-4 give the character, 5 and more the text
        $keep = ['#xFFFD;' => 0xFFFD, '#65533;' => 0xFFFD, '#xFFFC;' => 0xFFFC, '#65532;' => 0xFFFC, '#x1FFFE;' => 0x1FFFE, '#131070;' => 0x1FFFE, '#xFFFEF;' => 0xFFFEF,
                 '#xFFF;' => 0xFFF, '#6553;' => 6553, '#x10FFFF;' => 0x10FFFF, '#xFFFE0;' => 0xFFFE0, '#x0FFFD;' => 0xFFFD];
        foreach ($keep as $ref => $cp) {
            foreach (range(0, 6) as $k) {
                $name = "F «{$ref}» is kept @{$k}x";
                $r = $page($name, 'a' . $amp($k) . $ref . 'b');
                if ($r !== null) {
                    $same("{$name}: what is shown", $r['canon'][0] ?? null, 'a' . ($k <= 4 ? mb_chr($cp, 'UTF-8') : $amp($k - 5) . $ref) . 'b');
                }
            }
        }

        // strtol (task 36448): PHP reads a hexadecimal reference with strtol, which also takes a «0x» after the «x», so
        // «&#x0xFFFE;» is U+FFFE for the decoder whatever a pattern for the canonical spelling says. Level 4 is where it
        // fails the page; below it the strip or the HTML step has dealt with it already (what the HTML step makes of the
        // half reference «&#x0» is its own business, so there only the load and the absence of the characters are pinned),
        // from level 5 it is shown as typed.
        $strtol = ['#x0xFFFE;', '#x0XFFFF;', '#X0xfffe;', '#X0XFFFF;', '#x0x0000FFFE;', '#x0X0fffF;'];
        foreach ($shapes as $shape => [$build, $want]) {
            foreach ($strtol as $ref) {
                foreach (range(0, 6) as $k) {
                    $name = "F strtol {$shape} «{$ref}» @{$k}x";
                    $r = $page($name, $build($amp($k) . $ref));
                    if ($r === null) {
                        continue;
                    }
                    $check("{$name}: the output holds neither U+FFFE nor U+FFFF", preg_match('/\xEF\xBF[\xBE\xBF]/', $r['html']) !== 1, 'it is in the page');
                    if ($k >= 4) {
                        $same("{$name}: what is shown", $r['canon'][0] ?? null, $want($k === 4 ? "\u{FFFD}" : $amp($k - 5) . $ref));
                    }
                }
            }
        }

        // the grammar sweep: spellings of a reference, found by asking the decoder (html_entity_decode, the call toXML makes)
        // which of them are U+FFFE or U+FFFF, then each one at 4 levels through the real path. A candidate the decoder reads
        // and the helper missed is a page that fails to load. The control: the sweep holds such spellings, among them some
        // that only strtol reads (a «0x» prefix), or an empty sweep would pass.
        $pre = ['', 'x', 'X', 'x0x', 'x0X', 'X0x', 'X0X', 'x00x', 'x0x0x', '0x', 'x ', 'x+', 'x-', ' x', "x\t", 'x0x+', 'x0x ', 'x0x-', '+', '-', ' ', "\t", '0', 'x0', 'x0b', 'x0o', '0b', '0o', 'xx', 'x0xx'];
        $zeros = ['', '0', '000', str_repeat('0', 40)];
        $cores = ['fffe', 'FFFF', 'fFfE', '65534', '65535', 'fffe0', 'ffff ', '65534 ', 'FFFE.', '1fffe', '10fffe', 'fffe_', 'ff_fe'];
        $ends = [';', ' ;', '', ';;'];
        $candidates = 0;
        $decoded = 0;
        $quirk = 0;
        $missed = [];
        foreach ($pre as $p) {
            foreach ($zeros as $z) {
                foreach ($cores as $c) {
                    foreach ($ends as $e) {
                        $ref = '&#' . $p . $z . $c . $e;
                        $candidates++;
                        $isCharacter = preg_match('/\xEF\xBF[\xBE\xBF]/', html_entity_decode($ref)) === 1;
                        $decoded += $isCharacter ? 1 : 0;
                        $quirk += $isCharacter && preg_match('/^&#x0x/i', $ref) === 1 ? 1 : 0;
                        try {
                            $one('a' . $amp(4) . substr($ref, 1) . 'b');
                        } catch (\Throwable $ex) {
                            $missed[] = json_encode($ref) . ($isCharacter ? ' (decoded)' : '');
                        }
                    }
                }
            }
        }
        $check('F sweep: control: the candidates hold spellings the decoder reads as U+FFFE or U+FFFF', $decoded > 0, "{$decoded} of {$candidates}");
        $check('F sweep: control: some of them are read only because strtol takes a «0x» prefix', $quirk > 0, "{$quirk} of {$decoded}");
        $check("F sweep: the page loads at 4 levels for every candidate ({$candidates})", $missed === [], count($missed) . ' fail: ' . implode(' ', array_slice($missed, 0, 8)));

        // two decodes (validation 36431): the helper leaves «&amp;#xFFFE;» in a part, which one decode turns into the eight
        // characters «&#xFFFE;» and two turn into the character. The page is safe because exactly one decode follows (toXML)
        // and its output is escaped; so the part must still hold the reference one level encoded, the control must show that
        // two decodes of it WOULD be the character, and the page must load and show the eight characters.
        foreach (['#xFFFE;', '#x0xFFFE;', '#65534;'] as $ref) {
            $name = "F two decodes «{$ref}» @5x";
            $r = $page($name, 'a' . $amp(5) . $ref . 'b');
            if ($r === null) {
                continue;
            }
            $v = $r['post'][0]['parts'][0]['v'] ?? null;
            $same("{$name}: the part holds the reference one level encoded", $v, 'a&amp;' . $ref . 'b');
            $check("{$name}: control: one decode of the part is no character, two are", is_string($v)
                && !str_contains(html_entity_decode($v), "\u{FFFE}") && !str_contains(html_entity_decode($v), "\u{FFFF}")
                && preg_match('/\xEF\xBF[\xBE\xBF]/', html_entity_decode(html_entity_decode($v))) === 1, var_export($v, true));
            $same("{$name}: what is shown", $r['canon'][0] ?? null, 'a&' . $ref . 'b');
        }
    });

    // =================================================================================================
    // A: the apostrophe
    // =================================================================================================
    $arm('A apostrophe', function () use ($same, $check) {
        $pdo = new HdFakePdo();
        $dbClass = new \ReflectionClass(\App\Database\Db::class);
        $db = $dbClass->newInstanceWithoutConstructor();
        $dbClass->getProperty('db')->setValue($db, $pdo);
        $dbClass->getProperty('resultSet')->setValue($db, true);
        $so = (new \ReflectionClass('booking_soevent'))->newInstanceWithoutConstructor();
        (new \ReflectionProperty('booking_socommon', 'db'))->setValue($so, $db);
        (new \ReflectionProperty('booking_socommon', 'userSettings'))->setValue($so, ['account_id' => 4711]);

        // control: the fake PDO doubles a quote, as the old db_addslashes() relied on
        $same('A control: the quote of the fake PDO doubles an apostrophe', $db->db_addslashes("It's"), "It''s");

        $sql = 'INSERT INTO bb_event_comment (event_id, time, author, comment, type) VALUES (?, ?, ?, ?, ?)';
        $inputs = [
            'apostrophe' => "It's fine",
            'apostrophes with markup and an ampersand' => "Åse O'Brien's <b>x</b> & y",
            'two apostrophes in a row' => "rock ''n'' roll",
            'double quotes and a link' => 'He said "hi" <a href="https://x.test/?a=1&b=2">x</a>',
            'plain text' => 'Plain',
            'padding' => '  padded  ',
            'two lines' => "one\ntwo",
            'a backslash' => 'back\\slash',
            'empty' => '',
        ];
        foreach ($inputs as $name => $in) {
            $pdo->statements = [];
            $so->add_single_comment(EVENT_ID, $in);
            $same("A {$name}: one statement prepared", count($pdo->statements), 1);
            $st = $pdo->statements[0] ?? new HdFakeStatement('');
            $same("A {$name}: the SQL is unchanged", $st->sql, $sql);
            $same("A {$name}: the comment is bound exactly as given, as a string", $st->bound[4] ?? null, [$in, \PDO::PARAM_STR]);
            $same("A {$name}: the event id is bound as an integer", $st->bound[1] ?? null, [EVENT_ID, \PDO::PARAM_INT]);
            $same("A {$name}: the author is the account id, as an integer", $st->bound[3] ?? null, [4711, \PDO::PARAM_INT]);
            $same("A {$name}: the type is «comment»", $st->bound[5] ?? null, ['comment', \PDO::PARAM_STR]);
            if (str_contains($in, "'")) {
                // what the history shows: the stored value through the real Db::f
                $dbClass->getProperty('Record')->setValue($db, ['c' => $st->bound[4][0] ?? null]);
                $same("A {$name}: read back by Db::f it is what was written", $db->f('c', true), $in);
            }
        }
    });

    // =================================================================================================
    // K: the corpus (opt in with HD_DB_HEADER): counts only, nothing is printed from a row
    // =================================================================================================
    $corpusNote = 'K corpus arm NOT RUN (set HD_DB_HEADER to config/header.inc.php; HD_DB_HOST/HD_DB_PORT override the host)';
    if (getenv('HD_DB_HEADER')) {
        $corpusNote = null;
        // the header sets $phpgw_domain as a plain variable: it has to be required at global scope
        require getenv('HD_DB_HEADER');
        $arm('K corpus', function () use ($path, $dom, $live, $closed, $check, $same) {
            $d = $GLOBALS['phpgw_domain']['default'] ?? reset($GLOBALS['phpgw_domain']);
            $pdo = new \PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('HD_DB_HOST') ?: $d['db_host'], getenv('HD_DB_PORT') ?: $d['db_port'], $d['db_name']), $d['db_user'], $d['db_pass']);
            $stored = $pdo->query('select comment from bb_event_comment')->fetchAll(\PDO::FETCH_COLUMN);
            $strip = static fn(string $s): string => (string)(preg_replace('/\s+/u', '', $s) ?? preg_replace('/\s+/', '', $s));
            $httpHref = static fn(string $h): string => (string)preg_replace('/[\x00-\x1f\x7f]+/', '', trim($h));
            $active = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'img', 'form', 'input', 'link', 'meta', 'base', 'video', 'audio', 'source', 'button', 'select', 'textarea'];
            $n = ['rows' => 0, 'text_changed' => 0, 'rows_with_http_link' => 0, 'link_count_changed' => 0, 'link_target_changed' => 0,
                'rows_with_active_element_before' => 0, 'live_after' => 0, 'outside_vocabulary_after' => 0, 'block_misaligned' => 0];
            foreach (array_chunk($stored, 500) as $chunk) {
                $rows = array_map(static fn($c) => ['comment' => (string)$c], $chunk);
                $r = $path($rows);
                $divs = [];
                foreach ($dom($r['html'])->getElementsByTagName('div') as $div) {
                    $divs[] = $div;
                }
                if (count($divs) !== count($rows)) {
                    $n['block_misaligned'] += abs(count($divs) - count($rows));
                    continue;
                }
                foreach ($r['pre'] as $i => $pre) {
                    $n['rows']++;
                    // the old page: html_entity_decode (toXML) of the Db::f string, printed raw; script/style text is never shown
                    $old = $dom(html_entity_decode((string)$pre['comment']));
                    $oldLinks = [];
                    $hasActive = false;
                    foreach ($old->getElementsByTagName('*') as $el) {
                        $nm = strtolower($el->nodeName);
                        $hasActive = $hasActive || in_array($nm, $active, true);
                        if ($nm === 'a' && preg_match('#^https?://#i', $httpHref($el->getAttribute('href')))) {
                            $oldLinks[] = $httpHref($el->getAttribute('href'));
                        }
                    }
                    foreach (['script', 'style'] as $x) {
                        foreach (iterator_to_array($old->getElementsByTagName($x)) as $el) {
                            $el->parentNode->removeChild($el);
                        }
                    }
                    $oldText = $strip($old->getElementsByTagName('body')->item(0)->textContent);
                    $span = null;
                    foreach ($divs[$i]->childNodes as $c) {
                        if ($c instanceof \DOMElement && strtolower($c->nodeName) === 'span') {
                            $span = $c;
                        }
                    }
                    $newText = $strip((string)$span?->textContent);
                    $newLinks = [];
                    foreach ($span?->getElementsByTagName('a') ?? [] as $el) {
                        $newLinks[] = $el->getAttribute('href');
                    }
                    $n['rows_with_active_element_before'] += $hasActive ? 1 : 0;
                    $n['rows_with_http_link'] += $oldLinks ? 1 : 0;
                    $n['text_changed'] += $oldText !== $newText ? 1 : 0;
                    $n['link_count_changed'] += count($oldLinks) !== count($newLinks) ? 1 : 0;
                    $n['link_target_changed'] += $oldLinks !== $newLinks ? 1 : 0;
                }
                $n['live_after'] += $live($r['html']) !== null ? 1 : 0;
                $n['outside_vocabulary_after'] += $closed($r['html']) !== null ? 1 : 0;
            }
            echo 'K bb_event_comment (counts only, ', date('c'), '): ', json_encode($n), "\n";
            $check('K the corpus is not empty (a zero over nothing proves nothing)', $n['rows'] > 0, 'rows=0');
            $check('K the corpus holds http(s) link rows (the link arms are not vacuous)', $n['rows_with_http_link'] > 0, 'no row with a link');
            $same('K every row is aligned with its block', $n['block_misaligned'], 0);
            $same('K no row changed its visible text', $n['text_changed'], 0);
            $same('K no row lost or gained a link', $n['link_count_changed'], 0);
            $same('K no link changed its target', $n['link_target_changed'], 0);
            $same('K no chunk is LIVE after', $n['live_after'], 0);
            $same('K no chunk leaves the closed vocabulary', $n['outside_vocabulary_after'], 0);
        });
    }

    // --- report ----------------------------------------------------------------------------------------
    foreach ($failures as $f) {
        echo 'FAIL ', $f, "\n";
    }
    echo "\n", str_pad('group', 34), str_pad('pass', 8), "fail\n";
    foreach ($stats as $g => [$p, $f]) {
        echo str_pad($g, 34), str_pad((string)$p, 8), $f, "\n";
    }
    if ($corpusNote !== null) {
        echo "\n", $corpusNote, "\n";
    }
    $total = $passed + count($failures);
    if ($failures) {
        echo 'Event history XSS harness: FAIL ', count($failures), " of {$total} checks\n";
        exit(1);
    }
    echo "Event history XSS harness: PASS {$total} checks\n";
    exit(0);
}
