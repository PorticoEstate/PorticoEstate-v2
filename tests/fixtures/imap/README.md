# Local IMAP test mailbox

This fixture uses an isolated GreenMail IMAP server and seeds its disposable `INBOX` with 20 generated messages. It does not contact Outlook or any other live mail server.

## Start the server and load messages

From the repository root, start the optional service:

```sh
docker compose --profile email-test up -d imap-test-seed
```

This starts GreenMail and a one-shot Python seeder. The seeder waits up to 60 seconds for IMAPS and appends 20 messages only when INBOX is empty. Existing messages and flags are preserved. Starting the whole stack with `docker compose --profile email-test up -d` also includes the seeder. Starting only `imap-test` does not start its dependent seeder; use the command above to include both.

The seeder runs again on subsequent Compose starts and when Compose explicitly restarts its GreenMail dependency. A standalone `docker restart portico_imap_test` does not trigger it; rerun the startup command above after that. GreenMail has no persistent mailbox volume, so recreating its container loses its messages.

The loader disables certificate verification only for this local test endpoint. Use IMAP login `outlook-test` with password `local-test-password`; the email address is `outlook-test@outlook.test`. Use these credentials only against this test service.

To explicitly reset INBOX to the fixture, clearing its existing messages:

```sh
python3 tests/fixtures/imap/seed_mailbox.py
```

To seed an empty INBOX manually without clearing existing messages:

```sh
python3 tests/fixtures/imap/seed_mailbox.py --if-empty
```

To validate the message dataset without a running server:

```sh
python3 tests/fixtures/imap/seed_mailbox.py --dry-run
```

## Append regression test

With GreenMail running on the published local IMAPS port `1993`, run:

```sh
GREENMAIL_TEST=1 vendor/bin/phpunit tests/services/EmailTransportFactoryTest.php
```

The opt-in live test uses the disposable credentials above, creates a uniquely named folder, checks its empty message count, appends and reads back a message, checks the populated count and append rejection for a nonexistent folder, and removes its test folder in cleanup. It does not modify the seeded INBOX messages. Certificate verification is disabled only for this local GreenMail connection. Without `GREENMAIL_TEST=1`, this live test is skipped; factory and backend-result regressions still run.

The same command also runs isolated-folder flows for flag set/clear, UID copy/move, mark-deleted followed by explicit expunge, and persistence across close/reconnect. Copy/move checks source and destination counts and destination content. Reconnect selects INBOX before disconnecting: GreenMail 2.1.2 can otherwise retain a stale selected-folder listener and throw `NullPointerException` during subsequent folder deletion. The test does not restart GreenMail or clear INBOX to work around server failures.

The metadata flow expunges an initial message so UIDs differ from sequence numbers, then checks search identifier modes, UID/sequence header-cache isolation, refreshed flags after mutation, and header isolation when switching mailboxes.

The range-deletion flow verifies combined sequence ranges/lists, UID ranges after sequence renumbering, mark-only deletion before explicit expunge, and the `empty_trash()` wildcard range over multiple messages.

The missing-mailbox flow verifies that opening a nonexistent folder fails without publishing a connection handle and leaves an existing session usable.

The subscription flow subscribes and unsubscribes its temporary mailbox and uses independent LSUB commands to verify actual server state after each operation.

The listing flow creates child/grandchild mailboxes using the server's delimiter, verifies single-level `%` and recursive `*` matching plus nonempty reference paths, and removes children before their parents during cleanup.

The close-expunge flow verifies that ordinary logout preserves deletion marks and that closing with CL_EXPUNGE removes marked messages. The wrapper uses IMAP CLOSE to expunge and deselect before logout.

## Configure the email client

Create an active standard IMAP emailadmin profile assigned to the test user or `felamimail` application. For the app running in the `portico_internal` Docker network, set server `imap-test`, port `3993`, SSL, and disable certificate verification for this local test profile. For a PHP app running directly on the host, use `127.0.0.1`, port `1993`. Use IMAP login `outlook-test`, email address `outlook-test@outlook.test`, and the test password above. This fixture exercises password-based IMAP behavior; it does not simulate Microsoft OAuth.

Stop the optional server with:

```sh
docker compose --profile email-test down
```

The service is opt-in and does not start with the normal application stack.