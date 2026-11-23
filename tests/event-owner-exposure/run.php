<?php

/**
 * Hermetic tests for the owner arms of @Expose(when=…) (GH #1492, task 48050; operator ruling forum/pe-general/48190):
 * an owner arm compares a value stored on the entity with the viewer's identifier from the serialize() context
 * («customer_organization_number=$organization_number», «customer_ssn=$user_ssn», «group_id=$user_group_id»).
 * It must match only when BOTH are non-empty and equal. Before the fix a stored '' or NULL equalled the null context
 * of a viewer with no session, so a private event stored with an invoice type and no number showed its name, its
 * contact details and its secret to anyone.
 *
 *   php tests/event-owner-exposure/run.php
 *
 * Every model and the trait are the REAL code from HD_ROOT: bookingfrontend Event and Booking, booking Application,
 * bookingfrontend Organization, App\traits\SerializableTrait. The contexts are built the way their callers build them
 * (ScheduleEntityService, EventService, OrganizationService): user_ssn = UserHelper::$ssn (null without a session),
 * organization_number = array_column($bouser->organizations, 'orgnr') or null, user_group_id = the group ids or null.
 * Stubs: Translation (the private-event placeholder) and nothing else; the booking ACL lookup fails closed by itself.
 *
 * P   every class under test is the HD_ROOT copy (composer's own App\ loader points at this repo).
 * NO-BREAK rows (must pass on the base tree AND on the fix):
 *   N1  owner by organisation number sees name, organizer, contact, secret and the customer fields of a private event
 *   N2  owner by ssn: the same
 *   N3  owner whose organisation list also holds an organisation without a number: still the owner
 *   N4  a public event shows its name, organizer and contact to a viewer with no session, and no secret
 *   N5  a logged-in non-owner sees a private event as before: the placeholder, nothing gated
 *   N6  a logged-in non-owner sees a public event as N4
 *   N7  a booking's group member gets its secret (ids as int and as string); N8 a non-member and no session do not
 *   N9  the booking Application's owner gets customer_ssn
 *   N10 Organization's «$user_has_access=true» fields, and N11 Event's «$expose_name=1», are as before
 *   N12 the legacy PHP front page never serialises through the trait, and the edit/cancel links read no gated field
 *   N13 owners by organisation number and by ssn keep customer_identifier_type and customer_organization_number, on a
 *       private and a public event (the client's isOrgAdmin compares the delegate's number against them)
 *   N14 the client readers of the gated keys: the popper's owner test is a strict compare against the logged-in user's
 *       ssn (a user exists only after an ssn login), the application page's Edit and the «own» pending_change key on
 *       the secret being present
 * FIX rows (fail on the base tree, pass on the fix):
 *   F1  no session, private event, organisation-number type with '' : no secret, no contact, no organizer, no
 *       description, no customer_ssn, the placeholder name;   F2 the same with NULL;   F3 the same for an ssn type with ''
 *   F4  logged in with no organisations and an empty ssn ([] and '')
 *   F5  logged in as a delegate of an organisation without a number ([null] / ['']), stored ''
 *   F6  a PUBLIC event stored that way: the name stays, but no secret and no customer_ssn
 *   F7  a booking with group 0 against no session / a list holding a null
 *   F8  the booking Application with an empty ssn against no session
 *   F9  a direct-booking event whose secret is its application's, stored with a type and no number: not served
 *   F10 the stored side alone: a NULL stored number against a viewer value 0 / [0] (both equal NULL loosely)
 *   F11 customer_identifier_type and customer_organization_number: no session and non-owners no longer get them, on
 *       public or private events (their docblocks closed «when={…}» without «)», so the trait never read the rule)
 *
 * Red first: git archive 6ca0ca454 src | tar -x -C /tmp/oe-base && HD_ROOT=/tmp/oe-base php tests/event-owner-exposure/run.php
 * (F1-F11 fail there; F11 alone also fails on the tree with the guard but without the two docblock fixes.)
 * Environment (optional): HD_ROOT = tree holding src/ (default: this repo). vendor/ comes from HD_ROOT if it has one,
 * else from this repo; App\ classes always load from HD_ROOT.
 * Exit code: 0 all pass, 1 any failure (an arm that throws is a FAIL line, never a crash).
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

    final class OeWarnings
    {
        /** @var list<string> */
        public static array $list = [];
    }
    set_error_handler(static function (int $no, string $message, string $file = '', int $line = 0): bool {
        if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) {
            return true;
        }
        OeWarnings::$list[] = $message . ' @' . basename($file) . ':' . $line;
        return true;
    });

    $root = getenv('HD_ROOT') ?: dirname(__DIR__, 2);
    $repo = dirname(__DIR__, 2);
    // Composer first, then App\ from the tree under test registered IN FRONT of it: composer prepends its own loader,
    // whose App\ points at this repo, so registering ours before it would test this repo's trait, not HD_ROOT's.
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
    use App\modules\bookingfrontend\models\Organization;

    const OE_PLACEHOLDER = '[lang:bookingfrontend.private event]';
    const OE_NAME = 'T1492 owner event';
    const OE_ORG = '994239929';
    const OE_SSN = '01010112345';
    /**
     * Fields only an owner may see (the contact set is also shown on a public event). customer_identifier_type and
     * customer_organization_number are owner-only too, but only since their docblocks got the «)» the trait's pattern
     * needs; before that every viewer got them. They have their own rows (N13 owners keep them, F11 nobody else gets
     * them) so the no-break rows here stay true of the base tree as well.
     */
    const OE_OWNER_ONLY = ['secret', 'customer_ssn'];
    const OE_PRIVATE_GATED = ['secret', 'customer_ssn', 'contact_name', 'contact_email', 'contact_phone', 'organizer', 'description'];

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
        OeWarnings::$list = [];
        try {
            $body();
        } catch (\Throwable $e) {
            $armResult[$group] = false;
            $failures[] = "{$name} threw " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
        }
        foreach (array_unique(OeWarnings::$list) as $w) {
            $armResult[$group] = false;
            $failures[] = "{$name} warning: {$w}";
        }
    };
    $fatal = static function (string $why): never {
        echo 'FAIL harness cannot load the code under test — ', $why, "\n";
        echo "Owner exposure harness: FAIL 1 of 1 checks\n";
        exit(1);
    };
    foreach (['src/traits/SerializableTrait.php', 'src/modules/bookingfrontend/models/Event.php', 'src/modules/bookingfrontend/models/Booking.php',
              'src/modules/booking/models/Application.php', 'src/modules/bookingfrontend/models/Organization.php',
              'src/modules/bookingfrontend/services/ScheduleEntityService.php', 'src/modules/booking/inc/class.bobooking.inc.php',
              'src/modules/bookingfrontend/inc/class.uievent.inc.php'] as $needed) {
        if (!is_file($root . '/' . $needed)) {
            $fatal($needed . ' is missing under ' . $root);
        }
    }

    /** A private event as ScheduleEntityService reads it, with every gated field filled. */
    $event = static fn(array $over = []): Event => new Event($over + [
        'id' => 1492, 'type' => 'event', 'name' => OE_NAME, 'organizer' => 'Organizer AS', 'description' => 'Desc',
        'contact_name' => 'Kari Kontakt', 'contact_email' => 'kari@example.invalid', 'contact_phone' => '40000000',
        'secret' => 's3cr3t1492', 'is_public' => 0, 'customer_identifier_type' => 'organization_number',
        'customer_organization_number' => OE_ORG, 'customer_ssn' => null, 'building_id' => 10,
    ]);
    /** The context a caller builds: ssn of the session (null without one), the org numbers of its delegations. */
    $ctx = static fn($ssn = null, $orgs = null): array => ['user_ssn' => $ssn, 'organization_number' => $orgs];
    $present = static fn(array $out, array $keys): array => array_values(array_filter($keys, static fn($k) => array_key_exists($k, $out) && $out[$k] !== null));
    $ownerSees = static function (string $label, array $out) use ($same, $present): void {
        $same("{$label}: the real name", $out['name'] ?? null, OE_NAME);
        $same("{$label}: every gated field", $present($out, ['secret', 'contact_name', 'contact_email', 'contact_phone', 'organizer', 'description']),
            ['secret', 'contact_name', 'contact_email', 'contact_phone', 'organizer', 'description']);
    };
    /** The booking Application has typed properties a DB row always fills; give the ones a row would carry a value. */
    $bookingApplication = static function (array $data): BookingApplication {
        $a = new BookingApplication($data);
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
        return $a;
    };
    $strangerSees = static function (string $label, array $out) use ($same, $present): void {
        $same("{$label}: the placeholder", $out['name'] ?? null, OE_PLACEHOLDER);
        $same("{$label}: no gated field", $present($out, OE_PRIVATE_GATED), []);
    };

    // --- P: every class under test is the HD_ROOT copy (a red-first run against another tree means nothing otherwise)
    $arm('P', function () use ($root, $check) {
        $want = realpath($root . '/src') . '/';
        $classes = [Event::class, Booking::class, BookingApplication::class, Organization::class, \App\traits\SerializableTrait::class];
        // The trait reads its empty rule from OwnerIdentifier where the tree has it (trees before task 48753 do not):
        // without this, composer's loader would take the helper from this repo and test another tree's rule.
        if (is_file($root . '/src/helpers/OwnerIdentifier.php')) {
            $classes[] = \App\helpers\OwnerIdentifier::class;
        }
        foreach ($classes as $class) {
            $file = (string)realpath((string)(new \ReflectionClass($class))->getFileName());
            $check("P {$class} loads from HD_ROOT", str_starts_with($file, $want), $file);
        }
    });

    // --- NO-BREAK ------------------------------------------------------------------------------------
    $arm('N1', function () use ($event, $ctx, $ownerSees, $same) {
        $out = $event()->serialize($ctx(null, [OE_ORG]));
        $ownerSees('N1 owner by org nr', $out);
        $same('N1 owner by org nr: the number', $out['customer_organization_number'] ?? null, OE_ORG);
    });
    $arm('N2', function () use ($event, $ctx, $ownerSees, $same) {
        $out = $event(['customer_identifier_type' => 'ssn', 'customer_organization_number' => null, 'customer_ssn' => OE_SSN])->serialize($ctx(OE_SSN, null));
        $ownerSees('N2 owner by ssn', $out);
        $same('N2 owner by ssn: the ssn', $out['customer_ssn'] ?? null, OE_SSN);
    });
    $arm('N3', function () use ($event, $ctx, $ownerSees) {
        $ownerSees('N3 owner, list also holding a number-less org', $event()->serialize($ctx(null, ['111111111', null, '', OE_ORG])));
    });
    $arm('N4', function () use ($event, $ctx, $same, $present) {
        $out = $event(['is_public' => 1])->serialize($ctx());
        $same('N4 public, no session: the name', $out['name'] ?? null, OE_NAME);
        $same('N4 public, no session: organizer and contact', $present($out, ['organizer', 'contact_name', 'contact_email', 'contact_phone']),
            ['organizer', 'contact_name', 'contact_email', 'contact_phone']);
        $same('N4 public, no session: no owner-only field', $present($out, OE_OWNER_ONLY), []);
    });
    $arm('N5', function () use ($event, $ctx, $strangerSees) {
        $strangerSees('N5 non-owner (org list)', $event()->serialize($ctx('02020212345', ['111111111'])));
        $strangerSees('N5 non-owner (ssn arm)', $event(['customer_identifier_type' => 'ssn', 'customer_organization_number' => null, 'customer_ssn' => OE_SSN])
            ->serialize($ctx('02020212345', ['111111111'])));
    });
    $arm('N6', function () use ($event, $ctx, $same, $present) {
        $out = $event(['is_public' => 1])->serialize($ctx('02020212345', ['111111111']));
        $same('N6 non-owner, public: the name', $out['name'] ?? null, OE_NAME);
        $same('N6 non-owner, public: no owner-only field', $present($out, OE_OWNER_ONLY), []);
    });
    $arm('N7', function () use ($same) {
        $b = new Booking(['id' => 7, 'type' => 'booking', 'group_id' => 5, 'secret' => 'bsecret']);
        $same('N7 group member (int ids): the secret', $b->serialize(['user_ssn' => null, 'user_group_id' => [5]])['secret'] ?? null, 'bsecret');
        $same('N7 group member (string ids): the secret', $b->serialize(['user_ssn' => null, 'user_group_id' => ['3', '5']])['secret'] ?? null, 'bsecret');
    });
    $arm('N8', function () use ($same) {
        $b = new Booking(['id' => 7, 'type' => 'booking', 'group_id' => 5, 'secret' => 'bsecret']);
        $same('N8 non-member: no secret', $b->serialize(['user_ssn' => null, 'user_group_id' => [6]])['secret'] ?? null, null);
        $same('N8 no session: no secret', $b->serialize(['user_ssn' => null, 'user_group_id' => null])['secret'] ?? null, null);
    });
    $arm('N9', function () use ($same, $bookingApplication) {
        $a = $bookingApplication(['id' => 9, 'customer_ssn' => OE_SSN]);
        $same('N9 application owner: customer_ssn', $a->serialize(['user_ssn' => OE_SSN])['customer_ssn'] ?? null, OE_SSN);
        $same('N9 another viewer: no customer_ssn', $a->serialize(['user_ssn' => '02020212345'])['customer_ssn'] ?? null, null);
    });
    $arm('N10', function () use ($same) {
        $o = new Organization(['id' => 3, 'name' => 'Org', 'customer_organization_number' => OE_ORG]);
        $same('N10 with access: the gated field', $o->serialize(['user_has_access' => true])['customer_organization_number'] ?? null, OE_ORG);
        $same('N10 without access: not shown', $o->serialize(['user_has_access' => false])['customer_organization_number'] ?? null, null);
    });
    $arm('N11', function () use ($event, $ctx, $same) {
        $same('N11 $expose_name=1: the name of a private event', ($event()->serialize($ctx() + ['expose_name' => 1]))['name'] ?? null, OE_NAME);
    });
    $arm('N12', function () use ($root, $check) {
        foreach (['src/modules/booking/inc/class.bobooking.inc.php', 'src/modules/bookingfrontend/inc/class.uievent.inc.php'] as $legacy) {
            $src = (string)file_get_contents($root . '/' . $legacy);
            $check("N12 {$legacy} does not serialise through the trait", !str_contains($src, '->serialize(') && !str_contains($src, 'SerializableTrait'));
        }
        $svc = (string)file_get_contents($root . '/src/modules/bookingfrontend/services/ScheduleEntityService.php');
        $from = strpos($svc, 'private function addEditCancelLinks(');
        $to = $from === false ? false : strpos($svc, "\n    private function ", $from + 10);
        $check('N12 addEditCancelLinks found', $from !== false && $to !== false);
        if ($from !== false && $to !== false) {
            preg_match_all("/\\\$entityData\\['([a-z_]+)'\\]/", substr($svc, $from, $to - $from), $m);
            $read = array_values(array_unique($m[1]));
            $gated = array_values(array_intersect($read, array_merge(OE_PRIVATE_GATED, ['name'])));
            $check('N12 edit/cancel links read no gated field', $gated === [], 'reads ' . implode(', ', $gated));
        }
    });

    $arm('N14', function () use ($root, $repo, $check) {
        // The client reads the gated keys as «present or not», so an omitted key must stay «not the owner» and an owner
        // must keep the key. The owner keeps it (N1, N2); these pin the readers that decide on it.
        $client = (is_dir($root . '/src/modules/bookingfrontend/client/src') ? $root : $repo) . '/src/modules/bookingfrontend/client/src';
        $popper = @file_get_contents($client . '/components/building-calendar/modules/event/popper/content/event-popper-content.tsx');
        $check('N14 popper: a user is required before the ssn comparison', $popper !== false && (bool)preg_match('/if \(!user\) \{\s*return false;\s*\}/', $popper));
        $check('N14 popper: the ssn comparison is strict (an omitted customer_ssn is undefined, never the user\'s ssn)',
            $popper !== false && str_contains($popper, 'eventData.customer_ssn === ssn') && str_contains($popper, 'const ssn = user.ssn;'));
        $ctl = (string)@file_get_contents($root . '/src/modules/bookingfrontend/controllers/BookingUserController.php');
        $at = strpos($ctl, '$external_login_info = $bouser->validate_ssn_login(');
        $ser = strpos($ctl, '$serialized = $userModel->serialize();');
        $check('N14 the client gets a user only after an ssn login validated (so user.ssn is that ssn)',
            $at !== false && $ser !== false && $at < $ser && str_contains(substr($ctl, $at, $ser - $at), 'if (empty($external_login_info))'));
        $converter = @file_get_contents($client . '/components/building-calendar/util/event-converter.ts');
        $check('N14 isOrgAdmin: an event\'s number is read only for an organisation-number type (absent type → no number)',
            $converter !== false && (bool)preg_match("/if\\s*\\(\\s*eventData\\.customer_identifier_type === 'organization_number'\\s*\\)\\s*\\{\\s*eventOrgNumber = eventData\\.customer_organization_number;/", $converter));
        $check('N14 isOrgAdmin: the delegate match is strict (an absent number is undefined and matches no delegate)',
            $converter !== false && str_contains($converter, 'org.organization_number === eventOrgNumber'));
        $details = @file_get_contents($client . '/components/user/application/application-details.tsx');
        $check('N14 application page: Edit only when the event carries its secret', $details !== false && str_contains($details, 'showEdit = !!ev.secret;'));
        $svc = (string)file_get_contents($root . '/src/modules/bookingfrontend/services/ScheduleEntityService.php');
        $check('N14 «own» events (pending_change) are the ones whose payload carries the secret', str_contains($svc, "if (isset(\$eventData['secret'])) {"));
    });
    $arm('N13', function () use ($event, $ctx, $same) {
        // Owners keep customer_identifier_type and customer_organization_number, on a private and on a public event:
        // the client's isOrgAdmin (event-converter.ts) matches the delegate's organisation number against these two.
        foreach ([0 => 'private', 1 => 'public'] as $isPublic => $label) {
            $out = $event(['is_public' => $isPublic])->serialize($ctx(null, [OE_ORG]));
            $same("N13 owner by org nr, {$label}: customer_identifier_type", $out['customer_identifier_type'] ?? null, 'organization_number');
            $same("N13 owner by org nr, {$label}: customer_organization_number (what isOrgAdmin compares)", $out['customer_organization_number'] ?? null, OE_ORG);
            $out = $event(['is_public' => $isPublic, 'customer_identifier_type' => 'ssn', 'customer_organization_number' => null, 'customer_ssn' => OE_SSN])
                ->serialize($ctx(OE_SSN, null));
            $same("N13 owner by ssn, {$label}: customer_identifier_type", $out['customer_identifier_type'] ?? null, 'ssn');
        }
    });

    // --- FIX -----------------------------------------------------------------------------------------
    $arm('F1', function () use ($event, $ctx, $strangerSees) {
        $strangerSees("F1 no session, org-nr type + ''", $event(['customer_organization_number' => ''])->serialize($ctx()));
    });
    $arm('F2', function () use ($event, $ctx, $strangerSees) {
        $strangerSees('F2 no session, org-nr type + NULL', $event(['customer_organization_number' => null])->serialize($ctx()));
    });
    $arm('F3', function () use ($event, $ctx, $strangerSees) {
        $strangerSees("F3 no session, ssn type + ''", $event(['customer_identifier_type' => 'ssn', 'customer_organization_number' => null, 'customer_ssn' => ''])->serialize($ctx()));
    });
    $arm('F4', function () use ($event, $ctx, $strangerSees) {
        $strangerSees("F4 logged in, no orgs, empty ssn (org-nr type + '')", $event(['customer_organization_number' => ''])->serialize($ctx('', [])));
        $strangerSees("F4 logged in, no orgs, empty ssn (ssn type + '')", $event(['customer_identifier_type' => 'ssn', 'customer_organization_number' => null, 'customer_ssn' => ''])->serialize($ctx('', [])));
    });
    $arm('F5', function () use ($event, $ctx, $strangerSees) {
        $strangerSees("F5 delegate of a number-less org [null], stored ''", $event(['customer_organization_number' => ''])->serialize($ctx(null, [null])));
        $strangerSees("F5 delegate of a number-less org [''], stored ''", $event(['customer_organization_number' => ''])->serialize($ctx(null, [''])));
    });
    $arm('F6', function () use ($event, $ctx, $same, $present) {
        $out = $event(['is_public' => 1, 'customer_organization_number' => ''])->serialize($ctx());
        $same("F6 public, org-nr type + '', no session: the name stays", $out['name'] ?? null, OE_NAME);
        $same("F6 public, org-nr type + '', no session: no owner-only field", $present($out, OE_OWNER_ONLY), []);
    });
    $arm('F7', function () use ($same) {
        $b = new Booking(['id' => 7, 'type' => 'booking', 'group_id' => 0, 'secret' => 'bsecret']);
        $same('F7 group 0, no session: no secret', $b->serialize(['user_ssn' => null, 'user_group_id' => null])['secret'] ?? null, null);
        $same('F7b group 0, a list holding a null: no secret', $b->serialize(['user_ssn' => null, 'user_group_id' => [null]])['secret'] ?? null, null);
    });
    $arm('F9', function () use ($event, $ctx, $strangerSees) {
        // A direct-booking event carries its application's secret (uiapplication: `$event = $application`), so here the
        // value withheld is the application's own secret, which opens the application for writing.
        $direct = ['application_id' => 4711, 'secret' => 'the-application-secret', 'customer_organization_number' => ''];
        $strangerSees('F9 direct-booking event, no session', $event($direct)->serialize($ctx()));
        $strangerSees('F9 direct-booking event, logged-in non-owner', $event($direct)->serialize($ctx('02020212345', ['111111111'])));
    });
    $arm('F10', function () use ($event, $ctx, $same) {
        // The stored side on its own: a NULL stored number against a viewer value that equals NULL loosely (0).
        $same('F10 stored NULL vs a viewer 0: no secret', $event(['customer_organization_number' => null])->serialize($ctx(null, 0))['secret'] ?? null, null);
        $same('F10b stored NULL vs a viewer list [0]: no secret', $event(['customer_organization_number' => null])->serialize($ctx(null, [0]))['secret'] ?? null, null);
    });
    $arm('F11', function () use ($event, $ctx, $same, $present) {
        // The two fields whose @Expose(when={…} lacked its «)» went to every viewer for every event. Now only owners.
        $both = ['customer_identifier_type', 'customer_organization_number'];
        $same('F11 no session, public with a number: neither field', $present($event(['is_public' => 1])->serialize($ctx()), $both), []);
        $same('F11 no session, private with a number: neither field', $present($event()->serialize($ctx()), $both), []);
        $same('F11 logged-in non-owner, private: neither field', $present($event()->serialize($ctx('02020212345', ['111111111'])), $both), []);
        $same('F11 logged-in non-owner, public: neither field', $present($event(['is_public' => 1])->serialize($ctx('02020212345', ['111111111'])), $both), []);
        $same('F11 no session, ssn event: no customer_identifier_type',
            $present($event(['customer_identifier_type' => 'ssn', 'customer_organization_number' => null, 'customer_ssn' => OE_SSN])->serialize($ctx()), $both), []);
    });
    $arm('F8', function () use ($same, $bookingApplication) {
        $a = $bookingApplication(['id' => 9, 'customer_ssn' => '']);
        $same("F8 application ssn '', no session: not shown", array_key_exists('customer_ssn', $a->serialize(['user_ssn' => null]) ?? []), false);
    });

    // --- report ----------------------------------------------------------------------------------------
    foreach ($armResult as $name => $ok) {
        printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
    }
    foreach ($failures as $f) {
        echo 'FAIL ', $f, "\n";
    }
    $total = $passed + count($failures);
    if ($failures) {
        printf("Owner exposure harness: FAIL %d of %d checks on PHP %s\n", count($failures), $total, PHP_VERSION);
        exit(1);
    }
    printf("Owner exposure harness: PASS %d checks on PHP %s\n", $total, PHP_VERSION);
    exit(0);
}
