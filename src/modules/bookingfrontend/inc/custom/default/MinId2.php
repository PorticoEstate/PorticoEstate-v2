<?php

/**
 * phpGroupWare
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2010 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/
 * @package phpgroupware
 * @subpackage communication
 * @category core
 * @version $Id: Altinn_Bergen_kommune.php 4887 2010-02-23 10:33:44Z sigurd $
 */
/*
	  This program is free software: you can redistribute it and/or modify
	  it under the terms of the GNU General Public License as published by
	  the Free Software Foundation, either version 2 of the License, or
	  (at your option) any later version.

	  This program is distributed in the hope that it will be useful,
	  but WITHOUT ANY WARRANTY; without even the implied warranty of
	  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	  GNU General Public License for more details.

	  You should have received a copy of the GNU General Public License
	  along with this program.  If not, see <http://www.gnu.org/licenses/>.
	 */

/**
 * Wrapper for custom methods
 *
 * @package phpgroupware
 * @subpackage bookingfrontend
 */

use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\Database\Db;
use App\modules\phpgwapi\services\Log;

/**
 * START WRAPPER
 */
class bookingfrontend_external_user extends UserHelper
{

	public function __construct()
	{
		parent::__construct();

		if (!empty($this->config->config_data['debug']))
		{
			$this->debug = true;
		}
	}

	protected function get_user_orginfo()
	{
		$data = $this->validate_ssn_login();
		$fodselsnr = (string)$data['ssn'];
		$bregorgs = $this->get_breg_orgs($fodselsnr);

		if ($bregorgs == array())
		{
			$external_user = (object)'ciao';
			$external_user->login = '000000000';
		}
		else
		{
			if (count($bregorgs) > 1)
			{
				$external_user = (object)'ciao';
				$external_user->login = $bregorgs[0]['orgnr'];
				$orgs = array();
				foreach ($bregorgs as $org)
				{
					$orgs[] = array(
						'org_id' => $org['org_id'],
						'orgnr' => $org['orgnr'],
						'orgname' => $this->get_orgname_from_db($org['orgnr'], $org['customer_ssn'], $org['org_id'])
					);
				}
				Cache::session_set($this->get_module(), self::ORGARRAY_SESSION_KEY, $orgs);
			}
			elseif (count($bregorgs) == 1)
			{
				Cache::session_set($this->get_module(), self::ORGARRAY_SESSION_KEY, NULL);
				$external_user = (object)'ciao';
				$external_user->login = $bregorgs[0]['orgnr'];
			}
		}


		$this->log('External user', print_r($external_user, true));

		try
		{
			$orgnr = (new sfValidatorNorwegianOrganizationNumber())->clean($external_user->login);
			return array(
				'ssn'	 => $fodselsnr,
				'org_id' => $bregorgs[0]['org_id'],
				'orgnr' => $orgnr
			);
		}
		catch (sfValidatorError $e)
		{
			if ($this->debug)
			{
				echo $e->getMessage();
				die();
			}
			return array(
				'ssn'	 => null,
				'org_id' => null,
				'orgnr' => null
			);
		}
	}

