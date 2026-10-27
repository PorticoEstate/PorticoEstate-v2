<?php

use App\modules\email\services\PopMailboxSource;
use App\modules\email\services\PopMailboxSourceException;
use App\modules\email\services\PopMessageStore;
use ZBateson\MailMimeParser\Message;

require_once __DIR__ . '/class.mail_dcom_base.inc.php';
require_once __DIR__ . '/ModernIMAPWrapper.php';

final class LocalPopConnection
{
	public function __construct(public mail_dcom_pop3_local $transport) {}
}

class mail_dcom_pop3_local extends mail_dcom_base
{
	public $force_msg_uids = true;
	private int $owner = 0;
	private int $account = 0;
	private ?PopMessageStore $store = null;
	private ?Closure $storeFactory = null;
	private ?Closure $sourceFactory = null;
	private string $lastError = '';
	private bool $opened = false;

	public function __construct(?Closure $storeFactory = null, ?Closure $sourceFactory = null)
	{
		$this->storeFactory = $storeFactory;
		$this->sourceFactory = $sourceFactory;
	}

	public function setAccountContext(int $owner, int $account): void
	{
		$this->owner = $owner;
		$this->account = $account;
	}

	public function open($mailbox, $username, $password, $flags = 0)
	{
		$this->opened = false;
		if (!preg_match('/^\{([^}]+)\}(?:INBOX)?$/iD', $mailbox, $matches)) throw new RuntimeException('POP local storage supports INBOX only');
		$options = explode('/', $matches[1]);
		$address = parse_url('tcp://' . array_shift($options));
		if (!$address || empty($address['host'])) throw new RuntimeException('Invalid POP server address');
		$tls = in_array('ssl', $options, true);
		$host = $address['host'];
		$port = (int) ($address['port'] ?? ($tls ? 995 : 110));
		$key = PopMessageStore::mailboxKey($host, $port, $username);
		$this->store = $this->storeFactory ? ($this->storeFactory)($this->owner, $this->account, $key) : new PopMessageStore($this->owner, $this->account, $key);
		$known = $this->store->uidls();
		$source = null;
		try
		{
			$source = $this->sourceFactory ? ($this->sourceFactory)() : new PopMailboxSource($host, $port, $tls, !in_array('novalidate-cert', $options, true), $username, $password);
			foreach ($source->uidls() as $number => $uidl)
			{
				if (!isset($known[$uidl])) $this->store->save($uidl, $source->retrieve((int) $number));
			}
			$this->lastError = '';
		}
		catch (PopMailboxSourceException $exception)
		{
			$this->lastError = 'POP synchronization failed; showing previously stored messages';
			if (!$known && !$this->store->rows()) return false;
		}
		finally
		{
			if ($source !== null)
			{
				try { $source->close(); } catch (PopMailboxSourceException $exception) { $this->lastError = 'POP synchronization ended without a clean logout'; }
			}
		}
		$this->opened = true;
		return new LocalPopConnection($this);
	}

	public function close($stream, $flags = 0) { $this->opened = false; return true; }
	public function noop_ping_test($stream) { return $this->opened; }
	public function server_last_error() { return $this->lastError; }
	public function num_msg($stream) { return (int) $this->store->stats()['message_count']; }
	public function status($stream, $mailbox = '', $options = 0)
	{
		$stats = $this->store->stats();
		return (object) ['messages' => (int) $stats['message_count'], 'unseen' => (int) $stats['unseen_count'], 'recent' => 0, 'uidnext' => (int) $stats['uidnext'], 'uidvalidity' => 1];
	}
	public function mailboxmsginfo($stream)
	{
		$stats = $this->store->stats();
		return (object) ['Nmsgs' => (int) $stats['message_count'], 'Unread' => (int) $stats['unseen_count'], 'Recent' => 0, 'Size' => (int) $stats['total_size'], 'Date' => '', 'Driver' => 'local-pop', 'Mailbox' => 'INBOX'];
	}

	public function header($stream, $number, $fromlength = 0, $tolength = 0, $defaulthost = '')
	{
		$row = $this->row((int) $number);
		$message = Message::from($this->store->raw((int) $number), true);
		$header = new stdClass();
		foreach (['from', 'to', 'cc', 'bcc', 'reply_to', 'sender', 'return_path'] as $name)
		{
			$value = $message->getHeaderValue(str_replace('_', '-', $name), '');
			$addressHeader = $message->getHeader(str_replace('_', '-', $name));
			$addresses = $addressHeader instanceof \ZBateson\MailMimeParser\Header\AddressHeader
				? array_map(static fn($address) => ['address' => $address->getEmail(), 'name' => $address->getName() ?? ''], $addressHeader->getAddresses())
				: \PHPMailer\PHPMailer\PHPMailer::parseAddresses($value, false, 'UTF-8');
			$header->$name = array_map(static function ($address) {
				[$mailbox, $host] = array_pad(explode('@', $address['address'], 2), 2, '');
				return (object) ['personal' => $address['name'], 'mailbox' => $mailbox, 'host' => $host];
			}, $addresses);
			$header->{$name . 'address'} = $value;
		}
		$header->reply_to = $header->reply_to ?: $header->from;
		$header->subject = $header->Subject = $message->getHeaderValue('Subject', '');
		$header->date = $message->getHeaderValue('Date', '');
		$header->udate = strtotime($header->date) ?: (int) $row['received_at'];
		$header->message_id = $message->getHeaderValue('Message-ID', '');
		$header->Size = $header->size = (int) $row['message_size'];
		$header->Unseen = $row['is_seen'] ? '' : 'U';
		$header->Deleted = $row['is_deleted'] ? 'D' : '';
		foreach (['Recent', 'Answered', 'Flagged', 'Draft'] as $name) $header->$name = '';
		return $header;
	}

