<?php

namespace App\modules\bookingfrontend\controllers\applications;

use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\helpers\WebSocketHelper;
use App\modules\bookingfrontend\services\applications\ApplicationService;
use App\modules\bookingfrontend\services\applications\CheckoutService;
use App\modules\bookingfrontend\services\applications\VippsService;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Checkout",
 *     description="API Endpoints for Application Checkout and Payment"
 * )
 */
class CheckoutController
{
    private $bouser;
    private $applicationService;
    private $checkoutService;
    private $vippsService;
    private $userSettings;

    public function __construct(ContainerInterface $container)
    {
        $this->bouser = new UserHelper();
        $this->applicationService = new ApplicationService();
        $this->checkoutService = new CheckoutService();
        $this->vippsService = new VippsService();
        $this->userSettings = Settings::getInstance()->get('user');
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/applications/partials/checkout",
     *     summary="Update and finalize all partial applications with contact and organization info",
     *     tags={"Checkout"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"customerType", "contactName", "contactEmail", "contactPhone"},
     *             @OA\Property(property="customerType", type="string", enum={"ssn", "organization_number"}),
     *             @OA\Property(property="organizationNumber", type="string"),
     *             @OA\Property(property="organizationName", type="string"),
     *             @OA\Property(property="contactName", type="string"),
     *             @OA\Property(property="contactEmail", type="string"),
     *             @OA\Property(property="contactPhone", type="string"),
     *             @OA\Property(property="street", type="string"),
     *             @OA\Property(property="zipCode", type="string"),
     *             @OA\Property(property="city", type="string"),
     *             @OA\Property(property="organizerName", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Applications updated successfully"
     *     )
     * )
     */
    public function checkout(Request $request, Response $response): Response
    {
        try {
            $session = Sessions::getInstance();
            $session_id = $session->get_session_id();

            if (empty($session_id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No active session'],
                    400
                );
            }

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid JSON data'],
                    400
                );
            }

            try {
                // Update all partial applications
                $result = $this->applicationService->checkoutPartials($session_id, $data);

                // Add timestamp and session info for debugging
                $result['debug_timestamp'] = date('Y-m-d H:i:s');
                $result['debug_session_id'] = $session_id;

                // Determine message based on direct booking and count
                // Check if at least one application is a direct booking
                $has_direct_booking = false;
                foreach ($result['updated'] as $app) {
                    if ($app['status'] === 'ACCEPTED') {
                        $has_direct_booking = true;
                        break;
                    }
                }

                // Define message sets based on direct booking status
                if ($has_direct_booking) {
                    $messages = array(
                        'one' => array(
                            'registered' => 'application_processed_single',
                            'review' => ''
                        ),
                        'multiple' => array(
                            'registered' => 'applications_processed_multiple',
                            'review' => ''
                        )
                    );
                } else {
                    $messages = array(
                        'one' => array(
                            'registered' => 'application_registered_single',
                            'review' => 'case_officer_review_single'
                        ),
                        'multiple' => array(
                            'registered' => 'applications_registered_multiple',
                            'review' => 'case_officer_review_multiple'
                        )
                    );
                }

                // Choose message set based on count
                $msgset = count($result['updated']) > 1 ? 'multiple' : 'one';

                // Build message array
                $message_arr = array();
                $message_arr[] = lang($messages[$msgset]['registered']);
                if ($messages[$msgset]['review']) {
                    $message_arr[] = lang($messages[$msgset]['review']);
                }
                $message_arr[] = lang("Please check your Spam Filter if you are missing mail.");

                // Set message in cache
                Cache::message_set(implode("<br/>", $message_arr), 'message', 'booking.booking confirmed');
                WebSocketHelper::triggerPartialApplicationsUpdate($session_id);

                return ResponseHelper::sendJSONResponse([
                    'message' => 'Applications processed successfully',
                    'applications' => $result['updated'],
                    'skipped' => $result['skipped'],
                    'debug_info' => [
                        'collisions' => $result['debug_collisions'],
                        'timestamp' => $result['debug_timestamp'],
                        'session_id' => $result['debug_session_id']
                    ]
                ]);

            } catch (Exception $e) {
                // Check if the error message contains validation errors
                if (str_contains($e->getMessage(), ',')) {
                    // This is likely a validation error with multiple messages
                    return ResponseHelper::sendErrorResponse(
                        ['errors' => explode(', ', $e->getMessage())],
                        400
                    );
                }
                throw $e;
            }

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/applications/validate-checkout",
     *     summary="Validate if checkout of partial applications would succeed",
     *     tags={"Checkout"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"customerType", "contactName", "contactEmail", "contactPhone"},
     *             @OA\Property(property="customerType", type="string", enum={"ssn", "organization_number"}),
     *             @OA\Property(property="organizationNumber", type="string"),
     *             @OA\Property(property="organizationName", type="string"),
     *             @OA\Property(property="contactName", type="string"),
     *             @OA\Property(property="contactEmail", type="string"),
     *             @OA\Property(property="contactPhone", type="string"),
     *             @OA\Property(property="street", type="string"),
     *             @OA\Property(property="zipCode", type="string"),
     *             @OA\Property(property="city", type="string"),
     *             @OA\Property(property="eventTitle", type="string"),
     *             @OA\Property(property="organizerName", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Validation results",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="valid", type="boolean"),
     *             @OA\Property(
     *                 property="applications",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="valid", type="boolean"),
     *                     @OA\Property(property="would_be_direct_booking", type="boolean"),
     *                     @OA\Property(
     *                         property="issues",
     *                         type="array",
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(property="type", type="string"),
     *                             @OA\Property(property="message", type="string")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid data or no active session"
     *     )
     * )
     */
    public function validateCheckout(Request $request, Response $response): Response
    {
        try {
            $session = Sessions::getInstance();
            $session_id = $session->get_session_id();

            if (empty($session_id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No active session'],
                    400
                );
            }

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid JSON data'],
                    400
                );
            }

