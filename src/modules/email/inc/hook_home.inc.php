<?php

/**
 * EMail - Home hook
 *
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package email
 * @subpackage hooks
 */

use App\modules\email\services\EmailHomeSummary;
use App\modules\email\services\MailMessage;
use App\modules\phpgwapi\controllers\Applications;
use App\modules\phpgwapi\helpers\TwigHelper;
use App\modules\phpgwapi\services\Settings;

$settings = Settings::getInstance();
$prev_currentapp = $settings->get('flags')['currentapp'] ?? null;
$mail = null;
try
{
	$settings->update('flags', ['currentapp' => 'email']);
	$bootstrap = CreateObject('email.msg_bootstrap');
	$bootstrap->set_do_login(BS_LOGIN_NEVER);
	$bootstrap->ensure_mail_msg_exists('email.hook_home', 0);
	$mail = MailMessage::getInstance();
	$summary = (new EmailHomeSummary())->collect($mail, static function ($path, $parameters)
	{
		return phpgw::link($path, $parameters);
	});
	if ($summary !== null)
	{
		$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$extra_data = (new TwigHelper('email'))->render('@views/home-summary.twig', [
			'summary' => $summary,
			'folderLabel' => lang('E-Mail Folders') . ':',
			'switchLabel' => lang('switch current folder to'),
			'composeLabel' => lang('Compose New'),
			'goLabel' => lang('go'),
		]);
		// The legacy portal box inserts its values as HTML without escaping.
		$data = array_map(static fn ($message) => [
			'text' => $escape($message['text']),
			'link' => $escape($message['link']),
		], $summary['messages']);
		$settings->update('flags', ['currentapp' => $prev_currentapp]);
		$portalbox = CreateObject('phpgwapi.listbox', [
			'title' => '<font color="#FFFFFF">' . $escape(lang('EMail') . ' ' . $summary['alert']) . '</font>',
			'primary' => $GLOBALS['phpgw_info']['theme']['navbar_bg'],
			'secondary' => $GLOBALS['phpgw_info']['theme']['navbar_bg'],
			'tertiary' => $GLOBALS['phpgw_info']['theme']['navbar_bg'],
			'width' => '100%',
			'outerborderwidth' => '0',
			'header_background_image' => (new phpgwapi_common)->image('phpgwapi/templates/phpgw_website', 'bg_filler'),
		]);
		$app_id = (new Applications())->name2id('email');
		$GLOBALS['portal_order'][] = $app_id;
		foreach (['up', 'down', 'close', 'question', 'edit'] as $control)
		{
			$portalbox->set_controls($control, ['url' => '/set_box.php', 'app' => $app_id]);
		}
		$portalbox->data = $data;
		echo "\n<!-- BEGIN Mailbox info -->\n" . $portalbox->draw($extra_data) . '<!-- END Mailbox info -->' . "\n";
	}
}
finally
{
	try
	{
		if ($mail !== null)
		{
			$mail->end_request();
		}
	}
	finally
	{
		$settings->update('flags', ['currentapp' => $prev_currentapp]);
	}
}
