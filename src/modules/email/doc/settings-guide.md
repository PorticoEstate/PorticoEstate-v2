# Email Settings Guide

This guide describes the current `emailadmin` and `email` modules. It separates
mailbox access, OAuth application credentials, and outgoing mail configuration.
Provider-specific profile choices are presets, not a complete mailbox connection.

## 1. Centralized and Personal Configuration

| Term | Meaning |
| --- | --- |
| Centralized configuration | An administrator supplies shared connection settings and, for OAuth, application credentials through an emailadmin profile. |
| Personal configuration | A user supplies mailbox-specific settings and optionally their own OAuth application credentials in Email Preferences. |
| Main account | Account `0`, configured on the main Email Preferences page. It can use server defaults or custom settings. |
| Extra account | A separately configured mailbox belonging to the logged-in user, numbered `1` or higher. It must be saved and enabled. |
| Personal Microsoft account | A consumer identity such as an Outlook.com or Hotmail account. This is different from the application's main/extra account distinction. |

These are independent choices. A personal Outlook.com mailbox can use centrally
managed OAuth credentials. A work mailbox can be an extra account. An extra
account does not require the user to register a separate OAuth application.

Centralized OAuth credentials identify the application, not the mailbox. Each
user must sign in to the provider and authorize their mailbox. Refresh tokens
are stored with that user's selected account, not in the shared profile.
Centralized configuration does not automatically grant access to all mailboxes
and does not implement unattended application-only mailbox access.

### Recommended Variants

| Scenario | Administrator supplies | User supplies |
| --- | --- | --- |
| Organization IMAP with password authentication | Server defaults or profile, port, login convention, SMTP relay | Mailbox username/password when different from the application login |
| Organization Outlook/Microsoft 365 OAuth | Microsoft app registration, client ID/secret, tenant, callback, Outlook connection information | Full mailbox address, Microsoft OAuth selection, mailbox settings, individual consent |
| Personal Outlook extra account using a shared app | App registration supporting personal accounts and a suitable tenant | Enabled extra account, full address, Microsoft OAuth, IMAPS connection settings, consent |
| Personal Outlook extra account using its own app | A usable assigned profile; a callback override only if needed | The preceding settings plus Microsoft client ID, secret value, and tenant |
| Gmail using a shared app | Google Web application client ID/secret and callback | Full Gmail address, Google OAuth selection, saved account, consent |
| Gmail using its own app | A usable assigned profile; a callback override only if needed | The preceding settings plus Google client ID and secret |

## 2. Emailadmin Profiles

### Scope and Inheritance

Profiles can apply globally or to an application, group, or user. For the Email
module, choose application `email` when a profile should be application-specific.
Keep the profile active.

The resolver starts with matching base settings, then merges application,
primary-group, and user settings in that order. Later nonempty values can
override earlier values; blank fields generally do not erase inherited values.
Profile order matters when multiple profiles match the same lookup. Avoid
ambiguous competing profiles rather than relying on their names to select one.

All extra accounts use the logged-in user's resolved `email` profile. There is
no per-extra-account profile selector. Per-account OAuth credentials and ports
can override the relevant profile values.

A broadly scoped default profile also has a legacy path for updating global
server defaults. A scoped profile is not a guarantee that its mailbox hostname,
protocol, and login name appear automatically in the main Email Preferences
form. Check those values explicitly, particularly when adding an extra account.

### Global Options

| Setting | Purpose |
| --- | --- |
| Profile Name | Administrative description; it does not select a mailbox or provider by itself. |
| Default domain (`defaultDomain`) | Domain used by applicable login/address conventions. Not a provider tenant ID. |
| Organisation name | Organization information for supported identities. |
| Can be used by application/group/user | Profile scope. A user-specific profile is still administrator-managed configuration. |
| Profile is active (`ea_active`) | Activate a profile intended for use. |
| Users can define their own identities | Exposes a legacy policy value to consumers of the emailadmin profile. |
| Users can define their own email accounts | Exposes a legacy account policy value; do not assume every Email REST operation enforces it. |
| Users can define their own signatures / Default signature | Legacy profile signature policy/default, distinct from a mailbox's saved `email_sig`. |

