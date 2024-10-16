<?php

use App\Database\Db;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\services\Cache;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

$db		 = Db::getInstance();
$accounts_obj = new Accounts();
$serverSettings = Settings::getInstance()->get('server');
$userSettings = Settings::getInstance()->get('user');


//_debug_array($data);
//_debug_array($id);
$_closed = false;
if (in_array('status', $this->fields_updated))
{
	if ($data['status'] == 'X')
	{
		$_closed = true;
	}
	else if (stripos($data['status'], 'C') === 0)
	{
		$_status = (int)trim($data['status'], 'C');
		$db->query("SELECT * from fm_tts_status WHERE id = {$_status}", __LINE__, __FILE__);
		$db->next_record();
		if ($db->f('closed'))
		{
			$_closed = true;
		}
	}
}

$projects = array();
if ($_closed) // take action
{
	$interlink	 = CreateObject('property.interlink');
	$targets	 = $interlink->get_relation('property', '.ticket', $id, 'target');
	foreach ($targets as $target)
	{
		if ($target['location'] == '.project')
		{
			foreach ($target['data'] as $_data)
			{
				$project	 = execMethod('property.soproject.read_single', $_data['id']);
				$projects[]	 = array(
					'id'			 => $_data['id'],
					'coordinator'	 => $project['coordinator'],
					'link'			 => $_data['link'],
					'statustext'	 => $_data['statustext']
				);
			}
		}
	}

	$send = CreateObject('phpgwapi.send');

	$db->query("SELECT external_ticket_id FROM fm_tts_tickets WHERE id = " . (int)$id, __LINE__, __FILE__);
	$db->next_record();
	$external_ticket_id = $db->f('external_ticket_id');
	//varsle ISS om at denne utgår
	if ($external_ticket_id && !$projects)
	{
		$recipients = array(
			//	'dag.boye.tellnes@no.issworld.com',
			'sigurd.nes@bergen.kommune.no'
		);

		$_to				 = implode(';', $recipients);
		$bcc				 = '';
		$cc					 = '';
		$coordinator_email	 = 'IkkeSvar@bergen.kommune.no';
		$coordinator_name	 = $serverSettings['site_title'];

		$subject = "WO ID: {$external_ticket_id} er håndtert uten bestilling";
		$message = "Saken er avsluttet\nVårt nummer er: {$id}";

		try
		{
			$rcpt = $send->msg('email', $_to, $subject, $message, '', $cc, $bcc, $coordinator_email, $coordinator_name, 'txt', '', array());
			Cache::message_set(lang('%1 is notified', $_to), 'message');
			$this->historylog->add('M', $id, "ISS (varslet om at bestilling utgår, med referanse til deres avviksmelding)");
		}
		catch (Exception $exc)
		{
			Cache::message_set($exc->getMessage(), 'error');
		}
	}

	$validator	 = CreateObject('phpgwapi.EmailAddressValidator');
	$socommon	 = CreateObject('property.socommon');

	foreach ($projects as $project_info)
	{
		$prefs			 = $socommon->create_preferences('common', $project_info['coordinator']);
		$account_name	 = $accounts_obj->get($project_info['coordinator'])->__toString();
		if ($validator->check_email_address($prefs['email']))
		{
			// Email address is technically valid
			// avoid problems with the delimiter in the send class
			if (strpos($account_name, ','))
			{
				$_account_name	 = explode(',', $account_name);
				$account_name	 = ltrim($_account_name[1]) . ' ' . $_account_name[0];
			}

			$_to		 = "{$account_name}<{$prefs['email']}>";
			$from_name	 = $userSettings['fullname'];

			if (strpos($from_name, ','))
			{
				$_from_name	 = explode(',', $from_name);
				$from_name	 = ltrim($_from_name[1]) . ' ' . $_from_name[0];
			}

			$from_email	 = "{$from_name}<{$userSettings['preferences']['common']['email']}>";
			$cc			 = '';
			$bcc		 = '';
			$subject	 = "Status er endret for melding tilknyttet prosjekt {$project_info['id']}";
			$body		 = "<H2>{$subject}</H2>";
			$body		.= "</br><a href='https://{$serverSettings['hostname']}{$project_info['link']}'>{$subject} - klikk her for å oppdatere status for prosjektet</a>";

			try
			{
				$rcpt = $send->msg('email', $_to, $subject, stripslashes($body), '', $cc, $bcc, $from_email, $from_name, 'html', '');
			}
			catch (Exception $e)
			{
				$receipt['error'][] = array('msg' => $e->getMessage());
			}
			if ($rcpt)
			{
				$receipt['message'][] = array('msg' => "Epost er sendt til {$account_name} angående prosjektnr {$project_info['id']}");
			}
		}
		else
		{
			$receipt['error'][] = array('msg' => lang('This user has not defined an email address !') . ' : ' . $account_name);
		}
	}
}
