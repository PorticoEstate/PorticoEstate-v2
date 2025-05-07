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
	use App\modules\phpgwapi\services\Twig;

	class SqlToArray
	{
		/**
		 * @var object
		 */
		private $db;
	//	private $detection;
		private $process;
		private $html;
		private $setup;
		private $setup_tpl;
		
		public function __construct()
		{

			//setup_info
			Settings::getInstance()->set('setup_info', []); //$GLOBALS['setup_info']
			//setup_data
			Settings::getInstance()->set('setup', []); //$GLOBALS['phpgw_info']['setup']

			$this->db = Db::getInstance();
		//	$this->detection = new Detection();
			$this->process = new Process();
			$this->html = new Html();
			$this->setup = new Setup();

			$flags = array(
				'noheader' 		=> True,
				'nonavbar'		=> True,
				'currentapp'	=> 'home',
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

			
		function index()
		{

			$download = \Sanitizer::get_var('download','bool');
			$submit   = \Sanitizer::get_var('submit','bool');
			$showall  = \Sanitizer::get_var('showall','bool');
			$appname  = \Sanitizer::get_var('appname','string');
			if ($download)
			{
				$this->setup_tpl->set_file(array(
					'sqlarr'   => 'arraydl.tpl'
				));
				$this->setup_tpl->set_var('idstring',"/* \$Id" . ": tables_current.inc.php" . ",v 1.0 " . @date('Y/m/d',time()) .  " username " . "Exp \$ */");
				$this->setup_tpl->set_block('sqlarr','sqlheader','sqlheader');
				$this->setup_tpl->set_block('sqlarr','sqlbody','sqlbody');
				$this->setup_tpl->set_block('sqlarr','sqlfooter','sqlfooter');
			}
			else
			{
				$this->setup_tpl->set_file(array(
					'T_head' => 'head.tpl',
					'T_footer' => 'footer.tpl',
					'T_alert_msg' => 'msg_alert_msg.tpl',
					'T_login_main' => 'login_main.tpl',
					'T_login_stage_header' => 'login_stage_header.tpl',
					'T_setup_main' => 'schema.tpl',
					'applist'  => 'applist.tpl',
					'sqlarr'   => 'sqltoarray.tpl',
					'T_head'   => 'head.tpl',
					'T_footer' => 'footer.tpl'
				));
				$this->setup_tpl->set_block('T_login_stage_header','B_multi_domain','V_multi_domain');
				$this->setup_tpl->set_block('T_login_stage_header','B_single_domain','V_single_domain');
				$this->setup_tpl->set_block('T_setup_main','header','header');
				$this->setup_tpl->set_block('applist','appheader','appheader');
				$this->setup_tpl->set_block('applist','appitem','appitem');
				$this->setup_tpl->set_block('applist','appfooter','appfooter');
				$this->setup_tpl->set_block('sqlarr','sqlheader','sqlheader');
				$this->setup_tpl->set_block('sqlarr','sqlbody','sqlbody');
				$this->setup_tpl->set_block('sqlarr','sqlfooter','sqlfooter');
			}

			$this->setup->loaddb();

			if ($submit || $showall) {
				$dlstring = '';
				$term = '';

				if (!$download) {
					$header = $this->html->get_header();
				}

				if ($showall) {
					$table = $appname = '';
				}

				if ((!isset($table) || !$table) && !$appname) {
					$term = ',';
					$dlstring .= $this->printout(
				'sqlheader', $download, $appname, $table, $showall);

					$db = $this->db;
					$db->query('SHOW TABLES');
					while ($db->next_record()) {
						$table = $db->f(0);
						$this->parse_vars($table, $term);
						$dlstring .= $this->printout(
					'sqlbody', $download, $appname, $table, $showall);
					}
					$dlstring .= $this->printout(
				'sqlfooter', $download, $appname, $table, $showall);
				} elseif ($appname) {
					$dlstring .= $this->printout(
				'sqlheader', $download, $appname, $table, $showall);
					$term = ',';

					if (!isset($setup_info[$appname]['tables']) || !$setup_info[$appname]['tables']) {
						$f = SRC_ROOT_PATH . '/modules/' . $appname . '/setup/setup.inc.php';
						if (file_exists($f)) {
							/**
							 * Include existing file
							 */
							include($f);
						}
					}

					$tables = $setup_info[$appname]['tables'];
					foreach ($tables as $key => $table) {
						$this->parse_vars($table, $term);
						$dlstring .= $this->printout(
					'sqlbody', $download, $appname, $table, $showall);
					}
					$dlstring .= $this->printout(
				'sqlfooter', $download, $appname, $table, $showall);
				} elseif ($table) {
					$term = ';';
					$this->parse_vars($table, $term);
					$dlstring .= $this->printout('sqlheader', $download, $appname, $table, $showall);
					$dlstring .= $this->printout('sqlbody', $download, $appname, $table, $showall);
					$dlstring .= $this->printout('sqlfooter', $download, $appname, $table, $showall);
				}
				if ($download) {
					$this->download_handler($dlstring);
				}
				else
				{
					return $header . $dlstring;
				}
			} else {
				$header = $this->html->get_header();

				$this->setup_tpl->set_var('action_url', 'sqltoarray');
				$this->setup_tpl->set_var('lang_submit', 'Show selected');
				$this->setup_tpl->set_var('lang_showall', 'Show all');
				$this->setup_tpl->set_var('title', 'SQL to schema_proc array util');
				$this->setup_tpl->set_var('lang_applist', 'Applications');
				$this->setup_tpl->set_var('select_to_download_file', $this->setup->lang('Select to download file'));
				$this->setup_tpl->pfp('out', 'appheader');

				$d = dir(SRC_ROOT_PATH . '/modules');
				while ($entry = $d->read()) {
					$f = SRC_ROOT_PATH . '/modules/' . $entry . '/setup/setup.inc.php';
					if (file_exists($f)) {
						include($f);
					}
				}
				$appitems = '';
				//while (list($key,$data) = @each($setup_info))
				if (is_array($setup_info)) {
					foreach ($setup_info as $key => $data) {
						if ($data['tables'] && $data['title']) {
							$this->setup_tpl->set_var('appname', $data['name']);
							$this->setup_tpl->set_var('apptitle', $data['title']);
							$appitems .= $this->setup_tpl->fp('out', 'appitem');
						}
					}
				}
				$appfooter = $this->setup_tpl->fp('out', 'appfooter');
				return $header . $appitems . $appfooter;
			}
		}
		/**
		 * Parse variables
		 * 
		 * @param string $table
		 * @param string $term
		 */
		function parse_vars($table,$term)
		{
			$this->setup_tpl->set_var('table', $table);
			$this->setup_tpl->set_var('term',$term);

			$table_info = $this->process->sql_to_array($table);
			list($arr,$pk,$fk,$ix,$uc) = $table_info;
			$this->setup_tpl->set_var('arr',$arr);
			if (count($pk) > 1)
			{
				$this->setup_tpl->set_var('pks', "'".implode("','",$pk)."'");
			}
			elseif($pk && !empty($pk))
			{
				$this->setup_tpl->set_var('pks', "'" . $pk[0] . "'");
			}
			else
			{
				$this->setup_tpl->set_var('pks','');
			}

			if (count($fk) > 1)
			{
				$this->setup_tpl->set_var('fks', "\n\t\t\t\t" . implode(",\n\t\t\t\t",$fk) );
			}
			elseif($fk && !empty($fk))
			{
				$this->setup_tpl->set_var('fks', $fk[0]);
			}
			else
			{
				$this->setup_tpl->set_var('fks','');
			}
			if (is_array($ix) && count($ix) > 0 )
			{
				foreach($ix as $entry)
				{
					if(is_array($entry) &&  count($entry) > 1)
					{
						$ix_temp[] = "array('" . implode("','",$entry) . "')";
					}
					else
					{
						$ix_temp[] = "array('{$entry}')";
					}
				}
				unset($entry);
				$this->setup_tpl->set_var('ixs', implode(",",$ix_temp));
			}
			elseif($ix && !empty($ix))
			{
				$this->setup_tpl->set_var('ixs', "'{$ix[0]}'");
			}
			else
			{
				$this->setup_tpl->set_var('ixs','');
			}

			if (count($uc) > 1)
			{
				$this->setup_tpl->set_var('ucs', "'" . implode("','",$uc) . "'");
			}
			elseif($uc && !empty($uc))
			{
				$this->setup_tpl->set_var('ucs', "'" . $uc[0] . "'");
			}
			else
			{
				$this->setup_tpl->set_var('ucs','');
			}
		}

		/**
		 * 
		 * 
		 * @param string $template
		 * @return string
		 */
		function printout($template, $download, $appname, $table, $showall)
		{
		//	global $download,$appname,$table,$showall;
			$string = '';

			if ($download)
			{
				$this->setup_tpl->set_var('appname',$appname);
				$string = $this->setup_tpl->parse('out',$template);
			}
			else
			{
				$this->setup_tpl->set_var('appname',$appname);
				$this->setup_tpl->set_var('table',$table);
				$this->setup_tpl->set_var('lang_download','Download');
				$this->setup_tpl->set_var('showall',$showall);
				$this->setup_tpl->set_var('action_url','sqltoarray');
				$string = $this->setup_tpl->parse('out',$template);
			}
			return $string;
		}


		/**
		 * Download handler
		 * 
		 * @param string $dlstring
		 * @param string $fn
		 */
		function download_handler($dlstring,$fn='tables_current.inc.php')
		{
			header('Pragma: no-cache');
			header('Pragma: public');
			header('Expires: 0');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Content-Disposition: attachment; filename="' . $fn . '"');
			header('Content-Type: text/plain');
			echo $dlstring;
			exit;
		}

	}