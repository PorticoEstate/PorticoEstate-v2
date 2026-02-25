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
use App\modules\phpgwapi\services\Twig;

class SqlToArray
{
	/**
	 * @var object
	 */
	private $db;
	private $process;
	private $html;
	private $setup;
	private $twig;
	private $table_vars = [];

	public function __construct()
	{
		//setup_info
		Settings::getInstance()->set('setup_info', []);
		//setup_data
		Settings::getInstance()->set('setup', []);

		$this->db = Db::getInstance();
		$this->process = new Process();
		$this->html = new Html();
		$this->setup = new Setup();
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

	function index()
	{
		$download = \Sanitizer::get_var('download', 'bool');
		$submit   = \Sanitizer::get_var('submit', 'bool');
		$showall  = \Sanitizer::get_var('showall', 'bool');
		$appname  = \Sanitizer::get_var('appname', 'string');
		$setup_info = [];

		$this->setup->loaddb();

		if ($submit || $showall)
		{
			$dlstring = '';
			$term = '';

			if (!$download)
			{
				$header = $this->html->get_header($this->setup->lang('SQL to Array Conversion'));
			}

			if ($showall)
			{
				$table = $appname = '';
			}

			if ((!isset($table) || !$table) && !$appname)
			{
				$term = ',';
				$dlstring .= $this->printout('sqlheader', $download, $appname, $table, $showall);

				$db = $this->db;
				$db->query('SHOW TABLES');
				while ($db->next_record())
				{
					$table = $db->f(0);
					$this->parse_vars($table, $term);
					$dlstring .= $this->printout('sqlbody', $download, $appname, $table, $showall);
				}
				$dlstring .= $this->printout('sqlfooter', $download, $appname, $table, $showall);
			}
			elseif ($appname)
			{
				$setup_info = Settings::getInstance()->get('setup_info');
				$dlstring .= $this->printout('sqlheader', $download, $appname, $table, $showall);
				$term = ',';

				if (!isset($setup_info[$appname]['tables']) || !$setup_info[$appname]['tables'])
				{
					$f = SRC_ROOT_PATH . '/modules/' . $appname . '/setup/setup.inc.php';
					if (file_exists($f))
					{
						/**
						 * Include existing file
						 */
						include($f);
					}
				}

				$tables = $setup_info[$appname]['tables'];
				foreach ($tables as $key => $table)
				{
					$this->parse_vars($table, $term);
					$dlstring .= $this->printout('sqlbody', $download, $appname, $table, $showall);
				}
				$dlstring .= $this->printout('sqlfooter', $download, $appname, $table, $showall);
			}
			elseif ($table)
			{
				$term = ';';
				$this->parse_vars($table, $term);
				$dlstring .= $this->printout('sqlheader', $download, $appname, $table, $showall);
				$dlstring .= $this->printout('sqlbody', $download, $appname, $table, $showall);
				$dlstring .= $this->printout('sqlfooter', $download, $appname, $table, $showall);
			}

			if ($download)
			{
				$this->download_handler($dlstring);
			}
			else
			{
				return $header . $dlstring;
			}
		}
		else
		{
			// Render the main interface with Twig
			$header = $this->html->get_header($this->setup->lang('SQL to Array Conversion'));

			// Prepare data for the schema template
			$data = [
				'action_url' => 'sqltoarray',
				'lang_submit' => $this->setup->lang('Show selected'),
				'lang_showall' => $this->setup->lang('Show all'),
				'title' => $this->setup->lang('SQL to schema_proc array util'),
				'lang_applist' => $this->setup->lang('Applications'),
				'select_to_download_file' => $this->setup->lang('Select to download file')
			];

			// Use Twig to render the header section
			$output = $this->twig->renderBlock('schema.html.twig', 'header', [
				'description' => $this->setup->lang('SQL to Array Conversion')
			]);

			// Use Twig to render the app header section
			$output .= $this->twig->renderBlock('schema.html.twig', 'app_header', $data);

			// Load app data
			$d = dir(SRC_ROOT_PATH . '/modules');
			$setup_info = [];
			while ($entry = $d->read())
			{
				$f = SRC_ROOT_PATH . '/modules/' . $entry . '/setup/setup.inc.php';
				if (file_exists($f))
				{
					include($f);
				}
			}

			// Generate application items using Twig
			if (is_array($setup_info))
			{
				foreach ($setup_info as $key => $data)
				{
					if (isset($data['tables']) && isset($data['title']) && $data['tables'] && $data['title'])
					{
						$output .= $this->twig->renderBlock('schema.html.twig', 'apps', [
							'appname' => $data['name'],
							'apptitle' => $data['title'],
							'bg_color' => '#e6e6e6',
							'instimg' => 'completed.png',
							'instalt' => $this->setup->lang('completed'),
							'appinfo' => $data['name']
						]);
					}
				}
			}

			// Use Twig to render the footer
			$output .= $this->twig->renderBlock('schema.html.twig', 'app_footer', [
				'submit' => $this->setup->lang('Submit'),
				'cancel' => $this->setup->lang('Cancel')
			]);

			$output .= $this->twig->renderBlock('schema.html.twig', 'footer', []);

			return $header . $output;
		}
	}

	/**
	 * Parse variables
	 * 
	 * @param string $table
	 * @param string $term
	 */
	function parse_vars($table, $term)
	{
		// Store variables in the class property for later use
		$this->table_vars['table'] = $table;
		$this->table_vars['term'] = $term;

		$table_info = $this->process->sql_to_array($table);
		list($arr, $pk, $fk, $ix, $uc) = $table_info;
		$this->table_vars['arr'] = $arr;

		if (count($pk) > 1)
		{
			$this->table_vars['pks'] = "'" . implode("','", $pk) . "'";
		}
		elseif ($pk && !empty($pk))
		{
			$this->table_vars['pks'] = "'" . $pk[0] . "'";
		}
		else
		{
			$this->table_vars['pks'] = '';
		}

		if (count($fk) > 1)
		{
			$this->table_vars['fks'] = "\n\t\t\t\t" . implode(",\n\t\t\t\t", $fk);
		}
		elseif ($fk && !empty($fk))
		{
			$this->table_vars['fks'] = $fk[0];
		}
		else
		{
			$this->table_vars['fks'] = '';
		}

		if (is_array($ix) && count($ix) > 0)
		{
			$ix_temp = [];
			foreach ($ix as $entry)
			{
				if (is_array($entry) && count($entry) > 1)
				{
					$ix_temp[] = "array('" . implode("','", $entry) . "')";
				}
				else
				{
					$ix_temp[] = "array('{$entry}')";
				}
			}
			unset($entry);
			$this->table_vars['ixs'] = implode(",", $ix_temp);
		}
		elseif ($ix && !empty($ix))
		{
			$this->table_vars['ixs'] = "'{$ix[0]}'";
		}
		else
		{
			$this->table_vars['ixs'] = '';
		}

		if (count($uc) > 1)
		{
			$this->table_vars['ucs'] = "'" . implode("','", $uc) . "'";
		}
		elseif ($uc && !empty($uc))
		{
			$this->table_vars['ucs'] = "'" . $uc[0] . "'";
		}
		else
		{
			$this->table_vars['ucs'] = '';
		}
	}

	/**
	 * Print template output
	 * 
	 * @param string $template Template block name
	 * @param bool $download Whether to download the output
	 * @param string $appname Application name
	 * @param string $table Table name
	 * @param bool $showall Whether to show all tables
	 * @return string Output HTML
	 */
	function printout($template, $download, $appname, $table, $showall)
	{
		if ($download)
		{
			// For download mode, use the arraydl.html.twig template
			$templateVars = [
				'appname' => $appname,
				'table' => $table,
				'arr' => $this->table_vars['arr'],
				'pks' => $this->table_vars['pks'],
				'fks' => $this->table_vars['fks'],
				'ixs' => $this->table_vars['ixs'],
				'ucs' => $this->table_vars['ucs'],
				'term' => $this->table_vars['term'],
				'idstring' => "/* \$Id" . ": tables_current.inc.php" . ",v 1.0 " . @date('Y/m/d', time()) . " username " . "Exp \$ */"
			];

			// Map template names to Twig block names
			$blockMap = [
				'sqlheader' => 'sqlheader',
				'sqlbody' => 'sqlbody',
				'sqlfooter' => 'sqlfooter'
			];

			if (isset($blockMap[$template]))
			{
				return $this->twig->renderBlock('arraydl.html.twig', $blockMap[$template], $templateVars);
			}
		}
		else
		{
			// For display mode, use the sqltoarray.html.twig template
			$templateVars = [
				'appname' => $appname,
				'table' => $table,
				'lang_download' => $this->setup->lang('Download'),
				'showall' => $showall,
				'action_url' => 'sqltoarray',
				'arr' => $this->table_vars['arr'],
				'pks' => $this->table_vars['pks'],
				'fks' => $this->table_vars['fks'],
				'ixs' => $this->table_vars['ixs'],
				'ucs' => $this->table_vars['ucs'],
				'term' => $this->table_vars['term']
			];

			// Map template names to Twig block names
			$blockMap = [
				'sqlheader' => 'sqlheader',
				'sqlbody' => 'sqlbody',
				'sqlfooter' => 'sqlfooter'
			];

			if (isset($blockMap[$template]))
			{
				return $this->twig->renderBlock('sqltoarray.html.twig', $blockMap[$template], $templateVars);
			}
		}
	}

	/**
	 * Download handler
	 * 
	 * @param string $dlstring
	 * @param string $fn
	 */
	function download_handler($dlstring, $fn = 'tables_current.inc.php')
	{
		header('Pragma: no-cache');
		header('Pragma: public');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Content-Disposition: attachment; filename="' . $fn . '"');
		header('Content-Type: text/plain');
		echo html_entity_decode($dlstring);
		exit;
	}
}
