<?php

namespace App\modules\bookingfrontend\services\applications;

use App\Database\Db;
use App\modules\booking\services\CateringMailRenderer;
use App\modules\booking\services\EmailService;
use App\modules\booking\services\TranslationLangSwitch;
use App\modules\bookingfrontend\helpers\WebSocketHelper;
use App\modules\phpgwapi\services\PreferencesReader;

/**
 * Tells the case officer that the applicant added, changed or cancelled a catering order from
 * Min side (GH #1425).
 *
 * WHO — decided in recipients() and nowhere else:
 *   1. the application's case officer (bb_application.case_officer_id), when there is one with a
 *      usable address: that officer alone, as shipped (rulings 34077 / 34082);
 *   2. otherwise — no case officer, or one without a usable address (logged) — every active holder
 *      of the `case_officer` role on the application's building that has a usable address
 *      (operator D1, 2026-11-23, deliberately departing from 34077(c)/(d); pe-ceo 47048 for the
 *      officer without an address);
 *   3. one mail per distinct address (trimmed, case-insensitive); of accounts sharing one, the
 *      lowest account id's language is used.
 * The address is each account's own `common.email` preference.
 *
 * SWITCH (operator D4). The booking config key `notify_case_officer_on_catering_change`, set on the
 * mail settings page: absent means ON (the behaviour as shipped); the stored '0' of an unticked box
 * means OFF, for the officer and the group alike. The case entry is the caller's and is written
 * either way.
 *
 * LANGUAGE. Each recipient's own `common.lang`, else Norwegian (the instance language; existing
 * staff mails are Norwegian-only). The citizen's cookie never decides it — see CateringMailRenderer.
 *
 * IDENTITY. The request is the CITIZEN's. The building's case officers are read with a query of
 * their own and resolved with PreferencesReader::resolveApp (the framework's forced > own > default
 * order), never through the Preferences singleton, which would make the last of them the current
 * user for the rest of the request. loadOfficer() reads through PreferencesReader::read() for the
 * same reason.
 *
 * FAILURE. Called AFTER the catering write and its case entry are committed. notify() catches
 * Throwable at every level and cannot throw: a PHP 8 TypeError is an Error, not an Exception, and
 * the notification chains here have swallowed or 500'd on exactly that before
 * (AllocationWithdrawalService, EmailService::getBuildingInfo). A recipient whose mail cannot be
 * composed does not stop the others. The slow part — the SMTP send — is deferred the way
 * ApplicationCommentsService defers its mail, so it never holds the response.
 *
 * The methods marked "seam" exist so a test can replace exactly the part that touches the outside
 * world and leave the rest of the chain real.
 */
class CateringChangeNotifier
{
    /** The booking config key of the switch (D4). */
    public const SWITCH_KEY = 'notify_case_officer_on_catering_change';

    private const DEFAULT_LANG = 'no';
    private const SUPPORTED_LANGS = ['no', 'nn', 'en'];

    private CateringMailRenderer $renderer;

    public function __construct(?CateringMailRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new CateringMailRenderer(new TranslationLangSwitch());
    }

