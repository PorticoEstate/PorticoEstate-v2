/**
 * Hermetic check of the kitchen-contact HTML whitelist (utils/safe-html.ts, GH #1423, task 40289).
 *
 *   node tests/application-view/run_safe_html.mts
 *
 * Renders safeHtmlToReact(html) with react-dom/server and compares the markup with what the whitelist must leave:
 * tags p br strong b em i ul ol li a; an `a` keeps only an http(s)/mailto/tel href and always gets target=_blank
 * rel="noopener noreferrer"; script/style/iframe/object/embed/svg/math/template are dropped with their text, any
 * other tag keeps its text, empty blocks and edge breaks go, and an empty contact is null.
 *
 * SAFE_HTML_TS (optional) points at an alternative copy of safe-html.ts: mutate_safe_html.sh uses it to prove that a
 * deliberately weakened whitelist turns this harness red. The packages (react, react-dom, html-react-parser) come from
 * the client's node_modules (CLIENT_DIR, else this checkout's client, else the sibling pe-api checkout's); the
 * source under test is always THIS checkout's.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {existsSync, mkdtempSync, readFileSync, symlinkSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const clientRel = 'src/modules/bookingfrontend/client';
const clientDir = [process.env.CLIENT_DIR, join(repo, clientRel), resolve(repo, '..', 'pe-api', clientRel)]
    .find(dir => dir && existsSync(join(dir, 'node_modules', 'html-react-parser')));
if (!clientDir) throw new Error('no client node_modules found (set CLIENT_DIR)');

const dir = mkdtempSync(join(tmpdir(), 'safe-html-'));
symlinkSync(join(clientDir, 'node_modules'), join(dir, 'node_modules'));
writeFileSync(join(dir, 'safe-html.mts'), readFileSync(process.env.SAFE_HTML_TS ?? join(repo, clientRel, 'src/utils/safe-html.ts'), 'utf8'));
const {safeHtmlToReact, safeHref}: any = await import(pathToFileURL(join(dir, 'safe-html.mts')).href);
const {createElement}: any = await import(pathToFileURL(join(dir, 'node_modules/react/index.js')).href);
const {renderToStaticMarkup}: any = await import(pathToFileURL(join(dir, 'node_modules/react-dom/server.js')).href);

const render = (html: string): string | null => {
    const node = safeHtmlToReact(html);
    return node === null ? null : renderToStaticMarkup(createElement('div', null, node)).replace(/^<div>|<\/div>$/g, '');
};

let failures = 0;
let checks = 0;
const eq = (name: string, html: string, expected: string | null) => {
    checks++;
    const got = render(html);
    if (got !== expected) {
        failures++;
        console.log(`FAIL ${name}\n   in:       ${JSON.stringify(html)}\n   expected: ${JSON.stringify(expected)}\n   got:      ${JSON.stringify(got)}`);
    }
};

const A = (href: string) => `<a href="${href}" target="_blank" rel="noopener noreferrer">x</a>`;

// The editor's own output, and what must not be left of it.
eq('text + empty trailing paragraph (the measured contact_info)', '<p>Dette er&nbsp;Kontaktinformasjon tekstskjema</p><p><br></p>', '<p>Dette er\u00A0Kontaktinformasjon tekstskjema</p>');
eq('empty paragraph in the middle', '<p>a</p><p><br></p><p>b</p>', '<p>a</p><p>b</p>');
eq('nbsp-only paragraph is empty', '<p>&nbsp;</p>', null);
eq('zero-width-only paragraph is empty', '<p>​</p>', null);
eq('empty string', '', null);
eq('whitespace only', '  \n ', null);
eq('only breaks', '<br><br>', null);
eq('trailing break', 'text<br>', 'text');
eq('leading break', '<br>text', 'text');
eq('trailing break inside a paragraph', '<p>text<br></p>', '<p>text</p>');
eq('leading break inside a paragraph', '<p><br>text</p>', '<p>text</p>');
eq('trailing break inside a list item', '<ul><li>a<br></li></ul>', '<ul><li>a</li></ul>');
eq('break inside text is kept', 'a<br>b', 'a<br/>b');
eq('empty list item goes', '<ul><li>a</li><li><br></li></ul>', '<ul><li>a</li></ul>');

// Links: the four schemes pass, every other one is just its text.
eq('https', '<a href="https://example.com">x</a>', A('https://example.com'));
eq('HTTPS upper case', '<a href="HTTPS://EXAMPLE.NO">x</a>', A('HTTPS://EXAMPLE.NO'));
eq('http', '<a href="http://example.com">x</a>', A('http://example.com'));
eq('mailto', '<a href="mailto:a@b.no">x</a>', A('mailto:a@b.no'));
eq('tel', '<a href="tel:+4712345678">x</a>', A('tel:+4712345678'));
eq('href with surrounding space is trimmed', '<a href="  https://example.com ">x</a>', A('https://example.com'));
eq('javascript:', '<a href="javascript:alert(1)">x</a>', 'x');
eq('JaVaScRiPt:', '<a href="JaVaScRiPt:alert(1)">x</a>', 'x');
eq(' javascript: (leading space)', '<a href=" javascript:alert(1)">x</a>', 'x');
eq('java\\tscript: (tab inside the scheme)', '<a href="java&#9;script:alert(1)">x</a>', 'x');
eq('java&#115;cript: (encoded)', '<a href="java&#115;cript:alert(1)">x</a>', 'x');
eq('encoded after unescape (&amp;#115;)', '<a href="java&amp;#115;cript:alert(1)">x</a>', 'x');
eq('control character first', '<a href="\u0001javascript:alert(1)">x</a>', 'x');
// what a browser reads is what is tested and emitted: a tab inside the scheme is dropped, a control character inside it
// is NOT (a browser takes that for a relative link), a control character at an end is trimmed
eq('control character INSIDE the scheme is no scheme', '<a href="h&#x01;ttps://example.com">x</a>', 'x');
eq('tab inside the scheme is dropped, as a browser drops it', '<a href="ht&#9;tps://example.com">x</a>', A('https://example.com'));
eq('a control character at the end is trimmed', '<a href="https://example.com&#x01;">x</a>', A('https://example.com'));
eq('data:', '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', 'x');
eq('vbscript:', '<a href="vbscript:msgbox(1)">x</a>', 'x');
eq('relative link is not allowed', '<a href="/bookingfrontend/x">x</a>', 'x');
eq('scheme-less link is not allowed', '<a href="example.com">x</a>', 'x');
eq('a with no href', '<a>x</a>', 'x');
eq('link attributes other than href are dropped, target and rel are forced',
    '<a href="https://example.com" target="_self" rel="opener" onclick="x()" onmouseover="y()" style="color:red" class="c" id="i">x</a>',
    A('https://example.com'));
eq('a link without a rel in the source gets one', '<a href="https://example.com" target="_blank">x</a>', A('https://example.com'));
eq('link inside a paragraph, bad href', '<p><a href="javascript:x">y</a></p>', '<p>y</p>');

// Elements and attributes.
eq('img with onerror', '<img src=x onerror=alert(1)>text', 'text');
eq('img with onerror between words', 'a<img src=x onerror=alert(1)>b', 'ab');
eq('iframe', '<iframe src="https://evil.example"></iframe>text', 'text');
eq('iframe fallback text is dropped too', 'a<iframe src="https://evil.example">fallback</iframe>b', 'ab');
eq('script is dropped with its code', '<script>alert(1)</script>text', 'text');
eq('style element is dropped with its css', '<style>p{color:red}</style>text', 'text');
eq('object', '<object data="x">fallback</object>text', 'text');
eq('embed', '<embed src="x">text', 'text');
eq('svg onload', '<svg onload="alert(1)"><circle/></svg>text', 'text');
eq('math', '<math><mi>x</mi></math>text', 'text');
eq('template', '<template><b>x</b></template>text', 'text');
eq('paragraph attributes', '<p style="x:y" onclick="z()" class="c" id="i" data-x="1">t</p>', '<p>t</p>');
eq('strong/b/em/i keep no attributes', '<strong style="a:b">1</strong><b class="c">2</b><em id="d">3</em><i onclick="e()">4</i>', '<strong>1</strong><b>2</b><em>3</em><i>4</i>');
eq('span and u keep their text only', '<span style="a:b">a</span><u>b</u>', 'ab');
eq('font keeps its text', '<font color="red">hi</font>', 'hi');
eq('table keeps its text', '<table><tr><td>cell</td></tr></table>', 'cell');
eq('div becomes a paragraph, two do not run together', '<div>a</div><div>b</div>', '<p>a</p><p>b</p>');
eq('heading becomes a paragraph', '<h3 class="x">Kjøkken</h3>', '<p>Kjøkken</p>');
eq('a comment renders nothing', 'a<!-- <script>x</script> -->b', 'ab');
eq('text is escaped', '1 &lt; 2 &amp; 3 > 2', '1 &lt; 2 &amp; 3 &gt; 2');
eq('an escaped tag is text, not a tag', '&lt;script&gt;alert(1)&lt;/script&gt;', '&lt;script&gt;alert(1)&lt;/script&gt;');
eq('lists', '<ol><li>1</li><li><b>2</b></li></ol>', '<ol><li>1</li><li><b>2</b></li></ol>');

// safeHref directly: the scheme test is on the value as a browser reads it.
for (const [href, ok] of [['https://a.no', true], ['JAVASCRIPT:1', false], ['  tel:1', true], ['\tjava\nscript:1', false], ['h\u0001ttps://a.no', false], ['ht\ttps://a.no', true], [undefined, false], ['', false]] as const) {
    checks++;
    const got = safeHref(href as any) !== null;
    if (got !== ok) {
        failures++;
        console.log(`FAIL safeHref(${JSON.stringify(href)}) expected ${ok} got ${got}`);
    }
}

console.log(`${checks - failures}/${checks} pass`);
process.exit(failures === 0 ? 0 : 1);
