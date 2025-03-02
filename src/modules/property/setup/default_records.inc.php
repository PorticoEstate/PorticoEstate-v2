<?php

/**
 * phpGroupWare - property: a Facilities Management System.
 *
 * @author Sigurd Nes <sigurdne@online.no>
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @internal Development of this application was funded by http://www.bergen.kommune.no/bbb_/ekstern/
 * @package property
 * @subpackage setup
 * @version $Id$
 */
/**
 * Description
 * @package property
 */

use App\modules\phpgwapi\services\Settings;
use App\Database\Db;
use App\modules\phpgwapi\controllers\Locations;

$db = Db::getInstance();
$location_obj = new Locations();

$serverSettings = Settings::getInstance()->get('server');

$db->query("SELECT app_id FROM phpgw_applications WHERE app_name = 'property'");
$db->next_record();
$app_id = $db->f('app_id');

#
#  phpgw_locations
#


function p_setup_translate($key)
{
	$serverSettings = Settings::getInstance()->get('server');

	$lang = $serverSettings['default_lang'] == 'no' ? 'no' : 'en';

	static $translations = array();
	if (!$translations)
	{
		$translations['no'] = array(
			'Admin entity'	=> 'Admin entity',
			'Admin location' => 'Admin location',
			'Location'		=> 'Lokasjon',
			'Property'		=> 'Eiendom',
			'Building'		=> 'Bygning',
			'Entrance'		=> 'Inngang',
			'Apartment'		=> 'Leilighet',
			'custom queries' => 'Tilpassede spørringer',
			'Demand -> Workorder' => 'Behov -> bestilling',
			'Workorder'		=> 'Bestilling',
			'Transfer Workorder' => 'Overfør bestilling',
			'Request'	=> 'Behov',
			'Helpdesk'	=> 'Meldinger',
			'Helpdesk ad hock order' => 'Melding::forenklet bestilling',
			'Helpdesk External user' => 'Melding::extern bruker',
			'Invoice'	=> 'Faktura',
			'Documents'	=> 'Dokumenter',
			'Drawing'	=> 'Tegning',
			'Budget account' => 'Budsjettkonto(art)',
			'Tenant claim'	=> 'Leietakerkrav',
			'Budget'		=> 'Budsjett',
			'Obligations'	=> 'Forpliktelser',
			'Basis for high level lazy budgeting' => 'basis for forenklet budsjettering',
			'ifc integration' => 'IFC integrasjon',
			'Agreement'		=> 'Avtale',
			'Service agreement'		=> 'Serviceavtale',
			'Service agreement detail'		=> 'Serviceavtale::detaljer',
			'Tenant'		=> 'Leietaker',
			'Owner'		=> 'Eier',
			'Vendor'		=> 'Leverandør',
			'A dimension for accounting'		=> 'Dimensjon for kontering',
			'Scheduled events'		=> 'Planlagte hendelser',
			'Condition Survey'		=> 'Tilstandsanalyse',
			'Org unit'		=> 'Organisasjonsenhet',
			'Categories'		=> 'Kategorier',
			'Generic report'		=> 'Generisk rapport',
			'location exception'		=> 'Obsvarsel på lokasjon',
			'District'	=>	'Område',
			'Part of town'	=> 'Bydel',
			'Highest'	=>	'Høyest',
			'Lowest'	=>	'Lavest',
			'male'	=>	'Mann',
			'female'	=>	'Kvinne',
			'organization'	=>	'Organisasjon',
			'SOMETHING'	=>	'Kategori 1',
			'not active'	=>	'Utgått',
			'Owner category'	=> 'Eierkategori',
			'Admin location'	=> 'Administrer lokasjon',
			'Admin booking'	=> 'Administrer booking',
			'Building number'	=> 'Bygningsnummer'
		);
	}

	if (empty($translations[$lang][$key]))
	{
		return $key;
	}
	else
	{
		return $translations[$lang][$key];
	}
}

$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant) VALUES ({$app_id}, '.', 'Top', 1)");
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.admin', 'Admin')");
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.admin.entity', 'Admin entity')");
$translation = p_setup_translate('Admin location');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.admin.location', '{$translation}')");
$translation = p_setup_translate('Location');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.location', '{$translation}')");
$translation = p_setup_translate('Property');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.location.1', '{$translation}')");
$translation = p_setup_translate('Building');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.location.2', '{$translation}')");
$translation = p_setup_translate('Entrance');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.location.3', '{$translation}')");
$translation = p_setup_translate('Apartment');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.location.4', '$translation')");
$translation = p_setup_translate('custom queries');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.custom', '{$translation}')");
$translation = p_setup_translate('Demand -> Workorder');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib, c_attrib_table) VALUES ({$app_id}, '.project', '{$translation}', 1, 1, 1, 'fm_project')");
$translation = p_setup_translate('Workorder');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib, c_attrib_table) VALUES ({$app_id}, '.project.workorder', '{$translation}', 1, 1 ,1, 'fm_workorder')");
$translation = p_setup_translate('Transfer Workorder');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_c_function) VALUES ({$app_id}, '.project.workorder.transfer', '{$translation}', 1)");
$translation = p_setup_translate('Request');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib, c_attrib_table) VALUES ({$app_id}, '.project.request', '{$translation}', 1, 1 ,1, 'fm_request')");
$translation = p_setup_translate('Helpdesk');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib, c_attrib_table) VALUES ({$app_id}, '.ticket', '{$translation}', 1, 1, 1, 'fm_tts_tickets')");
$translation = p_setup_translate('Helpdesk ad hock order');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_c_attrib, c_attrib_table) VALUES ({$app_id}, '.ticket.order', '{$translation}', 1, 'fm_tts_tickets')");
$translation = p_setup_translate('Helpdesk External user');
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.ticket.external', '{$translation}')");
$translation = p_setup_translate('Invoice');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.invoice', '{$translation}')");
$translation = p_setup_translate('Documents');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.document', '{$translation}')");
$translation = p_setup_translate('Drawing');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.drawing', 'Drawing')");
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant) VALUES ({$app_id}, '.entity.1', 'Equipment', 1)");
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.entity.1.1', 'Meter', 1, 1, 1, 'fm_entity_1_1')");
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.entity.1.2', 'Elevator', 1, 1, 1, 'fm_entity_1_2')");
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.entity.1.3', 'Fire alarm central', 1, 1, 1, 'fm_entity_1_3')");
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant) VALUES ({$app_id}, '.entity.2', 'Report', 1)");
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.entity.2.1', 'Report type 1', 1, 1, 1, 'fm_entity_2_1')");
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_function, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.entity.2.2', 'Report type 2', 1, 1, 1, 'fm_entity_2_2')");
$translation = p_setup_translate('Budget account');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.b_account', '$translation')");
$translation = p_setup_translate('Tenant claim');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.tenant_claim', '{$translation}')");
$translation = p_setup_translate('Budget');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.budget', '{$translation}')");
$translation = p_setup_translate('Obligations');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.budget.obligations', '{$translation}')");
$translation = p_setup_translate('Basis for high level lazy budgeting');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.budget.basis', '{$translation}')");
$translation = p_setup_translate('ifc integration');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr) VALUES ({$app_id}, '.ifc', '{$translation}')");

