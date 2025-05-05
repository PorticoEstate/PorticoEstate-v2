<?php

namespace App\modules\setup\controllers;

use App\Database\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\setup\Setup;
use App\modules\phpgwapi\services\setup\Detection;
use App\modules\phpgwapi\services\setup\Process;
use App\modules\phpgwapi\services\setup\Html;
use App\modules\phpgwapi\services\Twig as TwigService;
use App\helpers\Template;

use App\modules\setup\controllers\SqlToArray;
use App\modules\setup\controllers\Applications;
use App\modules\setup\controllers\Lang;
use App\modules\setup\controllers\Config;
use App\modules\setup\controllers\Ldap;
use App\modules\setup\controllers\Accounts;


class SetupController
{
	private $twig;
	private $db;
	private $detection;
	private $process;
	private $html;
	private $setup;
	private $serverSettings;


	public function __construct()
	{
		ini_set('session.use_cookies', true);

		//setup_info
		Settings::getInstance()->set('setup_info', []); //$GLOBALS['setup_info']
		//setup_data
		Settings::getInstance()->set('setup', []); //$GLOBALS['phpgw_info']['setup']
		$this->serverSettings = Settings::getInstance()->get('server');
		$flags = array(
			'noheader' 		=> True,
			'nonavbar'		=> True,
			'currentapp'	=> 'setup',
			'noapi'			=> True,
			'nocachecontrol' => True
		);
		Settings::getInstance()->set('flags', $flags);

		$this->db = Db::getInstance();
		$this->detection = new Detection();
		$this->process = new Process();
		$this->html = new Html();
		$this->setup = new Setup();


		// Initialize Twig service
		$this->twig = TwigService::getInstance();
	}

	public function logout(Request $request, Response $response, $args)
	{
		$this->setup->auth('Config');


		//write a Html text with status logged out - with link to login
		$htmlText = '<p>Status: Logged out</p>';
		$htmlText .= '<p><a href="../setup/">Click here to login to setup</a></p>';
		$htmlText .= '<p><a href="../setup">Click here to login to Manageheader</a></p>';
		$htmlText .= '<p><a href="../login_ui">Click here to login to UI</a></p>';
		$htmlText .= '<p><a href="../login">Click here to login to API</a></p>';

		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($htmlText);
		return $response;
	}


	public function	SqlToArray(Request $request, Response $response, $args)
	{
		$SqlToArray = new SqlToArray();
		$ret = $SqlToArray->index();

		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($ret);
		return $response;
	}
	public function	Applications(Request $request, Response $response, $args)
	{

		$Applications = new Applications();
		$ret = $Applications->index();

		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($ret);
		return $response;
	}

	public function	Lang(Request $request, Response $response, $args)
	{

		$Applications = new Lang();
		$ret = $Applications->index();

		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($ret);
		return $response;
	}

	public function	Config(Request $request, Response $response, $args)
	{

		$Config = new Config();
		$ret = $Config->index();

		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($ret);
		return $response;
	}

	public function	Ldap(Request $request, Response $response, $args)
	{

		$Ldap = new Ldap();
		$ret = $Ldap->index();

		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($ret);
		return $response;
	}



	public function	Accounts(Request $request, Response $response, $args)
	{

		$Accounts = new Accounts();
		$ret = $Accounts->index();

		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($ret);
		return $response;
	}

	public function	ManageHeader(Request $request, Response $response, $args)
	{

		$ManageHeader = new ManageHeader();
		$ret = $ManageHeader->index();

		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($ret);
		return $response;
	}



