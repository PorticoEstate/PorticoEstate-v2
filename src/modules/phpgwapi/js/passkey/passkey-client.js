/**
 * Passkey (WebAuthn) Client-Side Logic
 */

// --- Helper Functions ---

/**
 * Converts ArrayBuffer to Base64URL string.
 * @param {ArrayBuffer} buffer
 * @returns {string}
 */
function bufferToBase64Url(buffer)
{
	const bytes = new Uint8Array(buffer);
	let str = '';
	for (const charCode of bytes)
	{
		str += String.fromCharCode(charCode);
	}
	const base64 = btoa(str);
	return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

/**
 * Converts Base64URL string to ArrayBuffer.
 * @param {string} base64Url
 * @returns {ArrayBuffer}
 */
function base64UrlToBuffer(base64Url)
{
	const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
	const padLength = (4 - (base64.length % 4)) % 4;
	const padded = base64 + '='.repeat(padLength);
	const binaryStr = atob(padded);
	const buffer = new ArrayBuffer(binaryStr.length);
	const bytes = new Uint8Array(buffer);
	for (let i = 0; i < binaryStr.length; i++)
	{
		bytes[i] = binaryStr.charCodeAt(i);
	}
	return buffer;
}

// --- Registration Logic ---

/**
 * Initiates the passkey registration process.
 * @param {string} username - The username for the user registering.
 * @param {string} optionsUrl - URL to fetch registration options from the server.
 * @param {string} verifyUrl - URL to send the registration result to the server.
 * @returns {Promise<boolean>} - True if registration was successful, false otherwise.
 */
async function registerPasskey(username, optionsUrl = '/passkey/register/options', verifyUrl = '/passkey/register/verify')
{
	try
	{
		// 1. Fetch registration options from the server
		const respOptions = await fetch(`${optionsUrl}?username=${encodeURIComponent(username)}`);
		if (!respOptions.ok)
		{
			throw new Error(`Failed to fetch registration options: ${respOptions.statusText}`);
		}
		const options = await respOptions.json();

		// 2. Convert necessary options from Base64URL to ArrayBuffer
		options.challenge = base64UrlToBuffer(options.challenge);
		options.user.id = base64UrlToBuffer(options.user.id);
		if (options.excludeCredentials)
		{
			options.excludeCredentials = options.excludeCredentials.map(cred => ({
				...cred,
				id: base64UrlToBuffer(cred.id)
			}));
		}

		// 3. Call navigator.credentials.create()
		console.log("Calling navigator.credentials.create() with options:", options);
		const credential = await navigator.credentials.create({ publicKey: options });
		console.log("Credential created:", credential);

		if (!credential)
		{
			throw new Error("Credential creation failed or was cancelled.");
		}

		// 4. Convert result ArrayBuffers back to Base64URL
		const credentialResponse = {
			id: credential.id,
			rawId: bufferToBase64Url(credential.rawId),
			type: credential.type,
			response: {
				clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
				attestationObject: bufferToBase64Url(credential.response.attestationObject),
			},
			// Include transports if available (useful for the server)
			transports: credential.response.getTransports ? credential.response.getTransports() : []
		};

		// 5. Send the result to the server for verification
		const respVerify = await fetch(verifyUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(credentialResponse),
		});

		if (!respVerify.ok)
		{
			const errorResult = await respVerify.json();
			throw new Error(`Server verification failed: ${errorResult.message || respVerify.statusText}`);
		}

		const result = await respVerify.json();
		console.log("Server verification result:", result);
		return result.success === true;

	} catch (error)
	{
		console.error("Passkey registration error:", error);
		alert(`Registration failed: ${error.message}`);
		return false;
	}
}

// --- Authentication Logic ---

/**
 * Initiates the passkey authentication process.
 * @param {string} optionsUrl - URL to fetch authentication options from the server.
 * @param {string} verifyUrl - URL to send the authentication result to the server.
 * @returns {Promise<boolean>} - True if authentication was successful, false otherwise.
 */
