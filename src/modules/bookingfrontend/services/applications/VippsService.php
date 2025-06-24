<?php

namespace App\modules\bookingfrontend\services\applications;

use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\security\Sessions;
use App\modules\phpgwapi\services\Cache;
use GuzzleHttp\Client;
use Exception;
use phpgw;

/**
 * Vipps payment service for bookingfrontend
 * Ported from class.vipps_helper.inc.php to modern service architecture
 */
class VippsService
{
    private $client_id;
    private $client_secret;
    private $subscription_key;
    private $base_url;
    private $msn;
    private $proxy;
    private $debug;
    private $accesstoken;
    private $client;

    public function __construct()
    {
        $this->initializeConfiguration();
        $this->client = new Client();
        // Don't get access token immediately - do it lazily when needed
    }

    /**
     * Initialize Vipps configuration from admin settings
     * Ported from vipps_helper constructor
     */
    private function initializeConfiguration(): void
    {
        Cache::session_set('bookingfrontend', 'payment_method', 'vipps');

        $location_obj = new Locations();
        $location_id = $location_obj->get_id('booking', 'run');
        $custom_config = CreateObject('admin.soconfig', $location_id);
        $custom_config_data = $custom_config->config_data['Vipps'] ?? [];

        $config = CreateObject('phpgwapi.config', 'booking')->read();

        $this->debug = !empty($custom_config_data['debug']);
        $this->base_url = $custom_config_data['base_url'] ?? 'https://apitest.vipps.no';
        $this->client_id = $custom_config_data['client_id'] ?? '';
        $this->client_secret = $custom_config_data['client_secret'] ?? '';
        $this->subscription_key = $custom_config_data['subscription_key'] ?? '';
        $this->msn = $custom_config_data['msn'] ?? '';
        $this->proxy = $config['proxy'] ?? '';
    }

    /**
     * Get Vipps access token
     * Ported from vipps_helper->get_accesstoken()
     */
    private function getAccessToken(): ?string
    {
        // Check if we already have a valid access token
        if (!empty($this->accesstoken)) {
            return $this->accesstoken;
        }

        // Validate configuration before making API call
        if (empty($this->client_id) || empty($this->client_secret) || empty($this->subscription_key)) {
            throw new Exception("Vipps configuration is incomplete. Missing client_id, client_secret, or subscription_key.");
        }

        $path = '/accesstoken/get';
        $url = "{$this->base_url}{$path}";

        $request = [
            'headers' => [
                'Accept' => 'application/json;charset=UTF-8',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'Ocp-Apim-Subscription-Key' => $this->subscription_key,
            ],
            'json' => []
        ];

        if ($this->proxy) {
            $request['proxy'] = [
                'http' => $this->proxy,
                'https' => $this->proxy
            ];
        }

        try {
            $response = $this->client->request('POST', $url, $request);
            $ret = json_decode($response->getBody()->getContents(), true);
            $this->accesstoken = $ret['access_token'] ?? null;
            return $this->accesstoken;
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            if ($this->debug) {
                error_log("Vipps access token error: " . $e->getMessage());
            }
            throw new Exception("Failed to get Vipps access token: " . $e->getMessage());
        }
    }

    /**
     * Initiate Vipps payment for given applications
     * Ported from vipps_helper->initiate_payment()
     *
     * @param array $application_ids Array of application IDs
     * @return array Payment result with URL for redirect
     * @throws Exception If payment initiation fails
     */
    public function initiatePayment(array $application_ids): array
    {
        // Get access token lazily
        $this->getAccessToken();

        if (empty($this->accesstoken)) {
            throw new Exception("No valid Vipps access token available");
        }

        $remote_order_id = null;
        $soapplication = CreateObject('booking.soapplication');
        $filters = ['id' => $application_ids];
        $params = ['filters' => $filters, 'results' => 'all'];
        $applications = $soapplication->read($params);

        $soapplication->get_purchase_order($applications);

        $transaction = null;
        $contact_phone = null;

        $total_amount = 0;
        $unpaid_order_ids = [];
        $building_names = [];

        foreach ($applications['results'] as $application) {
            $contact_phone = $application['contact_phone'];

            if (!empty($application['building_name']) && !in_array($application['building_name'], $building_names)) {
                $building_names[] = $application['building_name'];
            }

            foreach ($application['orders'] as $order) {
                if (empty($order['paid'])) {
                    $total_amount += (float)$order['sum'];
                    $unpaid_order_ids[] = $order['order_id'];
                }
            }
        }

        if ($total_amount > 0) {
            $building_text = !empty($building_names) ? implode(', ', $building_names) : '';
            $transaction_text = !empty($building_text) ? "Aktiv kommune, {$building_text}" : 'Aktiv kommune';

            $remote_order_id = $soapplication->add_payment($unpaid_order_ids, $this->msn);
            $transaction = [
                "amount" => $total_amount * 100,
                "orderId" => $remote_order_id,
                "transactionText" => $transaction_text,
                "skipLandingPage" => false,
                "scope" => "name address email",
                "useExplicitCheckoutFlow" => true
            ];
        }

        if (!$transaction) {
            throw new Exception("No unpaid orders found for payment initiation");
        }

        return $this->callVippsPaymentAPI($transaction, $contact_phone, $remote_order_id);
    }

