<?php

namespace App\modules\property\models;

//use App\traits\SerializableTrait;

/**
 * @OA\Schema(
 *     schema="Ticket",
 *     type="object",
 *     title="Ticket",
 *     description="Expanded Ticket model"
 * )
 * @Exclude
 */
class Ticket
{
	//	use SerializableTrait;
	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $id;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $subject;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $status;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $entry_date;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $modified_date;

	/**
	 * @OA\Property(type="integer")
	 * @Expose
	 */
	public $cat_id;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $external_owner_ssn;

	/**
	 * @OA\Property(type="string")
	 * @Expose
	 */
	public $details;

	private $botts;
	private $status_text = [];
	private $db;

	public function __construct($data = [])
	{
		require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
		if (!empty($data))
		{
			$this->populate($data);
		}

		$this->botts = CreateObject('property.botts');
		$this->status_text = $this->getStatusText();
		$this->db = \App\Database\Db::getInstance();
	}

	public function populate($data)
	{
		$this->id = $data['id'] ?? null;
		$this->subject = $data['subject'] ?? null;
		$this->status = $data['status'] ?? null;
		$this->entry_date = $data['entry_date'] ?? null;
		$this->modified_date = $data['modified_date'] ?? null;
		$this->external_owner_ssn = $data['external_owner_ssn'] ?? null;
		$this->details = $data['details'] ?? null;
		$this->cat_id = $data['cat_id'] ?? null;
	}



	public function serialize()
	{
		$status_text = $this->status_text;
		$db = $this->db;
		$data =  [
			'id' => $this->id,
			'subject' => $db->stripslashes($this->subject),
			'status' => $this->status,
			'status_text' => $status_text[$this->status] ?? '',
			'entry_date' => $this->entry_date,
			'modified_date' => $this->modified_date,
			'external_owner_ssn' => $this->external_owner_ssn,
			'details' => $db->stripslashes($this->details),
			'cat_id' => $this->cat_id,
			'category' => $this->get_category_name($this->cat_id)
		];

		if (empty($data['subject']))
		{

			$data['subject'] = $data['category'];
		}


		return $data;
	}

	function getStatusText()
	{
		return $this->botts->get_status_text();
	}

	function get_category_name($cat_id)
	{
		return $this->botts->get_category_name($cat_id);
	}

	function get_additional_notes()
	{
		return $this->botts->read_additional_notes($this->id);
	}

	public function get_record_history()
	{
		return $this->botts->read_record_history($this->id);
	}


	public function add_comment($content, $user_name, $publish)
	{
		$new_comment = "{$user_name}: $content";
		$historylog	 = CreateObject('property.historylog', 'tts');
		$historylog->add('C', $this->id, $content);
		$_history_id			 = $this->db->get_last_insert_id('fm_tts_history', 'history_id');
		$this->db->query("UPDATE fm_tts_history SET publish = 1 WHERE history_id = $_history_id", __LINE__, __FILE__);
	}
}
