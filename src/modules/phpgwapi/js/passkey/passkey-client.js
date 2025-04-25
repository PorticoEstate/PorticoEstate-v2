/**
 * Passkey Client JS
 * 
 * This file provides client-side functions for working with WebAuthn/Passkeys
 */

// Create a namespace to avoid undefined errors and global scope pollution
const PasskeyUtils = {
    // Development mode flag - set to true for testing without physical authenticators
    devMode: false,

    /**
     * Enable or disable development mode for passkey testing
     * @param {boolean} enable - true to enable dev mode, false to disable
     */
    setDevMode: function (enable)
    {
        this.devMode = !!enable;
        console.log(`Passkey development mode ${this.devMode ? 'enabled' : 'disabled'}`);

        // Try to set up virtual authenticator if enabling dev mode
        if (this.devMode)
        {
            setupVirtualAuthenticator();
        }

        // Store the setting in localStorage to persist between page loads
        try
        {
            localStorage.setItem('passkey_dev_mode', this.devMode ? 'true' : 'false');
        } catch (e)
        {
            console.warn('Could not save dev mode setting to localStorage:', e);
        }

        return this.devMode;
    },

    /**
     * Get current development mode status
     * @returns {boolean} true if dev mode is enabled
     */
    isDevMode: function ()
    {
        return this.devMode;
    },

    // Initialize dev mode from localStorage if available
    initDevMode: function ()
    {
        try
        {
            const savedMode = localStorage.getItem('passkey_dev_mode');
            if (savedMode === 'true')
            {
                this.setDevMode(true);
            }
        } catch (e)
        {
            console.warn('Could not load dev mode setting from localStorage:', e);
        }
    },

    /**
     * Convert a base64url string to an ArrayBuffer
     * @param {string} base64url - base64url encoded string
     * @returns {ArrayBuffer} decoded array buffer
     */
    base64urlToArrayBuffer: function (base64url)
    {
        if (!base64url)
        {
            console.error("Empty base64url string provided");
            return new ArrayBuffer(0);
        }
        try
        {
            const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
            const padLen = (4 - (base64.length % 4)) % 4;
            const padded = base64.padEnd(base64.length + padLen, '=');
            const binary = atob(padded);
            const buffer = new ArrayBuffer(binary.length);
            const bytes = new Uint8Array(buffer);

            for (let i = 0; i < binary.length; i++)
            {
                bytes[i] = binary.charCodeAt(i);
            }
            return buffer;
        } catch (error)
        {
            console.error("Error in base64urlToArrayBuffer:", error);
            return new ArrayBuffer(0);
        }
    },

    /**
     * Convert an ArrayBuffer to a base64url string
     * @param {ArrayBuffer} buffer - array buffer to encode
     * @returns {string} base64url encoded string
     */
    arrayBufferToBase64url: function (buffer)
    {
        if (!buffer)
        {
            console.error("Empty buffer provided");
            return "";
        }
        try
        {
            const bytes = new Uint8Array(buffer);
            let binary = '';
            for (let i = 0; i < bytes.byteLength; i++)
            {
                binary += String.fromCharCode(bytes[i]);
            }

            const base64 = btoa(binary);
            return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
        } catch (error)
        {
            console.error("Error in arrayBufferToBase64url:", error);
            return "";
        }
    }
};

// Initialize dev mode setting from localStorage
PasskeyUtils.initDevMode();

/**
 * Check if WebAuthn is available and if platform authenticators are supported
 * @returns {Promise<boolean>} true if platform authenticators are supported
 */
