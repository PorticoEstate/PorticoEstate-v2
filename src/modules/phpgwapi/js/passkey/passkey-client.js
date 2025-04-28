/**
 * Passkey Client JS library for WebAuthn operations
 * Secure implementation for browser-based WebAuthn operations
 */

/**
 * Utility functions for encoding/decoding between different formats
 */
const PasskeyUtils = {
    /**
     * Convert an ArrayBuffer to a Base64Url string
     * @param {ArrayBuffer} buffer - The buffer to convert
     * @returns {string} Base64Url encoded string
     */
    arrayBufferToBase64url: function (buffer)
    {
        const bytes = new Uint8Array(buffer);
        let str = '';
        for (const byte of bytes)
        {
            str += String.fromCharCode(byte);
        }
        // Base64 encode and convert to base64url format
        return btoa(str)
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, '');
    },

    /**
     * Convert a Base64Url string to an ArrayBuffer
     * @param {string} base64url - The Base64Url string to convert
     * @returns {ArrayBuffer} Decoded ArrayBuffer
     */
    base64urlToArrayBuffer: function (base64url)
    {
        // Convert base64url to base64
        const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
        // Add padding if needed
        const paddedBase64 = base64.padEnd(base64.length + (4 - (base64.length % 4 || 4)) % 4, '=');
        // Decode base64 to binary string
        const binaryString = atob(paddedBase64);
        // Convert binary string to ArrayBuffer
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++)
        {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }
};

/**
 * Check if WebAuthn is available and if platform authenticators are supported
 * @returns {Promise<boolean>} true if platform authenticators are supported
 */
async function isPlatformAuthenticatorAvailable()
{
    // First check if WebAuthn is available
    if (typeof PublicKeyCredential === 'undefined')
    {
        console.log('WebAuthn API is not available in this browser');
        return false;
    }

    // Always return true if basic WebAuthn is supported
    // This allows all modern browsers to work even if they don't support
    // the specific platform authenticator check method
    return true;
}

/**
 * Prepare WebAuthn creation options by converting base64url strings to ArrayBuffers
 * @param {Object} creationOptions - options from server
 * @param {string} username - the username for registration
 * @returns {Object} prepared options for navigator.credentials.create()
 */
