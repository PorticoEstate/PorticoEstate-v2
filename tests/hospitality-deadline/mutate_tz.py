#!/usr/bin/env python3
"""
Mutation check for the hospitality timestamp harness (run_tz.php, GH #1423 / #1425).

Same idea as mutate_mail.py: one deliberate defect at a time, applied to a TEMP COPY of the files the
harness loads (never to the tree), and the harness must go red for each. The baseline on the same copy
must be green first, and every replacement must match exactly once.

It needs the same Postgres as run_tz.php (HD_PG_DSN, HD_PG_USER, HD_PG_PASS) and prints SKIPPED when the
harness does. PHP_BIN overrides the binary.

The harness file itself is copied into that temp tree and run from there, so the arm guard (the refusal to
run in any database but the scratch one the master made) and the per-arm check counts are mutated like any
other code: Mz21-Mz29 take them away one at a time. With a guard missing the arms run in decoy databases
the master created, so no mutation can reach anything outside the harness.

One defect is NOT in the list because a sequential harness cannot see it: removing the table LOCK from the
migration. That is a concurrency property; it is listed at the end as an expected survivor, not hidden.

Usage:  python3 tests/hospitality-deadline/mutate_tz.py
"""
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
PHP = os.environ.get("PHP_BIN", "php")

# Everything run_tz.php loads from HD_ROOT.
FILES = [
    "src/Database/Db.php",
    "src/traits/SerializableTrait.php",
    "src/modules/booking/controllers/HospitalityController.php",
    "src/modules/booking/migrations/m20261027_hospitality_timestamps_to_utc.php",
    "src/modules/booking/models/Hospitality.php",
    "src/modules/booking/models/HospitalityOrder.php",
    "src/modules/booking/models/HospitalityOrderLine.php",
    "src/modules/booking/repositories/HospitalityOrderRepository.php",
    "src/modules/booking/repositories/HospitalityRepository.php",
    "src/modules/booking/services/HospitalityDeadlineCalculator.php",
    "src/modules/phpgwapi/services/Migration/Migration.php",
    "src/modules/phpgwapi/services/SchemaProc/SchemaProc.php",
    "tests/hospitality-deadline/run_tz.php",
]

H = "src/modules/booking/repositories/HospitalityRepository.php"
O = "src/modules/booking/repositories/HospitalityOrderRepository.php"
M = "src/modules/booking/migrations/m20261027_hospitality_timestamps_to_utc.php"
HM = "src/modules/booking/models/Hospitality.php"
C = "src/modules/booking/controllers/HospitalityController.php"
T = "tests/hospitality-deadline/run_tz.php"