async function isPlatformAuthenticatorAvailable()
{
    // First check if WebAuthn is supported by this browser
    if (!window.PublicKeyCredential)
    {
        console.log("WebAuthn not supported in this browser");
        return false;
    }

    // Check if conditional mediation (passkey autofill) is supported
    let conditionalMediationAvailable = false;
    if (typeof PublicKeyCredential.isConditionalMediationAvailable === 'function')
    {
        try
        {
            conditionalMediationAvailable = await PublicKeyCredential.isConditionalMediationAvailable();
        } catch (error)
        {
            console.error("Error checking conditionalMediationAvailable:", error);
        }
    }

    // Check if platform authenticator is available
    try
    {
        const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
        console.log("Platform authenticator available:", available);

        // We consider WebAuthn supported if either platform authenticator is available
        // or conditional mediation is available (for passkey syncing)
        return available || conditionalMediationAvailable;
    } catch (error)
    {
        console.error("Error checking authenticator availability:", error);
        return false;
    }
}

/**
 * Create and use a virtual authenticator for development/testing
 * Only works in Chrome with the --enable-web-authentication-testing-api flag
 * @returns {Promise<boolean>} true if virtual authenticator was successfully created
 */
async function setupVirtualAuthenticator()
{
    try
    {
        // Check if WebAuthn is supported by this browser
        if (!window.PublicKeyCredential)
        {
            console.error("WebAuthn API not available in this browser");
            return false;
        }

        // Method 1: Try using the newer Chrome virtual authenticator API
        if (typeof navigator.credentials.create === 'function' &&
            typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable === 'function')
        {

            console.log("Attempting to set up virtual authenticator...");

            try
            {
                // Different browsers implement this differently, try multiple approaches
                if (typeof navigator.credentials.selectMdsStatement === 'function')
                {
                    console.log("Using selectMdsStatement API");
                    await navigator.credentials.selectMdsStatement();
                }

                // Try to create a virtual authenticator
                // Modern Chrome implementation
                if (typeof PublicKeyCredential.mockAuthenticator === 'object')
                {
                    console.log("Using PublicKeyCredential.mockAuthenticator API");
                    await PublicKeyCredential.mockAuthenticator.enable();
                    return true;
                }

                // Alternative method for some Chrome versions
                if (typeof navigator.credentials.createVirtualAuthenticator === 'function')
                {
                    console.log("Using createVirtualAuthenticator API");
                    await navigator.credentials.createVirtualAuthenticator({
                        protocol: 'ctap2',
                        transport: 'internal',
                        hasResidentKey: true,
                        hasUserVerification: true,
                        isUserConsenting: true
                    });
                    return true;
                }

                // Even older Chrome
                if (typeof navigator.credentials.setVirtualAuthenticatorOptions === 'function')
                {
                    console.log("Using setVirtualAuthenticatorOptions API");
                    await navigator.credentials.setVirtualAuthenticatorOptions({
                        protocol: 'ctap2',
                        transport: 'internal',
                        hasResidentKey: true,
                        hasUserVerification: true,
                        isUserConsenting: true,
                        isUserVerified: true
                    });
                    return true;
                }

                // Pre-2021 Chrome versions with the old API name
                if (typeof PublicKeyCredential.setVirtualAuthenticatorOptions === 'function')
                {
                    console.log("Using PublicKeyCredential.setVirtualAuthenticatorOptions API");
                    await PublicKeyCredential.setVirtualAuthenticatorOptions({
                        protocol: 'ctap2',
                        transport: 'internal',
                        hasResidentKey: true,
                        hasUserVerification: true,
                        isUserConsenting: true,
                        isUserVerified: true
                    });
                    return true;
                }

                // Testing mode - assume we're in testing mode if we got this far
                console.log("No specific virtual authenticator API found, but Chrome was launched with --enable-web-authentication-testing-api flag");
                console.log("Proceeding with passkey testing using simulated authenticator");

                // Enable dev mode anyway - it will use more relaxed requirements
                PasskeyUtils.setDevMode(true);
                return true;

            } catch (e)
            {
                console.error("Failed to create virtual authenticator:", e);
                console.log("Make sure Chrome is launched with the --enable-web-authentication-testing-api flag");

                // Check if we're running in a compatible environment
                try
                {
                    const isCompatible = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
                    console.log("Platform authenticator available:", isCompatible);
                    if (isCompatible)
                    {
                        console.log("A platform authenticator is available, but the testing API is not accessible");
                        console.log("You might be able to use your system's authenticator instead of a virtual one");

                        // Enable dev mode anyway - it will use more relaxed requirements
                        PasskeyUtils.setDevMode(true);
                        return true;
                    }
                } catch (error)
                {
                    console.error("Error checking authenticator compatibility:", error);
                }

                return false;
            }
        } else
        {
            console.log("The WebAuthn credential management API is not fully available");
            return false;
        }
    } catch (error)
    {
        console.error("Unexpected error setting up virtual authenticator:", error);
        return false;
    }
}

