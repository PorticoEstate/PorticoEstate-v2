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
 * @subpackage cron
 * @version $Id$
 */
/**
 * Description
 * example cron : /usr/local/bin/php -q /var/www/html/phpgroupware/property/inc/cron/cron.php default hent_epost_fra_eksterne_BK
 * @package property
 */
include_class('property', 'cron_parent', 'inc/cron/');

/**
 * 
 * Work in progress...
 */

use App\Database\Db2;
use App\modules\phpgwapi\services\Settings;
use App\modules\phpgwapi\controllers\Accounts\Accounts;

use \jamesiarmes\PhpEws\Client;
use \jamesiarmes\PhpEws\Request\FindItemType;
use \jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfBaseFolderIdsType;
use \jamesiarmes\PhpEws\Enumeration\DefaultShapeNamesType;
use \jamesiarmes\PhpEws\Enumeration\DistinguishedFolderIdNameType;
use \jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use \jamesiarmes\PhpEws\Type\ConstantValueType;
use \jamesiarmes\PhpEws\Type\DistinguishedFolderIdType;
use \jamesiarmes\PhpEws\Type\FieldURIOrConstantType;
use \jamesiarmes\PhpEws\Type\ItemResponseShapeType;
use \jamesiarmes\PhpEws\Type\PathToUnindexedFieldType;
use \jamesiarmes\PhpEws\Type\RestrictionType;
use \jamesiarmes\PhpEws\Type\IsEqualToType;
use \jamesiarmes\PhpEws\Request\GetItemType;
use \jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfBaseItemIdsType;
use \jamesiarmes\PhpEws\Type\ItemIdType;
use \jamesiarmes\PhpEws\Request\GetAttachmentType;
use \jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfRequestAttachmentIdsType;
use \jamesiarmes\PhpEws\Type\RequestAttachmentIdType;
use \jamesiarmes\PhpEws\Request\UpdateItemType;
use \jamesiarmes\PhpEws\Type\ItemChangeType;
use \jamesiarmes\PhpEws\Type\SetItemFieldType;
use \jamesiarmes\PhpEws\Type\MessageType;
use \jamesiarmes\PhpEws\Type\ExchangeImpersonationType;
use \jamesiarmes\PhpEws\Type\ConnectingSIDType;
use \jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfItemChangeDescriptionsType;
// Folder info
use \jamesiarmes\PhpEws\Request\FindFolderType;
use \jamesiarmes\PhpEws\Enumeration\ContainmentComparisonType;
use \jamesiarmes\PhpEws\Enumeration\ContainmentModeType;
use \jamesiarmes\PhpEws\Enumeration\FolderQueryTraversalType;
use \jamesiarmes\PhpEws\Enumeration\UnindexedFieldURIType;
use \jamesiarmes\PhpEws\Type\FolderResponseShapeType;
//
use \jamesiarmes\PhpEws\Request\MoveItemType;


use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use Microsoft\Kiota\Authentication\Oauth\AuthorizationCodeContext;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAuthenticationProvider;

class hent_epost_fra_eksterne_BK_graph extends property_cron_parent
{

	var $items_to_move = array();
	protected $config, $join;

	public function __construct()
	{
		parent::__construct();

		$this->function_name = get_class($this);
		$this->sub_location	 = lang('property');
		$this->function_msg	 = 'Hent epost fra eksterne';
		$this->join			 =  $this->db->join;

		$this->config = CreateObject('admin.soconfig', $this->location_obj->get_id('property', '.admin'));

		//			$this->serverSettings['enforce_ssl'] = true;
	}

