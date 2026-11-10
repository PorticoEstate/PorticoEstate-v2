/**
 * What the officer SEES after pressing "send SMS" on an event (GH #1327, task 36097): the REAL
 * src/modules/booking/js/base/event.js runs in a node vm with a fake jQuery, and the reply of
 * send_sms_participants is played back to its ajax callbacks.
 *
 *   node tests/event-sms-participants/run_js.mjs
 *
 * Checks:
 *   J1  an error reply stays on screen in the error class (it used to be wiped after one second, so a
 *       reply with a message was gone before it could be read)
 *   J2  an ok reply is shown in the good class, verbatim (the server escapes the phone numbers; the client
 *       must neither double-escape nor unescape them), and stays
 *   J3  a reply with no message (PHP's bare false for «SMS disabled», null, {}, an ok with no text) shows the
 *       generic «could not send» text in the error class, never «undefined» and never nothing
 *   J4  the ajax error callback exists and shows the generic text (a 500, a die() body, an HTML error page,
 *       i.e. a reply that is not JSON, which is the silence of task 36021)
 *   J5  the request itself is unchanged (POST, json, the typed text, send_sms) and a declined confirm sends nothing
 *   J6  the source patterns outside event.js: the js_lang token in event_edit.xsl that makes lang['sms_send_failed']
 *       exist on the page, and the three new/reused lang keys in no, nn and en (a key missing from ALL
 *       languages renders raw to the officer with every validator green)
 *   J7  (task 36828) the up-front message in event_edit.xsl is in the class event.js gives the same answer after
 *       Send; the textarea and the button are both disabled on the one flag; SendSms() holds no recipient rule
 *
 * What it can NOT do is render the page: J1-J5 are behaviour of event.js against a fake DOM, J6 is text.
 * Every pattern in J6 has a control that must NOT match.
 *
 * HD_ROOT (optional) = a tree holding src/; mutate.py points it at a copy with one deliberate defect.
 *
 * Exit code: 0 all pass, 1 any failure.
 */
