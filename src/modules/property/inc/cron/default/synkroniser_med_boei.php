<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003,2004,2005,2006,2007 Free Software Foundation, Inc. http://www.fsf.org/
 * This file is part of phpGroupWare.
 *
 * phpGroupWare is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * phpGroupWare is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with phpGroupWare; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package property
 * @subpackage custom
 * @version $Id$
 */

/**
 * Description
 * example cron : /usr/bin/php -q /var/www/Api/src/modules/property/inc/cron/cron.php default synkroniser_med_boei
 * @package property
 */

use App\Database\Db2;
use App\modules\phpgwapi\services\Settings;

include_class('property', 'cron_parent', 'inc/cron/');

class synkroniser_med_boei extends property_cron_parent
{

	var $bocommon, $db_boei, $db_boei2, $db2;
	var $alert_messages = array();
	private $categories_to_exclude;
	private $exclude_loc1 = array();

	function __construct()
	{
		parent::__construct();

		$this->function_name = get_class($this);
		$this->sub_location	 = lang('location');
		$this->function_msg	 = 'Synkroniser_med_boei';

		$this->bocommon	 = CreateObject('property.bocommon');
		$this->join		 = $this->db->join;
		$this->like		 = $this->db->like;
		$this->left_join = " LEFT JOIN ";
		$this->db2		 = new Db2();

		$external_db = Settings::getInstance()->get('external_db');

		$host_info		 = explode(':', $external_db['boei']['db_host']);
		$host	 = $host_info[0];
		$port	 = isset($host_info[1]) && $host_info[1] ? $host_info[1] : $external_db['boei']['db_port'];

		$boei_dsn = "sqlsrv:Server={$host},{$port};Database={$external_db['boei']['db_name']}";
		$options = [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		];

		try
		{
			$this->db_boei = new Db2($boei_dsn, $external_db['boei']['db_user'], $external_db['boei']['db_pass'], $options);

			$this->db_boei->set_config($external_db['boei']);
		}
		catch (Exception $e)
		{
			$status = lang('unable_to_connect_to_database');
		}

		$this->db_boei2	 = clone ($this->db_boei);

		/*		echo "db\n";
		_debug_array($this->db->get_config());
		echo "db2\n";
		_debug_array($this->db2->get_config());
		echo "db_boei\n";
		_debug_array($this->db_boei->get_config());
		echo "db_boei2\n";
		_debug_array($this->db_boei2->get_config());
*/

		$this->categories_to_exclude = array(
			-1, // -1 = "Dummy"
			120, // 120 = "Flyktning Innleie"
		);
	}

	function execute()
	{
		$start = time();
		set_time_limit(2000);
		$this->update_tables();
		$this->legg_til_formaal();
		$this->legg_til_eier_phpgw();
		$this->legg_til_gateadresse_phpgw();
		$this->legg_til_objekt_phpgw();
		$this->legg_til_bygg_phpgw();
		$this->legg_til_seksjon_phpgw();
		$this->legg_til_leieobjekt_phpgw();
		$this->legg_til_leietaker_phpgw();
		$this->oppdater_leieobjekt();
		$this->oppdater_boa_objekt();
		$this->legg_til_zip_code_phpgw();
		$this->oppdater_boa_bygg();
		$this->oppdater_boa_del();
		$this->oppdater_oppsagtdato();
		$this->update_tenant_name();
		$this->update_tenant_phone();
		$this->slett_feil_telefon();
		$this->update_tenant_termination_date();
		$this->update_obskode();
		$this->update_hemmelig_adresse();
		$this->oppdater_namssakstatus_pr_leietaker();
		$this->alert();

		$msg						 = 'Tidsbruk: ' . (time() - $start) . ' sekunder';
		$this->cron_log($msg, $cron);
		echo "$msg\n";
		$this->receipt['message'][]	 = array('msg' => $msg);
	}

	function alert()
	{
		if ($this->alert_messages)
		{
			$subject = 'Oppdateringer fra BOEI til Portico';

			$toarray = array(
				'hc483@bergen.kommune.no',
				'Kvale, Silje <Silje.Kvale@bergen.kommune.no>'
			);
			$to		 = implode(';', $toarray);

			$html = implode('</br>', $this->alert_messages);

			try
			{
				$rc	 = CreateObject('phpgwapi.send')->msg('email', $to, $subject, $html, '', $cc	 = '', $bcc = '', 'hc483@bergen.kommune.no', 'Ikke svar', 'html');
			}
			catch (Exception $e)
			{
				$this->receipt['error'][] = array('msg' => $e->getMessage());
			}
		}
	}
	function cron_log($receipt = '')
	{

		$insert_values = array(
			$this->cron,
			date($this->db->datetime_format()),
			$this->function_name,
			$receipt
		);

		$insert_values = $this->db->validate_insert($insert_values);

		$sql = "INSERT INTO fm_cron_log (cron,cron_date,process,message) "
			. "VALUES ($insert_values)";
		$this->db->query($sql, __LINE__, __FILE__);
	}

	/**
	 * v_Eier
	 * 	v_Gateadresse
	 * 	boei_objekt
	 * 	boei_bygg
	 * 	boei_seksjon
	 * 	boei_leieobjekt
	 * 	boei_leietaker
	 * 	boei_reskontro
	 */
	function update_tables()
	{
		$this->update_table_formaal();
		$this->update_table_LeieobjektTVSignal();
		$this->update_table_eier();
		$this->update_table_gateadresse();
		$this->update_table_poststed();
		$this->update_table_Objekt();
		$this->update_table_Bygg();
		$this->update_table_seksjon();
		$this->update_table_leieobjekt();
		$this->update_table_leietaker();
		$this->update_table_reskontro();
	}

