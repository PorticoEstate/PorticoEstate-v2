# Local IMAP and POP test mailboxes

This fixture uses an isolated GreenMail IMAP server and seeds its disposable `INBOX` with 20 generated messages. It does not contact Outlook or any other live mail server.

An independent optional POP server uses the same dataset and loader. See [POP testing](#pop-testing) below. Neither server simulates OAuth.

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

The PEEK flow verifies that whole-body and MIME-section reads preserve unread state with FT_PEEK and mark Seen without it, including refresh of prefetched header flags.

`GREENMAIL_TEST=1 vendor/bin/phpunit tests/services/EmailMessageContentTest.php` additionally creates an isolated draft with an attachment, fetches its MIME structure by UID, passes it through the legacy part classifier and message JSON serializer, and verifies nonempty body text, attachment metadata, and unchanged unread state. The temporary folder is removed afterward. Structure-only FETCH parsing handles quoted charset/filename values immediately followed by closing parentheses without downloading the entire message.

The search-criteria flow uses the application's criteria builder for combined body/subject/flag/date filtering, verifies nonmatching body/date restrictions, and checks explicit rejection of unsupported criteria. The wrapper supports the application's conjunction-based search fields, not arbitrary IMAP boolean/group expressions.

## Configure the email client

Create an active standard IMAP emailadmin profile assigned to the test user or `felamimail` application. For the app running in the `portico_internal` Docker network, set server `imap-test`, port `3993`, SSL, and disable certificate verification for this local test profile. For a PHP app running directly on the host, use `127.0.0.1`, port `1993`. Use IMAP login `outlook-test`, email address `outlook-test@outlook.test`, and the test password above. This fixture exercises password-based IMAP behavior; it does not simulate Microsoft OAuth.

Stop only the optional IMAP fixture with:

```sh
docker compose --profile email-test stop imap-test-seed imap-test
```

The service is opt-in and does not start with the normal application stack.

## POP testing

### Start and seed

```sh
docker compose --profile email-test up -d pop-test-seed
docker compose --profile email-test logs pop-test-seed
```

This starts the separate `portico_pop_test` GreenMail container and a one-shot seeder. The seeder waits up to 60 seconds for the internal IMAPS listener and loads 20 messages only if INBOX is empty. POP has no APPEND command, so IMAPS is used only to load the mailbox; test clients retrieve the same messages through POP3 or POP3S. The seed IMAPS port is not published to the host.

| Setting | Value |
| --- | --- |
| Username | `pop-test` |
| Password | `local-test-password` |
| Email address | `pop-test@pop.test` |
| POP3 from the host | `127.0.0.1:1110` |
| POP3S from the host | `127.0.0.1:1995` |
| POP3 from the app container | `pop-test:3110` |
| POP3S from the app container | `pop-test:3995` |
| Internal IMAPS for seeding | `pop-test:3993` |

The published POP ports bind to loopback only. These credentials and the self-signed TLS certificate are for local testing, not production.

The POP fixture is isolated from `imap-test`: seeding or deleting its messages does not affect the IMAP fixture. It reuses the IMAP-named message subjects and dataset, with the recipient changed to `pop-test@pop.test`. There are plain/HTML messages, CC recipients, a subjectless message, and text attachments on messages 5, 12, and 19. IMAP Seen flags are not POP read/unread state.

### Configure the Email application

Create and enable an extra email account, or enable custom settings on the main account. Set **Email Account Name** to `pop-test`, **Email address** to `pop-test@pop.test`, authentication to **Password**, and the password shown above. When PHP runs in Docker, use **Mail Server** `pop-test` with **POP-3** and port `3110`, or **POP-3S** and port `3995`. When PHP runs on the host, use `127.0.0.1` with ports `1110` or `1995` respectively.

Set the port explicitly: the application's standard POP defaults are 110/995, not GreenMail's test ports. The current POP transport requires the native PHP IMAP extension; the Python checks below can verify GreenMail independently of that extension. POP3S uses the fixture's self-signed certificate; certificate-validation exceptions must remain restricted to this local test server.

There is no POP-specific emailadmin server preset. Do not select Microsoft or Google OAuth. This fixture tests the user Email POP settings and native POP transport, not cloud authentication or SMTP sending.

The app exposes one remote INBOX for POP, not a local downloaded mailbox. Do not expect IMAP Sent/Drafts/Trash folders. Explicit deletion can permanently remove messages from this disposable server.

### Read-only protocol check

From the host, verify both published ports with Python's standard library:

```sh
python3 - <<'PY'
import poplib
import ssl
from email import policy
from email.parser import BytesParser

for secure, port in [(False, 1110), (True, 1995)]:
    server = (poplib.POP3_SSL("127.0.0.1", port, timeout=10,
                             context=ssl._create_unverified_context())
              if secure else poplib.POP3("127.0.0.1", port, timeout=10))
    try:
        server.user("pop-test")
        server.pass_("local-test-password")
        count, size = server.stat()
        assert count > 0, "Mailbox is empty; run the seeder"
        response, entries, octets = server.list()
        assert len(entries) == count
        response, lines, octets = server.retr(1)
        message = BytesParser(policy=policy.default).parsebytes(b"\r\n".join(lines))
        assert message["To"] == "pop-test@pop.test"
        print(f"{'POP3S' if secure else 'POP3'}: {count} messages, first subject: {message['Subject']}")
    finally:
        server.quit()
PY
```

A newly seeded mailbox has 20 messages. This check issues no DELE commands and does not remove messages. Verify attachment download in the app using fixture message 5, 12, or 19. The IMAP regression tests above still target `imap-test`; they do not exercise POP automatically.

Run the native POP header regression from the application container:

```sh
docker exec -e XDEBUG_MODE=off -e GREENMAIL_POP_TEST=1 -e POP_TEST_HOST=pop-test -e POP_TEST_PORT=3995 portico_api \
  vendor/bin/phpunit --filter testGreenMailPopHeadersContainMailboxMetadata tests/services/EmailTransportFactoryTest.php
```

This verifies subject, sender, date, size, and the intentionally subjectless message through the actual POP transport. It uses `imap_headerinfo()` rather than the removed `imap_header()` alias. From the host, use `GREENMAIL_POP_TEST=1 vendor/bin/phpunit --filter testGreenMailPopHeadersContainMailboxMetadata tests/services/EmailTransportFactoryTest.php` when the native IMAP extension is installed. The inbox currently skips POP attachment detection to avoid downloading every message; check attachments by opening the message rather than relying on its inbox attachment flag.

GreenMail 2.1.2 can leave Python `poplib` command responses misaligned after `RSET`. For deletion-cancellation tests, issue `RSET`, disconnect, and verify the count on a new connection rather than relying on same-session STAT/LIST responses. Do not mistake this fixture limitation for production POP behavior.

### Reseed and reset

Rerun the normal `up -d pop-test-seed` command to seed only an empty mailbox. If even one message remains, `--if-empty` skips seeding; it does not restore partially deleted mailboxes.

To intentionally reset just the POP mailbox to 20 messages, deleting its current contents:

```sh
docker compose --profile email-test run --rm pop-test-seed \
  python3 /fixtures/seed_mailbox.py --host pop-test --port 3993 \
  --user pop-test --address pop-test@pop.test --wait-seconds 60
```

To validate generation without connecting to a server:

```sh
python3 tests/fixtures/imap/seed_mailbox.py --address pop-test@pop.test --dry-run
```

The server has no persistent mailbox volume; do not rely on messages surviving server restart or container recreation. A standalone `docker restart portico_pop_test` does not run the Compose seeder; rerun the normal startup command afterward.

Stop only the POP fixture, leaving the application and IMAP fixture running:

```sh
docker compose --profile email-test stop pop-test-seed pop-test
```

Avoid `docker compose down` when you only intend to stop a mail fixture: it also tears down the other services in this Compose project.