function prepareCreationOptions(creationOptions, username)
{
    // Decode challenge from base64url to ArrayBuffer
    creationOptions.challenge = PasskeyUtils.base64urlToArrayBuffer(creationOptions.challenge);

    // Get system name from current hostname or use a default
    const systemName = window.location.hostname || 'localhost';

    // Add user information if missing (REQUIRED by WebAuthn spec)
    if (!creationOptions.user)
    {
        // Create a default user object using a random ID
        const randomId = new Uint8Array(16);
        window.crypto.getRandomValues(randomId);

        creationOptions.user = {
            id: randomId.buffer,
            name: username + '@' + systemName,
            displayName: username
        };
    } else
    {
        // Decode user ID from base64url to ArrayBuffer if it exists
        if (creationOptions.user.id)
        {
            creationOptions.user.id = PasskeyUtils.base64urlToArrayBuffer(creationOptions.user.id);
        } else
        {
            // Create a random ID if none was provided
            const randomId = new Uint8Array(16);
            window.crypto.getRandomValues(randomId);
            creationOptions.user.id = randomId.buffer;
        }

        // Ensure name and displayName are present with proper values
        if (!creationOptions.user.name)
        {
            creationOptions.user.name = username + '@' + systemName;
        }
        if (!creationOptions.user.displayName)
        {
            creationOptions.user.displayName = username;
        }
    }

    // If excludeCredentials exists, decode ID for each credential
    if (creationOptions.excludeCredentials)
    {
        for (const credential of creationOptions.excludeCredentials)
        {
            if (credential.id)
            {
                credential.id = PasskeyUtils.base64urlToArrayBuffer(credential.id);
                // Make sure transports is correctly set if available
                if (!credential.transports)
                {
                    credential.transports = ['internal', 'usb', 'ble', 'nfc'];
                }
            }
        }
    }

    // Enforce security settings for WebAuthn
    if (!creationOptions.authenticatorSelection)
    {
        creationOptions.authenticatorSelection = {};
    }

    // Ensure user verification is required for security
    creationOptions.authenticatorSelection.userVerification = 'required';

    // Add pubKeyCredParams if missing (REQUIRED by WebAuthn spec)
    if (!creationOptions.pubKeyCredParams || !creationOptions.pubKeyCredParams.length)
    {
        // Default to common algorithms: ES256 (-7) and RS256 (-257)
        creationOptions.pubKeyCredParams = [
            { type: 'public-key', alg: -7 },    // ES256 (ECDSA w/ SHA-256)
            { type: 'public-key', alg: -257 }   // RS256 (RSASSA-PKCS1-v1_5 w/ SHA-256)
        ];
    }

    // Add rp (Relying Party) information if missing (REQUIRED by WebAuthn spec)
    if (!creationOptions.rp)
    {
        // Default to using the current domain as the relying party ID
        creationOptions.rp = {
            id: window.location.hostname,
            name: document.title || window.location.hostname
        };
    } else
    {
        // If rp exists but doesn't have an id, set it to the current domain
        if (!creationOptions.rp.id)
        {
            creationOptions.rp.id = window.location.hostname;
        }
        // If rp exists but doesn't have a name, use the title or hostname
        if (!creationOptions.rp.name)
        {
            creationOptions.rp.name = document.title || window.location.hostname;
        }
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
    // Decode challenge from base64url to ArrayBuffer
    requestOptions.challenge = PasskeyUtils.base64urlToArrayBuffer(requestOptions.challenge);

    // If allowCredentials exists, decode ID for each credential
    if (requestOptions.allowCredentials)
    {
        for (const credential of requestOptions.allowCredentials)
        {
            if (credential.id)
            {
                credential.id = PasskeyUtils.base64urlToArrayBuffer(credential.id);

                // Make sure transports is correctly set if available
                if (!credential.transports)
                {
                    credential.transports = ['internal', 'usb', 'ble', 'nfc'];
                }
            }
        }
    }

    // Ensure user verification is required for security
    requestOptions.userVerification = 'required';

    return requestOptions;
}

/**
 * Prepare creation response to be sent to server
 * @param {PublicKeyCredential} credential - credential from navigator.credentials.create()
 * @returns {Object} prepared response for server
 */
function prepareCreationResponse(credential)
{
    // Get the core credential data
    const response = {
        id: credential.id,
        rawId: PasskeyUtils.arrayBufferToBase64url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: PasskeyUtils.arrayBufferToBase64url(credential.response.clientDataJSON),
            attestationObject: PasskeyUtils.arrayBufferToBase64url(credential.response.attestationObject)
        }
    };

    // Add any additional registration information if available
    if (credential.getClientExtensionResults)
    {
        response.clientExtensionResults = credential.getClientExtensionResults();
    }

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
        rawId: PasskeyUtils.arrayBufferToBase64url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: PasskeyUtils.arrayBufferToBase64url(credential.response.clientDataJSON),
            authenticatorData: PasskeyUtils.arrayBufferToBase64url(credential.response.authenticatorData),
            signature: PasskeyUtils.arrayBufferToBase64url(credential.response.signature)
        }
    };

    // Add user handle if available (from resident key)
    if (credential.response.userHandle)
    {
        response.response.userHandle = PasskeyUtils.arrayBufferToBase64url(credential.response.userHandle);
    }

    // Add client extension results if available
    if (credential.getClientExtensionResults)
    {
        response.clientExtensionResults = credential.getClientExtensionResults();
    }

    return response;
}

/**
 * Register a new passkey
 * @param {string} username - the username
 * @param {string} registrationOptionsUrl - URL to fetch registration options
 * @param {string} registrationVerifyUrl - URL to verify registration
 * @returns {Promise<boolean>} true if registration succeeded
 */
