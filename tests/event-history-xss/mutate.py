#!/usr/bin/env python3
"""
Mutation check for the event-history XSS harness (run.php, task 36226).

Same idea as tests/event-sms-participants/mutate.py: one deliberate defect at a time, applied to a TEMP COPY of
the files the harness reads (never to the tree), and run.php must go red for each. The baseline on the same copy
must be green first, and every replacement must match exactly once. A mutation counts as caught only when the
harness exits 1 with a FAIL line: any other exit (a crash, a fatal error, a run that does not finish within
MUTATE_TIMEOUT seconds) is reported as CRASH and fails the run, because a harness that dies or hangs has not
looked at anything.

Every mutation runs HERMETIC first (no database). When HD_DB_HEADER is set the corpus arm K is run too, and the
mutations marked K must make a «FAIL K » line appear: that is what proves the corpus counts can fail. Without
HD_DB_HEADER the corpus part is NOT RUN and the script says so and exits 2 (a silent skip would read as green);
pass --no-corpus to accept that explicitly.

Usage:  python3 tests/event-history-xss/mutate.py
        HD_DB_HEADER=…/config/header.inc.php HD_DB_HOST=127.0.0.1 HD_DB_PORT=5435 python3 tests/event-history-xss/mutate.py
        (PHP_BIN overrides the binary)
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
PHP = os.environ.get("PHP_BIN", "php")
TIMEOUT = int(os.environ.get("MUTATE_TIMEOUT", "180"))  # seconds per harness run (a run takes under a minute, with the corpus arm)

# Everything the harness reads from HD_ROOT.
FILES = [
    "src/Database/Db.php",
    "src/modules/phpgwapi/inc/class.xmlhelper.inc.php",
    "src/modules/booking/inc/class.soevent.inc.php",
    "src/modules/booking/inc/class.uievent.inc.php",
    "src/modules/booking/templates/base/event_edit.xsl",
    "src/modules/booking/helpers/CommentParts.php",
]

SO = "src/modules/booking/inc/class.soevent.inc.php"
U = "src/modules/booking/inc/class.uievent.inc.php"
X = "src/modules/booking/templates/base/event_edit.xsl"
H = "src/modules/booking/helpers/CommentParts.php"

# the one statement of CommentParts::replaceNonCharacterReferences() that N16 and N17 replace as a whole
CALLBACK = ("\t\treturn (string)preg_replace_callback('/&#[^&;]*;/', static function ( array $reference ): string\n\t\t{\n"
            "\t\t\treturn in_array(html_entity_decode($reference[0]), array(\"\\u{FFFE}\", \"\\u{FFFF}\"), true) ? \"\\u{FFFD}\" : $reference[0];\n"
            "\t\t}, $value);\n")

# the statement of the same function that replaces the characters U+FFFE and U+FFFF themselves (task 36487)
RAW = "\t\t$value = str_replace(array(\"\\u{FFFE}\", \"\\u{FFFF}\"), \"\\u{FFFD}\", $value);\n"

R = re.compile  # a regex mutation: (pattern, replacement), the pattern must match exactly once


class Multi:
    """Several exact replacements in one file, applied in order; every one must match exactly once."""

    def __init__(self, *pairs):
        self.pairs = pairs


M = Multi  # M((old, new), (old, new)): `old` of a mutation, `new` is then ignored

# (id, file, description, old, new, k)   old: str (exact, once) or R(pattern); k: the corpus arm must catch it too
MUTATIONS = [
    # --- the stylesheet ---------------------------------------------------------------------------------
    ("X1", X, "d-o-e on the text branch of the parts",
     R(r'(<xsl:otherwise>\s*<xsl:value-of select="v")(/>)'), r'\1 disable-output-escaping="yes"\2', False),
    ("X2", X, "d-o-e on the link text",
     R(r'(<a href="\{href\}" rel="noopener noreferrer">\s*<xsl:value-of select="v")(/>)'), r'\1 disable-output-escaping="yes"\2', False),
    ("X3", X, "the raw `comment` is printed again, with d-o-e, beside the parts",
     '<xsl:for-each select="parts">', '<xsl:value-of select="comment" disable-output-escaping="yes"/><xsl:for-each select="parts">', False),
    ("X4", X, "the link loses rel=noopener noreferrer",
     ' rel="noopener noreferrer"', '', False),
    ("X5", X, "a break prints nothing",
     R(r"(<xsl:when test=\"k = 'br'\">)\s*<br/>"), r'\1', False),
    ("X6", X, "the link text is not printed",
     R(r'(<a href="\{href\}" rel="noopener noreferrer">)\s*<xsl:value-of select="v"/>'), r'\1', True),
    ("X7", X, "the href is taken from the link text",
     '<a href="{href}"', '<a href="{v}"', False),
    ("X8", X, "the link branch is gone: links print as text",
     "test=\"k = 'a'\"", "test=\"k = 'z'\"", False),
    ("X9", X, "d-o-e on the author of the history block",
     R(r'(<xsl:value-of select="author")(/>\s*</label>\s*<span>\s*<xsl:for-each select="parts">)'), r'\1 disable-output-escaping="yes"\2', False),
    ("X10", X, "a second d-o-e elsewhere in the file (the harness counts them)",
     '<xsl:value-of select="php:function(\'lang\', \'History and comments (%1)\', count(comments/author))" />',
     '<xsl:value-of select="php:function(\'lang\', \'History and comments (%1)\', count(comments/author))" disable-output-escaping="yes"/>', False),
    # --- edit() ----------------------------------------------------------------------------------------
    ("U1", U, "the raw `comment` is still emitted (no unset)",
     "\t\t\tunset($comment['comment']);\n", "", False),
    ("U2", U, "the parts are not set: a history row loses its text",
     "\t\t\t$comment['parts'] = CommentParts::parse($comment['comment']);\n", "", True),
    ("U3", U, "the host rewrite is lost before the parse",
     "$comment['comment'] = str_replace($external_site_address, $internal_site_address, $comment['comment']);",
     "$comment['comment'] = $comment['comment'];", False),
    ("U4", U, "edit() no longer imports the helper",
     "use App\\modules\\booking\\helpers\\CommentParts;\n", "", False),
    # --- the helper -------------------------------------------------------------------------------------
    ("H1", H, "any href scheme is accepted",
     "'#^https?://[^\\s\"<>]+$#i'", "'#^[a-z][a-z0-9+.-]*:[^\\s\"<>]+$#i'", False),
    ("H2", H, "control characters are not stripped from the href",
     "'/[\\x00-\\x1F\\x7F]+/'", "'/^$/'", False),
    ("H3", H, "script text is kept",
     "'script', 'style',", "'style',", False),
    ("H4", H, "style text is kept",
     "'script', 'style',", "'script',", False),
    ("H5", H, "svg is not dropped (its text is kept)",
     "'svg', 'math',", "'math',", False),
    ("H6", H, "nothing is dropped",
     "if (in_array($name, self::DROPPED, true))", "if (false)", False),
    ("H7", H, "unknown elements drop their text",
     "\t\t\t$before = count($parts);\n\t\t\tself::collect($child, $parts);\n", "\t\t\t$before = count($parts);\n", True),
    ("H8", H, "an <a> without a usable href loses its words",
     "\t\t\t\t// no usable link: the words stay\n\t\t\t\tself::collect($child, $parts);\n", "", False),
    ("H20", H, "the link keeps its href but its text is dropped (the declared drop_link_text arm)",
     "'v' => self::plainText($child)", "'v' => ''", True),
    ("H9", H, "the stored string is not entity-decoded once",
     "$text = html_entity_decode($stored, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');", "$text = $stored;", False),
    ("H10", H, "the stored string is entity-decoded twice",
     "$text = html_entity_decode($stored, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');",
     "$text = html_entity_decode(html_entity_decode($stored, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');", False),
    ("H11", H, "<p> ends in no line break",
     "if ($name === 'p' && count($parts) > $before)", "if (false)", False),
    ("H12", H, "a line break becomes a blank",
     "if ($name === 'br')\n\t\t\t{\n\t\t\t\t$parts[] = array('k' => 'br');", "if ($name === 'br')\n\t\t\t{\n\t\t\t\t$parts[] = array('k' => 't', 'v' => ' ');", False),
    ("H13", H, "the link text is the element's textContent (script text inside the link is kept)",
     "'v' => self::plainText($child)", "'v' => $child->textContent", False),
    ("H14", H, "the link carries the raw href, not the stripped one",
     "$parts[] = array('k' => 'a', 'href' => $href,", "$parts[] = array('k' => 'a', 'href' => $child->getAttribute('href'),", False),
    ("H15", H, "characters XML cannot hold are kept",
     "'/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]|\\xEF\\xBF[\\xBE\\xBF]/'", "'/^$/'", False),
    ("H16", H, "the href check is not anchored at the start",
     "'#^https?://[^\\s\"<>]+$#i'", "'#https?://[^\\s\"<>]+$#i'", False),
    ("H17", H, "the href check is not anchored at the end",
     "'#^https?://[^\\s\"<>]+$#i'", "'#^https?://[^\\s\"<>]+#i'", False),
    ("H18", H, "https links are no longer accepted (http only)",
     "'#^https?://[^\\s\"<>]+$#i'", "'#^http://[^\\s\"<>]+$#i'", False),
    # --- the apostrophe (add_single_comment) ----------------------------------------------------------
    ("A1", SO, "db_addslashes is back on the comment (It's is stored as It''s)",
     "\t\t\t\t\t'value'\t=> $comment,\n", "\t\t\t\t\t'value'\t=> $this->db->db_addslashes($comment),\n", False),
    ("A2", SO, "the comment is bound as an integer",
     "'value'\t=> $comment,\n\t\t\t\t\t'type'\t=> PDO::PARAM_STR", "'value'\t=> $comment,\n\t\t\t\t\t'type'\t=> PDO::PARAM_INT", False),
    ("A3", SO, "the comment is HTML-escaped on the way in",
     "\t\t\t\t\t'value'\t=> $comment,\n", "\t\t\t\t\t'value'\t=> htmlspecialchars($comment, ENT_QUOTES),\n", False),
    ("A4", SO, "the comment is trimmed on the way in",
     "\t\t\t\t\t'value'\t=> $comment,\n", "\t\t\t\t\t'value'\t=> trim($comment),\n", False),
    ("A5", SO, "the apostrophe is doubled by hand",
     "\t\t\t\t\t'value'\t=> $comment,\n", "\t\t\t\t\t'value'\t=> str_replace(\"'\", \"''\", $comment),\n", False),
    ("A6", SO, "the comment slot carries the type",
     "\t\t\t\t\t'value'\t=> $comment,\n", "\t\t\t\t\t'value'\t=> $type,\n", False),
    ("A7", SO, "the INSERT names its columns in another order",
     "INSERT INTO bb_event_comment (event_id, time, author, comment, type)", "INSERT INTO bb_event_comment (event_id, time, author, type, comment)", False),
    # --- the follow-up (task 36276): U+FFFE / U+FFFF; the references are judged by html_entity_decode (task 36448) ----
    ("N1", H, "the references to U+FFFE and U+FFFF are not replaced at all",
     "? \"\\u{FFFD}\" : $reference[0];", "? $reference[0] : $reference[0];", False),
    ("N2", H, "removed instead of replaced: the pieces around a reference join into a new one («&#x&#xFFFE;FFFE;»)",
     "? \"\\u{FFFD}\" : $reference[0];", "? '' : $reference[0];", False),
    ("N16", H, "removed and repeated until none is left (the first version): rebuilt references go too, but one pass per level makes it quadratic",
     CALLBACK,
     "\t\t$removed = 0;\n\t\tdo\n\t\t{\n\t\t\t$removed = 0;\n"
     "\t\t\t$value = (string)preg_replace_callback('/&#[^&;]*;/', static function ( array $reference ) use ( &$removed ): string\n\t\t\t{\n"
     "\t\t\t\tif (in_array(html_entity_decode($reference[0]), array(\"\\u{FFFE}\", \"\\u{FFFF}\"), true))\n\t\t\t\t{\n\t\t\t\t\t$removed++;\n\t\t\t\t\treturn '';\n\t\t\t\t}\n"
     "\t\t\t\treturn $reference[0];\n\t\t\t}, $value);\n\t\t}\n\t\twhile ($removed > 0);\n\t\treturn $value;\n", False),
    ("N3", H, "hexadecimal references only (a decimal one is not a candidate)",
     "'/&#[^&;]*;/'", "'/&#[xX][^&;]*;/'", False),
    ("N4", H, "decimal references only (a hexadecimal one is not a candidate)",
     "'/&#[^&;]*;/'", "'/&#[0-9][^&;]*;/'", False),
    ("N5", H, "U+FFFE only (U+FFFF stays)",
     "array(\"\\u{FFFE}\", \"\\u{FFFF}\"), true)", "array(\"\\u{FFFE}\"), true)", False),
    ("N6", H, "a leading zero ends the candidate (no leading zeros allowed)",
     "'/&#[^&;]*;/'", "'/&#[xX]?[1-9a-fA-F][^&;]*;/'", False),
    ("N7", H, "a capital X is not a candidate (case-sensitive)",
     "'/&#[^&;]*;/'", "'/&#[^&;X]*;/'", False),
    ("N8", H, "too greedy: U+FFFC and U+FFFD are replaced too",
     "array(\"\\u{FFFE}\", \"\\u{FFFF}\"), true)", "array(\"\\u{FFFC}\", \"\\u{FFFD}\", \"\\u{FFFE}\", \"\\u{FFFF}\"), true)", False),
    ("N8e", H, "U+FFFD is replaced too (by U+FFFD, the character toXML would make of it: harmless, so the harness must NOT notice; equivalent mutant control)",
     "array(\"\\u{FFFE}\", \"\\u{FFFF}\"), true)", "array(\"\\u{FFFD}\", \"\\u{FFFE}\", \"\\u{FFFF}\"), true)", False),
    ("N9", H, "too greedy: any code point that ends in FFFE or FFFF goes (U+1FFFE, U+FFFFE ...)",
     "in_array(html_entity_decode($reference[0]), array(\"\\u{FFFE}\", \"\\u{FFFF}\"), true)", "(mb_ord(html_entity_decode($reference[0]), 'UTF-8') & 0xFFFF) >= 0xFFFE", False),
    # --- the strtol «0x» forms (task 36448) ---------------------------------------------------------------
    ("N17", H, "the old spelling pattern is back: «&#x0xFFFE;» (a «0x» after the «x», which strtol takes) is missed",
     CALLBACK, "\t\treturn (string)preg_replace('/&#(?:x0*fff[ef]|0*6553[45]);/i', \"\\u{FFFD}\", $value);\n", False),
    ("N18", H, "only plain digits after the x are a candidate (a «0x» stretch is none)",
     "'/&#[^&;]*;/'", "'/&#[xX]?[0-9A-Fa-f]+;/'", False),
    ("N19", H, "judged with ENT_HTML5, which refuses these two characters: the decoder toXML calls does not",
     "html_entity_decode($reference[0]), array(", "html_entity_decode($reference[0], ENT_QUOTES | ENT_HTML5), array(", False),
    ("N20", H, "a candidate may hold an «&»: «&#x&#xFFFE;» is judged as a whole and is not one character",
     "'/&#[^&;]*;/'", "'/&#[^;]*;/'", False),
    ("N10", H, "the v of the parts is not cleaned (the href still is)",
     "\t\t\t\t$part['v'] = self::replaceNonCharacterReferences($part['v']);\n", "\t\t\t\t$part['v'] = $part['v'];\n", False),
    ("N11", H, "the href is not cleaned (the v still is)",
     "trim(self::replaceNonCharacterReferences((string)preg_replace('/[\\x00-\\x1F\\x7F]+/', '', $raw)))",
     "trim((string)preg_replace('/[\\x00-\\x1F\\x7F]+/', '', $raw))", False),
    ("N12", H, "the href is cleaned before its control characters are stripped (a tab inside a reference hides it)",
     "trim(self::replaceNonCharacterReferences((string)preg_replace('/[\\x00-\\x1F\\x7F]+/', '', $raw)))",
     "trim((string)preg_replace('/[\\x00-\\x1F\\x7F]+/', '', self::replaceNonCharacterReferences($raw)))", False),
    ("N13", H, "cleaned before the HTML step instead of after it (the old place of the strip)",
     M(("\t\t$text = (string)preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]|\\xEF\\xBF[\\xBE\\xBF]/', '', mb_scrub($text, 'UTF-8'));\n",
        "\t\t$text = (string)preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]|\\xEF\\xBF[\\xBE\\xBF]/', '', mb_scrub($text, 'UTF-8'));\n\t\t$text = self::replaceNonCharacterReferences($text);\n"),
       ("\t\t\t\t$part['v'] = self::replaceNonCharacterReferences($part['v']);\n", "\t\t\t\t$part['v'] = $part['v'];\n"),
       ("trim(self::replaceNonCharacterReferences((string)preg_replace('/[\\x00-\\x1F\\x7F]+/', '', $raw)))",
        "trim((string)preg_replace('/[\\x00-\\x1F\\x7F]+/', '', $raw))")), None, False),
    ("N14", H, "cleaned per text node, before the pieces are joined (a comment, tag or dropped script between «&#x» and «FFFE;» hides a reference)",
     M(("\t\t\t\tself::addText($parts, (string)$child->nodeValue);\n", "\t\t\t\tself::addText($parts, self::replaceNonCharacterReferences((string)$child->nodeValue));\n"),
       ("\t\t\t\t$part['v'] = self::replaceNonCharacterReferences($part['v']);\n", "\t\t\t\t$part['v'] = $part['v'];\n")), None, False),
    ("N15", H, "the link text is cleaned per text node, before plainText joins the pieces",
     M(("\t\t\t\t$text .= $child->nodeValue;\n", "\t\t\t\t$text .= self::replaceNonCharacterReferences((string)$child->nodeValue);\n"),
       ("\t\t\t\t$part['v'] = self::replaceNonCharacterReferences($part['v']);\n", "\t\t\t\t$part['v'] = $part['v'];\n")), None, False),
    # --- the characters themselves, made by the HTML step (a <meta charset="utf-7"> and «+//4-»; task 36487) ----------
    ("N21", H, "the characters U+FFFE and U+FFFF themselves are not replaced (the references still are): an href that a UTF-7 meta gave one fails the page's load",
     RAW, "", False),
    ("N22", H, "U+FFFE only (a raw U+FFFF stays)",
     RAW, RAW.replace("array(\"\\u{FFFE}\", \"\\u{FFFF}\")", "\"\\u{FFFE}\""), False),
    ("N23", H, "U+FFFF only (a raw U+FFFE stays)",
     RAW, RAW.replace("array(\"\\u{FFFE}\", \"\\u{FFFF}\")", "\"\\u{FFFF}\""), False),
    ("N24", H, "removed instead of replaced (the href loses the character instead of holding U+FFFD)",
     RAW, RAW.replace("\"\\u{FFFD}\", $value", "'', $value"), False),
    ("N21e", H, "the characters are replaced after the references are judged instead of before (the same result: a replaced character is neither part of a reference nor joins one; equivalent mutant control)",
     M((RAW, ""),
       (CALLBACK, CALLBACK.replace("return (string)preg_replace_callback(", "return str_replace(array(\"\\u{FFFE}\", \"\\u{FFFF}\"), \"\\u{FFFD}\", (string)preg_replace_callback(")
                          .replace("}, $value);\n", "}, $value));\n"))), None, False),
    ("H19", H, "adjacent text is no longer merged (harmless to the text, so the harness must NOT notice; equivalent mutant control)",
     "\t\tif ($last >= 0 && $parts[$last]['k'] === 't')\n", "\t\tif (false)\n", False),
]

# H19, N8e and N21e are the controls for the harness's own sensitivity: merging is a size optimisation and the visible text
# is the same (H19); replacing a reference to U+FFFD by U+FFFD changes nothing a reader or the page could tell (N8e); the
# order of the two steps of replaceNonCharacterReferences() changes nothing when the characters are replaced (N21e).
EQUIVALENT = {"H19", "N8e", "N21e"}


def run_harness(tree, corpus):
    env = dict(os.environ)
    env["HD_ROOT"] = tree
    env["XDEBUG_MODE"] = "off"
    if not corpus:
        env.pop("HD_DB_HEADER", None)
    try:
        p = subprocess.run([PHP, os.path.join(HERE, "run.php")], env=env, capture_output=True, text=True, timeout=TIMEOUT)
    except subprocess.TimeoutExpired:
        # a mutant that makes the code loop has not been looked at by anything: a CRASH, never a catch
        return 124, f"TIMEOUT after {TIMEOUT} s", ""
    out = (p.stdout + p.stderr).strip().splitlines()
    return p.returncode, (out[-1] if out else ""), p.stdout


def apply(original, old, new):
    if isinstance(old, Multi):
        text = original
        for o, nw in old.pairs:
            c = text.count(o)
            if c != 1:
                return c, original
            text = text.replace(o, nw, 1)
        return 1, text
    if isinstance(old, str):
        n = original.count(old)
        return n, original.replace(old, new, 1)
    mutated, n = old.subn(new, original)
    return n, mutated


def main():
    no_corpus = "--no-corpus" in sys.argv
    have_db = bool(os.environ.get("HD_DB_HEADER"))
    tmp = tempfile.mkdtemp(prefix="event-history-mut-")
    try:
        tree = os.path.join(tmp, "tree")
        for rel in FILES:
            dst = os.path.join(tree, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copyfile(os.path.join(ROOT, rel), dst)

        rc, tail, _ = run_harness(tree, False)
        print(f"baseline hermetic rc={rc}  {tail}")
        if rc != 0:
            print("BASELINE IS RED - mutation results would mean nothing")
            return 1
        if have_db:
            rc, tail, _ = run_harness(tree, True)
            print(f"baseline corpus   rc={rc}  {tail}")
            if rc != 0:
                print("CORPUS BASELINE IS RED - mutation results would mean nothing")
                return 1

        survived, crashed, k_missed = [], [], []
        for mid, rel, desc, old, new, k in MUTATIONS:
            path = os.path.join(tree, rel)
            original = open(path, encoding="utf-8").read()
            n, mutated = apply(original, old, new)
            if n != 1:
                print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times (need exactly 1): {desc}")
                return 1
            open(path, "w", encoding="utf-8").write(mutated)
            try:
                rc, tail, stdout = run_harness(tree, False)
                if have_db:
                    krc, ktail, kstdout = run_harness(tree, True)
            finally:
                open(path, "w", encoding="utf-8").write(original)
            m = re.search(r"FAIL (\d+) of (\d+)", tail)
            if mid in EQUIVALENT:
                if rc == 0:
                    print(f"{mid:4} equivalent, harness stays green as it must  {desc}")
                else:
                    print(f"{mid:4} EQUIVALENT MUTANT TURNED THE HARNESS RED rc={rc}  {tail[:100]}  {desc}")
                    survived.append(mid)
                continue
            if rc == 1 and m and "FAIL " in stdout:
                line = f"{mid:4} caught   rc={rc}  {m.group(1):>3} of {m.group(2)} checks fail"
            elif rc == 0:
                print(f"{mid:4} SURVIVED rc={rc}  {desc}")
                survived.append(mid)
                continue
            else:
                print(f"{mid:4} CRASH    rc={rc}  {tail[:120]}  {desc}")
                crashed.append(mid)
                continue
            if have_db:
                kfails = len(re.findall(r"^FAIL K ", kstdout, re.M))
                if k:
                    if krc == 1 and kfails:
                        line += f"  | corpus: {kfails} K checks fail"
                    else:
                        line += "  | corpus: K DID NOT FAIL"
                        k_missed.append(mid)
                else:
                    line += f"  | corpus: {kfails} K fail"
            print(f"{line}  {desc}")

        if survived or crashed or k_missed:
            if survived:
                print("SURVIVING MUTATIONS:", ", ".join(survived))
            if crashed:
                print("CRASHED (harness died instead of failing a check):", ", ".join(crashed))
            if k_missed:
                print("THE CORPUS ARM DID NOT CATCH:", ", ".join(k_missed))
            return 1
        real = len(MUTATIONS) - len(EQUIVALENT)
        if not have_db:
            print(f"all {real} mutations caught by the hermetic harness, 0 crashes. THE CORPUS ARM WAS NOT RUN (no HD_DB_HEADER): "
                  "the K-marked mutations are unproven against the corpus.")
            return 0 if no_corpus else 2
        print(f"all {real} mutations caught (the K-marked ones by the corpus arm too), 0 crashes; {len(EQUIVALENT)} equivalent-mutant control stays green; both baselines green")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
