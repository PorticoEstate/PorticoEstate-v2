<?php

/**
 * Passkey Management Controller
 * 
 * @license http://www.gnu.org/licenses/gpl.html GPL - GNU General Public License
 * @package phpgwapi
 * @subpackage controller
 */

namespace App\modules\phpgwapi\controllers;

use App\modules\phpgwapi\security\Auth\Auth_Passkeys;
use App\helpers\Template;
use App\helpers\DateHelper;
use App\modules\phpgwapi\services\Settings;
use PDO;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class Passkey_Management_Controller
{
	protected $db;
	protected $template;
	protected $auth_passkeys;
	protected $account_id;
	protected $account_lid;
	protected $serverSettings;
	protected $userSettings;
	protected $flags;
	protected $phpgwapi_common;
    // Add rate limiting properties
    protected $max_attempts = 5;
    protected $rate_window = 300; // 5 minutes in seconds

	public function __construct()
	{
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		$this->db = \App\Database\Db::getInstance();
		$this->phpgwapi_common = new \phpgwapi_common();

		$root = SRC_ROOT_PATH . "/modules/phpgwapi/templates/base";

		$this->template = Template::getInstance($root);
		$this->template->set_file('head', 'passkey_management.tpl');

		$this->auth_passkeys = new Auth_Passkeys();

		// Get settings
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		Settings::getInstance()->update('flags', [
			'currentapp' => 'preferences']);
		$this->flags = Settings::getInstance()->get('flags');

		// Get current user's account ID and username
		$this->account_id = (int) $this->userSettings['account_id'];
		$this->account_lid = $this->userSettings['account_lid'];

		// Set up common template variables
		$this->template->set_var([
			'webserver_url' => $this->serverSettings['webserver_url'],
			'username' => $this->account_lid
		]);

		// Set up translations
		$this->template->set_var([
			'lang_passkey_management' => lang('Passkey Management'),
			'lang_passkey_description' => lang('Passkeys are a secure way to sign in without using passwords. They use biometrics like fingerprints or facial recognition from your device.'),
			'lang_checking_passkey_support' => lang('Checking browser support for passkeys...'),
			'lang_passkey_not_supported' => lang('Your browser or device does not support passkeys. Please use a modern browser like Chrome, Edge, or Safari.'),
			'lang_your_passkeys' => lang('Your Passkeys'),
			'lang_no_passkeys' => lang('You have not registered any passkeys yet.'),
			'lang_add_new_passkey' => lang('Add New Passkey'),
			'lang_passkey_name' => lang('Passkey Name'),
			'lang_passkey_name_placeholder' => lang('e.g., Work Laptop, Personal Phone'),
			'lang_passkey_name_help' => lang('Give your passkey a memorable name to identify which device it belongs to.'),
			'lang_passkey_security_notice' => lang('You will be prompted to verify your identity using your device. This may require biometric authentication or a PIN.'),
			'lang_register_passkey' => lang('Register Passkey'),
			'lang_registering_passkey' => lang('Registering passkey, please follow your device prompts...'),
			'lang_passkey_registered_success' => lang('Passkey registered successfully!'),
			'lang_passkey_registration_failed' => lang('Passkey registration failed.'),
			'lang_passkey_registration_error' => lang('Error registering passkey'),
			'lang_error_passkey_name_required' => lang('Please enter a name for your passkey.'),
			'lang_confirm_delete_passkey' => lang('Are you sure you want to delete this passkey?'),
			'lang_delete_failed' => lang('Failed to delete passkey.'),
			'lang_delete_error' => lang('Error deleting passkey'),
			'lang_delete_passkey' => lang('Delete'),
			'lang_last_used' => lang('Last Used'),
			'lang_created_on' => lang('Created On'),
			'lang_never' => lang('Never')
		]);

		// Set up CSRF protection token if not already present
        if (!isset($_SESSION['passkey_csrf_token']) || empty($_SESSION['passkey_csrf_token'])) {
            $_SESSION['passkey_csrf_token'] = bin2hex(random_bytes(32));
        }
        
        // Add CSRF token to template variables
        $this->template->set_var([
            'csrf_token' => $_SESSION['passkey_csrf_token']
        ]);
	}

	/**
	 * Display the passkey management page
	 */
	public function index(Request $request, Response $response): Response
	{
		// Get user's passkeys
		$passkeys = $this->auth_passkeys->get_passkeys($this->account_id);

		// Generate HTML for the passkey list
		$passkey_list_html = '';
		if (!empty($passkeys))
		{
			$passkey_list_html = '<table class="table table-hover">
                <thead>
                    <tr>
                        <th>' . lang('Name') . '</th>
                        <th>' . lang('Created') . '</th>
                        <th>' . lang('Last Used') . '</th>
                        <th>' . lang('Actions') . '</th>
                    </tr>
                </thead>
                <tbody>';

			foreach ($passkeys as $passkey)
			{
				$created_date = !empty($passkey['added']) ?
					$this->format_datetime($passkey['added']) :
					lang('Unknown');

				$last_used = isset($passkey['last_used']) && !empty($passkey['last_used']) ?
					$this->format_datetime($passkey['last_used']) :
					lang('Never');

				$device_name = htmlspecialchars($passkey['device_name'] ?: lang('Unnamed Device'));

				$passkey_list_html .= '<tr>
                    <td>' . $device_name . '</td>
                    <td>' . $created_date . '</td>
                    <td>' . $last_used . '</td>
                    <td>
                        <button class="btn btn-sm btn-danger delete-passkey-button" 
                                data-credential-id="' . htmlspecialchars($passkey['credential_id']) . '"
                                title="' . lang('Delete') . '">
                            <i class="fas fa-trash"></i> ' . lang('Delete') . '
                        </button>
                    </td>
                </tr>';
			}

			$passkey_list_html .= '</tbody></table>';

			$this->template->set_var([
				'passkey_list_html' => $passkey_list_html,
				'no_passkeys_class' => 'd-none'
			]);
		}
		else
		{
			$this->template->set_var([
				'passkey_list_html' => '',
				'no_passkeys_class' => ''
			]);
		}


		// Set app header and update flags
		$this->flags['app_header'] = lang('Passkey Management');
		$this->flags['currentapp'] = 'preferences';
		Settings::getInstance()->set('flags', $this->flags);

		// Add the page to the framework
		$this->phpgwapi_common->phpgw_header(true);
		$this->template->pfp('out', 'head');
		$this->phpgwapi_common->phpgw_footer();

		return $response;
	}

	/**
	 * Format a datetime string to a human-readable format
	 * 
	 * @param string $datetime_str ISO 8601 datetime string (e.g., '2023-04-26T14:30:00+00:00')
	 * @return string Formatted datetime string
	 */
	private function format_datetime(string $datetime_str): string
	{
		try
		{
			$datetime = new \DateTime($datetime_str);
			
			// Apply user's timezone preference
			if (isset($this->userSettings['preferences']['common']['timezone'])) {
				$user_timezone = $this->userSettings['preferences']['common']['timezone'];
				$datetime->setTimezone(new \DateTimeZone($user_timezone));
			}

			$dateformat = $this->userSettings['preferences']['common']['dateformat'] ?? 'Y-m-d H:i:s';
			return $datetime->format($dateformat . ' H:i:s');
		}
		catch (\Exception $e)
		{
			error_log("Error formatting datetime: " . $e->getMessage());
			return $datetime_str; // Return original string if parsing fails
		}
	}

	/**
	 * Generate registration options for a new passkey
	 */
	public function getRegistrationOptions(Request $request, Response $response): Response
	{
        // Verify session authentication
        if (!$this->verifyAuthentication()) {
            return $this->jsonError($response, 'Authentication required', 401);
        }
        
        // Check for CSRF token - should be sent in header or query param
        if (!$this->verifyCsrfToken($request)) {
            return $this->jsonError($response, 'Invalid security token', 403);
        }
        
		// Regenerate the session ID before starting registration process
        $this->regenerateSession();

		// Get display name from user account
		$sql = "SELECT account_firstname, account_lastname FROM phpgw_accounts 
                WHERE account_id = :account_id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':account_id' => $this->account_id]);
		$account = $stmt->fetch(PDO::FETCH_ASSOC);

		$displayName = trim($account['account_firstname'] . ' ' . $account['account_lastname']);
		if (empty($displayName))
		{
			$displayName = $this->account_lid;
		}

		// Generate registration options
		$options = $this->auth_passkeys->getRegistrationArgs(
			$this->account_id,
			$this->account_lid,
			$displayName
		);

		// Store the challenge in session with a timestamp for expiration
        $_SESSION['webauthn_challenge'] = [
            'value' => $this->auth_passkeys->getWebAuthnInstance()->getChallenge()->getBinaryString(),
            'expires' => time() + 300 // 5 minute expiration
        ];

		$response->getBody()->write(json_encode($options));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Verify and store a new passkey
	 */
	public function verifyRegistration(Request $request, Response $response): Response
	{
        // Add debug logging
        error_log("[PASSKEY DEBUG] Starting verification of passkey registration");
        
        // Verify session authentication
        if (!$this->verifyAuthentication()) {
            error_log("[PASSKEY DEBUG] Authentication verification failed");
            return $this->jsonError($response, 'Authentication required', 401);
        }
        
        // Check for CSRF token
        if (!$this->verifyCsrfToken($request)) {
            error_log("[PASSKEY DEBUG] CSRF token verification failed");
            return $this->jsonError($response, 'Invalid security token', 403);
        }
        
        // Apply rate limiting
        if ($this->isRateLimited('register')) {
            error_log("[PASSKEY DEBUG] Rate limit exceeded for registration");
            return $this->jsonError($response, 'Too many registration attempts. Please try again later.', 429);
        }
        
		// Get device name from query params
		$queryParams = $request->getQueryParams();
		$deviceName = $queryParams['device_name'] ?? '';
		$deviceName = trim($deviceName);
		
		if (empty($deviceName))
		{
		    error_log("[PASSKEY DEBUG] Empty device name provided");
			return $this->jsonError($response, 'Device name is required');
		}
		
		// Get registration data from request body
		$params = json_decode($request->getBody()->getContents(), true);
		if (!$params || !isset($params['id']) || !isset($params['response']))
		{
		    error_log("[PASSKEY DEBUG] Invalid registration data format: " . json_encode($params));
			return $this->jsonError($response, 'Invalid registration data format');
		}
		
		try
		{
            // Get challenge value from session
            $challengeData = $_SESSION['webauthn_challenge'] ?? null;
            if (!$challengeData) {
                error_log("[PASSKEY DEBUG] No challenge found in session");
                return $this->jsonError($response, 'No challenge found in session');
            }
            
            $challenge = $challengeData['value'];
            error_log("[PASSKEY DEBUG] Challenge retrieved from session: " . substr(bin2hex($challenge), 0, 10) . "...");
            
			// Verify with WebAuthn
			error_log("[PASSKEY DEBUG] Processing registration with clientDataJSON size: " . 
			    strlen($params['response']['clientDataJSON']) . ", attestationObject size: " . 
			    strlen($params['response']['attestationObject']));
			    
			$success = $this->auth_passkeys->processRegistration(
				$params['response']['clientDataJSON'],
				$params['response']['attestationObject'],
				$params['id'],
				$this->account_id,
				$deviceName,
				true
			);

			if ($success)
			{
                // Clear the challenge from session after use
                unset($_SESSION['webauthn_challenge']);
                
                // Record successful operation for audit logging
                $this->logAuditEvent('passkey_register', 'Registered new passkey: ' . $deviceName);
                
                error_log("[PASSKEY DEBUG] Registration successful for device: " . $deviceName);
				return $this->jsonSuccess($response, 'Passkey registered successfully');
			}
			else
			{
                // Increment failed attempts counter
                $this->incrementRateLimitCounter('register');
                
                error_log("[PASSKEY DEBUG] Registration failed - verification returned false");
				return $this->jsonError($response, 'Failed to register passkey - verification returned false');
			}
		}
		catch (\Exception $e)
		{
            // Increment failed attempts counter
            $this->incrementRateLimitCounter('register');
            
            error_log("[PASSKEY DEBUG] Exception during registration: " . $e->getMessage());
			return $this->jsonError($response, 'Error processing registration request');
		}
	}

	/**
	 * Delete a passkey
	 */
	public function deletePasskey(Request $request, Response $response): Response
	{
        // Verify session authentication
        if (!$this->verifyAuthentication()) {
            return $this->jsonError($response, 'Authentication required', 401);
        }
        
        // Check for CSRF token
        if (!$this->verifyCsrfToken($request)) {
            return $this->jsonError($response, 'Invalid security token', 403);
        }
        
        // Apply rate limiting
        if ($this->isRateLimited('delete')) {
            return $this->jsonError($response, 'Too many deletion attempts. Please try again later.', 429);
        }
        
		$params = json_decode($request->getBody()->getContents(), true);

		if (!isset($params['credential_id']) || !is_string($params['credential_id'])) {
			return $this->jsonError($response, 'Missing or invalid credential ID', 400);
		}

		$credentialId = $this->sanitizeInput($params['credential_id']);

		try
		{
            // Regenerate session ID for security
            $this->regenerateSession();
            
            // Verify additional confirmation if set
            if (isset($params['confirmation_token']) && isset($_SESSION['deletion_confirmation_token'])) {
                if ($params['confirmation_token'] !== $_SESSION['deletion_confirmation_token']) {
                    return $this->jsonError($response, 'Invalid confirmation token', 403);
                }
            }
            
			$success = $this->auth_passkeys->remove_passkey($this->account_id, $credentialId);

			if ($success)
			{
                // Record successful operation for audit logging
                $this->logAuditEvent('passkey_delete', 'Deleted passkey: ' . $credentialId);
                
				return $this->jsonSuccess($response, 'Passkey deleted successfully');
			}
			else
			{
                // Increment failed attempts counter
                $this->incrementRateLimitCounter('delete');
                
				return $this->jsonError($response, 'Failed to delete passkey');
			}
		}
		catch (\Exception $e)
		{
            // Increment failed attempts counter
            $this->incrementRateLimitCounter('delete');
            
			return $this->jsonError($response, 'Error processing deletion request');
		}
	}

	/**
	 * Return a JSON success response
	 */
	private function jsonSuccess(Response $response, string $message, array $data = []): Response
	{
		$result = ['success' => true, 'message' => $message];
		if (!empty($data))
		{
			$result['data'] = $data;
		}

		$response->getBody()->write(json_encode($result));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Return a JSON error response
	 */
	private function jsonError(Response $response, string $message, int $status = 500): Response
	{
		$response->getBody()->write(json_encode([
			'success' => false,
			'message' => $message
		]));

		return $response
			->withHeader('Content-Type', 'application/json')
			->withStatus($status);
	}
    
    /**
     * Verify CSRF token from request
     * @param Request $request
     * @return bool
     */
    private function verifyCsrfToken(Request $request): bool
    {
        $token = null;
        
        // Check header first
        $headers = $request->getHeaders();
        if (isset($headers['X-CSRF-Token']) && !empty($headers['X-CSRF-Token'][0])) {
            $token = $headers['X-CSRF-Token'][0];
        }
        
        // Then check POST/GET params
        if (!$token) {
            $params = $request->getParsedBody();
            if (isset($params['csrf_token'])) {
                $token = $params['csrf_token'];
            } else {
                $queryParams = $request->getQueryParams();
                if (isset($queryParams['csrf_token'])) {
                    $token = $queryParams['csrf_token'];
                }
            }
        }
        
        // Verify the token against session token using constant-time comparison
        return $token && isset($_SESSION['passkey_csrf_token']) && 
               hash_equals($_SESSION['passkey_csrf_token'], $token);
    }
    
    /**
     * Validate registration parameters
     * @param array $params
     * @return bool
     */
    private function validateRegistrationParams(array $params): bool
    {
        return isset($params['id']) && 
               isset($params['response']) &&
               isset($params['response']['clientDataJSON']) &&
               isset($params['response']['attestationObject']);
    }
    
    /**
     * Check if the current challenge is valid and not expired
     * @return bool
     */
    private function isValidChallenge(): bool
    {
        if (!isset($_SESSION['webauthn_challenge']) || 
            !is_array($_SESSION['webauthn_challenge']) ||
            !isset($_SESSION['webauthn_challenge']['value']) ||
            !isset($_SESSION['webauthn_challenge']['expires'])) {
            return false;
        }
        
        // Check if challenge has expired
        return $_SESSION['webauthn_challenge']['expires'] > time();
    }
    
    /**
     * Sanitize user input to prevent injection attacks
     * @param string $input
     * @return string
     */
    private function sanitizeInput(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Verify that the user is authenticated
     * @return bool
     */
    private function verifyAuthentication(): bool
    {
        return isset($this->account_id) && $this->account_id > 0;
    }
    
    /**
     * Regenerate session ID safely
     * @return void
     */
    private function regenerateSession(): void
    {
        // Preserve session data
        $session_data = $_SESSION;
        
        // Regenerate session ID
        session_regenerate_id(true);
        
        // Restore session data
        $_SESSION = $session_data;
    }
    
    /**
     * Check if an operation is rate limited
     * @param string $operation The operation to check ('register', 'delete', etc.)
     * @return bool True if rate limited, false otherwise
     */
    private function isRateLimited(string $operation): bool
    {
        $key = "rate_limit_{$operation}_{$this->account_id}";
        
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            return false;
        }
        
        // Clean up old attempts
        $now = time();
        $_SESSION[$key] = array_filter($_SESSION[$key], function($timestamp) use ($now) {
            return $timestamp > ($now - $this->rate_window);
        });
        
        // Check if we've exceeded the maximum attempts
        return count($_SESSION[$key]) >= $this->max_attempts;
    }
    
    /**
     * Increment the rate limit counter for an operation
     * @param string $operation The operation being performed
     * @return void
     */
    private function incrementRateLimitCounter(string $operation): void
    {
        $key = "rate_limit_{$operation}_{$this->account_id}";
        
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        
        $_SESSION[$key][] = time();
    }
    
    /**
     * Log an audit event for security monitoring
     * @param string $event_type The type of event
     * @param string $event_details Additional details about the event
     * @return void
     */
    private function logAuditEvent(string $event_type, string $event_details): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $log_entry = json_encode([
            'timestamp' => date('c'),
            'account_id' => $this->account_id,
            'username' => $this->account_lid,
            'ip_address' => $ip,
            'user_agent' => $user_agent,
            'event_type' => $event_type,
            'details' => $event_details
        ]);
        
        // Log to the security audit log
        error_log("[SECURITY_AUDIT] {$log_entry}", 0);
    }
}
