<?php

use App\modules\phpgwapi\controllers\Accounts\Accounts;
use App\modules\phpgwapi\services\Settings;

$accounts_obj = new Accounts();
$serverSettings = Settings::getInstance()->get('server');
$userSettings = Settings::getInstance()->get('user');

$validator = CreateObject('phpgwapi.EmailAddressValidator');

if (isset($config_data['notify_email']) && $config_data['notify_email'])
{
	$to_array	 = array();
	$_to_array	 = explode(',', $config_data['notify_email']);

	if (isset($config_data['notify_rule']) && $config_data['notify_rule'])
	{
		$notify_rule = explode(',', $config_data['notify_rule']);
		foreach ($notify_rule as $_rule)
		{
			$__rule	 = explode('=&gt;', $_rule);
			$___rule = explode(';', trim($__rule[1]));
			if ($__rule)
			{
				$_condition = explode('=', $__rule[0]);
				if ($_condition)
				{
					$this->db->query("SELECT * FROM $target_table WHERE id = {$id} AND " . trim($_condition[0]) . "='" . trim($_condition[1]) . "'", __LINE__, __FILE__);
					if ($this->db->next_record())
					{
						foreach ($___rule as $____rule)
						{
							if (isset($_to_array[($____rule - 1)]))
							{
								$to_array[] = $_to_array[($____rule - 1)];
							}
						}
					}
				}
			}
		}
	}
	else
	{
		$to_array = $_to_array;
	}

	$to_array = array_unique($to_array);

	//_debug_array($to_array);

	$socommon	 = CreateObject('property.socommon');
	$prefs		 = $socommon->create_preferences('common', $user_id);

	if ($validator->check_email_address($prefs['email']))
	{
		$account_name = $accounts_obj->id2name($user_id);
		// avoid problems with the delimiter in the send class
		if (strpos($account_name, ','))
		{
			$_account_name	 = explode(',', $account_name);
			$account_name	 = ltrim($_account_name[1]) . ' ' . $_account_name[0];
		}
		$from_email = "{$account_name}<{$prefs['email']}>";

		$to_array[] = $from_email;
	}


	$send = CreateObject('phpgwapi.send');


	$_to = implode(';', $to_array);

	$from_name	 = 'noreply';
	$from_email	 = isset($from_email) && $from_email ? $from_email : "{$from_name}<sigurd.nes@bergen.kommune.no>";
	$cc			 = '';
	$bcc		 = '';
	$subject	 = "{$schema_text}::{$id}";

	// Include something in subject
	if (isset($config_data['email_include_in_subject']) && $config_data['email_include_in_subject'])
	{
		$params		 = explode('=&gt;', $config_data['email_include_in_subject']);
		$_metadata	 = $this->db->metadata($target_table);
		if (isset($_metadata[$params[1]]))
		{
			$this->db->query("SELECT {$params[1]} FROM $target_table WHERE id = {$id}", __LINE__, __FILE__);
			if ($this->db->next_record())
			{
				$subject .= "::{$params[0]} " . $this->db->f($params[1]);
			}
		}
		unset($_metadata);
	}

	unset($_link_to_item);

	if (isset($config_data['email_message']) && $config_data['email_message'])
	{
		$body = str_replace(array('[', ']'), array('<', '>'), $config_data['email_message']);
	}
	else
	{
		$body = "<H2>Det er registrert ny post i {$schema_text}</H2>";
	}

	$_duplicate_num = '';

	$this->db->query("SELECT kontraktsnummer FROM $target_table WHERE id = {$id}", __LINE__, __FILE__);
	if ($this->db->next_record())
	{
		$_kontraktsnummer	 = $this->db->f('kontraktsnummer');
		$this->db->query("SELECT num FROM $target_table WHERE id != {$id} AND kontraktsnummer = '{$_kontraktsnummer}'", __LINE__, __FILE__);
		$this->db->next_record();
		$_duplicate_num		 = $this->db->f('num');
	}

	$attachments = array();

	require_once PHPGW_SERVER_ROOT . "/catch/inc/custom/{$userSettings['domain']}/pdf_3_1.php";

	$pdf = new pdf_3_1();

	try
	{
		$report = $pdf->get_document($id, $_duplicate_num);
	}
	catch (Exception $e)
	{
		$error = $e->getMessage();
		echo "<H1>{$error}</H1>";
	}

	if ($_duplicate_num)
	{
		$this->db->query("DELETE  FROM $target_table WHERE id = {$id}", __LINE__, __FILE__);
	}

	$report_fname = tempnam($serverSettings['temp_dir'], 'PDF_') . '.pdf';
	file_put_contents($report_fname, $report, LOCK_EX);

	$attachments[] = array(
		'file' => $report_fname,
		'name' => "NLSH_melding_om_innflytting_{$id}.pdf",
		'type' => 'application/pdf'
	);

	if ($attachments)
	{
		$body .= "</br>Se vedlegg";
	}


	if ($_to && $send->msg('email', $_to, $subject, stripslashes($body), '', $cc, $bcc, $from_email, $from_name, 'html', '', $attachments, true))
	{
		$this->receipt['message'][] = array('msg' => "email notification sent to: {$_to}");
	}
	if (isset($report_fname) && is_file($report_fname))
	{
		unlink($report_fname);
	}
}
