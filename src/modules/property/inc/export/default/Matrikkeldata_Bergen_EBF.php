<?php

use App\Database\Db;
use App\Database\Db2;
use App\modules\phpgwapi\controllers\Locations;

class export_conv
{

	var $db;

	public function __construct()
	{
		$this->get_db();
	}

	public function overfor($download = 'on')
	{
		$buildings = $this->get_buildings();

		$name	 = array_keys($buildings[0]);
		$descr	 = array_keys($buildings[0]);

		CreateObject('property.bocommon')->download($buildings, $name, $descr);

		$phpgwapi_common = new \phpgwapi_common();
		$phpgwapi_common->phpgw_exit();
	}
	/* php ping function
		 */

	private function ping($host)
	{
		exec(sprintf('ping -c 1 -W 5 %s', escapeshellarg($host)), $res, $rval);
		return $rval === 0;
	}

	function get_db()
	{
		if ($this->db)
		{
			return $this->db;
		}

		$location_obj = new Locations();
		$config = CreateObject('admin.soconfig', $location_obj->get_id('property', '.admin'));

		$db_info = array(
			'db_host'	 => $config->config_data['matrikkelen']['host'], //'oradb36i.srv.bergenkom.no',
			'db_type'	 => 'oci8',
			'db_port'	 => $config->config_data['matrikkelen']['port'], //'21525',
			'db_name'	 => $config->config_data['matrikkelen']['db_name'], //'MATPROD',
			'db_user'	 => $config->config_data['matrikkelen']['user'], //'GIS_BRUKER',
			'db_pass'	 => $config->config_data['matrikkelen']['password'],
		);

		//			_debug_array($db_info);

		if (!$db_info['db_host'] || !$this->ping($db_info['db_host']))
		{
			$message = "Database server {$db_info['db_host']} is not accessible";
			echo $message;
			return false;
		}

		$port		 = $db_info['db_port'] ? $db_info['db_port'] : 1521;
		$_charset	 = ';charset=AL32UTF8';
		$dsn		 = "oci:dbname={$db_info['db_host']}:{$port}/{$db_info['db_name']}{$_charset}";

		try
		{
			$this->db = new Db2($dsn, $db_info['db_user'], $db_info['db_pass']);
		}
		catch (Exception $e)
		{
			$message = lang('unable_to_connect_to_database');
			echo $message;
			return false;
		}

		return $this->db;
	}