# (id, file, description, old, new)
MUTATIONS = [
    # --- writers: any of them back on an explicit NOW() disagrees with a UTC default ---------------------
    ("Mz1", H, "Hospitality update() writes modified with NOW() again",
     '$updates[] = "modified = DEFAULT";', '$updates[] = "modified = NOW()";'),
    ("Mz2", H, "Hospitality delete() writes modified with NOW() again",
     "UPDATE bb_hospitality SET active = 0, modified = DEFAULT WHERE id = :id", "UPDATE bb_hospitality SET active = 0, modified = NOW() WHERE id = :id"),
    ("Mz3", O, "order update() writes modified with NOW() again",
     '$updates[] = "modified = DEFAULT";', '$updates[] = "modified = NOW()";'),
    ("Mz4", O, "order updateStatus() (and so delete()) writes modified with NOW() again",
     "SET status = :status, modified = DEFAULT, modified_by = :modified_by", "SET status = :status, modified = NOW(), modified_by = :modified_by"),

    # --- the migration -------------------------------------------------------------------------------------
    ("Mz5", M, "the migration converts the rows but never switches the DEFAULT",
     "\t\t\t$this->sql(\"ALTER TABLE {$table} \" . implode(', ', $alter));", "\t\t\t$alter = $alter;"),
    ("Mz6", M, "the conversion is applied the wrong way round (UTC read as the session zone)",
     "$set[] = \"{$column} = ({$column} AT TIME ZONE current_setting('TimeZone')) AT TIME ZONE 'UTC'\";",
     "$set[] = \"{$column} = ({$column} AT TIME ZONE 'UTC') AT TIME ZONE current_setting('TimeZone')\";"),
    ("Mz7", M, "no marker: the state of the defaults is not read first, so a second run shifts the rows again",
     "\t\tif ($this->defaultsState() === 'utc') {\n\t\t\terror_log('[hospitality UTC migration] all five defaults are already UTC: nothing to do');\n\t\t\treturn;\n\t\t}\n", ""),
    ("Mz8", M, "the zone-source guard is gone: a zone from the client or the session is trusted",
     "if (!in_array($source, self::SERVER_SIDE_SOURCES, true)) {", "if (false) {"),
    ("Mz9", M, "half-done defaults are treated as the original ones (converted anyway)",
     "if (count($legacy) === count($exprs)) {", "if (count($legacy) > 0) {"),
    ("Mz10", M, "an unrecognised default spelling (localtimestamp) is accepted as an original one",
     "private const LEGACY_DEFAULTS = ['now()', 'CURRENT_TIMESTAMP'];", "private const LEGACY_DEFAULTS = ['now()', 'CURRENT_TIMESTAMP', 'LOCALTIMESTAMP'];"),
    ("Mz11", M, "the migration stops checking where the zone came from but only logs it (no abort on 'client')",
     "\t\t\t$this->fail(\"the session TimeZone '{$zone}' comes from '{$source}', not from the server:", "\t\t\terror_log(\"the session TimeZone '{$zone}' comes from '{$source}', not from the server:"),

    # --- readers -------------------------------------------------------------------------------------------
    ("Mz12", HM, "Hospitality.created loses its @Timestamp (the admin page would read raw naive UTC as local time)",
     "     * @OA\\Property(type=\"string\", format=\"date-time\")\n     * @Timestamp(format=\"c\", sourceTimezone=\"UTC\")\n     * @Expose\n     */\n    public $created;",
     "     * @OA\\Property(type=\"string\", format=\"date-time\")\n     * @Expose\n     */\n    public $created;"),
    ("Mz13", HM, "Hospitality.modified loses its @Timestamp",
     "     * @OA\\Property(type=\"string\", format=\"date-time\")\n     * @Timestamp(format=\"c\", sourceTimezone=\"UTC\")\n     * @Expose\n     */\n    public $modified;",
     "     * @OA\\Property(type=\"string\", format=\"date-time\")\n     * @Expose\n     */\n    public $modified;"),
    ("Mz14", HM, "Hospitality.created is read as Oslo time instead of UTC",
     "     * @Timestamp(format=\"c\", sourceTimezone=\"UTC\")\n     * @Expose\n     */\n    public $created;",
     "     * @Timestamp(format=\"c\", sourceTimezone=\"Europe/Oslo\")\n     * @Expose\n     */\n    public $created;"),
    ("Mz15", O, "the order page's changelog no longer converts changed_at",
     "            $row['changed_at'] = self::utcToVenueIso($row['changed_at']);\n", ""),
    ("Mz16", O, "the Endret av soker marker no longer converts changed_at",
     "$out[(int)$row['order_id']] = self::utcToVenueIso($row['changed_at']);", "$out[(int)$row['order_id']] = $row['changed_at'];"),
    ("Mz17", O, "utcToVenueIso reads the stored value as Oslo time instead of UTC",
     "return (new \\DateTimeImmutable($naiveUtc, new \\DateTimeZone('UTC')))", "return (new \\DateTimeImmutable($naiveUtc, new \\DateTimeZone('Europe/Oslo')))"),

    # --- the conflict check ----------------------------------------------------------------------------------
    ("Mz18", C, "the stored modified is parsed in PHP's default zone, not explicitly as UTC",
     "$serverTs = (new \\DateTimeImmutable($storedModified, $utc))->getTimestamp();", "$serverTs = strtotime($storedModified);"),
    ("Mz19", C, "an equal version counts as stale (< becomes <=)",
     "return $clientTs < $serverTs;", "return $clientTs <= $serverTs;"),

    # --- the harness itself: the arm guard (arms DROP TABLE ... CASCADE on application table names) ---------
    ("Mz21", T, "the arm no longer checks that the database is NAMED like a scratch one",
     "    if (preg_match(HD_SCRATCH_DB, $current) !== 1) {", "    if (false) {"),
    ("Mz22", T, "the arm no longer compares HD_ARM_DB (the master's name for it) with the database it is in",
     "    if ($armDb !== $current) {", "    if (false) {"),
    ("Mz23", T, "the arm no longer looks for tables the harness did not create (a second belt)",
     "    if ($foreign) {", "    if (false) {"),
    ("Mz24", T, "the refusal is computed but the arm runs on regardless (the guard, removed)",
     "    if ($refusal !== null) {", "    if (false) {"),
    ("Mz25", T, "a refusal exits 0 instead of 2",
     "Nothing was created, changed or dropped.\\n\");\n        return 2;", "Nothing was created, changed or dropped.\\n\");\n        return 0;"),
    ("Mz26", T, "the arm no longer insists on a Postgres connection before it asks for the database name",
     "    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {", "    if (false) {"),
    ("Mz27", T, "the scratch-name pattern accepts anything that starts with hd_tz_",
     "const HD_SCRATCH_DB = '/^hd_tz_(utc|oslo)_[0-9]+_[0-9a-f]{6}$/D';", "const HD_SCRATCH_DB = '/^hd_tz_/D';"),
    ("Mz28", T, "one check of an arm silently disappears (T4: '...and its modified'), so only the per-arm count can tell",
     "        $emit('T4 new code first: ...and its modified (written with DEFAULT)', $near('bb_hospitality_order', 'modified', $pre));\n", ""),
    ("Mz29", T, "the scratch-name pattern loses its end-of-string anchor: a trailing newline slips through",
     "[0-9a-f]{6}$/D';", "[0-9a-f]{6}$/';"),
]

