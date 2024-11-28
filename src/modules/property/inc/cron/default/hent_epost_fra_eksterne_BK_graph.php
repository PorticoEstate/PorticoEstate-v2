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
 * example cron : /usr/bin/php -q /var/www/Api/src/modules/property/inc/cron/cron.php default hent_epost_fra_eksterne_BK_graph

 * @package property
 */
include_class('property', 'cron_parent', 'inc/cron/');

use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAuthenticationProvider;
use Microsoft\Graph\GraphRequestAdapter;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\Generated\Users\Item\MailFolders\Item\Messages\MessagesRequestBuilderGetRequestConfiguration;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Users\Item\Messages\Item\Move\MovePostRequestBody;
use Microsoft\Kiota\Abstractions\ApiException;

class hent_epost_fra_eksterne_BK_graph extends property_cron_parent
{

	private $graphServiceClient;
	private $userPrincipalName;
	private $config;
	private $items_to_move = array();

	public function __construct()
	{
		parent::__construct();

		$this->function_name = get_class($this);
		$this->sub_location = lang('property');
		$this->function_msg = 'Hent epost fra eksterne';
		$this->join = $this->db->join;

		$this->config = CreateObject('admin.soconfig', $this->location_obj->get_id('property', '.admin'))->read();
		$this->userPrincipalName = $this->config['xPortico']['mailbox'];

		$this->initializeGraph();
	}


	private function initializeGraph()
	{
		//https://github.com/microsoftgraph/msgraph-sdk-php/blob/main/docs/Examples.md
		//https://learn.microsoft.com/en-us/graph/tutorials/php?tabs=aad&tutorial-step=3
		//https://github.com/microsoftgraph/msgraph-sdk-php/issues/1483

		$tenantId = $this->config['xPortico']['tenant_id'];
		$clientId = $this->config['xPortico']['client_id'];
		$clientSecret = $this->config['xPortico']['client_secret'];
		$this->userPrincipalName = $this->config['xPortico']['mailbox'];

		$tokenRequestContext = new ClientCredentialContext(
			$tenantId,
			$clientId,
			$clientSecret
		);

		$authProvider = new GraphPhpLeagueAuthenticationProvider($tokenRequestContext);

		// Create HTTP client with a Guzzle config to specify proxy
		if (!empty($this->serverSettings['httpproxy_server']))
		{
			$guzzleConfig = [
				"proxy" => "{$this->serverSettings['httpproxy_server']}:{$this->serverSettings['httpproxy_port']}"
			];
		}
		else
		{
			$guzzleConfig = array();
		}

		$httpClient = GraphClientFactory::createWithConfig($guzzleConfig);
		$requestAdapter = new GraphRequestAdapter($authProvider, $httpClient);
		$this->graphServiceClient = GraphServiceClient::createWithRequestAdapter($requestAdapter);
		/*
		try
		{
			$user = $this->graphServiceClient->users()->byUserId($this->userPrincipalName)->get()->wait();
			_debug_array("Hello, I am {$user->getGivenName()}");
		}
		catch (ApiException $ex)
		{
			echo $ex->getError()->getMessage();
		}
*/
	}


