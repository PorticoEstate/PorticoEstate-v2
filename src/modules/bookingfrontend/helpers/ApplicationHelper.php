<?php

namespace App\modules\bookingfrontend\helpers;

use App\helpers\OwnerIdentifier;
use App\modules\phpgwapi\security\Sessions;
use Psr\Http\Message\ServerRequestInterface;

class ApplicationHelper
{
    private UserHelper $userHelper;

    public function __construct()
    {
        $this->userHelper = new UserHelper();
    }

    /**
     * Whether this request was authorised by the application's own secret link
     * rather than by a session. Extracted verbatim from canViewApplication so the
     * two callers cannot drift apart.
     *
     * @param array $application The application data to check
     * @param ServerRequestInterface $request The request to check for secret parameter
     * @return bool True if the request carries this application's secret
     */
    private function hasValidSecret(array $application, ServerRequestInterface $request): bool
    {
        $queryParams = $request->getQueryParams();
        $secret = $queryParams['secret'] ?? null;

        return (bool)$secret
            && isset($application['secret'])
            && $application['secret'] === $secret;
    }

    /**
     * Resolve who is acting on an application, for the comment `author` column.
     *
     * A secret-link caller has no session, so UserHelper cannot name them and
     * ApplicationCommentsService's fallback yields null against a NOT NULL column.
     * For them the acting party is the applicant the link was mailed to, which is
     * what bb_application.contact_name records.
     *
     * Returns null for session-authenticated callers so the existing fallback to
     * the logged-in user's name is left exactly as it was.
     *
     * @param array $application The application being commented on
     * @param ServerRequestInterface $request The request whose authorisation mode decides
     * @return string|null The author to record, or null to defer to the session user
     */
    public function resolveCommentAuthor(array $application, ServerRequestInterface $request): ?string
    {
        if ($this->userHelper->is_logged_in()) {
            return null;
        }

        if (!$this->hasValidSecret($application, $request)) {
            return null;
        }

        $contactName = trim((string)($application['contact_name'] ?? ''));

        return $contactName !== '' ? $contactName : null;
    }

    /**
     * Check if the current user can view the given application
     * Supports both secret-based access and direct access (org or ssn)
     *
     * An identifier stored on the application makes nobody its owner when it is empty, nor when it is the
     * organisation number '000000000': MinId2 gives that one to every citizen without an organisation and to every
     * organisation with an ssn and no number, so it says nothing about whose application it is (OwnerIdentifier).
     * The compares are strict, so a stored identifier that passes is also exactly the viewer's.
     *
     * @param array $application The application data to check
     * @param ServerRequestInterface $request The request to check for secret parameter
     * @return bool True if user can view the application, false otherwise
     */
    public function canViewApplication(array $application, ServerRequestInterface $request): bool
    {
        if ($this->hasValidSecret($application, $request)) {
            return true; // Access allowed with correct secret
        }

        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        // Check if application belongs to current session (a partial stored without one belongs to no request)
        if ($application['status'] === 'NEWPARTIAL1'
            && !OwnerIdentifier::isEmpty($application['session_id'])
            && $application['session_id'] === $session_id) {
            return true;
        }

        // Additional checks if user is logged in
        if ($this->userHelper->is_logged_in()) {
            $ssn = $this->userHelper->ssn;
            $orgnr = $this->userHelper->orgnr;

            if (!OwnerIdentifier::isEmpty($application['customer_ssn'])
                && $application['customer_ssn'] === $ssn) {
                return true;
            }

            $ownedByNumber = $application['customer_identifier_type'] === 'organization_number'
                && OwnerIdentifier::identifiesOrganization($application['customer_organization_number']);

            if ($ownedByNumber && $application['customer_organization_number'] === $orgnr) {
                return true;
            }

            if ($ownedByNumber && $this->userHelper->organizations) {
                foreach ($this->userHelper->organizations as $org) {
                    if ($org['orgnr'] === $application['customer_organization_number']) {
                        return true;
                    }
                }
            }

            // What the list shows the user, the user can open: the list also matches an
            // application by customer_organization_id alone (ApplicationRepository::
            // getApplicationsBySsnAndOrganizations), and an application can carry an
            // organization id and no number. Like the list, not for a NEWPARTIAL1.
            if ($application['status'] !== 'NEWPARTIAL1'
                && !empty($application['customer_organization_id'])
                && $this->userHelper->organizations) {
                foreach ($this->userHelper->organizations as $org) {
                    if ((int)$org['org_id'] === (int)$application['customer_organization_id']) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if the current user can modify the given application
     * Same as canViewApplication for now, but separated for future different logic
     *
     * @param array $application The application data to check
     * @param ServerRequestInterface $request The request to check for secret parameter
     * @return bool True if user can modify the application, false otherwise
     */
    public function canModifyApplication(array $application, ServerRequestInterface $request): bool
    {
        return $this->canViewApplication($application, $request);
    }

    /**
     * Check if the current user can add comments to the given application
     * For now, same as canViewApplication, but might have different rules in the future
     *
     * @param array $application The application data to check
     * @param ServerRequestInterface $request The request to check for secret parameter
     * @return bool True if user can add comments, false otherwise
     */
    public function canAddComments(array $application, ServerRequestInterface $request): bool
    {
        return $this->canViewApplication($application, $request);
    }

    /**
     * Check if the current user can update the status of the given application
     * Might require higher permissions than just viewing
     *
     * @param array $application The application data to check
     * @param ServerRequestInterface $request The request to check for secret parameter
     * @return bool True if user can update status, false otherwise
     */
    public function canUpdateStatus(array $application, ServerRequestInterface $request): bool
    {
        // For now, same as modify, but could be restricted to admin users later
        return $this->canModifyApplication($application, $request);
    }
}