### Incoming Server Fields

| Field | Meaning |
| --- | --- |
| IMAP server (`imapServer`) | Incoming hostname, without a URL path. |
| IMAP port (`imapPort`) | Profile incoming port. An explicit mailbox port takes priority. |
| Login type (`imapLoginType`) | Username, Username@domain, or Admin credentials in the profile editor. Use the actual full mailbox address for cloud OAuth. |
| Connection encryption (`imapTLSEncryption`) | Profile choices: None (`0`), STARTTLS (`1`), TLS (`2`), SSL (`3`). These are profile-adapter choices, not the user's `imap_auth_type`. |
| Do not validate certificate (`imapTLSAuthentication` control) | Legacy certificate-validation control. Avoid bypassing validation; see implementation limitations below. |
| Legacy IMAP client (`imapoldcclient`) | Legacy adapter option. OAuth connections require the modern XOAUTH2-capable path. |
| IMAP username/password (`imapAuthUsername`, `imapAuthPassword`) | Used by profile adapters with Admin credentials login. These are not OAuth client credentials or user refresh tokens. |

### Incoming Server Types in Emailadmin

| Type | Additional settings and intended use |
| --- | --- |
| Standard IMAP (`2`) | Normal IMAP service with the common incoming fields above. |
| Cyrus IMAP (`3`) | Common fields plus Cyrus administration credentials and optional Sieve server/port. Use administration features only with a compatible server. |
| DBMail, qmailUser schema (`4`) | DBMail adapter with optional Sieve configuration. |
| Plesk IMAP, Courier (`5`) | Plesk/Courier administration adapter using the common fields. |
| DBMail, dbmailUser schema (`6`) | DBMail adapter for the alternate directory schema, with optional Sieve. |
| Microsoft Outlook IMAP (`7`) | Preset for `outlook.office365.com`, port `993`, encrypted IMAP; exposes Microsoft OAuth profile fields. |
| Google Gmail OAuth IMAP (`8`) | Preset for `imap.gmail.com`, port `993`, encrypted IMAP; exposes Google OAuth profile fields. |

The old POP3 profile entry is not offered by this profile catalog. POP3/POP3S
remain choices in the user's Email server-type field. Choosing Microsoft or
Google in emailadmin does not authorize users or change every saved account's
authentication method. Existing field values can remain when switching a
profile's server type; verify the hostname, port, and encryption after saving.

For Cyrus/DBMail, Sieve fields are `imapEnableSieve`, `imapSieveServer`, and
`imapSievePort`; the editor defaults the port to `2000`. Use the actual port of
the deployed Sieve service. Cyrus administration adds `imapEnableCyrusAdmin`,
`imapAdminUsername`, and `imapAdminPW`.

### Outgoing Server Types in Emailadmin

| Type | Additional settings and intended use |
| --- | --- |
| Standard SMTP (`1`) | SMTP host/port, authentication checkbox, optional SMTP username/password. |
| Postfix, qmail schema (`2`) | SMTP fields plus LDAP connection/default fields and forwarding-address administration. |
| Postfix, inetOrgPerson schema (`3`) | SMTP fields with the inetOrgPerson administration adapter. |
| Plesk SMTP, Qmail (`4`) | SMTP fields and forwarding-address administration. |
| Postfix, dbmail schema (`5`) | SMTP fields plus LDAP connection/default fields and forwarding-address administration. |

Common fields are `smtpServer`, `smtpPort`, `smtpAuth`,
`ea_smtp_auth_username`, and `ea_smtp_auth_password`. Directory-backed types
can additionally expose `smtpLDAPServer`, `smtpLDAPAdminDN`, `smtpLDAPAdminPW`,
`smtpLDAPBaseDN`, `smtpLDAPUseDefault`, and `editforwardingaddress`.
These directory/schema selections configure administration integration; they
are not SMTP OAuth modes and do not select a Gmail or Microsoft user identity.

### Administrative Mailbox Settings

The emailadmin user editor exposes Email address, alternate addresses, forwarding
addresses, quota in MB, Email account active, and Forward only. These configure
the administrative backend selected by the profile; support depends on that
backend. They are not the Email extra-account editor and do not grant OAuth
consent. Do not expect this editor to provision or change a cloud mailbox unless
the selected backend actually implements that operation.

