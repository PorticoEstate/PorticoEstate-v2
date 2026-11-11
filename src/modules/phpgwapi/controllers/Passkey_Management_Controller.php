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
use App\modules\phpgwapi\helpers\LegacyViewHelper;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Preferences;
use App\modules\phpgwapi\services\Settings;
use PDO;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

class Passkey_Management_Controller
{
	protected $db;
	protected $auth_passkeys;
	protected $account_id;
	protected $account_lid;
	protected $serverSettings;
	protected $userSettings;
	private TwigHelper $twig;
	private LegacyViewHelper $legacyView;
    // Add rate limiting properties
    protected $max_attempts = 5;
    protected $rate_window = 300; // 5 minutes in seconds

	public function __construct()
	{
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		$this->db = \App\Database\Db::getInstance();
		$this->legacyView = new LegacyViewHelper();
		$this->twig = new TwigHelper('phpgwapi');

		$this->auth_passkeys = new Auth_Passkeys();

		// Get settings
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		Settings::getInstance()->update('flags', [
			'currentapp' => 'preferences']);

		// Get current user's account ID and username
		$this->account_id = (int) $this->userSettings['account_id'];
		$this->account_lid = $this->userSettings['account_lid'];

		if (!isset($_SESSION['passkey_csrf_token']) || empty($_SESSION['passkey_csrf_token']))
		{
			$_SESSION['passkey_csrf_token'] = bin2hex(random_bytes(32));
		}
	}

