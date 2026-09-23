<?php
$preferences = \App\modules\phpgwapi\services\Preferences::getInstance();

$preferences->add('addressbook', 'company', 'addressbook_True');
$preferences->add('addressbook', 'lastname', 'addressbook_True');
$preferences->add('addressbook', 'firstname', 'addressbook_True');
$preferences->add('addressbook', 'default_category', '');
