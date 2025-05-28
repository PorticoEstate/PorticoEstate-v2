<?php

namespace App\modules\bookingfrontend\services;

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
        $this->accesstoken = $this->getAccessToken();
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
            return $ret['access_token'] ?? null;
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

        foreach ($applications['results'] as $application) {
            $dates = implode(', ', array_map([$this, 'getDateRange'], $application['dates']));
            $contact_phone = $application['contact_phone'];

            foreach ($application['orders'] as $order) {
                if (empty($order['paid'])) {
                    $remote_order_id = $soapplication->add_payment($order['order_id'], $this->msn);
                    $transaction = [
                        "amount" => (float)$order['sum'] * 100,
                        "orderId" => $remote_order_id,
                        "transactionText" => 'Aktiv kommune, bookingdato: ' . $dates,
                        "skipLandingPage" => false,
                        "scope" => "name address email",
                        "useExplicitCheckoutFlow" => true
                    ];
                    break 2;
                }
            }
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
     * Using placeholder for now - legacy system doesn't use real callbacks
     */
    private function generateCallbackUrl(): string
    {
        return "https://example.com/vipps/callbacks-for-payment-updates";
    }

    /**
     * Generate consent removal URL for Vipps
     * Using placeholder for now - legacy system doesn't use real callbacks
     */
    private function generateConsentRemovalUrl(): string
    {
        return "https://example.com/vipps/consent-removal";
    }

    /**
     * Generate fallback URL for Vipps payment
     * Using legacy add_contact endpoint for immediate compatibility
     */
    private function generateFallbackUrl(string $remote_order_id, string $session_id): string
    {
        return phpgw::link(
            '/bookingfrontend/',
            [
                'menuaction' => 'bookingfrontend.uiapplication.add_contact',
                'payment_order_id' => $remote_order_id,
                session_name() => $session_id
            ],
            false,
            true
        );
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
               !empty($this->msn) &&
               !empty($this->accesstoken);
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

        while ($attempts < $max_attempts) {
            $data = $this->getPaymentDetails($remote_order_id);
            
            if (isset($data['transactionLogHistory'][0])) {
                $last_transaction = $data['transactionLogHistory'][0];
                
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
            'message' => 'Payment status check failed after ' . $max_attempts . ' attempts'
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
     * Get debug status
     *
     * @return bool True if debug mode is enabled
     */
    public function isDebugEnabled(): bool
    {
        return $this->debug;
    }
}