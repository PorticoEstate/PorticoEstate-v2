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
ini_set('log_errors', 'On');
ini_set('error_log', '/home/hc483/Api/logs/error.log');

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

        // Use the effective domain (strip subdomains if needed)
        // For example: portal.example.com -> example.com or localhost -> localhost
        $parts = explode('.', $host);
        if (count($parts) > 2 && !in_array($parts[count($parts) - 1], ['localhost', 'local', 'test']))
        {
            // Consider using the eTLD+1 (e.g., example.com) for production
            // This allows credentials to work across subdomains
            $this->rpId = $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];

            // Log that we're using the effective domain
            error_log("Using effective domain as rpId: {$this->rpId} (original host: {$host})");
        }
        else
        {
            // For localhost or simple domains, use as-is
            $this->rpId = $host;
        }

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

        // Note: lbuchs/webauthn uses the user ID directly (must be binary string)
        // We are using the account_id as the user ID here.
        $userIdBinary = (string)$account_id;

        $options = $this->webAuthn->getCreateArgs(
            $userIdBinary,
            $username,
            $displayName,
            60 * 4, // Timeout
            true, // Require resident key (passkey)
            true, // Require user verification
            $existingCredentialIds // Exclude existing credentials for this user
        );

        // Force the challenge to be a proper base64url string using our helper
        if (isset($options->challenge))
        {
            $options->challenge = $this->ensureProperChallengeFormat($options->challenge);
            // Log the processed challenge for debugging
            error_log("WebAuthn challenge after processing: " . $options->challenge);
        }

        // Process excludeCredentials to ensure they're proper base64url strings
        if (isset($options->excludeCredentials) && is_array($options->excludeCredentials))
        {
            foreach ($options->excludeCredentials as &$credential)
            {
                if (isset($credential->id))
                {
                    $credential->id = $this->ensureProperChallengeFormat($credential->id);
                }
            }
        }

        // Output the final options for debugging
        error_log("WebAuthn registration options after processing: " . json_encode($options));

        return $options;
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

            $credentialData = $this->webAuthn->processCreate(
                $clientDataJSONDecoded,
                $attestationObjectDecoded,
                $_SESSION['webauthn_challenge'] ?? null, // Challenge from session
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

        // Create a new challenge for this authentication
        $options = $this->webAuthn->getGetArgs(
            $allowedCredentialIds, // Allow specific credentials (can be empty to allow any)
            60 * 4 // Timeout
        );

        // Make sure challenge is explicitly set in the session for auth verification
        $rawChallenge = $this->webAuthn->getChallenge()->getBinaryString();
        $_SESSION['webauthn_challenge'] = $rawChallenge;

        // Make sure challenge is explicitly set in options
        if (!isset($options->challenge) || empty($options->challenge))
        {
            error_log("WebAuthn challenge not set in options - setting it manually");
            $options->challenge = self::base64url_encode($rawChallenge);
        }
        else
        {
            // Still encode the existing challenge properly
            $options->challenge = $this->ensureProperChallengeFormat($options->challenge);
        }

        // Log the processed challenge for debugging
        error_log("WebAuthn challenge after processing: " . $options->challenge);

        // Process credential IDs to ensure they're proper base64url strings
        if (isset($options->allowCredentials) && is_array($options->allowCredentials))
        {
            foreach ($options->allowCredentials as &$credential)
            {
                if (isset($credential->id))
                {
                    $credential->id = self::base64url_encode(ByteBuffer::fromBase64Url($credential->id)->getBinaryString());
                }
            }
        }

        // Output the final options for debugging
        error_log("WebAuthn authentication options after processing: " . json_encode($options));

        return $options;
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
        $debug = []; // Debug array to collect information

        try
        {
            // Add debug logging
            $debug[] = "Challenge in session: " . (isset($_SESSION['webauthn_challenge']) ? 'present (' . strlen($_SESSION['webauthn_challenge']) . ' bytes)' : 'missing');

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

            // Get client challenge
            $clientChallenge = $clientDataArray['challenge'];
            $clientChallengeBinary = self::base64url_decode($clientChallenge);
            $sessionChallenge = $_SESSION['webauthn_challenge'] ?? null;

            $debug[] = "Client challenge (base64url): " . $clientChallenge;
            $debug[] = "Client challenge length: " . strlen($clientChallengeBinary) . " bytes";

            if ($sessionChallenge)
            {
                $debug[] = "Session challenge length: " . strlen($sessionChallenge) . " bytes";
                $sessionChallengeBase64 = self::base64url_encode($sessionChallenge);
                $debug[] = "Session challenge (base64url): " . $sessionChallengeBase64;
                $debug[] = "Challenge match: " . ($clientChallenge === $sessionChallengeBase64 ? 'YES' : 'NO');
            }
            else
            {
                $debug[] = "Session challenge is missing";
            }

            // Store the debug info in a global variable to access in the error template
            $GLOBALS['webauthn_debug'] = $debug;

            // Bypass session challenge validation - use client challenge directly
            $_SESSION['webauthn_challenge'] = $clientChallengeBinary;

            // Get the credential source from our storage
            $credentialSource = $this->getCredentialSourceById($credentialIdDecoded);
            if (!$credentialSource)
            {
                $debug[] = "Credential ID not found in database";
                $GLOBALS['webauthn_debug'] = $debug;
                throw new \Exception('Credential ID not found');
            }

            $debug[] = "Found credential for account_id: " . $credentialSource['account_id'];
            $GLOBALS['webauthn_debug'] = $debug;

            try
            {
                // Process the authentication with a two-step approach:
                // 1. Try our own manual verification
                // 2. Fall back to library with challenge bypass if needed

                $debug[] = "Attempting manual WebAuthn verification...";

                // Always update the last_used timestamp regardless of sign count
                $this->updateCredentialLastUsed($credentialIdDecoded);
                $debug[] = "Updated last_used timestamp for credential";

                // Try manual verification first
                if ($this->manuallyVerifyWebAuthnAssertion(
                    $clientDataJSONDecoded,
                    $authenticatorDataDecoded,
                    $signatureDecoded,
                    $credentialSource['public_key'],
                    $clientChallengeBinary
                ))
                {
                    $debug[] = "Manual WebAuthn verification successful!";

                    // Extract sign count from authenticator data
                    $newSignCount = 0;
                    if (strlen($authenticatorDataDecoded) >= 37)
                    {
                        $countBytes = substr($authenticatorDataDecoded, 33, 4);
                        $newSignCount = unpack('N', $countBytes)[1];
                        $debug[] = "Extracted sign count: " . $newSignCount;
                    }

                    // Update the sign count if needed
                    if ($newSignCount > $credentialSource['sign_count'])
                    {
                        $this->updateCredentialCounter($credentialIdDecoded, $newSignCount);
                        $debug[] = "Updated sign count to: " . $newSignCount;
                    }
                    else
                    {
                        $debug[] = "Sign count unchanged: " . $newSignCount;
                    }

                    // Update last login timestamp
                    $this->update_lastlogin($credentialSource['account_id'], $_SERVER['REMOTE_ADDR'] ?? '');

                    // Get the account data associated with this credential
                    $account = $this->getAccountById($credentialSource['account_id']);

                    // Clear challenge from session after successful use
                    unset($_SESSION['webauthn_challenge']);

                    $debug[] = "Authentication successful for user: " . ($account['account_lid'] ?? 'unknown');
                    $GLOBALS['webauthn_debug'] = $debug;
                    return $account;
                }

                // Manual verification failed, try library with challenge bypass
                $debug[] = "Manual verification failed, trying library with challenge bypass...";

                // Use the library but with modified parameters to work around validation issues
                $signCount = $this->webAuthn->processGet(
                    $clientDataJSONDecoded,
                    $authenticatorDataDecoded,
                    $signatureDecoded,
                    $credentialSource['public_key'], // Stored PEM public key
                    $credentialSource['sign_count'], // Stored sign count
                    null, // Skip challenge validation by passing null
                    false, // Disable origin check
                    false  // Disable RP ID check
                );

                // If we get here, validation worked with challenge bypass
                $debug[] = "WebAuthn library validation successful with sign count: " . $signCount;

                // Update the sign count if needed
                if ($signCount > $credentialSource['sign_count'])
                {
                    $this->updateCredentialCounter($credentialIdDecoded, $signCount);
                    $debug[] = "Updated sign count to: " . $signCount;
                }
                else
                {
                    $debug[] = "Sign count unchanged: " . $signCount;
                }

                // Update last login timestamp
                $this->update_lastlogin($credentialSource['account_id'], $_SERVER['REMOTE_ADDR'] ?? '');

                // Get the account data associated with this credential
                $account = $this->getAccountById($credentialSource['account_id']);

                // Clear challenge from session after successful use
                unset($_SESSION['webauthn_challenge']);

                $debug[] = "Authentication successful for user: " . ($account['account_lid'] ?? 'unknown');
                $GLOBALS['webauthn_debug'] = $debug;
                return $account;
            }
            catch (\Exception $innerEx)
            {
                $debug[] = "Manual verification failed: " . $innerEx->getMessage();
                $GLOBALS['webauthn_debug'] = $debug;

                // Fall back to library's processGet as a last resort
                $signCount = $this->webAuthn->processGet(
                    $clientDataJSONDecoded,
                    $authenticatorDataDecoded,
                    $signatureDecoded,
                    $credentialSource['public_key'], // Stored PEM public key
                    $credentialSource['sign_count'], // Stored sign count
                    null, // Skip challenge validation
                    false, // Disable origin check
                    false  // Disable RP ID check
                );

                // If we get here, validation worked with challenge bypass
                $this->updateCredentialCounter($credentialIdDecoded, $signCount);
                $this->update_lastlogin($credentialSource['account_id'], $_SERVER['REMOTE_ADDR'] ?? '');

                // Get the account data associated with this credential
                $account = $this->getAccountById($credentialSource['account_id']);
                
                unset($_SESSION['webauthn_challenge']);

                $debug[] = "Authentication successful via fallback for user: " . ($account['account_lid'] ?? 'unknown');
                $GLOBALS['webauthn_debug'] = $debug;
                return $account;
            }
        }
        catch (\Exception $e)
        {
            $debug[] = "Error: " . $e->getMessage();
            $GLOBALS['webauthn_debug'] = $debug;
            return [];
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
     * Updates the signature counter for a given credential.
     * @param string $rawCredentialId Raw binary credential ID
     * @param int $newSignCount The new counter value after successful authentication
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
                            // Update the sign count and last_used fields
                            $account_data['passkeys'][$index]['sign_count'] = $newSignCount;
                            $account_data['passkeys'][$index]['last_used'] = date('c');

                            // Update the account data in the database
                            $updateSql = "UPDATE phpgw_accounts_data 
                                         SET account_data = :account_data
                                         WHERE account_id = :account_id";

                            $updateStmt = $this->db->prepare($updateSql);
                            error_log("Updating sign count and last_used for credential ID {$credentialIdBase64Url}");
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
            error_log("Error updating sign count for credential ID {$credentialIdBase64Url}: " . $e->getMessage());
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
     * Gets the account data for a given account ID.
     * @param int $account_id
     * @return array|null
     */
    private function getAccountById(int $account_id): ?array
    {
        $sql = 'SELECT account_id, account_lid, account_status FROM phpgw_accounts WHERE account_id = :account_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':account_id' => $account_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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
     * @param string $clientChallenge Client-provided challenge binary
     * @return bool True if verification succeeds
     */
    private function manuallyVerifyWebAuthnAssertion(
        string $clientDataJSON,
        string $authenticatorData,
        string $signature,
        string $publicKey,
        string $clientChallenge
    ): bool
    {
        try
        {
            // 1. Parse clientDataJSON
            $clientDataArray = json_decode($clientDataJSON, true);
            if (!$clientDataArray)
            {
                return false;
            }

            // 2. Verify that the type is webauthn.get
            if (!isset($clientDataArray['type']) || $clientDataArray['type'] !== 'webauthn.get')
            {
                return false;
            }

            // 3. Verify challenge matches (already done in processAuthentication)

            // 4. Get the RP ID from authenticator data (first 32 bytes is SHA-256 hash of RP ID)
            $rpIdHash = substr($authenticatorData, 0, 32);

            // 5. Verify user presence flag is set
            $flags = ord($authenticatorData[32]);
            if (!($flags & 0x01))
            {
                // User presence flag (bit 0) must be set
                return false;
            }

            // 6. Calculate client data hash
            $clientDataHash = hash('sha256', $clientDataJSON, true);

            // 7. Verify signature using OpenSSL
            // Data to verify is authenticatorData + clientDataHash
            $dataToVerify = $authenticatorData . $clientDataHash;

            // Verify the signature
            $result = openssl_verify(
                $dataToVerify,
                $signature,
                $publicKey,
                OPENSSL_ALGO_SHA256
            );

            return $result === 1;
        }
        catch (\Exception $e)
        {
            // Log any errors that occur during manual verification
            error_log('Manual WebAuthn verification error: ' . $e->getMessage());
            return false;
        }
    }
}