$translation = p_setup_translate('Agreement');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.agreement', '{$translation}',1,'fm_agreement')");
$translation = p_setup_translate('Service agreement');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.s_agreement', '{$translation}',1,'fm_s_agreement')");
$translation = p_setup_translate('Service agreement detail');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.s_agreement.detail', '{$translation}',1,'fm_s_agreement_detail')");
//	$translation = p_setup_translate('Rental agreement');
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.r_agreement', 'Rental agreement',1,'fm_r_agreement')");
//	$translation = p_setup_translate('Rental agreement detail');
//	$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.r_agreement.detail', 'Rental agreement detail',1,'fm_r_agreement_detail')");
$translation = p_setup_translate('Tenant');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.tenant', '{$translation}',1,1,'fm_tenant')");
$translation = p_setup_translate('Owner');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.owner', '{$translation}',1,1,'fm_owner')");
$translation = p_setup_translate('Vendor');
$db->query("INSERT INTO phpgw_locations (app_id, name, descr, allow_grant, allow_c_attrib,c_attrib_table) VALUES ({$app_id}, '.vendor', '{$translation}',1,1,'fm_vendor')");

$translation = p_setup_translate('Admin booking');
$location_obj->add('.admin_booking', $translation, 'property');

$location_obj->add('.jasper', 'JasperReport', 'property', $allow_grant = true);

$translation = p_setup_translate('A dimension for accounting');
$location_obj->add('.invoice.dimb', $translation, 'property');
$translation = p_setup_translate('Scheduled events');
$location_obj->add('.scheduled_events', $translation, 'property');
$translation = p_setup_translate('Condition Survey');
$location_obj->add('.project.condition_survey', $translation, 'property', true, 'fm_condition_survey', true);
$translation = p_setup_translate('Org unit');
$location_obj->add('.org_unit', $translation, 'property', false, 'fm_org_unit', false, true);

$locations = array(
	'property.ticket' => '.ticket',
	'property.project' => '.project',
	'property.document' => '.document',
	'fm_vendor' => '.vendor',
	'fm_tenant' => '.tenant',
	'fm_owner' => '.owner'
);

$translation = p_setup_translate('Categories');
foreach ($locations as $dummy => $location)
{
	$location_obj->add("{$location}.category", $translation, 'property');
}

$translation = p_setup_translate('Generic report');
$location_obj->add('.report', $translation, 'property', $allow_grant = true);

$translation = p_setup_translate('location exception');
$location_obj->add('.location.exception', $translation, 'property');

$db->query("DELETE from phpgw_config WHERE config_app='property'");
//	$db->query("INSERT INTO phpgw_config (config_app, config_name, config_value) VALUES ('property','meter_table', 'fm_entity_1_1')");

#
#fm_district
#
$translation = p_setup_translate('District');

$db->query("INSERT INTO fm_district (id, descr) VALUES ('1', '{$translation} 1')");
$db->query("INSERT INTO fm_district (id, descr) VALUES ('2', '{$translation} 2')");
$db->query("INSERT INTO fm_district (id, descr) VALUES ('3', '{$translation} 3')");

#
#fm_part_of_town
#
$translation = p_setup_translate('Part of town');

$db->query("INSERT INTO fm_part_of_town (name, district_id) VALUES ('{$translation} 1','1')");


#
#fm_owner_category
#

$translation = p_setup_translate('Owner category');
$db->query("INSERT INTO fm_owner_category (id, descr) VALUES ('1', '{$translation} 1')");

#
#fm_owner
#
$translation = p_setup_translate('Owner');
$db->query("INSERT INTO fm_owner (id, abid, org_name, category) VALUES (1, 1, '{$translation} 1',1)");

#
#fm_owner_attribute
#
$location_id = $location_obj->get_id('property', '.owner');

$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable, search) VALUES ($location_id, 1, 1, 'abid', 'Contact', 'Contakt person', NULL, 'AB', 1, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable, search) VALUES ($location_id, 2, 1, 'org_name', 'Name', 'The name of the owner', NULL, 'V', 2, 50, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable, search) VALUES ($location_id, 3, 1, 'remark', 'remark', 'remark', NULL, 'T', 3, NULL, NULL, NULL, 'True', NULL)");

#
# Dumping data for table fm_location1_category
#

$translation = p_setup_translate('SOMETHING');
$db->query("INSERT INTO fm_location1_category (id, descr) VALUES (1, '{$translation}')");
$translation = p_setup_translate('not active');
$db->query("INSERT INTO fm_location1_category (id, descr) VALUES (99, '{$translation}')");
#
# Dumping data for table fm_location2_category
#

$translation = p_setup_translate('SOMETHING');
$db->query("INSERT INTO fm_location2_category (id, descr) VALUES (1, '{$translation}')");
$translation = p_setup_translate('not active');
$db->query("INSERT INTO fm_location2_category (id, descr) VALUES (99, '{$translation}')");
#
# Dumping data for table fm_location3_category
#

$translation = p_setup_translate('SOMETHING');
$db->query("INSERT INTO fm_location3_category (id, descr) VALUES (1, '{$translation}')");
$translation = p_setup_translate('not active');
$db->query("INSERT INTO fm_location3_category (id, descr) VALUES (99, '{$translation}')");
#
# Dumping data for table fm_location4_category
#

$translation = p_setup_translate('SOMETHING');
$db->query("INSERT INTO fm_location4_category (id, descr) VALUES (1, '{$translation}')");
$translation = p_setup_translate('not active');
$db->query("INSERT INTO fm_location4_category (id, descr) VALUES (99, '{$translation}')");


#
#fm_location1
#

$db->query("INSERT INTO fm_location1 ( location_code , loc1 , loc1_name , part_of_town_id , entry_date , category ,status, user_id , owner_id , remark )VALUES ('5000', '5000', 'Location name', '1', NULL , '1','1', '6', '1', 'remark')");

#
#fm_location2
#
$db->query("INSERT INTO fm_streetaddress (id, descr) VALUES (1, 'street name 1')");

$db->query("INSERT INTO fm_location2 ( location_code , loc1 , loc2 , loc2_name , entry_date , category, street_id, street_number, status, user_id , remark )VALUES ('5000-01', '5000', '01', 'Location name', NULL , '1', 1, '1A', '1', '6', 'remark')");

$db->query("INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name, entry_date, category, user_id, status, remark) VALUES ('5000-01-01', '5000', '01', '01', 'entrance name1', 1087745654, 1, 6, 1, NULL)");
$db->query("INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name, entry_date, category, user_id, status, remark) VALUES ('5000-01-02', '5000', '01', '02', 'entrance name2', 1087745654, 1, 6, 1, NULL)");
$db->query("INSERT INTO fm_location3 (location_code, loc1, loc2, loc3, loc3_name, entry_date, category, user_id, status, remark) VALUES ('5000-01-03', '5000', '01', '03', 'entrance name3', 1087745654, 1, 6, 1, NULL)");

