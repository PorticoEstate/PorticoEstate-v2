#!/usr/bin/env python3
"""Load a deterministic 20-message mailbox into the local GreenMail IMAP server."""

import argparse
import imaplib
import os
import ssl
import sys
import time
from datetime import datetime, timedelta, timezone
from email.message import EmailMessage
from email.policy import SMTP
from email.utils import format_datetime


MESSAGE_COUNT = 20
DEFAULT_USER = "outlook-test"
DEFAULT_ADDRESS = "outlook-test@outlook.test"
DEFAULT_PASSWORD = "local-test-password"


def build_message(number: int, now: datetime, address: str = DEFAULT_ADDRESS) -> tuple[EmailMessage, datetime]:
	date = now - timedelta(days=MESSAGE_COUNT - number, hours=number)
	message = EmailMessage()
	message["From"] = f"Sender {number:02d} <sender{number:02d}@example.test>"
	message["To"] = address
	message["Date"] = format_datetime(date)
	message["Message-ID"] = f"<imap-fixture-{number:02d}@example.test>"
	message["X-Test-Message"] = f"{number:02d}"

	if number != MESSAGE_COUNT:
		message["Subject"] = f"IMAP fixture {number:02d}: {subject_for(number)}"

	plain = f"Message {number:02d} of {MESSAGE_COUNT}. This is a local IMAP behavior fixture."
	message.set_content(plain)
	if number % 3 == 0:
		message.add_alternative(
			f"<html><body><h2>Fixture {number:02d}</h2><p>{plain}</p></body></html>",
			subtype="html",
		)
	if number in {5, 12, 19}:
		message.add_attachment(
			f"Attachment from test message {number:02d}.\n",
			filename=f"fixture-{number:02d}.txt",
			subtype="plain",
	)
	if number in {4, 8, 12, 16, 20}:
		message["Cc"] = f"Copy {number:02d} <copy{number:02d}@example.test>"
	return message, date


def subject_for(number: int) -> str:
	return {
		1: "Unread message",
		2: "Read message",
		3: "HTML and plain text",
		4: "Carbon copy recipient",
		5: "Message with attachment",
		6: "Search term: quarterly report",
		7: "Re: project update",
		8: "Flagged-message candidate",
		9: "Longer content for preview testing",
		10: "Unicode-safe ASCII subject",
		11: "Inbox sorting check",
		12: "HTML, CC, and attachment",
		13: "Search term: invoice 1042",
		14: "Conversation follow-up",
		15: "Plain text only",
		16: "Multiple recipients",
		17: "Another unread message",
		18: "A deliberately long subject line for testing wrapping and clipping in the message list view",
		19: "Attachment filename and download test",
		20: "No subject",
	}[number]


def messages(address: str = DEFAULT_ADDRESS) -> list[tuple[EmailMessage, datetime]]:
	now = datetime.now(timezone.utc).replace(microsecond=0)
	return [build_message(number, now, address) for number in range(1, MESSAGE_COUNT + 1)]


def clear_inbox(server: imaplib.IMAP4) -> None:
	status, _ = server.select("INBOX")
	if status != "OK":
		raise RuntimeError("Unable to select the test INBOX")
	status, result = server.search(None, "ALL")
	if status != "OK":
		raise RuntimeError("Unable to list existing test messages")
	message_ids = result[0].split() if result else []
	for message_id in message_ids:
		status, _ = server.store(message_id, "+FLAGS", r"(\Deleted)")
		if status != "OK":
			raise RuntimeError("Unable to clear the existing test INBOX")
	if message_ids:
		server.expunge()


def load_mailbox(host: str, port: int, username: str, password: str, if_empty: bool = False, wait_seconds: int = 0, address: str = DEFAULT_ADDRESS) -> None:
	deadline = time.monotonic() + wait_seconds
	while True:
		try:
			server = imaplib.IMAP4_SSL(host, port, ssl_context=ssl._create_unverified_context(), timeout=5)
			break
		except (OSError, imaplib.IMAP4.error):
			if time.monotonic() >= deadline:
				raise
			time.sleep(1)
	try:
		status, _ = server.login(username, password)
		if status != "OK":
			raise RuntimeError("IMAP login failed")
		if if_empty:
			status, _ = server.select("INBOX", readonly=True)
			if status != "OK":
				raise RuntimeError("Unable to inspect the test INBOX")
			status, result = server.search(None, "ALL")
			if status != "OK":
				raise RuntimeError("Unable to list existing test messages")
			if result and result[0].split():
				print("INBOX already contains messages; skipping seed.")
				return
		else:
			clear_inbox(server)
		for index, (message, date) in enumerate(messages(address), start=1):
			flags = r"(\Seen)" if index % 2 == 0 else None
			status, _ = server.append(
				"INBOX",
				flags,
				imaplib.Time2Internaldate(date.timestamp()),
				message.as_bytes(policy=SMTP),
			)
			if status != "OK":
				raise RuntimeError(f"Unable to append fixture message {index:02d}")
		status, result = server.search(None, "ALL")
		if status != "OK" or len(result[0].split()) != MESSAGE_COUNT:
			raise RuntimeError("The test INBOX does not contain exactly 20 messages")
		print(f"Loaded {MESSAGE_COUNT} messages into INBOX on {host}:{port} as {username}.")
	finally:
		try:
			server.logout()
		except imaplib.IMAP4.error:
			pass


def main() -> int:
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("--host", default=os.getenv("IMAP_TEST_HOST", "127.0.0.1"))
	parser.add_argument("--port", type=int, default=int(os.getenv("IMAP_TEST_PORT", "1993")))
	parser.add_argument("--user", default=os.getenv("IMAP_TEST_USER", DEFAULT_USER))
	parser.add_argument("--address", default=os.getenv("IMAP_TEST_ADDRESS", DEFAULT_ADDRESS), help="Recipient address for generated fixture messages")
	parser.add_argument("--password", default=os.getenv("IMAP_TEST_PASSWORD", DEFAULT_PASSWORD))
	parser.add_argument("--dry-run", action="store_true", help="Build and summarize messages without connecting")
	parser.add_argument("--if-empty", action="store_true", help="Seed only an empty INBOX; never clear existing messages")
	parser.add_argument("--wait-seconds", type=int, default=0, help="Seconds to wait for the IMAP service to accept connections")
	args = parser.parse_args()

	if args.dry_run:
		fixture_messages = messages(args.address)
		print(f"Generated {len(fixture_messages)} RFC 5322 messages; {sum(bool(message.get_content_maintype() == 'multipart') for message, _ in fixture_messages)} are multipart.")
		return 0

	try:
		load_mailbox(args.host, args.port, args.user, args.password, args.if_empty, args.wait_seconds, args.address)
	except (OSError, imaplib.IMAP4.error, RuntimeError) as error:
		print(f"Could not seed local IMAP mailbox: {error}", file=sys.stderr)
		return 1
	return 0


if __name__ == "__main__":
	raise SystemExit(main())