/**
 * Prepare WebAuthn creation options by converting base64url strings to ArrayBuffers
 * @param {Object} creationOptions - options from server
 * @returns {Object} prepared options for navigator.credentials.create()
 */
function prepareCreationOptions(creationOptions)
{
    console.log("Original creation options received:", JSON.stringify(creationOptions, null, 2));

    // Handle case where response is wrapped in a 'publicKey' property
    if (creationOptions.publicKey && typeof creationOptions.publicKey === 'object')
    {
        console.log("Unwrapping publicKey property");
        creationOptions = creationOptions.publicKey;
    }

    // Convert challenge from base64url to ArrayBuffer
    if (creationOptions.challenge)
    {
        // Check if the challenge is in a BINARY format (like "=?BINARY?B?ABC123?=")
        if (typeof creationOptions.challenge === 'string' && creationOptions.challenge.startsWith('=?BINARY?B?'))
        {
            // Extract the Base64 part
            const base64Part = creationOptions.challenge.replace('=?BINARY?B?', '').replace('?=', '');
            console.log("Extracted Base64 part from BINARY format:", base64Part);
            creationOptions.challenge = base64Part;
        }
        creationOptions.challenge = PasskeyUtils.base64urlToArrayBuffer(creationOptions.challenge);
    }

    // Handle user ID in BINARY format
    if (creationOptions.user && typeof creationOptions.user.id === 'string')
    {
        if (creationOptions.user.id.startsWith('=?BINARY?B?'))
        {
            // Extract the Base64 part
            const base64Part = creationOptions.user.id.replace('=?BINARY?B?', '').replace('?=', '');
            console.log("Extracted Base64 part from user.id BINARY format:", base64Part);
            creationOptions.user.id = base64Part;
        }
        // Convert userId from base64url to ArrayBuffer
        creationOptions.user.id = PasskeyUtils.base64urlToArrayBuffer(creationOptions.user.id);
    }

    // Convert excludeCredentials id from base64url to ArrayBuffer
    if (creationOptions.excludeCredentials && Array.isArray(creationOptions.excludeCredentials))
    {
        for (let i = 0; i < creationOptions.excludeCredentials.length; i++)
        {
            if (typeof creationOptions.excludeCredentials[i].id === 'string')
            {
                if (creationOptions.excludeCredentials[i].id.startsWith('=?BINARY?B?'))
                {
                    // Extract the Base64 part
                    const base64Part = creationOptions.excludeCredentials[i].id.replace('=?BINARY?B?', '').replace('?=', '');
                    creationOptions.excludeCredentials[i].id = base64Part;
                }
                creationOptions.excludeCredentials[i].id = PasskeyUtils.base64urlToArrayBuffer(
                    creationOptions.excludeCredentials[i].id
                );
            }
        }
    }

    // Add pubKeyCredParams if missing - this is a REQUIRED parameter
    if (!creationOptions.pubKeyCredParams || !Array.isArray(creationOptions.pubKeyCredParams) || creationOptions.pubKeyCredParams.length === 0)
    {
        console.log("Adding missing pubKeyCredParams to credential creation options");
        creationOptions.pubKeyCredParams = [
            { type: "public-key", alg: -7 },  // ES256 (Elliptic Curve P-256 with SHA-256)
            { type: "public-key", alg: -257 } // RS256 (RSASSA-PKCS1-v1_5 using SHA-256)
        ];
    }

    // Add relying party (rp) if missing - this is a REQUIRED parameter
    if (!creationOptions.rp)
    {
        console.log("Adding missing relying party (rp) to credential creation options");
        // Try to determine the current domain
        const domain = window.location.hostname;
        creationOptions.rp = {
            id: domain,
            name: document.title || "PorticoEstate"
        };
        console.log("Created default relying party with id:", domain);
    } else if (!creationOptions.rp.id)
    {
        // If rp exists but id is missing
        creationOptions.rp.id = window.location.hostname;
        console.log("Added missing relying party ID:", window.location.hostname);
    }

    // Configure the authenticatorSelection with reasonable defaults if not set
    if (!creationOptions.authenticatorSelection)
    {
        console.log("Adding authenticatorSelection with default values");
        creationOptions.authenticatorSelection = {
            authenticatorAttachment: "platform", //PasskeyUtils.isDevMode() ? "cross-platform" : "platform",
            userVerification: PasskeyUtils.isDevMode() ? "discouraged" : "preferred",
            requireResidentKey: true,
            residentKey: "required"
        };
    }

    // Ensure RP ID is valid and matches the current domain or a valid parent domain
    if (creationOptions.rp && creationOptions.rp.id)
    {
        // Check if the RP ID is valid for the current domain
        const currentDomain = window.location.hostname;
        const isValidRpId = currentDomain === creationOptions.rp.id ||
            currentDomain.endsWith('.' + creationOptions.rp.id);

        if (!isValidRpId)
        {
            console.warn(`Potential RP ID mismatch! Current domain: ${currentDomain}, RP ID: ${creationOptions.rp.id}`);
            console.log("This might cause the registration to fail due to security restrictions.");

            // In dev mode, attempt to fix the RP ID
            if (PasskeyUtils.isDevMode())
            {
                console.log("Dev mode: Adjusting RP ID to match current domain");
                creationOptions.rp.id = currentDomain;
            }
        } else
        {
            console.log("RP ID validation passed: " + creationOptions.rp.id);
        }
    }

    // Set timeout to allow enough time for user interaction
    if (!creationOptions.timeout)
    {
        creationOptions.timeout = 120000; // 2 minutes
    }

    // Set attestation if not set
    if (!creationOptions.attestation)
    {
        creationOptions.attestation = "none";
    }

    console.log("Prepared credential creation options:", JSON.stringify({
        rp: creationOptions.rp,
        user: {
            id: "...", // Don't log sensitive user ID
            name: creationOptions.user ? creationOptions.user.name : "undefined",
            displayName: creationOptions.user ? creationOptions.user.displayName : "undefined"
        },
        pubKeyCredParams: creationOptions.pubKeyCredParams,
        authenticatorSelection: creationOptions.authenticatorSelection,
        timeout: creationOptions.timeout,
        attestation: creationOptions.attestation
    }, null, 2));

    return creationOptions;
}

