<?php

namespace App\modules\bookingfrontend\helpers;

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
     * Check if the current user can view the given application
     * Supports both secret-based access and direct access (org or ssn)
     *
     * @param array $application The application data to check
     * @param ServerRequestInterface $request The request to check for secret parameter
     * @return bool True if user can view the application, false otherwise
     */
    public function canViewApplication(array $application, ServerRequestInterface $request): bool
    {
        // Check for secret parameter in GET/POST
        $queryParams = $request->getQueryParams();
        $secret = $queryParams['secret'] ?? null;
        
        if ($secret && isset($application['secret']) && $application['secret'] === $secret) {
            return true; // Access allowed with correct secret
        }

        $session = Sessions::getInstance();
        $session_id = $session->get_session_id();

        // Check if application belongs to current session
        if ($application['status'] === 'NEWPARTIAL1' && $application['session_id'] === $session_id) {
            return true;
        }

        // Additional checks if user is logged in
        if ($this->userHelper->is_logged_in()) {
            $ssn = $this->userHelper->ssn;
            $orgnr = $this->userHelper->orgnr;

            if ($application['customer_ssn'] === $ssn) {
                return true;
            }

            if ($application['customer_identifier_type'] === 'organization_number'
                && $application['customer_organization_number'] === $orgnr) {
                return true;
            }

            if ($application['customer_identifier_type'] === 'organization_number'
                && $this->userHelper->organizations) {
                foreach ($this->userHelper->organizations as $org) {
                    if ($org['orgnr'] === $application['customer_organization_number']) {
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