## 3. User Email Settings

### Main Account Versus Extra Accounts

For account `0`, enable **Use custom settings** (`use_custom_settings`) before saving custom connection
or OAuth values. Saving with it disabled removes the custom fields, including
the saved mailbox credential/token and per-account OAuth credentials. It is not
just a temporary visibility switch.

Extra accounts have their own settings and do not use that master switch. Enable
**Enable this email account** (`ex_account_enabled`), enter the account details, save, and reopen the
account before starting OAuth. Connecting OAuth does not automatically enable a
disabled extra account. Disabled accounts are not offered as inbox destinations.

### Connection and Identity Fields

| Label / key | Required value or behavior |
| --- | --- |
| Account Name / `account_name` | Friendly local label; not the server login. |
| Your full name / `fullname` | Sender display name for extra accounts. The main account normally obtains it from the application user. |
| Email Account Name / `userid` | Server login. For Microsoft/Google use the full mailbox email address. Save it before starting OAuth. |
| Email Password / `passwd` | Mailbox password for password authentication. With OAuth this storage holds an encrypted refresh token: leave the field blank; use Connect to authorize. |
| IMAP authentication / `imap_auth_type` | `password`, `microsoft_oauth`, or `google_oauth`. OAuth is supported for IMAP/IMAPS, not POP3. |
| Email address / `address` | From address; normally the same mailbox or a provider-authorized alias. Does not change the login or grant permission to send as another address. |
| Mail Server / `mail_server` | Incoming hostname. Confirm it for each account rather than assuming a scoped profile supplies it. |
| Mail Server type / `mail_server_type` | `imap`, `imaps`, `pop3`, or `pop3s`; see the protocol table. |
| Mail Server port / `mail_server_port` | Optional explicit port; strongly recommended when an extra account uses a different provider than the assigned profile. |
| IMAP Server Type / `imap_server_type` | Legacy folder conventions: Cyrus/Courier, UWash, or UW-Maildir. Not an authentication provider. |
| U-Wash Mail Folder / `mail_folder` | Legacy UWash folder prefix; leave at the appropriate default unless that server needs a prefix. |

### Protocols, Encryption, and Ports

These are conventional protocol ports, not a guarantee of connectivity or of
every legacy adapter's behavior. Match the configuration to the actual service.

| User server type | Conventional port | Intended connection | Authentication / capabilities |
| --- | --- | --- | --- |
| IMAP (`imap`) | `143` | Non-implicit-TLS IMAP; STARTTLS requires a compatible connection path | Password or configured OAuth; server folders available |
| IMAPS (`imaps`) | `993` | TLS from connection start | Password or Microsoft/Google OAuth; recommended cloud-mail choice |
| POP-3 (`pop3`) | `110` | POP3 download into a durable local INBOX | Password; local read/deletion state, no server folder tree or IMAP Sent/Drafts/Trash |
| POP-3S (`pop3s`) | `995` | TLS from connection start | Password; same folder limitations as POP3 |

Incoming port selection is: explicit account port, then resolved profile
`imapPort` for IMAP/IMAPS, then the protocol default above. POP3/POP3S do not
inherit the profile IMAP port. A wrong profile port can therefore affect an
extra IMAP account whose port is blank; set `993` explicitly for cloud IMAPS.

The Email module's modern mailbox wrapper does not implement a general mapping
of every emailadmin encryption choice into STARTTLS/plaintext behavior. Do not
treat selecting profile STARTTLS as proof that the Email runtime uses STARTTLS.
For nonstandard plaintext/STARTTLS or POP deployments, test the actual adapter;
IMAPS on `993` is the documented Microsoft/Google setup.

### Durable Local POP Mailboxes

Starting with Email version `0.9.17.502`, POP3/POP3S accounts import complete MIME messages into `phpgw_email_pop_messages`. Run the Email setup upgrade before using POP with this version; runtime code does not create database tables. The unused `phpgw_anglemail` cache table is not reused or modified.