    /**
     * Call Vipps payment API
     * Ported from vipps_helper->initiate_payment() API call section
     */
    private function callVippsPaymentAPI(array $transaction, string $contact_phone, string $remote_order_id): array
    {
        $path = '/ecomm/v2/payments';
        $url = "{$this->base_url}{$path}";

        $request = [];
        $this->addHeaders($request);

        $session_id = Sessions::getInstance()->get_session_id();
        $fallback_url = $this->generateFallbackUrl($remote_order_id, $session_id);

        $request_body = [
            "customerInfo" => [
                "mobileNumber" => $contact_phone
            ],
            "merchantInfo" => [
                "authToken" => $session_id,
                "callbackPrefix" => $this->generateCallbackUrl(),
                "consentRemovalPrefix" => $this->generateConsentRemovalUrl(),
                "fallBack" => str_replace('&amp;', '&', $fallback_url),
                "isApp" => false,
                "merchantSerialNumber" => $this->msn,
                "paymentType" => "eComm Regular Payment"
            ],
            "transaction" => $transaction
        ];

        $request['json'] = $request_body;

        try {
            $response = $this->client->request('POST', $url, $request);
            $ret = json_decode($response->getBody()->getContents(), true);
            return $ret;
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            // Init failed - clean up
            $soapplication = CreateObject('booking.soapplication');
            $soapplication->delete_payment($remote_order_id);

            throw new Exception("Vipps payment initiation failed: " . $e->getMessage());
        }
    }

    /**
     * Add headers for Vipps API requests
     * Ported from vipps_helper->get_header()
     */
    private function addHeaders(array &$request): void
    {
        // Ensure we have an access token
        if (empty($this->accesstoken)) {
            $this->getAccessToken();
        }

        $request['headers'] = [
            'Accept' => 'application/json;charset=UTF-8',
            'Authorization' => $this->accesstoken,
            'Ocp-Apim-Subscription-Key' => $this->subscription_key,
        ];

        if ($this->proxy) {
            $request['proxy'] = [
                'http' => $this->proxy,
                'https' => $this->proxy
            ];
        }
    }

    /**
     * Generate callback URL prefix for Vipps payment updates
     * Using placeholder URL that Vipps accepts - webhooks not implemented yet
     * TODO: Replace with real webhook endpoint when implementing server-to-server callbacks
     */
    private function generateCallbackUrl(): string
    {
        return "https://example.com/vipps/callbacks-for-payment-updates";
    }

    /**
     * Generate consent removal URL for Vipps
     * Using placeholder URL that Vipps accepts - not implemented yet
     * TODO: Replace with real endpoint when implementing consent removal
     */
    private function generateConsentRemovalUrl(): string
    {
        return "https://example.com/vipps/consent-removal";
    }

    /**
     * Generate fallback URL for Vipps payment
     * Points to the modern Next.js client page for payment completion
     */
    private function generateFallbackUrl(string $remote_order_id, string $session_id): string
    {
        // Get the base URL for the frontend client
        $userSettings = \App\modules\phpgwapi\services\Settings::getInstance()->get('user');
        $lang = $userSettings['preferences']['common']['lang'] ?? 'no';

        // Use the modern client checkout completion page
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return "{$protocol}://{$host}/bookingfrontend/client/{$lang}/checkout/vipps-return?" . http_build_query([
            'payment_order_id' => $remote_order_id,
            'session_id' => $session_id
        ]);
    }

