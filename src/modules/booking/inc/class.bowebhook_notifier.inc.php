<?php

use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\ConfigLocation;

/**
 * Webhook notification service
 * Handles delivery of webhooks when entities change
 */
class booking_bowebhook_notifier
{
	private $db;
	private $logger;
	private $api_key;
	private $webhook_secret;
	private $webhook_enabled;

	public function __construct()
	{
		$this->db = & $GLOBALS['phpgw']->db;
		$this->logger = CreateObject('phpgwapi.logger')->get_logger('webhook');

		// Load configuration (same pattern as OutlookHelper)
		$location_obj = new Locations();
		$location_id = $location_obj->get_id('booking', 'run');
		$custom_config_data = (new ConfigLocation($location_id))->read();

		if (!empty($custom_config_data['Outlook']['api_key']))
		{
			$this->api_key = $custom_config_data['Outlook']['api_key'];
		}
		if (!empty($custom_config_data['Outlook']['webhook_secret']))
		{
			$this->webhook_secret = $custom_config_data['Outlook']['webhook_secret'];
		}
		if (isset($custom_config_data['Outlook']['webhook_enabled']))
		{
			$this->webhook_enabled = (bool)$custom_config_data['Outlook']['webhook_enabled'];
		}
		else
		{
			$this->webhook_enabled = true; // Default to enabled
		}
	}

	/**
	 * Notify subscribers about entity changes
	 *
	 * @param string $entityType 'event', 'allocation', or 'booking'
	 * @param string $changeType 'created', 'updated', or 'deleted'
	 * @param int $entityId Entity ID
	 * @param array $resourceIds Associated resource IDs
	 */
	public function notifyChange($entityType, $changeType, $entityId, $resourceIds = array())
	{
		// Check if webhooks are enabled
		if (!$this->webhook_enabled)
		{
			$this->logger->debug('Webhooks disabled', array(
				'entity_type' => $entityType,
				'entity_id' => $entityId
			));
			return;
		}

		// Find active subscriptions matching this change
		$subscriptions = $this->findActiveSubscriptions($entityType, $resourceIds);

		if (empty($subscriptions))
		{
			$this->logger->debug('No active subscriptions found', array(
				'entity_type' => $entityType,
				'entity_id' => $entityId
			));
			return;
		}

		// Load entity data
		$entityData = $this->loadEntityData($entityType, $entityId);

		if (!$entityData)
		{
			$this->logger->warning('Entity not found for webhook', array(
				'entity_type' => $entityType,
				'entity_id' => $entityId
			));
			return;
		}

		// Send webhook to each subscription
		foreach ($subscriptions as $subscription)
		{
			try
			{
				// Check if this change type is subscribed
				$subscribedTypes = explode(',', $subscription['change_types']);
				if (!in_array($changeType, $subscribedTypes))
				{
					continue;
				}

				// Build notification payload
				$payload = $this->buildNotificationPayload(
					$subscription,
					$changeType,
					$entityType,
					$entityData,
					$resourceIds
				);

				// Deliver webhook
				$this->deliverWebhook($subscription, $payload, $entityType, $entityId, $changeType);
			}
			catch (Exception $e)
			{
				$this->logger->error('Webhook delivery failed', array(
					'subscription_id' => $subscription['subscription_id'],
					'entity_type' => $entityType,
					'entity_id' => $entityId,
					'error' => $e->getMessage()
				));
			}
		}
	}

	/**
	 * Find active subscriptions matching the change
	 */
	private function findActiveSubscriptions($entityType, $resourceIds)
	{
		// Build SQL query
		$sql = "SELECT * FROM bb_webhook_subscriptions 
				WHERE is_active = 1 
				AND expires_at > " . $this->db->db_addslashes(date('Y-m-d H:i:s')) . "
				AND resource_type IN ('" . $this->db->db_addslashes($entityType) . "', 'all')";

		// If specific resources, include those subscriptions
		if (!empty($resourceIds))
		{
			$resource_ids_safe = array_map(array($this->db, 'db_addslashes'), $resourceIds);
			$sql .= " AND (resource_id IS NULL OR resource_id IN (" . implode(',', $resource_ids_safe) . "))";
		}
		else
		{
			$sql .= " AND resource_id IS NULL";
		}

		$this->db->query($sql, __LINE__, __FILE__);

		$subscriptions = array();
		while ($this->db->next_record())
		{
			$subscriptions[] = array(
				'subscription_id' => $this->db->f('subscription_id'),
				'notification_url' => $this->db->f('notification_url'),
				'change_types' => $this->db->f('change_types'),
				'client_state' => $this->db->f('client_state'),
				'secret_key' => $this->db->f('secret_key'),
				'resource_id' => $this->db->f('resource_id')
			);
		}

		return $subscriptions;
	}

	/**
	 * Load entity data from database
	 */
	private function loadEntityData($entityType, $entityId)
	{
		switch ($entityType)
		{
			case 'event':
				$bo = CreateObject('booking.boevent');
				return $bo->read_single($entityId);

			case 'allocation':
				$bo = CreateObject('booking.boallocation');
				return $bo->read_single($entityId);

			case 'booking':
				$bo = CreateObject('booking.bobooking');
				return $bo->read_single($entityId);

			default:
				return null;
		}
	}

