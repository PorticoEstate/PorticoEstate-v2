<!-- Passkey Management Template -->
<div class="container">
    <h2>{lang_passkey_management}</h2>
    <p class="lead">{lang_passkey_description}</p>

    <div class="alert alert-info" id="passkey-compatibility-check" style="display:none;">
        <i class="fas fa-info-circle"></i> {lang_checking_passkey_support}
    </div>

    <div class="alert alert-danger" id="passkey-not-supported" style="display:none;">
        <i class="fas fa-exclamation-triangle"></i> {lang_passkey_not_supported}
    </div>

    <div class="row" id="passkey-content" style="display:none;">
        <!-- Current Passkeys -->
        <div class="col-md-12 mb-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{lang_your_passkeys}</h3>
                </div>
                <div class="card-body">
                    <div id="passkey-list-container">
                        <!-- Passkey list will be populated here -->
                        {passkey_list_html}
                    </div>
                    
                    <!-- Empty state message -->
                    <div id="no-passkeys-message" class="{no_passkeys_class}">
                        <p class="text-muted text-center py-3">{lang_no_passkeys}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add New Passkey -->
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{lang_add_new_passkey}</h3>
                </div>
                <div class="card-body">
                    <form id="register-passkey-form">
                        <input type="hidden" name="csrf_token" id="csrf-token" value="{csrf_token}">
                        <div class="form-group mb-3">
                            <label for="passkey-name">{lang_passkey_name}</label>
                            <input type="text" class="form-control" id="passkey-name" 
                                   placeholder="{lang_passkey_name_placeholder}" required>
                            <small class="form-text text-muted">{lang_passkey_name_help}</small>
                        </div>
                        
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-shield-alt"></i> {lang_passkey_security_notice}
                        </div>
                        
                        <button type="button" id="register-passkey-button" class="btn btn-primary">
                            <i class="fas fa-fingerprint"></i> {lang_register_passkey}
                        </button>
                    </form>
                    
                    <div id="registration-status" class="mt-3" style="display:none;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include the passkey client JavaScript -->
<script src="{webserver_url}/src/modules/phpgwapi/js/passkey/passkey-client.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const compatibilityCheck = document.getElementById('passkey-compatibility-check');
    const notSupportedMessage = document.getElementById('passkey-not-supported');
    const passkeyContent = document.getElementById('passkey-content');
    const registerButton = document.getElementById('register-passkey-button');
    const passkeyNameInput = document.getElementById('passkey-name');
    const registrationStatus = document.getElementById('registration-status');
    const deleteButtons = document.querySelectorAll('.delete-passkey-button');
    const csrfToken = document.getElementById('csrf-token').value;
    
    // Show checking message
    compatibilityCheck.style.display = 'block';
    
    // Check if WebAuthn is supported by the browser
    isPlatformAuthenticatorAvailable()
    .then(function(supported) {
        // Hide checking message
        compatibilityCheck.style.display = 'none';
        
        // The check was simplified in passkey-client.js to reduce false negatives,
        // so we should always show the passkey content if we make it here
        passkeyContent.style.display = 'block';
            
        // Register button click handler
        registerButton.addEventListener('click', function() {
            const deviceName = passkeyNameInput.value.trim();
            if (!deviceName) {
                showStatus('error', '{lang_error_passkey_name_required}');
                return;
            }
            
            registerButton.disabled = true;
            showStatus('info', '{lang_registering_passkey}');
            
            // Add CSRF token to requests
            registerPasskey(
                '{username}', 
                '{webserver_url}/passkey/register/options?csrf_token=' + encodeURIComponent(csrfToken), 
                '{webserver_url}/passkey/register/verify?device_name=' + encodeURIComponent(deviceName) + '&csrf_token=' + encodeURIComponent(csrfToken)
            ).then(function(success) {
                if (success) {
                    showStatus('success', '{lang_passkey_registered_success}');
                    passkeyNameInput.value = '';
                    // Refresh the page to show the new passkey
                    setTimeout(function() { window.location.reload(); }, 1500);
                } else {
                    showStatus('error', '{lang_passkey_registration_failed}');
                }
                registerButton.disabled = false;
            }).catch(function(error) {
                showStatus('error', '{lang_passkey_registration_error}: ' + error.message);
                registerButton.disabled = false;
            });
        });
        
        // Delete button click handlers
        deleteButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                if (confirm('{lang_confirm_delete_passkey}')) {
                    var credentialId = this.getAttribute('data-credential-id');
                    
                    fetch('{webserver_url}/passkey/delete?csrf_token=' + encodeURIComponent(csrfToken), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({ 
                            credential_id: credentialId
                        }),
                        credentials: 'same-origin' // Important: ensure cookies are sent
                    }).then(function(response) {
                        if (response.ok) {
                            window.location.reload();
                        } else {
                            response.json().then(function(data) {
                                alert(data.message || '{lang_delete_failed}');
                            }).catch(function() {
                                alert('{lang_delete_error}');
                            });
                        }
                    }).catch(function() {
                        alert('{lang_delete_error}');
                    });
                }
            });
        });
    })
    .catch(function(error) {
        // Hide checking message
        compatibilityCheck.style.display = 'none';
        console.error('Error checking WebAuthn support:', error);
        
        // Even if there was an error in the check, we'll try to show the UI anyway
        // as many browsers will still work with passkeys despite errors in detection
        passkeyContent.style.display = 'block';
    });
    
    // Function to show status messages with proper sanitization
    function showStatus(type, message) {
        registrationStatus.textContent = '';
        registrationStatus.style.display = 'block';
        registrationStatus.className = 'alert mt-3';
        
        // Sanitize message by using textContent instead of innerHTML
        const sanitizedMessage = document.createTextNode(message);
        
        if (type === 'success') {
            registrationStatus.classList.add('alert-success');
        } else if (type === 'error') {
            registrationStatus.classList.add('alert-danger');
        } else {
            registrationStatus.classList.add('alert-info');
        }
        
        registrationStatus.appendChild(sanitizedMessage);
        
        // Auto-hide after 10 seconds for non-error messages
        if (type !== 'error') {
            setTimeout(function() {
                registrationStatus.style.display = 'none';
            }, 10000);
        }
    }
    
    // Initialize activity timeout for security
    let inactivityTimeout;
    const resetInactivityTimer = function() {
        clearTimeout(inactivityTimeout);
        inactivityTimeout = setTimeout(function() {
            // Show warning that the session might expire
            if (confirm('Your session has been inactive. Would you like to refresh the page?')) {
                window.location.reload();
            }
        }, 15 * 60 * 1000); // 15 minutes
    };
    
    // Set up inactivity detection
    ['mousedown', 'keypress', 'scroll', 'touchstart'].forEach(function(event) {
        document.addEventListener(event, resetInactivityTimer, true);
    });
    
    // Initial timer start
    resetInactivityTimer();
});
</script>