    /**
     * @param array $payload see CateringMailRenderer::render(); plus
     *                       application.case_officer_id (int) and application.building_id (int) —
     *                       the links are added here
     * @return bool true when at least one mail was composed and handed to the (deferred) transport
     */
    public function notify(array $payload): bool
    {
        try {
            $application = $payload['application'] ?? [];
            $applicationId = (int)($application['id'] ?? 0);

            if (!$this->notificationsEnabled()) {
                return false;
            }

            $recipients = $this->recipients(
                $applicationId,
                (int)($application['case_officer_id'] ?? 0),
                (int)($application['building_id'] ?? 0)
            );
            if ($recipients === []) {
                return false;
            }

            $payload['links'] = $this->buildLinks($applicationId, (int)($payload['order']['id'] ?? 0));

            $handed = 0;
            foreach ($recipients as $recipient) {
                try {
                    $mail = $this->renderer->render($payload, $recipient['lang']);
                } catch (\Throwable $e) {
                    error_log("Catering change mail to account {$recipient['account_id']} for application {$applicationId} not composed: " . $e->getMessage());
                    continue;
                }
                $email = $recipient['email'];
                $this->defer(function () use ($email, $mail, $applicationId): void {
                    try {
                        $this->deliver($email, $mail['subject'], $mail['body']);
                    } catch (\Throwable $e) {
                        error_log("Catering change mail for application {$applicationId} failed: " . $e->getMessage());
                    }
                });
                $handed++;
            }
            return $handed > 0;
        } catch (\Throwable $e) {
            error_log('Catering change notification failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Who gets the mail, each with an address and a language. See WHO above.
     *
     * @return list<array{account_id: int, email: string, lang: string}>
     */
    protected function recipients(int $applicationId, int $officerId, int $buildingId): array
    {
        if ($officerId > 0) {
            $officer = $this->loadOfficer($officerId);
            $email = self::usableAddress($officer['email'] ?? null);
            if ($email !== null) {
                return [['account_id' => $officerId, 'email' => $email, 'lang' => self::language($officer['lang'] ?? null)]];
            }
            error_log("Catering change mail: case officer {$officerId} of application {$applicationId} has no usable email address; mailing the building's case officers instead");
        }

        if ($buildingId <= 0) {
            error_log("Catering change mail not sent: application {$applicationId} has no case officer to mail and no building");
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $this->loadBuildingCaseOfficerIds($buildingId))));
        sort($ids);
        if ($ids === []) {
            error_log("Catering change mail not sent: application {$applicationId} has no case officer to mail and building {$buildingId} has no active case officer");
            return [];
        }

        $rows = $this->readCommonPreferenceRows($ids);
        $out = [];
        $seen = [];
        foreach ($ids as $id) {
            $common = PreferencesReader::resolveApp(array_values(array_filter(
                $rows,
                static fn(array $row): bool => in_array((int)$row['preference_owner'], [-1, -2, $id], true)
            )));
            $email = self::usableAddress($common['email'] ?? null);
            if ($email === null || isset($seen[strtolower($email)])) {
                continue;
            }
            $seen[strtolower($email)] = true;
            $out[] = ['account_id' => $id, 'email' => $email, 'lang' => self::language($common['lang'] ?? null)];
        }
        if ($out === []) {
            error_log("Catering change mail not sent: none of building {$buildingId}'s case officers has a usable email address (application {$applicationId})");
        }
        return $out;
    }

    /** Absent (or empty) is ON — the behaviour as shipped. Only a stored '0' is OFF. */
    public static function switchIsOn(?string $stored): bool
    {
        return $stored === null || trim($stored) !== '0';
    }

    private static function usableAddress(mixed $email): ?string
    {
        $email = trim((string)($email ?? ''));
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private static function language(mixed $lang): string
    {
        $lang = (string)($lang ?? '');
        return in_array($lang, self::SUPPORTED_LANGS, true) ? $lang : self::DEFAULT_LANG;
    }

    /**
     * seam — the switch (D4). A switch that cannot be read counts as absent, i.e. ON.
     */
    protected function notificationsEnabled(): bool
    {
        try {
            $stmt = Db::getInstance()->prepare(
                "SELECT config_value FROM phpgw_config WHERE config_app = 'booking' AND config_name = :name"
            );
            $stmt->execute([':name' => self::SWITCH_KEY]);
            $value = $stmt->fetchColumn();
            return self::switchIsOn($value === false || $value === null ? null : (string)$value);
        } catch (\Throwable $e) {
            error_log('Catering change mail switch could not be read, treated as on: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * seam — the case officer's address and language, read WITHOUT making the officer the current
     * user. CreateObject('phpgwapi.preferences', $id) would: it returns the Preferences singleton and
     * rewrites Settings['user'] for the rest of the citizen's request (see PreferencesReader).
     *
     * @return array{email: ?string, lang: ?string}
     */
    protected function loadOfficer(int $accountId): array
    {
        $common = PreferencesReader::read($accountId, ['common'])['common'] ?? [];
        return [
            'email' => $common['email'] ?? null,
            'lang' => $common['lang'] ?? null,
        ];
    }

    /**
     * seam — the active accounts holding the `case_officer` role on the building (the bb_permission
     * rows EmailService::getMailAddresses() reads through sopermission_building, narrowed to the role).
     *
     * @return list<int>
     */
    protected function loadBuildingCaseOfficerIds(int $buildingId): array
    {
        $stmt = Db::getInstance()->prepare(
            "SELECT DISTINCT p.subject_id
             FROM bb_permission p
             JOIN phpgw_accounts a ON a.account_id = p.subject_id
             WHERE p.object_type = 'building' AND p.object_id = :building_id
               AND p.role = 'case_officer' AND a.account_status = 'A'
             ORDER BY p.subject_id"
        );
        $stmt->execute([':building_id' => $buildingId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * seam — the stored `common` preference rows of these accounts plus the forced (-1) and default
     * (-2) rows, in one query: the select of Preferences::Readpreferences() (Preferences.php:116)
     * for several owners, narrowed to the one app. Never through the Preferences singleton.
     *
     * @param list<int> $accountIds
     * @return list<array{preference_owner: int|string, preference_json: string}>
     */
    protected function readCommonPreferenceRows(array $accountIds): array
    {
        $ids = array_values(array_map('intval', $accountIds));
        $owners = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::getInstance()->prepare(
            "SELECT preference_owner, preference_json FROM phpgw_preferences
             WHERE preference_owner IN (-1, -2" . ($ids === [] ? '' : ", {$owners}") . ")
               AND REPLACE(preference_app, ' ', '') = 'common'"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * seam — absolute links to the case and to the order page in the booking backend.
     *
     * @return array{case: string, order: string}
     */
    protected function buildLinks(int $applicationId, int $orderId): array
    {
        $email = new EmailService();
        return [
            'case' => $email->buildBackendLink('/booking/view/applications/' . $applicationId),
            'order' => $email->buildBackendLink('/booking/view/hospitality-orders/' . $orderId, ['application_id' => $applicationId]),
        ];
    }

    /** seam — run the send after the response where the SAPI allows it. */
    protected function defer(callable $work): void
    {
        WebSocketHelper::forkNotification($work);
    }

    /** seam — the transport. */
    protected function deliver(string $to, string $subject, string $body): void
    {
        (new EmailService())->sendCateringChangeNotification($to, $subject, $body);
    }
}