	/**
	 * Display the passkey management page
	 */
	public function index(Request $request, Response $response): Response
	{
		Settings::getInstance()->update('flags', [
			'app_header' => lang('Passkey Management'),
			'currentapp' => 'preferences'
		]);

		$serverUrl = rtrim((string) ($this->serverSettings['webserver_url'] ?? ''), '/');
		$html = $this->twig->render('@views/passkey_management/phpgwapi_passkey_management.twig', [
			'api_url' => \phpgw::link('/passkey/list'),
			'registration_options_url' => \phpgw::link('/passkey/register/options'),
			'registration_verify_url' => \phpgw::link('/passkey/register/verify'),
			'delete_url' => \phpgw::link('/passkey/delete'),
			'csrf_token' => $_SESSION['passkey_csrf_token'],
			'username' => $this->account_lid,
			'passkey_client_url' => $serverUrl . '/src/modules/phpgwapi/js/passkey/passkey-client.js?n=' . rawurlencode((string) ($this->serverSettings['cache_refresh_token'] ?? ''))
		]);

		$response->getBody()->write($this->legacyView->render($html, 'preferences'));
		return $response->withHeader('Content-Type', 'text/html');
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
	 * Return the current user's passkeys for the management view.
	 */
	public function listPasskeys(Request $request, Response $response): Response
	{
		if (!$this->verifyAuthentication())
		{
			return $this->jsonError($response, 'Authentication required', 401);
		}

		$passkeys = array_values(array_map(function (array $passkey): array
		{
			return [
				'credential_id' => (string) ($passkey['credential_id'] ?? ''),
				'device_name' => (string) ($passkey['device_name'] ?? lang('Unnamed Device')),
				'created' => !empty($passkey['added']) ? $this->format_datetime((string) $passkey['added']) : lang('Unknown'),
				'last_used' => !empty($passkey['last_used']) ? $this->format_datetime((string) $passkey['last_used']) : lang('Never')
			];
		}, $this->auth_passkeys->get_passkeys($this->account_id)));

		return $this->jsonSuccess($response, 'Passkeys retrieved successfully', ['passkeys' => $passkeys])
			->withHeader('Cache-Control', 'no-store');
	}

	/**
	 * Check whether the current user should see the passkey enrollment suggestion.
	 */
	public function getSuggestion(Request $request, Response $response): Response
	{
		if (!$this->verifyAuthentication())
		{
			return $this->jsonError($response, 'Authentication required', 401);
		}

		$preferences = (new Preferences($this->account_id))->read();
		$passkeyPreferences = $preferences['phpgwapi'] ?? [];
		$showSuggestion = empty($passkeyPreferences['passkey_suggestion_opt_out'])
			&& (int) ($passkeyPreferences['passkey_suggestion_snoozed_until'] ?? 0) <= time()
			&& $this->auth_passkeys->get_passkeys($this->account_id) === [];

		return $this->jsonSuccess($response, 'Passkey suggestion status retrieved', [
			'show' => $showSuggestion,
			'csrf_token' => $_SESSION['passkey_csrf_token'],
			'management_url' => \phpgw::link('/passkey'),
			'title' => lang('Sign in faster with a passkey'),
			'description' => lang('Use your device’s fingerprint, face recognition, or screen lock instead of a password. Only set this up on a device you trust.'),
			'setup' => lang('Set up passkey'),
			'later' => lang('Not now'),
			'opt_out' => lang('Don’t ask again'),
			'save_error' => lang('Unable to save passkey suggestion preference.')
		])->withHeader('Cache-Control', 'no-store');
	}

	/**
	 * Save the user's passkey suggestion preference.
	 */
	public function updateSuggestion(Request $request, Response $response): Response
	{
		if (!$this->verifyAuthentication())
		{
			return $this->jsonError($response, 'Authentication required', 401);
		}

		if (!$this->verifyCsrfToken($request))
		{
			return $this->jsonError($response, 'Invalid security token', 403);
		}

		$params = $request->getParsedBody();
		if (!is_array($params))
		{
			$params = json_decode((string) $request->getBody(), true);
		}
		$action = is_array($params) ? ($params['action'] ?? '') : '';
		if (!in_array($action, ['snooze', 'opt_out'], true))
		{
			return $this->jsonError($response, 'Invalid passkey suggestion action', 400);
		}

		$preferences = new Preferences($this->account_id);
		if ($action === 'opt_out')
		{
			$preferences->add('phpgwapi', 'passkey_suggestion_opt_out', 1);
			$preferences->add('phpgwapi', 'passkey_suggestion_snoozed_until', 0);
		}
		else
		{
			$preferences->add('phpgwapi', 'passkey_suggestion_opt_out', 0);
			$preferences->add('phpgwapi', 'passkey_suggestion_snoozed_until', time() + (30 * 24 * 60 * 60));
		}
		$preferences->save_repository();

		return $this->jsonSuccess($response, 'Passkey suggestion preference saved')
			->withHeader('Cache-Control', 'no-store');
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

		$response->getBody()->write(json_encode($options));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Verify and store a new passkey
	 */
	public function verifyRegistration(Request $request, Response $response): Response
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
        if ($this->isRateLimited('register')) {
            return $this->jsonError($response, 'Too many registration attempts. Please try again later.', 429);
        }

		$challenge = $this->auth_passkeys->consumeRegistrationChallenge();
		if ($challenge === null)
		{
			return $this->jsonError($response, 'Registration challenge is missing, expired, or already used.', 400);
		}
        
		// Get device name from query params
		$queryParams = $request->getQueryParams();
		$deviceName = $queryParams['device_name'] ?? '';
		$deviceName = trim($deviceName);
		
		if (empty($deviceName))
		{
			return $this->jsonError($response, 'Device name is required');
		}
		
		// Get registration data from request body
		$params = json_decode($request->getBody()->getContents(), true);
		if (
			!is_array($params)
			|| !isset($params['id'], $params['response']['clientDataJSON'], $params['response']['attestationObject'])
			|| !is_string($params['id'])
			|| !is_string($params['response']['clientDataJSON'])
			|| !is_string($params['response']['attestationObject'])
		)
		{
			return $this->jsonError($response, 'Invalid registration data format');
		}
		
		try
		{
			// Verify with WebAuthn
			$success = $this->auth_passkeys->processRegistration(
				$params['response']['clientDataJSON'],
				$params['response']['attestationObject'],
				$params['id'],
				$this->account_id,
				$challenge,
				$deviceName
			);

			if ($success)
			{
                // Record successful operation for audit logging
                $this->logAuditEvent('passkey_register', 'Registered new passkey: ' . $deviceName);
                
				return $this->jsonSuccess($response, 'Passkey registered successfully');
			}
			else
			{
                // Increment failed attempts counter
                $this->incrementRateLimitCounter('register');
                
				return $this->jsonError($response, 'Failed to register passkey - verification returned false');
			}
		}
		catch (\Exception $e)
		{
            // Increment failed attempts counter
            $this->incrementRateLimitCounter('register');
            
            error_log('Passkey registration request failed: ' . $e->getMessage());
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
        // Header names are case-insensitive; getHeaders() keys depend on how the server reports them
        $token = $request->getHeaderLine('X-CSRF-Token') ?: null;
        
        // Then check POST/GET params
        if (!$token) {
            $params = $request->getParsedBody();
            if (!is_array($params)) {
                $params = json_decode((string) $request->getBody(), true);
            }
            if (is_array($params) && isset($params['csrf_token'])) {
                $token = $params['csrf_token'];
            } else {
                $queryParams = $request->getQueryParams();
                if (isset($queryParams['csrf_token'])) {
                    $token = $queryParams['csrf_token'];
                }
            }
        }
        
        // Verify the token against session token using constant-time comparison
        return is_string($token) && $token !== '' && isset($_SESSION['passkey_csrf_token']) &&
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