	function get_matrikkel_info(&$values)
	{
		$buildingTypes = array(
			111 => "Enebolig",
			112 => "Enebolig med hybelleilighet, sokkelleilighet o.l.",
			113 => "Våningshus",
			121 => "Tomannsbolig, vertikaldelt",
			122 => "Tomannsbolig, horisontaldelt",
			123 => "Våningshus, tomannsbolig, vertikaldelt",
			124 => "Våningshus, tomannsbolig, horisontaldelt",
			131 => "Rekkehus",
			133 => "Kjedehus inkl. atriumhus",
			135 => "Terrassehus",
			136 => "Andre småhus med 3 boliger eller flere",
			141 => "Store frittliggende boligbygg på 2 etasjer",
			142 => "Store frittliggende boligbygg på 3 og 4 etasjer",
			143 => "Store frittliggende boligbygg på 5 etasjer eller over",
			144 => "Store sammenbygde boligbygg på 2 etasjer",
			145 => "Store sammenbygde boligbygg på 3 og 4 etasjer",
			146 => "Store sammenbygde boligbygg på 5 etasjer og over",
			151 => "Bo- og servicesenter",
			152 => "Studenthjem/studentboliger",
			159 => "Annen bygning for bofellesskap",
			161 => "Fritidsbygning (hytter, sommerhus o.l.)",
			162 => "Helårsbolig benyttet som fritidsbolig",
			163 => "Våningshus benyttet som fritidsbolig",
			171 => "Seterhus, sel, rorbu o.l.",
			172 => "Skogs- og utmarkskoie, gamme",
			181 => "Garasje, uthus, anneks knyttet til bolig",
			182 => "Garasje, uthus, anneks knyttet til fritidsbolig",
			183 => "Naust, båthus, sjøbu",
			193 => "Boligbrakker",
			199 => "Annen boligbygning (f.eks. sekundærbolig reindrift)",
			211 => "Fabrikkbygning",
			212 => "Verkstedbygning",
			214 => "Bygning for renseanlegg",
			216 => "Bygning for vannforsyning, bl.a. pumpestasjon",
			219 => "Annen industribygning",
			221 => "Kraftstasjon (>15 000 kVA)",
			223 => "Transformatorstasjon (>10 000 kVA)",
			229 => "Annen energiforsyningsbygning",
			231 => "Lagerhall",
			232 => "Kjøle- og fryselager",
			233 => "Silobygning",
			239 => "Annen lagerbygning",
			241 => "Hus for dyr, fôrlager, strølager, frukt- og grønnsakslager, landbrukssilo, høy-/korntørke",
			243 => "Veksthus",
			244 => "Driftsbygning for fiske og fangst, inkl. oppdrettsanlegg",
			245 => "Naust/redskapshus for fiske",
			248 => "Annen fiskeri- og fangstbygning",
			249 => "Annen landbruksbygning",
			311 => "Kontor- og administrasjonsbygning, rådhus",
			312 => "Bankbygning, posthus",
			313 => "Mediebygning",
			319 => "Annen kontorbygning",
			321 => "Kjøpesenter, varehus",
			322 => "Butikkbygning",
			323 => "Bensinstasjon",
			329 => "Annen forretningsbygning",
			330 => "Messe- og kongressbygning",
			411 => "Ekspedisjonsbygning, flyterminal, kontrolltårn",
			412 => "Jernbane- og T-banestasjon",
			415 => "Godsterminal",
			416 => "Postterminal",
			419 => "Annen ekspedisjons- og terminalbygning",
			429 => "Telekommunikasjonsbygning",
			431 => "Parkeringshus",
			439 => "Annen garasje- hangarbygning",
			441 => "Trafikktilsynsbygning",
			449 => "Annen veg- og trafikktilsynsbygning",
			511 => "Hotellbygning",
			512 => "Motellbygning",
			519 => "Annen hotellbygning",
			521 => "Hospits, pensjonat",
			522 => "Vandrerhjem, feriehjem/-koloni, turisthytte",
			523 => "Appartement",
			524 => "Campinghytte/utleiehytte",
			529 => "Annen bygning for overnatting",
			531 => "Restaurantbygning, kafébygning",
			532 => "Sentralkjøkken, kantinebygning",
			533 => "Gatekjøkken, kioskbygning",
			539 => "Annen restaurantbygning",
			611 => "Lekepark",
			612 => "Barnehage",
			613 => "Barneskole",
			614 => "Ungdomsskole",
			615 => "Kombinert barne- og ungdomsskole",
			616 => "Videregående skole",
			619 => "Annen skolebygning",
			621 => "Universitets- og høgskolebygning med integrerte funksjoner, auditorium, lesesal o.a.",
			623 => "Laboratoriebygning",
			629 => "Annen universitets-, høgskole- og forskningsbygning",
			641 => "Museum, kunstgalleri",
			642 => "Bibliotek, mediatek",
			643 => "Zoologisk og botanisk hage",
			649 => "Annen museums- og bibliotekbygning",
			651 => "Idrettshall",
			652 => "Ishall",
			653 => "Svømmehall",
			654 => "Tribune og idrettsgarderobe",
			655 => "Helsestudio",
			659 => "Annen idrettsbygning",
			661 => "Kinobygning, teaterbygning, opera/konserthus",
			662 => "Samfunnshus, grendehus",
			663 => "Diskotek",
			669 => "Annet kulturhus",
			671 => "Kirke, kapell",
			672 => "Bedehus, menighetshus",
			673 => "Krematorium, gravkapell, bårehus",
			674 => "Synagoge, moské",
			675 => "Kloster",
			679 => "Annen bygning for religiøse aktiviteter",
			719 => "Sykehus",
			721 => "Sykehjem",
			722 => "Bo- og behandlingssenter, aldershjem",
			723 => "Rehabiliteringsinstitusjon, kurbad",
			729 => "Annet sykehjem",
			731 => "Klinikk, legekontor/-senter/-vakt",
			732 => "Helse- og sosialsenter, helsestasjon",
			739 => "Annen primærhelsebygning",
			819 => "Fengselsbygning",
			821 => "Politistasjon",
			822 => "Brannstasjon, ambulansestasjon",
			823 => "Fyrstasjon, losstasjon",
			824 => "Stasjon for radarovervåkning av fly- og/eller skipstrafikk",
			825 => "Tilfluktsrom/bunker",
			829 => "Annen beredskapsbygning",
			830 => "Monument",
			840 => "Offentlig toalett"
		);

		$sql = "SELECT DISTINCT MATRIKKELENHET.ID, GATE.GATENAVN, ADRESSE.HUSNR, ADRESSE.BOKSTAV,
			MATRIKKELENHET.CLASS as MATRIKKELENHET_CLASS, MATRIKKELENHET.ETABLERINGSDATO, MATRIKKELENHET.OPPGITTAREAL, BYGG.BYGNINGSNR, BYGG.CLASS as BYGG_CLASS, BYGG.BEBYGDAREAL, BYGG.ANTALLBOENHETER as BYGG_ANTALLBOENHETER, BYGG.BRUKSAREALTILBOLIG, BYGG.BRUKSAREALTILANNET, BYGG.BRUKSAREALTOTALT, BYGNINGSTYPEKODE
			FROM MATRIKKELENHET, BYGG, BRUKSENHET, GATE, ADRESSE
			WHERE MATRIKKELENHET.ID = BRUKSENHET.MATRIKKELENHETID
			AND BRUKSENHET.BYGGID = BYGG.ID
			AND BRUKSENHET.ADRESSEID = ADRESSE.ID
			AND GATE.ID = ADRESSE.GATEID
			AND BYGG.CLASS = 'Bygning'
			AND MATRIKKELENHET.UTGATT = 0";

		foreach ($values as &$value)
		{
			$bygningsnr = (int)$value['bygningsnr'];

			$sql = "SELECT DISTINCT
			-- BYGNINGSTATUSHISTORIKK.*,
			--MATRIKKELENHET.*,
			--BYGG.*,
				GATE.GATENAVN, ADRESSE.HUSNR, ADRESSE.BOKSTAV,
				BYGNINGSTATUSHISTORIKK.REGISTRERTDATO as DATO,
				MATRIKKELENHET.ID,
				MATRIKKELENHET.CLASS as MATRIKKELENHET_CLASS,
				MATRIKKELENHET.ETABLERINGSDATO,
				MATRIKKELENHET.OPPGITTAREAL,
				BYGG.BYGNINGSNR,
				BYGG.CLASS as BYGG_CLASS,
				BYGG.BEBYGDAREAL,
				BYGG.ANTALLBOENHETER as BYGG_ANTALLBOENHETER,
				BYGG.BRUKSAREALTILBOLIG as BYGG_BRUKSAREALTILBOLIG,
				BYGG.BRUKSAREALTILANNET as BYGG_BRUKSAREALTILANNET,
				BYGG.BRUKSAREALTOTALT as BYGG_BRUKSAREALTOTALT,
				BYGNINGSTYPEKODE,
				MATRIKKELENHET.KOMMUNEID,
				MATRIKKELENHET.GARDSNR,
				MATRIKKELENHET.BRUKSNR,
				MATRIKKELENHET.FESTENR,
				MATRIKKELENHET.SEKSJONSNR
				FROM MATRIKKELENHET, BYGG, BRUKSENHET, GATE, ADRESSE, BYGNINGSTATUSHISTORIKK
				WHERE MATRIKKELENHET.ID = BRUKSENHET.MATRIKKELENHETID
				AND BRUKSENHET.BYGGID = BYGG.ID
				AND BYGNINGSTATUSHISTORIKK.BYGGID = BYGG.ID
				AND BRUKSENHET.ADRESSEID = ADRESSE.ID
				AND GATE.ID = ADRESSE.GATEID
				AND BYGNINGSNR = {$bygningsnr}
				AND BYGG.CLASS = 'Bygning'
				AND MATRIKKELENHET.UTGATT = 0
				AND BYGNINGSTATUSHISTORIKK.BYGNINGSTATUSKODE IN ('FA', 'TB', 'MB')";

			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();
			{
				$debug = false;
				if ($debug)
				{
					$result = $this->db->Record;
					_debug_array($result);
					die();
				}
			}

			$value['Matrikkel_Adresse'] = $this->db->f('GATENAVN') . " " . $this->db->f('HUSNR') . " " . $this->db->f('BOKSTAV');
			$value['etableringsdato'] = $this->db->f('ETABLERINGSDATO');
			if (!$value['etableringsdato'])
			{
				$value['etableringsdato'] = $this->db->f('DATO');
			}
			$value['bruksareal_bolig']	 = $this->db->f('BYGG_BRUKSAREALTILBOLIG');
			$value['bruksareal_annet']	 = $this->db->f('BYGG_BRUKSAREALTILANNET');
			$value['bruksareal_totalt']	 = $this->db->f('BYGG_BRUKSAREALTOTALT');
			$value['antall_boenheter']	 = $this->db->f('BYGG_ANTALLBOENHETER');
			$value['kommune_id']		 = $this->db->f('KOMMUNEID');
			$value['gardsnr']			 = $this->db->f('GARDSNR');
			$value['bruksnr']			 = $this->db->f('BRUKSNR');
			$value['Bygningstypekode']	 = $this->db->f('BYGNINGSTYPEKODE');
			$value['BygningstypeTekst']	 = $buildingTypes[$value['Bygningstypekode']];
		}
	}

