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
use App\modules\phpgwapi\services\Crypto;
use App\modules\phpgwapi\services\Twig;
use PDO;
use Sanitizer;

class ManageHeader
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

	private $serverSettings;
	private $crypto;
	private $configDir;



	public function __construct()
	{
		$this->configDir = dirname(__DIR__, 4) . '/config';

		//setup_info
		Settings::getInstance()->set('setup_info', []); //$GLOBALS['setup_info']
		//setup_data
		Settings::getInstance()->set('setup', []); //$setup_data
		$this->serverSettings = Settings::getInstance()->get('server');

		if (!empty($_POST['setting']['enable_crypto']))
		{
			$this->serverSettings['enable_crypto'] = $_POST['setting']['enable_crypto'];
			$_iv  = $_POST['setting']['mcrypt_iv'];
			$_key = $_POST['setting']['setup_mcrypt_key'];
		}
		else
		{
			$_iv  = isset($this->serverSettings['mcrypt_iv']) ? $this->serverSettings['mcrypt_iv'] : '';
			$_key = isset($this->serverSettings['setup_mcrypt_key']) ? $this->serverSettings['setup_mcrypt_key'] : '';
		}

		Settings::getInstance()->set('server', $this->serverSettings); // used in crypto
		if ($_key)
		{
			$this->crypto = Crypto::getInstance(array($_key, $_iv));
		}

		$this->db = Db::getInstance();
		$this->detection = new Detection();
		$this->process = new Process();
		$this->html = new Html($this->crypto);
		$this->setup = new Setup();
		$this->translation = new SetupTranslation();

		$flags = array(
			'noheader' 		=> True,
			'nonavbar'		=> True,
			'currentapp'	=> 'setup',
			'noapi'			=> True,
			'nocachecontrol' => True
		);
		$this->twig = Twig::getInstance();
		Settings::getInstance()->set('flags', $flags);

	}

	/**
	 * Check form values
	 */
	function check_form_values()
	{
		$errors = '';
		$domains = Sanitizer::get_var('domains', 'string', 'POST');
		if (!is_array($domains))
		{
			$domains = array();
		}

		foreach ($domains as $k => $v)
		{
			$deletedomain = Sanitizer::get_var('deletedomain', 'string', 'POST');
			if (isset($deletedomain[$k]))
			{
				continue;
			}

			if (!$_POST['settings'][$k]['config_pass'])
			{
				$errors .= '<br>' . $this->setup->lang("You didn't enter a config password for domain %1", $v);
			}
		}

		$setting = Sanitizer::get_var('setting', 'string', 'POST');
		if (!$setting['HEADER_ADMIN_PASSWORD'])
		{
			$errors .= '<br>' . $this->setup->lang("You didn't enter a header admin password");
		}

		if ($errors)
		{
			$ret_header = $this->html->get_header('Error', True);
			return $ret_header . $errors;
		}
	}

	public function index()
	{
		if (Sanitizer::get_var('cancel', 'bool', 'POST'))
		{
			Header('Location: ../setup');
			exit;
		}

		if (file_exists($this->configDir . '/header.inc.php'))
		{
			$phpgw_settings = require($this->configDir . '/header.inc.php');
		}

		srand((int)microtime() * 1000000);
		$random_char = array(
			'0',
			'1',
			'2',
			'3',
			'4',
			'5',
			'6',
			'7',
			'8',
			'9',
			'a',
			'b',
			'c',
			'd',
			'e',
			'f',
			'g',
			'h',
			'i',
			'j',
			'k',
			'l',
			'm',
			'n',
			'o',
			'p',
			'q',
			'r',
			's',
			't',
			'u',
			'v',
			'w',
			'x',
			'y',
			'z',
			'A',
			'B',
			'C',
			'D',
			'E',
			'F',
			'G',
			'H',
			'I',
			'J',
			'K',
			'L',
			'M',
			'N',
			'O',
			'P',
			'Q',
			'R',
			'S',
			'T',
			'U',
			'V',
			'W',
			'X',
			'Y',
			'Z'
		);

		if (!isset($this->serverSettings['mcrypt_iv']) || !$this->serverSettings['mcrypt_iv'])
		{
			$this->serverSettings['mcrypt_iv'] = '';
			for ($i = 0; $i < 30; ++$i)
			{
				$this->serverSettings['mcrypt_iv'] .= $random_char[rand(0, count($random_char) - 1)];
			}
		}

		if (!isset($this->serverSettings['setup_mcrypt_key']) || !$this->serverSettings['setup_mcrypt_key'])
		{
			$this->serverSettings['setup_mcrypt_key'] = '';
			for ($i = 0; $i < 32; ++$i)
			{
				$this->serverSettings['setup_mcrypt_key'] .= $random_char[rand(0, count($random_char) - 1)];
			}
		}

		/* authentication phase */
		$setup_data = Settings::getInstance()->get('setup');

		$setup_data['stage']['header'] = $this->detection->check_header();


		/* Detect current mode */
		switch ($setup_data['stage']['header'])
		{
			case 1:
				$setup_data['HeaderFormMSG'] = $this->setup->lang('Create your header.inc.php');
				$setup_data['PageMSG'] = $this->setup->lang('You have not created your header.inc.php yet!<br> You can create it now.');
				break;
			case 2:
				$setup_data['HeaderFormMSG'] = $this->setup->lang('Your header admin password is NOT set. Please set it now!');
				$setup_data['PageMSG'] = $this->setup->lang('Your header admin password is NOT set. Please set it now!');
				break;
			case 3:
				$setup_data['HeaderFormMSG'] = $this->setup->lang('Your header.inc.php needs upgrading.');
				$setup_data['PageMSG'] = $this->setup->lang('<p class="msg">Your header.inc.php needs upgrading.<br>WARNING! MAKE BACKUPS!</p>');
				$setup_data['HeaderLoginMSG'] = $this->setup->lang('Your header.inc.php needs upgrading.');
				Settings::getInstance()->set('setup', $setup_data);
				if (!$this->setup->auth('Header'))
				{
					$ret_header = $this->html->get_header('Please login', True);
					$login_form = $this->html->login_form();
					$ret_footer =  $this->html->get_footer();
					return $ret_header . $login_form . $ret_footer;
				}
				break;
			case 10:
				if (!$this->setup->auth('Header'))
				{
					//return to setup
					Header('Location: ../setup');
					exit;

					//					$ret_header = $this->html->get_header('Please login', True);
					//					$login_form = $this->html->login_form();
					//					$ret_footer =  $this->html->get_footer();
					//					return $ret_header . $login_form . $ret_footer;
				}
				$setup_data['HeaderFormMSG'] = $this->setup->lang('Edit your header.inc.php');
				$setup_data['PageMSG'] = $this->setup->lang('Edit your existing header.inc.php');
				break;
		}
		Settings::getInstance()->set('setup', $setup_data);

		$action = Sanitizer::get_var('action', 'string', 'POST');
		if (is_array($action))
		{
			$action_keys = array_keys($action);
			$action = array_shift($action_keys);
		}
		switch ($action)
		{
			case 'download':
				if ($errors = $this->check_form_values())
				{
					return $errors;
				}

				header('Content-disposition: attachment; filename="header.inc.php"');
				header('Content-type: application/octet-stream');
				header('Pragma: no-cache');
				header('Expires: 0');

				$newheader = $this->html->generate_header();
				return $newheader;
				break;
			case 'view':
				if ($errors = $this->check_form_values())
				{
					return $errors;
				}
				$ret_header = $this->html->get_header('Generated header.inc.php', False, 'header');
				$ret_footer =  $this->html->get_footer();

				$newheader = htmlspecialchars($this->html->generate_header());
				$lang_intro = $this->setup->lang('Save this text as contents of your header.inc.php');
				$lang_text = $this->setup->lang('After retrieving the file, put it into place as the header.inc.php.  Then, click "continue".');
				$lang_continue = $this->setup->lang('continue');

				return $ret_header
					. <<<HTML
				<h1>{$lang_intro}</h1>
				<pre id="header_contents">
$newheader
				</pre>
				<form class="pure-form pure-form-stacked" action="logout" method="post">
					$lang_text<br>
					<input type="hidden" name="FormLogout" value="header">
					<input type="submit" class="pure-button" name="junk" value="{$lang_continue}">
				</form>
				{$ret_footer}
HTML;
				break;
			case 'write':
				if ($errors = $this->check_form_values())
				{
					return $errors;
				}
				$lang_continue = $this->setup->lang('continue');
				if (is_writeable('../header.inc.php') || (!file_exists('../header.inc.php') && is_writeable('../')))
				{
					$newheader = $this->html->generate_header();
					$ret_footer =  $this->html->get_footer();

					$fsetup = fopen($this->configDir . '/header.inc.php', 'wb');
					fwrite($fsetup, $newheader);
					fclose($fsetup);
					$ret_header = $this->html->get_header('Saved header.inc.php', False, 'header');
					return $ret_header
						. <<<HTML
					<form action="manageheader" method="post">
						Created header.inc.php!
						<input type="hidden" name="FormLogout" value="header">
						<input type="submit" name="junk" value="{$lang_continue}">
					</form>
					{$ret_footer}
HTML;
				}
				else
				{
					$ret_header = $this->html->get_header('Error generating header.inc.php', False, 'header');
					$ret_header .= $this->setup->lang('Could not open header.inc.php for writing!') . '<br>' . "\n";
					$ret_header .= $this->setup->lang('Please check read/write permissions on directories, or back up and use another option.') . '<br>';
					$ret_header .= '</td></tr></table></body></html>';
					return $ret_header;
				}
				break;
			default:

				$ret_header = $this->html->get_header($setup_data['HeaderFormMSG'], False, 'header');

				$detected = '';

				$detected .= $setup_data['PageMSG'];

				if (version_compare(PHP_VERSION, '8.0.0') < 0)
				{
					$detected .= '<b><p align="center" class="msg">'
						. $this->setup->lang('You appear to be using PHP %1, %2 requires %3 or later', PHP_VERSION, 'PorticoEstate', '8.0.0') . "\n"
						. '</p></b><td></tr></table></body></html>';
					return $ret_header . $detected;
				}

				$detected = '';
				$request_order = '';
				/* 				if (version_compare(PHP_VERSION, '7.2.0') >= 0) {
					if (!preg_match("/C/i", ini_get('request_order')) || !preg_match("/S/i", ini_get('request_order'))) {
						$detected .= '<b><p align="center" class="msg">'
						. $this->setup->lang('You need to set request_order = "GPCS" in php.ini') . "\n"
						. '</p></b><td></tr></table></body></html>';
						die($detected);
					} else {
						$request_order = '<li>' . $this->setup->lang('You appear to have set request_order = "GPCS"') . "</li>\n";
					}
				}
 */
				if (!function_exists('json_encode')) // Some distributions have removed the standard JSON extension as of PHP 5.5rc2 due to a license conflict
				{
					$detected .= '<b><p align="center" class="msg">'
						. "You have to install php-json\n"
						. '</p></b><td></tr></table></body></html>';
					return $ret_header . $detected;
				}

				$get_max_value_length = '';
				if (ini_get('suhosin.get.max_value_length'))
				{
					if (ini_get('suhosin.get.max_value_length') < 2000)
					{
						$get_max_value_length = '<li class="warn">Speed could be gained from setting suhosin.get.max_value_length = 2000 in php.ini' . "</li>\n";
					}
					else
					{
						$get_max_value_length = '<li>' . $this->setup->lang('You appear to have suhosin.get.max_value_length > 2000') . "</li>\n";
					}
				}

				$phpver = '<li>' . $this->setup->lang('You appear to be using PHP %1+', '8.0') . "</li>\n";
				$supported_sessions_type = array('php', 'db');

				$detected .= '<table id="manageheader">' . "\n";

				//			if ( !isset($_POST['ConfigLang']) || !$_POST['ConfigLang'] )
				//			{
				//				$_POST['ConfigLang'] = 'en';
				//			}

				$default_lang =  Sanitizer::get_var('ConfigLang', 'string', 'POST', $this->serverSettings['default_lang']);

				$detected .= '<tr><td colspan="2"><form action="../setup/manageheader" method="post">Please Select your language ' . $this->html->lang_select(True) . "</form></td></tr>\n";

				$manual = '<a href="https://github.com/PorticoEstate/PorticoEstate/blob/master/doc/README.adoc" target="_blank">' . $this->setup->lang('Portico Estate Administration Manual') . '</a>';
				$detected .= '<tr><td colspan="2"><p><strong>' . $this->setup->lang('Please consult the %1.', $manual) . "</strong></td></tr>\n";

				$detected .= '<tr class="th"><td colspan="2">' . $this->setup->lang('Analysis') . "</td></tr><tr><td colspan=\"2\">\n<ul id=\"analysis\">\n$phpver";
				$detected .= $request_order;
				$detected .= $get_max_value_length;

				$supported_db = array();
				if (extension_loaded('pdo_pgsql'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have Postgres-DB support enabled') . "</li>\n";
					$supported_db[]  = 'postgres';
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No Postgres-DB support found. Disabling') . "</li>\n";
				}
				if (extension_loaded('pdo_mysql'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have MySQL support enabled') . "</li>\n";
					$supported_db[] = 'mysql';
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No MySQL support found. Disabling') . "</li>\n";
				}
				if (extension_loaded('mssql') || function_exists('mssql_connect') || extension_loaded('sqlsrv'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have Microsoft SQL Server support enabled') . "</li>\n";
					$supported_db[] = 'mssql';
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No Microsoft SQL Server support found. Disabling') . "</li>\n";
				}
				if (extension_loaded('pdo_oci'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have Oracle (PDO_OCI) support enabled') . "</li>\n";
					//				$supported_db[] = 'oracle';
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No Oracle-DB support found. Disabling') . "</li>\n";
				}

				if (!class_exists('ZipArchive'))
				{
					$detected .= '<li class="warn">' . $this->setup->lang('you need ZipArchive for Excel-support') . "</li>\n";
				}

				if (class_exists('SoapClient'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have Soap support enabled') . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('you may need Soap support for integration with other systems') . "</li>\n";
				}

				if (function_exists('ImageCreate'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have GD enabled') . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('you may need GD for image manipulation') . "</li>\n";
				}

				if (class_exists('imagick'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have imagick enabled') . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('you may need imagick for image manipulation') . "</li>\n";
				}

				if (function_exists('curl_init'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have curl enabled') . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('you may need curl for integration capabilities') . "</li>\n";
				}

				if (!count($supported_db))
				{
					$lang_nodb = $this->setup->lang('Did not find any valid DB support!');
					$lang_fix = $this->setup->lang('Try to configure your php to support one of the above mentioned DBMS, or install phpGroupWare by hand.');
					$detected .= <<<HTML
							<li class="err">$lang_nodb</li>
						</ul>
						<h2>$lang_fix</h2>
					</b>
				<td>
			</tr>
		</table>
	</body>
</html>

HTML;
					return $ret_header . $detected;
				}

				/*
			if (extension_loaded('xml') || function_exists('xml_parser_create'))
			{
				$detected .= $this->setup->lang('You appear to have XML support enabled') . '<br>' . "\n";
				$xml_enabled = 'True';
			}
			else
			{
				$detected .= $this->setup->lang('No XML support found. Disabling') . '<br>' . "\n";
			}
			*/

				if (extension_loaded('imap') || function_exists('imap_open'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have IMAP support enabled') . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No IMAP support found. Email functions will be disabled') . "</li>\n";
				}
				if (extension_loaded('shmop') || function_exists('shmop_open'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have support for shared memory') . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No support for shared memory found.') . "</li>\n";
				}
				if (extension_loaded('redis'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have Redis enabled') . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No Redis-DB support found. Disabling') . "</li>\n";
				}

				$supported_crypto_type = array();
				if (extension_loaded('libsodium') || extension_loaded('sodium'))
				{
					$supported_crypto_type[] = 'libsodium';
					$detected .= '<li>' . $this->setup->lang('You appear to have enabled support for libsodium %1', SODIUM_LIBRARY_VERSION) . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No libsodium support found.') . "</li>\n";
				}

				if (extension_loaded('mcrypt') || function_exists('mcrypt_list_modes'))
				{
					$supported_crypto_type[] = 'mcrypt';
					$detected .= '<li>' . $this->setup->lang('You appear to have enabled support for mcrypt') . "</li>\n";
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('No mcrypt support found.') . "</li>\n";
				}


				if (extension_loaded('xsl') && class_exists('XSLTProcessor'))
				{
					$detected .= '<li>' . $this->setup->lang('You appear to have XML/XSLT support enabled') . "</li>\n";
				}
				else
				{
					$lang_noxsl = $this->setup->lang('No XSL support found.');
					$lang_fix = $this->setup->lang('You must install the php-xsl extension to continue');
					$detected .= <<<HTML
							<li class="err">$lang_noxsl</li>
						</ul>
						<h2>$lang_fix</h2>
					</b>
				<td>
			</tr>
		</table>
	</body>
</html>

HTML;
					return $ret_header . $detected;
				}

				$no_guess = false;
				if (
					is_file(SRC_ROOT_PATH . '/../config/header.inc.php')
					&& is_readable(SRC_ROOT_PATH . '/../config/header.inc.php')
				)
				{
					$detected .= '<li>' . $this->setup->lang('Found existing configuration file. Loading settings from the file...') . "</li>\n";
					$no_guess = true;
					/* This code makes sure the newer multi-domain supporting header.inc.php is being used */
					if (!isset($phpgw_settings['phpgw_domain']))
					{
						$detected .= '<li class="warn">' . $this->setup->lang("You're using an old configuration file format...") . "</li>\n";
						$detected .= '<li>' . $this->setup->lang('Importing old settings into the new format....') . "</li>\n";
					}
					else
					{
						if ($setup_data['stage']['header'] == 3)
						{
							$detected .= '<li class="warn">' . $this->setup->lang("You're using an old header.inc.php version...") . "</li>\n";
							$detected .= '<li>' . $this->setup->lang('Importing old settings into the new format....') . "</li>\n";
						}

						if (!isset($phpgw_settings['phpgw_domain']))
						{
							$phpgw_settings['phpgw_domain'] = array();
						}

						$default_domain = key($phpgw_settings['phpgw_domain']);
						$this->serverSettings['default_domain'] = $default_domain;
						unset($default_domain); // we kill this for security reasons
						$this->serverSettings['config_passwd'] = $phpgw_settings['phpgw_domain'][$this->serverSettings['default_domain']]['config_passwd'];


						if (Sanitizer::get_var('adddomain', 'string', 'POST'))
						{
							$phpgw_settings['phpgw_domain'][$this->setup->lang('new')] = array();
						}

					}

					if (defined('PHPGW_SERVER_ROOT'))
					{
						$this->serverSettings['server_root'] = PHPGW_SERVER_ROOT;
						$this->serverSettings['include_root'] = PHPGW_SERVER_ROOT;
					}
					else if (!isset($this->serverSettings['include_root']) && $this->serverSettings['header_version'] <= 1.6)
					{
						$this->serverSettings['include_root'] = $this->serverSettings['server_root'];
					}
					else if (!isset($this->serverSettings['header_version']) && $this->serverSettings['header_version'] <= 1.6)
					{
						$this->serverSettings['include_root'] = $this->serverSettings['server_root'];
					}
					$comment_l = '';
					$comment_r = '';

					// Generate domains HTML using Twig
					$domainsHtml = '';
					if (isset($phpgw_settings['phpgw_domain']) && is_array($phpgw_settings['phpgw_domain']))
					{
						foreach ($phpgw_settings['phpgw_domain'] as $key => $val)
						{
							$selected = '';
							$dbtype_options = '';
							foreach ($supported_db as $db)
							{
								$phpgw_settings['phpgw_domain'][$key]['db_type'] = $phpgw_settings['phpgw_domain'][$key]['db_type'] == 'pgsql' ? 'postgres' : $phpgw_settings['phpgw_domain'][$key]['db_type']; // upgrade from 0.9.16
								if ($db == $phpgw_settings['phpgw_domain'][$key]['db_type'])
								{
									$selected = ' selected';
								}
								else
								{
									$selected = '';
								}
								$dbtype_options .= <<<HTML
								<option{$selected} value="{$db}">$db</option>

HTML;
							}

							// Create domain variables for Twig template
							$domainVars = [
								'db_domain' => $key,
								'db_host' => $this->crypto->decrypt($val['db_host']),
								'db_port' => $this->crypto->decrypt($val['db_port']),
								'db_name' => $this->crypto->decrypt($val['db_name']),
								'db_user' => $this->crypto->decrypt($val['db_user']),
								'db_pass' => $this->crypto->decrypt($val['db_pass']),
								'db_type' => $val['db_type'],
								'config_pass' => $this->crypto->decrypt($val['config_passwd']),
								'dbtype_options' => $dbtype_options,
							];

							// Render domain block using Twig
							$domainsHtml .= $this->twig->renderBlock('manageheader.html.twig', 'domain', $domainVars);

							$i++;
						}
					}
				}
				else
				{
					$detected .= '<li class="warn">' . $this->setup->lang('Sample configuration not found. using built in defaults') . "</li>\n";
					$comment_l = '<!-- ';
					$comment_r = ' -->';

					$domainVars = [
						'db_domain' => 'default',
						'db_host' => 'localhost',
						'db_port' => '',
						'db_name' => 'phpgroupware',
						'db_user' => 'phpgroupware',
						'db_pass' => 'your_password',
						'db_type' => $supported_db[0],
						'config_pass' => 'changeme',
						'dbtype_options' => $dbtype_options,
					];

					$domainsHtml = $this->twig->renderBlock('manageheader.html.twig', 'domain', $domainVars);
				}

				// now guessing better settings then the default ones
				if (!$no_guess)
				{
					$detected .= '<li>' . $this->setup->lang('Now guessing better values for defaults...') . "</li>\n";
					$updir    =	dirname(__DIR__, 2);
					$this->serverSettings['server_root'] = $updir;
					$this->serverSettings['include_root'] = $updir;
				}

				$selected = '';
				$session_options = '';
				foreach ($supported_sessions_type as $stype)
				{
					$selected = '';
					if (
						isset($this->serverSettings['sessions_type'])
						&& $stype == $this->serverSettings['sessions_type']
					)
					{
						$selected = ' selected ';
					}
					$session_options .= <<<HTML
					<option{$selected} value="{$stype}">{$stype}</option>
HTML;
				}

				unset($stype);
				$selected = '';
				$crypto_options = <<<HTML
				<option value="">None</option>

HTML;
				foreach ($supported_crypto_type as $stype)
				{
					$selected = '';
					if (
						isset($this->serverSettings['enable_crypto'])
						&& $stype == $this->serverSettings['enable_crypto']
					)
					{
						$selected = ' selected ';
					}

					if (!empty($this->serverSettings['mcrypt_enabled']) && $stype == 'mcrypt')
					{
						$selected = ' selected ';
					}

					$crypto_options .= <<<HTML
					<option{$selected} value="{$stype}">{$stype}</option>
HTML;
				}				

				$detected .= "</ul>\n";

				// Build templateVars array for Twig template rendering
				$templateVars = [
					'detected' => $detected,
					'server_root' => $this->serverSettings['server_root'],
					'include_root' => $this->serverSettings['include_root'],
					'header_admin_password' => !empty($this->serverSettings['header_admin_password']) ? $this->crypto->decrypt($this->serverSettings['header_admin_password']) : '',
					'system_name' => isset($this->serverSettings['system_name']) ? $this->serverSettings['system_name'] : 'Portico Estate',
					'default_lang' => $default_lang,
					'login_left_message' => isset($this->serverSettings['login_left_message']) ?
						str_replace(array('<br>', '</br>', '<br />', '<', '>', '"'), array("\n", "\n", "", '[', ']', '&quot;'), $this->serverSettings['login_left_message']) : '',
					'login_right_message' => isset($this->serverSettings['login_right_message']) ?
						str_replace(array('<br>', '</br>', '<br />', '<', '>', '"'), array("\n", "\n", "", '[', ']', '&quot;'), $this->serverSettings['login_right_message']) : '',
					'new_user_url' => isset($this->serverSettings['new_user_url']) ? $this->serverSettings['new_user_url'] : '',
					'lost_password_url' => isset($this->serverSettings['lost_password_url']) ? $this->serverSettings['lost_password_url'] : '',
					'db_persistent_yes' => isset($this->serverSettings['db_persistent']) && $this->serverSettings['db_persistent'] ? ' selected' : '',
					'db_persistent_no' => isset($this->serverSettings['db_persistent']) && $this->serverSettings['db_persistent'] ? '' : ' selected',
					'session_options' => $session_options,
					'crypto_options' => $crypto_options,
					'mcrypt_enabled_yes' => isset($this->serverSettings['mcrypt_enabled']) && $this->serverSettings['mcrypt_enabled'] ? ' selected' : '',
					'mcrypt_enabled_no' => isset($this->serverSettings['mcrypt_enabled']) && $this->serverSettings['mcrypt_enabled'] ? '' : ' selected',
					'mcrypt_iv' => $this->serverSettings['mcrypt_iv'],
					'setup_mcrypt_key' => $this->serverSettings['setup_mcrypt_key'],
					'setup_acl' => !isset($this->serverSettings['setup_acl']) || !$this->serverSettings['setup_acl'] ? '127.0.0.1' : $this->serverSettings['setup_acl'],
					'domain_selectbox_yes' => isset($this->serverSettings['show_domain_selectbox']) && $this->serverSettings['show_domain_selectbox'] ? ' selected' : '',
					'domain_selectbox_no' => isset($this->serverSettings['show_domain_selectbox']) && $this->serverSettings['show_domain_selectbox'] ? '' : ' selected',
					'domain_from_host_yes' => isset($this->serverSettings['domain_from_host']) && $this->serverSettings['domain_from_host'] ? ' selected' : '',
					'domain_from_host_no' => isset($this->serverSettings['domain_from_host']) && $this->serverSettings['domain_from_host'] ? '' : ' selected',
					'errors' => $errors,
					'comment_l' => $comment_l,
					'comment_r' => $comment_r,
					'formend' => ''
				];



				$templateVars['domains'] = $domainsHtml;
				if (
					is_writeable('../header.inc.php') ||
					(!file_exists('../header.inc.php') && is_writeable('../'))
				)
				{
					$errors = '<br><input type="submit" name="action[write]" value="' . $this->setup->lang('Write config') . '">&nbsp;'
						. $this->setup->lang('or') . '&nbsp;<input type="submit" name="action[download]" value="' . $this->setup->lang('Download') . '">&nbsp;'
						. $this->setup->lang('or') . '&nbsp;<input type=submit name="action[view]" value="' . $this->setup->lang('View') . '"> ' . $this->setup->lang('the file') . '.</form>';
				}
				else
				{
					$errors = '<br>'
						. $this->setup->lang(
							'Cannot create the header.inc.php due to file permission restrictions.<br> Instead you can %1 the file.',
							'<input type="submit" name="action[download]" value="' . $this->setup->lang('Download') . '">' . $this->setup->lang('or') . '&nbsp;<input type="submit" name="action[view]" value="' . $this->setup->lang('View') . '">'
						)
						. '</form>';
				}

				$templateVars['errors'] = $errors;

				// Add language variables for Twig template
				$langVars = [
					'settings' => $this->setup->lang('Settings'),
					'adddomain' => $this->setup->lang('Add a domain'),
					'serverroot' => $this->setup->lang('Server Root'),
					'includeroot' => $this->setup->lang('Include Root (this should be the same as Server Root unless you know what you are doing)'),
					'adminpass' => $this->setup->lang('Admin password to header manager'),
					'system_name' => $this->setup->lang('System name'),
					'login_left_message' => $this->setup->lang('login left message'),
					'login_right_message' => $this->setup->lang('login right message'),
					'new_user' => $this->setup->lang('url new user'),
					'forgotten_password' => $this->setup->lang('url forgotten password'),
					'persist' => $this->setup->lang('Persistent connections'),
					'persistdescr' => $this->setup->lang('Do you want persistent connections (higher performance, but consumes more resources)'),
					'sesstype' => $this->setup->lang('Sessions Type'),
					'sesstypedescr' => $this->setup->lang('What type of sessions management do you want to use (PHP session management usually performs better)?'),
					'enable_crypto' => $this->setup->lang('Enable Crypto'),
					'mcryptiv' => $this->setup->lang('MCrypt initialization vector'),
					'mcryptivdescr' => $this->setup->lang('This should be around 32 bytes in length.<br>Note: The default has been randomly generated.'),
					'setup_mcrypt_key' => $this->setup->lang('Enter some random text as encryption key for the setup encryption'),
					'setup_mcrypt_key_descr' => $this->setup->lang('This should be around 32 bytes in length.<br>Note: The default has been randomly generated.'),
					'domselect' => $this->setup->lang('Domain select box on login'),
					'domain_from_host' => $this->setup->lang('Automatically detect domain from hostname'),
					'note_domain_from_host' => $this->setup->lang('Note: This option will only work if show domain select box is off.'),
					'finaldescr' => $this->setup->lang('After retrieving the file, put it into place as the header.inc.php.  Then, click "continue".'),
					'continue' => $this->setup->lang('Continue'),
					'True' => $this->setup->lang('True'),
					'False' => $this->setup->lang('False')
				];

				// Merge language variables with template variables
				$templateVars = array_merge($templateVars, ['lang' => $langVars]);

				// Render manageheader block using Twig
				$manageheaderHtml = $this->twig->renderBlock('manageheader.html.twig', 'manageheader', $templateVars);
				return $ret_header . $manageheaderHtml;
				// ending the switch default
		}
	}
}
