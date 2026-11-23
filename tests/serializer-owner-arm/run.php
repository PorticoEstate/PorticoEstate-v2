<?php

/**
 * Hermetic tests for how the owner arm of @Expose(when=…) compares an identifier stored on an entity with the viewer's
 * («customer_organization_number=$organization_number», «customer_ssn=$user_ssn», «group_id=$user_group_id»). Task
 * 49173 (declaration forum/pe-general/49165, rulings 49167/49168/49176/49177): the compare is strict, an int taken as its
 * digits; the placeholder organisation number '000000000' makes nobody the owner on either side; on the ssn arm only,
 * a stored value of exactly 10 digits gets its dropped leading zero back before the compare.
 *
 * Before: the list arm was a loose in_array and the scalar arm a loose ==, and neither left the placeholder out. A
 * citizen's session list holds '000000000' (MinId2), so every citizen was the owner of every event stored with it and,
 * loosely, of every event stored with another all-zero string ('0', '00000'); two digit strings compared as numbers.
 *
 *   php tests/serializer-owner-arm/run.php            the rows (exit 0 all pass, 1 any failure)
 *   php tests/serializer-owner-arm/run.php --matrix   one line per stored×viewer pair: arm, stored, viewer, MATCH|NO
 *                                                     (matrix.py compares two trees with it)
 *
 * The models and the trait are the REAL code from HD_ROOT: bookingfrontend Event (secret, owner arms by ssn and by
 * organisation number) and Booking (secret, group arm), booking Application (customer_ssn, ssn arm). «MATCH» = the
 * owner-only key is served. Stubs: Translation (the private-event placeholder name) and nothing else.
 *
 * P    every class under test is the HD_ROOT copy.
 * BOTH rows (pass on the base tree AND on the fix): what an owner sees must not change.
 *   O1   a delegate's real 9-digit number in the list: MATCH        O2  X1, the list holds the number as an int: MATCH
 *   O3   the list holds the placeholder AND the real number: MATCH  O6  another organisation: NO
 *   O7   a null list / an empty list / '' stored vs [''] : NO        O12 placeholder stored vs a real list: NO
 *   OS1  the organisation context as a scalar (not a list), the same number: MATCH
 *   S1   owner by ssn, 11 = 11: MATCH                                S2  another ssn: NO;  S3 no session: NO
 *   S4   a stored 10-digit ssn vs the viewer's 11-digit twin: MATCH  S5  a stored 10-digit vs an unrelated 11-digit: NO
 *   S13  a stored 10-digit vs the same 10 digits (string or int): MATCH; the pad is tried AFTER the value as stored
 *   Z1   an all-zero value equal on both sides is still a value: org '0' vs ['0'], group 0 vs [0], ssn '0' vs '0' and
 *        ten zeros vs ten zeros: MATCH (the all-zero guard is the pad's, not a rule for every compare)
 *   A1   the booking Application's applicant by ssn: MATCH; and the 10-digit twin: MATCH
 *   G1   X1, group ids '5' vs 5 and 5 vs '5': MATCH                 G2  another group: NO
 * FIX rows (fail on the base tree, pass on the fix): all NO.
 *   O4   placeholder stored + placeholder in the list               O5  '00000' / '0' stored vs a placeholder list
 *   O8   ' 000000000' (padded placeholder) on both sides            O10 leading-zero twin   O11 trailing-space twin
 *   OS2  scalar organisation context: placeholder on both sides, '0' vs the placeholder
 *   S6   '0' vs '00000'        S7  '00000000000' vs '0'             S8  a stored ssn with a trailing space
 *   S9   a stored 11-digit vs a viewer value with its zero dropped (the pad is the stored side's, and only of 10 digits)
 *   S10  a stored 9-digit vs '00' + those digits                    S11 a stored 12-digit '0' + 11 digits vs the 11
 *   S12  a stored 10 digits plus a newline (not exactly 10 digits)  A2  the Application: '0' vs '00000'
 *   S14  a stored ten zeros vs eleven zeros (the pad does not pad an all-zero value)
 *   G3   group '05' vs 5
 *
 * Red first: git archive 7cf8b902f src | tar -x -C /tmp/soa-base && HD_ROOT=/tmp/soa-base php tests/serializer-owner-arm/run.php
 * (every FIX row fails there; every BOTH row and P pass).
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo). vendor/ comes from HD_ROOT if it has one,
 * else from this repo; App\ classes always load from HD_ROOT.
 */

namespace App\modules\phpgwapi\services {
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

