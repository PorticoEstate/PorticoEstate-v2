<?php
/*	 * ************************************************************************\
	 * phpGroupWare - Messenger                                                 *
	 * http://www.phpgroupware.org                                              *
	 * This application written by Joseph Engo <jengo@phpgroupware.org>         *
	 * --------------------------------------------                             *
	 * Funding for this program was provided by http://www.checkwithmom.com     *
	 * --------------------------------------------                             *
	 *  This program is free software; you can redistribute it and/or modify it *
	 *  under the terms of the GNU General Public License as published by the   *
	 *  Free Software Foundation; either version 2 of the License, or (at your  *
	 *  option) any later version.                                              *
	  \************************************************************************* */

/* $Id$ */

use App\Database\Db;
use App\traits\DbRowTrait;
use PDO;


class messenger_somessenger extends messenger_somessenger_
{
	use DbRowTrait;

	var $db, $connected, $like;

	/** @var string[] columns allowed in ORDER BY, to avoid injecting arbitrary SQL via $params['order'] */
	private static $sortable_columns = array('message_id', 'message_from', 'message_status', 'message_date', 'message_subject');

	function __construct()
	{
		parent::__construct();
		$this->db = Db::getInstance();
		$this->like = $this->db->like;
		$this->connected = true;
	}

	function update_message_status($status, $message_id)
	{
		$stmt = $this->db->prepare('UPDATE phpgw_messenger_messages SET message_status = :status'
			. ' WHERE message_id = :message_id AND message_owner = :owner');
		$stmt->execute([
			':status' => $status,
			':message_id' => $message_id,
			':owner' => $this->owner,
		]);
	}

	function read_inbox($params)
	{
		$filtermethod = '';
		$queryParams = [':owner' => $this->owner];

		if (!empty($params['query']))
		{
			$filtermethod = " AND (message_subject {$this->like} :search1 OR message_content {$this->like} :search2)";
			$queryParams[':search1'] = '%' . $params['query'] . '%';
			$queryParams[':search2'] = '%' . $params['query'] . '%';
		}
		if (!empty($params['status']) && in_array($params['status'], array('N', 'R', 'O', 'F'), true))
		{
			$filtermethod .= " AND message_status = :status";
			$queryParams[':status'] = strtoupper($params['status']);
		}
		$sortmethod = '';
		if (!empty($params['order']) && in_array($params['order'], self::$sortable_columns, true))
		{
			$sort = strtoupper($params['sort']) === 'DESC' ? 'DESC' : 'ASC';
			$sortmethod = " ORDER BY {$params['order']} {$sort}";
		}

		$sql = "SELECT * FROM phpgw_messenger_messages WHERE message_owner = :owner{$filtermethod}{$sortmethod}";

		$this->db->limit_query_with_params($sql, $queryParams, (int)$params['start'], __LINE__, __FILE__);

		$messages = array();
		foreach ($this->db->resultSet as $row)
		{
			$messages[] = array(
				'id' => $row['message_id'],
				'from' => $row['message_from'],
				'status' => $row['message_status'],
				'date' => $row['message_date'],
				'subject' => $this->dbStrip($row['message_subject'])
			);
		}
		return $messages;
	}

	function read_message($message_id)
	{
		$stmt = $this->db->prepare('SELECT * FROM phpgw_messenger_messages'
			. ' WHERE message_id = :message_id AND message_owner = :owner');
		$stmt->execute([
			':message_id' => $message_id,
			':owner' => $this->owner,
		]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		$message = array(
			'id' => $row['message_id'],
			'from' => $row['message_from'],
			'status' => $row['message_status'],
			'date' => $row['message_date'],
			'subject' => $this->dbStrip($row['message_subject']),
			'content' => $this->dbStrip($row['message_content'])
		);
		if ($row['message_status'] == 'N')
		{
			$this->update_message_status('O', $message_id);
		}
		return $message;
	}

	function send_message($message, $global_message = False)
	{
		if ($global_message)
		{
			$this->owner = -1;
		}

		if (!preg_match('/^[0-9]+$/', $message['to']))
		{
			$message['to'] = $this->accounts_obj->name2id($message['to']);
		}

		$stmt = $this->db->prepare('INSERT INTO phpgw_messenger_messages'
			. ' (message_owner, message_from, message_status, message_date, message_subject, message_content)'
			. ' VALUES (:to, :from, :status, :date, :subject, :content)');
		$stmt->execute([
			':to' => $message['to'],
			':from' => $this->owner,
			':status' => 'N',
			':date' => time(),
			':subject' => $message['subject'],
			':content' => $message['content'],
		]);
	}

	function total_messages($extra_where_clause = '')
	{
		$stmt = $this->db->prepare('SELECT count(*) as cnt FROM phpgw_messenger_messages'
			. ' WHERE message_owner = :owner ' . $extra_where_clause);
		$stmt->execute([':owner' => $this->owner]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row['cnt'];
	}

	function delete_message($message_id)
	{
		$stmt = $this->db->prepare('DELETE FROM phpgw_messenger_messages'
			. ' WHERE message_id = :message_id AND message_owner = :owner');
		$stmt->execute([
			':message_id' => $message_id,
			':owner' => $this->owner,
		]);
	}

	function transaction_begin()
	{
		$this->db->transaction_begin();
	}

	function transaction_commit()
	{
		$this->db->transaction_commit();
	}
}
