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
     *             @OA\Property(property="eventTitle", type="string"),
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
                            'registered' => "Your application has now been processed and a confirmation email has been sent to you.",
                            'review' => ""
                        ),
                        'multiple' => array(
                            'registered' => "Your applications have now been processed and confirmation emails have been sent to you.",
                            'review' => ""
                        )
                    );
                } else {
                    $messages = array(
                        'one' => array(
                            'registered' => "Your application has now been registered and a confirmation email has been sent to you.",
                            'review' => "A Case officer will review your application as soon as possible."
                        ),
                        'multiple' => array(
                            'registered' => "Your applications have now been registered and confirmation emails have been sent to you.",
                            'review' => "A Case officer will review your applications as soon as possible."
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
            return ResponseHelper::sendErrorResponse(
                ['error' => $e->getMessage()],
                500
            );
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

            // 1. Validate and update applications with contact info (but keep them partial for Vipps payment)
            try {
                $updatedApplications = $this->applicationService->updateApplicationsWithContactInfo($session_id, $data, false);
                
                // 2. Calculate total amount for Vipps payment
                $totalAmount = $this->applicationService->calculateTotalSum($updatedApplications);
                
                if ($totalAmount <= 0) {
                    return ResponseHelper::sendErrorResponse(
                        ['error' => 'No payment required for these applications'],
                        400
                    );
                }

                // 3. Validate Vipps configuration
                if (!$this->vippsService->isConfigured()) {
                    return ResponseHelper::sendErrorResponse([
                        'error' => 'Vipps payment is not properly configured'
                    ], 500);
                }

                // 4. Initialize Vipps payment using VippsService
                $application_ids = array_column($updatedApplications, 'id');
                
                try {
                    $paymentResult = $this->vippsService->initiatePayment($application_ids);
                    
                    if (isset($paymentResult['url'])) {
                        return ResponseHelper::sendJSONResponse([
                            'success' => true,
                            'redirect_url' => $paymentResult['url'],
                            'orderId' => $paymentResult['orderId'] ?? null
                        ]);
                    } else {
                        // Handle Vipps initiation failure
                        return ResponseHelper::sendErrorResponse([
                            'error' => 'Failed to initiate Vipps payment',
                            'details' => is_array($paymentResult) ? json_encode($paymentResult) : 'Unknown error'
                        ], 500);
                    }
                } catch (Exception $vippsException) {
                    return ResponseHelper::sendErrorResponse([
                        'error' => 'Vipps payment initialization failed',
                        'details' => $vippsException->getMessage()
                    ], 500);
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
}