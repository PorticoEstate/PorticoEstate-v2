# Passkeys

PorticoEstate passkeys use WebAuthn through the `lbuchs/webauthn` PHP library.
Users can register and remove passkeys from **Preferences → Passkey Management**
(`/passkey`). The login page offers passkey sign-in when the deployment is
configured to allow it.

## Requirements

- The browser-facing site must use HTTPS. For local development only, browsers
  permit passkeys over HTTP on `localhost` (including `*.localhost`).
- The browser must support WebAuthn, and the user needs a platform or roaming
  authenticator that supports discoverable credentials and user verification.
- The server must have a non-empty `encryptkey` configured. It is used to
  derive opaque WebAuthn user handles for accounts.
- The browser-visible host and port must match the configured relying-party
  origin. WebAuthn does not use a URL path as part of the origin.

IP addresses are not supported as relying-party IDs, including loopback IPs
such as `127.0.0.1`. Use `http://localhost` for local development instead.

## Configuration behind a reverse proxy

The passkey relying-party ID and origin are derived from administrator
configuration, not from the incoming `Host` header or proxy headers.

Preferred configuration:

- Set `webserver_url` to the complete public URL, for example
  `https://test.aktiv-kommune.no`.
- If `webserver_url` is a relative path (such as `/`) or empty, set `hostname`
  to the public browser-visible domain, optionally including a public port,
  for example `test.aktiv-kommune.no` or `test.aktiv-kommune.no:8443`.
- For a relative `webserver_url`, PorticoEstate uses HTTPS for a domain name.
  HTTP is used only for `localhost`/`*.localhost` when `enforce_ssl` is off.

For TLS termination at a reverse proxy, configure the public HTTPS address even
if the proxy connects to the application container over HTTP. Do not set
`hostname` to a Docker service/container name or internal IP. A wrong or
unsupported configuration makes passkeys unavailable; the management page
displays a configuration notice instead of attempting registration.

## Supported authentication types

Passkeys are enabled when `auth_type` is `sql`, `azure`, `remoteuser`, or
`customsso`. The OpenID Connect backends configured for the latter SSO types do
not disable passkeys.

Passkeys are disabled for directory-backed types such as `ldap`, `ads`, `ntlm`,
`http`, `sqlssl`, `mail`, `exchange`, and `nis`. A passkey signs into the local
PorticoEstate account and does not consult an external directory during sign-in;
allowing it for those auth types could bypass account deactivation in the
directory.

For the allowed auth types, the local PorticoEstate account must still be
active. Disabling an account in an external identity provider does not
automatically deactivate its PorticoEstate account.

## Registration, sign-in, and stored data

- New passkeys are discoverable credentials, with user verification required.
- Registration and sign-in use server-issued, one-time challenges that expire
  after five minutes. Challenges are held in the user's PHP session.
- WebAuthn origins are checked against the configured browser-facing origin.
- Credential public keys, IDs, counters, device labels, and use timestamps are
  stored in the account's `phpgw_accounts_data` JSON data under `passkeys`.
- A credential ID can belong to only one account. Registering an ID that is
  already in use is rejected. If the same ID is somehow stored on several
  accounts, sign-in with it is refused and the conflict is written to the PHP
  error log.
- The suggestion shown after a successful login can be snoozed for 30 days or
  permanently dismissed. Those choices are stored in the user's `phpgw_preferences`
  under the `phpgwapi` preference group.

Older non-discoverable credentials may not work with accountless passkey
sign-in. If an existing credential cannot be used, remove it from Passkey
Management and register it again.

## Troubleshooting

If passkeys are reported unavailable:

1. Check that the site opens in the browser over HTTPS (or `http://localhost`
   for local development).
2. Check `webserver_url`. If it is relative or empty, check that `hostname`
   contains the public domain, not an internal Docker hostname or IP address.
3. If a non-default port is visible in the browser, ensure the configured
   public URL or `hostname` includes that port.
4. Confirm that `encryptkey` is configured and that `auth_type` is one of the
   supported types above.

Changing the public domain changes the relying-party ID and origin. Existing
passkeys registered for another domain will not authenticate at the new domain;
users should register new credentials after the domain migration.
