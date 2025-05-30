<?php

/**
 * phpGroupWare - sms: A SMS Gateway
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package sms
 * @subpackage sms
 * @version $Id$
 */

use App\Database\Db2;
use App\modules\phpgwapi\security\Acl;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\Cache;


/**
 * Description
 * @package sms
 */
class sms_uisms
{

	var $grants;
	var $start;
	var $query;
	var $sort;
	var $order;
	var $sub;
	var $currentapp, $account, $bo, $bocommon,
		$config, $gateway_number, $acl, $allrows,
		$db, $nextmatchs, $cat_id, $filter, $serverSettings, $userSettings, $flags, $apps, $phpgwapi_common;
	var $public_functions = array(
		'index' => true,
		'outbox' => true,
		'send' => true,
		'send_group' => true,
		'sendsmstogr_yes' => true,
		'delete_in' => true,
		'delete_out' => true,
		'daemon_manual' => true
	);

	function __construct()
	{
		$this->serverSettings = Settings::getInstance()->get('server');
		$this->userSettings = Settings::getInstance()->get('user');
		$this->flags = Settings::getInstance()->get('flags');
		$this->apps = Settings::getInstance()->get('apps');
		$this->phpgwapi_common = new \phpgwapi_common();

		Settings::getInstance()->update('flags', ['xslt_app' => true, 'menu_selection' => 'sms']);

		$this->account = $this->userSettings['account_id'];
		$this->bocommon = CreateObject('sms.bocommon');
		$location_obj = new Locations();
		$location_id = $location_obj->get_id('sms', 'run');
		$this->config = CreateObject('admin.soconfig', $location_id);
		$this->gateway_number = $this->config->config_data['common']['gateway_number'];
		$this->bo = CreateObject('sms.bosms', false);
		$this->acl = Acl::getInstance();
		$this->grants = $this->bo->grants;
		$this->start = $this->bo->start;
		$this->query = $this->bo->query;
		$this->sort = $this->bo->sort;
		$this->order = $this->bo->order;
		$this->allrows = $this->bo->allrows;
		$this->db = new Db2();
	}

	function save_sessiondata()
	{
		$data = array(
			'start' => $this->start,
			'query' => $this->query,
			'sort' => $this->sort,
			'order' => $this->order,
		);

		$this->bo->save_sessiondata($data);
	}