async function registerPasskey(username, registrationOptionsUrl, registrationVerifyUrl)
{
    try
    {
        console.log("Starting passkey registration process");

        // Append a no-cache parameter to prevent caching issues
        registrationOptionsUrl = appendNoCacheParam(registrationOptionsUrl);

        //  console.log("Fetching registration options from:", registrationOptionsUrl);
        const optionsResponse = await fetch(registrationOptionsUrl, {
            method: 'GET',
            credentials: 'same-origin' // Important: ensure cookies are sent
        });

        if (!optionsResponse.ok)
        {
            console.error("Failed to fetch registration options:", optionsResponse.status, optionsResponse.statusText);

            let errorMsg = `Failed to fetch registration options: ${optionsResponse.status}`;
            try
            {
                const errorData = await optionsResponse.json();
                if (errorData && errorData.message)
                {
                    errorMsg = errorData.message;
                    console.error("Server error message:", errorData.message);
                }
            } catch (e)
            {
                // If JSON parsing fails, use the default error message
                console.error("Could not parse error response:", e);
            }
            throw new Error(errorMsg);
        }

        // Parse the options and prepare for registration
        const publicKeyOptions = await optionsResponse.json();
        //   console.log("Received registration options:", JSON.stringify(publicKeyOptions, null, 2));

        // Convert base64url-encoded values to ArrayBuffer as required by the WebAuthn API
        const publicKey = prepareCreationOptions(publicKeyOptions, username);
        // console.log("Prepared options for navigator.credentials.create");

        // Actual registration using the WebAuthn API
        console.log("Calling navigator.credentials.create...");
        const credential = await navigator.credentials.create({
            publicKey
        });
        // console.log("Credentials created successfully", credential);

        if (!credential)
        {
            console.error("No credential returned from navigator.credentials.create");
            throw new Error("Browser returned no credential data");
        }

        // Convert the credential to a format suitable for sending to the server
        const response = prepareCreationResponse(credential);
        console.log("Prepared credential response for server verification");

        // Append a no-cache parameter to prevent caching issues
        registrationVerifyUrl = appendNoCacheParam(registrationVerifyUrl);

        console.log("Sending verification request to:", registrationVerifyUrl);
        // console.log("Verification request payload:", JSON.stringify(response, (key, value) =>
        // {
        //     // Don't log the full attestationObject and clientDataJSON which can be very long
        //     if (key === 'attestationObject' || key === 'clientDataJSON')
        //     {
        //         return `[${value.substring(0, 20)}...]`;
        //     }
        //     return value;
        // }, 2));

        const verifyResponse = await fetch(registrationVerifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            credentials: 'same-origin', // Important: ensure cookies are sent
            body: JSON.stringify(response)
        });

        if (!verifyResponse.ok)
        {
            console.error("Failed to verify registration:", verifyResponse.status, verifyResponse.statusText);

            let errorMsg = `Failed to verify registration: ${verifyResponse.status}`;
            try
            {
                const errorData = await verifyResponse.json();
                if (errorData && errorData.message)
                {
                    errorMsg = errorData.message;
                    console.error("Server error message:", errorData.message);
                }
            } catch (e)
            {
                // If JSON parsing fails, use the default error message
                console.error("Could not parse error response:", e);
            }
            throw new Error(errorMsg);
        }

        try
        {
            const result = await verifyResponse.json();
            //           console.log("Verification result:", result);
            return result.success === true;
        } catch (e)
        {
            console.error("Error parsing verification response:", e);
            throw new Error("Server returned invalid response format");
        }
    } catch (error)
    {
        console.error("Error during passkey registration:", error);
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
        // Add anti-cache parameter
        const noCacheUrl = appendNoCacheParam(authenticationOptionsUrl);

        // Step 1: Get authentication options from server
        const optionsResponse = await fetch(noCacheUrl, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'same-origin' // Important for security
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

        // Validate server response
        if (!optionsJson.challenge)
        {
            throw new Error("Server response missing challenge");
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
        const noCacheVerifyUrl = appendNoCacheParam(authenticationVerifyUrl);
        const verifyResponse = await fetch(noCacheVerifyUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'same-origin', // Important for security
            body: JSON.stringify(response)
        });

        if (!verifyResponse.ok)
        {
            let errorMsg = `Authentication failed: ${verifyResponse.status}`;
            try
            {
                const errorData = await verifyResponse.json();
                if (errorData && errorData.message)
                {
                    errorMsg = errorData.message;
                }
            } catch (e)
            {
                // If JSON parsing fails, use the default error message
            }
            throw new Error(errorMsg);
        }

        try
        {
            const result = await verifyResponse.json();
            return result.success === true;
        } catch (e)
        {
            throw new Error("Server returned invalid response format");
        }
    } catch (error)
    {
        console.error("Error during passkey authentication:", error);
        throw error;
    }
}

/**
 * Append a no-cache parameter to a URL to prevent caching
 * @param {string} url - The URL to append the parameter to
 * @returns {string} URL with no-cache parameter
 */
function appendNoCacheParam(url)
{
    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}_nocache=${Date.now()}`;
}

// Export functions for use in other scripts
if (typeof module !== 'undefined' && typeof module.exports !== 'undefined')
{
    module.exports = {
        PasskeyUtils,
        isPlatformAuthenticatorAvailable,
        prepareCreationOptions,
        prepareRequestOptions,
        prepareCreationResponse,
        prepareAuthenticationResponse,
        registerPasskey,
        authenticateWithPasskey
    };
}