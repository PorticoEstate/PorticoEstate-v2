<?php

/**
 * Webhook subscription management
 * Handles CRUD operations for webhook subscriptions
 */
class booking_bowebhook_manager
{
	private $db;
	private $logger;
	private $current_user_id;

	public function __construct()
	{
		$this->db = & $GLOBALS['phpgw']->db;
		$this->logger = CreateObject('phpgwapi.logger')->get_logger('webhook');
		$this->current_user_id = $GLOBALS['phpgw_info']['user']['account_id'];
	}

	/**
	 * Create a new webhook subscription
	 *
	 * @param array $data Subscription data
	 * @return array Result with subscription details or error
	 */
	public function create($data)
	{
		// Validate input
		if (empty($data['resourceType']))
		{
			return array('error' => 'resourceType is required');
		}

		if (empty($data['notificationUrl']))
		{
			return array('error' => 'notificationUrl is required');
		}

		// Validate notification URL (must be HTTPS in production)
		$url_parts = parse_url($data['notificationUrl']);
		if (!$url_parts || !isset($url_parts['scheme']) || !isset($url_parts['host']))
		{
			return array('error' => 'Invalid notificationUrl');
		}

		// Generate unique subscription ID
		$subscriptionId = 'sub_' . $this->generateUuid();

		// Calculate expiration
		$expirationMinutes = isset($data['expirationMinutes']) ? (int)$data['expirationMinutes'] : 43200; // 30 days default
		$expirationMinutes = min($expirationMinutes, 43200); // Max 30 days
		$expiresAt = date('Y-m-d H:i:s', strtotime("+{$expirationMinutes} minutes"));

		// Prepare values
		$resourceType = $this->db->db_addslashes($data['resourceType']);
		$resourceId = isset($data['resourceId']) ? (int)$data['resourceId'] : 'NULL';
		$notificationUrl = $this->db->db_addslashes($data['notificationUrl']);
		$changeTypes = isset($data['changeTypes']) && is_array($data['changeTypes']) 
			? implode(',', $data['changeTypes']) 
			: 'created,updated,deleted';
		$clientState = isset($data['clientState']) ? "'" . $this->db->db_addslashes($data['clientState']) . "'" : 'NULL';
		$secretKey = isset($data['secretKey']) ? "'" . $this->db->db_addslashes($data['secretKey']) . "'" : 'NULL';

		// Insert subscription
		$sql = "INSERT INTO bb_webhook_subscriptions 
				(subscription_id, resource_type, resource_id, notification_url, change_types, 
				 client_state, secret_key, is_active, expires_at, created_by, created_at)
				VALUES (
					'" . $this->db->db_addslashes($subscriptionId) . "',
					'{$resourceType}',
					{$resourceId},
					'{$notificationUrl}',
					'" . $this->db->db_addslashes($changeTypes) . "',
					{$clientState},
					{$secretKey},
					1,
					'" . $this->db->db_addslashes($expiresAt) . "',
					{$this->current_user_id},
					NOW()
				)";

		$this->db->query($sql, __LINE__, __FILE__);

		if ($this->db->affected_rows() > 0)
		{
			$this->logger->info('Webhook subscription created', array(
				'subscription_id' => $subscriptionId,
				'resource_type' => $data['resourceType'],
				'resource_id' => isset($data['resourceId']) ? $data['resourceId'] : null
			));

			return array(
				'success' => true,
				'subscription' => array(
					'subscriptionId' => $subscriptionId,
					'resourceType' => $data['resourceType'],
					'resourceId' => isset($data['resourceId']) ? $data['resourceId'] : null,
					'notificationUrl' => $data['notificationUrl'],
					'changeTypes' => explode(',', $changeTypes),
					'expirationDateTime' => $expiresAt,
					'createdDateTime' => date('Y-m-d H:i:s')
				)
			);
		}
		else
		{
			return array('error' => 'Failed to create subscription');
		}
	}

	/**
	 * Read a subscription by ID
	 *
	 * @param string $subscriptionId Subscription ID
	 * @return array|null Subscription data or null if not found
	 */
	public function read($subscriptionId)
	{
		$sql = "SELECT * FROM bb_webhook_subscriptions 
				WHERE subscription_id = '" . $this->db->db_addslashes($subscriptionId) . "'";

		$this->db->query($sql, __LINE__, __FILE__);

		if ($this->db->next_record())
		{
			return array(
				'subscriptionId' => $this->db->f('subscription_id'),
				'resourceType' => $this->db->f('resource_type'),
				'resourceId' => $this->db->f('resource_id'),
				'notificationUrl' => $this->db->f('notification_url'),
				'changeTypes' => explode(',', $this->db->f('change_types')),
				'clientState' => $this->db->f('client_state'),
				'isActive' => (bool)$this->db->f('is_active'),
				'expiresAt' => $this->db->f('expires_at'),
				'createdBy' => $this->db->f('created_by'),
				'createdAt' => $this->db->f('created_at'),
				'lastNotificationAt' => $this->db->f('last_notification_at'),
				'notificationCount' => $this->db->f('notification_count'),
				'failureCount' => $this->db->f('failure_count')
			);
		}

		return null;
	}

