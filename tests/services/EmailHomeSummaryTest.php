<?php

namespace Tests\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\modules\email\services\EmailHomeSummary;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class EmailHomeSummaryTest extends TestCase
{
	private function mail(): object
	{
		return new class
		{
			public bool $enabled = true;
			public bool $folders = true;
			public int $total = 6;
			public int $checks = 0;
			public array $headers = [];
			public ?string $subject = null;
			public function get_pref_value($key, $account): string
			{
				return $key === 'mainscreen_showmail' ? ($this->enabled ? 'True' : 'False') : 'True';
			}
			public function new_message_check(): array
			{
				$this->checks++;
				return ['number_all' => $this->total, 'number_new' => 2, 'alert_string' => '2 new'];
			}
			public function get_msgball_list(): array { return range(1, $this->total); }
			public function ball_data_parse_str($value): array { return ['uri' => ['msgnum' => $value]]; }
			public function phpgw_header($ball): array { $this->headers[] = $ball; return []; }
			public function get_subject($headers, $fallback): string { return $this->subject ?? str_repeat('ø', 66); }
			public function htmlspecialchars_decode($value): string { return htmlspecialchars_decode($value, ENT_QUOTES); }
			public function get_mailsvr_supports_folders(): bool { return $this->folders; }
			public function get_folder_list($account): array
			{
				return [['folder_long' => 'INBOX', 'folder_short' => 'INBOX', 'acctnum' => 0]];
			}
			public function prep_folder_out($folder): string { return rawurlencode($folder); }
			public function get_common_langs($key): string { return 'Inbox'; }
			public function get_folder_status_info($ball): array { return ['number_new' => 2]; }
		};
	}

	public function testDisabledWidgetDoesNotCheckMailbox(): void
	{
		$mail = $this->mail();
		$mail->enabled = false;
		$this->assertNull((new EmailHomeSummary())->collect($mail, static fn () => 'unused'));
		$this->assertSame(0, $mail->checks);
	}

	public function testSummaryLimitsHeadersAndPreservesFolderAndMessageRouting(): void
	{
		$mail = $this->mail();
		$summary = (new EmailHomeSummary())->collect($mail, static fn ($path, $args) => $path . '?' . http_build_query($args));
		$this->assertSame(6, $summary['total']);
		$this->assertSame(2, $summary['unread']);
		$this->assertCount(5, $summary['messages']);
		$this->assertCount(5, $mail->headers);
		$this->assertSame(str_repeat('ø', 65) . ' ...', $summary['messages'][0]['text']);
		$this->assertSame('/email/view/message?msgnum=1', $summary['messages'][0]['link']);
		$this->assertSame([['name' => 'Inbox (2)', 'value' => '&folder=INBOX&acctnum=0']], $summary['folders']);
	}

	public function testEmptyMailboxWithoutFoldersStillOffersCompose(): void
	{
		$mail = $this->mail();
		$mail->total = 0;
		$mail->folders = false;
		$summary = (new EmailHomeSummary())->collect($mail, static fn ($path, $args) => $path);
		$this->assertSame([], $summary['messages']);
		$this->assertSame([], $summary['folders']);
		$this->assertSame([], $mail->headers);
		$this->assertSame('/email/view/compose', $summary['composeUrl']);
	}

	public function testLegacySubjectEncodingIsDecodedBeforePresentation(): void
	{
		$mail = $this->mail();
		$mail->subject = 'Work &amp; &lt;Mail&gt;';
		$summary = (new EmailHomeSummary())->collect($mail, static fn ($path, $args) => $path);
		$this->assertSame('Work & <Mail>', $summary['messages'][0]['text']);
	}

	public function testTemplateEscapesFolderLabelsValuesAndLinks(): void
	{
		$twig = new Environment(new FilesystemLoader(__DIR__ . '/../../src/modules/email/html/base'));
		$html = $twig->render('home-summary.twig', [
			'summary' => ['folders' => [['name' => '<script>', 'value' => '"&folder=INBOX']],
				'inboxUrl' => '/inbox?x="', 'composeUrl' => '/compose?x="'],
			'folderLabel' => 'Folders', 'switchLabel' => 'Switch', 'composeLabel' => 'Compose', 'goLabel' => 'Go',
		]);
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
		$this->assertStringContainsString('&quot;&amp;folder=INBOX', $html);
		$this->assertStringContainsString('name="fldball_fake_uri"', $html);
		$this->assertStringContainsString('/compose?x=&quot;', $html);
	}
}
