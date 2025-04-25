<?php

/**
 * Authentication based on Passkeys (FIDO2/WebAuthn) using lbuchs/webauthn
 * @author 
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage accounts
 */

namespace App\modules\phpgwapi\security\Auth;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use PDO;
use App\modules\phpgwapi\services\Settings; // Assuming Settings service is used for config

class Auth_Passkeys
{
    private $db;
    private WebAuthn $webAuthn;
    private string $appName = 'PorticoEstate'; // Default, consider making configurable
    private string $rpId; // Relying Party ID (domain name)
    private $serverSettings;

    public function __construct()
    {
        $this->db = \App\Database\Db::getInstance();
        $this->serverSettings = Settings::getInstance()->get('server');

        // Determine Relying Party ID (usually the domain name)
        // Ensure this matches the domain the user sees in the browser
        $this->rpId = parse_url($_SERVER['HTTP_HOST'] ?? 'http://localhost', PHP_URL_HOST);
        if (!$this->rpId)
        {
            // Fallback or throw error if host cannot be determined
            $this->rpId = 'localhost';
            error_log('Warning: Could not determine RP ID from HTTP_HOST, defaulting to localhost');
        }

        // Get allowed origins (e.g., from settings or hardcoded)
        // Should include the full origin (scheme + host + port if non-standard)
        $allowedOrigins = [sprintf('https://%s', $this->rpId)];
        // Add http://localhost for development if needed, but be careful in production
        if ($this->rpId === 'localhost')
        {
            $allowedOrigins[] = 'http://localhost'; // Add appropriate port if needed
        }
        // You might fetch this from Settings::get('webauthn_allowed_origins') or similar

        try
        {
            $this->webAuthn = new WebAuthn($this->appName, $this->rpId);
            // Add root certificate(s) if you want to verify attestation
            // $this->webAuthn->addRootCertificates('path/to/certificates.pem'); 
        }
        catch (\Exception $e)
        {
            error_log('WebAuthn Initialization Error: ' . $e->getMessage());
            // Handle initialization error appropriately
            throw $e;
        }
    }

    /**
     * Generates arguments for navigator.credentials.create()
     * @param int $account_id The user's account ID
     * @param string $username The user's username
     * @param string $displayName The user's display name
     * @return \stdClass Options for navigator.credentials.create()
     */
    public function getRegistrationArgs(int $account_id, string $username, string $displayName): \stdClass
    {
        $existingCredentialIds = $this->getCredentialIdsForUser($account_id);

        // Note: lbuchs/webauthn uses the user ID directly (must be binary string)
        // We are using the account_id as the user ID here.
        // IMPORTANT: The library expects the user ID to be a raw binary string.
        // If account_id is numeric, it needs careful handling or conversion.
        // For simplicity here, let's assume account_id can be treated as a string,
        // but review if this ID needs to be binary/non-guessable.
        $userIdBinary = (string)$account_id;

        return $this->webAuthn->getCreateArgs(
            $userIdBinary,
            $username,
            $displayName,
            60 * 4, // Timeout
            true, // Require resident key (passkey)
            true, // Require user verification
            $existingCredentialIds // Exclude existing credentials for this user
        );
        // Challenge is automatically generated and stored in session: $_SESSION['challenge']
    }

    /**
     * Processes the response from navigator.credentials.create()
     * @param string $clientDataJSON Base64URL encoded clientDataJSON
     * @param string $attestationObject Base64URL encoded attestationObject
     * @param string $credentialId Base64URL encoded credential ID (from client, for logging/reference)
     * @param int $account_id The user ID this credential should be associated with
     * @param string $deviceName Optional device name provided by user
     * @param bool $requireResidentKey Whether resident key was required during creation
     * @return bool True if registration was successful
     */
    public function processRegistration(string $clientDataJSON, string $attestationObject, string $credentialId, int $account_id, string $deviceName = '', bool $requireResidentKey = true): bool
    {
        try
        {
            // The library expects raw binary data, not Base64URL
            // It handles Base64URL decoding internally if data starts with '{' or is json

            $credentialData = $this->webAuthn->processCreate(
                $clientDataJSON,
                $attestationObject,
                $_SESSION['webauthn_challenge'] ?? null, // Challenge from session
                $requireResidentKey, // requireResidentKey
                true,  // requireUserVerification
                false // checkOrigin - Already checked by library constructor based on allowedOrigins
            );

            // Store the credential data
            $this->storeCredentialSource(
                $account_id,
                $credentialData->credentialId, // Raw binary ID from library
                $credentialData->credentialPublicKey, // PEM format public key
                $credentialData->signCount, // Initial sign count
                $deviceName
            );

            // Clear challenge from session after successful use
            unset($_SESSION['webauthn_challenge']);
            return true;
        }
        catch (\Exception $e)
        {
            error_log('WebAuthn Registration Processing Error: ' . $e->getMessage());
            // Optionally clear challenge on error? Depends on desired retry behavior.
            // unset($_SESSION['webauthn_challenge']); 
            return false;
        }
    }