$db->query("INSERT INTO fm_location4 (location_code, loc1, loc2, loc3, loc4, loc4_name, entry_date, category, user_id, tenant_id, status, remark) VALUES ('5000-01-01-001', '5000', '01', '01', '001', 'apartment name1', 1087745753, 1, 6, 1, 1, NULL)");
$db->query("INSERT INTO fm_location4 (location_code, loc1, loc2, loc3, loc4, loc4_name, entry_date, category, user_id, tenant_id, status, remark) VALUES ('5000-01-01-002', '5000', '01', '01', '002', 'apartment name2', 1087745753, 1, 6, 2, 1, NULL)");
$db->query("INSERT INTO fm_location4 (location_code, loc1, loc2, loc3, loc4, loc4_name, entry_date, category, user_id, tenant_id, status, remark) VALUES ('5000-01-02-001', '5000', '01', '02', '001', 'apartment name3', 1087745753, 1, 6, 3, 1, NULL)");
$db->query("INSERT INTO fm_location4 (location_code, loc1, loc2, loc3, loc4, loc4_name, entry_date, category, user_id, tenant_id, status, remark) VALUES ('5000-01-02-002', '5000', '01', '02', '002', 'apartment name4', 1087745753, 1, 6, 4, 1, NULL)");
$db->query("INSERT INTO fm_location4 (location_code, loc1, loc2, loc3, loc4, loc4_name, entry_date, category, user_id, tenant_id, status, remark) VALUES ('5000-01-03-001', '5000', '01', '03', '001', 'apartment name5', 1087745753, 1, 6, 5, 1, NULL)");
$db->query("INSERT INTO fm_location4 (location_code, loc1, loc2, loc3, loc4, loc4_name, entry_date, category, user_id, tenant_id, status, remark) VALUES ('5000-01-03-002', '5000', '01', '03', '002', 'apartment name6', 1087745753, 1, 6, 6, 1, NULL)");

#
# fm_branch
#

$db->query("INSERT INTO fm_branch (id, num, descr) VALUES (1, 'rør', 'rørlegger')");
$db->query("INSERT INTO fm_branch (id, num, descr) VALUES (2, 'maler', 'maler')");
$db->query("INSERT INTO fm_branch (id, num, descr) VALUES (3, 'tomrer', 'Tømrer')");
$db->query("INSERT INTO fm_branch (id, num, descr) VALUES (4, 'renhold', 'Renhold')");

#
# fm_workorder_status
#

$db->query("INSERT INTO fm_workorder_status (id, descr) VALUES ('active', 'Active')");
$db->query("INSERT INTO fm_workorder_status (id, descr) VALUES ('ordered', 'Ordered')");
$db->query("INSERT INTO fm_workorder_status (id, descr) VALUES ('request', 'Request')");
$db->query("INSERT INTO fm_workorder_status (id, descr) VALUES ('closed', 'Closed')");

#
# fm_request_status
#

$db->query("INSERT INTO fm_request_status (id, descr) VALUES ('request', 'Request')");
$db->query("INSERT INTO fm_request_status (id, descr) VALUES ('canceled', 'Canceled')");
$db->query("INSERT INTO fm_request_status (id, descr) VALUES ('closed', 'avsluttet')");


#
# fm_request_condition_type
#

$db->query("INSERT INTO fm_request_condition_type (id, name, priority_key) VALUES (1, 'safety', 10)");
$db->query("INSERT INTO fm_request_condition_type (id, name, priority_key) VALUES (2, 'aesthetics', 2)");
$db->query("INSERT INTO fm_request_condition_type (id, name, priority_key) VALUES (3, 'indoor climate', 5)");
$db->query("INSERT INTO fm_request_condition_type (id, name, priority_key) VALUES (4, 'consequential damage', 5)");
$db->query("INSERT INTO fm_request_condition_type (id, name, priority_key) VALUES (5, 'user gratification', 4)");
$db->query("INSERT INTO fm_request_condition_type (id, name, priority_key) VALUES (6, 'residential environment', 6)");


#
# fm_document_category
#

$db->query("DELETE FROM phpgw_categories WHERE cat_appname = 'property'");
$serverSettings['account_repository'] = isset($serverSettings['account_repository']) ? $serverSettings['account_repository'] : '';
$accounts = createObject('phpgwapi.accounts');

$cats = CreateObject('phpgwapi.categories', -1, 'property', '.document');

$cats->add(
	array(
		'name' => 'Picture',
		'descr' => 'Picture',
		'parent' => 'none',
		'old_parent' => 0,
		'access' => 'public'
	)
);

$cats->add(
	array(
		'name' => 'Report',
		'descr' => 'Report',
		'parent' => 'none',
		'old_parent' => 0,
		'access' => 'public'
	)
);

$cats->add(
	array(
		'name' => 'Instruction',
		'descr' => 'Instruction',
		'parent' => 'none',
		'old_parent' => 0,
		'access' => 'public'
	)
);


#
# fm_document_status
#

$db->query("INSERT INTO fm_document_status (id, descr) VALUES ('draft', 'Draft')");
$db->query("INSERT INTO fm_document_status (id, descr) VALUES ('final', 'Final')");
$db->query("INSERT INTO fm_document_status (id, descr) VALUES ('obsolete', 'obsolete')");


#
# fm_standard_unit
#

$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (1, 'mm', 'Millimeter')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (2, 'm', 'Meter')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (3, 'm2', 'Square meters')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (4, 'm3', 'Cubic meters')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (5, 'km', 'Kilometre')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (6, 'Stk', 'Stk')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (7, 'kg', 'Kilogram')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (8, 'tonn', 'Tonn')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (9, 'h', 'Hours')");
$db->query("INSERT INTO fm_standard_unit (id, name, descr) VALUES (10, 'RS', 'Round Sum')");


#
#  fm_agreement_status
#
$db->query("INSERT INTO fm_agreement_status (id, descr) VALUES ('closed', 'Closed')");
$db->query("INSERT INTO fm_agreement_status (id, descr) VALUES ('active', 'Active agreement')");
$db->query("INSERT INTO fm_agreement_status (id, descr) VALUES ('planning', 'Planning')");


#
#  fm_ns3420
#
$db->query("INSERT INTO fm_ns3420 (id, num, tekst1, enhet) VALUES (1, 'D00', 'RIGGING, KLARGJØRING', 'RS')");
$db->query("INSERT INTO fm_ns3420 (id, num, tekst1, enhet,tekst2) VALUES (2, 'D20', 'RIGGING, ANLEGGSTOMT', 'RS','TILFØRSEL- OG FORSYNINGSANLEGG')");

#
# Data-ark for tabell fm_idgenerator
#

$db->query("INSERT INTO fm_idgenerator (name, value, descr) VALUES ('Bilagsnummer', '2003100000', 'Bilagsnummer')", __LINE__, __FILE__);
$db->query("INSERT INTO fm_idgenerator (name, value, descr) VALUES ('bilagsnr_ut', 0, 'Bilagsnummer utgående')", __LINE__, __FILE__);
$db->query("INSERT INTO fm_idgenerator (name, value, descr) VALUES ('Ecobatchid', '1', 'Ecobatchid')", __LINE__, __FILE__);
$db->query("INSERT INTO fm_idgenerator (name, value, descr) VALUES ('project', '1000', 'project')", __LINE__, __FILE__);
$db->query("INSERT INTO fm_idgenerator (name, value, descr) VALUES ('Statuslog', '1', 'Statuslog')", __LINE__, __FILE__);
$db->query("INSERT INTO fm_idgenerator (name, value, descr) VALUES ('workorder', '1000', 'workorder')", __LINE__, __FILE__);
$db->query("INSERT INTO fm_idgenerator (name, value, descr) VALUES ('request', '1000', 'request')", __LINE__, __FILE__);

#
# Dumping data for table fm_location_config
#