	public function fetchheader($stream, $number, $flags = 0) { return explode("\r\n\r\n", $this->store->raw((int) $number), 2)[0] . "\r\n"; }
	public function get_header($stream, $number, $flags = 0) { return $this->fetchheader($stream, $number, $flags); }
	public function get_body($stream, $number, $flags = 0) { return explode("\r\n\r\n", $this->store->raw((int) $number), 2)[1]; }
	public function fetch_raw_mail($stream, $number, $flags = 0) { return $this->store->raw((int) $number); }
	public function fetchstructure($stream, $number, $flags = 0) { return ModernIMAPWrapper::convertMimeStructure(Message::from($this->store->raw((int) $number), true)); }
	public function fetchbody($stream, $number, $section, $flags = 0)
	{
		$part = Message::from($this->store->raw((int) $number), true);
		if (!preg_match('/^[1-9][0-9]*(\.[1-9][0-9]*)*$/D', (string) $section)) throw new RuntimeException('Invalid MIME part');
		foreach (explode('.', (string) $section) as $index)
		{
			$children = $part instanceof \ZBateson\MailMimeParser\Message\IMultiPart ? $part->getChildParts() : [];
			if (!$children && $section === '1') break;
			if (!isset($children[(int) $index - 1])) throw new RuntimeException('MIME part no longer exists');
			$part = $children[(int) $index - 1];
		}
		$data = $part->getBinaryContentStream()?->getContents() ?? '';
		return match (strtolower($part->getContentTransferEncoding() ?? '7bit')) { 'base64' => base64_encode($data), 'quoted-printable' => quoted_printable_encode($data), default => $data };
	}

	public function sort($stream, $criteria = 1, $reverse = false, $flags = 0)
	{
		return $this->store->messageIds((int) $criteria, (int) (bool) $reverse);
	}

	public function page($stream, int $sort, int $order, int $start, int $pageSize): array
	{
		return $this->store->messageIds($sort, $order, $start, $pageSize);
	}

	public function i_search($stream, $criteria, $flags = 0)
	{
		$criteria = strtoupper(trim($criteria));
		if (!in_array($criteria, ['ALL', 'UNSEEN', 'SEEN', 'DELETED', 'UNDELETED'], true)) throw new RuntimeException('This search is not supported by the local POP mailbox');
		return array_map(static fn($row) => (int) $row['message_id'], array_values(array_filter($this->store->rows(), static fn($row) => match ($criteria) { 'UNSEEN' => !$row['is_seen'], 'SEEN' => (bool) $row['is_seen'], 'DELETED' => (bool) $row['is_deleted'], 'UNDELETED' => !$row['is_deleted'], default => true })));
	}

	public function set_flag($stream, $number, $flag) { return $this->flags($number, $flag, true); }
	public function flags($numbers, $flag, bool $enabled)
	{
		if (!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', (string) $numbers)) return false;
		foreach (explode(',', (string) $numbers) as $id) if (!$this->store->flag((int) $id, $flag, $enabled)) return false;
		return true;
	}
	public function delete($stream, $number, $flags = 0) { return $this->flags($number, '\\Deleted', true); }
	public function expunge($stream) { $this->store->expunge(); return true; }
	public function reopen($stream, $mailbox, $flags = 0) { return str_ends_with($mailbox, 'INBOX'); }
	public function listmailbox($stream, $reference, $pattern) { return ['INBOX']; }
	public function construct_folder_str($folder) { return 'INBOX'; }
	public function deconstruct_folder_str($folder) { return 'INBOX'; }
	public function mail_move($stream, $numbers, $mailbox, $flags = 0) { return false; }
	public function mailcopy($stream, $numbers, $mailbox, $flags = 0) { return false; }
	public function append($stream, ...$arguments) { return false; }
	public function createmailbox($stream, $mailbox) { return false; }
	public function deletemailbox($stream, $mailbox) { return false; }
	public function base64($data) { return base64_decode($data); }
	public function qprint($data) { return quoted_printable_decode($data); }

	private function row(int $id): array
	{
		return $this->store->metadata($id);
	}
}

final class LocalPopIMAPWrapper
{
	public static function imap_last_error(): string { return 'Unable to complete the local POP operation'; }

	public static function __callStatic(string $name, array $arguments)
	{
		$stream = $arguments[0];
		$transport = $stream->transport;
		if ($name === 'imap_setflag_full' || $name === 'imap_clearflag_full') return $transport->flags($arguments[1], $arguments[2], $name === 'imap_setflag_full');
		$method = [
			'imap_close' => 'close', 'imap_fetchheader' => 'fetchheader', 'imap_body' => 'get_body', 'imap_fetchbody' => 'fetchbody',
			'imap_fetchstructure' => 'fetchstructure', 'imap_header' => 'header', 'imap_headerinfo' => 'header', 'imap_search' => 'i_search',
			'imap_sort' => 'sort', 'imap_num_msg' => 'num_msg', 'imap_status' => 'status', 'imap_mailboxmsginfo' => 'mailboxmsginfo',
			'imap_delete' => 'delete', 'imap_expunge' => 'expunge', 'imap_ping' => 'noop_ping_test', 'imap_reopen' => 'reopen',
			'imap_mail_move' => 'mail_move', 'imap_mail_copy' => 'mailcopy', 'imap_append' => 'append',
		][$name] ?? null;
		if ($method === null) return false;
		return $transport->$method(...$arguments);
	}
}