# Not a failure of the harness: a property of what a sequential harness can see.
EXPECTED_SURVIVORS = [
    ("Mz20", M, "the table LOCK is removed from the migration (concurrent writers are not exercised by a sequential run)",
     "\t\t$this->sql('LOCK TABLE ' . implode(', ', array_keys(self::COLUMNS)) . ' IN ACCESS EXCLUSIVE MODE');\n", ""),
]


def run_harness(tree):
    env = dict(os.environ)
    env["HD_ROOT"] = tree
    # the copy of the harness inside the temp tree, not the one in the repo: some mutations are of the harness itself
    p = subprocess.run([PHP, "-d", "xdebug.mode=off", "-d", "display_errors=stderr", os.path.join(tree, T)],
                       env=env, capture_output=True, text=True)
    out = p.stdout.strip().splitlines()
    return p.returncode, (out[-1] if out else "")


def main():
    tmp = tempfile.mkdtemp(prefix="hd-muttz-")
    try:
        tree = os.path.join(tmp, "tree")
        for rel in FILES:
            dst = os.path.join(tree, rel)
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            shutil.copyfile(os.path.join(ROOT, rel), dst)

        rc, tail = run_harness(tree)
        print(f"baseline rc={rc}  {tail}")
        if rc == 3:
            print("SKIPPED: no database for the harness")
            return 3
        if rc != 0:
            print("BASELINE IS RED - mutation results would mean nothing")
            return 1

        survived = []
        for mid, rel, desc, old, new in MUTATIONS + EXPECTED_SURVIVORS:
            expected = any(mid == e[0] for e in EXPECTED_SURVIVORS)
            path = os.path.join(tree, rel)
            original = open(path, encoding="utf-8").read()
            n = original.count(old)
            if n != 1:
                print(f"{mid}: MUTATION DID NOT APPLY - pattern matched {n} times (need exactly 1): {desc}")
                return 1
            open(path, "w", encoding="utf-8").write(original.replace(old, new, 1))
            try:
                rc, tail = run_harness(tree)
            finally:
                open(path, "w", encoding="utf-8").write(original)
            caught = rc == 1
            label = "caught  " if caught else ("SURVIVED (expected)" if expected else "SURVIVED")
            print(f"{mid:5} {label} rc={rc}  {desc}")
            if not caught and not expected:
                survived.append(mid)
            if caught and expected:
                print(f"{mid}: was expected to survive but the harness caught it - update the list")

        if survived:
            print("SURVIVING MUTATIONS:", ", ".join(survived))
            return 1
        print(f"all {len(MUTATIONS)} mutations caught; baseline green; {len(EXPECTED_SURVIVORS)} expected survivor(s) listed")
        return 0
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    sys.exit(main())