import {readFileSync} from 'node:fs';
import {dirname, join, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const root = process.env.HD_ROOT ?? resolve(here, '../..');

const failures = [];
let checks = 0;
const check = (name, ok, detail = '') => {
    checks++;
    if (!ok) {
        failures.push(detail ? `${name} — ${detail}` : name);
    }
};
const same = (name, got, want) => check(name, JSON.stringify(got) === JSON.stringify(want), `got ${JSON.stringify(got)} want ${JSON.stringify(want)}`);

const GENERIC = '[lang:sms_send_failed]';

/** event.js in a vm: a fake jQuery that records what lands in #sms_receipt and what the page asked ajax for. */
const load = () => {
    const state = {receipt: [], ajax: [], timers: [], confirms: 0, confirmAnswer: true};

    const chain = (selector) => {
        const self = new Proxy(function () {}, {
            get(_t, prop) {
                if (typeof prop === 'symbol' || prop === 'then') {
                    return undefined;
                }
                return (...args) => {
                    if (prop === 'ready') {
                        args[0]();
                        return self;
                    }
                    if (prop === 'html') {
                        if (args.length === 0) {
                            return '';
                        }
                        if (selector === '#sms_receipt') {
                            state.receipt.push(String(args[0]));
                        }
                        return self;
                    }
                    if (prop === 'val') {
                        return args.length === 0 && selector === '#field_sms_content' ? 'Hello from the officer' : self;
                    }
                    return self;
                };
            },
        });
        return self;
    };
    const $ = (arg) => chain(typeof arg === 'string' ? arg : null);
    $.ajax = (opts) => {
        state.ajax.push(opts);
    };

    const sandbox = {
        $,
        document: {},
        window: {},
        lang: {'send sms': 'Send SMS', sms_send_failed: GENERIC},
        event_id: 84227,
        phpGWLink: (_file, args) => `/index.php?menuaction=${args.menuaction}&id=${args.id}`,
        JqueryPortico: {autocompleteHelper() {}},
        confirm: () => {
            state.confirms++;
            return state.confirmAnswer;
        },
        setTimeout: (fn, ms) => {
            state.timers.push({fn, ms});
            return state.timers.length;
        },
        console,
    };
    const ctx = vm.createContext(sandbox);
    vm.runInContext(readFileSync(join(root, 'src/modules/booking/js/base/event.js'), 'utf8'), ctx, {filename: 'event.js'});
    return {state, ctx};
};

/** press the button, then hand the reply to the callback the page registered */
const press = (reply) => {
    const page = load();
    page.ctx.SendSms();
    const opts = page.state.ajax[0];
    if (opts && reply) {
        reply(opts, page.state);
    }
    return {...page, opts};
};
const lastReceipt = (state) => state.receipt[state.receipt.length - 1] ?? '';
const respond = (data) => (opts) => opts.success(data);
const runTimers = (state) => {
    for (const t of [...state.timers]) {
        t.fn();
    }
};

// --- J5: the request -------------------------------------------------------------------------------------
{
    const {opts, state} = press();
    check('J5 SendSms is defined by event.js', typeof opts === 'object');
    same('J5 the button asks for confirmation once', state.confirms, 1);
    same('J5 POST, json, to the participants endpoint of THIS event', [opts?.type, opts?.dataType, opts?.url], ['POST', 'json', '/index.php?menuaction=booking.uievent.send_sms_participants&id=84227']);
    same('J5 the typed text and send_sms are posted', opts?.data, {sms_content: 'Hello from the officer', send_sms: true});

    const page = load();
    page.state.confirmAnswer = false;
    page.ctx.SendSms();
    same('J5 a declined confirm sends nothing', page.state.ajax.length, 0);
}

// --- J1: an error reply -----------------------------------------------------------------------------------
{
    const msg = '[lang:sms_event_no_participants]';
    const {state} = press(respond({status: 'error', message: msg}));
    same('J1 the message is shown in the error class', lastReceipt(state), `<div class="error">${msg}</div>`);
    same('J1 nothing is scheduled to wipe it', state.timers.length, 0);
    runTimers(state);
    same('J1 even if something were scheduled, the message is still the last thing on screen', lastReceipt(state), `<div class="error">${msg}</div>`);
}

// --- J2: an ok reply ----------------------------------------------------------------------------------------
{
    const msg = 'SMS sendt til: &lt;b&gt;1&lt;/b&gt;<br/>SMS feilet for: 91000002';
    const {state} = press(respond({status: 'ok', message: msg}));
    same('J2 the message is shown in the good class, byte for byte', lastReceipt(state), `<div class="msg_good">${msg}</div>`);
    same('J2 nothing is scheduled to wipe it', state.timers.length, 0);
    same('J2 it is written once', state.receipt.length, 1);
}

// --- J3: a reply with nothing to show -----------------------------------------------------------------------
for (const [what, data] of [['false (SMS disabled)', false], ['null', null], ['{}', {}], ['ok without a message', {status: 'ok'}], ['ok with an empty message', {status: 'ok', message: ''}], ['error without a message', {status: 'error'}]]) {
    const {state} = press(respond(data));
    same(`J3 [${what}] the generic text, in the error class`, lastReceipt(state), `<div class="error">${GENERIC}</div>`);
    check(`J3 [${what}] never «undefined»`, !lastReceipt(state).includes('undefined'));
    same(`J3 [${what}] nothing is scheduled`, state.timers.length, 0);
}

// --- J4: the ajax error callback ---------------------------------------------------------------------------------
{
    const page = press();
    check('J4 the page registers an error callback', typeof page.opts?.error === 'function');
    if (typeof page.opts?.error === 'function') {
        page.opts.error({status: 200, responseText: '<b>Fatal error</b>'}, 'parsererror', new SyntaxError('Unexpected token <'));
        same('J4 a reply that is not JSON shows the generic text, in the error class', lastReceipt(page.state), `<div class="error">${GENERIC}</div>`);
        same('J4 ... and nothing is scheduled', page.state.timers.length, 0);
        page.opts.error({status: 500, responseText: ''}, 'error', 'Internal Server Error');
        same('J4 a 500 shows it too', lastReceipt(page.state), `<div class="error">${GENERIC}</div>`);
    }
}

// --- J6: source patterns ---------------------------------------------------------------------------------------------
{
    const xsl = readFileSync(join(root, 'src/modules/booking/templates/base/event_edit.xsl'), 'utf8');
    const jsLang = (token) => new RegExp(`php:function\\('js_lang',[^)]*'${token}'[^)]*\\)`).test(xsl);
    check('J6 event_edit.xsl asks js_lang for sms_send_failed, so lang[\'sms_send_failed\'] exists on the page', jsLang('sms_send_failed'));
    check('J6 control: the same pattern does NOT match a token that is not there', !jsLang('sms_send_failed_x'));
    check('J6 control: ... and still matches a token that is (the pattern is not blind)', jsLang('send sms'));

    const hasKey = (lang, key) => {
        const text = readFileSync(join(root, `src/modules/booking/setup/phpgw_${lang}.lang`), 'utf8');
        return text.split('\n').filter((line) => line.startsWith(`${key}\tbooking\t${lang}\t`) && line.split('\t')[3]?.trim()).length;
    };
    for (const lang of ['no', 'nn', 'en']) {
        for (const key of ['sms_send_failed', 'sms_event_no_participants', 'sms_not_configured']) {
            same(`J6 ${key} has exactly one non-empty row in phpgw_${lang}.lang`, hasKey(lang, key), 1);
        }
        same(`J6 control: a key that does not exist has none in phpgw_${lang}.lang`, hasKey(lang, 'sms_send_failed_x'), 0);
    }
}

// --- J7: the up-front markup is the post-Send markup, and the page script holds no recipient rule (task 36828) -------
{
    const xsl = readFileSync(join(root, 'src/modules/booking/templates/base/event_edit.xsl'), 'utf8');
    const js = readFileSync(join(root, 'src/modules/booking/js/base/event.js'), 'utf8');

    // What the template puts in #sms_receipt up front, and the class it gives it.
    const up = /<div id="sms_receipt">\s*<xsl:if test="sms_no_recipients = 1">\s*<div class="([\w-]+)">\s*<xsl:value-of select="php:function\('lang', 'sms_event_no_participants'\)"\s*\/>/.exec(xsl);
    check('J7 event_edit.xsl shows sms_event_no_participants in #sms_receipt when sms_no_recipients = 1', up !== null);
    // What event.js puts there for the same answer after Send (the server's sms_event_no_participants is status error).
    const after = lastReceipt(press(respond({status: 'error', message: '[lang:sms_event_no_participants]'})).state);
    const afterClass = /^<div class="([\w-]+)">/.exec(after)?.[1];
    check('J7 control: event.js gives an error reply a class of its own', typeof afterClass === 'string' && afterClass.length > 0, after);
    same('J7 the class the template uses up front is the class event.js uses for the same message after Send', up?.[1], afterClass);
    check('J7 control: the pattern does not match a different key (it is not blind)',
        !/<xsl:value-of select="php:function\('lang', 'sms_event_no_participants_x'\)"/.test(xsl) && /php:function\('lang', 'sms_event_no_participants'\)/.test(xsl));

    // The disabled state is rendered by the server for the textarea AND the button, on the same flag.
    const flagged = (tag) => new RegExp(`<${tag}\\b[^>]*>\\s*<xsl:if test="sms_no_recipients = 1">\\s*<xsl:attribute name="disabled">disabled</xsl:attribute>`).test(xsl);
    check('J7 the textarea gets disabled on sms_no_recipients = 1', flagged('textarea'));
    check('J7 the send button gets disabled on sms_no_recipients = 1', flagged('button'));
    check('J7 control: the same pattern does NOT match an element that is not there', !flagged('select'));
    same('J7 the template tests the flag in exactly three places: the textarea, the message and the button', (xsl.match(/sms_no_recipients/g) ?? []).length, 3);

    // SendSms() posts the text and shows the reply. The recipient rule lives on the server, once.
    const from = js.indexOf('SendSms = function');
    const to = js.indexOf("$(window).on('load'");
    check('J7 the SendSms function was found in event.js', from > -1 && to > from);
    const body = js.slice(from, to);
    const code = body.replace(/\/\/[^\n]*/g, '').replace("'booking.uievent.send_sms_participants'", "''");
    check('J7 SendSms() in event.js holds no recipient rule (no trim, phone, recipient or participant)', !/\b(trim|phone|recipient|participant)/i.test(code), code.match(/\b(trim|phone|recipient|participant)\w*/i)?.[0]);
    check('J7 control: the same pattern DOES match the text before the comments and the menuaction are removed', /\b(trim|phone|recipient|participant)/i.test(body));
    check('J7 event.js does not disable the box itself (the server renders that)', !/disabled/.test(code));
}

// ---------------------------------------------------------------------------------------------------------------
if (failures.length) {
    for (const f of failures) {
        console.log(`FAIL ${f}`);
    }
    console.log(`Event SMS JS harness: FAIL ${failures.length} of ${checks} checks`);
    process.exit(1);
}
console.log(`Event SMS JS harness: PASS ${checks} checks on node ${process.version}`);
