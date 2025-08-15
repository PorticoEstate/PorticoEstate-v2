<?php

namespace App\modules\bookingfrontend\helpers;

use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Config;
use App\modules\phpgwapi\services\Cache;
use App\Database\Db;
use App\modules\phpgwapi\controllers\OpenIDConnect;
use App\modules\phpgwapi\security\Sessions;
use App\modules\bookingfrontend\helpers\WebSocketHelper;

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
		// Handle both plain and encoded SSNs for backward compatibility
		$encodedSSN = $this->encodeSSN($ssn);

		$sql = "SELECT o.name, o.organization_number, o.active
                FROM bb_organization o
                INNER JOIN bb_delegate d ON o.id = d.organization_id
                WHERE (d.ssn = :ssn OR d.ssn = :encoded_ssn) AND d.active = 1";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':ssn' => $ssn,
			':encoded_ssn' => $encodedSSN
		]);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function get_all_delegates($ssn)
	{
		// Handle both plain and encoded SSNs for backward compatibility
		$encodedSSN = $this->encodeSSN($ssn);

		$sql = "SELECT o.id as org_id, o.name as name, o.organization_number as organization_number, d.active as active
                FROM bb_organization o
                INNER JOIN bb_delegate d ON o.id = d.organization_id
                WHERE (d.ssn = :ssn OR d.ssn = :encoded_ssn)";
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':ssn' => $ssn,
			':encoded_ssn' => $encodedSSN
		]);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Encode SSN using SHA1 hash with base64 encoding (same as old system)
	 */
	private function encodeSSN(string $ssn): string
	{
		// Check if SSN is already encoded
		if (preg_match('/^{(.+)}(.+)$/', $ssn)) {
			return $ssn; // Already encoded
		}

		// Encode using SHA1 + base64 (same as old system)
		$hash = sha1($ssn);
		return '{SHA1}' . base64_encode($hash);
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
			// Check if user has active delegate access to organization by number
			if ($this->ssn) {
				$encodedSSN = $this->encodeSSN($this->ssn);
				
				$sql = "SELECT 1 FROM bb_organization o
						INNER JOIN bb_delegate d ON o.id = d.organization_id
						WHERE o.organization_number = :org_number 
						AND (d.ssn = :ssn OR d.ssn = :encoded_ssn)
						AND d.active = 1";

				$stmt = $this->db->prepare($sql);
				$stmt->execute([
					':org_number' => $organization_number,
					':ssn' => $this->ssn,
					':encoded_ssn' => $encodedSSN
				]);

				return (bool)$stmt->fetch();
			}
			return false;
		}

		$organization_info = $this->get_organization_info($organization_id);

		$customer_ssn = $organization_info['customer_ssn'];

		// Check if user is direct owner of organization
		if ($organization_id && $customer_ssn)
		{
			$external_login_info = $this->validate_ssn_login();
			return $customer_ssn == $external_login_info['ssn'];
		}

		if ($organization_info['organization_number'] == '')
		{
			return false;
		}

		// Check if user has active delegate access to this organization
		if ($this->ssn) {
			$encodedSSN = $this->encodeSSN($this->ssn);
			
			$sql = "SELECT 1 FROM bb_delegate d
					WHERE d.organization_id = :org_id 
					AND (d.ssn = :ssn OR d.ssn = :encoded_ssn)
					AND d.active = 1";

			$stmt = $this->db->prepare($sql);
			$stmt->execute([
				':org_id' => $organization_id,
				':ssn' => $this->ssn,
				':encoded_ssn' => $encodedSSN
			]);

			return (bool)$stmt->fetch();
		}

		return false;
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


	/**
	 * Get groups associated with the current user
	 * Retrieves groups where the user is either an organization admin or delegate
	 *
	 * @return array List of groups the user has access to
	 */
	public function getUserGroups(): array
	{
		if (!$this->is_logged_in()) {
			return [];
		}

		// Get organization IDs from user's organizations
		$orgIds = [];

		// Use the existing organizations property
		if ($this->organizations) {
			$orgIds = array_column($this->organizations, 'org_id');
		}

		// Also add organizations where user is directly the admin by SSN
		if ($this->ssn) {
			$sql = "SELECT id FROM bb_organization WHERE customer_ssn = :ssn";
			$stmt = $this->db->prepare($sql);
			$stmt->execute([':ssn' => $this->ssn]);
			$directOrgIds = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id');

			$orgIds = array_merge($orgIds, $directOrgIds);
			$orgIds = array_unique($orgIds); // Remove duplicates
		}

		if (empty($orgIds)) {
			return [];
		}

		// Get all groups for these organizations
		$placeholders = implode(',', array_fill(0, count($orgIds), '?'));
		$sql = "SELECT g.*, o.name as organization_name
            FROM bb_group g
            JOIN bb_organization o ON g.organization_id = o.id
            WHERE g.organization_id IN ($placeholders)
            AND g.active = 1
            ORDER BY g.name";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($orgIds);
		$groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		return $groups;
	}

	/**
	 * Get groups for a specific organization
	 *
	 * @param int $organization_id The ID of the organization
	 * @param bool $checkAccess Whether to check if the user has access to the organization
	 * @return array List of groups belonging to the organization
	 */
	public function getOrganizationGroups(int $organization_id, bool $checkAccess = true): array
	{
		// Check if user has access to this organization if required
		if ($checkAccess && !$this->is_organization_admin($organization_id)) {
			return [];
		}

		$sql = "SELECT g.*, o.name as organization_name
            FROM bb_group g
            JOIN bb_organization o ON g.organization_id = o.id
            WHERE g.organization_id = :organization_id
            AND g.active = 1
            ORDER BY g.name";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([':organization_id' => $organization_id]);
		$groups = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		return $groups;
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

	public function process_callback()
	{

		$skip_redirect = true;

		$this->validate_ssn_login($redirect = array(), $skip_redirect);

		$after = json_decode(\Sanitizer::get_var('after', 'raw', 'COOKIE'), true);

		$login_as_organization = \Sanitizer::get_var('login_as_organization', 'int', 'COOKIE');
		Sessions::getInstance()->phpgw_setcookie('login_as_organization', '0');
		Sessions::getInstance()->phpgw_setcookie('after', '', -3600);

		if ($login_as_organization)
		{
			/**
			 * Pick up the external login-info
			 */
			$bouser = new UserHelper();
			$bouser->log_in();
		}

		// If 'after' contains a '/', treat it as a URI (e.g., /this/page?with=params)
		if (strpos($after, '/') !== false || strpos($after, '?') !== false)
		{
			// Parse the URL to extract the path and query parameters
			$parsed_url = parse_url($after);
			$path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
			$query = isset($parsed_url['query']) ? $parsed_url['query'] : '';

			// Convert the query string into an array
			$query_params = [];
			if (!empty($query))
			{
				parse_str($query, $query_params);
			}
			$query_params['rid'] = Sessions::getInstance()->generate_click_history();

			// Sanitize and validate the path
			if (filter_var($path, FILTER_SANITIZE_URL))
			{
				// Redirect to the extracted path with query parameters
				\phpgw::redirect_link('/bookingfrontend' . $path, $query_params);
				exit;
			}
		}
		else if (!empty($after))
		{
			// If 'after' doesn't look like a URI, treat it as query params
			$redirect_data = [];
			parse_str($after, $redirect_data);
			if (isset($redirect_data['click_history']))
			{
				unset($redirect_data['click_history']);
			}
			if ($redirect_data)
			{
				$redirect_data['rid'] = Sessions::getInstance()->generate_click_history();
				// Redirect to /bookingfrontend/ with the provided query params
				\phpgw::redirect_link('/bookingfrontend/', $redirect_data);
				exit;
			}
			else
			{
				\phpgw::redirect_link('/bookingfrontend/');
				exit;
			}

		}
		else
		{
			\phpgw::redirect_link('/bookingfrontend/');
		}
	}

	public function get_cached_user_data()
	{
		return Cache::session_get($this->get_module(), self::USERARRAY_SESSION_KEY);
	}

	/**
	 * Validate external safe login - and return to me
	 * @param array $redirect
	 */
	public function validate_ssn_login($redirect = array(), $skip_redirect = false)
	{

		$after = str_replace('&amp;', '&', urldecode(\Sanitizer::get_var('after', 'raw', 'GET')));

		if ($after)
		{
			//convert the query string into an array: menuaction=bookingfrontend.uibuilding.show&id=46&click_history=44a37f06be01ecb798e1e7b2a782fb09
			//parse_str($after, $after);

			Sessions::getInstance()->phpgw_setcookie('after', json_encode($after), 0);
		}


		static $user_data = array();
		if (!$user_data)
		{
			$user_data = $this->get_cached_user_data();
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
		if (!empty($config_openid['common']['method_frontend']) && in_array('remote', $config_openid['common']['method_frontend']))
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
				
				// Initialize user data in database if this is first-time login
				$this->initialize_user_data($ret);
			}
			catch (\Exception $exc)
			{
				// Log the exception but continue with login
				error_log("Error fetching external user data: " . $exc->getMessage());
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

	/**
	 * Initialize user data in database if this is first-time login
	 * @param array $external_data Data retrieved from external service
	 */
	private function initialize_user_data($external_data)
	{
		if (empty($external_data['ssn'])) {
			return;
		}

		$ssn = $external_data['ssn'];
		$was_first_time_user = false;
		
		// Check if user already exists in database
		$existing_user = $this->get_user_id($ssn);
		if ($existing_user) {
			// User exists, update with latest external data if needed
			$this->update_user_from_external_data($existing_user, $external_data);
		} else {
			// First-time user, create new record
			$this->create_user_from_external_data($external_data);
			$was_first_time_user = true;
		}

		// Send WebSocket notification to refresh user data in connected clients
		// This is especially important for first-time users or when external data is updated
		try {
			if ($was_first_time_user) {
				error_log("UserHelper: Triggering WebSocket refresh for first-time user initialization");
			} else {
				error_log("UserHelper: Triggering WebSocket refresh for user data update");
			}
			
			WebSocketHelper::triggerBookingUserUpdate();
		} catch (\Exception $e) {
			// Log WebSocket errors but don't interrupt the user initialization process
			error_log("UserHelper: WebSocket notification failed: " . $e->getMessage());
		}
	}

	/**
	 * Create a new user record from external data
	 * @param array $external_data Data from external service
	 */
	private function create_user_from_external_data($external_data)
	{
		$fields = [
			'customer_ssn' => $external_data['ssn'],
			'name' => $external_data['name'] ?? '',
			'email' => $external_data['email'] ?? '',
			'phone' => $external_data['phone'] ?? '',
			'street' => $external_data['street'] ?? '',
			'zip_code' => $external_data['zip_code'] ?? '',
			'city' => $external_data['city'] ?? '',
			'created' => date('Y-m-d H:i:s')
		];

		// Filter out empty values
		$fields = array_filter($fields, function($value) {
			return $value !== '' && $value !== null;
		});

		if (empty($fields['customer_ssn'])) {
			error_log("Cannot create user: SSN is missing");
			return;
		}

		$placeholders = implode(',', array_fill(0, count($fields), '?'));
		$columns = implode(',', array_keys($fields));
		
		$sql = "INSERT INTO bb_user ({$columns}) VALUES ({$placeholders})";
		
		try {
			$stmt = $this->db->prepare($sql);
			$stmt->execute(array_values($fields));
			
			$userId = $this->db->lastInsertId();
			error_log("Created new user with ID: {$userId} for SSN: " . substr($external_data['ssn'], 0, 6) . "****");
		} catch (\Exception $e) {
			error_log("Error creating user: " . $e->getMessage());
		}
	}

	/**
	 * Update existing user record with latest external data
	 * @param int $user_id User ID to update
	 * @param array $external_data Data from external service
	 */
	private function update_user_from_external_data($user_id, $external_data)
	{
		// Only update if we have new external data
		$fields_to_update = [];
		$params = [':id' => $user_id];

		// Fields that can be updated from external data
		$updatable_fields = [
			'name' => $external_data['name'] ?? null,
			'street' => $external_data['street'] ?? null,
			'zip_code' => $external_data['zip_code'] ?? null,
			'city' => $external_data['city'] ?? null
		];

		foreach ($updatable_fields as $field => $value) {
			if (!empty($value)) {
				$fields_to_update[] = "{$field} = :{$field}";
				$params[":{$field}"] = $value;
			}
		}

		if (empty($fields_to_update)) {
			return; // Nothing to update
		}

		$params[':updated'] = date('Y-m-d H:i:s');
		$fields_to_update[] = "updated = :updated";

		$sql = "UPDATE bb_user SET " . implode(', ', $fields_to_update) . " WHERE id = :id";
		
		try {
			$stmt = $this->db->prepare($sql);
			$stmt->execute($params);
			
			if ($stmt->rowCount() > 0) {
				error_log("Updated user ID: {$user_id} with external data");
			}
		} catch (\Exception $e) {
			error_log("Error updating user: " . $e->getMessage());
		}
	}
}