async function authenticatePasskey(optionsUrl = '/passkey/authenticate/options', verifyUrl = '/passkey/authenticate/verify')
{
	try
	{
		// 1. Fetch authentication options from the server
		const respOptions = await fetch(optionsUrl);
		if (!respOptions.ok)
		{
			throw new Error(`Failed to fetch authentication options: ${respOptions.statusText}`);
		}
		const options = await respOptions.json();

		// 2. Convert necessary options from Base64URL to ArrayBuffer
		options.challenge = base64UrlToBuffer(options.challenge);
		if (options.allowCredentials)
		{
			options.allowCredentials = options.allowCredentials.map(cred => ({
				...cred,
				id: base64UrlToBuffer(cred.id)
			}));
		}

		// 3. Call navigator.credentials.get()
		console.log("Calling navigator.credentials.get() with options:", options);
		const credential = await navigator.credentials.get({ publicKey: options });
		console.log("Credential retrieved:", credential);

		if (!credential)
		{
			throw new Error("Authentication failed or was cancelled.");
		}

		// 4. Convert result ArrayBuffers back to Base64URL
		const credentialResponse = {
			id: credential.id,
			rawId: bufferToBase64Url(credential.rawId),
			type: credential.type,
			response: {
				clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
				authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
				signature: bufferToBase64Url(credential.response.signature),
				userHandle: credential.response.userHandle ? bufferToBase64Url(credential.response.userHandle) : null,
			},
		};

		// 5. Send the result to the server for verification
		const respVerify = await fetch(verifyUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify(credentialResponse),
		});

		if (!respVerify.ok)
		{
			const errorResult = await respVerify.json();
			throw new Error(`Server verification failed: ${errorResult.message || respVerify.statusText}`);
		}

		const result = await respVerify.json();
		console.log("Server verification result:", result);
		// Assuming successful verification redirects or updates UI
		// For now, just return success status
		return result.success === true;

	} catch (error)
	{
		console.error("Passkey authentication error:", error);
		alert(`Authentication failed: ${error.message}`);
		return false;
	}
}

// --- Feature Detection ---

/**
 * Checks if the browser supports WebAuthn (Platform authenticators like Windows Hello, Touch ID).
 * @returns {Promise<boolean>}
 */
async function isPlatformAuthenticatorAvailable()
{
	if (window.PublicKeyCredential &&
		PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable)
	{
		try
		{
			return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
		} catch (e)
		{
			console.warn("Error checking platform authenticator availability:", e);
			return false;
		}
	}
	return false;
}

/**
 * Checks if the browser supports WebAuthn conditional mediation (passkey autofill).
 * @returns {Promise<boolean>}
 */
async function isConditionalMediationAvailable()
{
	if (window.PublicKeyCredential && PublicKeyCredential.isConditionalMediationAvailable)
	{
		try
		{
			return await PublicKeyCredential.isConditionalMediationAvailable();
		} catch (e)
		{
			console.warn("Error checking conditional mediation availability:", e);
			return false;
		}
	}
	return false;
}


// Example Usage (You would typically call these from button clicks or page load)
/*
document.addEventListener('DOMContentLoaded', async () => {
	const username = 'testuser'; // Replace with actual username logic

	const registerButton = document.getElementById('registerPasskeyButton');
	const authenticateButton = document.getElementById('authenticatePasskeyButton');

	if (registerButton) {
		registerButton.addEventListener('click', () => {
			registerPasskey(username); // Use default URLs or pass custom ones
		});
		// Disable button if platform authenticator isn't available?
		// registerButton.disabled = !(await isPlatformAuthenticatorAvailable());
	}

	if (authenticateButton) {
		authenticateButton.addEventListener('click', () => {
			authenticatePasskey(); // Use default URLs or pass custom ones
		});
	}

	// Example for conditional mediation (autofill) on a login form
	if (await isConditionalMediationAvailable()) {
		console.log("Conditional mediation is available.");
		// You might trigger authenticatePasskey with specific options here
		// or rely on the browser's autofill UI.
	}
});
*/