    final class SoaWarnings
    {
        /** @var list<string> */
        public static array $list = [];
    }
    set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0): bool {
        if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) {
            return true;
        }
        SoaWarnings::$list[] = $message . ' @' . basename($file) . ':' . $line;
        return true;
    });

    $root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
    $repo = dirname(__DIR__, 2);
    // Composer first, then App\ from the tree under test registered IN FRONT of it: composer's own App\ points at this
    // repo, so without ours in front this would test this repo's trait, not HD_ROOT's.
    require (is_file($root . '/vendor/autoload.php') ? $root : $repo) . '/vendor/autoload.php';
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);

    use App\modules\booking\models\Application as BookingApplication;
    use App\modules\bookingfrontend\models\Booking;
    use App\modules\bookingfrontend\models\Event;

    const SOA_ORG = '994239929';
    const SOA_SSN = '01010112345';
    const SOA_SSN_ZERO_DROPPED = '1010112345';
    const SOA_OTHER_SSN = '02020212345';
    const SOA_PLACEHOLDER = '000000000';

    /** Does the arm under test serve its owner-only key? (Event/Booking: secret; Application: customer_ssn.) */
    $owns = static function (string $arm, mixed $stored, mixed $viewer): bool {
        switch ($arm) {
            case 'org':
                $out = (new Event(['id' => 1, 'type' => 'event', 'name' => 'n', 'is_public' => 0, 'secret' => 'SECRET',
                    'customer_identifier_type' => 'organization_number', 'customer_organization_number' => $stored, 'customer_ssn' => null]))
                    ->serialize(['user_ssn' => null, 'organization_number' => $viewer]);
                return ($out['secret'] ?? null) === 'SECRET';
            case 'ssn':
                $out = (new Event(['id' => 1, 'type' => 'event', 'name' => 'n', 'is_public' => 0, 'secret' => 'SECRET',
                    'customer_identifier_type' => 'ssn', 'customer_organization_number' => null, 'customer_ssn' => $stored]))
                    ->serialize(['user_ssn' => $viewer, 'organization_number' => null]);
                return ($out['secret'] ?? null) === 'SECRET';
            case 'app':
                $a = new BookingApplication(['id' => 9, 'customer_ssn' => $stored]);
                // The typed properties a DB row always fills; give each a value so serialize() reads them.
                foreach ((new \ReflectionClass($a))->getProperties() as $p) {
                    if (!$p->hasType() || $p->isStatic() || $p->isInitialized($a)) {
                        continue;
                    }
                    $t = $p->getType();
                    $name = $t instanceof \ReflectionNamedType ? $t->getName() : 'mixed';
                    $p->setValue($a, $t->allowsNull() ? null : match ($name) {
                        'int' => 0, 'float' => 0.0, 'string' => '', 'bool' => false, 'array' => [], default => null,
                    });
                }
                return array_key_exists('customer_ssn', $a->serialize(['user_ssn' => $viewer]) ?? []);
            case 'group':
                $out = (new Booking(['id' => 7, 'type' => 'booking', 'group_id' => $stored, 'secret' => 'SECRET']))
                    ->serialize(['user_ssn' => null, 'user_group_id' => $viewer]);
                return ($out['secret'] ?? null) === 'SECRET';
        }
        throw new \LogicException("no arm {$arm}");
    };

    // --- matrix mode: every stored × viewer pair of each arm, for matrix.py to compare between two trees ----------
    if (in_array('--matrix', $argv ?? [], true)) {
        // Each Application serialize() keeps about 0.75 MB (its @ExposeAcl lookup, on the base tree as well), and the
        // matrix serializes a few hundred of them.
        ini_set('memory_limit', '1G');
        $orgValues = [SOA_ORG, 994239929, '099423992', '99423992', '99423992 ', ' 994239929', '0994239929', SOA_PLACEHOLDER,
            ' ' . SOA_PLACEHOLDER, '00000', '000000', '0', 0, '', ' ', null, '123456785', 'abc'];
        $ssnValues = [SOA_SSN, SOA_SSN_ZERO_DROPPED, (int)SOA_SSN_ZERO_DROPPED, SOA_OTHER_SSN, '2020212345', SOA_SSN . ' ',
            '001010112345', '010112345', '00010112345', SOA_SSN_ZERO_DROPPED . "\n", '0000000000', '00000000000', '0',
            '00000', SOA_PLACEHOLDER, 0, '', null, '010190'];
        $groupValues = [5, '5', '05', ' 5', 6, '6', 0, '0', '', null];
        $arms = [
            'org[]' => ['org', $orgValues, static fn($v) => [$v]],
            'org[+ph]' => ['org', $orgValues, static fn($v) => [SOA_PLACEHOLDER, $v]],
            'org' => ['org', $orgValues, static fn($v) => $v],
            'ssn' => ['ssn', $ssnValues, static fn($v) => $v],
            'app' => ['app', array_values(array_filter($ssnValues, 'is_string')), static fn($v) => $v],
            'group[]' => ['group', $groupValues, static fn($v) => [$v]],
            'group' => ['group', $groupValues, static fn($v) => $v],
        ];
        // What a pair IS, from its values alone (never from the code under test), so two trees get the same column:
        // an empty side, a placeholder side, or a cross-spelling (loosely equal, not the same string). For a list,
        // only the elements that loosely equal the stored value count: those are what a loose in_array matched.
        $isEmpty = static fn($v): bool => $v === null || $v === false || $v === [] || (is_string($v) && trim($v) === '');
        $isPlaceholder = static fn($v): bool => is_string($v) && trim($v) === SOA_PLACEHOLDER;
        $why = static function (mixed $stored, mixed $viewer) use ($isEmpty, $isPlaceholder): string {
            $classes = [];
            $matched = is_array($viewer) ? array_filter($viewer, static fn($e) => $stored == $e) : [$viewer];
            foreach (array_merge([$stored], $matched) as $v) {
                if ($isEmpty($v)) {
                    $classes['empty'] = true;
                }
                if ($isPlaceholder($v)) {
                    $classes['placeholder'] = true;
                }
            }
            foreach ($matched as $e) {
                if (is_scalar($stored) && is_scalar($e) && $stored == $e && (string)$stored !== (string)$e) {
                    $classes['cross-spelling'] = true;
                }
            }
            return $classes ? implode(',', array_keys($classes)) : '-';
        };
        $exit = 0;
        foreach ($arms as $label => [$arm, $values, $viewerOf]) {
            foreach ($values as $stored) {
                foreach ($values as $v) {
                    $viewer = $viewerOf($v);
                    SoaWarnings::$list = [];
                    try {
                        $verdict = $owns($arm, $stored, $viewer) ? 'MATCH' : 'NO';
                    } catch (\Throwable $e) {
                        $verdict = 'THROW ' . get_class($e);
                        $exit = 1;
                    }
                    if (SoaWarnings::$list) {
                        $verdict .= ' WARN ' . implode('|', array_unique(SoaWarnings::$list));
                        $exit = 1;
                    }
                    printf("%s\t%s\t%s\t%s\t%s\n", $label, json_encode($stored), json_encode($viewer), $verdict, $why($stored, $viewer));
                }
            }
        }
        exit($exit);
    }

    // --- rows -------------------------------------------------------------------------------------------------------
    $failures = [];
    $passed = 0;
    $armResult = [];
    $arm = static function (string $name, callable $body) use (&$failures, &$armResult): void {
        $armResult[$name] ??= true;
        SoaWarnings::$list = [];
        try {
            $body(static function (string $label, bool $ok) use ($name, &$failures, &$armResult): void {
                if (!$ok) {
                    $armResult[$name] = false;
                    $failures[] = "{$name} {$label}";
                }
            });
        } catch (\Throwable $e) {
            $armResult[$name] = false;
            $failures[] = "{$name} threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        }
        foreach (array_unique(SoaWarnings::$list) as $w) {
            $armResult[$name] = false;
            $failures[] = "{$name} warning: {$w}";
        }
    };
    $count = 0;
    /** One stored×viewer pair: $want true = MATCH (the owner-only key served), false = NO. */
    $row = static function (string $name, string $kind, mixed $stored, mixed $viewer, bool $want) use ($arm, $owns, &$passed, &$count): void {
        $arm($name, static function (callable $fail) use ($kind, $stored, $viewer, $want, $owns, &$passed, &$count): void {
            $count++;
            $got = $owns($kind, $stored, $viewer);
            $label = sprintf('%s stored %s vs %s: got %s, want %s', $kind, json_encode($stored), json_encode($viewer),
                $got ? 'MATCH' : 'NO', $want ? 'MATCH' : 'NO');
            if ($got === $want) {
                $passed++;
            }
            $fail($label, $got === $want);
        });
    };

    $arm('P', function (callable $fail) use ($root, &$passed, &$count) {
        $want = realpath($root . '/src') . '/';
        foreach ([Event::class, Booking::class, BookingApplication::class, \App\traits\SerializableTrait::class, \App\helpers\OwnerIdentifier::class] as $class) {
            $count++;
            $file = (string)realpath((string)(new \ReflectionClass($class))->getFileName());
            $ok = str_starts_with($file, $want);
            $passed += $ok ? 1 : 0;
            $fail("{$class} loads from HD_ROOT ({$file})", $ok);
        }
    });

    // --- BOTH: what an owner sees, and what a non-owner does not, is as before --------------------------------------
    $row('O1', 'org', SOA_ORG, [SOA_ORG], true);
    $row('O2', 'org', SOA_ORG, [(int)SOA_ORG], true);
    $row('O2', 'org', (int)SOA_ORG, [SOA_ORG], true);
    $row('O3', 'org', SOA_ORG, [SOA_PLACEHOLDER, SOA_ORG], true);
    $row('O6', 'org', SOA_ORG, ['123456785'], false);
    $row('O7', 'org', SOA_ORG, null, false);
    $row('O7', 'org', SOA_ORG, [], false);
    $row('O7', 'org', '', [''], false);
    $row('O7', 'org', null, [null], false);
    $row('O12', 'org', SOA_PLACEHOLDER, [SOA_ORG], false);
    $row('OS1', 'org', SOA_ORG, SOA_ORG, true);
    $row('S1', 'ssn', SOA_SSN, SOA_SSN, true);
    $row('S2', 'ssn', SOA_SSN, SOA_OTHER_SSN, false);
    $row('S3', 'ssn', SOA_SSN, null, false);
    $row('S4', 'ssn', SOA_SSN_ZERO_DROPPED, SOA_SSN, true);
    $row('S5', 'ssn', SOA_SSN_ZERO_DROPPED, SOA_OTHER_SSN, false);
    $row('S13', 'ssn', SOA_SSN_ZERO_DROPPED, SOA_SSN_ZERO_DROPPED, true);
    $row('S13', 'ssn', (int)SOA_SSN_ZERO_DROPPED, (int)SOA_SSN_ZERO_DROPPED, true);
    $row('A1', 'app', SOA_SSN, SOA_SSN, true);
    $row('A1', 'app', SOA_SSN_ZERO_DROPPED, SOA_SSN, true);
    $row('G1', 'group', 5, ['5'], true);
    $row('G1', 'group', '5', [5], true);
    $row('G1', 'group', 5, ['3', '5'], true);
    $row('G2', 'group', 5, [6], false);
    $row('Z1', 'org', '0', ['0'], true);
    $row('Z1', 'group', 0, [0], true);
    $row('Z1', 'ssn', '0', '0', true);
    $row('Z1', 'ssn', '0000000000', '0000000000', true);

    // --- FIX: NO now, MATCH on the base tree ------------------------------------------------------------------------
    $row('O4', 'org', SOA_PLACEHOLDER, [SOA_PLACEHOLDER], false);
    $row('O5', 'org', '00000', [SOA_PLACEHOLDER], false);
    $row('O5', 'org', '0', [SOA_PLACEHOLDER], false);
    $row('O8', 'org', ' ' . SOA_PLACEHOLDER, [' ' . SOA_PLACEHOLDER], false);
    $row('O10', 'org', '099423992', ['99423992'], false);
    $row('O11', 'org', '99423992 ', ['99423992'], false);
    $row('OS2', 'org', SOA_PLACEHOLDER, SOA_PLACEHOLDER, false);
    $row('OS2', 'org', '0', SOA_PLACEHOLDER, false);
    $row('S6', 'ssn', '0', '00000', false);
    $row('S7', 'ssn', '00000000000', '0', false);
    $row('S8', 'ssn', SOA_SSN . ' ', SOA_SSN, false);
    $row('S9', 'ssn', SOA_SSN, SOA_SSN_ZERO_DROPPED, false);
    $row('S10', 'ssn', '010112345', '00010112345', false);
    $row('S11', 'ssn', '0' . SOA_SSN, SOA_SSN, false);
    $row('S12', 'ssn', SOA_SSN_ZERO_DROPPED . "\n", SOA_SSN, false);
    $row('S14', 'ssn', '0000000000', '00000000000', false);
    $row('A2', 'app', '0', '00000', false);
    $row('G3', 'group', '05', [5], false);

    // --- report -----------------------------------------------------------------------------------------------------
    foreach ($armResult as $name => $ok) {
        printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
    }
    foreach ($failures as $f) {
        echo 'FAIL ', $f, "\n";
    }
    if ($failures) {
        printf("Serializer owner arm harness: FAIL %d of %d checks on PHP %s\n", count($failures), $count, PHP_VERSION);
        exit(1);
    }
    printf("Serializer owner arm harness: PASS %d checks on PHP %s\n", $count, PHP_VERSION);
    exit(0);
}