    /**
     * Get date range string for transaction text
     * Ported from vipps_helper->get_date_range()
     */
    private function getDateRange(array $dates): string
    {
        return "{$dates['from_']} - {$dates['to_']}";
    }

    /**
     * Check if Vipps service is properly configured
     *
     * @return bool True if all required configuration is present
     */
    public function isConfigured(): bool
    {
        return !empty($this->client_id) &&
               !empty($this->client_secret) &&
               !empty($this->subscription_key) &&
               !empty($this->msn);
    }

    /**
     * Check payment status with Vipps API
     * Pure API client - returns raw Vipps data without application logic
     *
     * @param string $remote_order_id Payment order ID
     * @param int $max_attempts Maximum number of attempts (default 6)
     * @return array Payment status information
     * @throws Exception If payment check fails
     */
    public function checkPaymentStatus(string $remote_order_id, int $max_attempts = 6): array
    {
        $attempts = 0;
        $cancel_array = ['CANCEL', 'VOID', 'FAILED', 'REJECTED'];
        $approved_array = ['RESERVE', 'RESERVED'];
        $captured_array = ['CAPTURE'];

        while ($attempts < $max_attempts) {
            $data = $this->getPaymentDetails($remote_order_id);

            if (isset($data['transactionLogHistory'][0])) {
                $last_transaction = $data['transactionLogHistory'][0];

                // Payment already captured - return completed status
                if ($last_transaction['operationSuccess'] && in_array($last_transaction['operation'], $captured_array)) {
                    return [
                        'status' => 'captured',
                        'operation' => $last_transaction['operation'],
                        'amount' => $last_transaction['amount'],
                        'data' => $data
                    ];
                }

                // Return payment status without modifying application state
                if (in_array($last_transaction['operation'], $cancel_array)) {
                    return [
                        'status' => 'failed',
                        'operation' => $last_transaction['operation'],
                        'data' => $data
                    ];
                }

                if ($last_transaction['operationSuccess'] && in_array($last_transaction['operation'], $approved_array)) {
                    return [
                        'status' => 'ready_for_capture',
                        'operation' => $last_transaction['operation'],
                        'amount' => $last_transaction['amount'],
                        'data' => $data
                    ];
                }

                return [
                    'status' => 'pending',
                    'operation' => $last_transaction['operation'],
                    'data' => $data
                ];
            }

            $attempts++;
            if ($attempts < $max_attempts) {
                sleep(2); // Wait 2 seconds between attempts like legacy
            }
        }

        return [
            'status' => 'error',
            'message' => lang('vipps_payment_error') . ': Payment status check failed after ' . $max_attempts . ' attempts'
        ];
    }

    /**
     * Capture payment with Vipps API
     * Ported from vipps_helper->capture_payment() lines 210-248
     *
     * @param string $remote_order_id Payment order ID
     * @param int $amount Amount in øre (cents)
     * @return array Capture result
     * @throws Exception If capture fails
     */
    public function capturePayment(string $remote_order_id, int $amount): array
    {
        $path = "/ecomm/v2/payments/{$remote_order_id}/capture";
        $url = "{$this->base_url}{$path}";

        $request = [];
        $this->addHeaders($request);

        $transaction = [
            "amount" => $amount,
            "transactionText" => 'Booking i Aktiv kommune',
        ];

        $request_body = [
            "merchantInfo" => [
                "merchantSerialNumber" => $this->msn,
            ],
            "transaction" => $transaction
        ];

        $request['json'] = $request_body;

        try {
            $response = $this->client->request('POST', $url, $request);
            $ret = json_decode($response->getBody()->getContents(), true);
            return $ret;
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            if ($this->debug) {
                error_log("Vipps capture payment error: " . $e->getMessage());
            }
            throw new Exception("Failed to capture Vipps payment: " . $e->getMessage());
        }
    }