	/**
	 * List all subscriptions
	 *
	 * @param array $filters Optional filters
	 * @return array List of subscriptions
	 */
	public function list_subscriptions($filters = array())
	{
		$sql = "SELECT * FROM bb_webhook_subscriptions WHERE 1=1";

		if (isset($filters['resourceType']))
		{
			$sql .= " AND resource_type = '" . $this->db->db_addslashes($filters['resourceType']) . "'";
		}

		if (isset($filters['resourceId']))
		{
			$sql .= " AND resource_id = " . (int)$filters['resourceId'];
		}

		if (isset($filters['isActive']))
		{
			$sql .= " AND is_active = " . ($filters['isActive'] ? 1 : 0);
		}

		$sql .= " ORDER BY created_at DESC";

		$this->db->query($sql, __LINE__, __FILE__);

		$subscriptions = array();
		while ($this->db->next_record())
		{
			$subscriptions[] = array(
				'subscriptionId' => $this->db->f('subscription_id'),
				'resourceType' => $this->db->f('resource_type'),
				'resourceId' => $this->db->f('resource_id'),
				'notificationUrl' => $this->db->f('notification_url'),
				'changeTypes' => explode(',', $this->db->f('change_types')),
				'isActive' => (bool)$this->db->f('is_active'),
				'expiresAt' => $this->db->f('expires_at'),
				'createdAt' => $this->db->f('created_at'),
				'notificationCount' => $this->db->f('notification_count'),
				'failureCount' => $this->db->f('failure_count')
			);
		}

		return $subscriptions;
	}

	/**
	 * Renew a subscription (extend expiration)
	 *
	 * @param string $subscriptionId Subscription ID
	 * @param int $expirationMinutes New expiration in minutes
	 * @return array Result with updated subscription or error
	 */
	public function renew($subscriptionId, $expirationMinutes = 43200)
	{
		$subscription = $this->read($subscriptionId);

		if (!$subscription)
		{
			return array('error' => 'Subscription not found');
		}

		// Calculate new expiration
		$expirationMinutes = min($expirationMinutes, 43200); // Max 30 days
		$expiresAt = date('Y-m-d H:i:s', strtotime("+{$expirationMinutes} minutes"));

		$sql = "UPDATE bb_webhook_subscriptions 
				SET expires_at = '" . $this->db->db_addslashes($expiresAt) . "'
				WHERE subscription_id = '" . $this->db->db_addslashes($subscriptionId) . "'";

		$this->db->query($sql, __LINE__, __FILE__);

		if ($this->db->affected_rows() > 0)
		{
			$this->logger->info('Webhook subscription renewed', array(
				'subscription_id' => $subscriptionId,
				'new_expiration' => $expiresAt
			));

			$subscription['expiresAt'] = $expiresAt;
			return array(
				'success' => true,
				'subscription' => $subscription
			);
		}

		return array('error' => 'Failed to renew subscription');
	}

	/**
	 * Delete a subscription
	 *
	 * @param string $subscriptionId Subscription ID
	 * @return array Result with success status
	 */
	public function delete($subscriptionId)
	{
		$sql = "DELETE FROM bb_webhook_subscriptions 
				WHERE subscription_id = '" . $this->db->db_addslashes($subscriptionId) . "'";

		$this->db->query($sql, __LINE__, __FILE__);

		if ($this->db->affected_rows() > 0)
		{
			$this->logger->info('Webhook subscription deleted', array(
				'subscription_id' => $subscriptionId
			));

			return array('success' => true);
		}

		return array('error' => 'Subscription not found or already deleted');
	}

	/**
	 * Deactivate a subscription (soft delete)
	 *
	 * @param string $subscriptionId Subscription ID
	 * @return array Result with success status
	 */
	public function deactivate($subscriptionId)
	{
		$sql = "UPDATE bb_webhook_subscriptions 
				SET is_active = 0
				WHERE subscription_id = '" . $this->db->db_addslashes($subscriptionId) . "'";

		$this->db->query($sql, __LINE__, __FILE__);

		if ($this->db->affected_rows() > 0)
		{
			$this->logger->info('Webhook subscription deactivated', array(
				'subscription_id' => $subscriptionId
			));

			return array('success' => true);
		}

		return array('error' => 'Subscription not found');
	}

	/**
	 * Get delivery log for a subscription
	 *
	 * @param string $subscriptionId Subscription ID
	 * @param int $limit Maximum number of records to return
	 * @return array List of delivery log entries
	 */
	public function getDeliveryLog($subscriptionId, $limit = 100)
	{
		$sql = "SELECT * FROM bb_webhook_delivery_log 
				WHERE subscription_id = '" . $this->db->db_addslashes($subscriptionId) . "'
				ORDER BY created_at DESC
				LIMIT " . (int)$limit;

		$this->db->query($sql, __LINE__, __FILE__);

		$log = array();
		while ($this->db->next_record())
		{
			$log[] = array(
				'id' => $this->db->f('id'),
				'changeType' => $this->db->f('change_type'),
				'entityType' => $this->db->f('entity_type'),
				'entityId' => $this->db->f('entity_id'),
				'resourceId' => $this->db->f('resource_id'),
				'httpStatusCode' => $this->db->f('http_status_code'),
				'responseTimeMs' => $this->db->f('response_time_ms'),
				'errorMessage' => $this->db->f('error_message'),
				'createdAt' => $this->db->f('created_at')
			);
		}

		return $log;
	}

	/**
	 * Generate a UUID v4
	 *
	 * @return string UUID
	 */
	private function generateUuid()
	{
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