            // Validate checkout
            $validationResults = $this->applicationService->validateCheckout($session_id, $data);

            $validationResults['debug_timestamp'] = date('Y-m-d H:i:s');
            $validationResults['debug_session_id'] = $session_id;

            return ResponseHelper::sendJSONResponse($validationResults);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/checkout/external-payment-eligibility",
     *     summary="Check eligibility for external payment methods",
     *     tags={"Checkout"},
     *     @OA\Response(
     *         response=200,
     *         description="Payment eligibility information",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="eligible", type="boolean"),
     *             @OA\Property(property="reason", type="string"),
     *             @OA\Property(property="total_amount", type="number"),
     *             @OA\Property(property="applications_count", type="integer"),
     *             @OA\Property(
     *                 property="payment_methods",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="method", type="string"),
     *                     @OA\Property(property="logo", type="string")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="No active session"
     *     )
     * )
     */
    public function checkExternalPaymentEligibility(Request $request, Response $response): Response
    {
        try {
            $session = Sessions::getInstance();
            $session_id = $session->get_session_id();

            if (empty($session_id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No active session'],
                    400
                );
            }

            $eligibilityResult = $this->checkoutService->checkExternalPaymentEligibility($session_id);

            return ResponseHelper::sendJSONResponse($eligibilityResult);

        } catch (Exception $e) {
            // Log the error but don't expose sensitive configuration details
            error_log("External payment eligibility check failed: " . $e->getMessage());

            // Return a safe error response
            return ResponseHelper::sendJSONResponse([
                'eligible' => false,
                'reason' => 'External payment service not available',
                'payment_methods' => []
            ]);
        }
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/applications/partials/vipps-payment",
     *     summary="Initiate Vipps payment for partial applications",
     *     tags={"Checkout"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"eventTitle", "organizerName", "contactName", "contactEmail", "contactPhone"},
     *             @OA\Property(property="eventTitle", type="string"),
     *             @OA\Property(property="organizerName", type="string"),
     *             @OA\Property(property="contactName", type="string"),
     *             @OA\Property(property="contactEmail", type="string"),
     *             @OA\Property(property="contactPhone", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Vipps payment initiated successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="redirect_url", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid data or Vipps not available"
     *     )
     * )
     */
    public function initiateVippsPayment(Request $request, Response $response): Response
    {
        try {
            $session = Sessions::getInstance();
            $session_id = $session->get_session_id();

            if (empty($session_id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'No active session'],
                    400
                );
            }

            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Invalid JSON data'],
                    400
                );
            }

            // 1. Validate and update applications with contact info
            try {
                $updatedApplications = $this->applicationService->updateApplicationsWithContactInfo($session_id, $data, false);

                // 2. Separate applications into direct bookings and normal applications
                $directApplications = [];
                $normalApplications = [];

                $currentUnixTime = time();

                foreach ($updatedApplications as $application) {
                    $isDirectBooking = false;
                    if (isset($application['resources']) && is_array($application['resources'])) {
                        foreach ($application['resources'] as $resource) {
                            if (isset($resource['direct_booking']) && !empty($resource['direct_booking'])) {
                                // Check if current time is past the direct booking start time
                                if ($currentUnixTime > $resource['direct_booking']) {
                                    $isDirectBooking = true;
                                    break;
                                }
                            }
                        }
                    }

                    if ($isDirectBooking) {
                        $directApplications[] = $application;
                    } else {
                        $normalApplications[] = $application;
                    }
                }

                // 3. Submit normal applications immediately if any exist
                if (!empty($normalApplications)) {
                    // We need to submit only normal applications by updating their session temporarily
                    // Store current partial applications and restore only normal ones for checkout
                    $session = Sessions::getInstance();
                    $originalPartials = $this->applicationService->getPartialApplications($session_id);

                    // Temporarily clear partials and add only normal applications
                    $uiApplication = CreateObject('booking.uiapplication');
                    foreach ($originalPartials as $original) {
                        if (!in_array($original['id'], array_column($normalApplications, 'id'))) {
                            // Remove non-normal applications temporarily
                            $uiApplication->remove_partial($session_id, $original['id']);
                        }
                    }

                    // Submit normal applications
                    $normalResult = $this->applicationService->checkoutPartials($session_id, $data);

                    // Restore direct applications to partial state for payment
                    foreach ($directApplications as $directApp) {
                        $uiApplication->add_partial($directApp, $session_id);
                    }
                }

                // 4. Handle Vipps payment for direct applications (if eligible)
                if (!empty($directApplications)) {
                    // Calculate total amount for Vipps payment
                    $totalAmount = $this->applicationService->calculateTotalSum($directApplications);

                    if ($totalAmount <= 0) {
                        return ResponseHelper::sendErrorResponse(
                            ['error' => 'No payment required for direct applications'],
                            400
                        );
                    }

                    // Validate Vipps configuration
                    if (!$this->vippsService->isConfigured()) {
                        return ResponseHelper::sendErrorResponse([
                            'error' => lang('vipps_not_configured')
                        ], 500);
                    }

                    // Initialize Vipps payment using VippsService
                    $direct_application_ids = array_column($directApplications, 'id');

                    try {
                        $paymentResult = $this->vippsService->initiatePayment($direct_application_ids);

                        if (isset($paymentResult['url'])) {
                            return ResponseHelper::sendJSONResponse([
                                'success' => true,
                                'redirect_url' => $paymentResult['url'],
                                'orderId' => $paymentResult['orderId'] ?? null,
                                'normal_applications_submitted' => count($normalApplications),
                                'direct_applications_count' => count($directApplications)
                            ]);
                        } else {
                            // Handle Vipps initiation failure
                            return ResponseHelper::sendErrorResponse([
                                'error' => lang('vipps_init_failed'),
                                'details' => is_array($paymentResult) ? json_encode($paymentResult) : 'Unknown error'
                            ], 500);
                        }
                    } catch (Exception $vippsException) {
                        return ResponseHelper::sendErrorResponse([
                            'error' => lang('vipps_init_failed'),
                            'details' => $vippsException->getMessage()
                        ], 500);
                    }
                } else {
                    // Only normal applications - redirect to success page
                    return ResponseHelper::sendJSONResponse([
                        'success' => true,
                        'redirect_url' => '/user/applications',
                        'normal_applications_submitted' => count($normalApplications),
                        'direct_applications_count' => 0
                    ]);
                }

            } catch (Exception $e) {
                // Check if the error message contains validation errors
                if (str_contains($e->getMessage(), ',')) {
                    // This is likely a validation error with multiple messages
                    return ResponseHelper::sendErrorResponse(
                        ['errors' => explode(', ', $e->getMessage())],
                        400
                    );
                }
                throw $e;
            }

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/checkout/vipps/check-payment-status",
     *     summary="Check Vipps payment status and process payment",
     *     tags={"Checkout"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"payment_order_id"},
     *             @OA\Property(property="payment_order_id", type="string", description="Payment order ID from Vipps")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment status check result",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="string"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="applications_approved", type="boolean")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid payment order ID"
     *     )
     * )
     */
    public function checkVippsPaymentStatus(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data || empty($data['payment_order_id'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'payment_order_id is required'],
                    400
                );
            }

            $payment_order_id = $data['payment_order_id'];

            // Validate Vipps configuration
            if (!$this->vippsService->isConfigured()) {
                return ResponseHelper::sendErrorResponse([
                    'error' => lang('vipps_not_configured')
                ], 500);
            }

            // Process payment status with business logic
            $result = $this->vippsService->processPaymentStatus($payment_order_id);

            return ResponseHelper::sendJSONResponse($result);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Get(
     *     path="/bookingfrontend/checkout/vipps/payment-details/{payment_order_id}",
     *     summary="Get Vipps payment details",
     *     tags={"Checkout"},
     *     @OA\Parameter(
     *         name="payment_order_id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="string"),
     *         description="Payment order ID from Vipps"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment details from Vipps API",
     *         @OA\JsonContent(type="object")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Payment not found"
     *     )
     * )
     */
    public function getVippsPaymentDetails(Request $request, Response $response, array $args): Response
    {
        try {
            $payment_order_id = $args['payment_order_id'] ?? '';

            if (empty($payment_order_id)) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'payment_order_id is required'],
                    400
                );
            }

            // Validate Vipps configuration
            if (!$this->vippsService->isConfigured()) {
                return ResponseHelper::sendErrorResponse([
                    'error' => lang('vipps_not_configured')
                ], 500);
            }

            // Get payment details from Vipps API
            $details = $this->vippsService->getPaymentDetails($payment_order_id);

            return ResponseHelper::sendJSONResponse($details);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/checkout/vipps/cancel-payment",
     *     summary="Cancel Vipps payment",
     *     tags={"Checkout"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"payment_order_id"},
     *             @OA\Property(property="payment_order_id", type="string", description="Payment order ID from Vipps")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment cancellation result",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid payment order ID"
     *     )
     * )
     */
    public function cancelVippsPayment(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data || empty($data['payment_order_id'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'payment_order_id is required'],
                    400
                );
            }

            $payment_order_id = $data['payment_order_id'];

            // Validate Vipps configuration
            if (!$this->vippsService->isConfigured()) {
                return ResponseHelper::sendErrorResponse([
                    'error' => lang('vipps_not_configured')
                ], 500);
            }

            // Cancel payment
            $result = $this->vippsService->cancelPayment($payment_order_id);

            // Update payment status in database
            $soapplication = CreateObject('booking.soapplication');
            $soapplication->update_payment_status($payment_order_id, 'voided', 'CANCEL');

            return ResponseHelper::sendJSONResponse([
                'success' => true,
                'message' => 'Payment cancelled successfully',
                'vipps_response' => $result
            ]);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/checkout/vipps/refund-payment",
     *     summary="Refund Vipps payment",
     *     tags={"Checkout"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"payment_order_id", "amount"},
     *             @OA\Property(property="payment_order_id", type="string", description="Payment order ID from Vipps"),
     *             @OA\Property(property="amount", type="number", description="Amount to refund in kroner (will be converted to øre)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment refund result",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid payment order ID or amount"
     *     )
     * )
     */
    public function refundVippsPayment(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            if (!$data || empty($data['payment_order_id']) || !isset($data['amount'])) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'payment_order_id and amount are required'],
                    400
                );
            }

            $payment_order_id = $data['payment_order_id'];
            $amount = (float)$data['amount'];

            if ($amount <= 0) {
                return ResponseHelper::sendErrorResponse(
                    ['error' => 'Amount must be greater than 0'],
                    400
                );
            }

            // Validate Vipps configuration
            if (!$this->vippsService->isConfigured()) {
                return ResponseHelper::sendErrorResponse([
                    'error' => lang('vipps_not_configured')
                ], 500);
            }

            // Convert amount to øre (cents)
            $amount_ore = (int)($amount * 100);

            // Refund payment
            $result = $this->vippsService->refundPayment($payment_order_id, $amount_ore);

            // Update payment status in database
            $soapplication = CreateObject('booking.soapplication');
            $soapplication->update_payment_status($payment_order_id, 'refunded', 'REFUND', $amount);

            return ResponseHelper::sendJSONResponse([
                'success' => true,
                'message' => 'Payment refunded successfully',
                'refunded_amount' => $amount,
                'vipps_response' => $result
            ]);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }

    /**
     * @OA\Post(
     *     path="/bookingfrontend/checkout/vipps/post-to-accounting",
     *     summary="Post completed Vipps transactions to accounting system",
     *     tags={"Checkout"},
     *     @OA\Response(
     *         response=200,
     *         description="Accounting posting results",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="posted_transactions", type="integer"),
     *             @OA\Property(property="posted_refunds", type="integer"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Accounting system error"
     *     )
     * )
     */
    public function postVippsToAccounting(Request $request, Response $response): Response
    {
        try {
            // Validate Vipps configuration
            if (!$this->vippsService->isConfigured()) {
                return ResponseHelper::sendErrorResponse([
                    'error' => lang('vipps_not_configured')
                ], 500);
            }

            // Post transactions to accounting system
            $results = $this->vippsService->postToAccountingSystem();

            return ResponseHelper::sendJSONResponse($results);

        } catch (Exception $e) {
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
        }
    }
}