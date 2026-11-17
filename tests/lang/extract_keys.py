#!/usr/bin/env python3
"""Extract every translation key the Next client references and diff against the SERVED lang payload.

Usage: extract_keys.py <client_src_dir> <lang_dir> <out_json>
Read-only. Writes only <out_json>.

Resolution model (mirrors i18next with default keySeparator '.', ignoreJSONStructure=true,
no nsSeparator hit because keys contain no ':' in the used set):
    t('bookingfrontend.foo') -> payload['bookingfrontend']['foo']   (NO fallback to common)
    t('foo')                 -> payload['foo']  -> only resolves if 'foo' is a MODULE name (never a string)
"""
import json, os, re, sys

SRC, LANGDIR, OUT = sys.argv[1], sys.argv[2], sys.argv[3]
LANGS = ['no', 'nn', 'en']

payload = {l: json.load(open(os.path.join(LANGDIR, f'{l}.json'))) for l in LANGS}
MODULES = set(payload['no'].keys()) | set(payload['nn'].keys()) | set(payload['en'].keys())


def resolve(lang, key):
    """Return (status, value). status in FOUND / EMPTY / MISSING."""
    d = payload[lang]
    first, _, rest = key.partition('.')
    if first in d and isinstance(d[first], dict) and rest:
        v = d[first].get(rest)
        if v is None:
            return 'MISSING', None
        if isinstance(v, str):
            return ('EMPTY', v) if v.strip() == '' else ('FOUND', v)
        return 'MISSING', None
    return 'MISSING', None


def ci_lookup(lang, key):
    """Case-insensitive hit inside the SAME module (detects case-mismatch)."""
    first, _, rest = key.partition('.')
    d = payload[lang].get(first)
    if not isinstance(d, dict):
        return None
    for k in d:
        if k.lower() == rest.lower() and k != rest:
            return k
    return None


def other_modules(lang, key):
    """Where else does the bare key exist (wrong module prefix)?"""
    first, _, rest = key.partition('.')
    if first not in MODULES:
        rest = key
    hits = []
    for m, d in payload[lang].items():
        if isinstance(d, dict) and rest in d and m != first:
            hits.append(m)
    return hits


# ---- collect source -------------------------------------------------------------------------
files = []
for root, _, names in os.walk(SRC):
    if 'node_modules' in root or '/.next' in root:
        continue
    for n in names:
        if n.endswith(('.ts', '.tsx')):
            files.append(os.path.join(root, n))
files.sort()

CALL_RE = re.compile(r'(?<![\w.$])(?:(?:i18n\.)?t|tInstance|translate)\s*\(', re.S)
TRANS_RE = re.compile(r'<Trans\b[^>]*?\bi18nKey\s*=\s*(?:"([^"]+)"|\{\s*[\'"`]([^\'"`]+)[\'"`]\s*\})', re.S)
LIT_RE = re.compile(r"""(['"])((?:bookingfrontend|common|booking|login|preferences|home|admin|messenger)\.[^'"\n]{1,120}?)\1""")
STR_RE = re.compile(r"""'((?:\\.|[^'\\\n])*)'|"((?:\\.|[^"\\\n])*)"|`((?:\\.|[^`\\])*)`""", re.S)


def line_of(text, pos):
    return text.count('\n', 0, pos) + 1


def balanced_arg(text, start):
    """text[start] is just after '('. Return the first-argument text (up to top-level ',' or ')')."""
    depth, i, n = 0, start, len(text)
    in_str = None
    while i < n:
        c = text[i]
        if in_str:
            if c == '\\':
                i += 2
                continue
            if c == in_str:
                in_str = None
        else:
            if c in '\'"`':
                in_str = c
            elif c in '([{':
                depth += 1
            elif c in ')]}':
                if depth == 0:
                    return text[start:i]
                depth -= 1
            elif c == ',' and depth == 0:
                return text[start:i]
        i += 1
    return text[start:]


def is_comment_line(text, pos):
    ls = text.rfind('\n', 0, pos) + 1
    return text[ls:pos].lstrip().startswith(('//', '*', '/*'))


records = []  # one per call site / literal
for f in files:
    rel = os.path.relpath(f, SRC)
    text = open(f, encoding='utf-8', errors='replace').read()
    # 1. t(...) calls
    for m in CALL_RE.finditer(text):
        if is_comment_line(text, m.start()):
            continue
        arg = balanced_arg(text, m.end()).strip()
        ln = line_of(text, m.start())
        lits = [(a or b or c) for a, b, c in STR_RE.findall(arg)]
        if re.fullmatch(r"""'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`[^`$]*`""", arg, re.S):
            records.append(dict(file=rel, line=ln, kind='literal', key=lits[0], arg=arg[:160]))
        elif arg.startswith('`') and '${' in arg:
            records.append(dict(file=rel, line=ln, kind='dynamic-template', key=None, arg=arg[:200], literals=lits))
        elif lits:
            records.append(dict(file=rel, line=ln, kind='expr-with-literals', key=None, arg=arg[:200], literals=lits))
        else:
            records.append(dict(file=rel, line=ln, kind='dynamic-var', key=None, arg=arg[:200], literals=[]))
    # 2. <Trans i18nKey>
    for m in TRANS_RE.finditer(text):
        k = m.group(1) or m.group(2)
        records.append(dict(file=rel, line=line_of(text, m.start()), kind='literal', key=k, arg='<Trans i18nKey>'))
    # 3. bare key-shaped literals ANYWHERE (constants / tables / props later passed to t())
    for m in LIT_RE.finditer(text):
        if is_comment_line(text, m.start()):
            continue
        records.append(dict(file=rel, line=line_of(text, m.start()), kind='key-shaped-literal', key=m.group(2), arg=m.group(0)))

# ---- resolve ---------------------------------------------------------------------------------
out = dict(records=[], summary={})
cache = {}
for r in records:
    keys = []
    if r['kind'] in ('literal', 'key-shaped-literal'):
        keys = [r['key']]
    elif r['kind'] == 'expr-with-literals':
        keys = [x for x in r['literals'] if re.fullmatch(r"[A-Za-z0-9_.%\- ]+", x) and not x.startswith(' ')]
    r['resolved'] = {}
    for k in keys:
        if k not in cache:
            res = {}
            for l in LANGS:
                st, val = resolve(l, k)
                res[l] = dict(status=st, value=val, ci=ci_lookup(l, k) if st == 'MISSING' else None,
                              elsewhere=other_modules(l, k) if st == 'MISSING' else [])
            cache[k] = res
        r['resolved'][k] = cache[k]
    out['records'].append(r)

# per-key rollup
by_key = {}
for r in out['records']:
    for k, res in r['resolved'].items():
        e = by_key.setdefault(k, dict(key=k, sites=[], res=res))
        e['sites'].append(f"{r['file']}:{r['line']}[{r['kind']}]")
out['by_key'] = by_key

missing_any = {k: e for k, e in by_key.items() if any(e['res'][l]['status'] != 'FOUND' for l in LANGS)}
out['summary'] = dict(
    files_scanned=len(files),
    call_sites=len(out['records']),
    kinds={k: sum(1 for r in out['records'] if r['kind'] == k) for k in sorted({r['kind'] for r in out['records']})},
    distinct_keys=len(by_key),
    distinct_missing_any_lang=len(missing_any),
    modules_in_payload=sorted(MODULES),
)
json.dump(out, open(OUT, 'w'), indent=1, ensure_ascii=False)
print(json.dumps(out['summary'], indent=1))