    /**
     * Get payment details from Vipps API
     * Ported from vipps_helper->get_payment_details() lines 610+
     *
     * @param string $remote_order_id Payment order ID
     * @return array Payment details
     * @throws Exception If request fails
     */
    public function getPaymentDetails(string $remote_order_id): array
    {
        $path = "/ecomm/v2/payments/{$remote_order_id}/details";
        $url = "{$this->base_url}{$path}";

        $request = [];
        $this->addHeaders($request);

        try {
            $response = $this->client->request('GET', $url, $request);
            $ret = json_decode($response->getBody()->getContents(), true);
            return $ret;
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            if ($this->debug) {
                error_log("Vipps get payment details error: " . $e->getMessage());
            }
            throw new Exception("Failed to get Vipps payment details: " . $e->getMessage());
        }
    }

    /**
     * Cancel payment with Vipps API
     * Pure API client - only handles Vipps communication
     *
     * @param string $remote_order_id Payment order ID
     * @return array Response from Vipps API
     * @throws Exception If cancellation fails
     */
    public function cancelPayment(string $remote_order_id): array
    {
        $path = "/ecomm/v2/payments/{$remote_order_id}/cancel";
        $url = "{$this->base_url}{$path}";

        $request = [];
        $this->addHeaders($request);

        $transaction = [
            "transactionText" => 'Booking i Aktiv kommune',
        ];

        $request_body = [
            "merchantInfo" => [
                "merchantSerialNumber" => $this->msn,
            ],
            "transaction" => $transaction
        ];

        $request['json'] = $request_body;

        try {
            $response = $this->client->request('POST', $url, $request);
            $ret = json_decode($response->getBody()->getContents(), true);
            return $ret;

        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            if ($this->debug) {
                error_log("Vipps cancel payment error: " . $e->getMessage());
            }
            throw new Exception("Failed to cancel Vipps payment: " . $e->getMessage());
        }
    }

    /**
     * Refund payment with Vipps API
     * Ported from vipps_helper->refund_payment() lines 404-467
     *
     * @param string $remote_order_id Payment order ID
     * @param int $amount Amount in øre (cents)
     * @return array Refund result
     * @throws Exception If refund fails
     */
    public function refundPayment(string $remote_order_id, int $amount): array
    {
        $path = "/ecomm/v2/payments/{$remote_order_id}/refund";
        $url = "{$this->base_url}{$path}";

        $request = [];
        $this->addHeaders($request);

        $transaction = [
            "transactionText" => 'Booking i Aktiv kommune',
        ];

        $request_body = [
            "merchantInfo" => [
                "amount" => $amount,
                "merchantSerialNumber" => $this->msn,
            ],
            "transaction" => $transaction
        ];

        $request['json'] = $request_body;

        try {
            $response = $this->client->request('POST', $url, $request);
            $ret = json_decode($response->getBody()->getContents(), true);
            return $ret;
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            if ($this->debug) {
                error_log("Vipps refund payment error: " . $e->getMessage());
            }
            throw new Exception("Failed to refund Vipps payment: " . $e->getMessage());
        }
    }

    /**
     * Process payment status and handle business logic
     * Combines check_payment_status with application processing
     * Ported from vipps_helper->check_payment_status() lines 473-532
     *
     * @param string $remote_order_id Payment order ID
     * @return array Payment processing result
     */
    public function processPaymentStatus(string $remote_order_id): array
    {
        // Use the API-only checkPaymentStatus method
        $statusResult = $this->checkPaymentStatus($remote_order_id);

        switch ($statusResult['status']) {
            case 'failed':
                $this->processFailedPayment($remote_order_id, $statusResult['operation']);
                return [
                    'status' => 'cancelled',
                    'message' => lang('vipps_payment_cancelled')
                ];

            case 'captured':
                // Payment already captured, process applications directly
                return $this->processAlreadyCapturedPayment($remote_order_id, $statusResult['amount']);

            case 'ready_for_capture':
                return $this->processCapturePayment($remote_order_id, $statusResult['amount']);

            case 'pending':
                return [
                    'status' => 'pending',
                    'message' => lang('vipps_payment_pending')
                ];

            default:
                return $statusResult;
        }
    }

