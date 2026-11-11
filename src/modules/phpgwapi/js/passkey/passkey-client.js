/**
 * Browser helpers for WebAuthn passkey registration and authentication.
 */
const PasskeyUtils = {
    arrayBufferToBase64url: function (buffer)
    {
        const bytes = buffer instanceof ArrayBuffer
            ? new Uint8Array(buffer)
            : new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength);
        let binary = '';
        for (const byte of bytes)
        {
            binary += String.fromCharCode(byte);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    },

    base64urlToArrayBuffer: function (value)
    {
        if (typeof value !== 'string' || value === '' || !/^[A-Za-z0-9_-]+$/.test(value))
        {
            throw new TypeError('Invalid base64url WebAuthn value');
        }
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        const padded = base64.padEnd(base64.length + (4 - (base64.length % 4 || 4)) % 4, '=');
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index++)
        {
            bytes[index] = binary.charCodeAt(index);
        }
        return bytes.buffer;
    }
};

function getPublicKeyOptions(options)
{
    if (!options || typeof options !== 'object')
    {
        throw new TypeError('Invalid WebAuthn options');
    }
    return options.publicKey || options;
}

function cloneOptions(options)
{
    return JSON.parse(JSON.stringify(getPublicKeyOptions(options)));
}

function decodeCredentialDescriptors(descriptors)
{
    if (descriptors === undefined)
    {
        return;
    }
    if (!Array.isArray(descriptors))
    {
        throw new TypeError('Invalid WebAuthn credential descriptors');
    }
    for (const descriptor of descriptors)
    {
        if (!descriptor || typeof descriptor.id !== 'string')
        {
            throw new TypeError('Invalid WebAuthn credential descriptor');
        }
        descriptor.id = PasskeyUtils.base64urlToArrayBuffer(descriptor.id);
    }
}

/**
 * Return false only when the browser confirms that no platform authenticator is available.
 */
async function isPlatformAuthenticatorAvailable()
{
    if (typeof PublicKeyCredential === 'undefined')
    {
        return false;
    }
    if (typeof PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable !== 'function')
    {
        return false;
    }
    return PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
}

function isWebAuthnAvailable()
{
    return typeof PublicKeyCredential !== 'undefined'
        && typeof navigator.credentials?.create === 'function'
        && typeof navigator.credentials?.get === 'function';
}

function prepareCreationOptions(creationOptions)
{
    const publicKey = getPublicKeyOptions(creationOptions);
    if (typeof PublicKeyCredential !== 'undefined'
        && typeof PublicKeyCredential.parseCreationOptionsFromJSON === 'function')
    {
        return PublicKeyCredential.parseCreationOptionsFromJSON(publicKey);
    }

    const prepared = cloneOptions(publicKey);
    if (typeof prepared.challenge !== 'string' || !prepared.user || typeof prepared.user.id !== 'string')
    {
        throw new TypeError('Server returned incomplete passkey registration options');
    }
    prepared.challenge = PasskeyUtils.base64urlToArrayBuffer(prepared.challenge);
    prepared.user.id = PasskeyUtils.base64urlToArrayBuffer(prepared.user.id);
    decodeCredentialDescriptors(prepared.excludeCredentials);
    return prepared;
}

function prepareRequestOptions(requestOptions)
{
    const publicKey = getPublicKeyOptions(requestOptions);
    if (typeof PublicKeyCredential !== 'undefined'
        && typeof PublicKeyCredential.parseRequestOptionsFromJSON === 'function')
    {
        return PublicKeyCredential.parseRequestOptionsFromJSON(publicKey);
    }

    const prepared = cloneOptions(publicKey);
    if (typeof prepared.challenge !== 'string')
    {
        throw new TypeError('Server returned incomplete passkey authentication options');
    }
    prepared.challenge = PasskeyUtils.base64urlToArrayBuffer(prepared.challenge);
    decodeCredentialDescriptors(prepared.allowCredentials);
    return prepared;
}

function prepareCreationResponse(credential)
{
    if (typeof credential.toJSON === 'function')
    {
        return credential.toJSON();
    }
    const response = {
        id: credential.id,
        rawId: PasskeyUtils.arrayBufferToBase64url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: PasskeyUtils.arrayBufferToBase64url(credential.response.clientDataJSON),
            attestationObject: PasskeyUtils.arrayBufferToBase64url(credential.response.attestationObject)
        }
    };
    if (credential.getClientExtensionResults)
    {
        response.clientExtensionResults = credential.getClientExtensionResults();
    }
    return response;
}

function prepareAuthenticationResponse(credential)
{
    if (typeof credential.toJSON === 'function')
    {
        return credential.toJSON();
    }
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
    if (credential.response.userHandle)
    {
        response.response.userHandle = PasskeyUtils.arrayBufferToBase64url(credential.response.userHandle);
    }
    if (credential.getClientExtensionResults)
    {
        response.clientExtensionResults = credential.getClientExtensionResults();
    }
    return response;
}

async function registerPasskey(_username, registrationOptionsUrl, registrationVerifyUrl)
{
    const optionsResponse = await fetch(appendNoCacheParam(registrationOptionsUrl), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'},
        cache: 'no-store'
    });
    const optionsData = await readJsonResponse(optionsResponse, 'Unable to load passkey registration options');
    const credential = await navigator.credentials.create({
        publicKey: prepareCreationOptions(optionsData)
    });
    if (!credential)
    {
        throw new Error('The browser did not return passkey registration data.');
    }

    const verifyResponse = await fetch(appendNoCacheParam(registrationVerifyUrl), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(prepareCreationResponse(credential))
    });
    const result = await readJsonResponse(verifyResponse, 'Unable to verify passkey registration');
    return result.success === true;
}

async function readJsonResponse(response, fallbackMessage)
{
    let result;
    try
    {
        result = await response.json();
    }
    catch (error)
    {
        throw new Error(fallbackMessage + ': invalid server response');
    }
    if (!result || typeof result !== 'object')
    {
        throw new Error(fallbackMessage + ': invalid server response');
    }
    if (!response.ok)
    {
        throw new Error(result.message || fallbackMessage);
    }
    return result;
}

function appendNoCacheParam(url)
{
    const parsedUrl = new URL(url, window.location.href);
    parsedUrl.searchParams.set('_nocache', Date.now().toString());
    return parsedUrl.toString();
}

if (typeof module !== 'undefined' && typeof module.exports !== 'undefined')
{
    module.exports = {
        PasskeyUtils,
        isPlatformAuthenticatorAvailable,
        isWebAuthnAvailable,
        prepareCreationOptions,
        prepareRequestOptions,
        prepareCreationResponse,
        prepareAuthenticationResponse,
        registerPasskey
    };
}