	function get_buildings()
	{
		$db = Db::getInstance();

		$sql = "SELECT DISTINCT bygningsnr,fm_location1.loc1 as objekt, loc1_name as navn,"
			. " fm_owner_category.descr as eiertype, sum(boareal) as leieareal, sameie_andeler, fm_location1.zip_code as postnr
			FROM fm_location4
			JOIN fm_location1 on fm_location4.loc1 = fm_location1.loc1
			JOIN fm_owner ON fm_owner.id = fm_location1.owner_id
			JOIN fm_owner_category ON fm_owner.category = fm_owner_category.id
			WHERE fm_location4.category NOT IN (99)
			AND bygningsnr IS NOT NULL
			GROUP BY bygningsnr, objekt, navn, eiertype, sameie_andeler
            ORDER BY bygningsnr";

		$db->query($sql, __LINE__, __FILE__);
		$buildings = array();
		while ($db->next_record())
		{
			$buildings[] = $db->Record;
		}

		$this->get_matrikkel_info($buildings);

		foreach ($buildings as &$building)
		{
			$sql = "SELECT DISTINCT fm_location4_category.descr as formaal FROM fm_location4 JOIN fm_location4_category ON fm_location4.category = fm_location4_category.id"
				. " WHERE bygningsnr = {$building['bygningsnr']}"
				. " AND fm_location4_category.id NOT IN (99)"
				. " ORDER BY fm_location4_category.descr";
			$db->query($sql, __LINE__, __FILE__);
			$categories = array();
			while ($db->next_record())
			{
				$categories[] = $db->f('formaal', true);
			}

			$building['formaal'] = implode(', ', $categories);


			$sql = "SELECT DISTINCT loc1, loc2, loc3  FROM fm_location4 WHERE bygningsnr = {$building['bygningsnr']} AND fm_location4_category.id NOT IN (99)";
			$db->query($sql, __LINE__, __FILE__);
			$location_codes = array();
			while ($db->next_record())
			{
				$location_codes[] = $db->f('loc1') . '-' . $db->f('loc2') . '-' . $db->f('loc3');
			}

			$building['innganger'] = count($location_codes);

			$maalepunkter = array();
			//				foreach ($location_codes as $location_code)
			{
				$sql = "SELECT DISTINCT location_code, json_representation->>'maalepunkt_id' as maalepunkt_id FROM fm_bim_item "
					. " WHERE fm_bim_item.location_id = 25" // el-anlegg
					. " AND fm_bim_item.location_code like '{$building['objekt']}%'"
					. " AND (json_representation->>'maalepunkt_id' IS NOT NULL )"
					. " AND (json_representation->>'category' = '2' )"; // felles

				$db->query($sql, __LINE__, __FILE__);

				while ($db->next_record())
				{
					$maalepunkter[] = $db->f('maalepunkt_id');
				}
			}

			$building['maalepkunkt_id'] = implode(', ', $maalepunkter);


			//sprinkling:

			$sprinkler_lokasjoner = array();

			$sql = "SELECT DISTINCT location_code FROM fm_bim_item "
				. " WHERE fm_bim_item.location_id = 35" // sprinkling
				. " AND fm_bim_item.location_code like '{$building['objekt']}%'";

			$db->query($sql, __LINE__, __FILE__);

			while ($db->next_record())
			{
				$sprinkler_lokasjoner[] = $db->f('location_code');
			}

			$building['sprinkler'] = implode(', ', $sprinkler_lokasjoner);
		}

		return $buildings;
	}
}
