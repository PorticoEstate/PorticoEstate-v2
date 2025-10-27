<?php

namespace App\modules\bookingfrontend\services\applications;

use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\security\Sessions;
use Exception;

class CheckoutService
{
    private $applicationService;

    public function __construct()
    {
        $this->applicationService = new ApplicationService();
    }

    /**
     * Check eligibility for external payment methods (Vipps, etc.)
     *
     * @param string $session_id Current session ID
     * @return array Payment eligibility details
     */
    public function checkExternalPaymentEligibility(string $session_id): array
    {
        try {
            // Get partial applications for this session
            $applications = $this->applicationService->getPartialApplications($session_id);
            
            if (empty($applications)) {
                return [
                    'eligible' => false,
                    'reason' => 'No partial applications found',
                    'payment_methods' => []
                ];
            }

            // Check if any applications have recurring booking data
            // Recurring bookings should not be eligible for external payment as they require approval
            foreach ($applications as $application) {
                if (!empty($application['recurring_info'])) {
                    return [
                        'eligible' => false,
                        'reason' => lang('recurring_not_vipps_eligible'),
                        'total_amount' => 0,
                        'payment_methods' => []
                    ];
                }
            }

            // Calculate total amount
            $totalAmount = $this->applicationService->calculateTotalSum($applications);
            
            if ($totalAmount <= 0) {
                return [
                    'eligible' => false,
                    'reason' => 'No payment required',
                    'total_amount' => $totalAmount,
                    'payment_methods' => []
                ];
            }

            // Check if any resources require prepayment and get payment methods
            // This combines the validation logic from add_contact()
            $paymentMethods = $this->getAvailablePaymentMethods($applications);
            
            return [
                'eligible' => !empty($paymentMethods),
                'reason' => !empty($paymentMethods) ? 'External payment available' : 'No payment methods available (no prepayment required or not configured)',
                'total_amount' => $totalAmount,
                'payment_methods' => $paymentMethods,
                'applications_count' => count($applications)
            ];

        } catch (Exception $e) {
            return [
                'eligible' => false,
                'reason' => 'Error checking eligibility: ' . $e->getMessage(),
                'payment_methods' => []
            ];
        }
    }


    /**
     * Get available external payment methods based on configuration
     * Returns same format as booking.uiapplication->add_contact()
     * Uses exact same validation logic as the original method
     *
     * @param array $applications List of applications to check for prepayment requirement
     * @return array Available payment methods with method and logo properties
     */
    private function getAvailablePaymentMethods(array $applications): array
    {
        $payment_methods = [];
        
        try {
            // Inspect resources for prepayment - exact same logic as add_contact()
            $activate_prepayment = 0;
            foreach ($applications as $application) {
                // The resources array already contains prepayment info, no need to query again
                if (isset($application['resources']) && is_array($application['resources'])) {
                    foreach ($application['resources'] as $resource) {
                        if (isset($resource['activate_prepayment']) && $resource['activate_prepayment']) {
                            $activate_prepayment++;
                        }
                    }
                }
            }

            // Get booking configuration - same as add_contact()
            $location_obj = new Locations();
            $location_id = $location_obj->get_id('booking', 'run');
            $custom_config_obj = CreateObject('admin.soconfig', $location_id);
            $custom_config = $custom_config_obj->config_data;
            
            // Check Vipps availability - exact same condition as add_contact method
            if ($activate_prepayment && !empty($custom_config['payment']['method']) && !empty($custom_config['Vipps']['active'])) {
                // Additional check: verify Vipps is actually configured
                try {
                    $vippsService = new VippsService();
                    if ($vippsService->isConfigured()) {
                        $payment_methods[] = [
                            'method' => 'vipps',
                            'logo' => $this->getVippsLogo()
                        ];
                    }
                } catch (Exception $vippsException) {
                    // Vipps is enabled but not properly configured
                    error_log("Vipps is enabled but not properly configured: " . $vippsException->getMessage());
                }
            }
            
            // Add other payment methods here in the future
            // e.g., Stripe, PayPal, etc.
            
        } catch (Exception $e) {
            // Log error but don't throw, return empty array
            error_log("Error getting payment methods: " . $e->getMessage());
        }
        
        return $payment_methods;
    }


    /**
     * Get Vipps logo based on language
     *
     * @return string Logo image path
     */
    private function getVippsLogo(): string
    {
        $vipps_logo = 'continue_with_vipps_rect_210';
        
        // Get user language preference (similar to existing implementation)
        $userSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
        $lang = $userSettings['preferences']['common']['lang'] ?? 'en';
        
        switch ($lang) {
            case 'no':
            case 'nn':
                $vipps_logo .= "_NO";
                break;
            default:
                $vipps_logo .= "_EN";
                break;
        }
        
        // Return image path (would need phpgwapi_common for actual image URL)
        try {
            $phpgwapi_common = CreateObject('phpgwapi.common');
            return $phpgwapi_common->image('bookingfrontend', $vipps_logo);
        } catch (Exception $e) {
            return "/bookingfrontend/images/{$vipps_logo}.png"; // Fallback path
        }
    }

    /**
     * Validate that external payment can be processed
     *
     * @param string $session_id Session ID
     * @param string $payment_method Payment method (e.g., 'vipps')
     * @return array Validation result
     */
    public function validateExternalPaymentRequest(string $session_id, string $payment_method): array
    {
        $eligibility = $this->checkExternalPaymentEligibility($session_id);
        
        if (!$eligibility['eligible']) {
            return [
                'valid' => false,
                'error' => $eligibility['reason']
            ];
        }
        
        // Check if requested payment method is available
        $availableMethods = array_column($eligibility['payment_methods'], 'method');
        if (!in_array($payment_method, $availableMethods)) {
            return [
                'valid' => false,
                'error' => "Payment method '{$payment_method}' is not available"
            ];
        }
        
        return [
            'valid' => true,
            'total_amount' => $eligibility['total_amount'],
            'applications_count' => $eligibility['applications_count']
        ];
    }

}