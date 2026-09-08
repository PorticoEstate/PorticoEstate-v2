<?php

/**
 * phpGroupWare - sms
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package property
 * @subpackage core
 * @version $Id$
 */

use App\modules\preferences\helpers\PreferenceHelper;

$preferenceHelper = PreferenceHelper::getInstance();

$preferenceHelper->create_input_box('Your Cellphone', 'cellphone', 'help text', '', 15);
$preferenceHelper->create_input_box('Your signature', 'signature', 'Signature to be appended to your sms-messages', '', 15);
$preferenceHelper->create_select_box('show horisontal menues', 'horisontal_menus', array(
	'no' => 'No',
	'yes' => 'Yes'
), 'Horisontal menues are shown in top of page');
$default_start_page = array(
	'sms.inbox' => lang('inbox'),
	'sms.outbox' => lang('outbox'),
	'autoreply.index' => lang('autoreply'),
	'board.index' => lang('boards'),
	'command.index' => lang('command'),
	'command.log' => lang('command.log'),
	'custom.index' => lang('custom'),
	'poll.index' => lang('polls')
);
$preferenceHelper->create_select_box('Default start page', 'default_start_page', $default_start_page, 'Select your start-submodule');
