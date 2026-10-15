# Outlook IMAP OAuth2

Email can connect to Microsoft 365 or Outlook.com mailboxes over IMAP using delegated OAuth2 and XOAUTH2. Password authentication remains the default; users explicitly connect each account from its email preferences.

## Microsoft application setup

Register an application in Microsoft Entra ID and add a **Web** redirect URI that exactly matches `EMAIL_MICROSOFT_REDIRECT_URI`. The callback path is `/email/oauth/microsoft/callback` under the application's configured base path (for example, `/portico/email/oauth/microsoft/callback`). Add the delegated `IMAP.AccessAsUser.All` permission and grant consent as required by the tenant. The authorization flow also requests `offline_access`, `openid`, and `profile` so the application can refresh tokens.

Configure these environment variables in the PHP web runtime:

| Variable | Purpose |
| --- | --- |
| `EMAIL_MICROSOFT_TENANT_ID` | Entra tenant ID or `common` (default: `common`) |
| `EMAIL_MICROSOFT_CLIENT_ID` | Application (client) ID |
| `EMAIL_MICROSOFT_CLIENT_SECRET` | Client secret for the registered web application |
| `EMAIL_MICROSOFT_REDIRECT_URI` | Exact registered callback URI, including the deployment base path |

Restart the PHP application after changing the environment. The application must have outbound HTTPS access to `login.microsoftonline.com` and a working cURL/OpenSSL PHP runtime.

## Connect a mailbox

Set the account's IMAP server type and full email address, then use **Connect Microsoft Outlook with OAuth2** in the account preferences and complete Microsoft consent. Use the Outlook IMAP endpoint with TLS, normally `outlook.office365.com` on port `993` for IMAPS. OAuth refresh tokens are encrypted using the existing email password encryption and stored in that account's password preference; access tokens are obtained when connecting and are not persisted.

This covers IMAP retrieval only. SMTP sending still uses the existing SMTP authentication configuration and does not gain OAuth2 from connecting IMAP.