# Integrate Azure AD Single Sign-On (SSO) with PHP Application

To integrate Azure AD Single Sign-On (SSO) with your PHP application using the `jumbojett/OpenID-Connect-PHP` library, follow these steps:

## Install the jumbojett/OpenID-Connect-PHP Library

Install the library using Composer. If you don't have Composer installed, download it from [getcomposer.org](https://getcomposer.org/).

```sh
$ composer require jumbojett/openid-connect-php
```

## Configure Azure AD

1. Go to the Azure portal and register a new application in Azure AD.
2. Note down the Client ID, Tenant ID, and Client Secret.
3. Configure the redirect URI to point to your PHP application (e.g., `https://yourdomain.com/callback.php`).

## Create a Configuration File

Create a configuration file (e.g., `config.php`) to store your Azure AD credentials.

```php
<?php
// filepath: /var/www/html/config/config.php
return [
    'client_id' => 'YOUR_CLIENT_ID',
    'client_secret' => 'YOUR_CLIENT_SECRET',
    'redirect_uri' => 'https://yourdomain.com/callback.php',
    'authority' => 'https://login.microsoftonline.com/YOUR_TENANT_ID/v2.0',
    'scopes' => 'openid profile email'
];
```

## Create a Login Script

Create a login script (e.g., `login.php`) to initiate the authentication process.

```php
<?php
// filepath: /var/www/html/login.php
require 'vendor/autoload.php';
$config = require 'config/config.php';

use Jumbojett\OpenIDConnectClient;

$oidc = new OpenIDConnectClient(
    $config['authority'],
    $config['client_id'],
    $config['client_secret']
);

$oidc->setRedirectURL($config['redirect_uri']);
$oidc->addScope($config['scopes']);
$oidc->authenticate();
```

## Create a Callback Script

Create a callback script (e.g., `callback.php`) to handle the response from Azure AD.

```php
<?php
// filepath: /var/www/html/callback.php
require 'vendor/autoload.php';
$config = require 'config/config.php';

use Jumbojett\OpenIDConnectClient;

$oidc = new OpenIDConnectClient(
    $config['authority'],
    $config['client_id'],
    $config['client_secret']
);

$oidc->setRedirectURL($config['redirect_uri']);
$oidc->addScope($config['scopes']);
$oidc->authenticate();

$userInfo = $oidc->requestUserInfo();

// You can now use $userInfo to get user details
// For example, to get the username:
$username = $userInfo->preferred_username;

// Redirect to your application with the username
header('Location: /your-application.php?username=' . urlencode($username));
exit;
```

## Update Your Application to Handle the Username

Update your application to handle the username passed in the query string.

```php
<?php
// filepath: /var/www/html/your-application.php
session_start();

if (isset($_GET['username'])) {
    $_SESSION['username'] = $_GET['username'];
}

if (!isset($_SESSION['username'])) {
    header('Location: /login.php');
    exit;
}

// Your application logic here
echo 'Welcome, ' . htmlspecialchars($_SESSION['username']);
```

By following these steps, you can integrate Azure AD Single Sign-On (SSO) with your PHP application using the `jumbojett/OpenID-Connect-PHP` library. This setup will allow users to authenticate with Azure AD and access your application.