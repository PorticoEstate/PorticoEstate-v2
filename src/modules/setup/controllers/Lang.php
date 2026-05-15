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
use App\modules\phpgwapi\services\Twig;
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
	private $translation;
	private $twig;

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
		$this->twig = Twig::getInstance();

		$flags = array(
			'noheader' 		=> True,
			'nonavbar'		=> True,
			'currentapp'	=> 'setup',
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

			Header('Location: ../setup/lang');
			exit;
		}
		else
		{
			$this->detection->check_lang(false);	// get installed langs

			$setup_data = Settings::getInstance()->get('setup');

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

			// Prepare template variables
			$templateVars = [
				'stage_title' => $stage_title,
				'stage_desc' => $stage_desc,
				'tbl_width' => $tbl_width,
				'td_colspan' => $td_colspan,
				'td_align' => $td_align,
				'hidden_var1' => $hidden_var1,
				'select_box_desc' => $select_box_desc,
				'checkbox_langs' => $checkbox_langs
			];

			// Add method description and options if not a new install
			if (!$newinstall)
			{
				$meth_desc = $this->setup->lang('Select which method of upgrade you would like to do');
				$blurb_addonlynew = $this->setup->lang('Only add languages that are not in the database already');
				$blurb_addmissing = $this->setup->lang('Only add new phrases');
				$blurb_dumpold = $this->setup->lang('Delete all old languages and install new ones');

				$templateVars['meth_desc'] = $meth_desc;
				$templateVars['blurb_addonlynew'] = $blurb_addonlynew;
				$templateVars['blurb_addmissing'] = $blurb_addmissing;
				$templateVars['blurb_dumpold'] = $blurb_dumpold;
			}

			$db_config = $this->db->get_config();

			// Get header and footer
			$header = $this->html->get_header($stage_title, False, 'config', $this->db->get_domain() . '(' . $db_config['db_type'] . ')');
			
			// Render the main template using Twig
			$main = $this->twig->render('lang_main.html.twig', $templateVars);
			
			$footer = $this->html->get_footer();
			
			return $header . $main . $footer;
		}
	}
}