	function execute()
	{
		$start						 = time();
		$this->process_messages();
		$msg						 = 'Tidsbruk: ' . (time() - $start) . ' sekunder';
		$this->cron_log($msg, $cron);
		echo "$msg\n";
		$this->receipt['message'][]	 = array('msg' => $msg);
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

	function getAccessToken()
	{

		$clientId = $this->config->config_data['xPortico']['client_id'];
		$clientSecret = $this->config->config_data['xPortico']['client_secret'];
		$tenantId = $this->config->config_data['xPortico']['tenant_id'];



		$guzzle = new \GuzzleHttp\Client();
		$response = $guzzle->post("https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token", [
			'form_params' => [
				'client_id' => $clientId,
				'client_secret' => $clientSecret,
				'scope' => 'https://graph.microsoft.com/.default',
				'grant_type' => 'client_credentials',
			],
		]);

		$body = json_decode($response->getBody(), true);
		return $body['access_token'];

	}
	public function fetchEmails()
	{
		$accessToken = $this->getAccessToken();
		$graph = new Graph();
		$graph->setAccessToken($accessToken);

		$assigneeEmail = $this->config->config_data['xPortico']['mailbox'];

		// Get the folder ID for 'Portico_Leverador-meldinger'
		$folderId = $this->findFolderId($graph, $assigneeEmail, 'Portico_Leverador-meldinger');

		// Get unread messages from the folder
		$messages = $graph->createRequest("GET", "/users/$assigneeEmail/mailFolders/$folderId/messages?\$filter=isRead eq false")
		->setReturnType(Model\Message::class)
			->execute();

		foreach ($messages as $message)
		{
			$this->processMessage($graph, $message, $assigneeEmail);
		}
	}

	private function findFolderId(Graph $graph, $userEmail, $folderName)
	{
		$folders = $graph->createRequest("GET", "/users/$userEmail/mailFolders")
		->setReturnType(Model\MailFolder::class)
			->execute();

		foreach ($folders as $folder)
		{
			if ($folder->getDisplayName() === $folderName)
			{
				return $folder->getId();
			}
		}

		throw new Exception("Folder '$folderName' not found.");
	}

	private function processMessage(Graph $graph, Model\Message $message, $userEmail)
	{
		$messageId = $message->getId();
		$attachments = $message->getAttachments();

		$target = $this->handleMessage($message);

		if ($attachments && $target['id'])
		{
			$savedAttachments = $this->handleAttachments($graph, $attachments, $userEmail, $messageId);
			$this->addAttachmentToTarget($target, $savedAttachments);
			$this->cleanAttachmentFromTemp($savedAttachments);
		}

		$this->items_to_move[] = $messageId;
		$this->moveMessages($graph, $userEmail, $this->items_to_move, 'ImportertTilDatabase');
		$this->items_to_move = [];
	}

	private function handleMessage(Model\Message $message)
	{
		// Implement your message handling logic here
		return ['id' => 'some_target_id'];
	}

	private function handleAttachments(Graph $graph, $attachments, $userEmail, $messageId)
	{
		$savedAttachments = [];
		foreach ($attachments as $attachment)
		{
			if ($attachment instanceof Model\FileAttachment)
			{
				$attachmentId = $attachment->getId();
				$attachmentContent = $graph->createRequest("GET", "/users/$userEmail/messages/$messageId/attachments/$attachmentId/\$value")
				->execute()->getBody();
				// Save the attachment content to a file or database
				$savedAttachments[] = $attachmentContent;
			}
		}
		return $savedAttachments;
	}

	private function addAttachmentToTarget($target, $savedAttachments)
	{
		// Implement your logic to add attachments to the target
	}

	private function cleanAttachmentFromTemp($savedAttachments)
	{
		// Implement your logic to clean up temporary attachments
	}

	private function moveMessages(Graph $graph, $userEmail, $messageIds, $destinationFolderName)
	{
		$destinationFolderId = $this->findFolderId($graph, $userEmail, $destinationFolderName);

		foreach ($messageIds as $messageId)
		{
			$graph->createRequest("POST", "/users/$userEmail/messages/$messageId/move")
			->attachBody(['destinationId' => $destinationFolderId])
				->execute();
		}
	}

	
	function handle_message($item3)
	{
		$target	 = array();
		$subject = $item3->Subject;
		$rool	 = $item3->Body->_;

		$html2text	 = createObject('phpgwapi.html2text', $rool);
		$body		 = $html2text->getText();

		if (preg_match("/^ISS:/", $subject))
		{
			$ticket_id = $this->create_ticket($subject, $body);
			if ($ticket_id)
			{
				$this->receipt['message'][]	 = array('msg' => "Melding #{$ticket_id} er opprettet");
				$target['type']				 = 'fmticket';
				$target['id']				 = $ticket_id;
			}
		}
		else if (preg_match("/^Kvittering status:/", $subject))
		{
			$order_id = $this->set_order_status($subject, $body, $item3->LastModifiedName);

			if ($order_id)
			{
				$target['type']				 = 'workorder';
				$target['id']				 = $order_id;
				$this->receipt['message'][]	 = array('msg' => "Status for ordre #{$order_id} er oppdatert");
			}
		}
		else if (preg_match("/^ISS vedlegg:/", $subject))
		{
			$ticket_id = $this->get_ticket($subject);

			if ($ticket_id)
			{
				$target['type']	 = 'fmticket';
				$target['id']	 = $ticket_id;
			}
		}
		else if (preg_match("/\[PorticoTicket/", $subject))
		{
			preg_match_all("/\[[^\]]*\]/", $subject, $matches);
			$identificator_str	 = trim($matches[0][0], "[]");
			$identificator_arr	 = explode("::", $identificator_str);

			$sender		 = $item3->Sender->Mailbox->EmailAddress;
			$ticket_id	 = $this->update_external_communication($identificator_arr, $body, $sender);

			if ($ticket_id)
			{
				$target['type']	 = 'fmticket';
				$target['id']	 = $ticket_id;
			}
		}

		/**
		 * Ticket created / updated
		 */
		if ($target)
		{
			$this->items_to_move[] = $item3;
		}
		return $target;
	}

	function update_external_communication($identificator_arr, $body, $sender)
	{
		$ticket_id	 = (int)$identificator_arr[1];
		$msg_id		 = (int)$identificator_arr[2];

		if (!$msg_id)
		{
			return false;
		}
		$soexternal = createObject('property.soexternal_communication');

		$message_arr = explode('========', $body);
		$message	 = Sanitizer::clean_value($message_arr[0]);
		if ($soexternal->add_msg($msg_id, $message, $sender))
		{
			$sql		 = "SELECT assignedto"
				. " FROM fm_tts_tickets"
				. " WHERE id = {$ticket_id}";
			$this->db->query($sql, __LINE__, __FILE__);
			$this->db->next_record();
			$assignedto	 = $this->db->f('assignedto');
			if ($assignedto)
			{
				createObject('property.boexternal_communication')->alert_assigned($msg_id);
			}

			return $ticket_id;
		}
	}

	function get_ticket($subject)
	{
		//ISS vedlegg: vedlegg til #ID: <din WO ID>
		$subject_arr		 = explode('#', $subject);
		$id_arr				 = explode(':', $subject_arr[1]);
		$external_ticket_id	 = (int)($id_arr[1]);
		$sql				 = "SELECT id"
			. " FROM fm_tts_tickets"
			. " WHERE external_ticket_id = {$external_ticket_id}";
		$this->db->query($sql, __LINE__, __FILE__);
		$this->db->next_record();
		$ticket_id			 = $this->db->f('id');
		return $ticket_id;
	}

	function set_order_status($subject, $body, $from)
	{
		$order_arr	 = explode(':', $subject);
		$order_id	 = (int)trim($order_arr[1]);

		$text				 = trim($body);
		$textAr				 = explode(PHP_EOL, $text);
		$textAr				 = array_filter($textAr, 'trim'); // remove any extra \r characters left behind
		$message_details_arr = array();
		foreach ($textAr as $line)
		{
			if (preg_match("/Untitled document/", $line))
			{
				continue;
			}
			if (preg_match("/Status:/", $line))
			{
				$tatus_arr	 = explode(':', $line);
				$tatus_text	 = trim($tatus_arr[1]);
			}
			if (preg_match("/Lukketekst:/", $line))
			{
				$remark_arr				 = explode(':', $line);
				$message_details_arr[]	 = trim($remark_arr[1]);
			}
			else
			{
				$message_details_arr[] = trim($line);
			}
		}

		$message_details = Sanitizer::clean_value(implode(PHP_EOL, $message_details_arr));

		switch ($tatus_text)
		{
			case 'Utført EBF':
				$status_id	 = 1;
				break;
			case 'Igangsatt EBF':
				$status_id	 = 3;
				break;
			case 'Akseptert':
				$status_id	 = 4;
				break;
			case 'Akseptert med endret Due Date':
				$status_id	 = 4;
				break;
			default:
				break;
		}

		$ok = false;
		if ($order_id && $status_id)
		{
			$ok = $this->update_order_status($order_id, $status_id, $tatus_text, $from);
		}

		return $ok ? $order_id : false;
	}

	function update_order_status($order_id, $status_id, $tatus_text, $from)
	{
		$status_code = array(
			1	 => 'utført',
			2	 => 'ikke_tilgang',
			3	 => 'i_arbeid',
		);

		$historylog			 = CreateObject('property.historylog', 'workorder');
		// temporary - fix this
		$historylog->account = 6;

		$ok		 = false;
		if ($status	 = $status_code[$status_id])
		{
			$this->db->query("SELECT project_id, status FROM fm_workorder WHERE id='{$order_id}'", __LINE__, __FILE__);
			if ($this->db->next_record())
			{
				$project_id	 = (int)$this->db->f('project_id');
				$status_old	 = $this->db->f('status');
				$this->db->query("UPDATE fm_workorder SET status = '{$status}' WHERE id='{$order_id}'", __LINE__, __FILE__);
				$historylog->add('S', $order_id, $status, $status_old);
				$historylog->add('RM', $order_id, 'Status endret av: ' . $from);

				if (in_array($status_id, array(1, 3)))
				{
					$this->db->query("SELECT status FROM fm_project WHERE id='{$project_id}'", __LINE__, __FILE__);
					$this->db->next_record();
					$status_old = $this->db->f('status');
					if ($status_old != 'i_arbeid')
					{
						$this->db->query("UPDATE fm_project SET status = 'i_arbeid' WHERE id='{$project_id}'", __LINE__, __FILE__);
						$historylog_project			 = CreateObject('property.historylog', 'project');
						$historylog_project->account = 6;
						$historylog_project->add('S', $project_id, 'i_arbeid', $status_old);
						$historylog_project->add('RM', $project_id, "Bestilling {$order_id} endret av: {$from}");
					}

					//				execMethod('property.soworkorder.check_project_status',$order_id);

					$project_status_on_last_order_closed = 'utført';

					$this->db->query("SELECT count(id) AS orders_at_project FROM fm_workorder WHERE project_id= {$project_id}", __LINE__, __FILE__);
					$this->db->next_record();
					$orders_at_project = (int)$this->db->f('orders_at_project');

					$this->db->query("SELECT count(fm_workorder.id) AS closed_orders_at_project"
						. " FROM fm_workorder"
						. " JOIN fm_workorder_status ON (fm_workorder.status = fm_workorder_status.id)"
						. " WHERE project_id= {$project_id}"
						. " AND (fm_workorder_status.closed = 1 OR fm_workorder_status.delivered = 1)", __LINE__, __FILE__);

					$this->db->next_record();
					$closed_orders_at_project = (int)$this->db->f('closed_orders_at_project');

					$this->db->query("SELECT fm_project_status.closed AS closed_project, fm_project.status as old_status"
						. " FROM fm_project"
						. " JOIN fm_project_status ON (fm_project.status = fm_project_status.id)"
						. " WHERE fm_project.id= {$project_id}", __LINE__, __FILE__);

					$this->db->next_record();
					$closed_project	 = !!$this->db->f('closed_project');
					$old_status		 = $this->db->f('old_status');

					if ($status == 'utført' && $orders_at_project == $closed_orders_at_project && $old_status != $project_status_on_last_order_closed)
					{
						$this->db->query("UPDATE fm_project SET status = '{$project_status_on_last_order_closed}' WHERE id= {$project_id}", __LINE__, __FILE__);

						$historylog_project = CreateObject('property.historylog', 'project');

						$historylog_project->add('S', $project_id, $project_status_on_last_order_closed, $old_status);
						$historylog_project->add('RM', $project_id, 'Status endret ved at siste bestilling er satt til utført');
					}
				}

				if ($status_id == 1)
				{
					$this->close_ticket($project_id, $order_id);
				}

				$ok = true;
			}
		}
		else
		{
			$historylog->add('RM', $order_id, "{$from}: $tatus_text");
			$ok = true;
		}

		return $ok;
	}

	/**
	 * Avslutte meldinger som er relatert til bestillinger som settes til utført
	 * @param type $project_id
	 * @param type $order_id
	 */
	function close_ticket($project_id, $order_id)
	{
		$interlink	 = CreateObject('property.interlink');
		$historylog	 = CreateObject('property.historylog', 'tts');
		$botts		 = CreateObject('property.botts');

		$origin_data = $interlink->get_relation('property', '.project.workorder', $order_id, 'origin');
		$origin_data = array_merge($origin_data, $interlink->get_relation('property', '.project', $project_id, 'origin'));


		$tickets = array();
		foreach ($origin_data as $__origin)
		{
			if ($__origin['location'] != '.ticket')
			{
				continue;
			}

			foreach ($__origin['data'] as $_origin_data)
			{
				$tickets[] = (int)$_origin_data['id'];
			}
		}

		$note_closed = "Meldingen er automatisk avsluttet fra bestilling som er satt til utført";

		foreach ($tickets as $ticket_id)
		{
			$this->db->query("SELECT status, cat_id, finnish_date, finnish_date2 FROM fm_tts_tickets WHERE id='$ticket_id'", __LINE__, __FILE__);
			$this->db->next_record();

			/**
			 * Oppdatere kun åpne meldinger
			 */
			$status			 = $this->db->f('status');
			$ticket_category = $this->db->f('cat_id');
			if ($status == 'X' || $ticket_category == 34) // klargjøring (48)
			{
				continue;
			}

			$botts->update_status(array('status' => 'X'), $ticket_id);
			$historylog->add('C', $ticket_id, $note_closed);
			$this->receipt['message'][] = array('msg' => "Melding #{$ticket_id} er avsluttet");
		}
	}

	function create_ticket($subject, $body)
	{
		$ticket_id		 = $this->get_ticket($subject);
		$message_cat_id	 = 10100; // Melding fra eksterne -> vaktmesteravtale

		$subject_arr		 = explode('#', $subject);
		$id_arr				 = explode(':', $subject_arr[1]);
		$external_ticket_id	 = trim($id_arr[1]);
		$text				 = trim($body);
		$textAr				 = explode(PHP_EOL, $text);
		$textAr				 = array_filter($textAr, 'trim'); // remove any extra \r characters left behind
		$message_details_arr = array($subject);
		foreach ($textAr as $line)
		{

			if (preg_match("/Untitled document/", $line))
			{
				continue;
			}

			if (preg_match("/Lokasjonskode:/", $line))
			{
				$location_arr	 = explode(':', $line);
				$location_code	 = Sanitizer::clean_value(trim($location_arr[1]));
			}
			if (preg_match("/Kategori:/i", $line))
			{
				$category_arr	 = explode(':', $line);
				$category_text	 = trim($category_arr[1]);
				if (preg_match("/brann/i", $category_text))
				{
					$message_cat_id = 10103; // Melding fra eksterne -> vaktmesteravtale -> Brann
				}
			}
			else if (preg_match("/Avviket gjelder:/", $line))
			{
				$message_title_arr	 = explode(':', $line);
				$message_title		 = Sanitizer::clean_value(trim($message_title_arr[1]));
			}
			else
			{
				$message_details_arr[] = trim($line);
			}
		}

		$message_details = Sanitizer::clean_value(implode(PHP_EOL, $message_details_arr));

		if ($ticket_id)
		{
			$historylog = CreateObject('property.historylog', 'tts');
			$historylog->add('C', $ticket_id, $message_details);
		}
		else
		{

			if (!$location_code)
			{
				$this->log->debug(array(
					'text' => "mangler lokasjonskode for : %1", // fra: %2 ",
					'p1' => Sanitizer::clean_value($subject),
					//						'p2' => $value ? $value : ' ',
					'line' => __LINE__,
					'file' => __FILE__
				));

				return false;
			}


			$priority	 = 3;
			$ticket		 = array(
				'location_code'		 => $location_code,
				'cat_id'			 => $message_cat_id,
				'priority'			 => $priority, //valgfri (1-3)
				'title'				 => $message_title,
				'details'			 => $message_details,
				'external_ticket_id' => $external_ticket_id
			);

			$ticket_id = CreateObject('property.botts')->add_ticket($ticket);
		}
		return $ticket_id;
	}

	function clean_attacthment_from_temp($saved_attachments)
	{
		foreach ($saved_attachments as $saved_attachment)
		{
			unlink($saved_attachment['tmp_name']);
		}
	}

	function add_attacthment_to_target($target, $saved_attachments)
	{
		$target['type'];
		$target['id'];

		$bofiles = CreateObject('property.bofiles');
		foreach ($saved_attachments as $saved_attachment)
		{
			$file_name = str_replace(array('/', ' ', '..'), array('_', '_', '.'), $saved_attachment['name']);

			if ($file_name && $target['id'])
			{
				$to_file = "{$bofiles->fakebase}/{$target['type']}/{$target['id']}/{$file_name}";

				if ($bofiles->vfs->file_exists(array(
					'string'	 => $to_file,
					'relatives'	 => array(RELATIVE_NONE)
				)))
				{
					$this->receipt['error'][] = array('msg' => lang('This file already exists !'));
				}
				else
				{
					$bofiles->create_document_dir("{$target['type']}/{$target['id']}");
					$bofiles->vfs->override_acl = 1;

					if (!$bofiles->vfs->cp(array(
						'from'		 => $saved_attachment['tmp_name'],
						'to'		 => $to_file,
						'relatives'	 => array(RELATIVE_NONE | VFS_REAL, RELATIVE_ALL)
					)))
					{
						$this->receipt['error'][] = array('msg' => lang('Failed to upload file !'));
					}
					$bofiles->vfs->override_acl = 0;
				}
			}
		}
	}

	function update_message($client, $item3)
	{
		$message_id			 = $item3->ItemId->Id;
		$message_change_key	 = $item3->ItemId->ChangeKey;

		$request = new UpdateItemType();

		$request->SendMeetingInvitationsOrCancellations	 = 'SendToNone';
		$request->MessageDisposition					 = 'SaveOnly';
		$request->ConflictResolution					 = 'AlwaysOverwrite';
		$request->ItemChanges							 = array();

		// Build out item change request.
		$change						 = new ItemChangeType();
		$change->ItemId				 = new ItemIdType();
		$change->ItemId->Id			 = $message_id;
		$change->ItemId->ChangeKey	 = $message_change_key;

		// Build the set item field object and set the item on it.
		$field						 = new SetItemFieldType();
		$field->FieldURI			 = new PathToUnindexedFieldType();
		$field->FieldURI->FieldURI	 = "message:IsRead";
		$field->Message				 = new MessageType();
		$field->Message->IsRead		 = true;

		$change->Updates = new NonEmptyArrayOfItemChangeDescriptionsType();
		$change->Updates->SetItemField[] = $field;
		$request->ItemChanges[]			 = $change;

		$response = $client->UpdateItem($request);
	}

	function move_message($client, $item3, $movet_to_folder_info)
	{
		$request = new MoveItemType();

		$request->ToFolderId = new \jamesiarmes\PhpEws\Type\TargetFolderIdType();
		$request->ToFolderId->FolderId = new \jamesiarmes\PhpEws\Type\FolderIdType();

		$request->ToFolderId->FolderId->Id			 = $movet_to_folder_info['id'];
		$request->ToFolderId->FolderId->ChangeKey	 = $movet_to_folder_info['changekey'];

		$request->ItemIds = new NonEmptyArrayOfBaseItemIdsType();
		$request->ItemIds->ItemId = new ItemIdType();
		$request->ItemIds->ItemId->Id		 = $item3->ItemId->Id;
		$request->ItemIds->ItemId->ChangeKey = $item3->ItemId->ChangeKey;

		$response = $client->MoveItem($request);
	}

	function handle_attachments($client, $attachments, $response_message2)
	{
		$saved_attachments = array();

		$temp_dir = sys_get_temp_dir();

		// Build the request to get the attachments.
		$request3				 = new GetAttachmentType();
		$request3->AttachmentIds = new NonEmptyArrayOfRequestAttachmentIdsType();

		// Iterate over the attachments for the message.
		foreach ($attachments as $attachment_id)
		{
			$id										 = new RequestAttachmentIdType();
			$id->Id									 = $attachment_id;
			$request3->AttachmentIds->AttachmentId[] = $id;
		}

		$response3 = $client->GetAttachment($request3);

		// Iterate over the response messages, printing any error messages or
		// saving the attachments.
		$attachment_response_messages = $response3->ResponseMessages
			->GetAttachmentResponseMessage;
		foreach ($attachment_response_messages as $attachment_response_message)
		{
			// Make sure the request succeeded.
			if ($attachment_response_message->ResponseClass != ResponseClassType::SUCCESS)
			{
				$code	 = $response_message2->ResponseCode;
				$message = $response_message2->MessageText;
				$this->log->error(array(
					'text'	=> 'Failed to search for messages with %1 : %2.',
					'p1'	=> $code,
					'p2'	=> $message,
					'line'	=> __LINE__,
					'file'	=> __FILE__
				));
				continue;
			}

			// Iterate over the file attachments, saving each one.
			$attachments = $attachment_response_message->Attachments
				->FileAttachment;
			foreach ($attachments as $attachment)
			{
				$tmp_name	 = tempnam($temp_dir, "xPortico");
				$handle		 = fopen($tmp_name, "w");
				fwrite($handle, $attachment->Content);
				fclose($handle);

				$saved_attachments[] = array(
					'tmp_name'	 => $tmp_name,
					'name'		 => $attachment->Name,
				);
			}
		}

		return $saved_attachments;
	}
}