$db->query("INSERT INTO fm_location_config (location_type, column_name, input_text, lookup_form, f_key, ref_to_category, query_value, reference_table, reference_id, datatype, precision_, scale, default_value, nullable) VALUES (4, 'tenant_id', NULL, 1, 1, NULL, 0, 'fm_tenant', 'id', 'int', 4, NULL, NULL, 'True')");
$db->query("INSERT INTO fm_location_config (location_type, column_name, input_text, lookup_form, f_key, ref_to_category, query_value, reference_table, reference_id, datatype, precision_, scale, default_value, nullable) VALUES (2, 'street_id', NULL, 1, 1, NULL, 1, 'fm_streetaddress', 'id', 'int', 4, NULL, NULL, 'True')");
$db->query("INSERT INTO fm_location_config (location_type, column_name, input_text, lookup_form, f_key, ref_to_category, query_value, reference_table, reference_id, datatype, precision_, scale, default_value, nullable) VALUES (1, 'owner_id', NULL, NULL, 1, 1, NULL, 'fm_owner', 'id', 'int', 4, NULL, NULL, 'True')");
$db->query("INSERT INTO fm_location_config (location_type, column_name, input_text, lookup_form, f_key, ref_to_category, query_value, reference_table, reference_id, datatype, precision_, scale, default_value, nullable) VALUES (1, 'part_of_town_id', NULL, NULL, 1, NULL, NULL, 'fm_part_of_town', 'id', 'int', 4, NULL, NULL, 'True')");

#
# Dumping data for table fm_tenant_category
#
$translation = p_setup_translate('male');
$db->query("INSERT INTO fm_tenant_category (id, descr) VALUES (1, 'male')");
$translation = p_setup_translate('female');
$db->query("INSERT INTO fm_tenant_category (id, descr) VALUES (2, 'female')");
$translation = p_setup_translate('organization');
$db->query("INSERT INTO fm_tenant_category (id, descr) VALUES (3, 'organization')");

#
# Dumping data for table phpgw_cust_attribute
#
$location_id = $location_obj->get_id('property', '.tenant');

$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, search, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable) VALUES ($location_id, 1, 1, 1, 'first_name', 'First name', 'First name', NULL, 'V', 1, 50, NULL, NULL, 'True')");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, search, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable) VALUES ($location_id, 2, 1, 1, 'last_name', 'Last name', 'Last name', NULL, 'V', 2, 50, NULL, NULL, 'True')");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, search, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable) VALUES ($location_id, 3, 1, 1, 'contact_phone', 'contact phone', 'contact phone', NULL, 'V', 3, 20, NULL, NULL, 'True')");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, search, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable) VALUES ($location_id, 4, NULL, NULL, 'phpgw_account_id', 'Mapped User', 'Mapped User', NULL, 'user', 4, 4, NULL, NULL, 'True')");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, search, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable) VALUES ($location_id, 5, NULL, NULL, 'account_lid', 'User Name', 'User name for login', NULL, 'V', 5, 25, NULL, NULL, 'True')");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, search, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable) VALUES ($location_id, 6, NULL, NULL, 'account_pwd', 'Password', 'Users Password', NULL, 'pwd', 6, 32, NULL, NULL, 'True')");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, search, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable) VALUES ($location_id, 7, NULL, NULL, 'account_status', 'account status', 'account status', NULL, 'LB', 7, NULL, NULL, NULL, 'True')");

#
# Dumping data for table fm_tenant_choice
#

$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 7, 1, 'Active')");
$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 7, 2, 'Banned')");

#
# Dumping data for table fm_tenant
#

$db->query("INSERT INTO fm_tenant (id, first_name, last_name, category) VALUES (1, 'First name1', 'Last name1', 1)");
$db->query("INSERT INTO fm_tenant (id, first_name, last_name, category) VALUES (2, 'First name2', 'Last name2', 2)");
$db->query("INSERT INTO fm_tenant (id, first_name, last_name, category) VALUES (3, 'First name3', 'Last name3', 1)");
$db->query("INSERT INTO fm_tenant (id, first_name, last_name, category) VALUES (4, 'First name4', 'Last name4', 2)");
$db->query("INSERT INTO fm_tenant (id, first_name, last_name, category) VALUES (5, 'First name5', 'Last name5', 1)");
$db->query("INSERT INTO fm_tenant (id, first_name, last_name, category) VALUES (6, 'First name6', 'Last name6', 2)");

#
# Dumping data for table fm_ecoart
#

$db->query("INSERT INTO fm_ecoart (id, descr) VALUES (1, 'faktura')");
$db->query("INSERT INTO fm_ecoart (id, descr) VALUES (2, 'kreditnota')");


#
# Dumping data for table fm_ecobilag_category
#

$db->query("INSERT INTO fm_ecobilag_category (id, descr) VALUES (1, 'Drift, vedlikehold')");
$db->query("INSERT INTO fm_ecobilag_category (id, descr) VALUES (2, 'Prosjekt, Kontrakt')");
$db->query("INSERT INTO fm_ecobilag_category (id, descr) VALUES (3, 'Prosjekt, Tillegg')");
$db->query("INSERT INTO fm_ecobilag_category (id, descr) VALUES (4, 'Prosjekt, LP-stign')");
$db->query("INSERT INTO fm_ecobilag_category (id, descr) VALUES (5, 'Administrasjon')");

#
# Dumping data for table fm_ecomva
#

$db->query("INSERT INTO fm_ecomva (id, descr) VALUES (2, 'Mva 2')");
$db->query("INSERT INTO fm_ecomva (id, descr) VALUES (1, 'Mva 1')");
$db->query("INSERT INTO fm_ecomva (id, descr) VALUES (0, 'ingen')");
$db->query("INSERT INTO fm_ecomva (id, descr) VALUES (3, 'Mva 3')");
$db->query("INSERT INTO fm_ecomva (id, descr) VALUES (4, 'Mva 4')");
$db->query("INSERT INTO fm_ecomva (id, descr) VALUES (5, 'Mva 5')");

#
# Dumping data for table fm_entity
#

//	$location_id = $location_obj->get_id('property', '.entity.1');
//	$db->query("INSERT INTO fm_entity (location_id, id, name, descr, location_form, documentation) VALUES ({$location_id}, 1, 'Equipment', 'equipment', 1, 1)");
////$db->query("INSERT INTO fm_entity (id, name, descr, location_form, documentation, lookup_entity) VALUES (2, 'Report', 'report', 1, NULL, 'a:1:{i:0;s:1:"1";}')");
//	$location_id = $location_obj->get_id('property', '.entity.2');
//	$db->query("INSERT INTO fm_entity (location_id, id, name, descr, location_form, documentation, lookup_entity) VALUES ({$location_id}, 2, 'Report', 'report', 1, NULL, '')");

