<?php

namespace App\modules\booking\services;

use App\Database\Db;
use App\modules\phpgwapi\services\Log;
use App\modules\phpgwapi\controllers\Locations;
use App\modules\phpgwapi\services\ConfigLocation;

/**
 * Webhook notification service
 * Handles delivery of webhooks when entities change
 */
class WebhookNotifier
{
	private $db;
	private $log;
	private $api_key;
	private $webhook_secret;
	private $webhook_enabled;

	public function __construct()
	{
		$this->db = Db::getInstance();
		$this->log = new Log();

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
	 * @param string $entity_type 'event', 'allocation', or 'booking'
	 * @param string $change_type 'created', 'updated', or 'deleted'
	 * @param int $entityId Entity ID
	 * @param array $resource_ids Associated resource IDs
	 */
	public function notifyChange(string $entity_type, string $change_type, int $entityId, array $resource_ids = []): void
	{
		// Check if webhooks are enabled
		if (!$this->webhook_enabled)
		{
			$this->log->debug('Webhooks disabled', [
				'entity_type' => $entity_type,
				'entity_id' => $entityId
			]);
			return;
		}

		// Find active subscriptions matching this change
		$subscriptions = $this->findActiveSubscriptions($entity_type, $resource_ids);

		if (empty($subscriptions))
		{
			$this->log->debug('No active subscriptions found', [
				'entity_type' => $entity_type,
				'entity_id' => $entityId
			]);
			return;
		}

		// Load entity data
		$entity_data = $this->loadEntityData($entity_type, $entityId);

		if (!$entity_data)
		{
			$this->log->warn('Entity not found for webhook', [
				'entity_type' => $entity_type,
				'entity_id' => $entityId
			]);
			return;
		}

		// Send webhook to each subscription
		foreach ($subscriptions as $subscription)
		{
			try
			{
				// Check if this change type is subscribed
				$subscribedTypes = explode(',', $subscription['change_types']);
				if (!in_array($change_type, $subscribedTypes))
				{
					continue;
				}

				// Build notification payload
				$payload = $this->buildNotificationPayload(
					$subscription,
					$change_type,
					$entity_type,
					$entity_data,
					$resource_ids
				);

				// Deliver webhook
				$this->deliverWebhook($subscription, $payload, $entity_type, $entityId, $change_type);
			}
			catch (\Exception $e)
			{
				$this->log->error('Webhook delivery failed', [
					'subscription_id' => $subscription['subscription_id'],
					'entity_type' => $entity_type,
					'entity_id' => $entityId,
					'change_type' => $change_type,
					'error' => $e->getMessage()
				]);
			}
		}
	}

	/**
	 * Find active subscriptions matching the change
	 */
	private function findActiveSubscriptions(string $entity_type, array $resource_ids): array
	{
		// Build SQL to find matching subscriptions
		if (!empty($resource_ids))
		{
			$resource_idList = implode(',', array_map('intval', $resource_ids));
			$sql = "SELECT * FROM bb_webhook_subscriptions 
					WHERE is_active = 1 
					AND expires_at > NOW()
					AND entity_type IN (:entity_type, 'all')
					AND (resource_id IS NULL OR resource_id IN ({$resource_idList}))";
		}
		else
		{
			$sql = "SELECT * FROM bb_webhook_subscriptions 
					WHERE is_active = 1 
					AND expires_at > NOW()
					AND entity_type IN (:entity_type, 'all')
					AND resource_id IS NULL";
		}

		$stmt = $this->db->prepare($sql);
		$stmt->execute(['entity_type' => $entity_type]);

		$subscriptions = [];
		while ($row = $stmt->fetch())
		{
			$subscriptions[] = [
				'subscription_id' => $row['subscription_id'],
				'webhook_url' => $row['webhook_url'],
				'change_types' => $row['change_types'],
				'client_state' => $row['client_state'],
				'secret_key' => $row['secret_key'],
				'resource_id' => $row['resource_id']
			];
		}

		return $subscriptions;
	}

	/**
	 * Load entity data from database
	 */
	private function loadEntityData(string $entity_type, int $entityId): ?array
	{
		$data = null;
		
		switch ($entity_type)
		{
			case 'event':
				$bo = CreateObject('booking.boevent');
				$data = $bo->read_single($entityId);
				break;

			case 'allocation':
				$bo = CreateObject('booking.boallocation');
				$data = $bo->read_single($entityId);
				break;

			case 'booking':
				$bo = CreateObject('booking.bobooking');
				$data = $bo->read_single($entityId);
				break;

			default:
				return null;
		}
		
		// Add timezone information to datetime fields
		if ($data && isset($data['from_']))
		{
			$data['from_'] = $this->addTimezoneToDatetime($data['from_']);
		}
		if ($data && isset($data['to_']))
		{
			$data['to_'] = $this->addTimezoneToDatetime($data['to_']);
		}
		
		return $data;
	}
	
	/**
	 * Add timezone information to datetime string
	 */
	private function addTimezoneToDatetime(string $datetime): string
	{
		// If datetime already has timezone info, return as is
		if (strpos($datetime, '+') !== false || strpos($datetime, 'Z') !== false)
		{
			return $datetime;
		}
		
		// Parse the datetime and add Europe/Oslo timezone
		try
		{
			$dt = new \DateTime($datetime, new \DateTimeZone('Europe/Oslo'));
			// Return in ISO 8601 format with timezone
			return $dt->format('Y-m-d\TH:i:sP');
		}
		catch (\Exception $e)
		{
			// If parsing fails, return original
			return $datetime;
		}
	}

	/**
	 * Build notification payload
	 */
	private function buildNotificationPayload(
		array $subscription,
		string $change_type,
		string $entity_type,
		array $entity_data,
		array $resource_ids
	): array
	{
		return [
			'value' => [
				[
					'subscription_id' => $subscription['subscription_id'],
					'change_type' => $change_type,
					'entity_type' => $entity_type,
					'resource_id' => $subscription['resource_id'],
					'entity_type' => $entity_type,
					'entityId' => $entity_data['id'],
					'entity_data' => $entity_data,
					'clientState' => $subscription['client_state'],
					'timestamp' => date('Y-m-d\TH:i:s\Z'),
					'resources' => $resource_ids
				]
			]
		];
	}

	/**
	 * Deliver webhook via HTTP POST
	 */
	private function deliverWebhook(
		array $subscription,
		array $payload,
		string $entity_type,
		int $entityId,
		string $change_type
	): void
	{
		$startTime = microtime(true);
		$url = $subscription['webhook_url'];
		$payloadJson = json_encode($payload);

		// Generate HMAC signature
		$signature = null;
		if (!empty($subscription['secret_key']))
		{
			$signature = hash_hmac('sha256', $payloadJson, $subscription['secret_key']);
		}

		// Prepare HTTP headers (following OutlookHelper pattern)
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json',
			'User-Agent: PorticoEstate-Webhook/1.0'
		];

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
//_debug_array($payloadJson);die();
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
			$change_type,
			$entity_type,
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

			$this->log->info('Webhook delivered successfully', [
				'subscription_id' => $subscription['subscription_id'],
				'entity_type' => $entity_type,
				'entity_id' => $entityId,
				'http_code' => $httpCode,
				'response_time_ms' => round($responseTime)
			]);
		}
		else
		{
			$this->updateSubscriptionFailure($subscription['subscription_id']);

			$this->log->warn('Webhook delivery failed', [
				'subscription_id' => $subscription['subscription_id'],
				'entity_type' => $entity_type,
				'entity_id' => $entityId,
				'http_code' => $httpCode,
				'error' => $error
			]);
		}
	}

	/**
	 * Log webhook delivery attempt
	 */
	private function logDelivery(
		string $subscription_id,
		string $change_type,
		string $entity_type,
		int $entityId,
		?int $resource_id,
		int $httpCode,
		float $responseTime,
		?string $error
	): void
	{
		$sql = "INSERT INTO bb_webhook_delivery_log 
				(subscription_id, change_type, entity_type, entity_id, resource_id, 
				 http_status_code, response_time_ms, error_message, created_at)
				VALUES (:subscription_id, :change_type, :entity_type, :entity_id, :resource_id, 
						:http_status_code, :response_time_ms, :error_message, NOW())";

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			'subscription_id' => $subscription_id,
			'change_type' => $change_type,
			'entity_type' => $entity_type,
			'entity_id' => $entityId,
			'resource_id' => $resource_id,
			'http_status_code' => $httpCode,
			'response_time_ms' => round($responseTime),
			'error_message' => $error
		]);
	}

	/**
	 * Update subscription after successful delivery
	 */
	private function updateSubscriptionSuccess(string $subscription_id): void
	{
		$sql = "UPDATE bb_webhook_subscriptions 
				SET last_notification_at = NOW(),
					notification_count = notification_count + 1
				WHERE subscription_id = :subscription_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(['subscription_id' => $subscription_id]);
	}

	/**
	 * Update subscription after failed delivery
	 */
	private function updateSubscriptionFailure(string $subscription_id): void
	{
		$sql = "UPDATE bb_webhook_subscriptions 
				SET failure_count = failure_count + 1
				WHERE subscription_id = :subscription_id";

		$stmt = $this->db->prepare($sql);
		$stmt->execute(['subscription_id' => $subscription_id]);
	}
}
