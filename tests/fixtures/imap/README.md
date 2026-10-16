# Local IMAP test mailbox

This fixture uses an isolated GreenMail IMAP server and seeds its disposable `INBOX` with 20 generated messages. It does not contact Outlook or any other live mail server.

## Start the server and load messages

From the repository root, start the optional service:

```sh
docker compose --profile email-test up -d imap-test
python3 tests/fixtures/imap/seed_mailbox.py
```

The loader connects over IMAPS, clears only this local test mailbox, then appends the 20 messages. It disables certificate verification only for this local test endpoint. Re-running it resets the inbox to the same fixture. Use IMAP login `outlook-test` with password `local-test-password`; the email address is `outlook-test@outlook.test`. Use these credentials only against this test service.

To validate the message dataset without a running server:

```sh
python3 tests/fixtures/imap/seed_mailbox.py --dry-run
```

## Configure the email client

Create an active standard IMAP emailadmin profile assigned to the test user or `felamimail` application. For the app running in the `portico_internal` Docker network, set server `imap-test`, port `3993`, SSL, and disable certificate verification for this local test profile. For a PHP app running directly on the host, use `127.0.0.1`, port `1993`. Use IMAP login `outlook-test`, email address `outlook-test@outlook.test`, and the test password above. This fixture exercises password-based IMAP behavior; it does not simulate Microsoft OAuth.

Stop the optional server with:

```sh
docker compose --profile email-test down
```

The service is opt-in and does not start with the normal application stack.