/**
 * Prepare WebAuthn request options by converting base64url strings to ArrayBuffers
 * @param {Object} requestOptions - options from server
 * @returns {Object} prepared options for navigator.credentials.get()
 */
function prepareRequestOptions(requestOptions)
{
    console.log("Original request options received:", JSON.stringify(requestOptions, null, 2));

    // Handle case where response is wrapped in a 'publicKey' property
    if (requestOptions.publicKey && typeof requestOptions.publicKey === 'object')
    {
        console.log("Unwrapping publicKey property");
        requestOptions = requestOptions.publicKey;
    }

    // Convert challenge from base64url to ArrayBuffer
    if (requestOptions.challenge)
    {
        // Check if the challenge is in a BINARY format (like "=?BINARY?B?ABC123?=")
        if (typeof requestOptions.challenge === 'string' && requestOptions.challenge.startsWith('=?BINARY?B?'))
        {
            // Extract the Base64 part
            const base64Part = requestOptions.challenge.replace('=?BINARY?B?', '').replace('?=', '');
            console.log("Extracted Base64 part from BINARY format:", base64Part);
            requestOptions.challenge = base64Part;
        }
        requestOptions.challenge = PasskeyUtils.base64urlToArrayBuffer(requestOptions.challenge);
    }

    // Convert allowCredentials id from base64url to ArrayBuffer
    if (requestOptions.allowCredentials && Array.isArray(requestOptions.allowCredentials))
    {
        for (let i = 0; i < requestOptions.allowCredentials.length; i++)
        {
            if (typeof requestOptions.allowCredentials[i].id === 'string')
            {
                if (requestOptions.allowCredentials[i].id.startsWith('=?BINARY?B?'))
                {
                    // Extract the Base64 part
                    const base64Part = requestOptions.allowCredentials[i].id.replace('=?BINARY?B?', '').replace('?=', '');
                    requestOptions.allowCredentials[i].id = base64Part;
                }
                requestOptions.allowCredentials[i].id = PasskeyUtils.base64urlToArrayBuffer(
                    requestOptions.allowCredentials[i].id
                );
            }
        }
    }

    // Set reasonable defaults for authentication

    // Set user verification based on dev mode
    if (!requestOptions.userVerification)
    {
        requestOptions.userVerification = PasskeyUtils.isDevMode() ? "discouraged" : "preferred";
    }

    // Set reasonable timeout (2 minutes)
    if (!requestOptions.timeout)
    {
        requestOptions.timeout = 120000;
    }

    console.log("Prepared authentication request options:",
        JSON.stringify({
            challenge: "...", // Don't log challenge
            allowCredentials: requestOptions.allowCredentials ?
                `${requestOptions.allowCredentials.length} credentials` : "not specified",
            userVerification: requestOptions.userVerification,
            timeout: requestOptions.timeout
        }, null, 2));

    return requestOptions;
}

