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
					DateHelper::date_full($passkey['added']) :
					lang('Unknown');

				$last_used = isset($passkey['last_used']) && !empty($passkey['last_used']) ?
					DateHelper::date_full($passkey['last_used']) :
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
	 * Generate registration options for a new passkey
	 */
	public function getRegistrationOptions(Request $request, Response $response): Response
	{
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

		// Store the challenge in session for verification
		$_SESSION['webauthn_challenge'] = $this->auth_passkeys->getWebAuthnInstance()->getChallenge()->getBinaryString();

		$response->getBody()->write(json_encode($options));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Verify and store a new passkey
	 */
	public function verifyRegistration(Request $request, Response $response): Response
	{
		$params = json_decode($request->getBody()->getContents(), true);
		$deviceName = $_GET['device_name'] ?? '';

		if (!$params)
		{
			return $this->jsonError($response, 'Invalid request data', 400);
		}

		// Log received data for debugging
		error_log("Passkey registration verification - Received data: " . json_encode([
			'id' => $params['id'] ?? 'missing',
			'clientDataJSON_length' => isset($params['response']['clientDataJSON']) ? strlen($params['response']['clientDataJSON']) : 'missing',
			'attestationObject_length' => isset($params['response']['attestationObject']) ? strlen($params['response']['attestationObject']) : 'missing',
			'account_id' => $this->account_id,
			'device_name' => $deviceName
		]));

		// Check if webauthn_challenge exists in session
		if (!isset($_SESSION['webauthn_challenge']) || empty($_SESSION['webauthn_challenge']))
		{
			error_log("Passkey registration failed: No challenge found in session");
			return $this->jsonError($response, 'Challenge not found or expired. Please try again.', 400);
		}

		try
		{
			// Verify with WebAuthn
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
				return $this->jsonSuccess($response, 'Passkey registered successfully');
			}
			else
			{
				error_log("Passkey registration failed: WebAuthn verification returned false");
				return $this->jsonError($response, 'Failed to register passkey - verification returned false');
			}
		}
		catch (\Exception $e)
		{
			error_log("Passkey registration error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
			return $this->jsonError($response, 'Error: ' . $e->getMessage());
		}
	}

	/**
	 * Delete a passkey
	 */
	public function deletePasskey(Request $request, Response $response): Response
	{
		$params = json_decode($request->getBody()->getContents(), true);

		if (!isset($params['credential_id']))
		{
			return $this->jsonError($response, 'Missing credential ID', 400);
		}

		$credentialId = $params['credential_id'];

		try
		{
			$success = $this->auth_passkeys->remove_passkey($this->account_id, $credentialId);

			if ($success)
			{
				return $this->jsonSuccess($response, 'Passkey deleted successfully');
			}
			else
			{
				return $this->jsonError($response, 'Failed to delete passkey');
			}
		}
		catch (\Exception $e)
		{
			return $this->jsonError($response, 'Error: ' . $e->getMessage());
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
}