	function index()
	{
		$this->nextmatchs = CreateObject('phpgwapi.nextmatchs');

		Settings::getInstance()->update('flags', ['menu_selection' => 'sms::inbox']);

		$acl_location = '.inbox';

		phpgwapi_xslttemplates::getInstance()->add_file(array(
			'sms', 'nextmatchs',
			'search_field'
		));

		$this->bo->acl_location = $acl_location;

		if (!$this->acl->check($acl_location, ACL_READ, 'sms'))
		{
			$this->bocommon->no_access();
			return;
		}

		$sms_info = $this->bo->read_inbox();

		if ($this->acl->check($acl_location, ACL_ADD, 'sms'))
		{
			$add_right = true;
			$text_answer = lang('answer');
			$lang_answer_sms_text = lang('answer this sms');
		}
		else
		{
			$text_answer = '';
			$lang_answer_sms_text = '';
		}

		$content = array();
		foreach ($sms_info as $entry)
		{
			if ($this->bocommon->check_perms($entry['grants'], ACL_DELETE))
			{
				$link_delete = phpgw::link('/index.php', array(
					'menuaction' => 'sms.uisms.delete_in',
					'id' => $entry['id']
				));
				$text_delete = lang('delete');
				$lang_delete_sms_text = lang('delete the sms from inbox');
			}
			else
			{
				$link_delete = '';
				$text_delete = '';
				$lang_delete_sms_text = '';
			}

			if ($add_right)
			{
				$link_answer = phpgw::link('/index.php', array(
					'menuaction' => 'sms.uisms.send',
					'p_num' => $entry['sender']
				));
			}


			$content[] = array(
				'id' => $entry['id'],
				'sender' => $entry['sender'],
				'user' => $entry['user'],
				'message' => $entry['message'],
				'entry_time' => $entry['entry_time'],
				'link_delete' => $link_delete,
				'text_delete' => $text_delete,
				'lang_delete_sms_text' => $lang_delete_sms_text,
				'link_answer' => $link_answer,
				'text_answer' => $text_answer,
				'lang_answer_sms_text' => $lang_answer_sms_text,
			);
		}

		//_debug_array($entry['grants']);

		$table_header[] = array(
			'sort_entry_time' => $this->nextmatchs->show_sort_order(array(
				'sort' => $this->sort,
				'var' => 'in_datetime',
				'order' => $this->order,
				'extra' => array(
					'menuaction' => 'sms.uisms.index',
					'query' => $this->query,
					'cat_id' => $this->cat_id,
					'allrows' => $this->allrows
				)
			)),
			'sort_sender' => $this->nextmatchs->show_sort_order(array(
				'sort' => $this->sort,
				'var' => 'in_sender',
				'order' => $this->order,
				'extra' => array(
					'menuaction' => 'sms.uisms.index',
					'query' => $this->query,
					'cat_id' => $this->cat_id,
					'allrows' => $this->allrows
				)
			)),
			'lang_delete' => lang('delete'),
			'lang_id' => lang('id'),
			'lang_user' => lang('user'),
			'lang_sender' => lang('sender'),
			'lang_entry_time' => lang('time'),
			'lang_message' => lang('message'),
			'lang_answer' => lang('answer'),
		);

		if (!$this->allrows)
		{
			$record_limit = $this->userSettings['preferences']['common']['maxmatchs'];
		}
		else
		{
			$record_limit = $this->bo->total_records;
		}

		$link_data = array(
			'menuaction' => 'sms.uisms.index',
			'sort' => $this->sort,
			'order' => $this->order,
			'cat_id' => $this->cat_id,
			'filter' => $this->filter,
			'query' => $this->query
		);



		if ($this->acl->check($acl_location, ACL_ADD, 'sms'))
		{
			$table_add[] = array(
				'lang_send' => lang('Send SMS'),
				'lang_send_statustext' => lang('Send SMS'),
				'send_action' => phpgw::link('/index.php', array(
					'menuaction' => 'sms.uisms.send',
					'from' => 'index'
				)),
				'lang_send_group' => lang('Send broadcast SMS'),
				'lang_send_group_statustext' => lang('send group'),
				'send_group_action' => phpgw::link('/index.php', array(
					'menuaction' => 'sms.uisms.send_group',
					'from' => 'index'
				)),
			);
		}

		$msgbox_data = $this->phpgwapi_common->msgbox_data($receipt);

		$data = array(
			'msgbox_data' => $this->phpgwapi_common->msgbox($msgbox_data),
			'menu' => execMethod('sms.menu.links'),
			'allow_allrows' => true,
			'allrows' => $this->allrows,
			'start_record' => $this->start,
			'record_limit' => $record_limit,
			'num_records' => count($sms_info),
			'all_records' => $this->bo->total_records,
			'link_url' => phpgw::link('/index.php', $link_data),
			'img_path' => $this->phpgwapi_common->get_image_path('phpgwapi', 'default'),
			'lang_searchfield_statustext' => lang('Enter the search string. To show all entries, empty this field and press the SUBMIT button again'),
			'lang_searchbutton_statustext' => lang('Submit the search string'),
			'query' => $this->query,
			'lang_search' => lang('search'),
			'table_header_inbox' => $table_header,
			'table_add' => $table_add,
			'values_inbox' => $content
		);

		$appname = lang('inbox');
		$function_msg = lang('list inbox');

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . $appname . ': ' . $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('list_inbox' => $data));
		$this->save_sessiondata();
	}

	function outbox()
	{
		Settings::getInstance()->update('flags', ['menu_selection' => 'sms::outbox']);
		$acl_location = '.outbox';

		$this->nextmatchs = CreateObject('phpgwapi.nextmatchs');

		phpgwapi_xslttemplates::getInstance()->add_file(array(
			'sms', 'nextmatchs', 'menu',
			'search_field'
		));

		$this->bo->acl_location = $acl_location;

		if (!$this->acl->check($acl_location, ACL_READ, 'sms'))
		{
			$this->bocommon->no_access();
			return;
		}

		$receipt = Cache::session_get('sms_send_receipt', 'session_data');
		Cache::session_clear('sms_send_receipt', 'session_data');

		$sms_info = $this->bo->read_outbox();

		foreach ($sms_info as $entry)
		{
			if ($this->bocommon->check_perms($entry['grants'], ACL_DELETE))
			{
				$link_delete = phpgw::link('/index.php', array(
					'menuaction' => 'sms.uisms.delete_out',
					'id' => $entry['id']
				));
				$text_delete = lang('delete');
				$lang_delete_sms_text = lang('delete the sms from outbox');
			}

			$content[] = array(
				'id' => $entry['id'],
				'receiver' => $entry['p_dst'],
				'user' => $entry['user'],
				'message' => $entry['message'],
				'dst_group' => $entry['dst_group'],
				'entry_time' => $entry['entry_time'],
				'status' => $entry['status'],
				'link_delete' => $link_delete,
				'text_delete' => $text_delete,
				'lang_delete_sms_text' => $lang_delete_sms_text,
			);

			unset($link_delete);
			unset($text_delete);
			unset($lang_delete_sms_text);
		}

		//_debug_array($content);

		$table_header[] = array(
			'sort_entry_time' => $this->nextmatchs->show_sort_order(array(
				'sort' => $this->sort,
				'var' => 'p_datetime',
				'order' => $this->order,
				'extra' => array(
					'menuaction' => 'sms.uisms.outbox',
					'query' => $this->query,
					'cat_id' => $this->cat_id,
					'allrows' => $this->allrows
				)
			)),
			'lang_delete' => lang('delete'),
			'lang_id' => lang('id'),
			'lang_user' => lang('user'),
			'lang_group' => lang('group'),
			'lang_entry_time' => lang('time'),
			'lang_status' => lang('status'),
			'lang_receiver' => lang('receiver'),
			'lang_message' => lang('message'),
		);

		if (!$this->allrows)
		{
			$record_limit = $this->userSettings['preferences']['common']['maxmatchs'];
		}
		else
		{
			$record_limit = $this->bo->total_records;
		}

		$link_data = array(
			'menuaction' => 'sms.uisms.outbox',
			'sort' => $this->sort,
			'order' => $this->order,
			'cat_id' => $this->cat_id,
			'filter' => $this->filter,
			'query' => $this->query
		);


		if ($this->acl->check($acl_location, ACL_ADD, 'sms'))
		{
			$table_add[] = array(
				'lang_send' => lang('Send SMS'),
				'lang_send_statustext' => lang('Send SMS'),
				'send_action' => phpgw::link('/index.php', array(
					'menuaction' => 'sms.uisms.send',
					'from' => 'outbox'
				)),
				'lang_send_group' => lang('Send broadcast SMS'),
				'lang_send_group_statustext' => lang('send group'),
				'send_group_action' => phpgw::link('/index.php', array(
					'menuaction' => 'sms.uisms.send_group',
					'from' => 'outbox'
				)),
			);
		}


		$msgbox_data = $this->phpgwapi_common->msgbox_data($receipt);

		$data = array(
			'msgbox_data' => $this->phpgwapi_common->msgbox($msgbox_data),
			'menu' => execMethod('sms.menu.links'),
			'allow_allrows' => true,
			'allrows' => $this->allrows,
			'start_record' => $this->start,
			'record_limit' => $record_limit,
			'num_records' => count($sms_info),
			'all_records' => $this->bo->total_records,
			'link_url' => phpgw::link('/index.php', $link_data),
			'img_path' => $this->phpgwapi_common->get_image_path('phpgwapi', 'default'),
			'lang_searchfield_statustext' => lang('Enter the search string. To show all entries, empty this field and press the SUBMIT button again'),
			'lang_searchbutton_statustext' => lang('Submit the search string'),
			'query' => $this->query,
			'lang_search' => lang('search'),
			'table_header_outbox' => $table_header,
			'table_add' => $table_add,
			'values_outbox' => $content
		);

		$appname = lang('outbox');
		$function_msg = lang('list outbox');

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . $appname . ': ' . $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('list_outbox' => $data));
		$this->save_sessiondata();
	}

	function send()
	{
		Settings::getInstance()->update('flags', ['menu_selection' => 'sms::outbox']);
		$acl_location = '.outbox';
		if (!$this->acl->check($acl_location, ACL_ADD, 'sms'))
		{
			$this->bocommon->no_access();
			return;
		}

		$p_num = Sanitizer::get_var('p_num');
		$message = Sanitizer::get_var('message');
		$values = Sanitizer::get_var('values');
		$from = Sanitizer::get_var('from');
		$from = $from ? $from : 'index';

		phpgwapi_xslttemplates::getInstance()->add_file(array('sms'));

		/**
		 * Text messages exceeding 160 characters will be split up into a maximum of 6 SMS messages,
		 * each of 134 characters. Thus, the maximum length is 6*134=804 characters.
		 * This is done automatically by the SMS Gateway.
		 * Text messages of more than 804 characters will be truncated.
		 */
		$max_length = 804;

		if (is_array($values))
		{
			$values['p_num_text'] = Sanitizer::get_var('p_num_text', 'string', 'POST');
			$values['message'] = Sanitizer::get_var('message');
			$values['msg_flash'] = Sanitizer::get_var('msg_flash', 'bool', 'POST');
			$values['msg_unicode'] = Sanitizer::get_var('msg_unicode', 'bool', 'POST');

			$p_num = $values['p_num_text'] ? $values['p_num_text'] : $p_num;

			if ($values['save'])
			{

				if (!$values['message'])
				{
					$receipt['error'][] = array('msg' => lang('Please enter a message !'));
				}
				if (!$values['p_num_text'])
				{
					$receipt['error'][] = array('msg' => lang('Please enter a recipient !'));
				}

				if (!$receipt['error'])
				{
					$from = 'outbox';
					$values['p_num_text'] = explode(',', $p_num);
					$receipt = $this->bo->send_sms($values);
					$sms_id = $receipt['sms_id'];

					Cache::session_set('sms_send_receipt', 'session_data', $receipt);
					phpgw::redirect_link('/index.php', array('menuaction' => 'sms.uisms.' . $from));
				}
			}
			else
			{
				phpgw::redirect_link('/index.php', array('menuaction' => 'sms.uisms.' . $from));
			}
		}


		if ($sms_id)
		{
			if (!$receipt['error'])
			{
				$values = $this->bo->read_single($sms_id);
			}
			$function_msg = lang('edit place');
			$action = 'edit';
		}
		else
		{
			$function_msg = lang('add place');
			$action = 'add';
		}

		$link_data = array(
			'menuaction' => 'sms.uisms.send',
			'sms_id' => $sms_id,
			'from' => $from
		);

		$msgbox_data = $this->phpgwapi_common->msgbox_data($receipt);

		$code = <<<JS
			function SmsCountKeyUp(maxChar)
			{
			    var msg  = document.forms.fm_sendsms.message;
			    var left = document.forms.fm_sendsms.charNumberLeftOutput;
			    var smsLenLeft = maxChar  - msg.value.length;
			    if (smsLenLeft >= 0) 
			    {
				left.value = smsLenLeft;
			    } 
			    else 
			    {
				var msgMaxLen = maxChar;
				left.value = 0;
				msg.value = msg.value.substring(0, msgMaxLen);
			    }
			}
			function SmsCountKeyDown(maxChar)
			{
			    var msg  = document.forms.fm_sendsms.message;
			    var left = document.forms.fm_sendsms.charNumberLeftOutput;
			    var smsLenLeft = maxChar  - msg.value.length;
			    if (smsLenLeft >= 0) 
			    {
				left.value = smsLenLeft;
			    } 
			    else 
			    {
				var msgMaxLen = maxChar;
				left.value = 0; 
				msg.value = msg.value.substring(0, msgMaxLen);
			    }
			}
JS;

		phpgwapi_js::getInstance()->add_code('', $code);

		$data = array(
			'lang_to' => lang('to'),
			'lang_from' => lang('from'),
			'value_sms_from' => $this->gateway_number,
			'value_p_num' => $p_num,
			'lang_format' => lang('International format'),
			'lang_message' => lang('message'),
			'value_message' => $message,
			'lang_character_left' => lang('character left'),
			'lang_send_as_flash' => lang('send as flash message'),
			'lang_send_as_unicode' => lang('send as unicode'),
			'value_max_length' => $max_length,
			'msgbox_data' => $this->phpgwapi_common->msgbox($msgbox_data),
			'form_action' => phpgw::link('/index.php', $link_data),
			'lang_save' => lang('save'),
			'lang_cancel' => lang('cancel'),
			'lang_done_status_text' => lang('Back to the list'),
			'lang_save_status_text' => lang('Save the training'),
			'lang_apply' => lang('apply'),
			'lang_apply_status_text' => lang('Apply the values'),
		);

		$appname = lang('send sms');

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . $appname]);

		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('send' => $data));
	}

	function send_group()
	{
		Settings::getInstance()->update('flags', [
			'menu_selection' => 'sms::outbox',
			'xslt_app' => false,
			'app_header' => lang('SMS') . '::' . lang('Send broadcast SMS')
		]);

		$java_script = <<<JS

		function SmsCountKeyUp(maxChar)
		{
			var msg  = document.forms.fm_sendsms.message;
			var left = document.forms.fm_sendsms.charNumberLeftOutput;
			var smsLenLeft = maxChar  - msg.value.length;
			if (smsLenLeft >= 0) 
			{
			left.value = smsLenLeft;
			} 
			else 
			{
			var msgMaxLen = maxChar;
			left.value = 0;
			msg.value = msg.value.substring(0, msgMaxLen);
			}
		}
		function SmsCountKeyDown(maxChar)
		{
			var msg  = document.forms.fm_sendsms.message;
			var left = document.forms.fm_sendsms.charNumberLeftOutput;
			var smsLenLeft = maxChar  - msg.value.length;
			if (smsLenLeft >= 0) 
			{
			left.value = smsLenLeft;
			} 
			else 
			{
			var msgMaxLen = maxChar;
			left.value = 0; 
			msg.value = msg.value.substring(0, msgMaxLen);
			}
		}

JS;
		phpgwapi_js::getInstance()->add_code('', $java_script);

		$this->phpgwapi_common->phpgw_header();

		echo parse_navbar();

		$message = Sanitizer::get_var('message');
		$err = urldecode(Sanitizer::get_var('err'));

		$link_data = array(
			'menuaction' => 'sms.uisms.sendsmstogr_yes',
			'sms_id' => $sms_id,
			'from' => $from
		);
		$form_action = phpgw::link('/index.php', $link_data);

		$sms = CreateObject('sms.sms');
		$max_length = $core_config['smsmaxlength'] = 804;
		if ($sms_sender = $sms->username2sender($this->userSettings['account_lid']))
		{
			$max_length = $max_length - strlen($sms_sender);
		}
		else
		{
			$sms_sender = "<i>not set</i>";
		}
		if ($err)
		{
			$content = "<p><font color=red>$err</font><p>";
		}
		if ($this->gateway_number)
		{
			$sms_from = $this->gateway_number;
		}
		else
		{
			$sms_from = $mobile;
		}
		// WWW
		$db_query2 = "SELECT * FROM phpgw_sms_tblsmstemplate WHERE uid='{$this->account}'";
		$this->db->query($db_query2);
		$j = 0;
		$option_values = "<option value=\"\" default>--Please Select--</option>";
		while ($this->db->next_record())
		{
			$j++;
			$option_values .= "<option value=\"" . $this->db->f('t_text') . "\">" . $this->db->f('t_title') . "</option>";
			$input_values .= "<input type=\"hidden\" name=\"content_$j\" value=\"" . $this->db->f('t_text') . "\">";
		}

		// document.fm_sendsms.message.value = document.fm_smstemplate.content_num.value;
		$content .= "
			<!-- WWW -->
			    <script language=\"javascript\">
		
				function setTemplate()
				{		    
				    sellength = fm_sendsms.smstemplate.length;
				    for ( i=0; i<sellength; i++)
				    {
					if (fm_sendsms.smstemplate.options[i].selected == true)
					{
					    fm_sendsms.message.value = fm_sendsms.smstemplate.options[i].value;
					}
				    }
				}
			    </script>
		
			    <form name=\"fm_smstemplate\">
			    $input_values
			    </form>
		
			    <h2>Send broadcast SMS</h2>
			    <p>
			    <form name='fm_sendsms' id='fm_sendsms' action=$form_action method=POST>
			    <p>From: $sms_from
			    <p>
			    <p>Send to group: <select name=\"gp_code\">$list_of_group</select>
			    <!--
			    <table cellpadding=1 cellspacing=0 border=0>
			    <tr>
				<td nowrap>
				    Group(s):<br>
				    <select name=\"gp_code_dump[]\" size=\"10\" multiple=\"multiple\" onDblClick=\"moveSelectedOptions(this.form['gp_code_dump[]'],this.form['gp_code[]'])\">$list_of_group</select>
				</td>
				<td width=10>&nbsp;</td>
				<td align=center valign=middle>
				<input type=\"button\" class=\"button\" value=\"&gt;&gt;\" onclick=\"moveSelectedOptions(this.form['gp_code_dump[]'],this.form['gp_code[]'])\"><br><br>
				<input type=\"button\" class=\"button\" value=\"All &gt;&gt;\" onclick=\"moveAllOptions(this.form['gp_code_dump[]'],this.form['gp_code[]'])\"><br><br>
				<input type=\"button\" class=\"button\" value=\"&lt;&lt;\" onclick=\"moveSelectedOptions(this.form['gp_code[]'],this.form['gp_code_dump[]'])\"><br><br>
				<input type=\"button\" class=\"button\" value=\"All &lt;&lt;\" onclick=\"moveAllOptions(this.form['gp_code[]'],this.form['gp_code_dump[]'])\">
				</td>		
				<td width=10>&nbsp;</td>
				<td nowrap>
				    Send to:<br>
				    <select name=\"gp_code[]\" size=\"10\" multiple=\"multiple\" onDblClick=\"moveSelectedOptions(this.form['gp_code[]'],this.form['gp_code_dump[]'])\"></select>
				</td>
			    </tr>
			    </table>
			    -->
			    <p>Or: <input type=text size=20 maxlength=20 name=gp_code_text value=\"$dst_gp_code\"> (Group name)
			    <p>SMS Sender ID: $sms_sender 
			    <p>Message template: <select name=\"smstemplate\">$option_values</select>
			    <p><input type=\"button\" onClick=\"javascript: setTemplate();\" name=\"nb\" value=\"Use Template\" class=\"button\">
			    <p>Your message: 
			    <br><textarea cols=\"39\" rows=\"5\" onKeyUp=\"javascript: SmsCountKeyUp($max_length);\" onKeyDown=\"javascript: SmsCountKeyDown($max_length);\" name=\"message\" id=\"ta_sms_content\">$message</textarea>
			    <br>Character left: <input value=\"$max_length\" type=\"text\" onKeyPress=\"if (window.event.keyCode == 13){return false;}\" onFocus=\"this.blur();\" size=\"3\" name=\"charNumberLeftOutput\" id=\"charNumberLeftOutput\">
			    <p><input type=checkbox name=msg_flash> Send as flash message
			    <p><input type=submit class=button value=Send onClick=\"selectAllOptions(this.form['gp_code[]'])\"> 
			    </form>
			";
		echo $content;
	}

	function sendsmstogr_yes()
	{
		$gp_code = $_POST['gp_code'];
		if (!$gp_code[0])
		{
			$gp_code = $_POST['gp_code_text'];
		}
		$msg_flash = $_POST['msg_flash'];
		$message = $_POST['message'];
		if ($gp_code && $message)
		{
			$sms_type = "text";
			if ($msg_flash == "on")
			{
				$sms_type = "flash";
			}
			list($ok, $to, $smslog_id) = websend2group($username, $gp_code, $message, $sms_type);
			for ($i = 0; $i < count($ok); $i++)
			{
				if ($ok[$i])
				{
					$error_string .= "Your SMS for `" . $to[$i] . "` has been delivered to queue<br>";
				}
				else
				{
					$error_string .= "Fail to sent SMS to `" . $to[$i] . "`<br>";
				}
			}
			//  	header("Location: menu.php?inc=send_sms&op=sendsmstogr&message=".urlencode($message)."&err=".urlencode($error_string));
		}
		else
		{
			$error_string = "You must select receiver group and your message should not be empty";
			//    header("Location: menu.php?inc=send_sms&op=sendsmstogr&message=".urlencode($message)."&err=".urlencode("You must select receiver group and your message should not be empty"));
		}
		$link_data = array(
			'menuaction' => 'sms.uisms.send_group',
			'sms_id' => $sms_id,
			'from' => $from,
			'message' => urlencode($message),
			'err' => urlencode($error_string)
		);
		phpgw::redirect_link('/index.php', $link_data);
	}

	function delete_in()
	{
		Settings::getInstance()->update('flags', ['menu_selection' => 'sms::inbox']);
		$acl_location = '.inbox';
		if (!$this->acl->check($acl_location, ACL_DELETE, 'sms'))
		{
			$this->bocommon->no_access();
			return;
		}

		$id = Sanitizer::get_var('id', 'int');
		$confirm = Sanitizer::get_var('confirm', 'bool', 'POST');

		$link_data = array(
			'menuaction' => 'sms.uisms.index'
		);

		if (Sanitizer::get_var('confirm', 'bool', 'POST'))
		{
			$this->bo->delete_in($id);
			phpgw::redirect_link('/index.php', $link_data);
		}

		phpgwapi_xslttemplates::getInstance()->add_file(array('app_delete'));

		$data = array(
			'done_action' => phpgw::link('/index.php', $link_data),
			'delete_action' => phpgw::link('/index.php', array(
				'menuaction' => 'sms.uisms.delete_in',
				'id' => $id
			)),
			'lang_confirm_msg' => lang('do you really want to delete this entry'),
			'lang_yes' => lang('yes'),
			'lang_yes_statustext' => lang('Delete the entry'),
			'lang_no_statustext' => lang('Back to the list'),
			'lang_no' => lang('no')
		);

		$appname = lang('outbox');
		$function_msg = lang('delete');

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . $appname . ': ' . $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('delete' => $data));
	}

	function delete_out()
	{
		Settings::getInstance()->update('flags', ['menu_selection' => 'sms::outbox']);
		$acl_location = '.outbox';
		if (!$this->acl->check($acl_location, ACL_DELETE, 'sms'))
		{
			$this->bocommon->no_access();
			return;
		}

		$id = Sanitizer::get_var('id', 'int');
		$confirm = Sanitizer::get_var('confirm', 'bool', 'POST');

		$link_data = array(
			'menuaction' => 'sms.uisms.outbox'
		);

		if (Sanitizer::get_var('confirm', 'bool', 'POST'))
		{
			$this->bo->delete_out($id);
			phpgw::redirect_link('/index.php', $link_data);
		}

		phpgwapi_xslttemplates::getInstance()->add_file(array('app_delete'));

		$data = array(
			'done_action' => phpgw::link('/index.php', $link_data),
			'delete_action' => phpgw::link('/index.php', array(
				'menuaction' => 'sms.uisms.delete_out',
				'id' => $id
			)),
			'lang_confirm_msg' => lang('do you really want to delete this entry'),
			'lang_yes' => lang('yes'),
			'lang_yes_statustext' => lang('Delete the entry'),
			'lang_no_statustext' => lang('Back to the list'),
			'lang_no' => lang('no')
		);

		$appname = lang('outbox');
		$function_msg = lang('delete');

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . $appname . ': ' . $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('delete' => $data));
	}

	/**
	 * @param mixed $data
	 * If $data is an array - then the process is run as cron
	 */
	function daemon_manual($data = array())
	{
		Settings::getInstance()->update('flags', ['menu_selection' => 'admin::sms::refresh']);
		if (!$this->acl->check('run', ACL_READ, 'admin'))
		{
			$this->bocommon->no_access();
			return;
		}

		$sms = CreateObject('sms.sms');
		$sms->getsmsinbox(true);
		$sms->getsmsstatus();
		if (isset($data['cron']))
		{
			Settings::getInstance()->update('flags', ['xslt_app' => false]);
			return;
		}

		phpgwapi_xslttemplates::getInstance()->add_file(array('sms'));

		$receipt['message'][] = array('msg' => lang('Daemon refreshed'));

		$msgbox_data = $this->phpgwapi_common->msgbox_data($receipt);

		$data = array(
			'msgbox_data' => $this->phpgwapi_common->msgbox($msgbox_data),
			'menu' => execMethod('sms.menu.links'),
		);

		$appname = lang('config');
		$function_msg = lang('Daemon manual refresh');

		Settings::getInstance()->update('flags', ['app_header' => lang('sms') . ' - ' . $appname . ': ' . $function_msg]);
		phpgwapi_xslttemplates::getInstance()->set_var('phpgw', array('daemon_manual' => $data));
	}
}