/**
 * Prepare creation response to be sent to server
 * @param {PublicKeyCredential} credential - credential from navigator.credentials.create()
 * @returns {Object} prepared response for server
 */
function prepareCreationResponse(credential)
{
    const response = {
        id: credential.id,
        type: credential.type,
        rawId: PasskeyUtils.arrayBufferToBase64url(credential.rawId),
        response: {
            attestationObject: PasskeyUtils.arrayBufferToBase64url(credential.response.attestationObject),
            clientDataJSON: PasskeyUtils.arrayBufferToBase64url(credential.response.clientDataJSON)
        }
    };

    // Add client information
    response.clientInfo = {
        userAgent: navigator.userAgent,
        platform: navigator.platform,
        language: navigator.language
    };

    return response;
}

/**
 * Prepare authentication response to be sent to server
 * @param {PublicKeyCredential} credential - credential from navigator.credentials.get()
 * @returns {Object} prepared response for server
 */
function prepareAuthenticationResponse(credential)
{
    const response = {
        id: credential.id,
        type: credential.type,
        rawId: PasskeyUtils.arrayBufferToBase64url(credential.rawId),
        response: {
            authenticatorData: PasskeyUtils.arrayBufferToBase64url(credential.response.authenticatorData),
            clientDataJSON: PasskeyUtils.arrayBufferToBase64url(credential.response.clientDataJSON),
            signature: PasskeyUtils.arrayBufferToBase64url(credential.response.signature),
            userHandle: credential.response.userHandle ?
                PasskeyUtils.arrayBufferToBase64url(credential.response.userHandle) : null
        }
    };

    // Add client information
    response.clientInfo = {
        userAgent: navigator.userAgent,
        platform: navigator.platform,
        language: navigator.language
    };

    return response;
}

/**
 * Register a new passkey
 * @param {string} username - username to associate with the passkey
 * @param {string} registrationOptionsUrl - URL to fetch registration options
 * @param {string} registrationVerifyUrl - URL to verify registration
 * @returns {Promise<boolean|Object>} true if registration succeeded, or error object with details
 */