	function index(Request $request, Response $response, $args)
	{
		$setup_data = Settings::getInstance()->get('setup');
		$serverSettings = Settings::getInstance()->get('server');

		$GLOBALS['DEBUG'] = isset($_REQUEST['DEBUG']) && $_REQUEST['DEBUG'];

		@set_time_limit(0);

		// Get CSS
		$css = '';
		if (is_file(dirname(__DIR__, 2) . "/phpgwapi/templates/pure/css/version_3/pure-min.css"))
		{
			$css = file_get_contents(dirname(__DIR__, 2) . "/phpgwapi/templates/pure/css/version_3/pure-min.css");
		}

		// Check header and authentication
		$setup_data['stage']['header'] = $this->detection->check_header();

		if ($setup_data['stage']['header'] == '1')
		{
			Header('Location: ../setup/manageheader');
			exit;
		}
		else if ($setup_data['stage']['header'] != '10')
		{
			Header('Location: ../setup');
			exit;
		}
		elseif (!$this->setup->auth('Config'))
		{
			$_POST['ConfigLang'] = isset($this->serverSettings['default_lang']) ? $this->serverSettings['default_lang'] : '';

			$header = $this->html->get_header(lang('Please login'), True);
			$login_form = $this->html->login_form();
			$footer = $this->html->get_footer();


			$response = new \Slim\Psr7\Response();
			$response->getBody()->write($header . $login_form . $footer);

			return $response;
		}

		$this->setup->loaddb();

		$setup_info = $this->detection->get_versions();
		$setup_data['stage']['db'] = $this->detection->check_db();
		if ($setup_data['stage']['db'] != 1)
		{
			$setup_info = $this->detection->get_db_versions($setup_info);
			$setup_data['stage']['db'] = $this->detection->check_db();
			if ($GLOBALS['DEBUG'])
			{
				echo '<pre>';
				print_r($setup_info);
				echo '</pre>';
			}
		}

		/**
		 * Update code from SVN
		 */
		$subtitle = '';
		$submsg = '';
		$subaction = '';
		$setup_data['stage']['svn'] = 1; //default
		$svn_block_data = [];

		switch (\Sanitizer::get_var('action_svn'))
		{
			case 'check_for_svn_update':
				$subtitle = $this->setup->lang('check for update');
				$submsg = $this->setup->lang('At your request, this script is going to attempt to check for updates from github');
				$setup_data['currentver']['phpgwapi'] = 'check_for_svn_update';
				$setup_data['stage']['svn'] = 2;
				break;
			case 'perform_svn_update':
				$subtitle = $this->setup->lang('uppdating code');
				$submsg = $this->setup->lang('At your request, this script is going to attempt updating the system from github');
				$setup_data['currentver']['phpgwapi'] = 'perform_svn_update';
				$setup_data['stage']['svn'] = 1; // alternate
				break;
		}

		// Handle database actions
		switch (\Sanitizer::get_var('action'))
		{
			case 'Uninstall all applications':
				$subtitle = $this->setup->lang('Deleting Tables');
				$submsg = $this->setup->lang('Are you sure you want to delete your existing tables and data?') . '.';
				$subaction = $this->setup->lang('uninstall');
				$setup_data['currentver']['phpgwapi'] = 'predrop';
				$setup_data['stage']['db'] = 5;
				break;
			case 'Create Database':
				$subtitle = $this->setup->lang('Create Database');
				$submsg = $this->setup->lang('At your request, this script is going to attempt to create the database and assign the db user rights to it');
				$subaction = $this->setup->lang('created');
				$setup_data['currentver']['phpgwapi'] = 'dbcreate';
				$setup_data['stage']['db'] = 6;
				break;
			case 'REALLY Uninstall all applications':
				$subtitle = $this->setup->lang('Deleting Tables');
				$submsg = $this->setup->lang('At your request, this script is going to take the evil action of uninstalling all your apps, which deletes your existing tables and data') . '.';
				$subaction = $this->setup->lang('uninstalled');
				$setup_data['currentver']['phpgwapi'] = 'drop';
				$setup_data['stage']['db'] = 6;
				break;
			case 'Upgrade':
				$subtitle = $this->setup->lang('Upgrading Tables');
				$submsg = $this->setup->lang('At your request, this script is going to attempt to upgrade your old applications to the current versions') . '.';
				$subaction = $this->setup->lang('upgraded');
				$setup_data['currentver']['phpgwapi'] = 'oldversion';
				$setup_data['stage']['db'] = 6;
				break;
			case 'Install':
				$subtitle = $this->setup->lang('Creating Tables');
				$submsg = $this->setup->lang('At your request, this script is going to attempt to install the core tables and the admin and preferences applications for you') . '.';
				$subaction = $this->setup->lang('installed');
				$setup_data['currentver']['phpgwapi'] = 'new';
				$setup_data['stage']['db'] = 6;
				break;
		}

		// Check PHP version
		if (version_compare(phpversion(), '8.0.0', '<'))
		{
			// Render error using renderBlock
			$errorData = [
				'title' => 'Error',
				'message' => $this->setup->lang('You appear to be using PHP %1. Portico now requires PHP 8.0 or later', phpversion())
			];

			$content = $this->twig->render('head.html.twig', ['title' => 'Error']);
			$content .= $this->twig->renderBlock('alerts.html.twig', 'error_message', $errorData);
			$content .= $this->twig->render('footer.html.twig', []);

			$response = new \Slim\Psr7\Response();
			$response->getBody()->write($content);
			return $response;
		}

		// Set up images paths
		$serverSettings['app_images'] = 'templates/base/images';
		$serverSettings['api_images'] = './src/modules/phpgwapi/templates/base/images';
		$incomplete = "{$serverSettings['api_images']}/stock_no.png";
		$completed  = "{$serverSettings['api_images']}/stock_yes.png";

		// Process SVN block
		$svn_block_data = [];
		switch ($setup_data['stage']['svn'])
		{
			case 1:
				$svn_block_data = [
					'sudo_user_label' => $this->setup->lang('sudo user'),
					'sudo_password_label' => $this->setup->lang('password for %1', getenv('APACHE_RUN_USER')),
					'svnwarn' => $this->setup->lang('will try to perform a svn status -u'),
					'check_for_svn_update' => $this->setup->lang('check update'),
					'svn_message' => '',
				];

				if (isset($setup_data['currentver']['phpgwapi']) && $setup_data['currentver']['phpgwapi'] == 'perform_svn_update')
				{
					// Github update logic would go here
					$svn_block_data['svn_message'] = ''; // Message from SVN update
				}
				break;

			case 2:
				$svn_block_data = [
					'sudo_user' => $this->setup->lang('sudo user'),
					'value_sudo_user' => \Sanitizer::get_var('sudo_user'),
					'value_sudo_password' => \Sanitizer::get_var('sudo_password'),
					'sudo_password' => $this->setup->lang('password for %1', getenv('APACHE_RUN_USER')),
					'perform_svn_update' => $this->setup->lang('perform github update'),
					'execute' => $this->setup->lang('execute'),
					'svnwarn' => $this->setup->lang('will try to perform a git pull'),
					'svn_message' => '',
				];

				if (isset($setup_data['currentver']['phpgwapi']) && $setup_data['currentver']['phpgwapi'] == 'check_for_svn_update')
				{
					// SVN check logic would go here
					$svn_block_data['svn_message'] = ''; // Message from SVN check
				}
				break;
		}

		// Process DB block
		$db_config = $this->db->get_config();
		$db_block_data = [];
		$db_stage_content = '';

		// Set up images paths

		// Add common image variables to all DB blocks
		$commonBlockData = [
			'img_incomplete' => $incomplete,
			'img_completed' => $completed,
			'notcomplete' => $this->setup->lang('not complete'),
			'completed' => $this->setup->lang('completed'),
			'subtitle' => $subtitle,
			'submsg' => $submsg,
			'subaction' => $subaction
		];

		switch ($setup_data['stage']['db'])
		{
			case 1:
				$db_block_data = array_merge($commonBlockData, [
					'dbnotexist' => $this->setup->lang('Your Database is not working!'),
					'makesure' => $this->setup->lang('makesure'),
					'oncesetup' => $this->setup->lang('Once the database is setup correctly'),
					'createdb' => $this->setup->lang('Or we can attempt to create the database for you:'),
					'create_database' => $this->setup->lang('Create database'),
				]);

				switch ($db_config['db_type'])
				{
					case 'mysql':
						$db_block_data['instr'] = $this->setup->lang('mysqlinstr %1', $db_config['db_name']);
						$db_block_data['db_root'] = 'root';
						break;
					case 'pgsql':
					case 'postgres':
						$db_block_data['instr'] = $this->setup->lang('pgsqlinstr %1', $db_config['db_name']);
						$db_block_data['db_root'] = 'postgres';
						break;
				}

				$db_stage_content = $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_1', $db_block_data);
				break;

			case 2:
				$db_block_data = array_merge($commonBlockData, [
					'prebeta' => $this->setup->lang('You appear to be running a pre-beta version of phpGroupWare.<br />These versions are no longer supported, and there is no upgrade path for them in setup.<br /> You may wish to first upgrade to 0.9.10 (the last version to support pre-beta upgrades) <br />and then upgrade from there with the current version.')
				]);

				$db_stage_content = $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_2', $db_block_data);
				break;

			case 3:
				$db_block_data = array_merge($commonBlockData, [
					'dbexists' => $this->setup->lang('Your database is working, but you dont have any applications installed'),
					'install' => $this->setup->lang('Install'),
					'proceed' => $this->setup->lang('We can proceed'),
					'coreapps' => $this->setup->lang('all core tables and the admin and preferences applications')
				]);

				$db_stage_content = $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_3', $db_block_data);
				break;

			case 4:
				$db_block_data = array_merge($commonBlockData, [
					'oldver' => $this->setup->lang('You appear to be running version %1 of phpGroupWare', $setup_info['phpgwapi']['currentver']),
					'automatic' => $this->setup->lang('We will automatically update your tables/records to %1', $setup_info['phpgwapi']['version']),
					'backupwarn' => $this->setup->lang('backupwarn'),
					'upgrade' => $this->setup->lang('Upgrade'),
					'goto' => $this->setup->lang('Go to'),
					'configuration' => $this->setup->lang('configuration'),
					'applications' => $this->setup->lang('Manage Applications'),
					'language_management' => $this->setup->lang('Manage Languages'),
					'uninstall_all_applications' => $this->setup->lang('Uninstall all applications'),
					'dont_touch_my_data' => $this->setup->lang('Dont touch my data'),
					'dropwarn' => $this->setup->lang('Your tables may be altered and you may lose data')
				]);

				$db_stage_content = $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_4', $db_block_data);
				break;

			case 5:
				$db_block_data = array_merge($commonBlockData, [
					'are_you_sure' => $this->setup->lang('ARE YOU SURE?'),
					'really_uninstall_all_applications' => $this->setup->lang('REALLY Uninstall all applications'),
					'dropwarn' => $this->setup->lang('Your tables will be dropped and you will lose data'),
					'cancel' => $this->setup->lang('cancel')
				]);

				$db_stage_content = $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_5', $db_block_data);
				break;

			case 6:
				$pre_data = array_merge($commonBlockData, [
					'status' => $this->setup->lang('Status'),
					'tblchange' => $this->setup->lang('Table Change Messages')
				]);

				$db_stage_content = $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_6_pre', $pre_data);

				// Process database operations
				$this->db->set_halt_on_error('yes');
				$this->db->transaction_begin();

				switch ($setup_data['currentver']['phpgwapi'])
				{
					case 'dbcreate':
						try
						{
							$this->db->create_database($_POST['db_root'], $_POST['db_pass']);
						}
						catch (\Exception $e)
						{
							if ($e)
							{
								$pre_data['status'] = 'Error: ' . $e->getMessage();
							}
						}
						break;
					case 'drop':
						$setup_info = $this->detection->get_versions($setup_info);
						$setup_info = $this->process->droptables($setup_info);
						break;
					case 'new':
						// Only process phpgwapi, admin and preferences.
						$setup_info = $this->detection->base_install($setup_info);
						$setup_info = $this->process->pass($setup_info, 'new', false, true);
						$setup_data['currentver']['phpgwapi'] = 'oldversion';
						break;
					case 'oldversion':
						$setup_info = $this->process->pass($GLOBALS['setup_info'], 'upgrade', $GLOBALS['DEBUG']);
						$setup_data['currentver']['phpgwapi'] = 'oldversion';
						break;
				}

				$this->db->set_halt_on_error('no');
				$this->db->transaction_commit();

				$post_data = array_merge($commonBlockData, [
					'tableshave' => $this->setup->lang('If you did not receive any errors, your applications have been'),
					're-check_my_installation' => $this->setup->lang('Re-Check My Installation')
				]);

				$db_stage_content .= $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_6_post', $post_data);
				break;

			case 10:
				$db_block_data = array_merge($commonBlockData, [
					'tablescurrent' => $this->setup->lang('Your applications are current'),
					'uninstall_all_applications' => $this->setup->lang('Uninstall all applications'),
					'insanity' => $this->setup->lang('Insanity'),
					'dropwarn' => $this->setup->lang('Your tables will be dropped and you will lose data'),
					'deletetables' => $this->setup->lang('Uninstall all applications')
				]);

				$db_stage_content = $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_10', $db_block_data);
				break;

			default:
				$db_block_data = array_merge($commonBlockData, [
					'dbnotexist' => $this->setup->lang('Your database does not exist'),
					'create_one_now' => $this->setup->lang('Create one now')
				]);

				$db_stage_content = $this->twig->renderBlock('setup_db_blocks.html.twig', 'B_db_stage_default', $db_block_data);
				break;
		}

		// Update settings
		Settings::getInstance()->set('setup', $setup_data);

		// Config Section
		$config_step_text = $this->setup->lang('Step 2 - Configuration');
		$setup_data['stage']['config'] = $this->detection->check_config();

		$config_status = [
			'img' => $incomplete,
			'alt' => $this->setup->lang('not completed'),
			'table_data' => '',
			'ldap_table_data' => '&nbsp;'
		];

		switch ($setup_data['stage']['config'])
		{
			case 1:
				$config_status['img'] = $incomplete;
				$config_status['alt'] = $this->setup->lang('not completed');
				$config_status['table_data'] = $this->html->make_frm_btn_simple(
					$this->setup->lang('Please configure phpGroupWare for your environment'),
					'POST',
					'setup/config',
					'submit',
					$this->setup->lang('Configure Now'),
					''
				);
				break;

			case 10:
				$config_status['img'] = $completed;
				$config_status['alt'] = $this->setup->lang('completed');
				$completed_notice = '';

				// Check files_dir and temp_dir
				$stmt = $this->db->prepare("SELECT config_value FROM phpgw_config WHERE config_app = 'phpgwapi' AND config_name='files_dir'");
				$stmt->execute();
				$files_dir = $stmt->fetchColumn();

				$stmt = $this->db->prepare("SELECT config_value FROM phpgw_config WHERE config_app = 'phpgwapi' AND config_name='file_store_contents'");
				$stmt->execute();
				$file_store_contents = $stmt->fetchColumn();

				if ($files_dir && $file_store_contents == 'filesystem')
				{
					if (!is_dir($files_dir))
					{
						$completed_notice .= '<br /><b>' . $this->setup->lang('files dir %1 is not a directory', $files_dir) . '</b>';
					}
					if (!is_readable($files_dir))
					{
						$completed_notice .= '<br /><b>' . $this->setup->lang('files dir %1 is not readable', $files_dir) . '</b>';
					}
					if (!is_writable($files_dir))
					{
						$completed_notice .= '<br /><b>' . $this->setup->lang('files dir %1 is not writeable', $files_dir) . '</b>';
					}
				}

				$stmt = $this->db->prepare("SELECT config_value FROM phpgw_config WHERE config_app = 'phpgwapi' AND config_name='temp_dir'");
				$stmt->execute();
				$temp_dir = $stmt->fetchColumn();

				if ($temp_dir)
				{
					if (!is_dir($temp_dir))
					{
						$completed_notice .= '<br /><b>' . $this->setup->lang('temp dir %1 is not a directory', $temp_dir) . '</b>';
					}
					if (!is_readable($temp_dir))
					{
						$completed_notice .= '<br /><b>' . $this->setup->lang('temp dir %1 is not readable', $temp_dir) . '</b>';
					}
					if (!is_writable($temp_dir))
					{
						$completed_notice .= '<br /><b>' . $this->setup->lang('temp dir %1 is not writeable', $temp_dir) . '</b>';
					}
				}

				$config_status['table_data'] = $this->html->make_frm_btn_simple(
					$this->setup->lang('Configuration completed'),
					'POST',
					'setup/config',
					'submit',
					$this->setup->lang('Edit Current Configuration'),
					$completed_notice
				);

				// Check for LDAP settings
				$stmt = $this->db->prepare("SELECT config_value FROM phpgw_config WHERE config_name='auth_type'");
				$stmt->execute();
				$auth_type = $stmt->fetchColumn();

				if ($auth_type == 'ldap')
				{
					$stmt = $this->db->prepare("SELECT config_value FROM phpgw_config WHERE config_name='ldap_host'");
					$stmt->execute();
					$ldap_host = $stmt->fetchColumn();

					if ($ldap_host != '')
					{
						$config_status['ldap_table_data'] = $this->html->make_frm_btn_simple(
							$this->setup->lang('LDAP account import/export'),
							'POST',
							'setup/ldap',
							'submit',
							$this->setup->lang('Configure LDAP accounts'),
							''
						);
					}

					$stmt = $this->db->prepare("SELECT config_value FROM phpgw_config WHERE config_name='webserver_url'");
					$stmt->execute();
					$webserver_url = $stmt->fetchColumn();

					if ($webserver_url)
					{
						$config_status['table_data'] .= $this->html->make_href_link_simple(
							'<br>',
							'setup/accounts',
							$this->setup->lang('Setup an Admininstrator account'),
							$this->setup->lang('and optional demo accounts.')
						);
					}
				}
				else
				{
					$stmt = $this->db->prepare("SELECT config_value FROM phpgw_config WHERE config_name = 'account_repository'");
					$stmt->execute();
					$account_repository = $stmt->fetchColumn();

					$account_creation_notice = $this->setup->lang('and optional demo accounts.');

					if ($account_repository == 'sql')
					{
						$stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM phpgw_accounts");
						$stmt->execute();
						$number_of_accounts = (int) $stmt->fetchColumn();

						if ($number_of_accounts > 0)
						{
							$account_creation_notice .= $this->setup->lang('<br /><b>This will delete all existing accounts.</b>');
						}
					}
					$config_status['table_data'] .= $this->html->make_href_link_simple(
						'<br>',
						'setup/accounts',
						$this->setup->lang('Setup an Admininstrator account'),
						$account_creation_notice
					);
				}
				break;

			default:
				$config_status['img'] = $incomplete;
				$config_status['alt'] = $this->setup->lang('not completed');
				$config_status['table_data'] = $this->setup->lang('Not ready for this stage yet');
		}

		// Lang Section
		$lang_step_text = $this->setup->lang('Step 3 - Language Management');
		$setup_data['stage']['lang'] = $this->detection->check_lang();
		$setup_data = Settings::getInstance()->get('setup');

		$lang_status = [
			'img' => $incomplete,
			'alt' => 'not completed',
			'table_data' => ''
		];

		switch ($setup_data['stage']['lang'])
		{
			case 1:
				$lang_status['img'] = $incomplete;
				$lang_status['alt'] = 'not completed';
				$lang_status['table_data'] = $this->html->make_frm_btn_simple(
					$this->setup->lang('You do not have any languages installed. Please install one now <br />'),
					'POST',
					'setup/lang',
					'submit',
					$this->setup->lang('Install Language'),
					''
				);
				break;

			case 10:
				$langs_list = '';
				foreach ($setup_data['installed_langs'] as $key => $value)
				{
					if ($value)
					{
						$langs_list .= ($langs_list ? ', ' : '') . $value;
					}
				}
				$lang_status['img'] = $completed;
				$lang_status['alt'] = 'completed';
				$lang_status['table_data'] = $this->html->make_frm_btn_simple(
					$this->setup->lang('This stage is completed') . '<br/>' .  $this->setup->lang('Currently installed languages: %1', $langs_list) . ' <br/>',
					'POST',
					'setup/lang',
					'submit',
					$this->setup->lang('Manage Languages'),
					''
				);
				break;

			default:
				$lang_status['img'] = $incomplete;
				$lang_status['alt'] = $this->setup->lang('not completed');
				$lang_status['table_data'] = $this->setup->lang('Not ready for this stage yet');
		}

		// Apps Section
		$apps_step_text = $this->setup->lang('Step 4 - Advanced Application Management');
		$apps_status = [
			'img' => $incomplete,
			'alt' => $this->setup->lang('not completed'),
			'table_data' => $this->setup->lang('Not ready for this stage yet')
		];

		if (!isset($setup_data['stage']['db']))
		{
			$setup_data['stage']['db'] = null;
		}

		if ($setup_data['stage']['db'] == 10)
		{
			$apps_status['img'] = $completed;
			$apps_status['alt'] = $this->setup->lang('completed');
			$apps_status['table_data'] = $this->html->make_frm_btn_simple(
				$this->setup->lang('This stage is completed')  . '<br/>',
				'',
				'setup/applications',
				'submit',
				$this->setup->lang('Manage Applications'),
				''
			);
		}

		if (!isset($setup_data['header_msg']))
		{
			$setup_data['header_msg'] = '';
		}

		$langData = [
			'status_img' => $lang_status['img'],
			'status_alt' => $lang_status['alt'],
			'table_data' => $lang_status['table_data'],
			'step_text' => $lang_step_text
		];

		// Process GIT section
		$svn_step_text = $this->setup->lang('Step 0 - GIT pull');
		$svn_filled_block = '';
		
		// Setup common GIT block data
		$svnCommonData = [
			'img_completed' => $completed,
			'completed' => $this->setup->lang('completed'),
			'img_incomplete' => $incomplete,
			'notcomplete' => $this->setup->lang('not complete'),
			'svnwarn' => isset($svn_block_data['svnwarn']) ? $svn_block_data['svnwarn'] : $this->setup->lang('Note that this will modify your files directly')
		];
		
		// Render the appropriate SVN block based on the stage
		switch ($setup_data['stage']['svn']) {
			case 1:
				$svnBlockData = array_merge($svnCommonData, [
					'check_for_svn_update' => $this->setup->lang('check update'),
					'sudo_user' => $this->setup->lang('sudo user'),
					'sudo_password' => $this->setup->lang('password for %1', getenv('APACHE_RUN_USER')),
					'svn_message' => isset($svn_block_data['svn_message']) ? $svn_block_data['svn_message'] : ''
				]);
				$svn_filled_block = $this->twig->renderBlock('setup_svn_blocks.html.twig', 'B_svn_stage_1', $svnBlockData);
				break;
				
			case 2:
				$svnBlockData = array_merge($svnCommonData, [
					'perform_svn_update' => $this->setup->lang('perform svn update'),
					'execute' => $this->setup->lang('execute'),
					'sudo_user' => $this->setup->lang('sudo user'),
					'value_sudo_user' => \Sanitizer::get_var('sudo_user'),
					'value_sudo_password' => \Sanitizer::get_var('sudo_password'),
					'sudo_password' => $this->setup->lang('password for %1', getenv('APACHE_RUN_USER')),
					'svn_message' => isset($svn_block_data['svn_message']) ? $svn_block_data['svn_message'] : ''
				]);
				$svn_filled_block = $this->twig->renderBlock('setup_svn_blocks.html.twig', 'B_svn_stage_2', $svnBlockData);
				break;
		}

		// Prepare final data for rendering
		$templateData = [
			'title' => $setup_data['header_msg'],
			'css' => $css,
			'subtitle' => $subtitle,
			'submsg' => $submsg,
			'subaction' => $subaction,
			'img_incomplete' => $incomplete,
			'img_completed' => $completed,
			'svn_step_text' => $svn_step_text,
//			'V_svn_filled_block' => $svn_filled_block,
			'db_step_text' => $this->setup->lang('Step 1 - Simple Application Management'),
			'V_db_filled_block' => $db_stage_content,
			'config_step_text' => $config_step_text,
			'config_status_img' => $config_status['img'],
			'config_status_alt' => $config_status['alt'],
			'config_table_data' => $config_status['table_data'],
			'ldap_table_data' => $config_status['ldap_table_data'],
			'lang' => $langData,
			'apps_step_text' => $apps_step_text,
			'apps_status_img' => $apps_status['img'], 
			'apps_status_alt' => $apps_status['alt'],
			'apps_table_data' => $apps_status['table_data'],
			'configdomain' => $this->db->get_domain() . '(' . $db_config['db_type'] . ')',
			'lang_cookies_must_be_enabled' => $this->setup->lang('<b>NOTE:</b> You must have cookies enabled to use setup and header admin!')
		];

		// Render the template

		$content = $this->html->get_header(
			$setup_data['header_msg'],
			false,
			'config',
			$this->db->get_domain() . '(' . $db_config['db_type'] . ')'
		);

		$content .= $this->twig->render('setup_main.html.twig', $templateData);
		$content .= $this->twig->render('footer.html.twig', []);

		// Return the response
		$response = new \Slim\Psr7\Response();
		$response->getBody()->write($content);
		return $response;
	}
}
