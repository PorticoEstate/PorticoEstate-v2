<?php

/**
 * Authentication based on Passkeys (FIDO2/WebAuthn)
 * @author 
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage accounts
 */

namespace App\modules\phpgwapi\security\Auth;

use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CheckClientDataCollectorType;
use Webauthn\CeremonyStep\CheckChallenge;
use Webauthn\CeremonyStep\CheckOrigin;
use Webauthn\CeremonyStep\CheckRelyingPartyIdIdHash;
use Webauthn\CeremonyStep\CheckUserWasPresent;
use Webauthn\CeremonyStep\CheckUserVerification;
use Webauthn\CeremonyStep\CheckCounter;
use Webauthn\CeremonyStep\CheckBackupBitsAreConsistent;
use Webauthn\CeremonyStep\CheckSignature;
use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\ECDSA;
use Cose\Algorithm\Signature\EdDSA;
use Cose\Algorithm\Signature\RSA;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use Base64Url\Base64Url;
use App\modules\phpgwapi\services\Settings;
use PDO;

class Auth_Passkeys
{
    private $db;
    private CoseAlgorithmManager $algorithmManager;
    private CeremonyStepManager $ceremonyStepManager;

    public function __construct()
    {
        $this->db = \App\Database\Db::getInstance();
        
        // Initialize the COSE Algorithm Manager with supported algorithms
        $this->algorithmManager = new CoseAlgorithmManager();
        $this->algorithmManager->add(
            new ECDSA\ES256(),
            new ECDSA\ES384(),
            new ECDSA\ES512(),
            new RSA\RS256(),
            new RSA\RS384(),
            new RSA\RS512(),
            new EdDSA\Ed25519()
        );
        
        // Create individual assertion steps
        $assertionSteps = [
            new CheckClientDataCollectorType(),
            new CheckChallenge(),
            new CheckOrigin([]),
            new CheckRelyingPartyIdIdHash(),
            new CheckUserWasPresent(),
            new CheckUserVerification(),
            new CheckCounter(new \Webauthn\Counter\CounterChecker()),
            new CheckBackupBitsAreConsistent(),
            new CheckSignature()
        ];
        
        // Initialize the CeremonyStepManager with the array of steps
        $this->ceremonyStepManager = new CeremonyStepManager($assertionSteps);
    }

	/**
	 * Change the password of the current user
	 * @param string $old_passwd
	 * @param string $new_passwd
	 * @param int $account_id
	 * @return bool
	 */
	public function change_password($old_passwd, $new_passwd, $account_id = 0)
	{
		// This method is not applicable for Passkeys authentication
		return false;
	}

    /**
     * Get the username based on Passkey (WebAuthn) credentials
     * @param string $credentialId Base64 encoded credential ID
     * @param string $clientDataJSON Base64 encoded client data JSON
     * @param string $authenticatorData Base64 encoded authenticator data
     * @param string $signature Base64 encoded signature
     * @return string Username if valid, empty string otherwise
     */
    public function get_username($credentialId, $clientDataJSON, $authenticatorData, $signature)
    {
        try {
            // Load all accounts and search for the credentialId
            $sql = 'SELECT a.account_id, a.account_lid, d.account_data 
                    FROM phpgw_accounts_data d
                    JOIN phpgw_accounts a ON a.account_id = d.account_id
                    WHERE d.account_data LIKE :search';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':search' => '%' . $this->db->db_addslashes($credentialId) . '%']);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $account_data = json_decode($row['account_data'], true);
                if (!isset($account_data['passkeys'])) {
                    continue;
                }
                
                $matchingKey = null;
                foreach ($account_data['passkeys'] as $item) {
                    if ($item['credential_id'] === $credentialId) {
                        $matchingKey = $item;
                        break;
                    }
                }
                
                if (!$matchingKey) {
                    continue;
                }
                
                // Get the RP ID (hostname)
                $rpId = parse_url($_SERVER['HTTP_HOST'] ?? 'localhost', PHP_URL_HOST);
                
                // Create credential source for validation
                $credentialSource = new PublicKeyCredentialSource(
                    $credentialId,
                    'public-key',
                    [], // transports
                    'none', // attestation type
                    new EmptyTrustPath(), // trust path
                    Uuid::fromString('00000000-0000-0000-0000-000000000000'), // AAGUID
                    base64_decode($matchingKey['public_key']), // credential public key
                    (string)$row['account_id'], // user handle
                    0 // counter
                );
                
                // Create a custom repository that only knows about this credential
                $repository = new class($credentialSource) implements PublicKeyCredentialSourceRepositoryInterface {
                    private PublicKeyCredentialSource $source;
                    
                    public function __construct(PublicKeyCredentialSource $source) {
                        $this->source = $source;
                    }
                    
                    public function findOneByCredentialId(string $credentialId): ?PublicKeyCredentialSource {
                        return $this->source->publicKeyCredentialId === $credentialId ? $this->source : null;
                    }
                    
                    public function findAllForUserEntity(PublicKeyCredentialUserEntity $userEntity): array {
                        return [$this->source];
                    }
                };
                
                // Create AuthenticatorAssertionResponse object
                $authenticatorAssertionResponse = new AuthenticatorAssertionResponse(
                    Base64Url::decode($clientDataJSON),
                    Base64Url::decode($authenticatorData),
                    Base64Url::decode($signature),
                    null // userHandle
                );
                
                // Get the expected challenge from session
                $expectedChallenge = isset($_SESSION['webauthn_challenge']) 
                    ? $_SESSION['webauthn_challenge'] 
                    : '';
                    
                // If challenge exists, validate the credential
                if (!empty($expectedChallenge)) {
                    // Create the PublicKeyCredentialRequestOptions
                    $publicKeyCredentialRequestOptions = PublicKeyCredentialRequestOptions::create(
                        $expectedChallenge,
                        60000, // timeout
                        $rpId,
                        null,    // allowCredentials
                        null,  // userVerification
                    );
                    
                    // Create the validator with our ceremony steps
                    $validator = new AuthenticatorAssertionResponseValidator($this->ceremonyStepManager);
                    $validator->setLogger(new NullLogger());
                    
                    // Perform the validation
                    $validator->check(
                        $credentialSource,
                        $authenticatorAssertionResponse,
                        $publicKeyCredentialRequestOptions,
                        $rpId,
                        null // userHandle
                    );
                    
                    // Authentication successful, update last login
                    $this->update_lastlogin($row['account_id'], $_SERVER['REMOTE_ADDR'] ?? '');
                    
                    // Clear the challenge after successful authentication
                    unset($_SESSION['webauthn_challenge']);
                    
                    // Return the username
                    return $row['account_lid'];
                }
            }
        } catch (\Throwable $e) {
            // Log error for debugging
            error_log('WebAuthn validation error: ' . $e->getMessage());
        }
        
