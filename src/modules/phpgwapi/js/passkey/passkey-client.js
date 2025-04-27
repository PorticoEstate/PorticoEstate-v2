/**
 * Passkey Client JS
 * 
 * This file provides client-side functions for working with WebAuthn/Passkeys
 */

// Create a namespace to avoid undefined errors and global scope pollution
const PasskeyUtils = {
    /**
     * Convert a base64url string to an ArrayBuffer
     * @param {string} base64url - base64url encoded string
     * @returns {ArrayBuffer} decoded array buffer
     */
    base64urlToArrayBuffer: function (base64url)
    {
        if (!base64url)
        {
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
            return "";
        }
    }
};

/**
 * Check if WebAuthn is available and if platform authenticators are supported
 * @returns {Promise<boolean>} true if platform authenticators are supported
 */
async function isPlatformAuthenticatorAvailable()
{
    // First check if WebAuthn is supported by this browser
    if (!window.PublicKeyCredential)
    {
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
            // Silently fail and continue
        }
    }

    // Check if platform authenticator is available
    try
    {
        const available = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
        return available || conditionalMediationAvailable;
    } catch (error)
    {
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
    // Handle case where response is wrapped in a 'publicKey' property
    if (creationOptions.publicKey && typeof creationOptions.publicKey === 'object')
    {
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
        creationOptions.pubKeyCredParams = [
            { type: "public-key", alg: -7 },  // ES256 (Elliptic Curve P-256 with SHA-256)
            { type: "public-key", alg: -257 } // RS256 (RSASSA-PKCS1-v1_5 using SHA-256)
        ];
    }

    // Add relying party (rp) if missing - this is a REQUIRED parameter
    if (!creationOptions.rp)
    {
        // Try to determine the current domain
        const domain = window.location.hostname;
        creationOptions.rp = {
            id: domain,
            name: document.title || "PorticoEstate"
        };
    } else if (!creationOptions.rp.id)
    {
        // If rp exists but id is missing
        creationOptions.rp.id = window.location.hostname;
    }

    // Configure the authenticatorSelection with reasonable defaults if not set
    if (!creationOptions.authenticatorSelection)
    {
        creationOptions.authenticatorSelection = {
            authenticatorAttachment: "platform",
            userVerification: "preferred",
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
            throw new Error("RP ID mismatch - security restriction");
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

    return creationOptions;
}

/**
 * Prepare WebAuthn request options by converting base64url strings to ArrayBuffers
 * @param {Object} requestOptions - options from server
 * @returns {Object} prepared options for navigator.credentials.get()
 */
function prepareRequestOptions(requestOptions)
{
    // Handle case where response is wrapped in a 'publicKey' property
    if (requestOptions.publicKey && typeof requestOptions.publicKey === 'object')
    {
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

    // Set user verification to preferred by default
    if (!requestOptions.userVerification)
    {
        requestOptions.userVerification = "preferred";
    }

    // Set reasonable timeout (2 minutes)
    if (!requestOptions.timeout)
    {
        requestOptions.timeout = 120000;
    }

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
        // Step 1: Get registration options from server
        const optionsResponse = await fetch(registrationOptionsUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        });

        if (!optionsResponse.ok)
        {
            const errorText = await optionsResponse.text();
            throw new Error(`Failed to fetch registration options: ${optionsResponse.status}`);
        }

        // Step 2: Prepare options for WebAuthn API
        let optionsJson;
        try
        {
            optionsJson = await optionsResponse.json();
        } catch (e)
        {
            throw new Error("Server returned invalid JSON for registration options");
        }

        const creationOptions = prepareCreationOptions(optionsJson);

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
            throw new Error("Missing pubKeyCredParams in creation options");
        }

        // Step 3: Create credential with WebAuthn API
        let credential;
        try
        {
            credential = await navigator.credentials.create({
                publicKey: creationOptions
            });
        } catch (creationError)
        {
            if (creationError.name === 'NotAllowedError')
            {
                throw new Error("Operation was denied by the user or the security key");
            } else if (creationError.name === 'SecurityError')
            {
                throw new Error("The operation failed for security reasons");
            } else
            {
                throw new Error(`WebAuthn credential creation failed: ${creationError.name}`);
            }
        }

        if (!credential)
        {
            throw new Error("Credentials API returned null or undefined");
        }

        // Step 4: Prepare response for server
        const response = prepareCreationResponse(credential);

        // Add username to the response so the server knows which user this credential belongs to
        response.username = username;

        // Step 5: Send response to server for verification
        const verifyResponse = await fetch(registrationVerifyUrl, {
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
            let errorDetails = {};

            try
            {
                const errorData = await verifyResponse.json();
                errorMessage = errorData.message || errorMessage;
                errorDetails = errorData;
            } catch (e)
            {
                // If we can't parse JSON, use the text response
                const errorText = await verifyResponse.text();
                errorMessage += ` - ${errorText.substring(0, 100)}`;
            }

            // Return detailed error information
            const error = new Error(errorMessage);
            error.details = errorDetails;
            error.status = verifyResponse.status;
            throw error;
        }

        const verifyData = await verifyResponse.json();
        return verifyData.success === true;
    } catch (error)
    {
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
            throw new Error(`Failed to fetch authentication options: ${optionsResponse.status}`);
        }

        // Step 2: Prepare options for WebAuthn API
        let optionsJson;
        try
        {
            optionsJson = await optionsResponse.json();
        } catch (e)
        {
            throw new Error("Server returned invalid JSON for authentication options");
        }

        const requestOptions = prepareRequestOptions(optionsJson);

        // Step 3: Get credential with WebAuthn API
        const credential = await navigator.credentials.get({
            publicKey: requestOptions
        });

        if (!credential)
        {
            throw new Error("Credentials API returned null or undefined");
        }

        // Step 4: Prepare response for server
        const response = prepareAuthenticationResponse(credential);

        // Step 5: Send response to server for verification
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

            throw new Error(errorMessage);
        }

        const verifyData = await verifyResponse.json();
        return verifyData.success === true;
    } catch (error)
    {
        throw error;
    }
}