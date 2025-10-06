<?php

/**
 * Setup
 *
 * @copyright Copyright (C) 2000-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package setup
 * @version $Id$
 */

namespace App\modules\setup\controllers;

use App\Database\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\setup\Setup;
use App\modules\phpgwapi\services\setup\Detection;
use App\modules\phpgwapi\services\setup\Process;
use App\modules\phpgwapi\services\setup\Html;
use App\helpers\Template;
use App\modules\phpgwapi\services\setup\SetupTranslation;
use App\modules\phpgwapi\services\Sanitizer;
use PDO;

class Lang
{
	/**
	 * @var object
	 */
	private $db;
	private $detection;
	private $process;
	private $html;
	private $setup;
	private $setup_tpl;
	private $translation;

	public function __construct()
	{

		//setup_info
		Settings::getInstance()->set('setup_info', []); //$GLOBALS['setup_info']
		//setup_data
		Settings::getInstance()->set('setup', []); //$setup_data

		$this->db = Db::getInstance();
		$this->detection = new Detection();
		$this->process = new Process();
		$this->html = new Html();
		$this->setup = new Setup();
		$this->translation = new SetupTranslation();

		$flags = array(
			'noheader' 		=> True,
			'nonavbar'		=> True,
			'currentapp'	=> 'home',
			'noapi'			=> True,
			'nocachecontrol' => True
		);
		Settings::getInstance()->set('flags', $flags);


		// Check header and authentication
		if (!$this->setup->auth('Config'))
		{
			Header('Location: ../setup');
			exit;
		}

		$tpl_root = $this->html->setup_tpl_dir('setup');
		$this->setup_tpl = new Template($tpl_root);

		$this->html->set_tpl($this->setup_tpl);
	}


	public function index()
	{

		if (\Sanitizer::get_var('cancel', 'bool', 'POST'))
		{
			Header('Location: ../setup');
			exit;
		}

		$newinstall = false;


		if (isset($_POST['submit']) && $_POST['submit'])
		{
			$lang_selected = $_POST['lang_selected'];
			$upgrademethod = $_POST['upgrademethod'];

			$error = $this->translation->update_db($lang_selected, $upgrademethod);

			if ($error)
			{
				$error = <<<HTML
                        <div class="err">
                            <h2>ERROR</h2>
                            $error
                        </div>

        HTML;

				$this->setup_tpl->set_file(array(
					'T_head'		=> 'head.tpl',
					'T_footer'		=> 'footer.tpl',
				));

				$stage_title = $this->setup->lang('Multi-Language support setup');
				$stage_desc  = $this->setup->lang('ERROR');

				$db_config = $this->db->get_config();

				$header = $this->html->get_header("$stage_title: $stage_desc", false, 'config', $this->db->get_domain() . '(' . $db_config['db_type'] . ')');
				$return = $this->setup->lang('Return to Multi-Language support setup');
				$error .= <<<HTML
                        <div>
                            <a href="../setup/lang">$return</a>
                        </div>

        HTML;
				$footer = $this->html->get_footer();
				return $header . $error . $footer;
			}

			Header('Location: ../setup/lang?success=1');
			exit;
		}
		else
		{
			$this->detection->check_lang(false);	// get installed langs

			$setup_data = Settings::getInstance()->get('setup');

			$this->setup_tpl->set_file(array(
				'T_head'		=> 'head.tpl',
				'T_footer'		=> 'footer.tpl',
				'T_alert_msg'	=> 'msg_alert_msg.tpl',
				'T_lang_main'	=> 'lang_main.tpl'
			));

			$success_message = '';
			if (\Sanitizer::get_var('success', 'bool', 'GET'))
			{
				$success_message = <<<HTML
					<div style="background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; margin: 10px 0; border-radius: 4px;">
						<strong>Success!</strong> Languages have been installed successfully.
					</div>
HTML;
			}

			$this->setup_tpl->set_block('T_lang_main', 'B_choose_method', 'V_choose_method');

			$stage_title = $this->setup->lang('Multi-Language support setup');
			$stage_desc  = $this->setup->lang('This program will help you upgrade or install different languages for phpGroupWare');
			$tbl_width   = $newinstall ? '60%' : '80%';
			$td_colspan  = $newinstall ? '1' : '2';
			$td_align    = $newinstall ? ' align="center"' : '';
			$hidden_var1 = $newinstall ? '<input type="hidden" name="newinstall" value="True">' : '';

			$dir = dir(SRC_ROOT_PATH . '/modules/phpgwapi/setup');
			while (($file = $dir->read()) !== false)
			{
				if (substr($file, 0, 6) == 'phpgw_')
				{
					$avail_lang[] = "'" . substr($file, 6, 2) . "'";
				}
			}

			if (!$newinstall && !isset($setup_data['installed_langs']))
			{
				$this->detection->check_lang(false);	// get installed langs
			}

			$select_box_desc = $this->setup->lang('Select which languages you would like to use');

			$stmt = $this->db->prepare('SELECT lang_id, lang_name, available FROM phpgw_languages WHERE lang_id IN(' . implode(',', $avail_lang) . ') ORDER BY lang_name');
			$stmt->execute();

			$checkbox_langs = '';
			while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$id = $row['lang_id'];
				$name = $row['lang_name'];
				$checked = isset($setup_data['installed_langs'][$id]) ? ' checked = "checked"' : '';

				$checkbox_langs .= "<label><input type=\"checkbox\" name=\"lang_selected[]\" value=\"$id\"$checked>{$name}</label><br>";
			}

			$this->db->query("UPDATE phpgw_languages SET available = 'Yes' WHERE lang_id IN('" . implode("','", $avail_lang) . "')");

			if (!$newinstall)
			{
				$meth_desc = $this->setup->lang('Select which method of upgrade you would like to do');
				$blurb_addonlynew = $this->setup->lang('Only add languages that are not in the database already');
				$blurb_addmissing = $this->setup->lang('Only add new phrases');
				$blurb_dumpold = $this->setup->lang('Delete all old languages and install new ones');

				$this->setup_tpl->set_var('meth_desc', $meth_desc);
				$this->setup_tpl->set_var('blurb_addonlynew', $blurb_addonlynew);
				$this->setup_tpl->set_var('blurb_addmissing', $blurb_addmissing);
				$this->setup_tpl->set_var('blurb_dumpold', $blurb_dumpold);
				$this->setup_tpl->parse('V_choose_method', 'B_choose_method');
			}
			else
			{
				$this->setup_tpl->set_var('V_choose_method', '');
			}

			$this->setup_tpl->set_var('stage_title', $stage_title);
			$this->setup_tpl->set_var('stage_desc', $stage_desc);
			$this->setup_tpl->set_var('tbl_width', $tbl_width);
			$this->setup_tpl->set_var('td_colspan', $td_colspan);
			$this->setup_tpl->set_var('td_align', $td_align);
			$this->setup_tpl->set_var('hidden_var1', $hidden_var1);
			$this->setup_tpl->set_var('select_box_desc', $select_box_desc);
			$this->setup_tpl->set_var('checkbox_langs', $checkbox_langs);

			$this->setup_tpl->set_var('lang_install', $this->setup->lang('install'));
			$this->setup_tpl->set_var('lang_cancel', $this->setup->lang('cancel'));

			$db_config = $this->db->get_config();

			$header = $this->html->get_header("$stage_title", False, 'config', $this->db->get_domain() . '(' . $db_config['db_type'] . ')');
			$main = $this->setup_tpl->fp('out', 'T_lang_main');
			$footer = $this->html->get_footer();
			return $header . $success_message . $main . $footer;
		}
	}
}
