<?php

/**
 * Authentication based on Passkeys (FIDO2/WebAuthn) using lbuchs/webauthn
 * @author 
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage accounts
 */

namespace App\modules\phpgwapi\security\Auth;

// Enable error logging for this script
//ini_set('log_errors', 'On');


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

        // Determine Relying Party ID (usually the domain name without protocol or port)
        // Ensure this matches the domain the user sees in the browser
        // Get host from HTTP_HOST or SERVER_NAME, fallback to localhost
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

        // Remove port number if present
        if (strpos($host, ':') !== false)
        {
            $host = strtok($host, ':');
        }

        // Always use the full hostname as the rpId
        $this->rpId = $host;
        error_log("Using full host as rpId: {$this->rpId}");

        // Rather than constructing origins with schemes, let the library handle it
        // The library will automatically construct the proper allowed origins
        error_log("WebAuthn configuration - rpId: {$this->rpId}");

        try
        {
            // Pass null as the third parameter to let the library handle allowed origins
            // Based on the error message, it seems the library has an issue with the format
            $this->webAuthn = new WebAuthn($this->appName, $this->rpId, null);
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

        // Create user ID as a random value + account ID (base64url encoded)
        $randomBytes = random_bytes(16);
        $rawUserId = $account_id . '_' . bin2hex($randomBytes);
        $userIdBase64Url = self::base64url_encode($rawUserId);

        // Generate registration options
        // Note: The lbuchs/webauthn library might set some defaults we need to override
        $options = $this->webAuthn->getCreateArgs(
            $userIdBase64Url, // Use base64url encoded user ID
            $username,
            $displayName,
            60 * 3, // Timeout
            true, // Prevent re-registration
            $existingCredentialIds // Exclude existing
        );

        // Generate and store the challenge (raw binary in session, base64url for client)
        $rawChallenge = $this->webAuthn->getChallenge()->getBinaryString();
        $_SESSION['webauthn_challenge'] = [
            'value' => $rawChallenge,
            'expires' => time() + 300 // 5 minute expiration
        ];
        $challengeBase64Url = self::base64url_encode($rawChallenge);

        // --- Start Correcting the Options Structure ---

        // Create a clean stdClass object for the final response
        $finalOptions = new \stdClass();

        // Build the publicKey object correctly
        $finalOptions->publicKey = new \stdClass();

        // 1. Set Relying Party (rp)
        $finalOptions->publicKey->rp = $options->rp; // Use rp from library

        // 2. Set User Information (ensure ID is base64url)
        $finalOptions->publicKey->user = new \stdClass();
        $finalOptions->publicKey->user->id = $userIdBase64Url; // Already base64url
        $finalOptions->publicKey->user->name = $username;
        $finalOptions->publicKey->user->displayName = $displayName;

        // 3. Set Challenge (base64url)
        $finalOptions->publicKey->challenge = $challengeBase64Url; // Use base64url challenge

        // 4. Set pubKeyCredParams
        $finalOptions->publicKey->pubKeyCredParams = $options->pubKeyCredParams; // Use params from library

        // 5. Set Timeout
        $finalOptions->publicKey->timeout = $options->timeout; // Use timeout from library

        // 6. Set ExcludeCredentials (ensure IDs are base64url)
        $finalOptions->publicKey->excludeCredentials = [];
        foreach ($existingCredentialIds as $rawId) {
            $finalOptions->publicKey->excludeCredentials[] = (object)[
                'type' => 'public-key',
                'id' => self::base64url_encode($rawId) // Encode existing IDs to base64url
            ];
        }

        // 7. Set Authenticator Selection (Consistent & Secure Values)
        $finalOptions->publicKey->authenticatorSelection = new \stdClass();
        $finalOptions->publicKey->authenticatorSelection->authenticatorAttachment = null; // Allow any
        $finalOptions->publicKey->authenticatorSelection->userVerification = 'required';
        $finalOptions->publicKey->authenticatorSelection->residentKey = 'preferred';
        $finalOptions->publicKey->authenticatorSelection->requireResidentKey = false;

        // 8. Set Attestation
        $finalOptions->publicKey->attestation = $options->attestation ?? 'indirect'; // Use library default or 'indirect'

        // 9. Set Extensions (if any)
        if (isset($options->extensions)) {
            $finalOptions->publicKey->extensions = $options->extensions;
        }

        // --- End Correcting the Options Structure ---

        // Return ONLY the correctly structured object
        // Do NOT return the original $options or add root-level properties
        return $finalOptions;
    }

    /**
     * Ensure a WebAuthn challenge is properly formatted as base64url
     * This handles any unusual formats like RFC 1342 encoded strings
     * 
     * @param mixed $challenge The challenge to format
     * @return string Properly formatted base64url string
     */
    private function ensureProperChallengeFormat($challenge): string
    {
        // Handle ByteBuffer objects from lbuchs/webauthn
        if (is_object($challenge) && method_exists($challenge, 'getBinaryString'))
        {
            // Store raw binary challenge in session for verification
            $raw = $challenge->getBinaryString();
            $_SESSION['webauthn_challenge'] = $raw;

            // Log for debugging
            error_log("WebAuthn challenge stored in session, length: " . strlen($raw));

            // Return base64url encoded challenge for client
            return self::base64url_encode($raw);
        }

        if (is_string($challenge))
        {
            // Handle RFC 1342 encoded format: =?BINARY?B\?(.*?)\?=/', $challenge, $matches))
            {
                if (preg_match('/=\?BINARY\?B\\?(.*?)\\?=/', $challenge, $matches))
                {
                    $raw = base64_decode($matches[1]);
                }
                else
                {
                    throw new \Exception('Invalid challenge format: Unable to extract binary data');
                }
                $_SESSION['webauthn_challenge'] = $raw;
                error_log("WebAuthn challenge from RFC 1342 format, length: " . strlen($raw));
                return self::base64url_encode($raw);
            }

            // If it's already base64url encoded, try to decode and re-encode to verify
            try
            {
                $raw = self::base64url_decode($challenge);
                $_SESSION['webauthn_challenge'] = $raw;
                error_log("WebAuthn challenge from base64url, length: " . strlen($raw));
                return self::base64url_encode($raw);
            }
            catch (\Exception $e)
            {
                error_log("Challenge decode/encode failed: " . $e->getMessage());
            }
        }

        error_log("WebAuthn challenge in unexpected format: " . gettype($challenge));
        if (is_object($challenge) || is_array($challenge))
        {
            error_log("Challenge content: " . json_encode($challenge));
        }

        throw new \Exception('Invalid challenge format');
    }

    /**
     * Decode a base64url encoded string
     * Used for WebAuthn data processing
     * 
     * @param string $base64url The base64url encoded string
     * @return string The decoded string
     */
    public static function base64url_decode(string $base64url): string
    {
        $base64 = strtr($base64url, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0)
        {
            $base64 .= str_repeat('=', 4 - $padding);
        }
        return base64_decode($base64);
    }

    /**
     * Encode a string as base64url
     * Used for WebAuthn data processing
     * 
     * @param string $data The data to encode
     * @return string The base64url encoded string
     */
    public static function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
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
            // Properly decode the input data
            $clientDataJSONDecoded = null;
            $attestationObjectDecoded = null;

            // Check if the data appears to already be JSON (might start with '{')
            if (substr($clientDataJSON, 0, 1) === '{')
            {
                $clientDataJSONDecoded = $clientDataJSON;
            }
            else
            {
                // Use proper Base64URL decoding
                try
                {
                    $clientDataJSONDecoded = self::base64url_decode($clientDataJSON);
                }
                catch (\Exception $e)
                {
                    // Fallback to regular base64 decode if that fails
                    $clientDataJSONDecoded = base64_decode($clientDataJSON);
                }
            }

            // Similarly for attestation object
            try
            {
                $attestationObjectDecoded = self::base64url_decode($attestationObject);
            }
            catch (\Exception $e)
            {
                // Fallback to regular base64 decode if that fails
                $attestationObjectDecoded = base64_decode($attestationObject);
            }

            if (!$clientDataJSONDecoded || !$attestationObjectDecoded)
            {
                throw new \Exception('Failed to decode WebAuthn response data');
            }

            // Extract the challenge value from the session
            $sessionChallenge = null;
            if (isset($_SESSION['webauthn_challenge']))
            {
                if (is_array($_SESSION['webauthn_challenge']) && isset($_SESSION['webauthn_challenge']['value']))
                {
                    // New format with expiration
                    $sessionChallenge = $_SESSION['webauthn_challenge']['value'];
                }
                else
                {
                    // Legacy format (direct string)
                    $sessionChallenge = $_SESSION['webauthn_challenge'];
                }
            }

            if (!$sessionChallenge)
            {
                throw new \Exception('No valid challenge found in session');
            }

            $credentialData = $this->webAuthn->processCreate(
                $clientDataJSONDecoded,
                $attestationObjectDecoded,
                $sessionChallenge, // Use the extracted challenge value, not the array
                $requireResidentKey, // requireResidentKey
                true,  // requireUserVerification
                false // checkOrigin - Already checked by library constructor based on allowedOrigins
            );

            // Extract the sign count with proper checks
            // The property might be named counter, signCount, or something else
            // Let's check what properties are available and use the appropriate one
            $initialSignCount = 0; // Default if not available
            if (property_exists($credentialData, 'signCount'))
            {
                $initialSignCount = $credentialData->signCount;
            }
            elseif (property_exists($credentialData, 'counter'))
            {
                $initialSignCount = $credentialData->counter;
            }

            // Log the property structure for debugging
            error_log('WebAuthn credential data properties: ' . print_r(get_object_vars($credentialData), true));

            // Store the credential data
            $this->storeCredentialSource(
                $account_id,
                $credentialData->credentialId, // Raw binary ID from library
                $credentialData->credentialPublicKey, // PEM format public key
                $initialSignCount, // Initial sign count (or 0 if not provided)
                $deviceName
            );

            // Clear challenge from session after successful use
            unset($_SESSION['webauthn_challenge']);
            return true;
        }
        catch (\Exception $e)
        {
            error_log('WebAuthn Registration Processing Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            // Optionally clear challenge on error? Depends on desired retry behavior.
            // unset($_SESSION['webauthn_challenge']); 
            return false;
        }
    }

    /**
     * Processes the response from navigator.credentials.get() and returns account data on success
     * @param string $clientDataJSON Base64URL encoded clientDataJSON
     * @param string $authenticatorData Base64URL encoded authenticatorData
     * @param string $signature Base64URL encoded signature
     * @param string $credentialId Base64URL encoded credential ID provided by the client
     * @param string|null $userHandle Base64URL encoded userHandle (if available, from resident key)
     * @return array Account information (account_id, account_lid, account_status) or empty array on failure
     */
    public function processAuthentication(string $clientDataJSON, string $authenticatorData, string $signature, string $credentialId, ?string $userHandle): array
    {
        try
        {
            // Properly decode Base64URL strings to binary
            $clientDataJSONDecoded = self::base64url_decode($clientDataJSON);
            $authenticatorDataDecoded = self::base64url_decode($authenticatorData);
            $signatureDecoded = self::base64url_decode($signature);
            $credentialIdDecoded = self::base64url_decode($credentialId);
            $userHandleDecoded = $userHandle ? self::base64url_decode($userHandle) : null;

            // Extract client challenge for verification
            $clientDataArray = json_decode($clientDataJSONDecoded, true);
            if (!$clientDataArray || !isset($clientDataArray['challenge']))
            {
                throw new \Exception('Invalid client data: challenge not found');
            }

            // Get session challenge
            if (!$this->isValidSessionChallenge())
            {
                throw new \Exception('Challenge not found or expired');
            }

            // Get the challenge from session with proper structure
            $sessionChallengeData = $_SESSION['webauthn_challenge'];
            $sessionChallenge = $sessionChallengeData['value'];

            // Get client challenge
            $clientChallenge = $clientDataArray['challenge'];
            $clientChallengeBinary = self::base64url_decode($clientChallenge);

            // Get credential source from database
            $credentialSource = $this->getCredentialSourceById($credentialIdDecoded);
            if (!$credentialSource)
            {
                throw new \Exception('Credential ID not found');
            }

            // Verify the client challenge matches the session challenge
            $sessionChallengeBase64 = self::base64url_encode($sessionChallenge);
            if (!hash_equals($clientChallenge, $sessionChallengeBase64))
            {
                throw new \Exception('Challenge verification failed');
            }

            try
            {
                // Verify WebAuthn assertion
                if ($this->manuallyVerifyWebAuthnAssertion(
                    $clientDataJSONDecoded,
                    $authenticatorDataDecoded,
                    $signatureDecoded,
                    $credentialSource['public_key'],
                    $sessionChallenge
                ))
                {
                    // Extract sign count from authenticator data
                    $newSignCount = 0;
                    if (strlen($authenticatorDataDecoded) >= 37)
                    {
                        $countBytes = substr($authenticatorDataDecoded, 33, 4);
                        $newSignCount = unpack('N', $countBytes)[1];
                    }

                    // Update the sign count if needed
                    if ($newSignCount > $credentialSource['sign_count'])
                    {
                        $this->updateCredentialCounter($credentialIdDecoded, $newSignCount);
                    }

                    // Update last login timestamp
                    $this->update_lastlogin($credentialSource['account_id'], $_SERVER['REMOTE_ADDR'] ?? '');

                    // Get the account data associated with this credential
                    $account = $this->getAccountById($credentialSource['account_id']);

                    // Clear challenge from session after successful use
                    unset($_SESSION['webauthn_challenge']);

                    return $account;
                }

                // If manual verification fails, try the library with strict challenge checking
                $signCount = $this->webAuthn->processGet(
                    $clientDataJSONDecoded,
                    $authenticatorDataDecoded,
                    $signatureDecoded,
                    $credentialSource['public_key'],
                    $credentialSource['sign_count'],
                    $sessionChallenge // Use the proper session challenge
                );

                // Update the sign count if needed
                if ($signCount > $credentialSource['sign_count'])
                {
                    $this->updateCredentialCounter($credentialIdDecoded, $signCount);
                }

                // Update last login timestamp
                $this->update_lastlogin($credentialSource['account_id'], $_SERVER['REMOTE_ADDR'] ?? '');

                // Get the account data associated with this credential
                $account = $this->getAccountById($credentialSource['account_id']);

                // Clear challenge from session after successful use
                unset($_SESSION['webauthn_challenge']);

                return $account;
            }
            catch (\Exception $innerEx)
            {
                throw new \Exception('WebAuthn assertion verification failed: ' . $innerEx->getMessage());
            }
        }
        catch (\Exception $e)
        {
            // Log minimal error information
            error_log('Authentication error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if the current challenge in session is valid and not expired
     * @return bool
     */
    private function isValidSessionChallenge(): bool
    {
        if (!isset($_SESSION['webauthn_challenge']))
        {
            return false;
        }

        // Check for new format with expiration
        if (is_array($_SESSION['webauthn_challenge']))
        {
            if (
                !isset($_SESSION['webauthn_challenge']['value']) ||
                !isset($_SESSION['webauthn_challenge']['expires'])
            )
            {
                return false;
            }

            // Check if challenge has expired
            return $_SESSION['webauthn_challenge']['expires'] > time();
        }

        // Legacy format (just a string value) - consider valid but migrate to new format
        // This provides backward compatibility
        $challenge = $_SESSION['webauthn_challenge'];
        $_SESSION['webauthn_challenge'] = [
            'value' => $challenge,
            'expires' => time() + 300 // 5 minute expiration
        ];

        return true;
    }

    /**
     * Get account information by account ID
     * @param int $account_id
     * @return array Account information or empty array if not found
     */
    public function getAccountById(int $account_id): array
    {
        try
        {
            $sql = "SELECT account_id, account_lid, account_status 
                   FROM phpgw_accounts 
                   WHERE account_id = :account_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':account_id' => $account_id]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            return $account ?: [];
        }
        catch (\Exception $e)
        {
            error_log('Error retrieving account information: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Updates the counter for a credential
     * @param string $rawCredentialId Raw binary credential ID
     * @param int $newSignCount New sign count value
     * @return bool Success status
     */
    private function updateCredentialCounter(string $rawCredentialId, int $newSignCount): bool
    {
        // Create a ByteBuffer and use jsonSerialize to get the Base64URL string
        $buffer = new ByteBuffer($rawCredentialId);
        // Set to use Base64URL encoding
        ByteBuffer::$useBase64UrlEncoding = true;
        $credentialIdBase64Url = $buffer->jsonSerialize();

        try
        {
            // Get the account data containing this credential ID using parameterized query
            $sql = "SELECT account_id, account_data
                   FROM phpgw_accounts_data 
                   WHERE account_data @> :search_json::jsonb";

            $stmt = $this->db->prepare($sql);
            $searchJson = json_encode(['passkeys' => [['credential_id' => $credentialIdBase64Url]]]);
            $stmt->execute([':search_json' => $searchJson]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row)
            {
                $account_id = (int)$row['account_id'];
                $account_data = json_decode($row['account_data'], true);

                // Find the passkey in the array
                if (isset($account_data['passkeys']) && is_array($account_data['passkeys']))
                {
                    foreach ($account_data['passkeys'] as $index => $passkey)
                    {
                        if (isset($passkey['credential_id']) && $passkey['credential_id'] === $credentialIdBase64Url)
                        {
                            // Update the sign count and last_used fields
                            $account_data['passkeys'][$index]['sign_count'] = $newSignCount;
                            $account_data['passkeys'][$index]['last_used'] = date('c');

                            // Update the account data in the database
                            $updateSql = "UPDATE phpgw_accounts_data 
                                         SET account_data = :account_data
                                         WHERE account_id = :account_id";

                            $updateStmt = $this->db->prepare($updateSql);

                            return $updateStmt->execute([
                                ':account_data' => json_encode($account_data),
                                ':account_id' => $account_id
                            ]);
                        }
                    }
                }
            }

            return false;
        }
        catch (\Exception $e)
        {
            error_log('Error updating credential counter: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Updates the last_used timestamp for a given credential.
     * @param string $rawCredentialId Raw binary credential ID
     * @return bool Success status
     */
    private function updateCredentialLastUsed(string $rawCredentialId): bool
    {
        // Create a ByteBuffer and use jsonSerialize to get the Base64URL string
        $buffer = new ByteBuffer($rawCredentialId);
        // Set to use Base64URL encoding
        ByteBuffer::$useBase64UrlEncoding = true;
        $credentialIdBase64Url = $buffer->jsonSerialize();

        try
        {
            // Get the account data containing this credential ID
            $sql = "SELECT account_id, account_data
                   FROM phpgw_accounts_data 
                   WHERE account_data @> :search_json::jsonb";

            $stmt = $this->db->prepare($sql);
            $searchJson = json_encode(['passkeys' => [['credential_id' => $credentialIdBase64Url]]]);
            $stmt->execute([':search_json' => $searchJson]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row)
            {
                $account_id = (int)$row['account_id'];
                $account_data = json_decode($row['account_data'], true);

                // Find the passkey in the array
                if (isset($account_data['passkeys']) && is_array($account_data['passkeys']))
                {
                    foreach ($account_data['passkeys'] as $index => $passkey)
                    {
                        if (isset($passkey['credential_id']) && $passkey['credential_id'] === $credentialIdBase64Url)
                        {
                            // Update only the last_used field
                            $account_data['passkeys'][$index]['last_used'] = date('c');

                            // Update the account data in the database
                            $updateSql = "UPDATE phpgw_accounts_data 
                                         SET account_data = :account_data
                                         WHERE account_id = :account_id";

                            $updateStmt = $this->db->prepare($updateSql);
                            error_log("Updating last_used timestamp for credential ID {$credentialIdBase64Url}");
                            return $updateStmt->execute([
                                ':account_data' => json_encode($account_data),
                                ':account_id' => $account_id
                            ]);
                        }
                    }
                }
            }

            error_log("Passkey not found for credential ID {$credentialIdBase64Url}");
            return false;
        }
        catch (\Exception $e)
        {
            error_log("Error updating last_used for credential ID {$credentialIdBase64Url}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a passkey credential for a user.
     * @param int $account_id
     * @param string $credentialIdBase64Url Base64URL encoded credential ID to remove
     * @return bool
     */
    public function remove_passkey(int $account_id, string $credentialIdBase64Url): bool
    {
        try
        {
            // Sanitize and validate the credential ID
            $credentialIdBase64Url = trim($credentialIdBase64Url);
            if (empty($credentialIdBase64Url))
            {
                return false;
            }

            // Verify this credential belongs to the given account
            $sql = "SELECT account_data FROM phpgw_accounts_data 
                   WHERE account_id = :account_id AND 
                   account_data @> :search_json::jsonb";

            $stmt = $this->db->prepare($sql);
            $searchJson = json_encode(['passkeys' => [['credential_id' => $credentialIdBase64Url]]]);
            $stmt->execute([
                ':account_id' => $account_id,
                ':search_json' => $searchJson
            ]);

            // If no match was found, return false
            if (!$stmt->fetch(PDO::FETCH_ASSOC))
            {
                return false;
            }

            // Fetch the current account data
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
                $sql = 'UPDATE phpgw_accounts_data 
                       SET account_data = :account_data 
                       WHERE account_id = :account_id';
                $stmt = $this->db->prepare($sql);
                return $stmt->execute([
                    ':account_data' => json_encode($account_data),
                    ':account_id' => $account_id
                ]);
            }

            return false;
        }
        catch (\Exception $e)
        {
            error_log('Error removing passkey: ' . $e->getMessage());
            return false;
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

        // Use JSONB operators for efficient querying
        $sql = "SELECT account_id, account_data 
                FROM phpgw_accounts_data 
                WHERE account_data @> :search_json::jsonb";

        $stmt = $this->db->prepare($sql);
        $searchJson = json_encode(['passkeys' => [['credential_id' => $credentialIdBase64Url]]]);
        $stmt->execute([':search_json' => $searchJson]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row)
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

    /**
     * Manually verify a WebAuthn assertion without using the library's challenge validation
     * 
     * @param string $clientDataJSON Raw client data JSON
     * @param string $authenticatorData Raw authenticator data
     * @param string $signature Raw signature bytes
     * @param string $publicKey PEM formatted public key
     * @param string $challenge Expected challenge (binary)
     * @return bool True if verification succeeds
     */
    private function manuallyVerifyWebAuthnAssertion(
        string $clientDataJSON,
        string $authenticatorData,
        string $signature,
        string $publicKey,
        string $challenge
    ): bool
    {
        try
        {
            // 1. Parse clientDataJSON
            $clientDataArray = json_decode($clientDataJSON, true);
            if (!$clientDataArray || !isset($clientDataArray['type']) || !isset($clientDataArray['challenge']))
            {
                return false;
            }

            // 2. Verify that the type is webauthn.get
            if ($clientDataArray['type'] !== 'webauthn.get')
            {
                return false;
            }

            // 3. Verify challenge matches what we expect (using constant-time comparison)
            $expectedChallenge = self::base64url_encode($challenge);
            if (!hash_equals($expectedChallenge, $clientDataArray['challenge']))
            {
                return false;
            }

            // 4. Prepare client data hash
            $clientDataHash = hash('sha256', $clientDataJSON, true);

            // 5. Prepare the signature base
            $signatureBase = $authenticatorData . $clientDataHash;

            // 6. Verify the signature
            $key = openssl_pkey_get_public($publicKey);
            if (!$key)
            {
                return false;
            }

            // Determine algorithm based on key type
            $keyDetails = openssl_pkey_get_details($key);
            if (!$keyDetails)
            {
                return false;
            }

            // Use appropriate verification method based on key type
            if (isset($keyDetails['ec']))
            {
                // EC key (like P-256)
                return openssl_verify($signatureBase, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
            }
            elseif (isset($keyDetails['rsa']))
            {
                // RSA key
                return openssl_verify($signatureBase, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
            }

            return false;
        }
        catch (\Exception $e)
        {
            error_log('Manual WebAuthn verification error: ' . $e->getMessage());
            return false;
        }
    }
}