Each mailbox is scoped by application user, mail-account number, and a hash of the POP hostname, port, and login name. POP UIDLs identify remote messages, while stable local message IDs identify messages in the application. Changing the server, port, or login name selects a different local mailbox; old data is retained but is not shown under the new identity. A password change alone does not change the mailbox identity.

Opening the account synchronizes messages not already represented by a UIDL. Complete headers, bodies, and attachments are stored together as base64-encoded MIME in the database, with a SHA-256 integrity digest and read-after-write verification. Base64 is a storage encoding, not encryption. Protect the database and backups as mailbox content. A single import is limited to 100 MB per message and 1 MB per response line; larger messages remain on the server and produce a sync warning. The first import of a large mailbox can take multiple requests if interrupted; already saved messages are not downloaded again.

The synchronizer never issues remote DELE commands. Imported messages remain available locally if the remote server later removes them. Read/unread changes are local and persistent, and the inbox can inspect stored MIME for attachment indicators without downloading messages again. If a subsequent network/authentication sync fails, stored messages remain readable and the folder pane shows a sync warning. Successful initial synchronization still requires valid POP credentials and a server with working UIDL support.

Deleting or moving a local POP message out of INBOX hides its local copy while retaining its UIDL tombstone and MIME. This prevents the next sync from downloading it again and does not delete the server copy. No permanent purge, retention job, remote-delete option, or recovery UI is implemented. Database growth therefore includes hidden messages; maintain backups and plan retention separately. Deleting an account in preferences does not purge its stored messages. Never delete this table as a cache-cleaning operation.

Local POP currently provides one INBOX, not local Sent/Drafts/Trash folders. Copying out to an IMAP account uses the existing cross-account message action; importing copies into a POP destination or moving between POP folders is unsupported. Local search supports ALL, SEEN, UNSEEN, DELETED, and UNDELETED; text/date searches are explicitly rejected rather than evaluated against the remote server. Flag state is not synchronized to other POP clients.

### Microsoft and Google Credential Fields

| User key | Meaning |
| --- | --- |
| `microsoft_client_id` | Optional mailbox-specific Microsoft application/client ID. Blank inherits the resolved profile. |
| `microsoft_client_secret` | Optional client secret **value**, not the secret ID. Encrypted and hidden; blank on an edit preserves a saved secret, or inherits the profile if none is saved. |
| `microsoft_tenant_id` | Optional authority override: `consumers`, `common`, `organizations`, or an organization tenant ID. Blank inherits the profile, then defaults to `common`. |
| `google_client_id` | Optional mailbox-specific Google Web application client ID. Blank inherits the profile. |
| `google_client_secret` | Optional Google client secret. Encrypted and hidden; blank preserves a saved secret or falls back to the profile. |

Client IDs and secrets resolve independently. When switching to a different app,
provide both matching values; do not combine a new client ID with an inherited
secret from another app. Changing the app or mailbox requires reconnecting.
An empty secret input does not mean that no secret has been saved.

Redirect URIs are profile-level fields, not per-account preference fields. An
explicit `microsoft_redirect_uri` or `google_redirect_uri` overrides the generated
callback. Otherwise the OAuth controller generates the corresponding callback
from the application URL. Each personal app must register the effective callback.

### Folder and Presentation Preferences

| Settings | Purpose |
| --- | --- |
| `email_sig` | Plain-text signature; the compose workflow does not append it to forwarded messages. |
| `default_sorting`, `layout` | Default newest/oldest order and legacy message-list layout. |
| `icon_theme`, `icon_size`, `button_type` | Legacy appearance preferences; not every modern view uses them. |
| `show_addresses` | Sender-name/address display preference for the main account. |
| `mainscreen_showmail` | Main-account mail summary on the application home page. |
| `use_trash_folder`, `trash_folder_name` | Enable Trash behavior and choose the actual IMAP folder path. |
| `use_sent_folder`, `sent_folder_name` | Save an outgoing copy in the chosen IMAP folder. This is separate from SMTP delivery and provider-side Sent handling. |
| `use_draft_folder`, `draft_folder_name` | Save drafts in the chosen IMAP folder. The legacy schema labels the draft-folder name as "Sent messages folder"; its key is `draft_folder_name`. |
| `enable_utf7` | Legacy folder-name encoding option; do not enable as a connectivity fix. |
| `fwd_inline_text` | Quote the forwarded body inline; the original message is also attached separately. |
| `addressbook_choice`, `js_addressbook_screensize` | Legacy address-book style and window size. |
| `newmsg_combobox`, `show_foldersize` | Legacy new-message count/folder-size display options. |
| `cache_data` | Inactive/deprecated schema preference; not a tuning switch for the current inbox index. |

