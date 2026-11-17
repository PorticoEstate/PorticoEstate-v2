<?php

namespace App\modules\bookingfrontend\services;

use App\Database\Db;
use App\modules\booking\services\AllocationTimeChangeMailRenderer;
use App\modules\booking\services\EmailService;
use App\modules\booking\services\TranslationLangSwitch;
use App\modules\bookingfrontend\helpers\WebSocketHelper;

/**
 * Tells the application's case officer, in a mail of its own, that the applicant asked for a new
 * time or shortened one on an allocation from Min side (GH #1392). It comes on top of the comment
 * and the group mail AllocationTimeChangeService already sends; those are unchanged.
 *
 * WHO. The one case officer on bb_application.case_officer_id — never the group, never the
 * building's role holders. The address is that account's own `common.email` preference. No case
 * officer: no mail, and a log line. An officer without a usable address: no mail, and a log line
 * saying whose. Same rules as CateringChangeNotifier, which this is modelled on.
 *
 * LANGUAGE. The officer's own `common.lang`, else Norwegian (the instance language). The citizen's
 * cookie or session language never decides it — see AllocationTimeChangeMailRenderer.
 *
 * IDENTITY. The request is the CITIZEN's, and stays so. The officer's preferences are read with a
 * query of their own, never through CreateObject('phpgwapi.preferences', $id): that returns the
 * Preferences SINGLETON and calls setAccountId(), which rewrites Settings['user'] (account id and
 * preferences) for the rest of the request — the deferred group mail and bell, and every legacy
 * read that authorises on the current user, would then run as the case officer.
 *
 * FAILURE. Called AFTER the change is committed. notify() catches Throwable at every level and
 * cannot throw: a PHP 8 TypeError is an Error, not an Exception, and the notification chains here
 * have swallowed or 500'd on exactly that before. The slow part — the SMTP send — is deferred, so
 * it never holds the citizen's response.
 *
 * The methods marked "seam" exist so a test can replace exactly the part that touches the outside
 * world and leave the rest of the chain real.
 */
class AllocationTimeChangeNotifier
{
    private const DEFAULT_LANG = 'no';
    private const SUPPORTED_LANGS = ['no', 'nn', 'en'];

    private AllocationTimeChangeMailRenderer $renderer;

    public function __construct(?AllocationTimeChangeMailRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new AllocationTimeChangeMailRenderer(new TranslationLangSwitch());
    }

    /**
     * @param array $payload see AllocationTimeChangeMailRenderer::render(); the application also
     *                       carries case_officer_id (int) — the links are added here
     * @return bool true when a mail was composed and handed to the (deferred) transport
     */
    public function notify(array $payload): bool
    {
        try {
            $application = $payload['application'] ?? [];
            $applicationId = (int)($application['id'] ?? 0);
            $officerId = (int)($application['case_officer_id'] ?? 0);

            if ($officerId <= 0) {
                error_log("Allocation time change mail not sent: application {$applicationId} has no case officer");
                return false;
            }

            $officer = $this->loadOfficer($officerId);
            $email = trim((string)($officer['email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                error_log("Allocation time change mail not sent: case officer {$officerId} of application {$applicationId} has no usable email address");
                return false;
            }

            $lang = (string)($officer['lang'] ?? '');
            if (!in_array($lang, self::SUPPORTED_LANGS, true)) {
                $lang = self::DEFAULT_LANG;
            }

            $payload['links'] = ['case' => $this->buildCaseLink($applicationId)];
            $mail = $this->renderer->render($payload, $lang);

            $this->defer(function () use ($email, $mail, $applicationId): void {
                try {
                    $this->deliver($email, $mail['subject'], $mail['body']);
                } catch (\Throwable $e) {
                    error_log("Allocation time change mail for application {$applicationId} failed: " . $e->getMessage());
                }
            });
            return true;
        } catch (\Throwable $e) {
            error_log('Allocation time change notification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * seam — the case officer's address and language, read WITHOUT making the officer the current user.
     *
     * @return array{email: ?string, lang: ?string}
     */
    protected function loadOfficer(int $accountId): array
    {
        $common = self::resolveCommonPreferences($this->readCommonPreferenceRows($accountId));
        return [
            'email' => $common['email'] ?? null,
            'lang' => $common['lang'] ?? null,
        ];
    }

    /**
     * seam — the stored `common` preference rows that decide an account's effective preferences:
     * the forced row (owner -1), the default row (owner -2) and the account's own. The same select
     * as Preferences::Readpreferences() (Preferences.php:116), narrowed to the one app.
     *
     * @return list<array{preference_owner: int|string, preference_json: string}>
     */
    protected function readCommonPreferenceRows(int $accountId): array
    {
        $stmt = Db::getInstance()->prepare(
            "SELECT preference_owner, preference_json FROM phpgw_preferences
             WHERE preference_owner IN (-1, -2, ?) AND REPLACE(preference_app, ' ', '') = 'common'"
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The effective `common` preferences of one account, resolved exactly as
     * Preferences::Readpreferences() resolves them (Preferences.php:113-169) — minus the object's
     * side effects on Settings and the Translation singleton:
     *   1. the account's own value;
     *   2. a default (owner -2) fills in where the own value is unset or '';
     *   3. a forced value (owner -1) overwrites whatever is there, even an own value.
     * Every stored string is unquoted (stripslashes) first, as Preferences::unquote() does.
     *
     * NOT mirrored: the `$$name$$` placeholder substitution of Preferences::standard_substitutes().
     * A stored address or language that still carries a placeholder is therefore not substituted: it
     * fails the address check (logged, no mail) or is not a supported language (Norwegian).
     * No stored common.email or common.lang holds a placeholder in the local database.
     *
     * @param list<array{preference_owner: int|string, preference_json: string}> $rows
     * @return array<string, mixed>
     */
    private static function resolveCommonPreferences(array $rows): array
    {
        $forced = $default = $own = [];
        foreach ($rows as $row) {
            $value = self::unquote(json_decode((string)$row['preference_json'], true));
            if (!is_array($value)) {
                continue;
            }
            switch ((int)$row['preference_owner']) {
                case -1:
                    $forced = $value;
                    break;
                case -2:
                    $default = $value;
                    break;
                default:
                    $own = $value;
                    break;
            }
        }

        $effective = $own;
        foreach ($default as $name => $value) {
            if (!isset($effective[$name]) || $effective[$name] === '') {
                $effective[$name] = $value;
            }
        }
        foreach ($forced as $name => $value) {
            $effective[$name] = $value;
        }
        return $effective;
    }

    /** Preferences::unquote() (Preferences.php:93): stripslashes on every scalar, recursively. */
    private static function unquote(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::unquote(...), $value);
        }
        return stripslashes((string)$value);
    }

    /** seam — the absolute link to the case in the booking backend. */
    protected function buildCaseLink(int $applicationId): string
    {
        return (new EmailService())->buildBackendLink('/booking/view/applications/' . $applicationId);
    }

    /** seam — run the send after the response where the SAPI allows it. */
    protected function defer(callable $work): void
    {
        WebSocketHelper::forkNotification($work);
    }

    /** seam — the transport. */
    protected function deliver(string $to, string $subject, string $body): void
    {
        (new EmailService())->sendCaseOfficerChangeMail($to, $subject, $body);
    }
}