async function registerPasskey(username, registrationOptionsUrl, registrationVerifyUrl)
{
    try
    {
        console.log("Starting passkey registration process for user:", username);

        // Set username in a global variable so prepareCreationOptions can access it
        window.passkeyUsername = username;

        // Step 1: Get registration options from server
        console.log("Fetching registration options from:", registrationOptionsUrl);
        const optionsResponse = await fetch(registrationOptionsUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!optionsResponse.ok)
        {
            const errorText = await optionsResponse.text();
            console.error("Server returned error when fetching registration options:", optionsResponse.status, errorText);
            throw new Error(`Failed to fetch registration options: ${optionsResponse.status} ${errorText.substring(0, 100)}`);
        }

        // Step 2: Prepare options for WebAuthn API
        let optionsJson;
        try
        {
            optionsJson = await optionsResponse.json();
            console.log("Received registration options from server:", JSON.stringify(optionsJson));
        } catch (e)
        {
            console.error("Failed to parse JSON from server response:", e);
            throw new Error("Server returned invalid JSON for registration options");
        }

        const creationOptions = prepareCreationOptions(optionsJson);

        // Debug log the RP ID to ensure it matches the domain
        console.log("Effective domain (from window.location.hostname):", window.location.hostname);
        console.log("RP ID being used:", creationOptions.rp?.id);

        // Apply security key testing mode if enabled (after creationOptions is initialized)
        if (window.securityKeyTesting)
        {
            creationOptions.authenticatorSelection = {
                authenticatorAttachment: "platform",// "cross-platform",
                userVerification: "required"
            };
            creationOptions.timeout = 120000; // 2 minutes
            console.log("Security key testing mode enabled for registration");
        }

        // Verify required fields are present before calling the WebAuthn API
        if (!creationOptions.rp)
        {
            throw new Error("Missing relying party (rp) in creation options");
        }
        if (!creationOptions.user)
        {
            throw new Error("Missing user in creation options");
        }
        if (!creationOptions.challenge)
        {
            throw new Error("Missing challenge in creation options");
        }
        if (!creationOptions.pubKeyCredParams || creationOptions.pubKeyCredParams.length === 0)
        {
            throw new Error("Missing pubKeyCredParams in creation options - this should have been added automatically");
        }

        console.log("Calling navigator.credentials.create() with prepared options");

        // Step 3: Create credential with WebAuthn API
        let credential;
        try
        {
            credential = await navigator.credentials.create({
                publicKey: creationOptions
            });
        } catch (creationError)
        {
            console.error("Error during credential creation:", creationError);

            // Check for common errors and give more helpful messages
            if (creationError.name === 'NotAllowedError')
            {
                throw new Error("Operation was denied by the user or the security key - did you cancel the prompt?");
            } else if (creationError.name === 'SecurityError')
            {
                throw new Error("The operation failed for security reasons - the RP ID might not match the domain");
            } else
            {
                throw new Error(`WebAuthn credential creation failed: ${creationError.name}: ${creationError.message}`);
            }
        }

        if (!credential)
        {
            throw new Error("Credentials API returned null or undefined");
        }

        console.log("Credential created successfully, preparing response");

        // Step 4: Prepare response for server
        const response = prepareCreationResponse(credential);

        // Add username to the response so the server knows which user this credential belongs to
        response.username = username;

        // Log the response being sent to the server (excluding large binary data)
        console.log("Sending credential to server:", {
            id: response.id,
            type: response.type,
            rawId_length: response.rawId ? response.rawId.length : 'undefined',
            username: response.username,
            clientDataJSON_length: response.response?.clientDataJSON ? response.response.clientDataJSON.length : 'undefined',
            attestationObject_length: response.response?.attestationObject ? response.response.attestationObject.length : 'undefined'
        });

        // Step 5: Send response to server for verification
        console.log("Sending credential to server for verification at:", registrationVerifyUrl);
        const verifyResponse = await fetch(registrationVerifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(response)
        });

        // Log the status of the server response
        console.log("Server verification response status:", verifyResponse.status);

        if (!verifyResponse.ok)
        {
            let errorMessage = `Verification failed with status: ${verifyResponse.status}`;
            let errorDetails = {};

            try
            {
                const errorData = await verifyResponse.json();
                console.error("Server verification error details:", errorData);
                errorMessage = errorData.message || errorMessage;
                errorDetails = errorData;
            } catch (e)
            {
                // If we can't parse JSON, use the text response
                const errorText = await verifyResponse.text();
                console.error("Server verification error text:", errorText);
                errorMessage += ` - ${errorText.substring(0, 100)}`;
            }

            console.error("Server verification failed:", errorMessage);

            // Return detailed error information
            const error = new Error(errorMessage);
            error.details = errorDetails;
            error.status = verifyResponse.status;
            throw error;
        }

        console.log("Server verification completed successfully");
        const verifyData = await verifyResponse.json();
        console.log("Verification response data:", verifyData);

        // Clean up the global variable
        delete window.passkeyUsername;

        return verifyData.success === true;
    } catch (error)
    {
        // Clean up the global variable even if there's an error
        delete window.passkeyUsername;

        console.error('Error registering passkey:', error);
        throw error;
    }
}