#
# Dumping data for table fm_entity_category
#
#
# Dumping data for table fm_entity_attribute
#
//	$location_id = $location_obj->get_id('property', '.entity.1.1');
//	$db->query("INSERT INTO fm_entity_category (location_id, entity_id, id, name, descr, prefix, lookup_tenant, tracking, location_level) VALUES ({$location_id}, 1, 1, 'Meter', 'Meter', NULL, NULL, NULL, 3)");
//
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 1, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 2, 'category', 'Category', 'Category statustext', 'LB', NULL, 2, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 3, 'ext_system_id', 'Ext system id', 'External system id', 'V', NULL, 3, NULL, 12, NULL, NULL, 'False')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 4, 'maaler_nr', 'Ext meter id', 'External meter id', 'V', NULL, 4, NULL, 12, NULL, NULL, 'False')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 5, 'remark', 'Remark', 'Remark status text', 'T', NULL, 5, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 1, 'status 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 2, 'status 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 2, 1, 'Tenant power meter')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 2, 2, 'Joint power meter')");
//
//	$location_id = $location_obj->get_id('property', '.entity.1.2');
//	$db->query("INSERT INTO fm_entity_category (location_id, entity_id, id, name, descr, prefix, lookup_tenant, tracking, location_level) VALUES ({$location_id}, 1, 2, 'Elevator', 'Elevator', 'E', NULL, NULL, 3)");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 1, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 2, 'attribute1', 'Attribute 1', 'Attribute 1 statustext', 'V', NULL, 2, NULL, 12, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 3, 'attribute2', 'Attribute 2', 'Attribute 2 status text', 'D', NULL, 3, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 4, 'attribute3', 'Attribute 3', 'Attribute 3 status text', 'R', NULL, 4, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 5, 'attribute4', 'Attribute 4', 'Attribute 4 statustext', 'CH', NULL, 5, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 6, 'attribute5', 'Attribute 5', 'Attribute 5 statustext', 'AB', NULL, 6, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 1, 'status 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 2, 'status 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 4, 1, 'choice 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 4, 2, 'choice 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 5, 1, 'choice 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 5, 2, 'choice 2')");
//
//	$location_id = $location_obj->get_id('property', '.entity.1.3');
//	$db->query("INSERT INTO fm_entity_category (location_id, entity_id, id, name, descr, prefix, lookup_tenant, tracking, location_level) VALUES ({$location_id}, 1, 3, 'Fire alarm central', 'Fire alarm central', 'F', NULL, NULL, 3)");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 1, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 2, 'attribute1', 'Attribute 1', 'Attribute 1 statustext', 'V', NULL, 2, NULL, 12, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 3, 'attribute2', 'Attribute 2', 'Attribute 2 status text', 'D', NULL, 3, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 4, 'attribute3', 'Attribute 3', 'Attribute 3 status text', 'R', NULL, 4, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 5, 'attribute4', 'Attribute 4', 'Attribute 4 statustext', 'CH', NULL, 5, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 6, 'attribute5', 'Attribute 5', 'Attribute 5 statustext', 'AB', NULL, 6, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 1, 'status 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 2, 'status 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 4, 1, 'choice 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 4, 2, 'choice 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 5, 1, 'choice 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 5, 2, 'choice 2')");
//
//	$location_id = $location_obj->get_id('property', '.entity.2.1');
//	$db->query("INSERT INTO fm_entity_category (location_id, entity_id, id, name, descr, prefix, lookup_tenant, tracking, location_level) VALUES ({$location_id}, 2, 1, 'Report type 1', 'Report type 1', 'RA', 1, 1, 4)");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 1, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 2, 'attribute1', 'Attribute 1', 'Attribute 1 statustext', 'V', NULL, 2, NULL, 12, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 3, 'attribute2', 'Attribute 2', 'Attribute 2 status text', 'D', NULL, 3, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 4, 'attribute3', 'Attribute 3', 'Attribute 3 status text', 'R', NULL, 4, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 5, 'attribute4', 'Attribute 4', 'Attribute 4 statustext', 'CH', NULL, 5, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 6, 'attribute5', 'Attribute 5', 'Attribute 5 statustext', 'AB', NULL, 6, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 1, 'status 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 2, 'status 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 4, 1, 'choice 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 4, 2, 'choice 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 5, 1, 'choice 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 5, 2, 'choice 2')");
//
//	$location_id = $location_obj->get_id('property', '.entity.2.2');
//	$db->query("INSERT INTO fm_entity_category (location_id, entity_id, id, name, descr, prefix, lookup_tenant, tracking, location_level) VALUES ({$location_id}, 2, 2, 'Report type 2', 'Report type 2', 'RB', 1, 1, 4)");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 1, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 2, 'attribute1', 'Attribute 1', 'Attribute 1 statustext', 'V', NULL, 2, NULL, 12, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 3, 'attribute2', 'Attribute 2', 'Attribute 2 status text', 'D', NULL, 3, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 4, 'attribute3', 'Attribute 3', 'Attribute 3 status text', 'R', NULL, 4, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 5, 'attribute4', 'Attribute 4', 'Attribute 4 statustext', 'CH', NULL, 5, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable) VALUES ($location_id, 6, 'attribute5', 'Attribute 5', 'Attribute 5 statustext', 'AB', NULL, 6, NULL, NULL, NULL, NULL, 'True')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 1, 'status 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 1, 2, 'status 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 4, 1, 'choice 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 4, 2, 'choice 2')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 5, 1, 'choice 1')");
//	$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 5, 2, 'choice 2')");
//
//
//#
//# Dumping data for table fm_entity_lookup
//#
//
//	$db->query("INSERT INTO fm_entity_lookup (entity_id, location, type) VALUES (1, 'project', 'lookup')");
//	$db->query("INSERT INTO fm_entity_lookup (entity_id, location, type) VALUES (1, 'ticket', 'lookup')");
//	$db->query("INSERT INTO fm_entity_lookup (entity_id, location, type) VALUES (2, 'request', 'start')");
//	$db->query("INSERT INTO fm_entity_lookup (entity_id, location, type) VALUES (2, 'ticket', 'start')");
//

#
# Dumping data for table fm_custom
#

$db->query("INSERT INTO fm_custom (id, name, sql_text) VALUES (1, 'test query', 'select * from phpgw_accounts')");

#
# Dumping data for table fm_custom_cols
#

$db->query("INSERT INTO fm_custom_cols (custom_id, id, name, descr, sorting) VALUES (1, 1, 'account_id', 'ID', 1)");
$db->query("INSERT INTO fm_custom_cols (custom_id, id, name, descr, sorting) VALUES (1, 2, 'account_lid', 'Lid', 2)");
$db->query("INSERT INTO fm_custom_cols (custom_id, id, name, descr, sorting) VALUES (1, 3, 'account_firstname', 'First Name', 3)");
$db->query("INSERT INTO fm_custom_cols (custom_id, id, name, descr, sorting) VALUES (1, 4, 'account_lastname', 'Last Name', 4)");


#
# Dumping data for table fm_vendor_attribute
#
$location_id = $location_obj->get_id('property', '.vendor');
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable, search) VALUES ($location_id, 1, 1, 'org_name', 'Name', 'The Name of the vendor', NULL, 'V', 1, 50, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable, search) VALUES ($location_id, 2, 1, 'contact_phone', 'Contact phone', 'Contact phone', NULL, 'V', 2, 20, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, list, column_name, input_text, statustext, size, datatype, attrib_sort, precision_, scale, default_value, nullable, search) VALUES ($location_id, 3, 1, 'email', 'email', 'email', NULL, 'email', 3, 64, NULL, NULL, 'True', 1)");


$db->query("INSERT INTO fm_vendor_category (id, descr) VALUES (1, 'kateogory 1')");
$db->query("INSERT INTO fm_vendor (id, org_name, email, contact_phone, category) VALUES (1, 'Demo vendor', 'demo@vendor.org', '5555555', 1)");


