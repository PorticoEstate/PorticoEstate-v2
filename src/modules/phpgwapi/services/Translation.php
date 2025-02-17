<?php

/**
 * Handles multi-language support
 * @author Sigurd Nes <sigurdne@online.no>
 * @author Dave Hall <skwashd@phpgroupware.org>
 * @author Joseph Engo <jengo@phpgroupware.org>
 * @author Dan Kuykendall <seek3r@phpgroupware.org>
 * @copyright Portions Copyright (C) 2000-2009 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.fsf.org/licenses/lgpl.html GNU Lesser General Public License
 * @package phpgwapi
 * @subpackage application
 * @version $Id$
 */

/**
 * Handles multi-language support use SQL tables
 *
 * @package phpgwapi
 * @subpackage application
 */

namespace App\modules\phpgwapi\services;

use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\services\Settings;
use PDO;


class Translation
{
	/**
	 * @var string $userlang user's prefered language
	 * @internal this should probably be private and probably will become private
	 */
	public $userlang = 'en';

	/**
	 * @var bool $lang_is_cached break off the function populate_cache
	 */
	public $lang_is_cached = false;

	/**
	 * @var array $lang the translated strings - speeds look up
	 */
	private static $lang = array();

	/**
	 * @var array $errors errors returned from function calls
	 */
	public $errors = array();

	/**
	 * @var bool $collect_missing collects missing translations to the lang_table with app_name = ##currentapp##
	 */
	private $collect_missing = false;

	/**
	 * Maxiumum length of a translation string
	 */
	const MAX_MESSAGE_ID_LENGTH = 230;

	/**
	 * Constructor
	 *
	 * @param bool $reset reload the translations
	 */

	private $db;
	protected $cache;
	private $serverSettings;
	private $preferences;
	private static $instance = null;

	private function __construct($preferences = [])
	{
		$this->db = \App\Database\Db::getInstance();
		$this->serverSettings = Settings::getInstance()->get('server');

		$this->preferences = isset(Settings::getInstance()->get('user')['preferences']) ? Settings::getInstance()->get('user')['preferences'] : $preferences;

		$userlang = isset($this->serverSettings['default_lang']) && $this->serverSettings['default_lang'] ? $this->serverSettings['default_lang'] : 'en';
		if (isset($this->preferences['common']['lang']))
		{
			$userlang = $this->preferences['common']['lang'];
		}

		$this->set_userlang($userlang, true);

		if (
			isset($this->serverSettings['collect_missing_translations'])
			&& $this->serverSettings['collect_missing_translations']
		)
		{
			$this->collect_missing = true;
		}
	}

	protected function set_db($db)
	{
		$this->db = $db;
	}
	protected function set_serverSettings($serverSettings)
	{
		$this->serverSettings = $serverSettings;
	}

	public static function getInstance($preferences = [])
	{
		if (self::$instance === null)
		{
			self::$instance = new self($preferences);
		}

		return self::$instance;
	}
	/**
	 * Reset the current user's language settings
	 */
	protected function reset_lang()
	{
		if (!isset($this->serverSettings['install_id']))
		{
			return;
		}
		$lang = Cache::system_get('phpgwapi', "lang_{$this->userlang}", true);
		$this->lang_is_cached = false;
		if ($lang && is_array($lang))
		{
			self::$lang = $lang;
			$this->lang_is_cached = true;
			return;
		}
		self::$lang = array();
	}

	/**
	 * Set the user's selected language
	 */
	public function set_userlang($lang, $reset = false)
	{
		//			print_r($lang);

		if (strlen($lang) != 2)
		{
			$lang = 'en';
		}
		$this->userlang = $lang;
		if ($reset)
		{
			$this->reset_lang();
		}
	}

	/**
	 * Get the user's selected language
	 */
	public function get_userlang()
	{
		return $this->userlang;
	}