	public function execute()
	{
		$start = time();
		$this->process_messages();
		$msg = 'Tidsbruk: ' . (time() - $start) . ' sekunder';
		$this->cron_log($msg);
		echo "$msg\n";
		$this->receipt['message'][] = array('msg' => $msg);
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

	private function process_messages()
	{
		$folderName = 'Portico_Leverador-meldinger';
		$folderId = $this->findFolderId($folderName);

		if (!$folderId)
		{
			$this->log->error("Folder '{$folderName}' not found.");
			return;
		}

		$messages = $this->getUnreadMessages($folderId);

		foreach ($messages->getValue() as $message)
		{
			$this->handleMessage($message);
		}

		$this->moveProcessedMessages();
	}

	private function findFolderId($folderName, $parentFolderId = null)
	{
		$folderId = null;

		// Get the folders at the current level
		if ($parentFolderId === null)
		{
			$folders = $this->graphServiceClient->users()->byUserId($this->userPrincipalName)->mailFolders()->get()->wait();
		}
		else
		{
			$folders = $this->graphServiceClient->users()->byUserId($this->userPrincipalName)->mailFolders()->byMailFolderId($parentFolderId)->childFolders()->get()->wait();
		}

		// Iterate through the folders
		foreach ($folders->getValue() as $folder)
		{
			$DisplayName = $folder->getDisplayName();
			if ($DisplayName == $folderName)
			{
				$folderId = $folder->getId();
				return $folderId;
			}

			// Recursively search in child folders
			$folderId = $this->findFolderId($folderName, $folder->getId());
			if ($folderId !== null)
			{
				return $folderId;
			}
		}

		return $folderId;
	}

	private function getUnreadMessages($folderId)
	{
		$requestConfig = new MessagesRequestBuilderGetRequestConfiguration(
			queryParameters: MessagesRequestBuilderGetRequestConfiguration::createQueryParameters(
				select: ['subject', 'body', 'from', 'isRead']
				//				top: 10
			),
			headers: ['Prefer' => 'outlook.body-content-type=text']
		);
		$messages = $this->graphServiceClient->users()->byUserId($this->userPrincipalName)->mailFolders()->byMailFolderId($folderId)->messages()->get($requestConfig)->wait();

		return $messages;
	}

	private function handleMessage($message)
	{
		$subject = $message->getSubject();
		$body = $message->getBody()->getContent();

		$target = array();

		if (preg_match("/^ISS:/", $subject))
		{
			$ticket_id = $this->create_ticket($subject, $body);
			if ($ticket_id)
			{
				$this->receipt['message'][] = array('msg' => "Melding #{$ticket_id} er opprettet");
				$target['type'] = 'fmticket';
				$target['id'] = $ticket_id;
			}
		}
		elseif (preg_match("/^Kvittering status:/", $subject))
		{
			$order_id = $this->set_order_status($subject, $body, $message->getFrom()->getEmailAddress()->getAddress());
			if ($order_id)
			{
				$target['type'] = 'workorder';
				$target['id'] = $order_id;
				$this->receipt['message'][] = array('msg' => "Status for ordre #{$order_id} er oppdatert");
			}
		}
		elseif (preg_match("/^ISS vedlegg:/", $subject))
		{
			$ticket_id = $this->get_ticket($subject);
			if ($ticket_id)
			{
				$target['type'] = 'fmticket';
				$target['id'] = $ticket_id;
			}
		}
		elseif (preg_match("/\[PorticoTicket/", $subject))
		{
			preg_match_all("/\[[^\]]*\]/", $subject, $matches);
			$identificator_str = trim($matches[0][0], "[]");
			$identificator_arr = explode("::", $identificator_str);

			$sender = $message->getFrom()->getEmailAddress()->getAddress();
			$ticket_id = $this->update_external_communication($identificator_arr, $body, $sender);

			if ($ticket_id)
			{
				$target['type'] = 'fmticket';
				$target['id'] = $ticket_id;
			}
		}

		if ($target)
		{
			$this->items_to_move[] = $message->getId();
			$this->handleAttachments($message, $target);
		}
	}

	private function add_attachment_to_target($target, $attachment)
	{
		$bofiles = CreateObject('property.bofiles');

		$file_name = str_replace(array('/', ' ', '..'), array('_', '_', '.'), $attachment['name']);

		if ($file_name && $target['id'])
		{
			$to_file = "{$bofiles->fakebase}/{$target['type']}/{$target['id']}/{$file_name}";

			if ($bofiles->vfs->file_exists(array(
				'string' => $to_file,
				'relatives' => array(RELATIVE_NONE)
			)))
			{
				$this->receipt['error'][] = array('msg' => lang('This file already exists !'));
			}
			else
			{
				$bofiles->create_document_dir("{$target['type']}/{$target['id']}");
				$bofiles->vfs->override_acl = 1;

				if (!$bofiles->vfs->cp(array(
					'from' => $attachment['tmp_name'],
					'to' => $to_file,
					'relatives' => array(RELATIVE_NONE | VFS_REAL, RELATIVE_ALL)
				)))
				{
					$this->receipt['error'][] = array('msg' => lang('Failed to upload file !'));
				}
				else
				{
					$this->receipt['message'][] = array('msg' => lang('File %1 has been added', $file_name));
				}
				$bofiles->vfs->override_acl = 0;
			}
		}
	}


	private function handleAttachments($message, $target)
	{
		//https://learn.microsoft.com/en-us/graph/api/message-list-attachments?view=graph-rest-1.0&tabs=php

		$attachments = $this->graphServiceClient->users()->byUserId($this->userPrincipalName)->messages()->byMessageId($message->getId())->attachments()->get()->wait();
		foreach ($attachments->getValue() as $attachment)
		{

			if ($attachment->getIsInline())
			{
				continue;
			}

			// Get the attachment content
			//https://learn.microsoft.com/en-us/graph/api/attachment-get?view=graph-rest-1.0&tabs=php
			$content = $this->graphServiceClient->users()->byUserId($this->userPrincipalName)
				->messages()->byMessageId($message->getId())
				->attachments()->byAttachmentId($attachment->getId())->get()->wait()
				->getContentBytes()
				->getContents();

			$tempFile = tempnam(sys_get_temp_dir(), "attachment");
			file_put_contents($tempFile, base64_decode($content));

			$this->add_attachment_to_target($target, array(
				'tmp_name' => $tempFile,
				'name' => $attachment->getName()
			));

			unlink($tempFile);
		}
	}

	private function moveProcessedMessages()
	{
		$destinationFolderId = $this->findFolderId('ImportertTilDatabase');

		foreach ($this->items_to_move as $messageId)
		{
			// Mark message as read
			$requestBody = new Message();
			$requestBody->setIsRead(true);

			$result = $this->graphServiceClient->users()->byUserId($this->userPrincipalName)->messages()->byMessageId($messageId)->patch($requestBody)->wait();

			// move message to another folder
			$requestBody = new MovePostRequestBody();
			$requestBody->setDestinationId($destinationFolderId);

			$result = $this->graphServiceClient->users()->byUserId($this->userPrincipalName)->messages()->byMessageId($messageId)->move()->post($requestBody)->wait();
		}

		$this->items_to_move = array();
	}


	function update_external_communication($identificator_arr, $body, $sender)
	{
		$ticket_id	 = (int)$identificator_arr[1];
		$msg_id		 = (int)$identificator_arr[2];

		if (!$msg_id)
		{
			return $ticket_id;
		}
		$soexternal = createObject('property.soexternal_communication');

		$message_arr = explode('========', $body);

		$message	 = Sanitizer::clean_value($message_arr[0]);
		$normalizedString = trim(str_replace(array("\\r\\n"), "\n", $message), "\n");

		if ($soexternal->add_msg($msg_id, $normalizedString, $sender))
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
}