#
# Data for table fm_location_type
#

$location_naming[1]['name'] = strtolower(p_setup_translate('Property'));
$location_naming[1]['descr'] = p_setup_translate('Property');
$location_naming[2]['name'] = strtolower(p_setup_translate('Building'));
$location_naming[2]['descr'] = p_setup_translate('Building');
$location_naming[3]['name'] = strtolower(p_setup_translate('Entrance'));
$location_naming[3]['descr'] = p_setup_translate('Entrance');
$location_naming[4]['name'] = strtolower(p_setup_translate('Apartment'));
$location_naming[4]['descr'] = p_setup_translate('Apartment');

for ($location_type = 1; $location_type < 5; $location_type++)
{
	$default_attrib['id'][] = 1;
	$default_attrib['column_name'][] = 'location_code';
	$default_attrib['type'][] = 'V';
	$default_attrib['precision'][] = 4 * $location_type;
	$default_attrib['nullable'][] = 'False';
	$default_attrib['input_text'][] = 'location_code';
	$default_attrib['statustext'][] = 'location_code';

	$default_attrib['id'][] = 2;
	$default_attrib['column_name'][] = 'loc' . $location_type . '_name';
	$default_attrib['type'][] = 'V';
	$default_attrib['precision'][] = 50;
	$default_attrib['nullable'][] = 'True';
	$default_attrib['input_text'][] = 'loc' . $location_type . '_name';
	$default_attrib['statustext'][] = 'loc' . $location_type . '_name';

	$default_attrib['id'][] = 3;
	$default_attrib['column_name'][] = 'entry_date';
	$default_attrib['type'][] = 'I';
	$default_attrib['precision'][] = 4;
	$default_attrib['nullable'][] = 'True';
	$default_attrib['input_text'][] = 'entry_date';
	$default_attrib['statustext'][] = 'entry_date';

	$default_attrib['id'][] = 4;
	$default_attrib['column_name'][] = 'category';
	$default_attrib['type'][] = 'I';
	$default_attrib['precision'][] = 4;
	$default_attrib['nullable'][] = 'False';
	$default_attrib['input_text'][] = 'category';
	$default_attrib['statustext'][] = 'category';

	$default_attrib['id'][] = 5;
	$default_attrib['column_name'][] = 'user_id';
	$default_attrib['type'][] = 'I';
	$default_attrib['precision'][] = 4;
	$default_attrib['nullable'][] = 'False';
	$default_attrib['input_text'][] = 'user_id';
	$default_attrib['statustext'][] = 'user_id';

	$default_attrib['id'][] = 6;
	$default_attrib['column_name'][] = 'modified_by';
	$default_attrib['type'][] = 'user';
	$default_attrib['precision'][] = 4;
	$default_attrib['nullable'][] = 'true';
	$default_attrib['input_text'][] = 'modified_by';
	$default_attrib['statustext'][] = 'modified_by';

	$default_attrib['id'][] = 7;
	$default_attrib['column_name'][] = 'modified_on';
	$default_attrib['type'][] = 'DT';
	$default_attrib['precision'][] = 8;
	$default_attrib['nullable'][] = 'true';
	$default_attrib['input_text'][] = 'modified_on';
	$default_attrib['statustext'][] = 'modified_on';

	$pk = array();
	for ($i = 1; $i < $location_type + 1; $i++)
	{
		$pk[$i - 1] = 'loc' . $i;

		$default_attrib['id'][] = $i + 7;
		$default_attrib['column_name'][] = 'loc' . $i;
		$default_attrib['type'][] = 'V';
		$default_attrib['precision'][] = 4;
		$default_attrib['nullable'][] = 'False';
		$default_attrib['input_text'][] = 'loc' . $i;
		$default_attrib['statustext'][] = 'loc' . $i;
	}

	/*
		  if($location_type>1)
		  {
		  $fk_table='fm_location'. ($location_type-1);

		  for ($i=1; $i<$standard['id']; $i++)
		  {
		  $fk['loc' . $i]	= $fk_table . '.loc' . $i;
		  }
		  }
		 */
	$ix = array('location_code');

	$db->query("INSERT INTO fm_location_type (id,name,descr,pk,ix) "
		. "VALUES ($location_type,'"
		. $location_naming[$location_type]['name'] . "','"
		. $location_naming[$location_type]['descr'] . "','"
		. implode(',', $pk) . "','"
		. implode(',', $ix) . "')");

	$db->query("UPDATE fm_location_type set list_info = 'a:1:{i:1;s:1:\"1\";}' WHERE id = 1");
	$db->query("UPDATE fm_location_type set list_info = 'a:2:{i:1;s:1:\"1\";i:2;s:1:\"2\";}', list_address = 1 WHERE id = 2");
	$db->query("UPDATE fm_location_type set list_info = 'a:3:{i:1;s:1:\"1\";i:2;s:1:\"2\";i:3;s:1:\"3\";}', list_address = 1 WHERE id = 3");
	$db->query("UPDATE fm_location_type set list_info = 'a:1:{i:1;s:1:\"1\";}', list_address = 1  WHERE id = 4");

	$location_id = $location_obj->get_id('property', ".location.{$location_type}");

	for ($i = 0; $i < count($default_attrib['id']); $i++)
	{
		$db->query("INSERT INTO phpgw_cust_attribute (location_id, id,column_name,datatype,precision_,input_text,statustext,nullable,custom)"
			. " VALUES ("
			. $location_id . ','
			. $default_attrib['id'][$i] . ",'"
			. $default_attrib['column_name'][$i] . "','"
			. $default_attrib['type'][$i] . "',"
			. $default_attrib['precision'][$i] . ",'"
			. $default_attrib['input_text'][$i] . "','"
			. $default_attrib['statustext'][$i] . "','"
			. $default_attrib['nullable'][$i] . "',NULL)");
	}

	unset($pk);
	unset($ix);
	unset($default_attrib);
}

#
# Dumping data for table fm_location_attrib
#
$location_id = $location_obj->get_id('property', '.location.1');
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 10, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 11, 'remark', 'Remark', 'Remark', 'T', NULL, 2, NULL, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 12, 'mva', 'mva', 'Status', 'I', NULL, 3, NULL, 4, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 13, 'kostra_id', 'kostra_id', 'kostra_id', 'I', NULL, 4, NULL, 4, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 14, 'part_of_town_id', 'part_of_town_id', 'part_of_town_id', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 15, 'owner_id', 'owner_id', 'owner_id', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 16, 'change_type', 'change_type', 'change_type', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 17, 'rental_area', 'Rental area', 'Rental area', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 18, 'area_gross', 'Gross area', 'Sum of the areas included within the outside face of the exterior walls of a building.', 'N', NULL, 6, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 19, 'area_net', 'Net area', 'The wall-to-wall floor area of a room.', 'N', NULL, 7, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 20, 'area_usable', 'Usable area', 'generally measured from paint to paint inside the permanent walls and to the middle of partitions separating rooms', 'N', NULL, 8, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 21, 'delivery_address', 'Delivery address', 'Delivery address', 'T', NULL, 9, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 22, 'zip_code', 'Zip code', 'Zip code', 'V', NULL, 10, NULL, 4, NULL, NULL, 'True', 1)");

$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 10, 1, 'OK')");
$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 10, 2, 'Not OK')");