    /**
     * Generates arguments for navigator.credentials.get()
     * @param int|null $account_id Optional: If provided, only allows credentials for this user
     * @return \stdClass Options for navigator.credentials.get()
     */
    public function getAuthenticationArgs(?int $account_id = null): \stdClass
    {
        $allowedCredentialIds = [];
        if ($account_id !== null)
        {
            $allowedCredentialIds = $this->getCredentialIdsForUser($account_id);
        }

        return $this->webAuthn->getGetArgs(
            $allowedCredentialIds, // Allow specific credentials (can be empty to allow any)
            60 * 4 // Timeout
        );
        // Challenge is automatically generated and stored in session: $_SESSION['challenge']
    }

    /**
     * Processes the response from navigator.credentials.get() and returns the username on success
     * @param string $clientDataJSON Base64URL encoded clientDataJSON
     * @param string $authenticatorData Base64URL encoded authenticatorData
     * @param string $signature Base64URL encoded signature
     * @param string $credentialId Base64URL encoded credential ID provided by the client
     * @param string|null $userHandle Base64URL encoded userHandle (if available, from resident key)
     * @return string Username if valid, empty string otherwise
     */
    public function processAuthentication(string $clientDataJSON, string $authenticatorData, string $signature, string $credentialId, ?string $userHandle): string
    {
        try
        {
            // The library expects raw binary data for credentialId if looking up source
            // Use ByteBuffer::fromBase64Url to decode the Base64URL string
            $rawCredentialId = ByteBuffer::fromBase64Url($credentialId)->getBinaryString();

            // 1. Find the credential public key and counter
            $credentialSource = $this->getCredentialSourceById($rawCredentialId);

            if (!$credentialSource)
            {
                error_log('WebAuthn Authentication Error: Credential ID not found.');
                return '';
            }

            // 2. Process the authentication attempt
            $signCount = $this->webAuthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $credentialSource['public_key'], // Stored PEM public key
                $credentialSource['sign_count'], // Stored sign count
                $_SESSION['webauthn_challenge'] ?? null, // Challenge from session
                null, // checkOrigin - Checked by library constructor
                null  // checkRpId - Checked by library constructor
            );

            // 3. Authentication successful - Update counter and last login
            $this->updateCredentialCounter($rawCredentialId, $signCount);
            $this->update_lastlogin($credentialSource['account_id'], $_SERVER['REMOTE_ADDR'] ?? '');

            // 4. Get the username associated with this credential
            $username = $this->getUsernameByAccountId($credentialSource['account_id']);

            // Clear challenge from session after successful use
            unset($_SESSION['webauthn_challenge']);

            return $username ?? '';
        }
        catch (\Exception $e)
        {
            error_log('WebAuthn Authentication Processing Error: ' . $e->getMessage());
            // Optionally clear challenge on error?
            // unset($_SESSION['webauthn_challenge']);
            return '';
        }
    }

    // --- Credential Storage/Retrieval Methods ---

    /**
     * Stores the new credential data associated with a user account.
     * Adapts the previous register_passkey logic.
     * @param int $account_id
     * @param string $credentialId Raw binary credential ID
     * @param string $publicKey PEM formatted public key
     * @param int $signCount Initial signature counter
     * @param string $deviceName Optional device name
     * @return bool Success status
     */
    private function storeCredentialSource(int $account_id, string $credentialId, string $publicKey, int $signCount, string $deviceName = ''): bool
    {
        $sql = 'SELECT account_data FROM phpgw_accounts_data WHERE account_id = :account_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':account_id' => $account_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $account_data = $row ? json_decode($row['account_data'], true) : [];
        if (!isset($account_data['passkeys']))
        {
            $account_data['passkeys'] = [];
        }

        // Encode binary ID to Base64URL for JSON storage
        $buffer = new ByteBuffer($credentialId);
        ByteBuffer::$useBase64UrlEncoding = true;
        $credentialIdBase64Url = $buffer->jsonSerialize();

        // Check if this credential ID already exists for the user (shouldn't happen with excludeCredentials)
        $exists = false;
        foreach ($account_data['passkeys'] as $key)
        {
            if ($key['credential_id'] === $credentialIdBase64Url)
            {
                $exists = true;
                break;
            }
        }

        if (!$exists)
        {
            $account_data['passkeys'][] = [
                'credential_id' => $credentialIdBase64Url, // Store as Base64URL
                'public_key' => $publicKey, // Store PEM key
                'sign_count' => $signCount, // Store counter
                'device_name' => $deviceName,
                'added' => date('c')
            ];

            $sql = 'UPDATE phpgw_accounts_data SET account_data = :account_data WHERE account_id = :account_id';
            if (!$row)
            { // Insert if no row existed
                $sql = 'INSERT INTO phpgw_accounts_data (account_id, account_data) VALUES (:account_id, :account_data)';
            }
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':account_data' => json_encode($account_data),
                ':account_id' => $account_id
            ]);
        }
        else
        {
            error_log("Attempted to register existing credential ID {$credentialIdBase64Url} for account {$account_id}");
            return false; // Indicate failure or handle as update if needed
        }
    }

    /**
     * Retrieves all registered passkey credential details for a user.
     * @param int $account_id
     * @return array
     */
    public function get_passkeys(int $account_id): array
    {
        $sql = 'SELECT account_data FROM phpgw_accounts_data WHERE account_id = :account_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':account_id' => $account_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $account_data = $row ? json_decode($row['account_data'], true) : [];
        return $account_data['passkeys'] ?? [];
    }

    /**
     * Retrieves all registered credential IDs (raw binary format) for a user.
     * Used by getCreateArgs (excludeCredentials) and getGetArgs (allowCredentials).
     * @param int $account_id
     * @return array<string> Array of raw binary credential IDs
     */
    private function getCredentialIdsForUser(int $account_id): array
    {
        $passkeys = $this->get_passkeys($account_id);
        $ids = [];
        foreach ($passkeys as $key)
        {
            try
            {
                // Decode Base64URL from storage back to raw binary
                $ids[] = ByteBuffer::fromBase64Url($key['credential_id'])->getBinaryString();
            }
            catch (\Exception $e)
            {
                error_log("Failed to decode stored credential ID {$key['credential_id']} for user {$account_id}: " . $e->getMessage());
            }
        }
        return $ids;
    }

    /**
     * Retrieves specific credential details by its raw binary ID.
     * Used during authentication (processGet).
     * @param string $rawCredentialId Raw binary credential ID
     * @return array|null Credential details [account_id, public_key, sign_count] or null if not found
     */
    private function getCredentialSourceById(string $rawCredentialId): ?array
    {
        // Create a ByteBuffer and use jsonSerialize to get the Base64URL string
        $buffer = new ByteBuffer($rawCredentialId);
        // Set to use Base64URL encoding (instead of RFC 1342-like format)
        ByteBuffer::$useBase64UrlEncoding = true;
        $credentialIdBase64Url = $buffer->jsonSerialize();
        
        // This requires searching through all users' data - potentially inefficient.
        // Consider a dedicated table for credentials if performance becomes an issue.
        $sql = 'SELECT account_id, account_data FROM phpgw_accounts_data WHERE account_data LIKE :search';
        $stmt = $this->db->prepare($sql);
        // Use a broader search initially, then filter in PHP
        $stmt->execute([':search' => '%"credential_id":"' . $this->db->db_addslashes($credentialIdBase64Url) . '"%']);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
        {
            $account_data = json_decode($row['account_data'], true);
            if (isset($account_data['passkeys']))
            {
                foreach ($account_data['passkeys'] as $key)
                {
                    if ($key['credential_id'] === $credentialIdBase64Url)
                    {
                        return [
                            'account_id' => (int)$row['account_id'],
                            'public_key' => $key['public_key'], // PEM format
                            'sign_count' => (int)$key['sign_count']
                        ];
                    }
                }
            }
        }
        return null;
    }

    /**
     * Updates the signature counter for a given credential.
     * @param string $rawCredentialId Raw binary credential ID
     * @param int $newSignCount The new counter value after successful authentication
     * @return bool Success status
     */
    private function updateCredentialCounter(string $rawCredentialId, int $newSignCount): bool
    {
        // Create a ByteBuffer and use jsonSerialize to get the Base64URL string
        $buffer = new ByteBuffer($rawCredentialId);
        // Set to use Base64URL encoding (instead of RFC 1342-like format)
        ByteBuffer::$useBase64UrlEncoding = true;
        $credentialIdBase64Url = $buffer->jsonSerialize();

        // Find the account associated with this credential ID
        $sql = 'SELECT account_id, account_data FROM phpgw_accounts_data WHERE account_data LIKE :search';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':search' => '%"credential_id":"' . $this->db->db_addslashes($credentialIdBase64Url) . '"%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); // Assume credential IDs are unique across users

        if ($row)
        {
            $account_id = (int)$row['account_id'];
            $account_data = json_decode($row['account_data'], true);
            $updated = false;
            if (isset($account_data['passkeys']))
            {
                foreach ($account_data['passkeys'] as &$key)
                { // Use reference to modify array directly
                    if ($key['credential_id'] === $credentialIdBase64Url)
                    {
                        $key['sign_count'] = $newSignCount;
                        $updated = true;
                        break;
                    }
                }
                unset($key); // Unset reference

                if ($updated)
                {
                    $updateSql = 'UPDATE phpgw_accounts_data SET account_data = :account_data WHERE account_id = :account_id';
                    $updateStmt = $this->db->prepare($updateSql);
                    return $updateStmt->execute([
                        ':account_data' => json_encode($account_data),
                        ':account_id' => $account_id
                    ]);
                }
            }
        }
        error_log("Failed to update counter for credential ID {$credentialIdBase64Url}");
        return false;
    }


    /**
     * Remove a passkey credential for a user.
     * @param int $account_id
     * @param string $credentialIdBase64Url Base64URL encoded credential ID to remove
     * @return bool
     */
    public function remove_passkey(int $account_id, string $credentialIdBase64Url): bool
    {
        $sql = 'SELECT account_data FROM phpgw_accounts_data WHERE account_id = :account_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':account_id' => $account_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $account_data = $row ? json_decode($row['account_data'], true) : [];
        if (!isset($account_data['passkeys']))
        {
            return false; // No passkeys to remove
        }

        $initialCount = count($account_data['passkeys']);
        $account_data['passkeys'] = array_values(array_filter(
            $account_data['passkeys'],
            fn($item) => $item['credential_id'] !== $credentialIdBase64Url
        ));
        $removed = count($account_data['passkeys']) < $initialCount;

        if ($removed)
        {
            $sql = 'UPDATE phpgw_accounts_data SET account_data = :account_data WHERE account_id = :account_id';
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':account_data' => json_encode($account_data),
                ':account_id' => $account_id
            ]);
        }

        return false; // Credential ID not found
    }

    /**
     * Gets the username (account_lid) for a given account ID.
     * @param int $account_id
     * @return string|null
     */
    private function getUsernameByAccountId(int $account_id): ?string
    {
        $sql = 'SELECT account_lid FROM phpgw_accounts WHERE account_id = :account_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':account_id' => $account_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['account_lid'] ?? null;
    }


    /**
     * Update last login (reuse from previous implementation)
     * @param int $account_id
     * @param string $ip
     */
    public function update_lastlogin(int $account_id, string $ip): void
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

    /**
     * Change password - Not applicable for Passkeys, keep for interface compatibility?
     * @param string $old_passwd
     * @param string $new_passwd
     * @param int $account_id
     * @return bool
     */
    public function change_password($old_passwd, $new_passwd, $account_id = 0): bool
    {
        // This method is not directly applicable for Passkeys authentication
        // but might be needed if the class implements a broader Auth interface.
        return false;
    }

    /**
     * Helper to get the WebAuthn instance if needed externally (e.g., for encoding/decoding)
     * @return WebAuthn
     */
    public function getWebAuthnInstance(): WebAuthn
    {
        return $this->webAuthn;
    }
}