	/**
	 * Read a lang file and return it as an array
	 *
	 * @param string $fn the filename parse
	 * @param string $lang the lang to be parsed - used for validation
	 * @return array $entries of translation string - empty array on failure
	 */
	protected function parse_lang_file($fn, $lang)
	{
		if (!file_exists($fn))
		{
			$this->errors[] = "Failed load lang file: $fn";
			return array();
		}

		$entries = array();
		$lines = file($fn);
		foreach ($lines as $cnt => $line)
		{
			$entry = explode("\t", $line);
			//Make sure the lang files only have valid entries
			if (count($entry) != 4  || $entry[2] != $lang)
			{
				$err_line = $cnt + 1;
				$this->errors[] = "Invalid entry in $fn @ line {$err_line}: <code>" . htmlspecialchars(preg_replace('/\t/', '\\t', $line)) . "</code> - skipping";
				continue;
			}

			//list($message_id,$app_name,$ignore,$content) = $entry;
			$entries[] = array(
				'message_id'	=> trim($entry[0]),
				'app_name'		=> trim($entry[1]),
				'lang'			=> trim($entry[2]),
				'content'		=> trim($entry[3])
			);
		}
		return $entries;
	}

	/**
	 * Populate shared memory with the available translation strings
	 */
	public function populate_cache()
	{
		if ($this->lang_is_cached)
		{
			return;
		}
		$sql = "SELECT * from phpgw_lang ORDER BY app_name DESC";
		$stmt = $this->db->prepare($sql);
		$stmt->execute();

		$lang_set = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$lang_set[$row['lang']][$row['app_name']][$row['message_id']] = $row['content'];
		}