        // No valid credential found or validation failed
        return '';
    }

	/**
	 * Register a new Passkey credential for a user
	 * @param int $account_id
	 * @param string $credentialId
	 * @param string $publicKey
	 * @param string $deviceName
	 * @return bool
	 */
	public function register_passkey($account_id, $credentialId, $publicKey, $deviceName = '')
	{
		// Fetch current account_data
		$sql = 'SELECT account_data FROM phpgw_accounts_data WHERE account_id = :account_id';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':account_id' => $account_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		$account_data = $row ? json_decode($row['account_data'], true) : [];
		if (!isset($account_data['passkeys']))
		{
			$account_data['passkeys'] = [];
		}

		// Add new credential
		$account_data['passkeys'][] = [
			'credential_id' => $credentialId,
			'public_key' => $publicKey,
			'device_name' => $deviceName,
			'added' => date('c')
		];

		// Save back to DB
		$sql = 'UPDATE phpgw_accounts_data SET account_data = :account_data WHERE account_id = :account_id';
		$stmt = $this->db->prepare($sql);
		return $stmt->execute([
			':account_data' => json_encode($account_data),
			':account_id' => $account_id
		]);
	}

	/**
	 * Get all registered passkeys for a user
	 * @param int $account_id
	 * @return array
	 */
	public function get_passkeys($account_id)
	{
		$sql = 'SELECT account_data FROM phpgw_accounts_data WHERE account_id = :account_id';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':account_id' => $account_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		$account_data = $row ? json_decode($row['account_data'], true) : [];
		return isset($account_data['passkeys']) ? $account_data['passkeys'] : [];
	}

	/**
	 * Remove a passkey credential for a user
	 * @param int $account_id
	 * @param string $credentialId
	 * @return bool
	 */
	public function remove_passkey($account_id, $credentialId)
	{
		$sql = 'SELECT account_data FROM phpgw_accounts_data WHERE account_id = :account_id';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':account_id' => $account_id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		$account_data = $row ? json_decode($row['account_data'], true) : [];
		if (!isset($account_data['passkeys']))
		{
			return false;
		}

		$account_data['passkeys'] = array_values(array_filter(
			$account_data['passkeys'],
			fn($item) => $item['credential_id'] !== $credentialId
		));

		$sql = 'UPDATE phpgw_accounts_data SET account_data = :account_data WHERE account_id = :account_id';
		$stmt = $this->db->prepare($sql);
		return $stmt->execute([
			':account_data' => json_encode($account_data),
			':account_id' => $account_id
		]);
	}

	/**
	 * Update last login (reuse from Auth_)
	 */
	public function update_lastlogin($account_id, $ip)
	{
		$ip = $this->db->db_addslashes($ip);
		$account_id = (int) $account_id;
		$now = time();

		$sql = 'UPDATE phpgw_accounts'
			. " SET account_lastloginfrom = :ip,"
			. " account_lastlogin = :now"
			. " WHERE account_id = :account_id";

		$stmt = $this->db->prepare($sql);
		$stmt->bindParam(':ip', $ip);
		$stmt->bindParam(':now', $now);
		$stmt->bindParam(':account_id', $account_id);
		$stmt->execute();
	}
}