$location_id = $location_obj->get_id('property', '.location.2');
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 11, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 12, 'remark', 'Remark', 'Remark', 'T', NULL, 2, NULL, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 13, 'change_type', 'change_type', 'change_type', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 14, 'rental_area', 'Rental area', 'Rental area', 'N', NULL, 3, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 15, 'area_gross', 'Gross area', 'Sum of the areas included within the outside face of the exterior walls of a building.', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 16, 'area_net', 'Net area', 'The wall-to-wall floor area of a room.', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 17, 'area_usable', 'Usable area', 'generally measured from paint to paint inside the permanent walls and to the middle of partitions separating rooms', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 11, 1, 'OK')");
$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 11, 2, 'Not OK')");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 18, 'street_id', 'street_id', 'street_id', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 19, 'street_number', 'street_number', 'street_number', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$translation = p_setup_translate('Building number');

$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 20, 'building_number', '{$translation}', '{$translation}', 'I', NULL, NULL, NULL, 8, NULL, NULL, 'True', 1)");

$location_id = $location_obj->get_id('property', '.location.3');
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 12, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 13, 'remark', 'Remark', 'Remark', 'T', NULL, 2, NULL, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 14, 'change_type', 'change_type', 'change_type', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 15, 'rental_area', 'Rental area', 'Rental area', 'N', NULL, 3, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 16, 'area_gross', 'Gross area', 'Sum of the areas included within the outside face of the exterior walls of a building.', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 17, 'area_net', 'Net area', 'The wall-to-wall floor area of a room.', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 18, 'area_usable', 'Usable area', 'generally measured from paint to paint inside the permanent walls and to the middle of partitions separating rooms', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 12, 1, 'OK')");
$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 12, 2, 'Not OK')");


$location_id = $location_obj->get_id('property', '.location.4');
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 13, 'status', 'Status', 'Status', 'LB', NULL, 1, NULL, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 14, 'remark', 'Remark', 'Remark', 'T', NULL, 2, NULL, NULL, NULL, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 15, 'tenant_id', 'tenant_id', 'tenant_id', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, size, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 16, 'change_type', 'change_type', 'change_type', 'I', NULL, NULL, NULL, 4, NULL, NULL, 'True', NULL)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 17, 'rental_area', 'Rental area', 'Rental area', 'N', NULL, 4, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 18, 'area_gross', 'Gross area', 'Sum of the areas included within the outside face of the exterior walls of a building.', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 19, 'area_net', 'Net area', 'The wall-to-wall floor area of a room.', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_attribute (location_id, id, column_name, input_text, statustext, datatype, list, attrib_sort, precision_, scale, default_value, nullable,custom) VALUES ($location_id, 20, 'area_usable', 'Usable area', 'generally measured from paint to paint inside the permanent walls and to the middle of partitions separating rooms', 'N', NULL, 5, 20, 2, NULL, 'True', 1)");
$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 13, 1, 'OK')");
$db->query("INSERT INTO phpgw_cust_choice (location_id, attrib_id, id, value) VALUES ($location_id, 13, 2, 'Not OK')");


$db->query("INSERT INTO fm_action_pending_category (num, name, descr) VALUES ('approval', 'Approval', 'Please approve the item requested')");
$db->query("INSERT INTO fm_action_pending_category (num, name, descr) VALUES ('remind', 'Remind', 'This is a reminder of task assigned')");
$db->query("INSERT INTO fm_action_pending_category (num, name, descr) VALUES ('accept_delivery', 'Accept delivery', 'Please accept delivery on this item')");

// Admin get full access
$aclobj =createObject('phpgwapi.acl');;
$aclobj->enable_inheritance = true;
$admin_group = $accounts->name2id('admin');
if ($admin_group) // check if admin has been defined yet
{
	$aclobj->set_account_id($admin_group, true);
	$aclobj->add('property', '.', 31);
	$aclobj->add('property', 'run', 1);
	$aclobj->save_repository();
}

$db->query("INSERT INTO fm_jasper_input_type (name, descr) VALUES ('integer', 'Integer')");
$db->query("INSERT INTO fm_jasper_input_type (name, descr) VALUES ('float', 'Float')");
$db->query("INSERT INTO fm_jasper_input_type (name, descr) VALUES ('text', 'Text')");
$db->query("INSERT INTO fm_jasper_input_type (name, descr) VALUES ('date', 'Date')");
$db->query("INSERT INTO fm_jasper_input_type (name, descr) VALUES ('timestamp', 'timestamp')");
$db->query("INSERT INTO fm_jasper_input_type (name, descr) VALUES ('AB', 'Address book')");
$db->query("INSERT INTO fm_jasper_input_type (name, descr) VALUES ('VENDOR', 'Vendor')");
$db->query("INSERT INTO fm_jasper_input_type (name, descr) VALUES ('user', 'system user')");

$db->query("INSERT INTO fm_jasper_format_type (id) VALUES ('PDF')");
$db->query("INSERT INTO fm_jasper_format_type (id) VALUES ('CSV')");
$db->query("INSERT INTO fm_jasper_format_type (id) VALUES ('XLS')");
$db->query("INSERT INTO fm_jasper_format_type (id) VALUES ('XHTML')");
$db->query("INSERT INTO fm_jasper_format_type (id) VALUES ('DOCX')");

/**
 * FIXME
 * Fails at adodb::MetaColumns() for MSSQL
 * - and mess up the transaction for mysql
 */

if (!in_array($serverSettings['db_type'], array('mysql', 'mssql', 'mssqlnative')))
{
	$solocation = createObject('property.solocation');
	$solocation->update_location();
}
else
{
	echo "<li><b>Run the \"Update location\" from within the administration->property after installation</b></li>";
}

$custom_config = CreateObject('admin.soconfig', $location_obj->get_id('property', '.invoice'));

// common
$receipt_section_common = $custom_config->add_section(
	array(
		'name' => 'common',
		'descr' => 'common invoice config'
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_common['section_id'],
		'input_type' => 'text',
		'name' => 'host',
		'descr' => 'Host',
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_common['section_id'],
		'input_type' => 'text',
		'name' => 'user',
		'descr' => 'User',
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_common['section_id'],
		'input_type' => 'password',
		'name' => 'password',
		'descr' => 'Password',
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_common['section_id'],
		'input_type' => 'listbox',
		'name' => 'method',
		'descr' => 'Export / import method',
		'choice' => array('local', 'ftp', 'ssh'),
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_common['section_id'],
		'attrib_id' => $receipt['attrib_id'],
		'input_type' => 'listbox',
		'name' => 'invoice_approval',
		'descr' => 'Number of persons required to approve for payment',
		'choice' => array(1, 2),
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_common['section_id'],
		'input_type' => 'text',
		'name' => 'baseurl_invoice',
		'descr' => 'baseurl on remote server for image of invoice',
	)
);

// import:
$receipt_section_import = $custom_config->add_section(
	array(
		'name' => 'import',
		'descr' => 'import invoice config'
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_import['section_id'],
		'input_type' => 'text',
		'name' => 'local_path',
		'descr' => 'path on local sever to store imported files',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_import['section_id'],
		'input_type' => 'text',
		'name' => 'budget_responsible',
		'descr' => 'default initials if responsible can not be found',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_import['section_id'],
		'input_type' => 'text',
		'name' => 'remote_basedir',
		'descr' => 'basedir on remote server',
	)
);