		$language = array_keys($lang_set);
		if (isset($language) && is_array($language))
		{
			foreach ($language as $lang)
			{
				Cache::system_set('phpgwapi', "lang_{$lang}", $lang_set[$lang], true);

				//FIXME: evaluate beenefits from chunking into app_lang
				/*
					foreach ($lang_set[$lang] as $app => $app_lang)
					{
						Cache::system_set('phpgwapi', "lang_{$lang}_{$app}", $app_lang, true);
					}
*/
			}
		}
	}

	/**
	 * Translate a string
	 *
	 * @param string $key the string to translate - truncates at 230 chars
	 * @param array $vars substitutions to apply to string "%$array_key" must be present in $key
	 * @param bool $only_common only use the "common" translation, should be used when calling this from non module contexts
	 * @return string the translated string - when unable to be translated, the string is returned as "!$key"
	 */
	public function translate($key, $vars = array(), $only_common = false, $force_app = '')
	{
		if (empty($key))
		{
			return;
		}

		if (!$userlang = $this->userlang)
		{
			$userlang = 'en';
		}

		$app_name = $force_app ? $force_app : Settings::getInstance()->get('flags')['currentapp'];
		$lookup_key = strtolower(trim(substr($key, 0, self::MAX_MESSAGE_ID_LENGTH)));

		if ((!isset(self::$lang[$app_name][$lookup_key]) && !isset(self::$lang['common'][$lookup_key]))
			&& (!$only_common && !isset(self::$lang[$app_name][$lookup_key]))
		)
		{
			$applist = "'common'";
			if (!$only_common)
			{
				$applist .= ", '{$app_name}'";
			}

			$sql = 'SELECT message_id, content, app_name'
				. ' FROM phpgw_lang WHERE lang = :userlang AND message_id = :lookup_key'
				. ' AND app_name IN(' . $applist . ')';

			$stmt = $this->db->prepare($sql);
			$stmt->execute([':userlang' => $userlang, ':lookup_key' => $lookup_key]);

			while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$_app_name = $row['app_name'];
				self::$lang[$_app_name][$lookup_key] = $row['content'];
			}
		}

		$key = $lookup_key;
		$ret = '';

		if (!empty(self::$lang[$app_name][$key]))
		{
			$ret = self::$lang[$app_name][$key];
		}
		else if (!empty(self::$lang['common'][$key]))
		{
			$ret = self::$lang['common'][$key];
		}

		if (!$ret)
		{
			$ret = "!{$key}";	// save key if we dont find a translation
			//don't look for it again
			self::$lang[$app_name][$key] = $ret;

			if ($this->collect_missing)
			{
				$stmt = $this->db->prepare("SELECT message_id FROM phpgw_lang WHERE lang = :userlang AND message_id = :lookup_key AND app_name = :app_name");
				$stmt->execute([':userlang' => $userlang, ':lookup_key' => $lookup_key, ':app_name' => "##{$app_name}##"]);

				if ($stmt->fetch() === false)
				{
					$insertStmt = $this->db->prepare("INSERT INTO phpgw_lang (message_id,app_name,lang,content) VALUES(:lookup_key, :app_name, :userlang, 'missing')");
					$insertStmt->execute([':lookup_key' => $lookup_key, ':app_name' => "##{$app_name}##", ':userlang' => $userlang]);
				}
			}
		}

		$ndx = 1;

		foreach ($vars as $key => $val)
		{
			$ret = preg_replace("/%$ndx/", (string)$val, $ret);
			++$ndx;
		}
		return $ret;
	}

	/**
	 * Add an applications translation strings to the available list
	 *
	 * @param string $app the application's strings to add
	 */
	public function add_app($app)
	{
		if (!is_array(self::$lang))
		{
			self::$lang = array();
		}

		if (!$userlang = $this->userlang)
		{
			$userlang = 'en';
		}

		$sql = "SELECT message_id,content FROM phpgw_lang WHERE lang = :userlang AND app_name = :app";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':userlang' => $userlang, ':app' => $app]);

		$currentapp = Settings::getInstance()->get('flags')['currentapp'];

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			self::$lang[$currentapp][strtolower($row['message_id'])] = $row['content'];
		}
	}

	/**
	 * Get a list of installed languages
	 *
	 * @return array list of languages - count() == 0 none installed (shouldn't happen - EVER!)
	 */
	public function get_installed_langs()
	{
		$langs = array();

		$stmt = $this->db->prepare('SELECT DISTINCT l.lang, ln.lang_name FROM phpgw_lang l, phpgw_languages ln WHERE l.lang = ln.lang_id');
		$stmt->execute();

		$langs = array();
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC))
		{
			$langs[$row['lang']] = $row['lang_name'];
		}
		return $langs;
	}

	function get_apps()
	{
		$sql = 'SELECT app_name FROM phpgw_applications WHERE app_enabled != 0 ORDER BY app_order ASC';
		// get all installed apps
		$apps = [];
		try
		{
			$this->db->query($sql);
			$values = $this->db->resultSet;

			foreach ($values as $key => $value)
			{
				$apps[$value['app_name']] = $value['app_name'];
			}
		}
		catch (\PDOException $e)
		{
			die("Error executing query: " . $e->getMessage());
		}

		return $apps;
	}

	/**
	 * Update the currently available translation strings stored in the db
	 *
	 * @param array $lang_selected the languages to update
	 * @param string $upgrademethod the way to upgrade the translations
	 * @return string any error messages - empty string means it worked perfectly
	 */
	public function update_db($lang_selected, $upgrademethod)
	{
		$error = '';

		$this->db->beginTransaction();

		if (!isset($this->serverSettings['lang_ctimes']))
		{
			$this->serverSettings['lang_ctimes'] = array();
		}

		if (!isset($this->serverSettings) && $upgrademethod != 'dumpold')
		{
			$stmt = $this->db->prepare("SELECT * FROM phpgw_config WHERE config_app = 'phpgwapi' AND config_name = 'lang_ctimes'");
			$stmt->execute();

			if ($row = $stmt->fetch(PDO::FETCH_ASSOC))
			{
				$this->serverSettings['lang_ctimes'] = unserialize($row['config_value']);
			}
		}
		if (count($lang_selected))
		{

			if ($upgrademethod == 'dumpold')
			{
				$stmt = $this->db->prepare('DELETE FROM phpgw_lang');
				$stmt->execute();
				$this->serverSettings['lang_ctimes'] = array();
			}

			foreach ($lang_selected as $lang)
			{
				$lang = strtolower($lang);

				if (strlen($lang) != 2)
				{
					$error .= "Invalid lang code '" . htmlspecialchars($lang) . "': skipping<br>\n";
					continue;
				}

				//echo '<br />Working on: ' . $lang;
				Cache::system_clear('phpgwapi', "lang_{$lang}");

				if ($upgrademethod == 'addonlynew')
				{
					$stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM phpgw_lang WHERE lang=:lang");
					$stmt->execute([':lang' => $lang]);

					$row = $stmt->fetch(PDO::FETCH_ASSOC);

					if ($row['cnt'] != 0)
					{
						echo "<div class=\"error\">Lang code '{$lang}' already installed: skipping</div>\n";
						continue;
					}
				}

				$raw = array();

				// this populates Settings/apps

				// Visit each app/setup dir, look for a phpgw_lang file
				// get apps from database instead of filesystem

				$apps = $this->get_apps();

				foreach (array_keys($apps) as $app)
				{

					$appfile = SRC_ROOT_PATH . "/modules/" . $app . "/setup/phpgw_{$lang}.lang";
					if (!is_file($appfile))
					{
						// make sure file exists before trying to load it
						continue;
					}

					$lines = $this->parse_lang_file($appfile, $lang);
					if (!count($lines))
					{
						echo "<div class=\"error\">" . implode("<br>\n", $this->errors) . "</div>\n";
						$this->errors = array();
						continue;
					}

					foreach ($lines as $line)
					{
						$message_id = strtolower(trim(substr($line['message_id'], 0, self::MAX_MESSAGE_ID_LENGTH)));
						$app_name = trim($line['app_name']);
						$content = trim($line['content']);

						$raw[$app_name][$message_id] = $content;
					}
					// Override with localised translations

					$ConfigDomain = $this->db->get_domain();
					$appfile_override = SRC_ROOT_PATH . "/modules" . $app . "/setup/{$ConfigDomain}/phpgw_{$lang}.lang";

					if (is_file($appfile_override))
					{
						$lines = $this->parse_lang_file($appfile_override, $lang);
						if (count($lines))
						{
							foreach ($lines as $line)
							{
								$message_id = strtolower(trim(substr($line['message_id'], 0, self::MAX_MESSAGE_ID_LENGTH)));
								$app_name = trim($line['app_name']);
								$content = trim($line['content']);
								$raw[$app_name][$message_id] = $content;
							}
						}
					}

					$this->serverSettings['lang_ctimes'][$lang][$app] = filectime($appfile);
				}
				foreach ($raw as $app_name => $ids)
				{
					foreach ($ids as $message_id => $content)
					{
						if ($upgrademethod == 'addmissing')
						{
							$stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM phpgw_lang WHERE message_id=:message_id AND lang=:lang AND app_name=:app_name");
							$stmt->execute([':message_id' => $message_id, ':lang' => $lang, ':app_name' => $app_name]);

							$row = $stmt->fetch(PDO::FETCH_ASSOC);
							if ($row['cnt'] != 0)
							{
								continue;
							}
						}

						$stmt = $this->db->prepare("INSERT INTO phpgw_lang (message_id,app_name,lang,content) VALUES(:message_id, :app_name, :lang, :content)");
						$result = $stmt->execute([':message_id' => $message_id, ':app_name' => $app_name, ':lang' => $lang, ':content' => $content]);

						if (!$result)
						{
							$error .= "Error inserting record: phpgw_lang values ('$message_id','$app_name','$lang','$content')<br>";
						}
					}
				}
			}

			$stmt = $this->db->prepare("DELETE from phpgw_config WHERE config_app='phpgwapi' AND config_name='lang_ctimes'");
			$stmt->execute();

			$stmt = $this->db->prepare("INSERT INTO phpgw_config(config_app,config_name,config_value) VALUES ('phpgwapi','lang_ctimes', :config_value)");
			$stmt->execute([':config_value' => serialize($this->serverSettings['lang_ctimes'])]);

			Settings::getInstance()->set('server', $this->serverSettings);

			$this->db->commit();
		}
		return $error;
	}
}
