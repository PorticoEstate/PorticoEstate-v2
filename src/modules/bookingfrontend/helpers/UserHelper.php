<?php

namespace App\modules\bookingfrontend\helpers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\services\Cache;
use App\Database\Db;
use App\modules\phpgwapi\controllers\OpenIDConnect;

class UserHelper
{

	const ORGNR_SESSION_KEY = 'orgnr';
	const ORGID_SESSION_KEY = 'org_id';
	const ORGARRAY_SESSION_KEY = 'orgarray';
	const USERARRAY_SESSION_KEY = 'userarray';

	public $ssn = null;
	/*
         * Official public identificator
         */
	public $orgnr = null;
	public $orgname = null;

	/*
         * Internal identificator
         */
	public $org_id = null;
	protected
		$default_module = 'bookingfrontend',
		$module,
		$config;


	public $organizations = null;

	/**
	 * Debug for testing
	 * @access public
	 * @var bool
	 */
	public $debug = false;
	var $db;

	public function __construct()
	{
		require_once(PHPGW_SERVER_ROOT . '/booking/inc/vendor/symfony/validator/bootstrap.php');
		$this->db = Db::getInstance();
		$this->set_module();
		$this->orgnr = $this->get_user_orgnr_from_session();
		$this->org_id = $this->get_user_org_id_from_session();

		$session_org_id = \Sanitizer::get_var('session_org_id', 'int', 'GET');
		if ($this->is_logged_in())
		{
			//            $this->organizations = Cache::session_get($this->get_module(), self::ORGARRAY_SESSION_KEY);
			$this->load_user_organizations();

			if ($session_org_id)
			{
				if (($session_org_id != $this->org_id) && in_array($session_org_id, array_map("self::get_ids_from_array", $this->organizations)))
				{
					try
					{
						$session_org_nr = '';
						foreach ($this->organizations as $org)
						{
							if ($org['org_id'] == $session_org_id)
							{
								$session_org_nr = $org['orgnr'];
							}
						}

						$org_number = (new \sfValidatorNorwegianOrganizationNumber)->clean($session_org_nr);
						if ($org_number)
						{
							$this->change_org($session_org_id);
						}
					}
					catch (\sfValidatorError $e)
					{
						$session_org_id = -1;
					}
				}
			}
			$external_login_info = $this->validate_ssn_login();
			$this->ssn = $external_login_info['ssn'];
		}

		$this->orgname = $this->get_orgname_from_db($this->orgnr, $this->ssn);
		$this->config = new Config('bookingfrontend');
		$this->config->read();
		if (!empty($this->config->config_data['debug']))
		{
			$this->debug = true;
		}
	}

	function get_ids_from_array($org)
	{
		return $org['org_id'];
	}

	protected function get_orgname_from_db($orgnr, $customer_ssn = null, $org_id = null)
	{
		if (!$orgnr)
		{
			return null;
		}

		if ($org_id)
		{
			$this->db->query("SELECT name FROM bb_organization WHERE id =" . (int)$org_id, __LINE__, __FILE__);
		}
		else if ($orgnr == '000000000' && $customer_ssn)
		{
			$this->db->limit_query("SELECT name FROM bb_organization WHERE customer_ssn ='{$customer_ssn}'", 0, __LINE__, __FILE__, 1);
		}
		else
		{
			$this->db->limit_query("SELECT name FROM bb_organization WHERE organization_number ='{$orgnr}'", 0, __LINE__, __FILE__, 1);
		}
		if (!$this->db->next_record())
		{
			return $orgnr;
		}
		return $this->db->f('name', false);
	}


