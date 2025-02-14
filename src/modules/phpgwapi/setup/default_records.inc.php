<?php

/**
 * Setup
 * @copyright Copyright (C) 2003-2005 Free Software Foundation, Inc. http://www.fsf.org/
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License
 * @package phpgwapi
 * @subpackage setup
 * @version $Id$
 */
$db = \App\Database\Db::getInstance();
$location_obj = new \App\modules\phpgwapi\controllers\Locations();
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('aa','Afar','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ab','Abkhazian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('af','Afrikaans','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('am','Amharic','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ar','Arabic','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('as','Assamese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ay','Aymara','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('az','Azerbaijani','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ba','Bashkir','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('be','Byelorussian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('bg','Bulgarian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('bh','Bihari','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('bi','Bislama','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('bn','Bengali','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('bo','Tibetan','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('br','Breton','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ca','Catalan','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('co','Corsican','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('cs','Czech','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('cy','Welsh','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('da','Danish','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('de','German','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('dz','Bhutani','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('el','Greek','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('en','English','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('eo','Esperanto','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('es','Spanish','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('et','Estonian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('eu','Basque','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('fa','Persian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('fi','Finnish','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('fj','Fiji','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('fo','Faeroese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('fr','French','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('fy','Frisian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ga','Irish','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('gd','Scots Gaelic','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('gl','Galician','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('gn','Guarani','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('gu','Gujarati','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ha','Hausa','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('he','Hebrew','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('hi','Hindi','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('hr','Croatian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('hu','Hungarian','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('hy','Armenian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ia','Interlingua','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ie','Interlingue','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ik','Inupiak','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('id','Indonesian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('is','Icelandic','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('it','Italian','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('iu', 'Inuktitut', 'No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ja','Japanese','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('jw','Javanese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ka','Georgian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('kk','Kazakh','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('kl','Greenlandic','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('km','Cambodian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('kn','Kannada','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ko','Korean','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ks','Kashmiri','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ku','Kurdish','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ky','Kirghiz','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('la','Latin','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ln','Lingala','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('lo','Laothian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('lt','Lithuanian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('lv','Latvian / Lettish','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('mg','Malagasy','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('mi','Maori','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('mk','Macedonian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ml','Malayalam','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('mn','Mongolian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('mo','Moldavian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('mr','Marathi','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ms','Malay','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('mt','Maltese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('my','Burmese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('na','Nauru','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ne','Nepali','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('nl','Dutch','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('no','Norwegian','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('nn','Norwegian NN','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('oc','Occitan','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('om','Oromo / Afan','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('or','Oriya','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('pa','Punjabi','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('pl','Polish','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ps','Pashto / Pushto','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('pt','Portuguese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('qu','Quechua','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('rm','Rhaeto-Romance','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('rn','Kirundi','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ro','Romanian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ru','Russian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('rw','Kinyarwanda','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sa','Sanskrit','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sd','Sindhi','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sg','Sangro','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sh','Serbo-Croatian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('si','Singhalese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sk','Slovak','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sl','Slovenian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sm','Samoan','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sn','Shona','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('so','Somali','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sq','Albanian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sr','Serbian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ss','Siswati','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('st','Sesotho','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('su','Sudanese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sv','Swedish','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('sw','Swahili','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ta','Tamil','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('te','Tegulu','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('tg','Tajik','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('th','Thai','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ti','Tigrinya','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('tk','Turkmen','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('tl','Tagalog','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('tn','Setswana','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('to','Tonga','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('tr','Turkish','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ts','Tsonga','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('tt','Tatar','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('tw','Twi','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ug', 'Uigur', 'No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('uk','Ukrainian','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('ur','Urdu','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('uz','Uzbek','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('vi','Vietnamese','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('vo','Volapuk','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('wo','Wolof','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('xh','Xhosa','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('yi','Yiddish','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('yo','Yoruba','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('zh','Chinese (Simplified)','No')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('zt','Chinese (Traditional)','Yes')");
$db->query("INSERT INTO phpgw_languages (lang_id, lang_name, available) VALUES ('zu','Zulu','No')");

$db->query("INSERT INTO phpgw_interserv(server_name,server_host,server_url,trust_level,trust_rel,server_mode) VALUES ('phpGW cvsdemo',NULL,'http://www.phpgroupware.org/cvsdemo/xmlrpc.php',99,0,'xmlrpc')");

$now = $db->to_timestamp(time());
$db->query("INSERT INTO phpgw_vfs (owner_id, createdby_id, modifiedby_id, created, modified, size, mime_type, deleteable, comment, app, directory, name, link_directory, link_name) VALUES (0,0,0,'{$now}',NULL,NULL,'Directory','Y',NULL,NULL,'/','', NULL, NULL)");
$db->query("INSERT INTO phpgw_vfs (owner_id, createdby_id, modifiedby_id, created, modified, size, mime_type, deleteable, comment, app, directory, name, link_directory, link_name) VALUES (0,0,0,'{$now}',NULL,NULL,'Directory','Y',NULL,NULL,'/','home', NULL, NULL)");


$db->query("INSERT INTO phpgw_contact_types (contact_type_descr,contact_type_table) VALUES ('Persons','phpgw_contact_person')");
$db->query("INSERT INTO phpgw_contact_types (contact_type_descr,contact_type_table) VALUES ('Organizations','phpgw_contact_org')");
$db->query("INSERT INTO phpgw_contact_comm_type (type) VALUES ('email')");
$db->query("INSERT INTO phpgw_contact_comm_type (type) VALUES ('phone')");
$db->query("INSERT INTO phpgw_contact_comm_type (type) VALUES ('mobile phone')");
$db->query("INSERT INTO phpgw_contact_comm_type (type) VALUES ('fax')");
$db->query("INSERT INTO phpgw_contact_comm_type (type) VALUES ('instant messaging')");
$db->query("INSERT INTO phpgw_contact_comm_type (type) VALUES ('url')");
$db->query("INSERT INTO phpgw_contact_comm_type (type) VALUES ('other')");
// Address type
$db->query("INSERT INTO phpgw_contact_addr_type (description) VALUES ('work')");
$db->query("INSERT INTO phpgw_contact_addr_type (description) VALUES ('home')");
// Note type
$db->query("INSERT INTO phpgw_contact_note_type (description) VALUES ('general')");
$db->query("INSERT INTO phpgw_contact_note_type (description) VALUES ('vcard')");
$db->query("INSERT INTO phpgw_contact_note_type (description) VALUES ('system')");

$db->query("SELECT comm_type_id FROM phpgw_contact_comm_type WHERE type='email'");
//	$comm_type_ids = $db->m_odb->resultSet;
$comm_type_ids = array();
while ($db->next_record())
{
	$comm_type_ids[] = array(
		'comm_type_id'	=> $db->f('comm_type_id')
	);
}

for ($i = 0; $i < count($comm_type_ids); $i++)
{
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'home email'"
		.  ")");

	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'work email'"
		.  ")");
}

$db->query("SELECT comm_type_id FROM phpgw_contact_comm_type WHERE type='phone'");
//	$comm_type_ids = $db->m_odb->resultSet;
$comm_type_ids = array();
while ($db->next_record())
{
	$comm_type_ids[] = array(
		'comm_type_id'	=> $db->f('comm_type_id')
	);
}

for ($i = 0; $i < count($comm_type_ids); $i++)
{
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'home phone'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'work phone'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'voice phone'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'msg phone'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'pager'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'bbs'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'modem'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'isdn'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'video'"
		.  ")");
}
$db->query("SELECT comm_type_id FROM phpgw_contact_comm_type WHERE type='fax'");
//	$comm_type_ids = $db->m_odb->resultSet;
$comm_type_ids = array();
while ($db->next_record())
{
	$comm_type_ids[] = array(
		'comm_type_id'	=> $db->f('comm_type_id')
	);
}

for ($i = 0; $i < count($comm_type_ids); $i++)
{
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'home fax'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'work fax'"
		.  ")");
}
$db->query("SELECT comm_type_id FROM phpgw_contact_comm_type WHERE type='mobile phone'");
//	$comm_type_ids = $db->m_odb->resultSet;
$comm_type_ids = array();
while ($db->next_record())
{
	$comm_type_ids[] = array(
		'comm_type_id'	=> $db->f('comm_type_id')
	);
}

for ($i = 0; $i < count($comm_type_ids); $i++)
{
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'mobile (cell) phone'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'car phone'"
		.  ")");
}
$db->query("SELECT comm_type_id FROM phpgw_contact_comm_type WHERE type='instant messaging'");
//	$comm_type_ids = $db->m_odb->resultSet;
$comm_type_ids = array();
while ($db->next_record())
{
	$comm_type_ids[] = array(
		'comm_type_id'	=> $db->f('comm_type_id')
	);
}

for ($i = 0; $i < count($comm_type_ids); $i++)
{
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'msn'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'aim'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'yahoo'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'icq'"
		.  ")");
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'jabber'"
		.  ")");
}
$db->query("SELECT comm_type_id FROM phpgw_contact_comm_type WHERE type='url'");
//	$comm_type_ids = $db->m_odb->resultSet;
$comm_type_ids = array();
while ($db->next_record())
{
	$comm_type_ids[] = array(
		'comm_type_id'	=> $db->f('comm_type_id')
	);
}

for ($i = 0; $i < count($comm_type_ids); $i++)
{
	$db->query("INSERT INTO phpgw_contact_comm_descr (comm_type_id,descr) VALUES ("
		. $comm_type_ids[$i]['comm_type_id']
		. ",'website'"
		.  ")");
}

// Sane defaults for the API
$values = array(
	'max_access_log_age'	=> 90,
	'block_time'			=> 30,
	'num_unsuccessful_id'	=> 3,
	'num_unsuccessful_ip'	=> 3,
	'install_id'			=> sha1(uniqid(rand(), true)),
	'max_history'			=> 20,
	'sessions_checkip'		=> 'True',
	'sessions_timeout'		=> 1440,
	'addressmaster'			=> -3,
	'log_levels'			=> serialize(array('global_level' => 'E', 'module' => array(), 'user' => array())),
	'freshinstall'			=> 1,
	'usecookies'			=> 'True',
	'cache_refresh_token'	=> 1
);

foreach ($values as $name => $val)
{
	$sql = "INSERT INTO phpgw_config VALUES('phpgwapi', '{$name}', '{$val}')";
	$db->query($sql, __LINE__, __FILE__);
}

$location_obj->add('changepassword', 'allow user to change password', 'preferences', false);
$location_obj->add('anonymous', 'allow anonymous sessions for public modules', 'phpgwapi', false);
$location_obj->add('vfs_filedata', 'config section for VFS filedata - file backend', 'admin', false);
$location_id = $location_obj->add('openid_connect', 'config section for OpenID Connect', 'admin', false);

//$custom_config = CreateObject('admin.soconfig', $location_id);
$custom_config = (new \App\modules\phpgwapi\services\ConfigLocation($location_id));
// common
$receipt_section_common = $custom_config->add_section(
	array(
		'name' => 'common',
		'descr' => 'common OpenID Connect configuration'
	)
);

//method_backend
$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_common['section_id'],
		'input_type'	=> 'checkbox',
		'name'			=> 'method_backend',
		'descr'			=> 'method backend OpenID Connect configuration',
		'choice' => array('local', 'remote'),
		'value'			=> [],
	)
);

//method_frontend
$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_common['section_id'],
		'input_type'	=> 'checkbox',
		'name'			=> 'method_frontend',
		'descr'			=> 'method frontend OpenID Connect configuration',
		'choice' => array('remote'),
		'value'			=> [],
	)
);


//remote
$receipt_section_remote = $custom_config->add_section(
	array(
		'name' => 'remote',
		'descr' => 'Remote OpenID Connect configuration'
	)
);


$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_remote['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'name',
		'descr'			=> 'Name of method',
		'value'			=> '',
	)
);

//respons_variable
$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_remote['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'response_variable',
		'descr'			=> 'Name of variable to fetch from response',
		'value'			=> '',
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_remote['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'client_id',
		'descr'			=> 'Client ID',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_remote['section_id'],
		'input_type'	=> 'password',
		'name'			=> 'client_secret',
		'descr'			=> 'Client Secret',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_remote['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'redirect_uri',
		'descr'			=> 'Redirect URI',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_remote['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'provider_url',
		'descr'			=> 'Provider url',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_remote['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'scopes',
		'descr'			=> 'Scopes',
		'value'			=> '',
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_remote['section_id'],
		'input_type'	=> 'checkbox',
		'name'			=> 'debug',
		'descr'			=> 'Debug - will print out the response',
		'choice' => array('debug'),
		'value'			=> [],
	)
);

//local
$receipt_section_local = $custom_config->add_section(
	array(
		'name' => 'local',
		'descr' => 'local OpenID Connect configuration'
	)
);

/* config values
	[
		'client_id' => 'YOUR_CLIENT_ID',
		'client_secret' => 'YOUR_CLIENT_SECRET',
		'redirect_uri' => 'https://yourdomain.com/callback.php',
		'provider_url' => 'https://login.microsoftonline.com/YOUR_TENANT_ID/v2.0',
		'scopes' => 'openid profile email'
	];
*/
$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_local['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'name',
		'descr'			=> 'Name of method',
		'value'			=> '',
	)
);

//respons_variable
$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_local['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'response_variable',
		'descr'			=> 'Name of variable to fetch from response',
		'value'			=> '',
	)
);
$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_local['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'client_id',
		'descr'			=> 'Client ID',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_local['section_id'],
		'input_type'	=> 'password',
		'name'			=> 'client_secret',
		'descr'			=> 'Client Secret',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_local['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'redirect_uri',
		'descr'			=> 'Redirect URI',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_local['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'provider_url',
		'descr'			=> 'Provider url',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_local['section_id'],
		'input_type'	=> 'text',
		'name'			=> 'scopes',
		'descr'			=> 'Scopes',
		'value'			=> '',
	)
);

$receipt = $custom_config->add_attrib(
	array(
		'section_id'	=> $receipt_section_local['section_id'],
		'input_type'	=> 'checkbox',
		'name'			=> 'debug',
		'descr'			=> 'Debug - will print out the response',
		'choice' => array('debug'),
		'value'			=> [],
	)
);