    /**
     * Process failed payment by cancelling orders
     * Ported from vipps_helper->cancel_order() lines 271-314
     */
    private function processFailedPayment(string $remote_order_id, string $remote_state): void
    {
        $sopurchase_order = createObject('booking.sopurchase_order');
        $soapplication = CreateObject('booking.soapplication');
        $application_ids = $soapplication->get_application_from_payment_order($remote_order_id);
        $session_id = Sessions::getInstance()->get_session_id();

        if (!empty($session_id) && !empty($application_ids)) {
            $partials = CreateObject('booking.uiapplication')->get_partials($session_id);

            \App\Database\Db::getInstance()->transaction_begin();

            $bo_block = createObject('booking.boblock');

            foreach ($application_ids as $application_id) {
                $exists = false;
                foreach ($partials['list'] as $partial) {
                    if ($partial['id'] == $application_id) {
                        $bo_block->cancel_block($session_id, $partial['dates'], $partial['resources']);
                        $exists = true;
                        break;
                    }
                }
                if ($exists) {
                    $sopurchase_order->delete_purchase_order($application_id);
                    $soapplication->delete_application($application_id);
                }
            }

            $soapplication->update_payment_status($remote_order_id, 'voided', $remote_state);
            \App\Database\Db::getInstance()->transaction_commit();
        }

        Cache::message_set('cancelled');
    }