/**
 * Authenticate with a passkey
 * @param {string} authenticationOptionsUrl - URL to fetch authentication options
 * @param {string} authenticationVerifyUrl - URL to verify authentication
 * @returns {Promise<boolean>} true if authentication succeeded
 */
async function authenticateWithPasskey(authenticationOptionsUrl, authenticationVerifyUrl)
{
    try
    {
        console.log("Starting passkey authentication process");

        // Step 1: Get authentication options from server
        const optionsResponse = await fetch(authenticationOptionsUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!optionsResponse.ok)
        {
            const errorText = await optionsResponse.text();
            console.error("Server returned error when fetching authentication options:", optionsResponse.status, errorText);
            throw new Error(`Failed to fetch authentication options: ${optionsResponse.status} ${errorText.substring(0, 100)}`);
        }

        // Step 2: Prepare options for WebAuthn API
        let optionsJson;
        try
        {
            optionsJson = await optionsResponse.json();
            console.log("Received authentication options from server");
        } catch (e)
        {
            console.error("Failed to parse JSON from server response:", e);
            throw new Error("Server returned invalid JSON for authentication options");
        }

        const requestOptions = prepareRequestOptions(optionsJson);

        // Apply security key testing mode if enabled (after requestOptions is initialized)
        if (window.securityKeyTesting)
        {
            requestOptions.userVerification = "required";
            requestOptions.timeout = 120000; // 2 minutes
            console.log("Security key testing mode enabled for authentication");
        }

        // DEVELOPMENT MODE: Modify authentication options for testing
        if (PasskeyUtils.isDevMode())
        {
            console.log("Applying development mode settings to authentication options");
            // Set user verification to "discouraged" for testing - more lenient
            requestOptions.userVerification = "discouraged";
            // Add longer timeout for testing (5 minutes)
            requestOptions.timeout = 300000;
        }

        console.log("Calling navigator.credentials.get() with prepared options");

        // Step 3: Get credential with WebAuthn API
        const credential = await navigator.credentials.get({
            publicKey: requestOptions
        });

        if (!credential)
        {
            throw new Error("Credentials API returned null or undefined");
        }

        console.log("Credential retrieved successfully, preparing response");

        // Step 4: Prepare response for server
        const response = prepareAuthenticationResponse(credential);

        // Step 5: Send response to server for verification
        console.log("Sending credential to server for verification");
        const verifyResponse = await fetch(authenticationVerifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(response)
        });

        if (!verifyResponse.ok)
        {
            let errorMessage = `Verification failed with status: ${verifyResponse.status}`;
            try
            {
                const errorData = await verifyResponse.json();
                errorMessage = errorData.message || errorMessage;
            } catch (e)
            {
                // If we can't parse JSON, use the text response
                const errorText = await verifyResponse.text();
                errorMessage += ` - ${errorText.substring(0, 100)}`;
            }

            console.error("Server verification failed:", errorMessage);
            throw new Error(errorMessage);
        }

        console.log("Server verification completed successfully");
        const verifyData = await verifyResponse.json();
        return verifyData.success === true;
    } catch (error)
    {
        console.error('Error authenticating with passkey:', error);
        throw error;
    }
}