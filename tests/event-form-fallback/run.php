<?php

/**
 * Rule-table harness for App\modules\booking\helpers\EventFormFallback (#1498).
 *
 *   php tests/event-form-fallback/run.php
 *
 * Plain PHP on purpose: the pe-api image is built --no-dev, so PHPUnit does not exist there.
 * Pure rule, no database, no framework. Every expected value is written out here as a literal,
 * not derived from the code under test.
 *
 * HELPER_FILE (optional) points at a mutated copy of the helper; mutate.py uses it to show that each
 * deliberately broken copy turns THIS harness red. The DB-checked arms (the edit form and the saved
 * rows) are run against the live stack, not here.
 */

$helper = getenv('HELPER_FILE') ?: __DIR__ . '/../../src/modules/booking/helpers/EventFormFallback.php';
if (!is_file($helper)) {
	fwrite(STDERR, "helper not found: $helper\n");
	exit(2);
}
require_once $helper;

use App\modules\booking\helpers\EventFormFallback as F;

$failures = [];
$count = 0;

$expect = function (string $name, $got, $want) use (&$failures, &$count): void {
	$count++;
	if ($got !== $want) {
		$failures[] = $name;
		echo "FAIL  $name\n      got:  " . json_encode($got) . "\n      want: " . json_encode($want) . "\n";
	} else {
		echo "ok    $name\n";
	}
};

// The application as the 84216 fixture has it: group 6 has 5 men, audience 7.
$appWith = [
	'agegroups' => [
		['agegroup_id' => 2, 'male' => 0, 'female' => 0],
		['agegroup_id' => 4, 'male' => 0, 'female' => 0],
		['agegroup_id' => 5, 'male' => 0, 'female' => 0],
		['agegroup_id' => 6, 'male' => 5, 'female' => 0],
	],
	'audience' => [7],
];
$appFemale = ['agegroups' => [['agegroup_id' => 4, 'male' => 0, 'female' => 3]]];
$appNone = ['agegroups' => [['agegroup_id' => 2, 'male' => 0, 'female' => 0]], 'audience' => [7]];
$appEmpty = [];

// The event side: its own rows, or none.
$eventNone = ['agegroups' => [], 'audience' => []];
$eventRows = ['agegroups' => [['agegroup_id' => 6, 'male' => 1, 'female' => 0]], 'audience' => [1]];
$eventAudOnly = ['agegroups' => [], 'audience' => [1]];
$eventRowsNoAud = ['agegroups' => [['agegroup_id' => 6, 'male' => 1, 'female' => 0]], 'audience' => []];

// 1. Event has no rows, application has participants: fill both.
$expect('no rows + app with participants fills agegroups and audience',
	F::fillFor($eventNone, $appWith),
	['agegroups' => $appWith['agegroups'], 'audience' => [7]]);

// 2. Application with zero participants: no agegroups (the save must still be refused), audience still fills.
$expect('no rows + app with zero participants fills audience only',
	F::fillFor($eventNone, $appNone),
	['audience' => [7]]);

// 3. Application with nothing at all: nothing to show.
$expect('no rows + empty app fills nothing', F::fillFor($eventNone, $appEmpty), []);

// 4. Event has its own rows and audience: never replaced.
$expect('own rows and audience are never replaced', F::fillFor($eventRows, $appWith), []);

// 5. Event has audience but no rows: only agegroups fill, the event's audience stays.
$expect('audience kept, agegroups filled when event has no rows',
	F::fillFor($eventAudOnly, $appWith),
	['agegroups' => $appWith['agegroups']]);

// 6. Event has rows but no audience: only audience fills, the event's rows stay.
$expect('rows kept, audience filled when event has none',
	F::fillFor($eventRowsNoAud, $appWith),
	['audience' => [7]]);

// 7. Female-only participants count as participants.
$expect('female-only application counts as participants',
	F::fillFor($eventNone, $appFemale),
	['agegroups' => $appFemale['agegroups']]);

// 8. An event with no agegroups key at all is treated as having none.
$expect('missing agegroups key counts as none',
	F::fillFor(['audience' => []], $appWith),
	['agegroups' => $appWith['agegroups'], 'audience' => [7]]);

// 9. hasParticipants: male only, female only, both zero, empty, and rows without the count keys.
$expect('hasParticipants: male 5 is true', F::hasParticipants([['male' => 5, 'female' => 0]]), true);
$expect('hasParticipants: female 3 is true', F::hasParticipants([['male' => 0, 'female' => 3]]), true);
$expect('hasParticipants: 0/0 is false', F::hasParticipants([['male' => 0, 'female' => 0]]), false);
$expect('hasParticipants: no rows is false', F::hasParticipants([]), false);
$expect('hasParticipants: rows without count keys is false', F::hasParticipants([['agegroup_id' => 2]]), false);

echo "\n" . ($count - count($failures)) . "/$count passed\n";
if ($failures) {
	echo 'FAILED: ' . implode('; ', $failures) . "\n";
	exit(1);
}
exit(0);
