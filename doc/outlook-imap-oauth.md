# Outlook IMAP OAuth2

Email can connect to Microsoft 365 or Outlook.com mailboxes over IMAP using delegated OAuth2 and XOAUTH2. Password authentication remains the default; users explicitly connect each account from its email preferences.

## Microsoft application setup

Register an application in Microsoft Entra ID and add a **Web** redirect URI matching the address used by this installation. The callback path is `/email/oauth/microsoft/callback` under the application's configured base path (for example, `/portico/email/oauth/microsoft/callback`). Add the delegated `IMAP.AccessAsUser.All` permission and grant consent as required by the tenant. The authorization flow also requests `offline_access`, `openid`, and `profile` so the application can refresh tokens.

In **Email Administration → Profiles**, create or edit an active profile assigned to the `felamimail` application, the mailbox's user/group, or the applicable default profile. Select **Microsoft Outlook IMAP** and fill in the Microsoft tenant ID (or `common`), application client ID, client secret, and exact redirect URI. The client secret is encrypted before it is stored in the profile database, is never returned to the editor, and can be left blank when editing to retain the saved value. The OAuth settings come from the effective profile resolved for the mailbox.

The PHP runtime must have outbound HTTPS access to `login.microsoftonline.com` and a working cURL/OpenSSL runtime.

## Connect a mailbox

Set the account's IMAP server type and full email address, then use **Connect Microsoft Outlook with OAuth2** in the account preferences and complete Microsoft consent. The Outlook profile pre-fills `outlook.office365.com` on port `993` with SSL. OAuth refresh tokens are encrypted using the existing email password encryption and stored in that account's password preference; access tokens are obtained when connecting and are not persisted.

This covers IMAP retrieval only. SMTP sending still uses the existing SMTP authentication configuration and does not gain OAuth2 from connecting IMAP.