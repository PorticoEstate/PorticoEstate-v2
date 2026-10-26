<?php

namespace App\modules\bookingfrontend\services\applications;

use App\modules\booking\services\CateringMailRenderer;
use App\modules\booking\services\EmailService;
use App\modules\booking\services\TranslationLangSwitch;
use App\modules\bookingfrontend\helpers\WebSocketHelper;

/**
 * Tells the application's case officer that the applicant added, changed or cancelled a catering
 * order from Min side (GH #1425, operator rulings 34077 / 34082).
 *
 * WHO. The one case officer on bb_application.case_officer_id — never the group, never the
 * building's role holders. The address is that account's own `common.email` preference, the same
 * source EmailService::getMailAddresses() reads (but for that one account only). No case officer:
 * no mail. An officer without a usable address: no mail, and a log line saying whose.
 *
 * LANGUAGE. The officer's own `common.lang`, else Norwegian (the instance language; existing staff
 * mails are Norwegian-only). The citizen's cookie never decides it — see CateringMailRenderer.
 *
 * FAILURE. Called AFTER the catering write and its case entry are committed. notify() catches
 * Throwable at every level and cannot throw: a PHP 8 TypeError is an Error, not an Exception, and
 * the notification chains here have swallowed or 500'd on exactly that before
 * (AllocationWithdrawalService, EmailService::getBuildingInfo). The slow part — the SMTP send — is
 * deferred the way ApplicationCommentsService defers its mail, so it never holds the response.
 *
 * The methods marked "seam" exist so a test can replace exactly the part that touches the outside
 * world and leave the rest of the chain real.
 */
class CateringChangeNotifier
{
    private const DEFAULT_LANG = 'no';
    private const SUPPORTED_LANGS = ['no', 'nn', 'en'];

    private CateringMailRenderer $renderer;

    public function __construct(?CateringMailRenderer $renderer = null)
    {
        $this->renderer = $renderer ?? new CateringMailRenderer(new TranslationLangSwitch());
    }

    /**
     * @param array $payload see CateringMailRenderer::render(); plus
     *                       application.case_officer_id (int) — links are added here
     * @return bool true when a mail was composed and handed to the (deferred) transport
     */
    public function notify(array $payload): bool
    {
        try {
            $application = $payload['application'] ?? [];
            $applicationId = (int)($application['id'] ?? 0);
            $officerId = (int)($application['case_officer_id'] ?? 0);

            // Ruling (d): no case officer, no mail. The change is logged in the case regardless.
            if ($officerId <= 0) {
                return false;
            }

            $officer = $this->loadOfficer($officerId);
            $email = trim((string)($officer['email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                error_log("Catering change mail not sent: case officer {$officerId} of application {$applicationId} has no usable email address");
                return false;
            }

            $lang = (string)($officer['lang'] ?? '');
            if (!in_array($lang, self::SUPPORTED_LANGS, true)) {
                $lang = self::DEFAULT_LANG;
            }

            $payload['links'] = $this->buildLinks($applicationId, (int)($payload['order']['id'] ?? 0));
            $mail = $this->renderer->render($payload, $lang);

            $this->defer(function () use ($email, $mail, $applicationId): void {
                try {
                    $this->deliver($email, $mail['subject'], $mail['body']);
                } catch (\Throwable $e) {
                    error_log("Catering change mail for application {$applicationId} failed: " . $e->getMessage());
                }
            });
            return true;
        } catch (\Throwable $e) {
            error_log('Catering change notification failed: ' . $e->getMessage());
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