	public function get_user_id($ssn)
	{
		$sql = "SELECT id FROM bb_user WHERE customer_ssn = :ssn";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':ssn' => $ssn]);
		$result = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $result ? $result['id'] : null;
	}

	public function read_single($id)
	{
		$sql = "SELECT * FROM bb_user WHERE id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $id]);
		return $stmt->fetch(\PDO::FETCH_ASSOC);
	}

	public function get_applications($ssn)
	{
		$sql = "SELECT a.id, a.created as date, a.status, b.name as building_name,
                GROUP_CONCAT(r.name SEPARATOR ', ') as resource_names,
                a.from_, a.customer_organization_number, a.contact_name
                FROM bb_application a
                LEFT JOIN bb_building b ON a.building_id = b.id
                LEFT JOIN bb_application_resource ar ON a.id = ar.application_id
                LEFT JOIN bb_resource r ON ar.resource_id = r.id
                WHERE a.customer_ssn = :ssn
                GROUP BY a.id
                ORDER BY a.created DESC";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':ssn' => $ssn]);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function get_invoices($ssn)
	{
		$sql = "SELECT i.id, i.description, i.article_description, i.cost,
                i.customer_organization_number, i.exported as invoice_sent
                FROM bb_invoice i
                WHERE i.customer_ssn = :ssn
                ORDER BY i.created DESC";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':ssn' => $ssn]);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function get_delegate($ssn)
	{
		$sql = "SELECT o.name, o.organization_number, o.active
                FROM bb_organization o
                INNER JOIN bb_delegate d ON o.id = d.organization_id
                WHERE d.customer_ssn = :ssn";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':ssn' => $ssn]);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	protected function get_organizations()
	{
		$results = array();
		$this->db = Db::getInstance();
		$this->db->query("select organization_number from bb_organization ORDER by organization_number ASC", __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$results[] = $this->db->f('organization_number', false);
		}
		return $results;
	}


	protected function load_user_organizations()
	{
		$orgs = Cache::session_get($this->get_module(), self::ORGARRAY_SESSION_KEY);
		$this->organizations = $orgs;

		//        // Set current organization for backward compatibility
		//        if (!$this->org_id && !empty($this->organizations)) {
		//            $first_org = reset($this->organizations);
		//            $this->org_id = $first_org['org_id'];
		//            $this->orgnr = $first_org['orgnr'];
		//            $this->orgname = $first_org['name'];
		//        }
	}


	protected function set_module($module = null)
	{
		$this->module = is_string($module) ? $module : $this->default_module;
	}

	public function get_module()
	{
		return $this->module;
	}

	public function log_in()
	{
		$this->log_off();

		$authentication_method = isset($this->config->config_data['authentication_method']) && $this->config->config_data['authentication_method'] ? $this->config->config_data['authentication_method'] : '';

		if (!$authentication_method)
		{
			throw new \LogicException('authentication_method not chosen');
		}

		$file = PHPGW_SERVER_ROOT . "/bookingfrontend/inc/custom/default/{$authentication_method}";

		if (!is_file($file))
		{
			throw new \LogicException("authentication method \"{$authentication_method}\" not available");
		}

		require_once $file;

		$external_user = new \bookingfrontend_external_user();

		$orginfo = $external_user->get_user_orginfo();
		$this->orgnr = $orginfo['orgnr'];
		$this->org_id = $orginfo['org_id'];
		$this->orgname = $this->get_orgname_from_db($orginfo['orgnr'], $orginfo['ssn'], $orginfo['org_id']);

		if ($this->is_logged_in())
		{
			$this->write_user_orgnr_to_session();
		}

		if ($this->debug)
		{
			//				echo 'is_logged_in():<br>';
			//				_debug_array($this->is_logged_in());
		}

		return $this->is_logged_in();
	}

	public function change_org($org_id)
	{
		$orgs = Cache::session_get($this->get_module(), self::ORGARRAY_SESSION_KEY);
		$orglist = array();
		foreach ($orgs as $org)
		{
			$orglist[] = $org['org_id'];

			if ($org['org_id'] == $org_id)
			{
				$this->orgnr = $org['orgnr'];
			}
		}
		if (in_array($org_id, $orglist))
		{

			$this->org_id = $org_id;
			$this->orgname = $this->get_orgname_from_db($this->orgnr, $this->ssn, $this->org_id);

			if ($this->is_logged_in())
			{
				$this->write_user_orgnr_to_session();
			}

			return $this->is_logged_in();
		}
		else
		{

			if ($this->is_logged_in())
			{
				$this->write_user_orgnr_to_session();
			}

			return $this->is_logged_in();
		}
	}

	public function log_off()
	{
		$this->clear_user_orgnr();
		$this->clear_user_orgnr_from_session();
		$this->clear_user_orglist_from_session();
		$this->clear_user_org_id_from_session();
	}

	protected function clear_user_orgnr()
	{
		$this->org_id = null;
		$this->orgnr = null;
		$this->orgname = null;
	}

	public function get_user_orgnr()
	{
		if (!$this->orgnr)
		{
			$this->orgnr = $this->get_user_orgnr_from_session();
		}
		return $this->orgnr;
	}

	public function get_user_org_id()
	{
		if (!$this->org_id)
		{
			$this->org_id = $this->get_user_org_id_from_session();
		}
		return $this->org_id;
	}

	public function is_logged_in()
	{
		return !!$this->get_user_orgnr();
	}

	public function is_organization_admin($organization_id = null, $organization_number = null)
	{
		if (!$this->is_logged_in())
		{
			return false;
		}

		/**
		 * On user adding organization from bookingfrontend
		 */
		if (!$organization_id && $organization_number)
		{
			$orgs = (array)Cache::session_get($this->get_module(), self::ORGARRAY_SESSION_KEY);

			$orgs_map = array();
			foreach ($orgs as $org)
			{
				$orgs_map[] = $org['orgnr'];
			}
			unset($org);
			return in_array($organization_number, $orgs_map);
		}

		$organization_info = $this->get_organization_info($organization_id);

		$customer_ssn = $organization_info['customer_ssn'];

		if ($organization_id && $customer_ssn)
		{
			$external_login_info = $this->validate_ssn_login();
			return $customer_ssn == $external_login_info['ssn'];
		}

		if ($organization_info['organization_number'] == '')
		{
			return false;
		}

		return $organization_id == $this->org_id;
	}

	private function get_organization_info($organization_id)
	{
		$sql = "SELECT customer_ssn, organization_number FROM bb_organization WHERE id = :organization_id";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':organization_id' => $organization_id));
		$organization = $sth->fetch();

		return $organization;
	}

	public function is_group_admin($group_id = null)
	{
		// FIXME!!!!!! REMOVE THIS ONCE ALTINN IS OPERATIONAL
		if (strcmp($_SERVER['SERVER_NAME'], 'dev.redpill.se') == 0 || strcmp($_SERVER['SERVER_NAME'], 'bk.localhost') == 0)
		{
			//return true;
		}
		// FIXME!!!!!! REMOVE THIS ONCE ALTINN IS OPERATIONAL
		if (!$this->is_logged_in())
		{
			//return false;
		}
		$group = $this->get_group_info($group_id);
		return $this->is_organization_admin($group['organization_id']);
	}

	private function get_group_info($group_id)
	{
		$sql = "SELECT organization_id FROM bb_group WHERE id = :group_id";
		$sth = $this->db->prepare($sql);
		$sth->execute(array(':group_id' => $group_id));
		$group = $sth->fetch();

		return $group;
	}

	protected function write_user_orgnr_to_session()
	{
		if (!$this->is_logged_in())
		{
			throw new \LogicException('Cannot write orgnr to session unless user is logged on');
		}

		Cache::session_set($this->get_module(), self::ORGNR_SESSION_KEY, $this->get_user_orgnr());
		Cache::session_set($this->get_module(), self::ORGID_SESSION_KEY, $this->get_user_org_id());
	}

	protected function clear_user_orgnr_from_session()
	{
		Cache::session_clear($this->get_module(), self::ORGNR_SESSION_KEY);
	}

	protected function clear_user_org_id_from_session()
	{
		Cache::session_clear($this->get_module(), self::ORGID_SESSION_KEY);
	}

	protected function clear_user_orglist_from_session()
	{
		#			Cache::session_clear($this->get_module(), self::ORGARRAY_SESSION_KEY);
	}

	protected function get_user_org_id_from_session()
	{
		return Cache::session_get($this->get_module(), self::ORGID_SESSION_KEY);
	}

	protected function get_user_orgnr_from_session()
	{
		try
		{
			return (new \sfValidatorNorwegianOrganizationNumber)->clean(Cache::session_get($this->get_module(), self::ORGNR_SESSION_KEY));
		}
		catch (\sfValidatorError $e)
		{
			return null;
		}
	}

	public function get_session_id()
	{
		return Cache::session_get($this->get_module(), self::ORGID_SESSION_KEY);
	}


	protected function current_app()
	{
		$flags = Settings::getInstance()->get('flags');
		return $flags['currentapp'];
	}

	/**
	 * Validate external safe login - and return to me
	 * @param array $redirect
	 */
	public function validate_ssn_login($redirect = array(), $skip_redirect = false)
	{
		static $user_data = array();
		if (!$user_data)
		{
			$user_data = Cache::session_get($this->get_module(), self::USERARRAY_SESSION_KEY);
		}
		if (!empty($user_data['ssn']))
		{
			return $user_data;
		}

		if (!empty($this->config->config_data['test_ssn']))
		{
			$ssn = $this->config->config_data['test_ssn'];
			Cache::message_set('Warning: ssn is set by test-data', 'error');
		}
		else if (!empty($_SERVER['HTTP_UID']))
		{
			$ssn = (string)$_SERVER['HTTP_UID'];
		}
		if (!empty($_SERVER['OIDC_pid']))
		{
			$ssn = (string)$_SERVER['OIDC_pid'];
		}
		if (!empty($_SERVER['REDIRECT_OIDC_pid']))
		{
			$ssn = (string)$_SERVER['REDIRECT_OIDC_pid'];
		}

		$location_obj = new \App\modules\phpgwapi\controllers\Locations();
		$location_id	= $location_obj->get_id('admin', 'openid_connect');
		if ($location_id)
		{
			$config_openid = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();
		}

		/**
		 * OpenID Connect
		 */
		$redirect_after_callback = '';
		if (!empty($config_openid['common']['method_backend']) && in_array('remote', $config_openid['common']['method_backend']))
		{
			$get_ssn_callback = false;
			//check for the url path contains /bookingfrontend/userhelper/callback
			if (strpos($_SERVER['REQUEST_URI'], '/bookingfrontend/userhelper/callback') !== false)
			{
				$get_ssn_callback = true;
			}

			$type = 'remote';
			$config_openid[$type]['redirect_uri'] = \phpgw::link('/bookingfrontend/userhelper/callback', ['type' => $type], false, true);
			$OpenIDConnect = OpenIDConnect::getInstance($type, $config_openid);

			if (!$get_ssn_callback)
			{
				Cache::session_set('bookingfrontend', 'redirect_after_callback', json_encode($redirect));
				$OpenIDConnect->authenticate();
				exit;
			}
			else
			{
				$ssn = $OpenIDConnect->get_username();
				$redirect_after_callback = Cache::session_get('bookingfrontend', 'redirect_after_callback');
				Cache::session_clear('bookingfrontend', 'redirect_after_callback');
			}
		}

		if (isset($this->config->config_data['bypass_external_login']) && $this->config->config_data['bypass_external_login'])
		{
			$ret = array(
				'ssn' => $ssn,
				'phone' => (string)$_SERVER['HTTP_MOBILTELEFONNUMMER'],
				'email' => (string)$_SERVER['HTTP_EPOSTADRESSE']
			);
			Cache::session_set($this->get_module(), self::USERARRAY_SESSION_KEY, $ret);

			return $ret;
		}

		$configfrontend = (new Config('bookingfrontend'))->read();

		try
		{
			$sf_validator = new \sfValidatorNorwegianSSN(array(), array(
				'invalid' => 'ssn is invalid'
			));

			$sf_validator->setOption('required', true);
			$sf_validator->clean($ssn);
		}
		catch (\sfValidatorError $e)
		{
			if ($skip_redirect)
			{
				return array();
			}

			\phpgw::no_access($this->current_app(), 'Du må logge inn via ID-porten');

			/*
            if (\Sanitizer::get_var('second_redirect', 'bool'))
            {
                \phpgw::no_access($this->current_app(), 'Du må logge inn via ID-porten');
            }

            Cache::session_set('bookingfrontend', 'redirect', json_encode($redirect));

            $login_parameter = isset($configfrontend['login_parameter']) && $configfrontend['login_parameter'] ? $configfrontend['login_parameter'] : '';
            $custom_login_url = isset($configfrontend['custom_login_url']) && $configfrontend['custom_login_url'] ? $configfrontend['custom_login_url'] : '';
            if ($custom_login_url && $login_parameter)
            {
                if (strpos($custom_login_url, '?'))
                {
                    $sep = '&';
                }
                else
                {
                    $sep = '?';
                }
                $login_parameter = ltrim($login_parameter, '&');
                $custom_login_url .= "{$sep}{$login_parameter}";
            }

            if ($custom_login_url)
            {
                header('Location: ' . $custom_login_url);
                exit;
            }
            else
            {
                \phpgw::redirect_link('/bookingfrontend/login/');
            }
*/
		}

		$ret = array(
			'ssn' => $ssn,
			'phone' => (string)$_SERVER['HTTP_MOBILTELEFONNUMMER'],
			'email' => (string)$_SERVER['HTTP_EPOSTADRESSE']
		);

		$get_name_from_external = isset($configfrontend['get_name_from_external']) && $configfrontend['get_name_from_external'] ? $configfrontend['get_name_from_external'] : '';

		$file = PHPGW_SERVER_ROOT . "/bookingfrontend/inc/custom/default/{$get_name_from_external}";

		if (is_file($file))
		{
			require_once $file;
			$external_user = new \bookingfrontend_external_user_name();
			try
			{
				$external_user->get_name_from_external_service($ret);
			}
			catch (\Exception $exc)
			{
			}
		}

		Cache::session_set($this->get_module(), self::USERARRAY_SESSION_KEY, $ret);

		if ($redirect_after_callback)
		{
			$redirect = json_decode($redirect_after_callback, true);
			if ($redirect)
			{
				\phpgw::redirect_link('/bookingfrontend/', $redirect);
			}
		}


		return $ret;
	}
}