	/**
	 * Build notification payload
	 */
	private function buildNotificationPayload($subscription, $changeType, $entityType, $entityData, $resourceIds)
	{
		return array(
			'value' => array(
				array(
					'subscriptionId' => $subscription['subscription_id'],
					'changeType' => $changeType,
					'resourceType' => $entityType,
					'resourceId' => $subscription['resource_id'],
					'entityType' => $entityType,
					'entityId' => $entityData['id'],
					'entityData' => $entityData,
					'clientState' => $subscription['client_state'],
					'timestamp' => date('c'),
					'resources' => $resourceIds
				)
			)
		);
	}

	/**
	 * Deliver webhook via HTTP POST
	 */
	private function deliverWebhook($subscription, $payload, $entityType, $entityId, $changeType)
	{
		$startTime = microtime(true);
		$url = $subscription['notification_url'];
		$payloadJson = json_encode($payload);

		// Generate HMAC signature
		$signature = null;
		if (!empty($subscription['secret_key']))
		{
			$signature = hash_hmac('sha256', $payloadJson, $subscription['secret_key']);
		}

		// Prepare HTTP headers (following OutlookHelper pattern)
		$headers = array(
			'Content-Type: application/json',
			'Accept: application/json',
			'User-Agent: PorticoEstate-Webhook/1.0'
		);

		// Add API key if configured
		if (!empty($this->api_key))
		{
			$headers[] = 'X-API-Key: ' . $this->api_key;
		}

		// Add HMAC signature if secret configured
		if ($signature)
		{
			$headers[] = 'X-Booking-Signature: sha256=' . $signature;
		}

		// Send HTTP POST request (following OutlookHelper pattern)
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		// Disable proxy for internal Docker communication (same as OutlookHelper)
		curl_setopt($ch, CURLOPT_PROXY, '');
		curl_setopt($ch, CURLOPT_NOPROXY, 'portico_outlook,localhost,127.0.0.1');

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		$responseTime = (microtime(true) - $startTime) * 1000; // Convert to ms

		// Log delivery
		$this->logDelivery(
			$subscription['subscription_id'],
			$changeType,
			$entityType,
			$entityId,
			$subscription['resource_id'],
			$httpCode,
			$responseTime,
			$error ?: null
		);

		// Update subscription stats
		if ($httpCode >= 200 && $httpCode < 300)
		{
			$this->updateSubscriptionSuccess($subscription['subscription_id']);

			$this->logger->info('Webhook delivered successfully', array(
				'subscription_id' => $subscription['subscription_id'],
				'entity_type' => $entityType,
				'entity_id' => $entityId,
				'http_code' => $httpCode,
				'response_time_ms' => round($responseTime)
			));
		}
		else
		{
			$this->updateSubscriptionFailure($subscription['subscription_id']);

			$this->logger->warning('Webhook delivery failed', array(
				'subscription_id' => $subscription['subscription_id'],
				'entity_type' => $entityType,
				'entity_id' => $entityId,
				'http_code' => $httpCode,
				'error' => $error
			));
		}
	}

	/**
	 * Log webhook delivery attempt
	 */
	private function logDelivery($subscriptionId, $changeType, $entityType, $entityId, $resourceId, $httpCode, $responseTime, $error)
	{
		$values = array(
			$this->db->db_addslashes($subscriptionId),
			$this->db->db_addslashes($changeType),
			$this->db->db_addslashes($entityType),
			(int)$entityId,
			$resourceId ? (int)$resourceId : 'NULL',
			$httpCode ? (int)$httpCode : 'NULL',
			round($responseTime),
			$error ? "'" . $this->db->db_addslashes($error) . "'" : 'NULL'
		);

		$sql = "INSERT INTO bb_webhook_delivery_log 
				(subscription_id, change_type, entity_type, entity_id, resource_id, 
				 http_status_code, response_time_ms, error_message, created_at)
				VALUES ('" . implode("', '", array_slice($values, 0, 3)) . "', " 
				. $values[3] . ", " . $values[4] . ", " . $values[5] . ", " 
				. $values[6] . ", " . $values[7] . ", NOW())";

		$this->db->query($sql, __LINE__, __FILE__);
	}

	/**
	 * Update subscription after successful delivery
	 */
	private function updateSubscriptionSuccess($subscriptionId)
	{
		$sql = "UPDATE bb_webhook_subscriptions 
				SET last_notification_at = NOW(),
					notification_count = notification_count + 1
				WHERE subscription_id = '" . $this->db->db_addslashes($subscriptionId) . "'";

		$this->db->query($sql, __LINE__, __FILE__);
	}

	/**
	 * Update subscription after failed delivery
	 */
	private function updateSubscriptionFailure($subscriptionId)
	{
		$sql = "UPDATE bb_webhook_subscriptions 
				SET failure_count = failure_count + 1
				WHERE subscription_id = '" . $this->db->db_addslashes($subscriptionId) . "'";

		$this->db->query($sql, __LINE__, __FILE__);
	}
}
