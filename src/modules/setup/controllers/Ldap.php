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

	class Ldap
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
			if (!$this->setup->auth('Config')) {
				Header('Location: ../setup');
				exit;
			}

			$tpl_root = $this->html->setup_tpl_dir('setup');
			$this->setup_tpl = new Template($tpl_root);

			$this->html->set_tpl($this->setup_tpl);
		
		}

		
		public function index()
			{
				if (\Sanitizer::get_var('cancel', 'bool', 'POST')) {
					Header('Location: ../setup');
					exit;
				}
		
				$ret_header = $this->html->get_header($this->setup->lang('LDAP Config'), '', 'config', $this->db->get_domain());
		
				if (isset($GLOBALS['error']) && $GLOBALS['error']) {
					//echo '<br /><center><b>Error:</b> '.$error.'</center>';
					$this->html->show_alert_msg('Error', $GLOBALS['error']);
				}
		
				// Prepare variables for Twig template
				$templateVars = [
					'description' => $this->setup->lang('LDAP Accounts Configuration'),
					'lang_ldapmodify' => $this->setup->lang('Modify an existing LDAP account store for use with phpGroupWare (for a new install using LDAP accounts)'),
					'lang_ldapimport' => $this->setup->lang('Import accounts from LDAP to the phpGroupware accounts table (for a new install using SQL accounts)'),
					'lang_ldapexport' => $this->setup->lang('Export phpGroupware accounts from SQL to LDAP'),
					'lang_ldapdummy' => $this->setup->lang('Setup demo accounts in LDAP'),
					'ldapmodify' => 'ldapmodify',
					'ldapimport' => 'ldapimport',
					'ldapexport' => 'ldapexport',
					'ldapdummy' => 'accounts',
					'action_url' => '/setup',
					'cancel' => $this->setup->lang('Cancel')
				];
		
				// Render the template blocks using Twig
				$header = $this->twig->renderBlock('ldap.html.twig', 'header', $templateVars);
				$jump = $this->twig->renderBlock('ldap.html.twig', 'jump', $templateVars);
				$cancel_only = $this->twig->renderBlock('ldap.html.twig', 'cancel_only', $templateVars);
				$footer = $this->twig->renderBlock('ldap.html.twig', 'footer', $templateVars);
				$ret_footer = $this->html->get_footer();
		
				return $ret_header . $header . $jump . $cancel_only . $footer . $ret_footer;
			}

		function ldapmodify()
		{
			if (\Sanitizer::get_var('cancel', 'bool', 'POST')) {
				header('Location: ldap');
				exit;
			}
	
			$serverSettings = Settings::getInstance()->get('server');
	
			$ret_header = $this->html->get_header($this->setup->lang('LDAP Config'), '', 'config', $this->db->get_domain());
	
			if (\Sanitizer::get_var('submit', 'bool', 'POST')) {
				$ldap_host = \Sanitizer::get_var('ldap_host', 'string', 'POST');
				$ldap_port = \Sanitizer::get_var('ldap_port', 'string', 'POST');
				$ldap_base = \Sanitizer::get_var('ldap_base', 'string', 'POST');
				$ldap_admin = \Sanitizer::get_var('ldap_admin', 'string', 'POST');
				$ldap_admin_pw = \Sanitizer::get_var('ldap_admin_pw', 'string', 'POST');
				$ldap_auth_type = \Sanitizer::get_var('ldap_auth_type', 'string', 'POST');
				$ldap_user_context = \Sanitizer::get_var('ldap_user_context', 'string', 'POST');
				$ldap_group_context = \Sanitizer::get_var('ldap_group_context', 'string', 'POST');
				$account_repository = \Sanitizer::get_var('account_repository', 'string', 'POST');
				$ldap_use_tls = \Sanitizer::get_var('ldap_use_tls', 'string', 'POST');
	
				$serverSettings['ldap_host'] = $ldap_host;
				$serverSettings['ldap_port'] = $ldap_port;
				$serverSettings['ldap_base'] = $ldap_base;
				$serverSettings['ldap_admin'] = $ldap_admin;
				$serverSettings['ldap_admin_pw'] = $ldap_admin_pw;
				$serverSettings['ldap_account_home'] = isset($ldap_account_home) ? $ldap_account_home : '';
				$serverSettings['ldap_account_shell'] = isset($ldap_account_shell) ? $ldap_account_shell : '';
				$serverSettings['ldap_auth_type'] = $ldap_auth_type;
				$serverSettings['ldap_user_context'] = $ldap_user_context;
				$serverSettings['ldap_group_context'] = $ldap_group_context;
				$serverSettings['account_repository'] = $account_repository;
				$serverSettings['ldap_encryption_type'] = isset($ldap_encryption_type) ? $ldap_encryption_type : '';
				$serverSettings['ldap_rfc2307bis'] = isset($ldap_rfc2307bis) ? $ldap_rfc2307bis : '';
				$serverSettings['ldap_use_tls'] = $ldap_use_tls;
				Settings::getInstance()->set('server', $serverSettings);
				// If anything fails, we will re-display the page with the error
				if (@ldap_connect($serverSettings['ldap_host'], $serverSettings['ldap_port'])) {
					header('Location: ldap');
					exit;
				}
				$GLOBALS['error'] = $this->setup->lang('cannot connect to ldap server');
			}
	
			// Prepare variables for Twig template
			$templateVars = [
				'description' => $this->setup->lang('LDAP Accounts Configuration'),
				'explanation' => $this->setup->lang('Configure access to the LDAP authentication/directory server'),
				'form_action' => 'ldapmodify',
				'lang_title' => $this->setup->lang('LDAP server information'),
				'lang_server' => $this->setup->lang('LDAP server'),
				'lang_port' => $this->setup->lang('LDAP server port'),
				'lang_base' => $this->setup->lang('LDAP base search'),
				'lang_admin' => $this->setup->lang('LDAP root dn'),
				'lang_admin_pw' => $this->setup->lang('LDAP root password'),
				'account_context' => $this->setup->lang('LDAP account repository settings'),
				'auth_type' => $this->setup->lang('LDAP authentication type'),
				'use_tls' => $this->setup->lang('Use TLS?'),
				'ldap_text' => $this->setup->lang('LDAP'),
				'ads_text' => $this->setup->lang('Active Directory'),
				'lang_yes' => $this->setup->lang('Yes'),
				'lang_no' => $this->setup->lang('No'),
				'user_context' => $this->setup->lang('Users context'),
				'group_context' => $this->setup->lang('Groups context'),
				'schema_select' => $this->setup->lang('LDAP schema'),
				'schema_type' => $this->setup->lang('LDAP schema to use'),
				'lang_submit' => $this->setup->lang('Submit'),
				'lang_cancel' => $this->setup->lang('Cancel'),
				'ldap_host' => isset($serverSettings['ldap_host']) ? $serverSettings['ldap_host'] : '',
				'ldap_port' => isset($serverSettings['ldap_port']) ? $serverSettings['ldap_port'] : '389',
				'ldap_base' => isset($serverSettings['ldap_base']) ? $serverSettings['ldap_base'] : '',
				'ldap_admin' => isset($serverSettings['ldap_admin']) ? $serverSettings['ldap_admin'] : '',
				'ldap_admin_pw' => isset($serverSettings['ldap_admin_pw']) ? $serverSettings['ldap_admin_pw'] : '',
				'ldap_auth_type' => isset($serverSettings['ldap_auth_type']) ? $serverSettings['ldap_auth_type'] : 'ldap',
				'ldap_use_tls' => isset($serverSettings['ldap_use_tls']) ? $serverSettings['ldap_use_tls'] : 'False',
				'ldap_user_context' => isset($serverSettings['ldap_user_context']) ? $serverSettings['ldap_user_context'] : '',
				'ldap_group_context' => isset($serverSettings['ldap_group_context']) ? $serverSettings['ldap_group_context'] : ''
			];
	
			// Generate schema options
			$schemas = ['rfc2307', 'rfc2307bis', 'samba3', 'edir8'];
			$schema_options = '';
			
			foreach ($schemas as $schema) {
				$selected = '';
				if (isset($serverSettings['account_repository']) && $schema === $serverSettings['account_repository']) {
					$selected = ' selected="selected"';
				}
				$schema_options .= '<option value="' . $schema . '"' . $selected . '>' . $schema . '</option>';
			}
			
			$templateVars['schema_options'] = $schema_options;
	
			// Render the template blocks using Twig
			$header = $this->twig->renderBlock('ldap.html.twig', 'ldapmodify_header', $templateVars);
			$form = $this->twig->renderBlock('ldap.html.twig', 'ldapmodify_form', $templateVars);
			$footer = $this->twig->renderBlock('ldap.html.twig', 'footer', $templateVars);
			$ret_footer = $this->html->get_footer();
	
			return $ret_header . $header . $form . $footer . $ret_footer;
		}

		function ldapimport()
		{
			if (\Sanitizer::get_var('cancel', 'bool', 'POST')) {
				header('Location: ldap');
				exit;
			}
	
			$serverSettings = Settings::getInstance()->get('server');
			$ret_header = $this->html->get_header($this->setup->lang('LDAP Config'), '', 'config', $this->db->get_domain());
	
			// Prepare variables for Twig template
			$templateVars = [
				'description' => $this->setup->lang('LDAP Accounts Configuration'),
				'explanation' => $this->setup->lang('Import accounts from LDAP to the phpGroupware accounts table (for a new install using SQL accounts)'),
				'form_action' => 'ldap',
				'lang_continue' => $this->setup->lang('Continue'),
				'results_message' => $this->setup->lang('This operation not yet implemented'),
				'show_results' => false,
				'results' => []
			];
	
			// Render the template blocks using Twig
			$header = $this->twig->renderBlock('ldap.html.twig', 'ldapimport_header', $templateVars);
			$results = $this->twig->renderBlock('ldap.html.twig', 'ldap_results', $templateVars);
			$footer = $this->twig->renderBlock('ldap.html.twig', 'footer', $templateVars);
			$ret_footer = $this->html->get_footer();
	
			return $ret_header . $header . $results . $footer . $ret_footer;
		}
	
		function ldapexport()
		{
			if (\Sanitizer::get_var('cancel', 'bool', 'POST')) {
				header('Location: ldap');
				exit;
			}
	
			$serverSettings = Settings::getInstance()->get('server');
			$ret_header = $this->html->get_header($this->setup->lang('LDAP Config'), '', 'config', $this->db->get_domain());
	
			// Prepare variables for Twig template
			$templateVars = [
				'description' => $this->setup->lang('LDAP Accounts Configuration'),
				'explanation' => $this->setup->lang('Export phpGroupware accounts from SQL to LDAP'),
				'form_action' => 'ldap',
				'lang_continue' => $this->setup->lang('Continue'),
				'results_message' => $this->setup->lang('This operation not yet implemented'),
				'show_results' => false,
				'results' => []
			];
	
			// Render the template blocks using Twig
			$header = $this->twig->renderBlock('ldap.html.twig', 'ldapexport_header', $templateVars);
			$results = $this->twig->renderBlock('ldap.html.twig', 'ldap_results', $templateVars);
			$footer = $this->twig->renderBlock('ldap.html.twig', 'footer', $templateVars);
			$ret_footer = $this->html->get_footer();
	
			return $ret_header . $header . $results . $footer . $ret_footer;
		}
	}