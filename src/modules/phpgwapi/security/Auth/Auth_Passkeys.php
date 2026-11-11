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
    private string $origin;
    private $serverSettings;
    private const MAX_PENDING_CHALLENGES = 10;

    public function __construct()
    {
        $this->db = \App\Database\Db::getInstance();
        $this->serverSettings = Settings::getInstance()->get('server');

        $relyingParty = self::getRelyingPartyConfiguration();
        if ($relyingParty === null)
        {
            throw new \RuntimeException('Passkeys require an absolute HTTPS webserver_url, or a public domain in hostname when webserver_url is relative.');
        }

        $this->rpId = $relyingParty['rp_id'];
        $this->origin = $relyingParty['origin'];

        try
        {
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
     * Derive the WebAuthn RP ID and origin from admin configuration
     * (never from request Host or proxy headers).
     *
     * Uses webserver_url when it is absolute. When it is a relative path (typical behind
     * a reverse proxy, e.g. "/"), the configured hostname is used instead, with HTTPS
     * unless the host is localhost and enforce_ssl is off.
     *
     * WebAuthn requires a domain-name RP ID: IP addresses are rejected by browsers,
     * and plain HTTP is only a secure context for localhost.
     *
     * @return array{rp_id: string, origin: string}|null Null when passkeys cannot work with the configuration.
     */
    public static function getRelyingPartyConfiguration(): ?array
    {
        $serverSettings = Settings::getInstance()->get('server');
        $urlParts = parse_url(trim((string) ($serverSettings['webserver_url'] ?? '')));
        if (!is_array($urlParts))
        {
            return null;
        }

        if (empty($urlParts['host']))
        {
            $configuredHost = trim((string) ($serverSettings['hostname'] ?? ''));
            $urlParts = $configuredHost !== '' && !preg_match('#[/?\#@\s]#', $configuredHost)
                ? parse_url('//' . $configuredHost)
                : false;
            if (!is_array($urlParts) || empty($urlParts['host']))
            {
                return null;
            }
            $fallbackHost = strtolower(rtrim((string) $urlParts['host'], '.'));
            $fallbackIsLocalhost = $fallbackHost === 'localhost' || str_ends_with($fallbackHost, '.localhost');
            $urlParts['scheme'] = ($fallbackIsLocalhost && empty($serverSettings['enforce_ssl'])) ? 'http' : 'https';
        }

        $scheme = strtolower((string) ($urlParts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($urlParts['host'] ?? ''), '.'));
        $isLocalhost = $host === 'localhost' || str_ends_with($host, '.localhost');
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || !($scheme === 'https' || ($scheme === 'http' && $isLocalhost)))
        {
            return null;
        }

        $port = $urlParts['port'] ?? null;
        if (($port === 443 && $scheme === 'https') || ($port === 80 && $scheme === 'http'))
        {
            $port = null;
        }

        return [
            'rp_id' => $host,
            'origin' => $scheme . '://' . $host . ($port ? ':' . $port : ''),
        ];
    }

    public static function isRelyingPartyConfigured(): bool
    {
        return self::getRelyingPartyConfiguration() !== null;
    }

    /**
     * Auth types where the local PorticoEstate account is authoritative for access.
     * The SSO types (azure/remoteuser/customsso, incl. OpenID Connect backends) already
     * fall back to local SQL passwords, so a passkey is never weaker than that path.
     * Directory-backed types (ldap, ads, ntlm, http, sqlssl, mail, exchange, nis) are
     * excluded: a passkey would bypass account disabling in the external directory.
     */
    private const PASSKEY_AUTH_TYPES = ['sql', 'azure', 'remoteuser', 'customsso'];

    public static function isLocalAuthenticationAllowed(): bool
    {
        $serverSettings = Settings::getInstance()->get('server');
        return in_array(strtolower((string) ($serverSettings['auth_type'] ?? '')), self::PASSKEY_AUTH_TYPES, true);
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

        $rawUserId = $this->getUserHandleForAccount($account_id);
        $userIdBase64Url = self::base64url_encode($rawUserId);

        // Generate registration options with the library's positional arguments.
        $options = $this->webAuthn->getCreateArgs(
            $rawUserId,
            $username,
            $displayName,
            60 * 3, // Timeout
            true, // Require a discoverable credential
            'required', // Require user verification
            null, // Allow platform and roaming authenticators
            $existingCredentialIds
        );
        $libraryOptions = $options->publicKey;

        // Generate and store the challenge (raw binary in session, base64url for client)
        $rawChallenge = $this->webAuthn->getChallenge()->getBinaryString();
        $challengeBase64Url = $this->storeChallenge('webauthn_registration_challenges', $rawChallenge);

        $finalOptions = new \stdClass();
        $finalOptions->publicKey = new \stdClass();

        if (isset($libraryOptions->rp) && is_object($libraryOptions->rp)) {
            $finalOptions->publicKey->rp = $libraryOptions->rp;
        } else {
            $finalOptions->publicKey->rp = (object)[
                'id' => $this->rpId,
                'name' => $this->appName
            ];
        }

        $finalOptions->publicKey->user = new \stdClass();
        $finalOptions->publicKey->user->id = $userIdBase64Url;
        $finalOptions->publicKey->user->name = $username;
        $finalOptions->publicKey->user->displayName = $displayName;

        $finalOptions->publicKey->challenge = $challengeBase64Url;

        if (isset($libraryOptions->pubKeyCredParams) && is_array($libraryOptions->pubKeyCredParams)) {
            $finalOptions->publicKey->pubKeyCredParams = $libraryOptions->pubKeyCredParams;
        } else {
            $finalOptions->publicKey->pubKeyCredParams = [
                (object)['type' => 'public-key', 'alg' => -7],  // ES256
                (object)['type' => 'public-key', 'alg' => -257] // RS256
            ];
        }

        if (isset($libraryOptions->timeout) && is_numeric($libraryOptions->timeout)) {
            $finalOptions->publicKey->timeout = $libraryOptions->timeout;
        } else {
            $finalOptions->publicKey->timeout = 180000; // Default to 180 seconds (3 minutes)
        }

        $finalOptions->publicKey->excludeCredentials = [];
        foreach ($existingCredentialIds as $rawId) {
            $finalOptions->publicKey->excludeCredentials[] = (object)[
                'type' => 'public-key',
                'id' => self::base64url_encode($rawId),
                'transports' => ['usb', 'nfc', 'ble', 'hybrid', 'internal']
            ];
        }

        $finalOptions->publicKey->authenticatorSelection = new \stdClass();
        $finalOptions->publicKey->authenticatorSelection->userVerification = 'required';
        $finalOptions->publicKey->authenticatorSelection->residentKey = 'required';
        $finalOptions->publicKey->authenticatorSelection->requireResidentKey = true;

        $finalOptions->publicKey->attestation = 'none';

        if (isset($libraryOptions->extensions)) {
            $finalOptions->publicKey->extensions = $libraryOptions->extensions;
        }

        return $finalOptions;
    }

    /**
     * Generate server-issued authentication options and save their challenge.
     */
    public function getAuthenticationArgs(): \stdClass
    {
        ByteBuffer::$useBase64UrlEncoding = true;
        $options = $this->webAuthn->getGetArgs([], 60, true, true, true, true, true, true);
        $this->storeChallenge('webauthn_login_challenges', $this->webAuthn->getChallenge()->getBinaryString());

        return $options->publicKey;
    }

    /**
     * Consume the server-issued challenge identified by the client response.
     */
    public function consumeAuthenticationChallenge(string $challengeBase64Url): ?string
    {
        return $this->consumeChallenge('webauthn_login_challenges', $challengeBase64Url);
    }

    /**
     * Consume the server-issued registration challenge identified by the client response.
     */
    public function consumeRegistrationChallenge(string $challengeBase64Url): ?string
    {
        return $this->consumeChallenge('webauthn_registration_challenges', $challengeBase64Url);
    }

    private function storeChallenge(string $sessionKey, string $rawChallenge): string
    {
        $challengeBase64Url = self::base64url_encode($rawChallenge);
        $challenges = $_SESSION[$sessionKey] ?? [];
        if (!is_array($challenges))
        {
            $challenges = [];
        }

        $now = time();
        foreach ($challenges as $challenge => $data)
        {
            if (!is_array($data) || (int) ($data['expires'] ?? 0) <= $now)
            {
                unset($challenges[$challenge]);
            }
        }
        while (count($challenges) >= self::MAX_PENDING_CHALLENGES)
        {
            unset($challenges[array_key_first($challenges)]);
        }

        $challenges[$challengeBase64Url] = [
            'value' => $rawChallenge,
            'expires' => $now + 300
        ];
        $_SESSION[$sessionKey] = $challenges;
        return $challengeBase64Url;
    }

    private function consumeChallenge(string $sessionKey, string $challengeBase64Url): ?string
    {
        try
        {
            self::base64url_decode($challengeBase64Url);
        }
        catch (\InvalidArgumentException $e)
        {
            return null;
        }

        $challenges = $_SESSION[$sessionKey] ?? [];
        if (!is_array($challenges) || !isset($challenges[$challengeBase64Url]))
        {
            return null;
        }

        $challenge = $challenges[$challengeBase64Url];
        unset($challenges[$challengeBase64Url]);
        $_SESSION[$sessionKey] = $challenges;

        if (!is_array($challenge) || !isset($challenge['value'], $challenge['expires'])
            || !is_string($challenge['value']) || $challenge['value'] === ''
            || (int) $challenge['expires'] <= time())
        {
            return null;
        }

        return $challenge['value'];
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
        if ($base64url === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/', $base64url))
        {
            throw new \InvalidArgumentException('Invalid base64url value');
        }

        $base64 = strtr($base64url, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0)
        {
            $base64 .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($base64, true);
        if ($decoded === false)
        {
            throw new \InvalidArgumentException('Invalid base64url value');
        }

        return $decoded;
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
     * @return bool True if registration was successful
     */
    public function processRegistration(string $clientDataJSON, string $attestationObject, string $credentialId, int $account_id, string $challenge, string $deviceName = ''): bool
    {
        try
        {
            $clientDataJSONDecoded = self::base64url_decode($clientDataJSON);
            $attestationObjectDecoded = self::base64url_decode($attestationObject);
            $credentialIdDecoded = self::base64url_decode($credentialId);

            if (!$clientDataJSONDecoded || !$attestationObjectDecoded || !$credentialIdDecoded)
            {
                throw new \Exception('Failed to decode WebAuthn response data');
            }

            $this->validateClientOrigin($clientDataJSONDecoded);

            $credentialData = $this->webAuthn->processCreate(
                $clientDataJSONDecoded,
                $attestationObjectDecoded,
                $challenge,
                true, // Require user verification
                true, // Require user presence
                false // Do not require a trusted attestation root certificate
            );

            if (!hash_equals($credentialData->credentialId, $credentialIdDecoded))
            {
                throw new \Exception('Credential ID does not match the attestation data.');
            }

            $initialSignCount = (int) ($credentialData->signatureCounter ?? 0);

            // Store the credential data
            return $this->storeCredentialSource(
                $account_id,
                $credentialData->credentialId,
                $credentialData->credentialPublicKey,
                $initialSignCount,
                $deviceName
            );
        }
        catch (\Exception $e)
        {
            error_log('WebAuthn Registration Processing Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
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
    public function processAuthentication(string $clientDataJSON, string $authenticatorData, string $signature, string $credentialId, ?string $userHandle, string $challenge): array
    {
        try
        {
            $clientDataJSONDecoded = self::base64url_decode($clientDataJSON);
            $authenticatorDataDecoded = self::base64url_decode($authenticatorData);
            $signatureDecoded = self::base64url_decode($signature);
            $credentialIdDecoded = self::base64url_decode($credentialId);

            $this->validateClientOrigin($clientDataJSONDecoded);
            $clientDataArray = json_decode($clientDataJSONDecoded, true);
            if (!$clientDataArray || !isset($clientDataArray['challenge']) || !is_string($clientDataArray['challenge']))
            {
                throw new \Exception('Invalid client data: challenge not found');
            }

            $clientChallenge = $clientDataArray['challenge'];
            $sessionChallengeBase64 = self::base64url_encode($challenge);
            if (!hash_equals($sessionChallengeBase64, $clientChallenge))
            {
                throw new \Exception('Challenge verification failed');
            }

            $credentialSource = $this->getCredentialSourceById($credentialIdDecoded);
            if (!$credentialSource)
            {
                throw new \Exception('Credential ID not found');
            }

            if ($userHandle === null)
            {
                throw new \Exception('Discoverable credential response did not include a user handle.');
            }
            $userHandleRaw = self::base64url_decode($userHandle);
            $userHandleBase64Url = self::base64url_encode($userHandleRaw);
            if (isset($credentialSource['user_handle']))
            {
                $storedUserHandle = self::base64url_decode($credentialSource['user_handle']);
                if (!hash_equals($storedUserHandle, $userHandleRaw))
                {
                    throw new \Exception('Credential user handle does not match the stored account binding.');
                }
            }

            $this->webAuthn->processGet(
                $clientDataJSONDecoded,
                $authenticatorDataDecoded,
                $signatureDecoded,
                $credentialSource['public_key'],
                $challenge,
                $credentialSource['sign_count'],
                true
            );

            $signCount = $this->webAuthn->getSignatureCounter();
            $storedSignCount = $signCount === null
                ? $credentialSource['sign_count']
                : max($credentialSource['sign_count'], $signCount);
            if (!$this->updateCredentialCounter($credentialIdDecoded, $storedSignCount, $userHandleBase64Url))
            {
                throw new \RuntimeException('Unable to update passkey usage data.');
            }

            $this->update_lastlogin($credentialSource['account_id'], $_SERVER['REMOTE_ADDR'] ?? '');
            return $this->getAccountById($credentialSource['account_id']);
        }
        catch (\Exception $e)
        {
            // Log minimal error information
            error_log('Authentication error: ' . $e->getMessage());
            return [];
        }
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

    private function validateClientOrigin(string $clientDataJSON): void
    {
        $clientData = json_decode($clientDataJSON);
        if (!is_object($clientData) || !isset($clientData->origin) || !is_string($clientData->origin)
            || !hash_equals($this->origin, $clientData->origin) || !empty($clientData->crossOrigin))
        {
            throw new \Exception('Invalid WebAuthn origin');
        }
    }

    /**
     * Updates the counter for a credential
     * @param string $rawCredentialId Raw binary credential ID
     * @param int $newSignCount New sign count value
     * @return bool Success status
     */
    private function updateCredentialCounter(string $rawCredentialId, int $newSignCount, string $userHandle): bool
    {
        $buffer = new ByteBuffer($rawCredentialId);
        ByteBuffer::$useBase64UrlEncoding = true;
        $credentialIdBase64Url = $buffer->jsonSerialize();

        try
        {
            $this->db->transaction_begin();
            $sql = "SELECT account_id, account_data
                   FROM phpgw_accounts_data 
                   WHERE account_data @> :search_json::jsonb
                   FOR UPDATE";

            $stmt = $this->db->prepare($sql);
            $searchJson = json_encode(['passkeys' => [['credential_id' => $credentialIdBase64Url]]]);
            $stmt->execute([':search_json' => $searchJson]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row)
            {
                $accountId = (int) $row['account_id'];
                $accountData = json_decode($row['account_data'], true);

                if (isset($accountData['passkeys']) && is_array($accountData['passkeys']))
                {
                    foreach ($accountData['passkeys'] as $index => $passkey)
                    {
                        if (isset($passkey['credential_id']) && $passkey['credential_id'] === $credentialIdBase64Url)
                        {
                            if (isset($passkey['user_handle']))
                            {
                                $storedUserHandle = self::base64url_decode((string) $passkey['user_handle']);
                                $providedUserHandle = self::base64url_decode($userHandle);
                                if (!hash_equals($storedUserHandle, $providedUserHandle))
                                {
                                    $this->db->transaction_abort();
                                    return false;
                                }
                            }
                            else
                            {
                                $accountData['passkeys'][$index]['user_handle'] = $userHandle;
                            }
                            $accountData['passkeys'][$index]['sign_count'] = $newSignCount;
                            $accountData['passkeys'][$index]['last_used'] = date('c');
                            $updateSql = "UPDATE phpgw_accounts_data 
                                         SET account_data = :account_data
                                         WHERE account_id = :account_id";
                            $updateStmt = $this->db->prepare($updateSql);
                            $updated = $updateStmt->execute([
                                ':account_data' => json_encode($accountData),
                                ':account_id' => $accountId
                            ]);
                            if (!$updated)
                            {
                                $this->db->transaction_abort();
                                return false;
                            }
                            return $this->db->transaction_commit();
                        }
                    }
                }
            }

            $this->db->transaction_abort();
            return false;
        }
        catch (\Exception $e)
        {
            $this->db->transaction_abort();
            error_log('Error updating credential counter: ' . $e->getMessage());
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
        $credentialIdBase64Url = trim($credentialIdBase64Url);
        if ($credentialIdBase64Url === '')
        {
            return false;
        }

        try
        {
            $this->db->transaction_begin();
            $sql = 'SELECT account_data FROM phpgw_accounts_data WHERE account_id = :account_id FOR UPDATE';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':account_id' => $account_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $accountData = $row ? json_decode($row['account_data'], true) : null;
            if (!is_array($accountData) || !isset($accountData['passkeys']) || !is_array($accountData['passkeys']))
            {
                $this->db->transaction_abort();
                return false;
            }

            $initialCount = count($accountData['passkeys']);
            $accountData['passkeys'] = array_values(array_filter(
                $accountData['passkeys'],
                fn($item) => !is_array($item) || ($item['credential_id'] ?? null) !== $credentialIdBase64Url
            ));
            if (count($accountData['passkeys']) === $initialCount)
            {
                $this->db->transaction_abort();
                return false;
            }

            $sql = 'UPDATE phpgw_accounts_data SET account_data = :account_data WHERE account_id = :account_id';
            $stmt = $this->db->prepare($sql);
            $updated = $stmt->execute([
                ':account_data' => json_encode($accountData),
                ':account_id' => $account_id
            ]);
            if (!$updated)
            {
                $this->db->transaction_abort();
                return false;
            }
            return $this->db->transaction_commit();
        }
        catch (\Throwable $e)
        {
            $this->db->transaction_abort();
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
        try
        {
            $buffer = new ByteBuffer($credentialId);
            ByteBuffer::$useBase64UrlEncoding = true;
            $credentialIdBase64Url = $buffer->jsonSerialize();

            $this->db->transaction_begin();

            // Serialize registrations of the same credential ID across accounts and reject IDs
            // already registered to any user (WebAuthn §7.1 step 22).
            $stmt = $this->db->prepare('SELECT pg_advisory_xact_lock(hashtext(CAST(:credential_id AS text)))');
            $stmt->execute([':credential_id' => $credentialIdBase64Url]);
            $stmt = $this->db->prepare('SELECT account_id FROM phpgw_accounts_data WHERE account_data @> CAST(:search_json AS jsonb) LIMIT 1');
            $stmt->execute([':search_json' => json_encode(['passkeys' => [['credential_id' => $credentialIdBase64Url]]])]);
            $existingOwner = $stmt->fetchColumn();
            if ($existingOwner !== false)
            {
                $this->db->transaction_abort();
                error_log("Rejected registration of credential ID {$credentialIdBase64Url} for account {$account_id}: already registered to account " . (int) $existingOwner);
                return false;
            }

            $sql = 'SELECT account_data FROM phpgw_accounts_data WHERE account_id = :account_id FOR UPDATE';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':account_id' => $account_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $accountData = $row ? json_decode($row['account_data'], true) : [];
            if (!is_array($accountData))
            {
                $this->db->transaction_abort();
                throw new \RuntimeException('Stored account data is invalid.');
            }
            if (!isset($accountData['passkeys']) || !is_array($accountData['passkeys']))
            {
                $accountData['passkeys'] = [];
            }

            foreach ($accountData['passkeys'] as $passkey)
            {
                if (is_array($passkey) && ($passkey['credential_id'] ?? null) === $credentialIdBase64Url)
                {
                    $this->db->transaction_abort();
                    error_log("Attempted to register existing credential ID {$credentialIdBase64Url} for account {$account_id}");
                    return false;
                }
            }

            $accountData['passkeys'][] = [
                'credential_id' => $credentialIdBase64Url,
                'user_handle' => self::base64url_encode($this->getUserHandleForAccount($account_id)),
                'public_key' => $publicKey,
                'sign_count' => $signCount,
                'device_name' => $deviceName,
                'added' => date('c')
            ];

            $sql = $row
                ? 'UPDATE phpgw_accounts_data SET account_data = :account_data WHERE account_id = :account_id'
                : 'INSERT INTO phpgw_accounts_data (account_id, account_data) VALUES (:account_id, :account_data)';
            $stmt = $this->db->prepare($sql);
            $saved = $stmt->execute([
                ':account_data' => json_encode($accountData),
                ':account_id' => $account_id
            ]);
            if (!$saved)
            {
                $this->db->transaction_abort();
                return false;
            }
            return $this->db->transaction_commit();
        }
        catch (\Throwable $e)
        {
            $this->db->transaction_abort();
            error_log('Error storing passkey credential: ' . $e->getMessage());
            return false;
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
     * Used to exclude already registered credentials from new registrations.
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

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1)
        {
            error_log("Passkey credential ID {$credentialIdBase64Url} is registered to multiple accounts; refusing to authenticate.");
            return null;
        }
        $row = $rows[0] ?? null;
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
                            'sign_count' => (int)$key['sign_count'],
                            'user_handle' => isset($key['user_handle']) ? (string) $key['user_handle'] : null
                        ];
                    }
                }
            }
        }
        return null;
    }

    private function getUserHandleForAccount(int $account_id): string
    {
        $key = (string) ($this->serverSettings['encryptkey'] ?? '');
        if ($key === '')
        {
            throw new \RuntimeException('A configured encryption key is required to create passkey user handles.');
        }

        return hash_hmac('sha256', $this->rpId . "\0" . $account_id, $key, true);
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