    /**
     * Process payment capture and approve applications
     * Combines capture with approve_application logic
     */
    private function processCapturePayment(string $remote_order_id, int $amount): array
    {
        $soapplication = CreateObject('booking.soapplication');

        try {
            // Update status to pending first
            $soapplication->update_payment_status($remote_order_id, 'pending', 'RESERVE');

            // Attempt to capture payment
            $capture = $this->capturePayment($remote_order_id, $amount);

            if (isset($capture['transactionInfo']['status']) && $capture['transactionInfo']['status'] == 'Captured') {
                \App\Database\Db::getInstance()->transaction_begin();
                $soapplication->update_payment_status($remote_order_id, 'completed', 'CAPTURE');
                $approved = $this->approveApplications($remote_order_id, $amount);
                \App\Database\Db::getInstance()->transaction_commit();

                // Set individual success messages per application with titles
                if ($approved) {
                    // Get application IDs and details for individual messages
                    $soapplication = CreateObject('booking.soapplication');
                    $application_ids = $soapplication->get_application_from_payment_order($remote_order_id);

                    $applicationService = new ApplicationService();
                    foreach ($application_ids as $app_id) {
                        $app = $applicationService->getApplicationById($app_id);
                        if ($app) {
                            $app_name = $app['name'] ?? 'Søknad';
                            $app_title = lang('application') . ': ' . $app_name;
                            $message = lang('vipps_payment_approved_single') . "<br/>" . lang("Please check your Spam Filter if you are missing mail.");
                            Cache::message_set($message, 'message', $app_title);
                        }
                    }
                }

                return [
                    'status' => 'completed',
                    'message' => lang('vipps_payment_completed'),
                    'applications_approved' => $approved
                ];
            } else {
                return [
                    'status' => 'capture_failed',
                    'message' => lang('vipps_payment_error'),
                    'details' => $capture
                ];
            }
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => lang('vipps_payment_error') . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process payment that is already captured by Vipps
     * Directly approve applications without attempting capture
     *
     * @param string $remote_order_id Payment order ID
     * @param int $amount Amount in øre (cents)
     * @return array Processing result
     */
    private function processAlreadyCapturedPayment(string $remote_order_id, int $amount): array
    {
        $soapplication = CreateObject('booking.soapplication');

        try {
            \App\Database\Db::getInstance()->transaction_begin();

            // Update payment status to completed
            $soapplication->update_payment_status($remote_order_id, 'completed', 'CAPTURE');

            // Approve applications
            $approved = $this->approveApplications($remote_order_id, $amount);

            \App\Database\Db::getInstance()->transaction_commit();

            // Set individual success messages per application with titles
            if ($approved) {
                // Get application IDs and details for individual messages
                $soapplication = CreateObject('booking.soapplication');
                $application_ids = $soapplication->get_application_from_payment_order($remote_order_id);

                $applicationService = new ApplicationService();
                foreach ($application_ids as $app_id) {
                    $app = $applicationService->getApplicationById($app_id);
                    if ($app) {
                        $app_name = $app['name'] ?? 'Søknad';
                        $app_title = lang('application') . ': ' . $app_name;
                        $message = lang('vipps_payment_approved_single') . "<br/>" . lang("Please check your Spam Filter if you are missing mail.");
                        Cache::message_set($message, 'message', $app_title);
                    }
                }
            }

            return [
                'status' => 'completed',
                'message' => lang('vipps_payment_completed'),
                'applications_approved' => $approved
            ];

        } catch (Exception $e) {
            \App\Database\Db::getInstance()->transaction_abort();
            return [
                'status' => 'error',
                'message' => lang('vipps_payment_error') . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Approve applications after successful payment
     * Ported from vipps_helper->approve_application() lines 562-633
     */
    private function approveApplications(string $remote_order_id, int $amount): bool
    {
        $_amount = ($amount / 100);
        $boapplication = CreateObject('booking.boapplication');

        $application_ids = $boapplication->so->get_application_from_payment_order($remote_order_id);
        $ret = false;

        foreach ($application_ids as $application_id) {
            $application = $boapplication->so->read_single($application_id);

            // All applications in payment order are direct booking applications (require payment)
            $application['status'] = 'ACCEPTED';
            $receipt = $boapplication->update($application);

            $event = $application;
            unset($event['id']);
            unset($event['id_string']);
            $event['application_id'] = $application['id'];
            $event['is_public'] = 0;
            $event['include_in_list'] = 0;
            $event['reminder'] = 0;
            $event['customer_internal'] = 0;
            $event['cost'] = $_amount;
            $event['completed'] = 1; // paid!

            $building_info = $boapplication->so->get_building_info($application['id']);
            $event['building_id'] = $building_info['id'];
            $this->addComment($event, lang('Event was created'));
            $this->addCostHistory($event, lang('cost is set'), $_amount);

            $booking_boevent = createObject('booking.boevent');
            $errors = [];

            // Validate timeslots
            foreach ($application['dates'] as $checkdate) {
                $event['from_'] = $checkdate['from_'];
                $event['to_'] = $checkdate['to_'];
                $errors = array_merge($errors, $booking_boevent->validate($event));
            }

            if (!$errors) {
                $session_id = Sessions::getInstance()->get_session_id();

                CreateObject('booking.souser')->collect_users($application['customer_ssn']);
                $bo_block = createObject('booking.boblock');
                $bo_block->cancel_block($session_id, $application['dates'], $application['resources']);

                // Add event for each timeslot
                foreach ($application['dates'] as $checkdate) {
                    $event['from_'] = $checkdate['from_'];
                    $event['to_'] = $checkdate['to_'];
                    $receipt = $booking_boevent->so->add($event);
                }

                $booking_boevent->so->update_id_string();
                createObject('booking.sopurchase_order')->identify_purchase_order($application['id'], $receipt['id'], 'event');

                $boapplication->send_notification($application);
                $ret = true;
            }
        }

        // Process normal applications that were waiting for payment confirmation
        $normal_application_ids = \App\modules\phpgwapi\services\Cache::system_get('bookingfrontend', 'vipps_normal_apps_' . $remote_order_id) ?? [];

        if (!empty($normal_application_ids)) {
            // Get the contact data that was used for the Vipps payment
            $contact_data = \App\modules\phpgwapi\services\Cache::system_get('bookingfrontend', 'vipps_contact_data_' . $remote_order_id) ?? [];

            if (!empty($contact_data)) {
                try {
                    // Get the session ID from the application data
                    $session_id = null;
                    foreach ($application_ids as $app_id) {
                        $app = $boapplication->so->read_single($app_id);
                        if (!empty($app['session_id'])) {
                            $session_id = $app['session_id'];
                            break;
                        }
                    }

                    if (!empty($session_id)) {
                        $applicationService = new \App\modules\bookingfrontend\services\applications\ApplicationService();

                        // Check if normal applications still exist as partials
                        $partialApplications = $applicationService->getPartialApplications($session_id);
                        $normalPartials = array_filter($partialApplications, function($app) use ($normal_application_ids) {
                            return in_array($app['id'], $normal_application_ids);
                        });

                        if (!empty($normalPartials)) {
                            // Process only the normal applications by updating them with contact info and finalizing
                            foreach ($normalPartials as $normalApp) {
                                try {
                                    // Update this specific normal application with contact info
                                    $updateData = [
                                        'contact_name' => $contact_data['contactName'],
                                        'contact_email' => $contact_data['contactEmail'],
                                        'contact_phone' => $contact_data['contactPhone'],
                                        'customer_organization_number' => $contact_data['customerType'] === 'organization_number' ? $contact_data['organizationNumber'] : null,
                                        'customer_organization_name' => $contact_data['customerType'] === 'organization_number' ? $contact_data['organizationName'] : null,
                                        'session_id' => null, // Finalize the application
                                        'status' => 'NEW', // Normal applications go for approval
                                        'modified' => date('Y-m-d H:i:s')
                                    ];

                                    // Add address fields if provided
                                    if (!empty($contact_data['street'])) {
                                        $updateData['customer_street'] = $contact_data['street'];
                                    }
                                    if (!empty($contact_data['zipCode'])) {
                                        $updateData['customer_zip_code'] = $contact_data['zipCode'];
                                    }
                                    if (!empty($contact_data['city'])) {
                                        $updateData['customer_city'] = $contact_data['city'];
                                    }

                                    $applicationService->patchApplicationMainData($updateData, $normalApp['id']);

                                    // Send notification for the normal application
                                    $bo_application = CreateObject('booking.boapplication');
                                    $updatedApp = $bo_application->so->read_single($normalApp['id']);
                                    $bo_application->send_notification($updatedApp);

                                    // Set confirmation message for normal application (same as regular checkout)
                                    $app_name = $updatedApp['name'] ?? 'Søknad';
                                    $app_title = lang('application') . ': ' . $app_name;
                                    $message = lang('application_registered_single') . "<br/>" .
                                              lang('case_officer_review_single') . "<br/>" .
                                              lang("Please check your Spam Filter if you are missing mail.");
                                    \App\modules\phpgwapi\services\Cache::message_set($message, 'message', $app_title);

                                    $ret = true;
                                } catch (\Exception $e) {
                                    error_log("Failed to process normal application {$normalApp['id']}: " . $e->getMessage());
                                }
                            }

                            // Trigger WebSocket update to notify frontend about partial applications changes
						}
					}
                } catch (\Exception $e) {
                    // Log error but don't fail the whole payment process
                    error_log("Failed to process normal applications after Vipps payment: " . $e->getMessage());
                }
            }
        }

        // Clean up cached data
        \App\modules\phpgwapi\services\Cache::system_clear('bookingfrontend', 'vipps_normal_apps_' . $remote_order_id);
        \App\modules\phpgwapi\services\Cache::system_clear('bookingfrontend', 'vipps_contact_data_' . $remote_order_id);
		\App\modules\bookingfrontend\helpers\WebSocketHelper::triggerPartialApplicationsUpdate($session_id);


        return $ret;
    }

    /**
     * Add comment to event
     * Ported from vipps_helper->add_comment() lines 534-542
     */
    private function addComment(array &$event, string $comment, string $type = 'comment'): void
    {
        $event['comments'][] = [
            'time' => 'now',
            'author' => 'Vipps',
            'comment' => $comment,
            'type' => $type
        ];
    }

    /**
     * Add cost history to event
     * Ported from vipps_helper->add_cost_history() lines 543-556
     */
    private function addCostHistory(array &$event, string $comment = '', string $cost = '0.00'): void
    {
        if (!$comment) {
            $comment = lang('cost is set');
        }

        $event['costs'][] = [
            'time' => 'now',
            'author' => 'Vipps',
            'comment' => $comment,
            'cost' => $cost
        ];
    }

    /**
     * Post completed transactions to accounting system
     * Ported from vipps_helper->postToAccountingSystem() lines 676-838
     *
     * @return array Results of posting transactions
     */
    public function postToAccountingSystem(): array
    {
        $soapplication = CreateObject('booking.soapplication');
        $results = [
            'posted_transactions' => 0,
            'posted_refunds' => 0,
            'errors' => []
        ];

        try {
            // Get accounting system configuration
            $location_obj = new Locations();
            $location_id = $location_obj->get_id('booking', 'run');
            $custom_config = CreateObject('admin.soconfig', $location_id);
            $accounting_config = $custom_config->config_data['Accounting'] ?? [];
            $accounting_system = $accounting_config['system'] ?? 'visma_enterprise';

            // Create accounting system
            try {
                $accounting = \App\modules\booking\helpers\accounting\AccountingSystemFactory::create(
                    $accounting_system,
                    $accounting_config,
                    $this->debug
                );
            } catch (Exception $e) {
                $error = "Failed to initialize accounting system: " . $e->getMessage();
                $results['errors'][] = $error;
                if ($this->debug) {
                    error_log($error);
                }
                return $results;
            }

            // Check if accounting system is configured
            if (!$accounting->isConfigured()) {
                $error = "Accounting system is not properly configured: " . $accounting->getLastError();
                $results['errors'][] = $error;
                if ($this->debug) {
                    error_log($error);
                }
                return $results;
            }

            // Process unposted transactions
            $result = $soapplication->get_unposted_transactions();
            $unposted_transactions = $result['results'] ?? [];

            foreach ($unposted_transactions as $transaction) {
                $remote_order_id = $transaction['remote_order_id'];
                $amount = $transaction['amount'];
                $description = $transaction['description'];
                $date = $transaction['date'];

                // Get payment details from Vipps
                try {
                    $payment_details = $this->getPaymentDetails($remote_order_id);

                    if (isset($payment_details['transactionInfo']['status']) && $payment_details['transactionInfo']['status'] === 'CAPTURE') {
                        // Post transaction to accounting system
                        $result = $accounting->postTransaction(
                            $amount / 100,
                            $description,
                            $date,
                            $remote_order_id
                        );

                        if ($result) {
                            $soapplication->mark_as_posted($remote_order_id);
                            $results['posted_transactions']++;

                            if ($this->debug) {
                                error_log("Successfully posted transaction {$remote_order_id} to accounting system.");
                            }
                        } else {
                            $error = "Failed to post transaction {$remote_order_id}: " . $accounting->getLastError();
                            $results['errors'][] = $error;
                            if ($this->debug) {
                                error_log($error);
                            }
                        }
                    } else {
                        if ($this->debug) {
                            error_log("Transaction {$remote_order_id} is not captured. Skipping.");
                        }
                    }
                } catch (Exception $e) {
                    $error = "Error processing transaction {$remote_order_id}: " . $e->getMessage();
                    $results['errors'][] = $error;
                    if ($this->debug) {
                        error_log($error);
                    }
                }
            }

            // Process unposted refunds
            $result = $soapplication->get_unposted_refund_transactions();
            $unposted_refunds = $result['results'] ?? [];

            foreach ($unposted_refunds as $refund) {
                $remote_order_id = $refund['remote_order_id'];
                $amount = $refund['amount'];
                $description = $refund['description'];
                $date = $refund['date'];
                $original_transaction_id = $refund['original_transaction_id'] ?? null;

                // Post refund to accounting system
                $result = $accounting->postRefundTransaction(
                    $amount / 100,
                    $description,
                    $date,
                    $remote_order_id,
                    $original_transaction_id
                );

                if ($result) {
                    $soapplication->mark_refund_as_posted($remote_order_id);
                    $results['posted_refunds']++;

                    if ($this->debug) {
                        error_log("Successfully posted refund for transaction {$remote_order_id} to accounting system.");
                    }
                } else {
                    $error = "Failed to post refund for transaction {$remote_order_id}: " . $accounting->getLastError();
                    $results['errors'][] = $error;
                    if ($this->debug) {
                        error_log($error);
                    }
                }
            }

            // Process refunded posted payments that need refund posting
            $refunds_needing_posting = $soapplication->get_refunded_posted_payments();

            foreach ($refunds_needing_posting as $refund) {
                $result = $accounting->postRefundTransaction(
                    $refund['amount'] / 100,
                    $refund['description'],
                    $refund['date'],
                    $refund['remote_order_id'],
                    $refund['original_transaction_id']
                );

                if ($result) {
                    $soapplication->mark_refund_as_posted($refund['remote_order_id']);
                    $results['posted_refunds']++;

                    if ($this->debug) {
                        error_log("Successfully posted refund for transaction {$refund['remote_order_id']} to accounting system.");
                    }
                } else {
                    $error = "Failed to post refund for transaction {$refund['remote_order_id']}: " . $accounting->getLastError();
                    $results['errors'][] = $error;
                    if ($this->debug) {
                        error_log($error);
                    }
                }
            }

        } catch (Exception $e) {
            $error = "General error in postToAccountingSystem: " . $e->getMessage();
            $results['errors'][] = $error;
            if ($this->debug) {
                error_log($error);
            }
        }

        return $results;
    }

    /**
     * Get debug status
     *
     * @return bool True if debug mode is enabled
     */
    public function isDebugEnabled(): bool
    {
        return $this->debug;
    }
}