For Gmail and localized mailboxes, select real server folder paths instead of
assuming that `Sent`, `Trash`, or `Drafts` exists. Gmail may expose paths under
`[Gmail]`, with names depending on the mailbox language. Provider-created Sent
copies and application-created copies can duplicate each other; check before
enabling additional Sent-folder storage.

## 4. Microsoft Outlook and Microsoft 365

### Centralized Microsoft Application Registration

1. Open [Microsoft Entra app registrations](https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade).
2. Register an application with the appropriate supported account types. For personal Outlook.com/Hotmail accounts, it must support personal Microsoft accounts. A work-only registration cannot authorize them.
3. Configure a **Web** redirect URI for the application's `/email/oauth/microsoft/callback` endpoint, including any deployment base path.
4. Configure the delegated Outlook/Exchange IMAP permission `IMAP.AccessAsUser.All`. The application requests `https://outlook.office.com/IMAP.AccessAsUser.All`, `offline_access`, `openid`, and `profile`; this is not the application-only `IMAP.AccessAsApp` flow.
5. Create a client secret and copy its **value** securely. Track its expiry.
6. In emailadmin select Microsoft Outlook IMAP, verify `outlook.office365.com:993` and encryption, and enter `microsoft_client_id`, `microsoft_client_secret`, `microsoft_tenant_id`, and the callback override if needed.
7. Scope the active profile to the intended users/application and verify that it resolves for them.

| Tenant value | Appropriate audience |
| --- | --- |
| `consumers` | Personal Microsoft accounts only |
| `common` | Personal and work/school accounts, if supported by the app registration |
| `organizations` | Work/school accounts only |
| Organization tenant ID/domain | A specific organization's accounts and policies |

The tenant setting cannot expand the app registration's supported account types.
Microsoft 365 administrators may need to enable IMAP, approve consent, or adjust
application-access policies. Personal Outlook users must enable IMAP in their
mailbox settings where required.

### Minimum Main-Account Settings With a Central Profile

1. Enable **Use custom settings**.
2. Save the full mailbox address as **Email Account Name** and the correct **Email address** for sending.
3. Choose **Microsoft Outlook OAuth2**.
4. Verify **Mail Server** `outlook.office365.com` and **Mail Server type** IMAPS. Leave the port blank only if the profile supplies `993`; otherwise enter it.
5. Leave Microsoft client ID, secret, and tenant blank to inherit the profile.
6. Save, reopen the page, choose Connect to Microsoft, and complete sign-in and consent.

No mailbox password is required for this OAuth connection. The callback stores
an encrypted refresh token and selects Microsoft OAuth for that account.

### Personal Extra Account

Create an enabled extra account with its display name, full mailbox username,
From address, Microsoft OAuth, `outlook.office365.com`, IMAPS, and port `993`.
Use inherited app credentials if they support personal accounts. Otherwise enter
the extra account's own client ID/secret and set tenant `consumers`.
Save and reopen before connecting. For a work mailbox use the appropriate tenant
instead. The Connect link is available once credentials are configured; it is not
proof that Microsoft has authorized the mailbox yet.

**Sending limitation:** Microsoft OAuth currently supplies incoming IMAP access.
The module does not configure Microsoft SMTP XOAUTH2 or request `SMTP.Send`.
Successful Microsoft IMAP sign-in does not configure outgoing Outlook SMTP.
Use an authorized application SMTP relay or implement Microsoft SMTP OAuth
separately before relying on sending through that mailbox's own SMTP account.

## 5. Gmail and Google Workspace

### Centralized Google Application Registration