//export
$receipt_section_export = $custom_config->add_section(
	array(
		'name' => 'export',
		'descr' => 'Invoice export'
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_export['section_id'],
		'input_type' => 'text',
		'name' => 'cleanup_old',
		'descr' => 'Overføre manuelt registrerte fakturaer rett til historikk'
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_export['section_id'],
		'input_type' => 'date',
		'name' => 'dato_aarsavslutning',
		'descr' => "Dato for årsavslutning: overført pr. desember foregående år"
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_export['section_id'],
		'input_type' => 'text',
		'name' => 'path',
		'descr' => 'path on local sever to store exported files',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_export['section_id'],
		'input_type' => 'text',
		'name' => 'pre_path',
		'descr' => 'path on local sever to store exported files for pre approved vouchers',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id' => $receipt_section_export['section_id'],
		'input_type' => 'text',
		'name' => 'remote_basedir',
		'descr' => 'basedir on remote server to receive files',
	)
);

$sql = 'CREATE VIEW fm_open_workorder_view AS'
	. ' SELECT fm_workorder.id, fm_workorder.project_id, fm_workorder_status.descr FROM fm_workorder'
	. ' JOIN fm_workorder_status ON fm_workorder.status = fm_workorder_status.id WHERE fm_workorder_status.delivered IS NULL AND fm_workorder_status.closed IS NULL';
$db->query($sql, __LINE__, __FILE__);


$sql = 'CREATE VIEW fm_ecobilag_sum_view AS'
	. ' SELECT DISTINCT bilagsnr, sum(godkjentbelop) AS approved_amount, sum(belop) AS amount FROM fm_ecobilag GROUP BY bilagsnr';

if ($serverSettings['db_type'] == 'postgres')
{
	$sql .= " ORDER BY bilagsnr ASC";
}

$db->query($sql, __LINE__, __FILE__);


$sql = 'CREATE VIEW fm_orders_pending_cost_view AS'
	. ' SELECT fm_ecobilag.pmwrkord_code AS order_id, sum(fm_ecobilag.godkjentbelop) AS pending_cost FROM fm_ecobilag GROUP BY fm_ecobilag.pmwrkord_code';

$db->query($sql, __LINE__, __FILE__);

$sql = 'CREATE VIEW fm_orders_actual_cost_view AS'
	. ' SELECT fm_ecobilagoverf.pmwrkord_code AS order_id, sum(fm_ecobilagoverf.godkjentbelop) AS actual_cost FROM fm_ecobilagoverf  GROUP BY fm_ecobilagoverf.pmwrkord_code';

$db->query($sql, __LINE__, __FILE__);

switch ($serverSettings['db_type'])
{
	case 'sqlsrv':
	case 'mssqlnative':
	case 'postgres':
	case 'mysql':
		$sql = 'CREATE VIEW fm_orders_paid_or_pending_view AS
				SELECT orders_paid_or_pending.order_id,
				   orders_paid_or_pending.periode,
				   orders_paid_or_pending.amount,
				   orders_paid_or_pending.periodization,
				   orders_paid_or_pending.periodization_start,
				   orders_paid_or_pending.mvakode
				  FROM ( SELECT fm_ecobilagoverf.pmwrkord_code AS order_id,
						   fm_ecobilagoverf.periode,
						   sum(fm_ecobilagoverf.godkjentbelop) AS amount,
						   fm_ecobilagoverf.periodization,
						   fm_ecobilagoverf.periodization_start,
						   fm_ecobilagoverf.mvakode
						  FROM fm_ecobilagoverf
						 GROUP BY fm_ecobilagoverf.pmwrkord_code, fm_ecobilagoverf.periode, fm_ecobilagoverf.periodization, fm_ecobilagoverf.periodization_start,fm_ecobilagoverf.mvakode
					   UNION ALL
						SELECT fm_ecobilag.pmwrkord_code AS order_id,
						   fm_ecobilag.periode,
						   sum(fm_ecobilag.godkjentbelop) AS amount,
						   fm_ecobilag.periodization,
						   fm_ecobilag.periodization_start,
						   fm_ecobilag.mvakode
						  FROM fm_ecobilag
						 GROUP BY fm_ecobilag.pmwrkord_code, fm_ecobilag.periode, fm_ecobilag.periodization, fm_ecobilag.periodization_start, fm_ecobilag.mvakode) orders_paid_or_pending';

		if ($serverSettings['db_type'] == 'postgres')
		{
			$sql .= " ORDER BY orders_paid_or_pending.periode, orders_paid_or_pending.order_id";
		}
		$db->query($sql, __LINE__, __FILE__);
		break;
	default:
		//do nothing for now
}

$sql = 'CREATE VIEW fm_project_budget_year_from_order_view AS'
	. ' SELECT DISTINCT fm_workorder.project_id, fm_workorder_budget.year'
	. ' FROM fm_workorder_budget'
	. ' JOIN fm_workorder ON fm_workorder.id = fm_workorder_budget.order_id';

if ($serverSettings['db_type'] == 'postgres')
{
	$sql .= " ORDER BY fm_workorder.project_id";
}
$db->query($sql, __LINE__, __FILE__);


$sql = 'CREATE VIEW fm_project_budget_year_view AS'
	. ' SELECT DISTINCT fm_project_budget.project_id, fm_project_budget.year'
	. ' FROM fm_project_budget';

if ($serverSettings['db_type'] == 'postgres')
{
	$sql .= " ORDER BY fm_project_budget.project_id";
}
$db->query($sql, __LINE__, __FILE__);

$db->query("INSERT INTO fm_ecodimb_role (id, name, amount_limit) VALUES (1, 'Bestiller', 50000)", __LINE__, __FILE__);
$db->query("INSERT INTO fm_ecodimb_role (id, name, amount_limit) VALUES (2, 'Attestant', 250000)", __LINE__, __FILE__);
$db->query("INSERT INTO fm_ecodimb_role (id, name, amount_limit) VALUES (3, 'Anviser', 1000000)", __LINE__, __FILE__);

$translation = p_setup_translate('Highest');
$db->query("INSERT INTO fm_tts_priority (id, name) VALUES (1, '1 - {$translation}')");
$db->query("INSERT INTO fm_tts_priority (id, name) VALUES (2, '2')");
$translation = p_setup_translate('Lowest');
$db->query("INSERT INTO fm_tts_priority (id, name) VALUES (3, '3 - {$translation}')");

$probability_comment[1]	 = ' - ' . 'low probability';
$probability_comment[2]	 = ' - ' . 'medium probability';
$probability_comment[3]	 = ' - ' . 'high probability';
for ($i = 1; $i <= 3; $i++)
{
	$db->query("INSERT INTO fm_request_probability (id, name) VALUES ({$i}, '{$i}{$probability_comment[$i]}')");
}

$consequence_comment[0]	 = ' - ' . 'None Consequences';
$consequence_comment[1]	 = ' - ' . 'Minor Consequences';
$consequence_comment[2]	 = ' - ' . 'Medium Consequences';
$consequence_comment[3]	 = ' - ' . 'Serious Consequences';
for ($i = 0; $i <= 3; $i++)
{
	$db->query("INSERT INTO fm_request_consequence (id, name) VALUES ({$i}, '{$i}{$consequence_comment[$i]}')");
}