	/**
	 * Henter organisasjonsnummer som personen har en rolle i
	 * @param string $fodselsnr
	 * @return array $results organisasjonsnr
	 */
	private function get_breg_orgs($fodselsnr)
	{
		$results = array();
		$orgs_validate = array(-1);

		/**
		 * Her kaller du tjenesten som gjør spørringen mot Brønnøysund.
		 *	$fodselsnr er som det skal være (ikke hash)
		 */
		try
		{
			$orgs = $this->get_orgs_from_external_service($fodselsnr);
		}
		catch (Exception $e)
		{
			$log = new Log();
			$log->error(array(
				'text'	=> "<b>Exception:</b>\n" . $e->getMessage() . "\n" . $e->getTraceAsString(),
				'line'	=> $e->getline(),
				'file'	=> $e->getfile()
			));
		}

		if ($orgs && is_array($orgs))
		{
			foreach ($orgs as $org)
			{
				if (empty($org['orgnr']))
				{
					continue;
				}
				/*
					$this->db->query("SELECT id as org_id, organization_number"
						. " FROM bb_organization"
						. " WHERE active = 1 AND organization_number = '{$org['orgnr']}'", __LINE__, __FILE__);

					if (!$this->db->next_record())
					{
						continue;
					}

					$results[] = array
					(
						'orgnr' => $org['orgnr'],
						'customer_ssn'	 => null
					);
*/
				$orgs_validate[] = $org['orgnr'];
			}
		}

		$hash	 = sha1($fodselsnr);
		$ssn	 = '{SHA1}';
		$ssn	 .= base64_encode($hash);

		$sql = "SELECT DISTINCT * FROM ("
			// Delegates
			. "SELECT bb_organization.id as org_id, bb_organization.customer_ssn, bb_organization.organization_number, bb_organization.name AS organization_name"
			. " FROM bb_delegate"
			. " JOIN  bb_organization ON bb_delegate.organization_id = bb_organization.id"
			. " WHERE bb_delegate.active = 1 AND bb_organization.active = 1 AND bb_delegate.ssn = '{$ssn}'"
			. " UNION"
			// Personal organizations
			. " SELECT bb_organization.id as org_id, customer_ssn, organization_number, name AS organization_name"
			. " FROM bb_organization"
			. " WHERE bb_organization.active = 1 AND (customer_ssn = '{$fodselsnr}' AND customer_identifier_type = 'ssn')"
			. " OR organization_number IN ('" . implode("','", $orgs_validate) . "')"
			. " UNION"
			// Role from official registers
			. " SELECT id as org_id, customer_ssn, organization_number, name AS organization_name"
			. " FROM bb_organization"
			. " WHERE active = 1 AND organization_number IN ('" . implode("','", $orgs_validate) . "')"
			. " ) as t";

		$this->log('Delegert_eller_rolle_sql', $sql);

		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$org_id				 = $this->db->f('org_id');
			$customer_ssn		 = $this->db->f('customer_ssn');
			$organization_number = $this->db->f('organization_number');

			/*
				if($organization_number && in_array($organization_number, $orgs_validate))
				{
					continue;
				}
*/
			if ($customer_ssn && !$organization_number)
			{
				$organization_number = '000000000';
			}

			$results[] = array(
				'org_id'		 => $org_id,
				'orgnr'			 => $organization_number,
				'customer_ssn'	 => $customer_ssn
			);

			$orgs_validate[] = $organization_number;
		}

		$test_organizations = (array)explode(',', (string)$this->config->config_data['test_organization']);
		if ($this->debug && $test_organizations[0])
		{
			foreach ($test_organizations as $test_organization)
			{
				if (in_array($test_organization, $orgs_validate))
				{
					continue;
				}

				$this->db->query("SELECT id FROM bb_organization WHERE organization_number = '{$test_organization}'", __LINE__, __FILE__);
				while ($this->db->next_record())
				{
					$results[] = array(
						'org_id'		 => $this->db->f('id'),
						'orgnr'			 => $test_organization,
						'customer_ssn'	 => null
					);
				}
			}
		}

		return $results;
	}


	private function get_orgs_from_external_service($fodselsnr)
	{
		$apikey = !empty($this->config->config_data['apikey']) ? $this->config->config_data['apikey'] : '';
		$webservicehost = !empty($this->config->config_data['webservicehost']) ? $this->config->config_data['webservicehost'] : '';

		if (!$webservicehost || !$apikey)
		{
			throw new Exception('Missing parametres for webservice');
		}

		$post_data = array(
			'apikey'	=> $apikey,
			'id'		=> $fodselsnr
		);

		$post_string = http_build_query($post_data);

		$this->log('webservicehost', print_r($webservicehost, true));
		$this->log('POST data', print_r($post_data, true));

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_URL, $webservicehost);
		//			curl_setopt($ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
		$result = curl_exec($ch);

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$ret = json_decode($result, true);

		$this->log('webservice httpCode', print_r($httpCode, true));
		$this->log('webservice returdata as json', $result);
		$this->log('webservice returdata as array', print_r($ret, true));

		if (isset($ret['orgnr']))
		{
			return array($ret);
		}
		else
		{
			return $ret;
		}
	}

	private function log($what, $value = '')
	{
		$serverSettings = Settings::getInstance()->get('server');
		if (!empty($serverSettings['log_levels']['module']['login']))
		{
			$bt = debug_backtrace();
			$log = new Log();
			$log->debug(array(
				'text' => "what: %1, <br/>value: %2",
				'p1' => $what,
				'p2' => $value ? $value : ' ',
				'line' => __LINE__,
				'file' => __FILE__
			));
			unset($bt);
		}
	}
}
