<?php

namespace App\modules\booking\services;

use App\Database\Db;
use App\modules\phpgwapi\services\Log;
use App\modules\phpgwapi\services\Settings;

/**
 * Webhook subscription management service
 * Handles CRUD operations for webhook subscriptions
 */
class WebhookManager
{
	private $db;
	private $log;
	private $account_id;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->log = new Log();
		$this->account_id = Settings::getInstance()->get('account_id');
	}

	/**
	 * Create a new webhook subscription
	 *
	 * @param array $data Subscription data
	 * @return array Result with subscription details or error
	 */
	public function create(array $data): array
	{
		// Default resource_type to 'all' if not provided
		if (empty($data['resource_type']))
		{
			$data['resource_type'] = 'resource';
		}

		// Validate notification URL is required
		if (empty($data['webhook_url']))
		{
			return ['error' => 'webhook_url is required'];
		}

		// Validate notification URL (must be HTTPS in production)
		$url_parts = parse_url($data['webhook_url']);
		if (!$url_parts || !isset($url_parts['scheme']) || !isset($url_parts['host']))
		{
			return ['error' => 'Invalid webhook_url'];
		}

		// Generate unique subscription ID
		$subscription_id = 'sub_' . $this->generateUuid();

		// Calculate expiration
		$expirationMinutes = isset($data['expirationMinutes']) ? (int)$data['expirationMinutes'] : 43200; // 30 days default
		$expirationMinutes = min($expirationMinutes, 43200); // Max 30 days
		$expires_at = date('Y-m-d H:i:s', strtotime("+{$expirationMinutes} minutes"));

		// Prepare values
		$resource_type = $this->db->db_addslashes($data['resource_type']);
		$resource_id = isset($data['calendar_id']) ? (int)$data['calendar_id'] : null;
		$webhook_url = $this->db->db_addslashes($data['webhook_url']);
		$change_types = isset($data['change_types']) && is_array($data['change_types']) 
			? implode(',', $data['change_types']) 
			: 'created,updated,deleted';
		$clientState = isset($data['client_state']) ? $this->db->db_addslashes($data['client_state']) : null;
		$secretKey = isset($data['secretKey']) ? $this->db->db_addslashes($data['secretKey']) : null;

		// Insert subscription
		$sql = "INSERT INTO bb_webhook_subscriptions 
				(subscription_id, resource_type, resource_id, webhook_url, change_types, 
				 client_state, secret_key, is_active, expires_at, created_by, created_at)
				VALUES (
					:subscription_id,
					:resource_type,
					:resource_id,
					:webhook_url,
					:change_types,
					:client_state,
					:secret_key,
					1,
					:expires_at,
					:created_by,
					NOW()
				)";

		$params = [
			'subscription_id' => $subscription_id,
			'resource_type' => $resource_type,
			'resource_id' => $resource_id,
			'webhook_url' => $webhook_url,
			'change_types' => $this->db->db_addslashes($change_types),
			'client_state' => $clientState,
			'secret_key' => $secretKey,
			'expires_at' => $expires_at,
			'created_by' => $this->account_id
		];

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		if ($stmt->rowCount() > 0)
		{
			$this->log->info('Webhook subscription created', [
				'subscription_id' => $subscription_id,
				'resource_type' => $data['resource_type'],
				'resource_id' => $data['resource_id'] ?? null
			]);

			return [
				'success' => true,
				'subscription' => [
					'subscription_id' => $subscription_id,
					'resource_type' => $data['resource_type'],
					'resource_id' => $data['resource_id'] ?? null,
					'webhook_url' => $data['webhook_url'],
					'change_types' => explode(',', $change_types),
					'expires_at' => $expires_at,
					'created_at' => date('Y-m-d H:i:s')
				]
			];
		}
		else
		{
			return ['error' => 'Failed to create subscription'];
		}
	}

	/**
	 * Read a subscription by ID
	 *
	 * @param string $subscription_id Subscription ID
	 * @return array|null Subscription data or null if not found
	 */
	public function read(string $subscription_id): ?array
	{
		$sql = "SELECT * FROM bb_webhook_subscriptions 
				WHERE subscription_id = :subscription_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(['subscription_id' => $this->db->db_addslashes($subscription_id)]);

		if ($row = $stmt->fetch())
		{
			return [
				'subscription_id' => $row['subscription_id'],
				'resource_type' => $row['resource_type'],
				'resource_id' => $row['resource_id'],
				'webhook_url' => $row['webhook_url'],
				'change_types' => explode(',', $row['change_types']),
				'clientState' => $row['client_state'],
				'is_active' => (bool)$row['is_active'],
				'expires_at' => $row['expires_at'],
				'created_by' => $row['created_by'],
				'created_at' => $row['created_at'],
				'last_notification_at' => $row['last_notification_at'],
				'notification_count' => $row['notification_count'],
				'failure_count' => $row['failure_count']
			];
		}

		return null;
	}

	/**
	 * List all subscriptions
	 *
	 * @param array $filters Optional filters
	 * @return array List of subscriptions
	 */
	public function listSubscriptions(array $filters = []): array
	{
		$sql = "SELECT * FROM bb_webhook_subscriptions WHERE 1=1";
		$params = [];

		if (isset($filters['resource_type']))
		{
			$sql .= " AND resource_type = :resource_type";
			$params['resource_type'] = $this->db->db_addslashes($filters['resource_type']);
		}

		if (isset($filters['resource_id']))
		{
			$sql .= " AND resource_id = :resource_id";
			$params['resource_id'] = (int)$filters['resource_id'];
		}

		if (isset($filters['is_active']))
		{
			$sql .= " AND is_active = :is_active";
			$params['is_active'] = $filters['is_active'] ? 1 : 0;
		}

		$sql .= " ORDER BY created_at DESC";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		$subscriptions = [];
		while ($row = $stmt->fetch())
		{
			$subscriptions[] = [
				'subscription_id' => $row['subscription_id'],
				'resource_type' => $row['resource_type'],
				'resource_id' => $row['resource_id'],
				'webhook_url' => $row['webhook_url'],
				'change_types' => explode(',', $row['change_types']),
				'is_active' => (bool)$row['is_active'],
				'expires_at' => $row['expires_at'],
				'created_at' => $row['created_at'],
				'notification_count' => $row['notification_count'],
				'failure_count' => $row['failure_count']
			];
		}

		return $subscriptions;
	}

	/**
	 * Renew a subscription (extend expiration)
	 *
	 * @param string $subscription_id Subscription ID
	 * @param int $expirationMinutes New expiration in minutes
	 * @return array Result with updated subscription or error
	 */
	public function renew(string $subscription_id, int $expirationMinutes = 43200): array
	{
		$subscription = $this->read($subscription_id);

		if (!$subscription)
		{
			return ['error' => 'Subscription not found'];
		}

		// Calculate new expiration
		$expirationMinutes = min($expirationMinutes, 43200); // Max 30 days
		$expires_at = date('Y-m-d H:i:s', strtotime("+{$expirationMinutes} minutes"));

		$sql = "UPDATE bb_webhook_subscriptions 
				SET expires_at = :expires_at
				WHERE subscription_id = :subscription_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'expires_at' => $expires_at,
			'subscription_id' => $this->db->db_addslashes($subscription_id)
		]);

		if ($stmt->rowCount() > 0)
		{
			$this->log->info('Webhook subscription renewed', [
				'subscription_id' => $subscription_id,
				'new_expiration' => $expires_at
			]);

			$subscription['expires_at'] = $expires_at;
			return [
				'success' => true,
				'subscription' => $subscription
			];
		}

		return ['error' => 'Failed to renew subscription'];
	}

	/**
	 * Delete a subscription
	 *
	 * @param string $subscription_id Subscription ID
	 * @return array Result with success status
	 */
	public function delete(string $subscription_id): array
	{
		$sql = "DELETE FROM bb_webhook_subscriptions 
				WHERE subscription_id = :subscription_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(['subscription_id' => $this->db->db_addslashes($subscription_id)]);

		if ($stmt->rowCount() > 0)
		{
			$this->log->info('Webhook subscription deleted', [
				'subscription_id' => $subscription_id
			]);

			return ['success' => true];
		}

		return ['error' => 'Subscription not found or already deleted'];
	}

	/**
	 * Deactivate a subscription (soft delete)
	 *
	 * @param string $subscription_id Subscription ID
	 * @return array Result with success status
	 */
	public function deactivate(string $subscription_id): array
	{
		$sql = "UPDATE bb_webhook_subscriptions 
				SET is_active = 0
				WHERE subscription_id = :subscription_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(['subscription_id' => $this->db->db_addslashes($subscription_id)]);

		if ($stmt->rowCount() > 0)
		{
			$this->log->info('Webhook subscription deactivated', [
				'subscription_id' => $subscription_id
			]);

			return ['success' => true];
		}

		return ['error' => 'Subscription not found'];
	}

	/**
	 * Get delivery log for a subscription
	 *
	 * @param string $subscription_id Subscription ID
	 * @param int $limit Maximum number of records to return
	 * @return array List of delivery log entries
	 */
	public function getDeliveryLog(string $subscription_id, int $limit = 100): array
	{
		$sql = "SELECT * FROM bb_webhook_delivery_log 
				WHERE subscription_id = :subscription_id
				ORDER BY created_at DESC
				LIMIT :limit";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'subscription_id' => $this->db->db_addslashes($subscription_id),
			'limit' => $limit
		]);

		$log = [];
		while ($row = $stmt->fetch())
		{
			$log[] = [
				'id' => $row['id'],
				'change_type' => $row['change_type'],
				'entity_type' => $row['entity_type'],
				'entityId' => $row['entity_id'],
				'resource_id' => $row['resource_id'],
				'httpStatusCode' => $row['http_status_code'],
				'responseTimeMs' => $row['response_time_ms'],
				'errorMessage' => $row['error_message'],
				'created_at' => $row['created_at']
			];
		}

		return $log;
	}

	/**
	 * Generate a UUID v4
	 *
	 * @return string UUID
	 */
	private function generateUuid(): string
	{
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