1. Open [Google Auth Platform clients](https://console.cloud.google.com/auth/clients) and select the intended project.
2. Configure consent/audience settings. For an external app in Testing, add the intended Gmail addresses as test users.
3. Create a **Web application** OAuth client.
4. Register the full `/email/oauth/google/callback` URL under **Authorized redirect URIs**, not Authorized JavaScript origins. Browser-only JavaScript origins are not required for this server-side flow.
5. Enter the client ID/secret and optional callback override on the Google Gmail OAuth IMAP profile, and verify `imap.gmail.com`, port `993`, and encrypted IMAP.

The application requests `openid`, `email`, and `https://mail.google.com/`.
The Gmail scope is restricted; public production use can require Google
verification and additional security requirements. External Testing refresh
tokens with Gmail scopes can expire after seven days.

### Main and Extra Accounts

Use the full Gmail address as **Email Account Name** and set the correct From
address. Select **Google Gmail OAuth2**. For the main account enable custom
settings; for an extra account enable the account itself. Use central credentials
or supply a matching per-account Google client ID/secret. Save and reopen before
choosing **Connect Gmail with Google OAuth2**.

The callback saves the refresh token and sets the account's incoming hostname to
`imap.gmail.com` and server type to IMAPS. An existing explicit port is not reset;
verify it is `993`. Workspace administrators may need to allow the application
and IMAP access.

Gmail OAuth also configures sending through `smtp.gmail.com:587`, STARTTLS, and
XOAUTH2 using the connected account's token. It overrides the normal application
SMTP connection for that Gmail account. The From address must be the mailbox or
an authorized send-as identity.

## 6. SMTP and Outgoing Mail

For non-Gmail-OAuth accounts, the current compose workflow constructs its mailer
from application server settings and environment variables, not directly from
each extra account's incoming hostname or its resolved emailadmin SMTP object.
An incoming password, OAuth tenant, or IMAP profile preset does not select SMTP.

| Effective setting | Server-setting key | Environment override |
| --- | --- | --- |
| SMTP hostname | `smtp_server` | `SMTP_HOST` |
| SMTP port | `smtp_port` | `SMTP_PORT` |
| Encryption | `smtpSecure` (`tls` for STARTTLS, `ssl` for implicit TLS) | `SMTP_SECURE` |
| Use authentication | `smtpAuth` (`yes`, `true`, or `1`) | `SMTP_AUTH` |
| SMTP username | `smtpUser` | `SMTP_USER` |
| SMTP password | `smtpPassword` | `SMTP_PASSWORD` |
| Timeout | `smtp_timeout` | `SMTP_TIMEOUT` |

The profile editor defaults SMTP to port `25`. Typical submission ports are
`587` with STARTTLS and `465` with implicit TLS; internal relays may use `25`.
Use the relay's actual security and authentication requirements. Set
`SMTP_SECURE` explicitly for the intended mode rather than inferring it from
the incoming IMAP encryption choice.

Legacy profile-to-global synchronization uses different SMTP names in places,
including `smpt_port`, `smtp_auth_user`, and `smtp_auth_passwd`. Do not assume
that filling emailadmin SMTP fields updates the effective mailer's port or
credentials. Verify the effective keys above and any environment overrides.

Operational overrides include `SMTP_FROM_OVERRIDE` (replace From),
`REDIRECT_EMAIL` (redirect recipients), and the application's disk-capture mode
(capture instead of delivery). Check them before treating a successful send
response as proof of delivery to the intended recipient.

## 7. Callbacks, Proxies, and Network Access

For a local installation under `/portico`, the example callbacks are:

```text
http://127.0.0.1:8088/portico/email/oauth/microsoft/callback
http://127.0.0.1:8088/portico/email/oauth/google/callback
```

Register the actual public URL used by the deployment. Do not add a trailing
`?`, invent a different port, or substitute `localhost` for `127.0.0.1`.
Use HTTPS in production and check each provider's loopback/HTTP registration
restrictions for development. The browser must return to the same application
session, and the callback must be reachable by that browser.

Both OAuth token services use the server's `httpproxy_server` and
`httpproxy_port` when valid. The separately configured port is applied even if
the proxy address contains an embedded port. This is an HTTP proxy connection
using CONNECT for OAuth HTTPS traffic, not a SOCKS proxy configuration.

| Traffic | Destination | Uses this HTTP proxy handling? |
| --- | --- | --- |
| Microsoft authorization/consent | Microsoft sign-in page | Browser networking, not the PHP OAuth proxy helper |
| Google authorization/consent | Google sign-in page | Browser networking, not the PHP OAuth proxy helper |
| Microsoft token exchange/refresh | `login.microsoftonline.com:443` | Yes |
| Google token exchange/refresh | `oauth2.googleapis.com:443` | Yes |
| Google account verification | `openidconnect.googleapis.com:443` | Yes |
| Microsoft IMAPS | `outlook.office365.com:993` | No |
| Gmail IMAPS | `imap.gmail.com:993` | No |
| Gmail SMTP | `smtp.gmail.com:587` | No |
| Application SMTP relay | Configured host/port | No |

The current mail transport does not tunnel IMAP/SMTP through this HTTP proxy.
OAuth consent can succeed while folder loading fails because outbound port
`993` is blocked. Allow direct mailbox/SMTP egress or deploy a separately
supported mail transport solution; changing the OAuth proxy port cannot make
an IMAP connection use it.

## 8. Troubleshooting and Security Notes

| Symptom | Check |
| --- | --- |
| Connect link missing | Save/reopen the account; confirm a complete account or profile client ID/secret. New extra accounts must exist before authorization. |
| Google `redirect_uri_mismatch` | Full URI registered under Authorized redirect URIs; exact host, scheme, port, base path, callback, and profile override. |
| Google `403 access_denied` | Testing audience/test users, production verification, and Workspace policies. |
| Microsoft personal account rejected | App must support personal Microsoft accounts; use `consumers` or a suitable `common` configuration. |
| Microsoft consent denied | Delegated IMAP permission, tenant policies, administrator approval, and supported audience. |
| Token request times out at proxy | Correct proxy host/port and connectivity from the PHP container as well as the host. |
| Consent succeeds but folders fail | Incoming host/port, full username, IMAP enablement, token, tenant, and direct IMAP connectivity. |
| Extra account missing from inbox destinations | Account must be saved, complete, enabled, and present in the preferences account list; reload the inbox after changes. |
| Receiving works, sending fails | Check outgoing relay/Gmail SMTP separately; Microsoft IMAP OAuth does not enable Microsoft SMTP OAuth. |
| Changes appear stale | Reopen the form/request; account preference and session caches are separate from the provider's tokens. Reconnect after changing the OAuth application. |

OAuth callbacks use single-use session state and PKCE. Start a fresh Connect
flow after a failed or expired callback instead of replaying the old URL.
Never share callback authorization codes, refresh/access tokens, client secret
values, or preference dumps containing credentials.

Mailbox credentials and OAuth client secrets use the application's encryption
helpers. Restrict database/configuration access, protect the encryption keys,
and rotate expired application secrets. Avoid entering mailbox passwords in
OAuth accounts; that field stores their refresh token after connection.

TLS encryption and certificate validation are separate. The legacy Email IMAPS
call-string path currently includes `novalidate-cert`, and the generic SMTP
configurator disables peer validation; the Gmail SMTP override explicitly enables
it. Do not claim strict certificate validation merely because IMAPS, SSL, or a
profile validation checkbox is selected. Review the active transport and trust
configuration before production deployment.

### Implementation References

- [Email preference schema](../services/EmailPreferenceSchema.php)
- [Email preference defaults](../services/EmailPreferenceDefaults.php)
- [Incoming port resolution](../services/EmailServerPortResolver.php)
- [Microsoft OAuth service](../services/MicrosoftImapOAuth.php)
- [Google OAuth service](../services/GoogleMailOAuth.php)
- [Outgoing-message configuration](../services/EmailOutgoingMessage.php)
- [Emailadmin profile types and settings](../../emailadmin/inc/class.emailadmin_bo.inc.php)
- [Emailadmin profile resolution](../../emailadmin/inc/class.emailadmin_so.inc.php)
- [Application SMTP configuration](../../phpgwapi/services/PHPMailerSmtpConfigurator.php)
