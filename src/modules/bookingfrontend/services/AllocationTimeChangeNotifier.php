<?php

namespace App\modules\bookingfrontend\services;

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
     * seam — the case officer's address and language.
     *
     * @return array{email: ?string, lang: ?string}
     */
    protected function loadOfficer(int $accountId): array
    {
        $prefs = CreateObject('phpgwapi.preferences', $accountId)->read();
        return [
            'email' => $prefs['common']['email'] ?? null,
            'lang' => $prefs['common']['lang'] ?? null,
        ];
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
        (new EmailService())->sendCaseOfficerNotification($to, $subject, $body);
    }
}
