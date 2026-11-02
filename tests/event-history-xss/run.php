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
 *   K   (HD_DB_HEADER set) the corpus: every stored bb_event_comment row of the local database through the real
 *       path; counts only, no content is printed. Visible text and links are unchanged against the old page's
 *       view of the same row (script/style text excluded), and nothing is LIVE afterwards.
 *
 * Red first: run it against a tree that does not have the fix, e.g.
 *   git archive 1116d6db2 src | tar -x -C /tmp/hd-base && HD_ROOT=/tmp/hd-base php tests/event-history-xss/run.php
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
     * Rows (stored strings) through the real feeder, the verbatim edit() block, the real toXML and a sheet.
     *
     * @param list<array{comment:string,author?:string}> $rows
     * @return array{pre:array,post:array,xml:string,html:string,canon:list<string>,spans:list<string>}
     */
    $path = static function (array $rows, ?\XSLTProcessor $processor = null, array $config = [], bool $runBlockToo = true) use ($runBlock, $realSheet, $dom, $canon, $collapse): array {
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
        $prev = libxml_use_internal_errors(true);
        try {
            $tree = new \SimpleXMLElement($xml);
        } catch (\Exception $e) {
            $why = trim((string)(libxml_get_errors()[0]->message ?? $e->getMessage()));
            throw new \RuntimeException('toXML gave XML that does not parse (the whole page would fail): ' . $why);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
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