	function update_table_formaal()
	{
		$metadata = $this->db->metadata('boei_formaal');
		//_debug_array($metadata);

		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_formaal
				(
				  id integer NOT NULL,
				  navn character varying(50),
				  tjeneste_id integer,
				  CONSTRAINT boei_formaal_pkey PRIMARY KEY (id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_formaal', __LINE__, __FILE__);
		$sql_boei	 = 'SELECT TOP 100 PERCENT Formaal_ID, CAST(NavnPaaFormaal as TEXT) AS NavnPaaFormaal, CAST(Tjenestested as TEXT) AS Tjenestested FROM Formaal';
		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_formaal (id, navn, tjeneste_id)'
			. ' VALUES(?, ?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => (int)$this->db_boei->f('Formaal_ID'),
					'type'	 => PDO::PARAM_INT
				),
				2	 => array(
					'value'	 => $this->db->db_addslashes($this->db_boei->f('NavnPaaFormaal')),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => (int)$this->db_boei->f('Tjenestested'),
					'type'	 => PDO::PARAM_INT
				)
			);
		}

		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_LeieobjektTVSignal()
	{
		$metadata = $this->db->metadata('boei_leieobjekt_tv_signal');
		//_debug_array($metadata);
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_leieobjekt_tv_signal
				(
				  id integer NOT NULL,
				  navn character varying(50),
				  CONSTRAINT boei_tv_signal_pkey PRIMARY KEY (id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_leieobjekt_tv_signal', __LINE__, __FILE__);
		$sql_boei	 = 'SELECT TOP 100 PERCENT id,CAST(TVSignal as TEXT) AS TVSignal FROM LeieobjektTVSignal';
		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_leieobjekt_tv_signal (id, navn)'
			. ' VALUES(?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => (int)$this->db_boei->f('id'),
					'type'	 => PDO::PARAM_INT
				),
				2	 => array(
					'value'	 => $this->db->db_addslashes($this->db_boei->f('TVSignal')),
					'type'	 => PDO::PARAM_STR
				)
			);
		}

		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_eier()
	{
		//			$metadata_boei = $this->db_boei->metadata('Eier');
		$metadata = $this->db->metadata('boei_eier');
		//_debug_array($metadata);
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_eier
				(
				  eier_id integer NOT NULL,
				  navn character varying(50),
				  eiertype_id integer NOT NULL,
				  CONSTRAINT boei_eier_pkey PRIMARY KEY (eier_id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_eier', __LINE__, __FILE__);
		$sql_boei	 = 'SELECT TOP 100 PERCENT Eier_ID,CAST(Navn as TEXT) AS Navn,EierType_ID  FROM Eier';
		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_eier (eier_id, navn, eiertype_id)'
			. ' VALUES(?, ?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => (int)$this->db_boei->f('Eier_ID'),
					'type'	 => PDO::PARAM_INT
				),
				2	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('Navn'))),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => (int)$this->db_boei->f('EierType_ID'),
					'type'	 => PDO::PARAM_INT
				)
			);
		}

		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_Gateadresse()
	{
		$metadata_boei	 = $this->db_boei->metadata('Gateadresse');
		//	_debug_array($metadata_boei);
		$metadata		 = $this->db->metadata('boei_gateadresse');
		//_debug_array($metadata);

		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_gateadresse
				(
				  gateadresse_id integer NOT NULL,
				  gatenavn character varying(50),
				  nasjonalid integer,
				  CONSTRAINT boei_gateadresse_pkey PRIMARY KEY (gateadresse_id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_gateadresse', __LINE__, __FILE__);
		$sql_boei = 'SELECT TOP 100 PERCENT Gateadresse_ID, CAST(GateNavn as TEXT) AS GateNavn, NasjonalID FROM Gateadresse';
		$this->db_boei->query($sql_boei, __LINE__, __FILE__);

		// using stored prosedures
		$sql		 = 'INSERT INTO boei_gateadresse (gateadresse_id, gatenavn, nasjonalid)'
			. ' VALUES(?, ?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => (int)$this->db_boei->f('Gateadresse_ID'),
					'type'	 => PDO::PARAM_INT
				),
				2	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('GateNavn'))),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => (int)$this->db_boei->f('NasjonalID'),
					'type'	 => PDO::PARAM_INT
				)
			);
		}

		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_poststed()
	{
		//			$metadata_boei	 = $this->db_boei->metadata('Poststed');
		//	_debug_array($metadata_boei);
		$metadata		 = $this->db->metadata('boei_poststed');
		//_debug_array($metadata);
		//die();
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_poststed
				(
					id character varying(4) NOT NULL,
					navn character varying(50),
				  CONSTRAINT boei_poststed_pkey PRIMARY KEY (id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_poststed', __LINE__, __FILE__);
		$sql_boei = 'SELECT TOP 100 PERCENT Postnr_ID, CAST(Poststed as TEXT) AS Poststed  FROM Poststed';

		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_poststed (id, navn)'
			. ' VALUES(?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => $this->db_boei->f('Postnr_ID'),
					'type'	 => PDO::PARAM_STR
				),
				2	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('Poststed'))),
					'type'	 => PDO::PARAM_STR
				)
			);
		}
		//			_debug_array($valueset);
		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_Objekt()
	{
		$metadata_boei	 = $this->db_boei->metadata('Objekt');
		//	_debug_array($metadata_boei);
		$metadata		 = $this->db->metadata('boei_objekt');
		//_debug_array($metadata);
		//die();
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_objekt
				(
					objekt_id character varying(4) NOT NULL,
					navn character varying(50),
					generelladresse character varying(50),
					bydel_id integer,
					postnr_id character varying(4),
					eier_id integer,
					tjenestested integer,
				  CONSTRAINT boei_objekt_pkey PRIMARY KEY (objekt_id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_objekt', __LINE__, __FILE__);
		$sql_boei = 'SELECT TOP 100 PERCENT Objekt_ID, CAST(Navn as TEXT) AS Navn, Bydel_ID, Postnr_ID, Eier_ID, Tjenestested  FROM Objekt';

		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_objekt (objekt_id, navn, bydel_id,postnr_id,eier_id,tjenestested)'
			. ' VALUES(?, ?, ?, ?, ?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => $this->db_boei->f('Objekt_ID'),
					'type'	 => PDO::PARAM_STR
				),
				2	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('Navn'))),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => (int)$this->db_boei->f('Bydel_ID'),
					'type'	 => PDO::PARAM_INT
				),
				4	 => array(
					'value'	 => $this->db_boei->f('Postnr_ID'),
					'type'	 => PDO::PARAM_STR
				),
				5	 => array(
					'value'	 => (int)$this->db_boei->f('Eier_ID'),
					'type'	 => PDO::PARAM_INT
				),
				6	 => array(
					'value'	 => (int)$this->db_boei->f('Tjenestested'),
					'type'	 => PDO::PARAM_INT
				)
			);
		}
		//			_debug_array($valueset);
		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_Bygg()
	{
		//_debug_array($this->db_boei);
		$metadata_boei	 = $this->db_boei->metadata('Bygg');
		//_debug_array($metadata_boei);
		$metadata		 = $this->db->metadata('boei_bygg');
		//_debug_array($metadata);
		//die();
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_bygg
				(
					objekt_id character varying(4) NOT NULL,
					bygg_id character varying(2) NOT NULL,
					byggnavn character varying(100),
					generelladresse character varying(100),
					driftstatus smallint,
				  CONSTRAINT boei_bygg_pkey PRIMARY KEY (objekt_id, bygg_id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_bygg', __LINE__, __FILE__);
		$sql_boei	 = 'SELECT TOP 100 PERCENT Objekt_ID, Bygg_ID, CAST(ByggNavn as TEXT) AS ByggNavn, Driftstatus FROM Bygg';
		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_bygg (objekt_id, bygg_id, byggnavn, driftstatus)'
			. ' VALUES(?, ?, ?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => $this->db_boei->f('Objekt_ID'),
					'type'	 => PDO::PARAM_STR
				),
				2	 => array(
					'value'	 => $this->db_boei->f('Bygg_ID'),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('ByggNavn'))),
					'type'	 => PDO::PARAM_STR
				),
				4	 => array(
					'value'	 => (int)$this->db_boei->f('Driftstatus'),
					'type'	 => PDO::PARAM_INT
				),
			);
		}

		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_Seksjon()
	{
		$metadata_boei	 = $this->db_boei->metadata('Seksjon');
		//_debug_array($metadata_boei);
		$metadata		 = $this->db->metadata('boei_seksjon');
		//_debug_array($metadata);
		//die();
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_seksjon
				(
					objekt_id character varying(4) NOT NULL,
					bygg_id character varying(2) NOT NULL,
					seksjons_id character varying(2) NOT NULL,
					beskrivelse character varying(35),
				  CONSTRAINT boei_seksjon_pkey PRIMARY KEY (objekt_id, bygg_id, seksjons_id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_seksjon', __LINE__, __FILE__);
		$sql_boei	 = 'SELECT TOP 100 PERCENT Objekt_ID, Bygg_ID, Seksjons_ID, CAST(Beskrivelse as TEXT) AS Beskrivelse  FROM Seksjon';
		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_seksjon (objekt_id, bygg_id, seksjons_id, beskrivelse)'
			. ' VALUES(?, ?, ?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => $this->db_boei->f('Objekt_ID'),
					'type'	 => PDO::PARAM_STR
				),
				2	 => array(
					'value'	 => $this->db_boei->f('Bygg_ID'),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => $this->db_boei->f('Seksjons_ID'),
					'type'	 => PDO::PARAM_STR
				),
				4	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('Beskrivelse'))),
					'type'	 => PDO::PARAM_STR
				)
			);
		}

		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_leieobjekt()
	{
		$metadata_boei	 = $this->db_boei->metadata('Leieobjekt');
		//_debug_array($metadata_boei);
		$metadata		 = $this->db->metadata('boei_leieobjekt');
		//_debug_array($metadata);
		//die();
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_leieobjekt
				(
					objekt_id character varying(4) NOT NULL,
					bygg_id character varying(2) NOT NULL,
					seksjons_id character varying(2) NOT NULL,
					leie_id character varying(3) NOT NULL,
					flyttenr smallint,
					formaal_id smallint,
					gateadresse_id integer,
					gatenr character varying(30),
					etasje character varying(5),
					antallrom smallint,
					boareal integer,
					andelavfellesareal smallint,
					livslopsstd smallint,
					heis smallint,
					driftsstatus_id smallint,
					leietaker_id integer,
					beregnet_boa numeric(20,2),
					tv_signal_id smallint,
					disponert_av character varying(60),

				  CONSTRAINT boei_leieobjekt_pkey PRIMARY KEY (objekt_id, bygg_id, seksjons_id, leie_id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_leieobjekt', __LINE__, __FILE__);
		$sql_boei = 'SELECT TOP 100 PERCENT Objekt_ID, Bygg_ID, Seksjons_ID, Leie_ID, Flyttenr,'
			. ' Formaal_ID, Gateadresse_ID, Gatenr, Etasje, AntallRom, Boareal,'
			. ' AndelAvFellesareal, Livslopsstd, Heis, Driftsstatus_ID, Leietaker_ID, Beregnet_Boa, TVSignal_ID, DisponertAv'
			. ' FROM Leieobjekt';

		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_leieobjekt (objekt_id, bygg_id, seksjons_id, leie_id, flyttenr,'
			. ' formaal_id, gateadresse_id, gatenr, etasje, antallrom, boareal,'
			. ' andelavfellesareal,livslopsstd, heis, driftsstatus_id, leietaker_id,beregnet_boa, tv_signal_id, disponert_av)'
			. ' VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => $this->db_boei->f('Objekt_ID'),
					'type'	 => PDO::PARAM_STR
				),
				2	 => array(
					'value'	 => $this->db_boei->f('Bygg_ID'),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => $this->db_boei->f('Seksjons_ID'),
					'type'	 => PDO::PARAM_STR
				),
				4	 => array(
					'value'	 => $this->db_boei->f('Leie_ID'),
					'type'	 => PDO::PARAM_STR
				),
				5	 => array(
					'value'	 => (int)$this->db_boei->f('Flyttenr'),
					'type'	 => PDO::PARAM_INT
				),
				6	 => array(
					'value'	 => (int)$this->db_boei->f('Formaal_ID'),
					'type'	 => PDO::PARAM_INT
				),
				7	 => array(
					'value'	 => (int)$this->db_boei->f('Gateadresse_ID'),
					'type'	 => PDO::PARAM_INT
				),
				8	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('Gatenr'))),
					'type'	 => PDO::PARAM_STR
				),
				9	 => array(
					'value'	 => $this->db->db_addslashes($this->db_boei->f('Etasje')),
					'type'	 => PDO::PARAM_STR
				),
				10	 => array(
					'value'	 => (int)$this->db_boei->f('AntallRom'),
					'type'	 => PDO::PARAM_INT
				),
				11	 => array(
					'value'	 => (int)$this->db_boei->f('Boareal'),
					'type'	 => PDO::PARAM_INT
				),
				12	 => array(
					'value'	 => (int)$this->db_boei->f('AndelAvFellesareal'),
					'type'	 => PDO::PARAM_INT
				),
				13	 => array(
					'value'	 => (int)$this->db_boei->f('Livslopsstd'),
					'type'	 => PDO::PARAM_INT
				),
				14	 => array(
					'value'	 => (int)$this->db_boei->f('Heis'),
					'type'	 => PDO::PARAM_INT
				),
				15	 => array(
					'value'	 => (int)$this->db_boei->f('Driftsstatus_ID'),
					'type'	 => PDO::PARAM_INT
				),
				16	 => array(
					'value'	 => (int)$this->db_boei->f('Leietaker_ID'),
					'type'	 => PDO::PARAM_INT
				),
				17	 => array(
					'value'	 => (float)$this->db_boei->f('Beregnet_Boa'),
					'type'	 => PDO::PARAM_STR
				),
				18	 => array(
					'value'	 => (int)$this->db_boei->f('TVSignal_ID'),
					'type'	 => PDO::PARAM_INT
				),
				19	 => array(
					'value'	 => $this->db_boei->f('DisponertAv'),
					'type'	 => PDO::PARAM_STR
				),
			);
		}
		//			_debug_array($valueset);
		$this->db->insert($sql, $valueset, __LINE__, __FILE__);

		$filter = implode(',', $this->categories_to_exclude);
		$sql_exlude = "SELECT DISTINCT objekt_id FROM boei_leieobjekt WHERE formaal_id IN ({$filter}) ORDER BY objekt_id";
		$this->db->query($sql_exlude, __LINE__, __FILE__);
		$exclude_loc1 = array();
		while ($this->db->next_record())
		{
			$exclude_loc1[] = $this->db->f('objekt_id');
		}

		$this->exclude_loc1 = $exclude_loc1;
	}

	function update_table_leietaker()
	{
		$metadata_boei	 = $this->db_boei->metadata('Leietaker');
		//		_debug_array($metadata_boei);
		$metadata		 = $this->db->metadata('boei_leietaker');
		//_debug_array($metadata);
		//die();
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_leietaker
				(
					leietaker_id integer NOT NULL,
					fornavn character varying(40),
					etternavn character varying(40),
					kjonn_juridisk smallint,
					oppsagtdato character varying(10),
					namssakstatusdrift_id smallint,
					namssakstatusokonomi_id smallint,
					hemmeligadresse smallint,
					obskode character varying(12),
					telefon character varying(12),
					ssn character varying(11),
					CONSTRAINT boei_leietaker_pkey PRIMARY KEY (leietaker_id)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_leietaker', __LINE__, __FILE__);

		$sql_boei	 = 'SELECT TOP 100 PERCENT Leietaker_ID, CAST(Fornavn as TEXT) AS Fornavn, CAST(Etternavn as TEXT) AS Etternavn, Kjonn_Juridisk,'
			. ' OppsagtDato, NamssakStatusDrift_ID, NamssakStatusOkonomi_ID, hemmeligAdresse, OBSKode, Telefon1, Fodt_dato, Personnr'
			. ' FROM Leietaker';
		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_leietaker (leietaker_id, fornavn, etternavn, kjonn_juridisk,'
			. ' oppsagtdato,namssakstatusdrift_id,namssakstatusokonomi_id,hemmeligadresse,obskode,telefon, ssn)'
			. ' VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
		$valueset	 = array();
		while ($this->db_boei->next_record())
		{
			$telefon = $this->db_boei->f('Telefon1');
			$Fodt_dato = $this->db_boei->f('Fodt_dato');
			$Personnr = $this->db_boei->f('Personnr');

			$ssn = null;
			if ($Personnr && $Fodt_dato)
			{
				$dato_arr = explode('.', $Fodt_dato);
				$ssn = $dato_arr[0] . $dato_arr[1] . substr($dato_arr[2], 2, 2) . $Personnr;
			}

			$valueset[] = array(
				1	 => array(
					'value'	 => (int)$this->db_boei->f('Leietaker_ID'),
					'type'	 => PDO::PARAM_INT
				),
				2	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('Fornavn'))),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => $this->db->db_addslashes(($this->db_boei->f('Etternavn'))),
					'type'	 => PDO::PARAM_STR
				),
				4	 => array(
					'value'	 => (int)$this->db_boei->f('Kjonn_Juridisk'),
					'type'	 => PDO::PARAM_INT
				),
				5	 => array(
					'value'	 => $this->db_boei->f('OppsagtDato'),
					'type'	 => PDO::PARAM_STR
				),
				6	 => array(
					'value'	 => (int)$this->db_boei->f('NamssakStatusDrift_ID'),
					'type'	 => PDO::PARAM_INT
				),
				7	 => array(
					'value'	 => (int)$this->db_boei->f('NamssakStatusOkonomi_ID'),
					'type'	 => PDO::PARAM_INT
				),
				8	 => array(
					'value'	 => (int)$this->db_boei->f('hemmeligAdresse'),
					'type'	 => PDO::PARAM_INT
				),
				9	 => array(
					'value'	 => ($this->db_boei->f('OBSKode')),
					'type'	 => PDO::PARAM_STR
				),
				10	 => array(
					'value'	 => ctype_digit($telefon) && strlen($telefon) == 8 ? $telefon : null,
					'type'	 => PDO::PARAM_STR
				),
				11	 => array(
					'value'	 => $ssn,
					'type'	 => PDO::PARAM_STR
				)
			);
		}
		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function update_table_reskontro()
	{
		$metadata_boei	 = $this->db_boei->metadata('reskontro');
		//_debug_array($metadata_boei);
		$metadata		 = $this->db->metadata('boei_reskontro');
		//_debug_array($metadata);
		//die();
		if (!$metadata)
		{
			$sql_table = <<<SQL
				CREATE TABLE boei_reskontro
				(
					objekt_id character varying(4) NOT NULL,
					leie_id character varying(3) NOT NULL,
					flyttenr smallint,
					leietaker_id integer NOT NULL,
					innflyttetdato character varying(10),
					CONSTRAINT boei_reskontro_pkey PRIMARY KEY (objekt_id,leie_id,flyttenr)
				);
SQL;
			$this->db->query($sql_table, __LINE__, __FILE__);
		}
		$this->db->query('DELETE FROM boei_reskontro', __LINE__, __FILE__);
		$sql_boei	 = 'SELECT TOP 100 PERCENT * FROM reskontro';
		$this->db_boei->query($sql_boei, __LINE__, __FILE__);
		// using stored prosedures
		$sql		 = 'INSERT INTO boei_reskontro (objekt_id,leie_id,flyttenr,leietaker_id, innflyttetdato )'
			. ' VALUES(?, ?, ?, ?, ?)';
		$valueset	 = array();

		while ($this->db_boei->next_record())
		{
			$valueset[] = array(
				1	 => array(
					'value'	 => $this->db_boei->f('Objekt_ID'),
					'type'	 => PDO::PARAM_STR
				),
				2	 => array(
					'value'	 => $this->db_boei->f('Leie_ID'),
					'type'	 => PDO::PARAM_STR
				),
				3	 => array(
					'value'	 => (int)$this->db_boei->f('Flyttenr'),
					'type'	 => PDO::PARAM_INT
				),
				4	 => array(
					'value'	 => (int)$this->db_boei->f('Leietaker_ID'),
					'type'	 => PDO::PARAM_INT
				),
				5	 => array(
					'value'	 => $this->db_boei->f('InnflyttetDato'),
					'type'	 => PDO::PARAM_STR
				)
			);
		}

		$this->db->insert($sql, $valueset, __LINE__, __FILE__);
	}

	function legg_til_eier_phpgw()
	{
		$sql = " SELECT boei_eier.eier_id as id, boei_eier.eiertype_id as category"
			. " FROM boei_eier";

		$this->db->query($sql, __LINE__, __FILE__);
		$owners = array();
		while ($this->db->next_record())
		{
			$category	 = $this->db->f('category');
			$owners[]	 = array(
				'id'		 => (int)$this->db->f('id'),
				'category'	 => $category == 0 ? 5 : $category
			);
		}
		$this->db->transaction_begin();

		foreach ($owners as $owner)
		{
			$sql2 = "UPDATE fm_owner set category = '{$owner['category']}' WHERE id = '{$owner['id']}'";

			$this->db->query($sql2, __LINE__, __FILE__);
		}

		unset($owner);
		$owners = array();

		$sql = "SELECT boei_eier.eier_id, boei_eier.navn as org_name,boei_eier.eiertype_id as category FROM  fm_owner RIGHT OUTER JOIN "
			. " boei_eier ON fm_owner.id = boei_eier.eier_id"
			. " WHERE (fm_owner.id IS NULL)";

		$this->db->query($sql, __LINE__, __FILE__);
		while ($this->db->next_record())
		{
			$category = $this->db->f('category');

			$owners[] = array(
				'id'		 => $this->db->f('eier_id'),
				'org_name'	 => $this->db->f('org_name'),
				'remark'	 => $this->db->f('org_name'),
				'category'	 => $category == 0 ? 5 : $category,
				'entry_date' => time(),
				'owner_id'	 => 6
			);
		}

		$owner_msg = array();
		foreach ($owners as $owner)
		{

			$sql2 = "INSERT INTO fm_owner (id,org_name,remark,category,entry_date,owner_id)"
				. "VALUES (" . $this->db->validate_insert($owner) . ")";

			$this->db->query($sql2, __LINE__, __FILE__);

			$owner_msg[] = $owner['org_name'];
		}

		$this->db->transaction_commit();

		$msg						 = count($owners) . ' eier er lagt til: ' . implode(",", $owner_msg);
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function legg_til_formaal()
	{
		$sql = "SELECT boei_formaal.id, boei_formaal.navn, boei_formaal.tjeneste_id"
			. " FROM fm_location4_category RIGHT OUTER JOIN "
			. " boei_formaal ON fm_location4_category.id = boei_formaal.id"
			. " WHERE fm_location4_category.id IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);
		$formaal_latin = array();
		while ($this->db->next_record())
		{
			$formaal_latin[] = array(
				'id'		 => $this->db->f('id'),
				'descr'		 => $this->db->f('navn'),
			);
		}

		$this->db->transaction_begin();

		$formaal_msg = array();
		foreach ($formaal_latin as $formaal)
		{

			$sql2 = "INSERT INTO fm_location4_category (id, descr) "
				. "VALUES (" . $this->db->validate_insert($formaal) . ")";

			$this->db->query($sql2, __LINE__, __FILE__);

			$formaal_msg[] = $formaal['descr'];
		}

		$sql = "UPDATE fm_location4_category SET descr = boei_formaal.navn FROM boei_formaal WHERE fm_location4_category.id = boei_formaal.id";
		$this->db->query($sql, __LINE__, __FILE__);

		$this->db->transaction_commit();

		$msg						 = count($formaal_latin) . ' Formål er lagt til: ' . implode(",", $formaal_msg);
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function legg_til_gateadresse_phpgw()
	{
		//legg til
		$sql = "SELECT boei_gateadresse.gateadresse_id, boei_gateadresse.gatenavn, boei_gateadresse.nasjonalid FROM fm_streetaddress RIGHT OUTER JOIN "
			. " boei_gateadresse ON fm_streetaddress.id = boei_gateadresse.gateadresse_id"
			. " WHERE (fm_streetaddress.id IS NULL)";

		$this->db->query($sql, __LINE__, __FILE__);
		$gater = array();
		while ($this->db->next_record())
		{
			$gater[] = array(
				'id'	 => (int)$this->db->f('gateadresse_id'),
				'descr'	 => $this->db->f('gatenavn'),
				'nasjonalid' => (int)$this->db->f('nasjonalid'),
			);
		}
		$this->db->transaction_begin();

		$gate_msg = array();
		foreach ($gater as $gate)
		{
			$sql2 = "INSERT INTO fm_streetaddress (id,descr, nasjonalid)"
				. " VALUES ({$gate['id']}, '{$gate['descr']}', {$gate['nasjonalid']})";

			$this->db->query($sql2, __LINE__, __FILE__);
			$gate_msg[] = $gate['descr'];
		}


		//oppdater gatenavn - om det er endret

		$sql = "SELECT boei_gateadresse.gateadresse_id, boei_gateadresse.gatenavn FROM boei_gateadresse";

		$this->db->query($sql, __LINE__, __FILE__);

		$msg = count($gater) . ' gateadresser er lagt til: ' . implode(",", $gate_msg);

		$gate = array();
		while ($this->db->next_record())
		{
			$gate[] = array(
				'id'	 => (int)$this->db->f('gateadresse_id'),
				'descr'	 => $this->db->f('gatenavn')
			);
		}

		foreach ($gate as $gate_info)
		{
			$sql_utf = "UPDATE fm_streetaddress SET descr = '{$gate_info['descr']}' WHERE id = " . (int)$gate_info['id'];
			$this->db->query($sql_utf, __LINE__, __FILE__);
		}

		$this->db->transaction_commit();

		$this->receipt['message'][] = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function legg_til_objekt_phpgw()
	{
		$sql = "SELECT boei_objekt.objekt_id, boei_objekt.navn, boei_objekt.bydel_id, boei_objekt.eier_id,boei_objekt.tjenestested, boei_objekt.postnr_id"
			. " FROM fm_location1 RIGHT OUTER JOIN "
			. " boei_objekt ON fm_location1.loc1 = boei_objekt.objekt_id"
			. " WHERE fm_location1.loc1 IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);
		$objekt_latin = array();
		while ($this->db->next_record())
		{
			$tjenestested = (int)$this->db->f('tjenestested');
			$loc1		  = $this->db->f('objekt_id');
			/*
 * Alle kostraid’er skal ha mva/AV-kode 75
 * Bortsett fra
 * 26550 som jeg ønsker varsel på,
 * og 26555 som ikke finnes enda, men som kanskje blir opprettet på innleieboliger.
*/

			$mva = 75;
			if (in_array($tjenestested, array(26550, 26555)))
			{
				$mva = 0;
			}

			if (in_array($tjenestested, array(26510, 26550, 26555, 26530, 26540, 26570, 26575)))
			{
				$this->alert_messages[] = "Opprettet objekt {$loc1} i Portico med tjeneste {$tjenestested}";
			}

			$objekt_latin[] = array(
				'location_code'		 => $loc1,
				'loc1'				 => $loc1,
				'loc1_name'			 => $this->db->f('navn'),
				'part_of_town_id'	 => $this->db->f('bydel_id'),
				'owner_id'			 => $this->db->f('eier_id'),
				'kostra_id'			 => $tjenestested,
				'zip_code'			 => $this->db->f('postnr_id'),
				'category'			 => 1,
				'mva'				 => $mva
			);
		}

		$this->db->transaction_begin();

		$obj_msg = array();
		foreach ($objekt_latin as $objekt)
		{

			$sql2 = "INSERT INTO fm_location1 (location_code, loc1, loc1_name, part_of_town_id, owner_id, kostra_id, zip_code, category, mva) "
				. "VALUES (" . $this->db->validate_insert($objekt) . ")";

			$this->db->query($sql2, __LINE__, __FILE__);
			$this->db->query("INSERT INTO fm_locations (level, location_code, loc1) VALUES (1, '{$objekt['location_code']}', '{$objekt['loc1']}')", __LINE__, __FILE__);

			$obj_msg[] = $objekt['loc1'];
		}

		$this->db->transaction_commit();

		$msg						 = count($objekt_latin) . ' Objekt er lagt til: ' . implode(",", $obj_msg);
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function legg_til_bygg_phpgw()
	{
		$sql = "SELECT boei_bygg.objekt_id || '-' || boei_bygg.bygg_id AS location_code, boei_bygg.objekt_id, boei_bygg.bygg_id, boei_bygg.byggnavn,boei_bygg.driftstatus"
			. " FROM boei_bygg LEFT OUTER JOIN"
			. " fm_location2 ON boei_bygg.objekt_id = fm_location2.loc1 AND boei_bygg.bygg_id = fm_location2.loc2"
			. " WHERE fm_location2.loc1 IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);
		$bygg_latin = array();
		while ($this->db->next_record())
		{
			$bygg_latin[] = array(
				'location_code'	 => $this->db->f('location_code'),
				'loc1'			 => $this->db->f('objekt_id'),
				'loc2'			 => $this->db->f('bygg_id'),
				'loc2_name'		 => $this->db->f('byggnavn'),
				'category'		 => 98
			);
		}

		$this->db->transaction_begin();

		$bygg_msg = array();
		foreach ($bygg_latin as $bygg)
		{

			$sql2 = "INSERT INTO fm_location2 (location_code, loc1, loc2, loc2_name,category) "
				. "VALUES (" . $this->db->validate_insert($bygg) . ")";

			$this->db->query($sql2, __LINE__, __FILE__);
			$this->db->query("INSERT INTO fm_locations (level, location_code, loc1) VALUES (2, '{$bygg['location_code']}', '{$bygg['loc1']}')", __LINE__, __FILE__);

			$bygg_msg[] = $bygg['location_code'];
		}

		$this->db->transaction_commit();

		$msg						 = count($bygg_latin) . ' Bygg er lagt til: ' . implode(",", $bygg_msg);
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function legg_til_seksjon_phpgw()
	{

		$sql = "SELECT boei_seksjon.objekt_id || '-' || boei_seksjon.bygg_id || '-' || boei_seksjon.seksjons_id AS location_code, boei_seksjon.objekt_id, boei_seksjon.bygg_id,"
			. " boei_seksjon.seksjons_id, boei_seksjon.beskrivelse"
			. " FROM boei_seksjon LEFT OUTER JOIN"
			. " fm_location3 ON boei_seksjon.objekt_id = fm_location3.loc1 AND boei_seksjon.bygg_id = fm_location3.loc2 AND "
			. " boei_seksjon.seksjons_id = fm_location3.loc3"
			. " WHERE fm_location3.loc1 IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);
		$seksjon_latin = array();
		while ($this->db->next_record())
		{
			$seksjon_latin[] = array(
				'location_code'	 => $this->db->f('location_code'),
				'loc1'			 => $this->db->f('objekt_id'),
				'loc2'			 => $this->db->f('bygg_id'),
				'loc3'			 => $this->db->f('seksjons_id'),
				'loc3_name'		 => $this->db->f('beskrivelse'),
				'category'		 => 98
			);
		}

		$this->db->transaction_begin();

		$seksjon_msg = array();
		foreach ($seksjon_latin as $seksjon)
		{
			$sql2 = "INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name, category) "
				. "VALUES (" . $this->db->validate_insert($seksjon) . ")";

			$this->db->query($sql2, __LINE__, __FILE__);
			$this->db->query("INSERT INTO fm_locations (level, location_code, loc1) VALUES (3, '{$seksjon['location_code']}', '{$seksjon['loc1']}')", __LINE__, __FILE__);

			$seksjon_msg[] = $seksjon['location_code'];
		}

		$this->db->transaction_commit();

		$msg						 = count($seksjon_latin) . ' Seksjon er lagt til: ' . implode(",", $seksjon_msg);
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function legg_til_leieobjekt_phpgw()
	{
		$this->db->query("SELECT * FROM boei_leieobjekt_tv_signal", __LINE__, __FILE__);

		$tv_signaler = array();
		while ($this->db->next_record())
		{
			$tv_signaler[$this->db->f('id')] = $this->db->f('navn');
		}


		$sql = "SELECT boei_leieobjekt.objekt_id || '-' || boei_leieobjekt.bygg_id || '-' || boei_leieobjekt.seksjons_id || '-' || boei_leieobjekt.leie_id AS location_code,"
			. " boei_leieobjekt.objekt_id, boei_leieobjekt.leie_id, boei_leieobjekt.bygg_id, boei_leieobjekt.seksjons_id,"
			. " boei_leieobjekt.formaal_id, boei_leieobjekt.gateadresse_id, boei_leieobjekt.gatenr, boei_leieobjekt.etasje, boei_leieobjekt.antallrom,"
			. " boei_leieobjekt.boareal, boei_leieobjekt.livslopsstd, boei_leieobjekt.heis, boei_leieobjekt.driftsstatus_id, boei_leieobjekt.leietaker_id,"
			. " boei_leieobjekt.beregnet_boa, boei_leieobjekt.flyttenr, boei_leieobjekt.tv_signal_id"
			. " FROM boei_leieobjekt LEFT OUTER JOIN"
			. " fm_location4 ON boei_leieobjekt.objekt_id = fm_location4.loc1 AND boei_leieobjekt.leie_id = fm_location4.loc4"
			. " WHERE fm_location4.loc1 IS NULL";


		$this->db->query($sql, __LINE__, __FILE__);

		$leieobjekt_latin = array();

		while ($this->db->next_record())
		{
			$leieobjekt_latin[] = array(
				'location_code'		 => $this->db->f('location_code'),
				'loc1'				 => $this->db->f('objekt_id'),
				'loc4'				 => $this->db->f('leie_id'),
				'loc2'				 => $this->db->f('bygg_id'),
				'loc3'				 => $this->db->f('seksjons_id'),
				'category'			 => $this->db->f('formaal_id'),
				'street_id'			 => $this->db->f('gateadresse_id'),
				'street_number'		 => $this->db->f('gatenr'),
				'etasje'			 => $this->db->f('etasje'),
				'antallrom'			 => $this->db->f('antallrom'),
				'boareal'			 => $this->db->f('boareal'),
				'livslopsstd'		 => $this->db->f('livslopsstd'),
				'heis'				 => $this->db->f('heis'),
				'driftsstatus_id'	 => $this->db->f('driftsstatus_id'),
				'tenant_id'			 => $this->db->f('leietaker_id'),
				'beregnet_boa'		 => $this->db->f('beregnet_boa'),
				'flyttenr'			 => $this->db->f('flyttenr'),
				'tv_signal'			 => $tv_signaler[$this->db->f('tv_signal_id')],
				'loc4_name'			 =>	$this->db->f('etasje'),
			);
		}

		$this->db->transaction_begin();

		$leieobjekt_msg = array();
		foreach ($leieobjekt_latin as $leieobjekt)
		{
			$sql2 = "INSERT INTO fm_location4 (location_code, loc1, loc4, loc2, loc3, category, street_id, street_number, etasje, antallrom, boareal, livslopsstd, heis, driftsstatus_id,
                      tenant_id, beregnet_boa, flyttenr, tv_signal, loc4_name)"
				. "VALUES (" . $this->db->validate_insert($leieobjekt) . ")";

			$this->db->query($sql2, __LINE__, __FILE__);
			$this->db->query("INSERT INTO fm_locations (level, location_code, loc1) VALUES (4, '{$leieobjekt['location_code']}', '{$leieobjekt['loc1']}')", __LINE__, __FILE__);

			$leieobjekt_msg[] = $leieobjekt['location_code'];
		}

		$this->db->transaction_commit();

		$msg						 = count($leieobjekt_latin) . ' Leieobjekt er lagt til: ' . implode(",", $leieobjekt_msg);
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function legg_til_zip_code_phpgw()
	{
		$sql = "SELECT DISTINCT boei_poststed.id, boei_poststed.navn"
			. " FROM fm_zip_code RIGHT OUTER JOIN"
			. " boei_poststed ON fm_zip_code.id = boei_poststed.id"
			. " WHERE fm_zip_code.id IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);

		$zip_codes = array();

		while ($this->db->next_record())
		{
			$zip_codes[] = array(
				'id'			 => $this->db->f('id'),
				'name'	 => $this->db->f('navn')
			);
		}

		$this->db->transaction_begin();

		$msg = array();
		foreach ($zip_codes as $zip_code)
		{
			$sql2 = "INSERT INTO fm_zip_code (id, name)"
				. "VALUES (" . $this->db->validate_insert($zip_code) . ")";

			$this->db->query($sql2, __LINE__, __FILE__);

			$msg[] = "[{$zip_code['id']}  '{$zip_code['name']}']";
		}

		$this->db->transaction_commit();

		$msg						 = count($zip_codes) . ' Postnr er lagt til: ' . implode(",", $msg);
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function legg_til_leietaker_phpgw()
	{
		$sql = " SELECT boei_leietaker.leietaker_id, boei_leietaker.fornavn, boei_leietaker.etternavn, boei_leietaker.kjonn_juridisk,"
			. " boei_leietaker.namssakstatusokonomi_id, boei_leietaker.namssakstatusdrift_id, boei_leietaker.obskode,boei_leietaker.ssn"
			. " FROM fm_tenant RIGHT OUTER JOIN"
			. " boei_leietaker ON fm_tenant.id = boei_leietaker.leietaker_id"
			. " WHERE fm_tenant.id IS NULL";

		$this->db->query($sql, __LINE__, __FILE__);

		$leietakere = array();

		while ($this->db->next_record())
		{
			$leietakere[] = array(
				'id'			 => $this->db->f('leietaker_id'),
				'first_name'	 => $this->db->f('fornavn'),
				'last_name'		 => $this->db->f('etternavn'),
				'category'		 => $this->db->f('kjonn_juridisk') + 1,
				'status_eco'	 => $this->db->f('namssakstatusokonomi_id'),
				'status_drift'	 => $this->db->f('namssakstatusdrift_id'),
				'obskode'		 => $this->db->f('obskode'),
				'contact_phone'	 => $this->db->f('telefon'),
				'ssn'			 => $this->db->f('ssn'),
				'entry_date'	 => time(),
				'owner_id'		 => 6
			);
		}

		$this->db->transaction_begin();

		$leietaker_msg = array();
		foreach ($leietakere as $leietaker)
		{
			$sql2 = "INSERT INTO fm_tenant (id, first_name, last_name, category, status_eco, status_drift, obskode, contact_phone, ssn, entry_date,owner_id)"
				. "VALUES (" . $this->db->validate_insert($leietaker) . ")";

			$this->db->query($sql2, __LINE__, __FILE__);

			$leietaker_msg[] = "[{$leietaker['last_name']}, '{$leietaker['first_name']}']";
		}

		$this->db->transaction_commit();

		$msg						 = count($leietakere) . ' Leietaker er lagt til: ' . implode(",", $leietaker_msg);
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function update_tenant_name()
	{
		$sql = "SELECT boei_leietaker.leietaker_id, boei_leietaker.fornavn, boei_leietaker.etternavn FROM boei_leietaker"
			. " JOIN fm_tenant ON boei_leietaker.leietaker_id = fm_tenant.id"
			. " WHERE hemmeligadresse = 0 AND (first_name != fornavn OR last_name != etternavn )";
		$this->db->query($sql, __LINE__, __FILE__);

		$i = 0;
		while ($this->db->next_record())
		{
			$sql2 = "UPDATE fm_tenant SET"
				. " first_name = '" . $this->db->f('fornavn') . "',"
				. " last_name = '" . $this->db->f('etternavn') . "'"
				. " WHERE id = " . (int)$this->db->f('leietaker_id');
			//_debug_array($sql2);
			$this->db2->query($sql2, __LINE__, __FILE__);
			$i++;
		}

		$sql = "SELECT boei_leietaker.leietaker_id, boei_leietaker.ssn FROM boei_leietaker"
			. " JOIN fm_tenant ON boei_leietaker.leietaker_id = fm_tenant.id"
			. " WHERE boei_leietaker.ssn IS NOT NULL AND fm_tenant.ssn IS NULL";
		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$sql2 = "UPDATE fm_tenant SET ssn = '" . $this->db->f('ssn') . "' WHERE id = " . (int)$this->db->f('leietaker_id');
			//_debug_array($sql2);
			$this->db2->query($sql2, __LINE__, __FILE__);
		}

		$msg						 = $i . ' Leietakere er oppdatert med navn';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function update_tenant_phone()
	{
		$sql = "SELECT leietaker_id, telefon FROM boei_leietaker"
			. " JOIN fm_tenant on boei_leietaker.leietaker_id = fm_tenant.id"
			. " WHERE contact_phone IS NULL AND boei_leietaker.telefon IS NOT NULL";
		$this->db->query($sql, __LINE__, __FILE__);
		$i = 0;
		while ($this->db->next_record())
		{
			$sql2 = "UPDATE fm_tenant SET"
				. " contact_phone = '" . $this->db->f('telefon') . "'"
				. " WHERE id = " . (int)$this->db->f('leietaker_id');
			//_debug_array($sql2);
			$this->db2->query($sql2, __LINE__, __FILE__);
			$i++;
		}

		$msg						 = $i . ' Leietakere er oppdatert med telefon';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function update_tenant_termination_date()
	{
		$sql = "SELECT boei_leietaker.leietaker_id, boei_leietaker.oppsagtdato FROM boei_leietaker"
			. " JOIN fm_tenant ON boei_leietaker.leietaker_id = fm_tenant.id"
			. " WHERE fm_tenant.oppsagtdato != boei_leietaker.oppsagtdato";
		$this->db->query($sql, __LINE__, __FILE__);

		$i = 0;
		while ($this->db->next_record())
		{
			$sql2 = "UPDATE fm_tenant SET"
				. " oppsagtdato = '" . $this->db->f('oppsagtdato') . "'"
				. " WHERE id = " . (int)$this->db->f('leietaker_id');
			//_debug_array($sql2);
			$this->db2->query($sql2, __LINE__, __FILE__);
			$i++;
		}

		$msg						 = $i . ' Leietakere er oppdatert med oppsagtdato';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}


	function update_hemmelig_adresse()
	{
		$sql = "SELECT DISTINCT boei_leietaker.leietaker_id as tenant_id, boei_leietaker.hemmeligadresse FROM boei_leietaker"
			. " WHERE hemmeligadresse IS NOT NULL AND hemmeligadresse = 1";

		$this->db->query($sql, __LINE__, __FILE__);

		$hemmeligadresser = array();
		while ($this->db->next_record())
		{
			$hemmeligadresser[] =  (int)$this->db->f('tenant_id');
		}

		if ($hemmeligadresser)
		{
			$sql2 = "UPDATE fm_tenant SET first_name = '******', last_name = '******'"
				. " WHERE id IN (" . implode(',', $hemmeligadresser) . ')';

			$this->db2->query($sql2, __LINE__, __FILE__);
		}
	}


	function update_obskode()
	{
		$sql = "SELECT DISTINCT boei_leietaker.leietaker_id as tenant_id, boei_leietaker.obskode FROM boei_leietaker"
			. " JOIN fm_location4 ON boei_leietaker.leietaker_id = fm_location4.tenant_id"
			. " WHERE fm_location4.tenant_id > 0 AND (boei_leietaker.obskode != fm_location4.obskode OR"
			. " (boei_leietaker.obskode IS NULL AND fm_location4.obskode IS NOT NULL) OR"
			. " (boei_leietaker.obskode IS NOT NULL AND fm_location4.obskode IS NULL))";

		$this->db->query($sql, __LINE__, __FILE__);

		$obskoder = array();
		while ($this->db->next_record())
		{
			$obskoder[] = array(
				'tenant_id'	 => (int)$this->db->f('tenant_id'),
				'obskode'	 => $this->db->f('obskode')
			);
		}

		foreach ($obskoder as $entry)
		{
			$sql2 = "UPDATE fm_location4 SET obskode = '{$entry['obskode']}'"
				. " WHERE tenant_id = {$entry['tenant_id']}";

			$this->db2->query($sql2, __LINE__, __FILE__);
		}

		//			$sql = "SELECT DISTINCT substring(location_code from 0 for 8) AS location_code, obskode"
		$sql = "SELECT DISTINCT location_code, obskode"
			. " FROM fm_location4 WHERE obskode IS NOT NULL AND LENGTH(obskode) > 0";

		$this->db->query($sql, __LINE__, __FILE__);
		$locations = array();

		while ($this->db->next_record())
		{
			$locations[] = $this->db->f('location_code');
		}

		if ($locations)
		{
			$now = time();
			$sql = "UPDATE fm_location_exception SET end_date = $now"
				. " WHERE category_text_id = 4"
				. " AND (end_date IS NULL OR end_date = 0 OR end_date > $now)"
				. " AND location_code NOT IN('" . implode("','", $locations) . "')";

			$this->db->query($sql, __LINE__, __FILE__);

			$sql = "SELECT DISTINCT location_code FROM fm_location_exception WHERE (end_date IS NULL OR end_date = 0) AND category_text_id = 4";
			$this->db->query($sql, __LINE__, __FILE__);

			$old_locations = array();

			while ($this->db->next_record())
			{
				$old_locations[] = $this->db->f('location_code');
			}

			$sql = "SELECT max(id) as id FROM fm_location_exception";
			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();
			$id = (int)$this->db->f('id');

			foreach ($locations as $location_code)
			{
				if (!in_array($location_code, $old_locations))
				{
					$id++;

					$sql = "INSERT INTO fm_location_exception ("
						. "id, location_code, severity_id, category_id, start_date, user_id, entry_date, modified_date, alert_vendor, category_text_id )"
						. " values ({$id}, '{$location_code}', 3, 5, {$now}, 6, {$now} , {$now}, 1, 4)";
					$this->db->query($sql, __LINE__, __FILE__);
				}
			}
		}

		$msg						 = count($obskoder) . ' OBSKoder er oppdatert';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function oppdater_leieobjekt()
	{
		$this->db->query("SELECT * FROM boei_leieobjekt_tv_signal", __LINE__, __FILE__);

		$tv_signaler = array();
		while ($this->db->next_record())
		{
			$tv_signaler[$this->db->f('id')] = $this->db->f('navn');
		}

		$sql = "SELECT boei_leieobjekt.objekt_id,boei_leieobjekt.leie_id,boei_leieobjekt.leietaker_id,"
			. " boareal, formaal_id, gateadresse_id, gatenr, etasje,driftsstatus_id, boei_leieobjekt.flyttenr, innflyttetdato, boei_leieobjekt.tv_signal_id"
			. " FROM  boei_leieobjekt"
			. " LEFT JOIN boei_reskontro ON boei_leieobjekt.objekt_id=boei_reskontro.objekt_id AND boei_leieobjekt.leie_id=boei_reskontro.leie_id"
			. " AND boei_leieobjekt.flyttenr=boei_reskontro.flyttenr AND boei_leieobjekt.leietaker_id=boei_reskontro.leietaker_id";

		$this->db->query($sql, __LINE__, __FILE__);

		$this->db->transaction_begin();


		$i = 0;
		while ($this->db->next_record())
		{
			$sql2 = " UPDATE  fm_location4 SET "
				. " tenant_id = '" . $this->db->f('leietaker_id') . "',"
				. " category = '" . $this->db->f('formaal_id') . "',"
				. " etasje = '" . $this->db->f('etasje') . "',"
				. " loc4_name = '" . $this->db->f('etasje') . "',"
				. " street_id = '" . $this->db->f('gateadresse_id') . "',"
				. " street_number = '" . $this->db->f('gatenr') . "',"
				. " driftsstatus_id = '" . $this->db->f('driftsstatus_id') . "',"
				. " boareal = '" . $this->db->f('boareal') . "',"
				. " flyttenr = '" . $this->db->f('flyttenr') . "',"
				. " innflyttetdato = '" . date("M d Y", strtotime($this->db->f('innflyttetdato'))) . "',"
				. " tv_signal = '" . $tv_signaler[$this->db->f('tv_signal_id')] . "'"
				. " WHERE  loc1 = '" . $this->db->f('objekt_id') . "'  AND  loc4= '" . $this->db->f('leie_id') . "'";

			$this->db2->query($sql2, __LINE__, __FILE__);
			$i++;
		}

		$this->db->transaction_commit();

		$msg						 = $i . ' Leieobjekt er oppdatert';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function oppdater_boa_objekt()
	{
		$metadata = $this->db->metadata('fm_location1');


		$sql = " SELECT boei_objekt.objekt_id,bydel_id,tjenestested,boei_objekt.navn,boei_objekt.eier_id, kostra_id, fm_owner_category.id as owner_type_id"
			. " FROM boei_objekt 
				JOIN fm_location1 ON boei_objekt.objekt_id = fm_location1.loc1
				JOIN fm_owner_category ON fm_location1.owner_id = fm_owner_category.id"
			. " WHERE boei_objekt.navn != fm_location1.loc1_name"
			. " OR  boei_objekt.bydel_id != fm_location1.part_of_town_id"
			. " OR  boei_objekt.eier_id != fm_location1.owner_id"
			. " OR  boei_objekt.tjenestested != fm_location1.kostra_id";
		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$tjenestested = (int)$this->db->f('tjenestested');
			$kostra_id = (int)$this->db->f('kostra_id');
			$loc1		  = $this->db->f('objekt_id');
			$owner_type_id 	  = (int)$this->db->f('owner_type_id');
			$navn = $this->db->f('navn');

			$mva = 75;
			if (in_array($tjenestested, array(26555)) || (in_array($owner_type_id,array(1, 2)) && $tjenestested == 26550))
			{
				$mva = 0;
			}

			if ($kostra_id != $tjenestested)
			{
				$this->alert_messages[] = "Objekt {$loc1} i Portico endret tjeneste fra {$kostra_id} til {$tjenestested}";

				$sql_history = "SELECT * FROM fm_location1 WHERE loc1 ='{$loc1}'";
				$this->db2->query($sql_history, __LINE__, __FILE__);
				$this->db2->next_record();

				$cols = array();
				$vals = array();
				foreach ($metadata as $column => $val)
				{
					$cols[] = $column;

					if (ctype_digit($this->db2->f($column)))
					{
						$vals[] = $this->db2->f($column);
					}
					else
					{
						$vals[] = $this->db2->db_addslashes($this->db2->f($column, true));
					}
				}

				$cols[]	 = 'exp_date';
				$vals[]	 = date($this->db2->datetime_format(), time());

				$cols	 = implode(",", $cols);
				$vals	 = $this->db2->validate_insert($vals);
				$this->db2->query("INSERT INTO fm_location1_history ($cols) VALUES ($vals)", __LINE__, __FILE__);
			}

			$sql2 = " UPDATE fm_location1 SET "
				. " loc1_name = '" . $navn . "',"
				. " part_of_town_id = " . (int)$this->db->f('bydel_id') . ","
				. " owner_id = " . (int)$this->db->f('eier_id') . ","
				. " mva = " . $mva . ","
				. " kostra_id = " . $tjenestested
				. " WHERE  loc1 = '" . $this->db->f('objekt_id') . "'";

			/*
				* Alle kostraid’er skal ha mva/AV-kode 75
				* Bortsett fra
				* 26550 som jeg ønsker varsel på,
				* og 26555 som ikke finnes enda, men som kanskje blir opprettet på innleieboliger.
				*/


			$this->db2->query($sql2, __LINE__, __FILE__);
		}

		$sql = " SELECT boei_objekt.objekt_id, boei_objekt.postnr_id"
			. " FROM boei_objekt JOIN fm_location1 ON boei_objekt.objekt_id = fm_location1.loc1"
			. " WHERE boei_objekt.postnr_id != fm_location1.zip_code OR fm_location1.zip_code IS NULL";
		$this->db->query($sql, __LINE__, __FILE__);

		while ($this->db->next_record())
		{
			$sql2 = " UPDATE fm_location1 SET "
				. " zip_code = '" . $this->db->f('postnr_id') . "'"
				. " WHERE  loc1 = '" . $this->db->f('objekt_id') . "'";
			$this->db2->query($sql2, __LINE__, __FILE__);
		}

		$sql = " SELECT sum(boei_leieobjekt.boareal) as sum_boa, count(leie_id) as ant_leieobjekt,"
			. " boei_objekt.objekt_id FROM  boei_objekt {$this->join} boei_leieobjekt ON boei_objekt.objekt_id = boei_leieobjekt.objekt_id"
			. " WHERE boei_leieobjekt.formaal_id NOT IN (99)"
			. " GROUP BY boei_objekt.objekt_id";

		$this->db->query($sql, __LINE__, __FILE__);

		//	$this->db->transaction_begin();

		$i = 0;
		while ($this->db->next_record())
		{
			$sql2 = " UPDATE fm_location1 SET "
				. " sum_boa = '" . $this->db->f('sum_boa') . "',"
				. " ant_leieobjekt = " . (int)$this->db->f('ant_leieobjekt')
				. " WHERE  loc1 = '" . $this->db->f('objekt_id') . "'";
			$this->db2->query($sql2, __LINE__, __FILE__);
			$i++;
		}
		//	$this->db->transaction_commit();

		$msg						 = $i . ' Objekt er oppdatert';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function oppdater_boa_bygg()
	{
		$sql = " SELECT sum(boei_leieobjekt.boareal) as sum_boa, count(leie_id) as ant_leieobjekt,"
			. " boei_bygg.objekt_id,boei_bygg.bygg_id , byggnavn  FROM  boei_bygg $this->join boei_leieobjekt "
			. " ON boei_bygg.objekt_id = boei_leieobjekt.objekt_id AND boei_bygg.bygg_id = boei_leieobjekt.bygg_id"
			. " WHERE boei_leieobjekt.formaal_id NOT IN (99)"
			. " GROUP BY boei_bygg.objekt_id,boei_bygg.bygg_id ,byggnavn";

		$this->db->query($sql, __LINE__, __FILE__);

		//	$this->db->transaction_begin();

		$i = 0;
		while ($this->db->next_record())
		{
			$sql2 = " UPDATE fm_location2 SET "
				. " loc2_name = '" . $this->db->f('byggnavn') . "',"
				. " sum_boa = '" . $this->db->f('sum_boa') . "',"
				. " ant_leieobjekt = '" . $this->db->f('ant_leieobjekt') . "'"
				. " WHERE  loc1 = '" . $this->db->f('objekt_id') . "'  AND  loc2= '" . $this->db->f('bygg_id') . "'";

			$this->db2->query($sql2, __LINE__, __FILE__);
			$i++;
		}
		//	$this->db->transaction_commit();

		$msg						 = $i . ' Bygg er oppdatert';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function oppdater_boa_del()
	{
		$sql = " SELECT sum(boei_leieobjekt.boareal) as sum_boa, count(leie_id) as ant_leieobjekt,"
			. " boei_seksjon.objekt_id,boei_seksjon.bygg_id,boei_seksjon.seksjons_id , beskrivelse   FROM  boei_seksjon $this->join boei_leieobjekt "
			. " ON boei_seksjon.objekt_id = boei_leieobjekt.objekt_id"
			. " AND boei_seksjon.bygg_id = boei_leieobjekt.bygg_id"
			. " AND boei_seksjon.seksjons_id = boei_leieobjekt.seksjons_id"
			. " WHERE boei_leieobjekt.formaal_id NOT IN (99)"
			. " GROUP BY boei_seksjon.objekt_id,boei_seksjon.bygg_id,boei_seksjon.seksjons_id,beskrivelse";

		$this->db->query($sql, __LINE__, __FILE__);

		$i = 0;

		//	$this->db->transaction_begin();

		while ($this->db->next_record())
		{
			$sql2 = "UPDATE fm_location3 SET "
				. " loc3_name = '" . $this->db->f('beskrivelse') . "',"
				. " sum_boa = '" . $this->db->f('sum_boa') . "',"
				. " ant_leieobjekt = '" . $this->db->f('ant_leieobjekt') . "'"
				. " WHERE  loc1 = '" . $this->db->f('objekt_id') . "'  AND  loc2= '" . $this->db->f('bygg_id') . "'  AND  loc3= '" . $this->db->f('seksjons_id') . "'";

			$this->db2->query($sql2, __LINE__, __FILE__);
			$i++;
		}
		//	$this->db->transaction_commit();

		$msg						 = $i . ' Seksjoner er oppdatert';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function oppdater_oppsagtdato()
	{
		$sql = "SELECT fm_tenant.id,boei_leietaker.oppsagtdato"
			. " FROM  fm_tenant LEFT OUTER JOIN"
			. " boei_leietaker ON fm_tenant.id = boei_leietaker.leietaker_id AND "
			. " fm_tenant.oppsagtdato = boei_leietaker.oppsagtdato"
			. " WHERE (boei_leietaker.leietaker_id IS NULL)";

		$this->db->query($sql, __LINE__, __FILE__);

		//		$this->db->transaction_begin();

		$i = 0;
		while ($this->db->next_record())
		{
			$sql2 = "UPDATE fm_tenant SET "
				. " oppsagtdato = '" . $this->db->f('oppsagtdato') . "'"
				. " WHERE  id = " . (int)$this->db->f('id');

			$this->db2->query($sql2, __LINE__, __FILE__);
			$i++;
		}
		//	$this->db->transaction_commit();

		$msg						 = $i . ' oppsagtdato er oppdatert';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function slett_feil_telefon()
	{
		$sql = "SELECT count(contact_phone) as ant_tlf from fm_tenant WHERE id > 99999 OR id = 0";

		$this->db->query($sql, __LINE__, __FILE__);

		$this->db->next_record();

		$ant_tlf = $this->db->f('ant_tlf');

		$sql = "UPDATE fm_tenant SET contact_phone = NULL WHERE id > 99999 OR id = 0";

		$this->db->query($sql, __LINE__, __FILE__);

		$msg						 = $ant_tlf . ' Telefon nr er slettet';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}

	function oppdater_namssakstatus_pr_leietaker()
	{

		$sql = "SELECT fm_tenant.id"
			. " FROM  fm_tenant LEFT OUTER JOIN"
			. " boei_leietaker ON fm_tenant.id = boei_leietaker.leietaker_id AND "
			. " fm_tenant.status_drift = boei_leietaker.namssakstatusdrift_id AND "
			. " fm_tenant.status_eco = boei_leietaker.namssakstatusokonomi_id"
			. " WHERE (boei_leietaker.leietaker_id IS NULL)";

		$this->db->query($sql, __LINE__, __FILE__);

		$this->db->transaction_begin();

		$leietaker = array();
		while ($this->db->next_record())
		{
			$leietaker[] = (int)$this->db->f('id');
		}

		$leietaker_oppdatert = array();
		for ($i = 0; $i < count($leietaker); $i++)
		{
			$sql = "SELECT namssakstatusokonomi_id, namssakstatusdrift_id"
				. " FROM  boei_leietaker"
				. " WHERE (boei_leietaker.leietaker_id = '" . $leietaker[$i] . "')";

			$this->db->query($sql, __LINE__, __FILE__);

			$this->db->next_record();
			$leietaker_oppdatert[] = array(
				'id'			 => (int)$leietaker[$i],
				'status_drift'	 => (int)$this->db->f('namssakstatusdrift_id'),
				'status_eco'	 => (int)$this->db->f('namssakstatusokonomi_id')
			);
		}

		for ($i = 0; $i < count($leietaker_oppdatert); $i++)
		{
			$sql = " UPDATE fm_tenant SET "
				. " status_eco = '" . $leietaker_oppdatert[$i]['status_eco'] . "',"
				. " status_drift = '" . $leietaker_oppdatert[$i]['status_drift'] . "'"
				. " WHERE  id = '" . $leietaker_oppdatert[$i]['id'] . "'";

			$this->db->query($sql, __LINE__, __FILE__);
		}

		$this->db->transaction_commit();

		$msg						 = $i . ' namssakstatus er oppdatert';
		$this->receipt['message'][]	 = array('msg' => $msg);
		$this->cron_log($msg);
	}
}
