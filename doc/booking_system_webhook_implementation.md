# Booking System Webhook Implementation Guide

## Overview

This document provides a detailed plan for implementing webhooks in the PorticoEstate booking system to enable real-time synchronization with the calendar bridge. The implementation will:

- **Use normal session login and handling** - No separate authentication mechanism needed
- **Send webhooks immediately** when events/allocations/bookings are created, edited, or deleted
- **Use PHP-FPM for async processing** - Send payloads without blocking the main process
- **Avoid database triggers** - Integrate webhook calls directly into business logic
- **Support polling fallback** - Calendar bridge can poll via cron if webhooks fail

The architecture follows proven patterns from Microsoft Graph webhooks while being adapted to the existing PorticoEstate infrastructure.

---

## Table of Contents

1. [Webhook Architecture Overview](#webhook-architecture-overview)
2. [Implementation Strategy](#implementation-strategy)
3. [Code Structure and Placement](#code-structure-and-placement)
4. [Implementation Phases](#implementation-phases)
5. [API Specifications](#api-specifications)
6. [Security Requirements](#security-requirements)
7. [Testing Strategy](#testing-strategy)
8. [Monitoring and Troubleshooting](#monitoring-and-troubleshooting)

---

## 1. Webhook Architecture Overview

### Design Principles

1. **No Database Triggers** - Webhooks sent directly from business logic layer
2. **Session-Based Authentication** - Use existing login/session handling
3. **Async Processing via PHP-FPM** - Non-blocking webhook delivery using fastcgi_finish_request()
4. **Polling Fallback** - Calendar bridge can poll if webhooks fail
5. **Idempotent Processing** - Support duplicate notifications safely

### System Flow

```text
User Action (Create/Update/Delete Event/Allocation/Booking)
    ↓
Business Logic Layer (boevent/boallocation/bobooking)
    ↓
Save to Database (Transaction Committed)
    ↓
Trigger Webhook Notification (bowebhook_notifier)
    ↓
fastcgi_finish_request() - Release connection to user
    ↓
Async: Send HTTP POST to Calendar Bridge
    ↓
Calendar Bridge receives notification
    ↓
Transform and sync to Outlook
```

### Key Components

- **Webhook Notifier Service** (`class.bowebhook_notifier.inc.php`) - Centralized webhook delivery
- **Webhook Manager** (`class.bowebhook_manager.inc.php`) - Subscription CRUD operations
- **Integration Points** - Modified business objects (boevent, boallocation, bobooking)
- **API Endpoints** - REST API for subscription management
- **Background Worker** - Optional: Process failed webhooks via cron

---

## 2. Implementation Strategy

### 2.1 No Database Triggers - Direct Integration

**Why avoid database triggers:**
- Better control and debugging
- Easier to test and mock
- Can access session context and user information
- Fits naturally into existing business logic flow
- Avoids PostgreSQL/Oracle compatibility issues

**Integration approach:**
- Add webhook calls in `add()`, `update()`, and `delete()` methods
- Call after successful database transaction commit
- Use try-catch to prevent webhook failures from breaking main flow

### 2.2 Async Processing with PHP-FPM

**Using `fastcgi_finish_request()`:**

```php
// Complete the request to user
$db->transaction_commit();

// Return response to user
echo json_encode(['success' => true, 'id' => $event_id]);

// Close connection to client
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Now send webhook asynchronously (user already got response)
$webhookNotifier->notifyEventCreated($event_id, $resource_id);
```

**Benefits:**
- User gets immediate response
- Webhook delivery happens in background
- No need for separate queue/worker process
- Works with existing php-fpm setup

### 2.3 Session-Based Authentication

**Webhooks use normal login:**
- No separate API keys or OAuth tokens
- Calendar bridge subscribes using regular user credentials
- Webhook endpoints check standard session authentication
- Same permissions model as regular UI/API access

### 2.4 Configuration Management

**Following existing OutlookHelper pattern:**

The webhook configuration will be stored in the same location as the current Outlook bridge configuration:

```php
// Configuration stored via ConfigLocation (location_id: booking.run)
$location_obj = new Locations();
$location_id = $location_obj->get_id('booking', 'run');
$custom_config_data = (new ConfigLocation($location_id))->read();

// Webhook configuration structure:
$custom_config_data['Outlook'] = [
    'baseurl' => 'https://bridge.example.com',    // Calendar bridge base URL
    'api_key' => 'your-api-key',                  // API key for authentication
    'tenant_id' => 'your-tenant-id',              // Tenant identifier
    'webhook_enabled' => true,                     // Enable/disable webhooks
    'webhook_secret' => 'shared-secret-key'       // HMAC secret key
];
```

**How it works:**

1. **Base URL Configuration**: The calendar bridge URL is configured once in `ConfigLocation` for the booking module
2. **Full URL Storage**: Each subscription stores the complete notification URL including the tenant_id query parameter (e.g., `https://bridge.example.com/bridges/webhook/booking_system?tenant_id=bergen`)
3. **API Key Authentication**: When booking system sends webhooks, it includes the API key in headers (same as current OutlookHelper)
4. **Tenant Identification**: Multi-tenant support via `tenant_id` query parameter in the URL (matching Outlook bridge pattern)
5. **HMAC Secret**: Additional security layer for webhook payload validation

**Example webhook HTTP request:**

```http
POST https://bridge.example.com/bridges/webhook/booking_system?tenant_id=bergen HTTP/1.1
Content-Type: application/json
X-API-Key: your-api-key
X-Booking-Signature: sha256=abc123...
User-Agent: PorticoEstate-Webhook/1.0

{
  "value": [...]
}
```

**URL Pattern:**
- The webhook URL follows the Outlook bridge pattern: `{baseurl}/bridges/webhook/booking_system?tenant_id={tenant_id}`
- The full URL including the tenant_id query parameter is stored in `bb_webhook_subscriptions.notification_url`
- Each subscription stores its complete notification URL for direct HTTP POST delivery

This ensures consistency with the existing `OutlookHelper` class which already uses the same configuration pattern for communication with the calendar bridge.

---

## 3. Code Structure and Placement

### 3.1 New Files to Create

```text
src/modules/booking/
├── inc/
│   ├── class.bowebhook_manager.inc.php          # Subscription CRUD operations
│   ├── class.bowebhook_notifier.inc.php         # Webhook delivery service
│   ├── class.sowebhook_subscription.inc.php     # Database layer for subscriptions
│   └── class.sowebhook_delivery_log.inc.php     # Database layer for delivery tracking
├── controllers/
│   └── WebhookController.php                     # REST API endpoints
└── services/
    └── WebhookDeliveryService.php                # Async delivery logic
```

### 3.2 Files to Modify

```text
src/modules/booking/inc/
├── class.boevent.inc.php          # Add webhook calls in add/update/delete
├── class.boallocation.inc.php     # Add webhook calls in add/update/delete  
└── class.bobooking.inc.php        # Add webhook calls in add/update/delete
```

### 3.3 Database Tables

```sql
-- Webhook subscriptions
CREATE TABLE bb_webhook_subscriptions (
    id SERIAL PRIMARY KEY,
    subscription_id VARCHAR(255) UNIQUE NOT NULL,
    resource_type VARCHAR(50) NOT NULL,  -- 'event', 'allocation', 'booking', 'resource'
    resource_id INT,                      -- Specific resource ID or NULL for all
    notification_url TEXT NOT NULL,       -- Full URL including tenant_id query param
    change_types VARCHAR(255) NOT NULL DEFAULT 'created,updated,deleted',
    client_state VARCHAR(255),
    secret_key VARCHAR(255),              -- For HMAC signature validation
    is_active BOOLEAN DEFAULT TRUE,
    expires_at TIMESTAMP NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_notification_at TIMESTAMP,
    notification_count INT DEFAULT 0,
    failure_count INT DEFAULT 0,
    FOREIGN KEY (created_by) REFERENCES phpgw_accounts(account_id)
);

CREATE INDEX idx_webhook_subs_resource ON bb_webhook_subscriptions(resource_type, resource_id);
CREATE INDEX idx_webhook_subs_active ON bb_webhook_subscriptions(is_active, expires_at);

-- Webhook delivery log
CREATE TABLE bb_webhook_delivery_log (
    id SERIAL PRIMARY KEY,
    subscription_id VARCHAR(255) NOT NULL,
    change_type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,     -- 'event', 'allocation', 'booking'
    entity_id INT NOT NULL,
    resource_id INT,
    http_status_code INT,
    response_time_ms INT,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES bb_webhook_subscriptions(subscription_id)
);

CREATE INDEX idx_webhook_log_subscription ON bb_webhook_delivery_log(subscription_id, created_at);
CREATE INDEX idx_webhook_log_entity ON bb_webhook_delivery_log(entity_type, entity_id);
```

### 3.4 Integration Points

**In `class.boevent.inc.php`:**

```php
public function add(&$event)
{
    // Existing validation...
    
    // Save to database
    $this->db->transaction_begin();
    $result = parent::add($event);
    $this->db->transaction_commit();
    
    // NEW: Send webhook notification
    try {
        $webhookNotifier = CreateObject('booking.bowebhook_notifier');
        
        // Close connection to user first
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        // Now send webhook asynchronously
        $webhookNotifier->notifyChange(
            'event',
            'created',
            $event['id'],
            $event['resources'] ?? []
        );
    } catch (Exception $e) {
        // Log error but don't fail the main operation
        $this->logger->error('Webhook notification failed', [
            'event_id' => $event['id'],
            'error' => $e->getMessage()
        ]);
    }
    
    return $result;
}
```

**Similar modifications in:**
- `class.boallocation.inc.php` - notify on allocation changes
- `class.bobooking.inc.php` - notify on booking changes

---

## 4. Implementation Phases

### Phase 1: Database Schema and Core Classes (Week 1)

**Tasks:**

1. Create database migration scripts
2. Implement `class.sowebhook_subscription.inc.php`
3. Implement `class.sowebhook_delivery_log.inc.php`  
4. Implement `class.bowebhook_manager.inc.php`
5. Write unit tests for data layer

**Deliverables:**
- Database tables created
- Basic CRUD operations for subscriptions
- Test coverage > 80%

### Phase 2: Webhook Notifier Service (Week 1-2)

**Tasks:**

1. Implement `class.bowebhook_notifier.inc.php`
2. Add HTTP client for webhook delivery
3. Implement HMAC signature generation
4. Add error handling and logging
5. Write unit tests with mocked HTTP client

**Key Methods:**

```php
class booking_bowebhook_notifier
{
    public function notifyChange($entityType, $changeType, $entityId, $resourceIds);
    private function findActiveSubscriptions($entityType, $resourceIds);
    private function buildNotificationPayload($subscription, $changeType, $entity);
    private function deliverWebhook($subscription, $payload);
    private function generateSignature($payload, $secret);
    private function logDelivery($subscriptionId, $changeType, $entityType, $entityId, $statusCode, $responseTime, $error);
}
```

**Deliverables:**
- Webhook notifier service working
- Successful delivery tracking
- Failed delivery logging

### Phase 3: Integration with Business Logic (Week 2)

**Tasks:**

1. Modify `class.boevent.inc.php` - add webhook calls
2. Modify `class.boallocation.inc.php` - add webhook calls
3. Modify `class.bobooking.inc.php` - add webhook calls
4. Test webhook delivery doesn't block user response
5. Test webhook failures don't break main operations

**Integration Pattern:**

```php
// After successful DB commit
$this->db->transaction_commit();

// Close user connection
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Send webhook (async)
try {
    $webhookNotifier->notifyChange(...);
} catch (Exception $e) {
    // Log but don't fail
    $this->logger->error('Webhook failed', ['error' => $e->getMessage()]);
}
```

**Deliverables:**
- Webhooks sent on create/update/delete
- User response time unchanged
- Error handling prevents failures

### Phase 4: REST API Endpoints (Week 2-3)

**Tasks:**

1. Create `WebhookController.php`
2. Implement subscription management endpoints
3. Add authentication middleware
4. Add rate limiting
5. Write integration tests

**Endpoints:**

```php
// Create subscription
POST /booking/api/webhooks/subscriptions

// List subscriptions  
GET /booking/api/webhooks/subscriptions

// Get subscription
GET /booking/api/webhooks/subscriptions/{id}

// Update subscription (renew)
PATCH /booking/api/webhooks/subscriptions/{id}

// Delete subscription
DELETE /booking/api/webhooks/subscriptions/{id}

// Validation endpoint
GET /booking/api/webhooks/validate
```

**Deliverables:**
- Full REST API working
- Session authentication enforced
- API documentation

### Phase 5: Testing and Deployment (Week 3-4)

**Tasks:**

1. Integration testing with calendar bridge
2. Load testing webhook delivery
3. Test failure scenarios
4. Performance optimization
5. Documentation and deployment

**Test Scenarios:**
- Create 100 events rapidly - all webhooks delivered
- Simulate bridge downtime - graceful failure
- Test duplicate notifications handled idempotently
- Verify async processing doesn't block users
- Test subscription expiration and renewal

**Deliverables:**
- System ready for production
- Performance benchmarks met
- Complete documentation

---

## 5. API Specifications

### 5.1 Create Subscription

**Request:**

```http
POST /booking/api/webhooks/subscriptions HTTP/1.1
Host: booking.example.com
Cookie: sessionid=abc123
Content-Type: application/json

{
  "resourceType": "event",
  "resourceId": 123,
  "notificationUrl": "https://bridge.example.com/bridges/webhook/booking_system?tenant_id=bergen",
  "changeTypes": ["created", "updated", "deleted"],
  "clientState": "mySecretToken",
  "expirationMinutes": 43200
}
```

**Note:** The `notificationUrl` must include the full URL with tenant_id query parameter following the Outlook bridge pattern.

**Response:**

```json
{
  "success": true,
  "subscription": {
    "subscriptionId": "sub_550e8400-e29b-41d4-a716-446655440000",
    "resourceType": "event",
    "resourceId": 123,
    "notificationUrl": "https://bridge.example.com/bridges/webhook/booking_system?tenant_id=bergen",
    "changeTypes": ["created", "updated", "deleted"],
    "expirationDateTime": "2025-11-10T14:30:00Z",
    "createdDateTime": "2025-10-10T14:30:00Z"
  }
}
```

### 5.2 Webhook Notification Payload

**Format:**

```json
{
  "value": [
    {
      "subscriptionId": "sub_550e8400-e29b-41d4-a716-446655440000",
      "changeType": "created",
      "resourceType": "event",
      "resourceId": 123,
      "entityType": "event",
      "entityId": 456,
      "entityData": {
        "id": 456,
        "subject": "Board Meeting",
        "from_": "2025-10-15T10:00:00Z",
        "to_": "2025-10-15T12:00:00Z",
        "building_name": "City Hall",
        "organization_name": "City Council",
        "resources": [123, 124]
      },
      "clientState": "mySecretToken",
      "timestamp": "2025-10-10T14:35:00Z"
    }
  ]
}
```

**HTTP Headers:**

```http
POST /bridges/webhook/booking_system?tenant_id=bergen HTTP/1.1
Host: bridge.example.com
Content-Type: application/json
X-API-Key: your-api-key
X-Booking-Signature: sha256=abc123def456...
User-Agent: PorticoEstate-Webhook/1.0
```

**Note:** The tenant_id is included in the URL query string (matching Outlook bridge pattern), and the full URL is stored in the subscription's `notification_url` field.

### 5.3 Security - HMAC Signature

**Generation (booking system):**

```php
$secret = $subscription['secret_key'];
$payload = json_encode($notificationData);
$signature = hash_hmac('sha256', $payload, $secret);
$headers = ['X-Booking-Signature' => 'sha256=' . $signature];
```

**Validation (calendar bridge):**

```php
$receivedSignature = $_SERVER['HTTP_X_BOOKING_SIGNATURE'];
$secret = $this->getSubscriptionSecret($subscriptionId);
$computedSignature = 'sha256=' . hash_hmac('sha256', $requestBody, $secret);

if (!hash_equals($computedSignature, $receivedSignature)) {
    throw new Exception('Invalid signature');
}
```

---

## 6. Implementation Example Code

### 6.1 Webhook Notifier Service

```php
<?php
/**
 * Webhook notification service
 * Handles delivery of webhooks when entities change
 */
class booking_bowebhook_notifier
{
    private $db;
    private $soSubscription;
    private $soDeliveryLog;
    private $logger;
    private $baseurl;
    private $api_key;
    private $tenant_id;
    private $webhook_secret;
    private $webhook_enabled;
    
    public function __construct()
    {
        $this->db = CreateObject('phpgwapi.db');
        $this->soSubscription = CreateObject('booking.sowebhook_subscription');
        $this->soDeliveryLog = CreateObject('booking.sowebhook_delivery_log');
        $this->logger = CreateObject('phpgwapi.logger')->get_logger('webhook');
        
        // Load configuration (same pattern as OutlookHelper)
        $location_obj = new \App\modules\phpgwapi\controllers\Locations();
        $location_id = $location_obj->get_id('booking', 'run');
        $custom_config_data = (new \App\modules\phpgwapi\services\ConfigLocation($location_id))->read();
        
        if (!empty($custom_config_data['Outlook']['api_key'])) {
            $this->api_key = $custom_config_data['Outlook']['api_key'];
        }
        if (!empty($custom_config_data['Outlook']['webhook_secret'])) {
            $this->webhook_secret = $custom_config_data['Outlook']['webhook_secret'];
        }
        if (isset($custom_config_data['Outlook']['webhook_enabled'])) {
            $this->webhook_enabled = (bool)$custom_config_data['Outlook']['webhook_enabled'];
        } else {
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
    public function notifyChange($entityType, $changeType, $entityId, $resourceIds = [])
    {
        // Check if webhooks are enabled
        if (!$this->webhook_enabled) {
            $this->logger->debug('Webhooks disabled', [
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            return;
        }
        
        // Find active subscriptions matching this change
        $subscriptions = $this->findActiveSubscriptions($entityType, $resourceIds);
        
        if (empty($subscriptions)) {
            $this->logger->debug('No active subscriptions found', [
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            return;
        }
        
        // Load entity data
        $entityData = $this->loadEntityData($entityType, $entityId);
        
        if (!$entityData) {
            $this->logger->warning('Entity not found for webhook', [
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            return;
        }
        
        // Send webhook to each subscription
        foreach ($subscriptions as $subscription) {
            try {
                // Check if this change type is subscribed
                $subscribedTypes = explode(',', $subscription['change_types']);
                if (!in_array($changeType, $subscribedTypes)) {
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
                
            } catch (Exception $e) {
                $this->logger->error('Webhook delivery failed', [
                    'subscription_id' => $subscription['subscription_id'],
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Find active subscriptions matching the change
     */
    private function findActiveSubscriptions($entityType, $resourceIds)
    {
        $filters = [
            'is_active' => true,
            'expires_at >' => date('Y-m-d H:i:s')
        ];
        
        // Match subscriptions for this entity type or 'all'
        $filters['resource_type'] = [$entityType, 'all'];
        
        // If specific resources, include those subscriptions
        if (!empty($resourceIds)) {
            // Get subscriptions for specific resources OR all resources (NULL)
            $sql = "SELECT * FROM bb_webhook_subscriptions 
                    WHERE is_active = TRUE 
                    AND expires_at > NOW()
                    AND resource_type IN ('{$entityType}', 'all')
                    AND (resource_id IS NULL OR resource_id IN (" . implode(',', array_map('intval', $resourceIds)) . "))";
        } else {
            // Get subscriptions for all resources
            $sql = "SELECT * FROM bb_webhook_subscriptions 
                    WHERE is_active = TRUE 
                    AND expires_at > NOW()
                    AND resource_type IN ('{$entityType}', 'all')
                    AND resource_id IS NULL";
        }
        
        $this->db->query($sql, __LINE__, __FILE__);
        
        $subscriptions = [];
        while ($this->db->next_record()) {
            $subscriptions[] = [
                'subscription_id' => $this->db->f('subscription_id'),
                'notification_url' => $this->db->f('notification_url'),
                'change_types' => $this->db->f('change_types'),
                'client_state' => $this->db->f('client_state'),
                'secret_key' => $this->db->f('secret_key'),
                'resource_id' => $this->db->f('resource_id')
            ];
        }
        
        return $subscriptions;
    }
    
    /**
     * Load entity data from database
     */
    private function loadEntityData($entityType, $entityId)
    {
        switch ($entityType) {
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
        return [
            'value' => [
                [
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
                ]
            ]
        ];
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
        if (!empty($subscription['secret_key'])) {
            $signature = hash_hmac('sha256', $payloadJson, $subscription['secret_key']);
        }
        
        // Prepare HTTP headers (following OutlookHelper pattern)
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: PorticoEstate-Webhook/1.0'
        ];
        
        // Add API key if configured
        if (!empty($this->api_key)) {
            $headers[] = 'X-API-Key: ' . $this->api_key;
        }
        
        // Add HMAC signature if secret configured
        if ($signature) {
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
        if ($httpCode >= 200 && $httpCode < 300) {
            $this->updateSubscriptionSuccess($subscription['subscription_id']);
            
            $this->logger->info('Webhook delivered successfully', [
                'subscription_id' => $subscription['subscription_id'],
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'http_code' => $httpCode,
                'response_time_ms' => round($responseTime)
            ]);
        } else {
            $this->updateSubscriptionFailure($subscription['subscription_id']);
            
            $this->logger->warning('Webhook delivery failed', [
                'subscription_id' => $subscription['subscription_id'],
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'http_code' => $httpCode,
                'error' => $error
            ]);
        }
    }
    
    /**
     * Log webhook delivery attempt
     */
    private function logDelivery($subscriptionId, $changeType, $entityType, $entityId, $resourceId, $httpCode, $responseTime, $error)
    {
        $sql = "INSERT INTO bb_webhook_delivery_log 
                (subscription_id, change_type, entity_type, entity_id, resource_id, 
                 http_status_code, response_time_ms, error_message, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $subscriptionId,
            $changeType,
            $entityType,
            $entityId,
            $resourceId,
            $httpCode,
            round($responseTime),
            $error
        ]);
    }
    
    /**
     * Update subscription after successful delivery
     */
    private function updateSubscriptionSuccess($subscriptionId)
    {
        $sql = "UPDATE bb_webhook_subscriptions 
                SET last_notification_at = NOW(),
                    notification_count = notification_count + 1
                WHERE subscription_id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$subscriptionId]);
    }
    
    /**
     * Update subscription after failed delivery
     */
    private function updateSubscriptionFailure($subscriptionId)
    {
        $sql = "UPDATE bb_webhook_subscriptions 
                SET failure_count = failure_count + 1
                WHERE subscription_id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$subscriptionId]);
    }
}
```

---

## 7. Testing Strategy

### 7.1 Unit Tests

Test each component in isolation:

```php
class WebhookNotifierTest extends PHPUnit_Framework_TestCase
{
    public function testNotifyChangeFindsSubscriptions()
    {
        // Mock database
        // Mock HTTP client
        // Verify correct subscriptions matched
    }
    
    public function testWebhookDeliveryIncludesSignature()
    {
        // Mock HTTP request
        // Verify X-Booking-Signature header present
    }
    
    public function testFailedDeliveryLogged()
    {
        // Mock HTTP failure
        // Verify error logged
        // Verify subscription failure count incremented
    }
}
```

### 7.2 Integration Tests

Test end-to-end flow:

```bash
# Create event and verify webhook sent
curl -X POST http://booking.local/index.php?menuaction=booking.boevent.add \
  -H "Cookie: sessionid=abc123" \
  -d '{"name":"Test Event","from_":"2025-10-15 10:00","to_":"2025-10-15 12:00"}'

# Verify webhook received at bridge
tail -f /var/log/bridge/webhooks.log
```

### 7.3 Performance Tests

```bash
# Create 100 events rapidly
for i in {1..100}; do
  curl -X POST http://booking.local/index.php?menuaction=booking.boevent.add \
    -H "Cookie: sessionid=abc123" \
    -d "{\"name\":\"Event $i\",...}"
done

# Verify:
# - User responses remain fast (< 200ms)
# - All webhooks delivered
# - No memory leaks
```

---

## 8. Monitoring and Troubleshooting

### 8.1 Key Metrics

```sql
-- Active subscriptions
SELECT COUNT(*) FROM bb_webhook_subscriptions 
WHERE is_active = TRUE AND expires_at > NOW();

-- Delivery success rate (last 24h)
SELECT 
    COUNT(CASE WHEN http_status_code BETWEEN 200 AND 299 THEN 1 END)::float / 
    NULLIF(COUNT(*), 0) * 100 as success_rate
FROM bb_webhook_delivery_log
WHERE created_at > NOW() - INTERVAL '24 hours';

-- Failed deliveries by subscription
SELECT 
    subscription_id,
    COUNT(*) as failure_count,
    MAX(created_at) as last_failure
FROM bb_webhook_delivery_log
WHERE http_status_code NOT BETWEEN 200 AND 299
    AND created_at > NOW() - INTERVAL '24 hours'
GROUP BY subscription_id
ORDER BY failure_count DESC;
```

### 8.2 Logging

```php
// Log levels
$this->logger->debug('Webhook details', $context);  // Development only
$this->logger->info('Webhook delivered', $context);  // Normal operations
$this->logger->warning('Delivery failed', $context); // Retryable errors
$this->logger->error('Critical failure', $context);  // Requires attention
```

### 8.3 Alerts

Monitor and alert on:
- Delivery failure rate > 10%
- Subscriptions expiring in next 24h
- HTTP timeouts increasing
- Memory usage during webhook delivery

---

## 9. Deployment Checklist

- [ ] Database tables created (bb_webhook_subscriptions, bb_webhook_delivery_log)
- [ ] Webhook notifier service implemented
- [ ] Integration added to boevent/boallocation/bobooking
- [ ] REST API endpoints created
- [ ] Session authentication working
- [ ] HMAC signature generation/validation working
- [ ] Logging configured
- [ ] Monitoring dashboard created
- [ ] Integration tests passing
- [ ] Performance tests passing
- [ ] Documentation complete
- [ ] Calendar bridge updated to consume webhooks

---

## 10. Timeline

| Phase | Duration | Key Deliverables |
|-------|----------|------------------|
| Phase 1: Database & Core Classes | Week 1 | Tables, CRUD operations |
| Phase 2: Webhook Notifier | Week 1-2 | Delivery service working |
| Phase 3: Business Logic Integration | Week 2 | Events/allocations/bookings notify |
| Phase 4: REST API | Week 2-3 | Subscription management API |
| Phase 5: Testing & Deployment | Week 3-4 | Production ready |
| **Total** | **3-4 weeks** | **Webhook system live** |

---

*Document Version: 2.0*  
*Last Updated: October 10, 2025*  
*Author: GitHub Copilot*

**Microsoft Graph Approach:**

```http
POST /subscriptions
{
  "changeType": "created,updated,deleted",
  "notificationUrl": "https://your-app.com/bridges/webhook/outlook",
  "resource": "users/{user-id}/events",
  "expirationDateTime": "2025-10-17T18:23:45.9356913Z",
  "clientState": "secretToken"
}
```

**Key Features:**
- Subscription has unique ID
- Expiration time (max 4230 minutes for events)
- Requires validation on creation
- Supports renewal before expiration
- Client state for validation

### 2.2 Webhook Validation

**Microsoft Graph Validation Handshake:**

```http
GET /bridges/webhook/outlook?validationToken=abc123
```

Expected Response:
```
HTTP 200 OK
Content-Type: text/plain

abc123
```

### 2.3 Notification Payload

**Microsoft Graph Notification Structure:**

```json
{
  "value": [
    {
      "subscriptionId": "7f105c7d-2dc5-4530-97cd-4e7ae6534c07",
      "subscriptionExpirationDateTime": "2025-10-17T18:23:45.9356913Z",
      "changeType": "created",
      "resource": "Users/211c1bef-1234-5678-9abc-def012345678/Events/AAMkAGI1...",
      "resourceData": {
        "@odata.type": "#Microsoft.Graph.Event",
        "@odata.id": "Users/211c1bef-1234-5678-9abc-def012345678/Events/AAMkAGI1...",
        "@odata.etag": "W/\"EZ9r3czxY0m2jz8c45/o7wAABFzuVQ==\"",
        "id": "AAMkAGI1..."
      },
      "clientState": "secretToken",
      "tenantId": "84bd8158-6d4d-4958-8b9f-9d6445542f95"
    }
  ]
}
```

### 2.4 Change Types

Microsoft Graph supports:
- `created` - New event created
- `updated` - Event modified
- `deleted` - Event deleted

### 2.5 Reliability Features

- **Retry Logic**: Microsoft retries failed deliveries with exponential backoff
- **Duplicate Detection**: Same notification may be sent multiple times (idempotent processing required)
- **Batch Notifications**: Multiple changes in single POST request
- **Expiration Management**: Subscriptions auto-expire if not renewed

---

## 3. Booking System Webhook Requirements

### 3.1 Core Requirements

Your booking system must implement:

#### **R1: Subscription API**
- Endpoint to create webhook subscriptions
- Store subscription metadata
- Generate unique subscription IDs
- Set expiration times
- Return subscription details

#### **R2: Validation Endpoint**
- Support GET request with validation token
- Return token as plain text response
- Verify endpoint is reachable before activating subscription

#### **R3: Change Detection**
- Detect when events are created
- Detect when events are updated
- Detect when events are deleted
- Track changes per resource (room/equipment)

#### **R4: Notification Delivery**
- POST notifications to subscribed URLs
- Batch multiple changes efficiently
- Include all required metadata
- Handle delivery failures with retries

#### **R5: Subscription Management**
- List active subscriptions
- Renew subscriptions before expiry
- Delete/unsubscribe functionality
- Track subscription health

---

## 4. Implementation Phases

### Phase 1: Database Schema (Week 1)

```sql
-- Subscription tracking table
CREATE TABLE booking_webhook_subscriptions
(
    id SERIAL PRIMARY KEY,
    subscription_id VARCHAR(255) UNIQUE NOT NULL,
    resource_id VARCHAR(255) NOT NULL, -- room/equipment ID
    notification_url TEXT NOT NULL,
    change_types VARCHAR(255) NOT NULL DEFAULT 'created,updated,deleted',
    client_state VARCHAR(255), -- validation token
    expires_at TIMESTAMP NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_notification_at TIMESTAMP,
    notification_count INTEGER DEFAULT 0,
    failure_count INTEGER DEFAULT 0
);

CREATE INDEX idx_webhook_subs_resource ON booking_webhook_subscriptions(resource_id);
CREATE INDEX idx_webhook_subs_active ON booking_webhook_subscriptions(is_active, expires_at);

-- Notification delivery tracking
CREATE TABLE booking_webhook_deliveries
(
    id SERIAL PRIMARY KEY,
    subscription_id VARCHAR(255) NOT NULL,
    change_type VARCHAR(50) NOT NULL,
    event_id VARCHAR(255) NOT NULL,
    resource_id VARCHAR(255) NOT NULL,
    notification_payload TEXT,
    delivery_status VARCHAR(50) DEFAULT 'pending', -- pending, success, failed
    http_status_code INTEGER,
    retry_count INTEGER DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    delivered_at TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES booking_webhook_subscriptions(subscription_id)
);

CREATE INDEX idx_webhook_deliveries_status ON booking_webhook_deliveries(delivery_status, created_at);
CREATE INDEX idx_webhook_deliveries_subscription ON booking_webhook_deliveries(subscription_id);

-- Change detection tracking (for systems without native trigger support)
CREATE TABLE booking_event_snapshots
(
    id SERIAL PRIMARY KEY,
    event_id VARCHAR(255) NOT NULL,
    resource_id VARCHAR(255) NOT NULL,
    event_hash VARCHAR(64) NOT NULL, -- SHA256 hash of event data
    snapshot_data JSONB, -- full event data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(event_id, created_at)
);

CREATE INDEX idx_event_snapshots_event ON booking_event_snapshots(event_id);
CREATE INDEX idx_event_snapshots_resource ON booking_event_snapshots(resource_id, created_at);
```

### Phase 2: Subscription Management API (Week 1-2)

#### **Endpoint 1: Create Subscription**

```http
POST /api/webhooks/subscriptions
Content-Type: application/json

{
  "resourceId": "resource_123",
  "notificationUrl": "https://sync.example.com/bridges/webhook/booking_system",
  "changeTypes": ["created", "updated", "deleted"],
  "clientState": "secretToken123",
  "expirationMinutes": 4230
}
```

**Response:**
```json
{
  "success": true,
  "subscription": {
    "subscriptionId": "bs-sub-550e8400-e29b-41d4-a716-446655440000",
    "resourceId": "resource_123",
    "notificationUrl": "https://sync.example.com/bridges/webhook/booking_system",
    "changeTypes": ["created", "updated", "deleted"],
    "expirationDateTime": "2025-10-17T18:23:45Z",
    "createdDateTime": "2025-10-10T18:23:45Z"
  }
}
```

**Implementation Logic:**
```php
public function createWebhookSubscription($data)
{
    // 1. Validate input
    if (empty($data['resourceId']) || empty($data['notificationUrl']))
    {
        throw new \InvalidArgumentException('resourceId and notificationUrl required');
    }
    
    // 2. Validate notification URL with handshake
    $validationToken = bin2hex(random_bytes(16));
    $isValid = $this->validateWebhookEndpoint(
        $data['notificationUrl'], 
        $validationToken
    );
    
    if (!$isValid)
    {
        throw new \Exception('Webhook endpoint validation failed');
    }
    
    // 3. Generate subscription ID
    $subscriptionId = 'bs-sub-' . $this->generateUuid();
    
    // 4. Calculate expiration
    $expirationMinutes = min($data['expirationMinutes'] ?? 4230, 4230);
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expirationMinutes} minutes"));
    
    // 5. Store subscription
    $sql = "INSERT INTO booking_webhook_subscriptions 
            (subscription_id, resource_id, notification_url, change_types, 
             client_state, expires_at, is_active)
            VALUES (?, ?, ?, ?, ?, ?, TRUE)";
    
    $this->db->execute($sql, [
        $subscriptionId,
        $data['resourceId'],
        $data['notificationUrl'],
        implode(',', $data['changeTypes'] ?? ['created', 'updated', 'deleted']),
        $data['clientState'] ?? null,
        $expiresAt
    ]);
    
    return [
        'subscriptionId' => $subscriptionId,
        'resourceId' => $data['resourceId'],
        'notificationUrl' => $data['notificationUrl'],
        'changeTypes' => $data['changeTypes'] ?? ['created', 'updated', 'deleted'],
        'expirationDateTime' => $expiresAt,
        'createdDateTime' => date('Y-m-d H:i:s')
    ];
}

private function validateWebhookEndpoint($url, $validationToken)
{
    try
    {
        $response = $this->httpClient->get($url, [
            'query' => ['validationToken' => $validationToken],
            'timeout' => 5
        ]);
        
        return $response->getStatusCode() === 200 
            && trim($response->getBody()->getContents()) === $validationToken;
    }
    catch (\Exception $e)
    {
        $this->logger->error('Webhook validation failed', [
            'url' => $url,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}
```

#### **Endpoint 2: Validation Handshake**

```http
GET /api/webhooks/validate?validationToken=abc123
```

**Response:**
```
HTTP 200 OK
Content-Type: text/plain

abc123
```

**Implementation:**
```php
public function handleValidation($validationToken)
{
    // Simply echo back the token
    header('Content-Type: text/plain');
    echo $validationToken;
    exit;
}
```

#### **Endpoint 3: Renew Subscription**

```http
PATCH /api/webhooks/subscriptions/{subscriptionId}
Content-Type: application/json

{
  "expirationMinutes": 4230
}
```

#### **Endpoint 4: Delete Subscription**

```http
DELETE /api/webhooks/subscriptions/{subscriptionId}
```

#### **Endpoint 5: List Subscriptions**

```http
GET /api/webhooks/subscriptions?resourceId=resource_123
```

### Phase 3: Change Detection System (Week 2-3)

#### **Option A: Database Triggers (Recommended)**

```sql
-- Trigger function to capture changes
CREATE OR REPLACE FUNCTION notify_booking_change()
RETURNS TRIGGER AS $$
DECLARE
    change_type VARCHAR(10);
    notification_data JSONB;
BEGIN
    -- Determine change type
    IF TG_OP = 'INSERT' THEN
        change_type := 'created';
        notification_data := row_to_json(NEW)::jsonb;
    ELSIF TG_OP = 'UPDATE' THEN
        change_type := 'updated';
        notification_data := row_to_json(NEW)::jsonb;
    ELSIF TG_OP = 'DELETE' THEN
        change_type := 'deleted';
        notification_data := row_to_json(OLD)::jsonb;
    END IF;
    
    -- Queue webhook notification
    INSERT INTO booking_webhook_queue (
        event_id, 
        resource_id, 
        change_type, 
        event_data,
        created_at
    ) VALUES (
        COALESCE(NEW.id, OLD.id),
        COALESCE(NEW.resource_id, OLD.resource_id),
        change_type,
        notification_data,
        CURRENT_TIMESTAMP
    );
    
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;

-- Attach trigger to events table
CREATE TRIGGER booking_events_change_trigger
AFTER INSERT OR UPDATE OR DELETE ON booking_events
FOR EACH ROW EXECUTE FUNCTION notify_booking_change();
```


### Phase 4: Notification Delivery System (Week 3-4)

#### **Webhook Queue Processor**

```php
class BookingWebhookDeliveryService
{
    private $db;
    private $httpClient;
    private $logger;
    
    /**
     * Process queued webhook notifications
     */
    public function processQueue($batchSize = 50)
    {
        $sql = "SELECT DISTINCT wq.id, wq.event_id, wq.resource_id, 
                wq.change_type, wq.event_data, wq.created_at,
                ws.subscription_id, ws.notification_url, ws.client_state
                FROM booking_webhook_queue wq
                INNER JOIN booking_webhook_subscriptions ws 
                    ON ws.resource_id = wq.resource_id 
                    AND ws.is_active = TRUE
                    AND ws.expires_at > NOW()
                    AND POSITION(wq.change_type IN ws.change_types) > 0
                WHERE wq.status = 'pending'
                ORDER BY wq.created_at ASC
                LIMIT ?";
        
        $queueItems = $this->db->query($sql, [$batchSize]);
        
        // Group by subscription for batch delivery
        $batches = [];
        foreach ($queueItems as $item)
        {
            $subId = $item['subscription_id'];
            if (!isset($batches[$subId]))
            {
                $batches[$subId] = [
                    'subscription' => [
                        'id' => $subId,
                        'url' => $item['notification_url'],
                        'client_state' => $item['client_state']
                    ],
                    'notifications' => []
                ];
            }
            
            $batches[$subId]['notifications'][] = [
                'queue_id' => $item['id'],
                'changeType' => $item['change_type'],
                'eventId' => $item['event_id'],
                'resourceId' => $item['resource_id'],
                'eventData' => json_decode($item['event_data'], true),
                'timestamp' => $item['created_at']
            ];
        }
        
        // Deliver batches
        $results = [
            'success' => 0,
            'failed' => 0
        ];
        
        foreach ($batches as $batch)
        {
            try
            {
                $this->deliverBatch($batch);
                $results['success'] += count($batch['notifications']);
            }
            catch (\Exception $e)
            {
                $results['failed'] += count($batch['notifications']);
                $this->logger->error('Batch delivery failed', [
                    'subscription_id' => $batch['subscription']['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }
    
    /**
     * Deliver notification batch to webhook URL
     */
    private function deliverBatch($batch)
    {
        $subscription = $batch['subscription'];
        $notifications = $batch['notifications'];
        
        // Build payload matching Outlook format
        $payload = [
            'value' => array_map(function($notif) use ($subscription) {
                return [
                    'subscriptionId' => $subscription['id'],
                    'changeType' => $notif['changeType'],
                    'resource' => "resources/{$notif['resourceId']}/events/{$notif['eventId']}",
                    'resourceData' => [
                        'id' => $notif['eventId'],
                        'type' => 'booking_event',
                        'data' => $notif['eventData']
                    ],
                    'clientState' => $subscription['client_state'],
                    'timestamp' => $notif['timestamp']
                ];
            }, $notifications)
        ];
        
        // Send HTTP POST
        $response = $this->httpClient->post($subscription['url'], [
            'json' => $payload,
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'BookingSystem-Webhook/1.0'
            ]
        ]);
        
        $statusCode = $response->getStatusCode();
        
        if ($statusCode >= 200 && $statusCode < 300)
        {
            // Mark notifications as delivered
            foreach ($notifications as $notif)
            {
                $this->markDelivered($notif['queue_id'], $statusCode);
            }
            
            // Update subscription metrics
            $this->updateSubscriptionMetrics($subscription['id'], count($notifications));
            
            $this->logger->info('Webhook batch delivered successfully', [
                'subscription_id' => $subscription['id'],
                'notification_count' => count($notifications),
                'http_status' => $statusCode
            ]);
        }
        else
        {
            throw new \Exception("HTTP {$statusCode} response from webhook endpoint");
        }
    }
    
    /**
     * Mark queue item as delivered
     */
    private function markDelivered($queueId, $httpStatus)
    {
        $sql = "UPDATE booking_webhook_queue 
                SET status = 'delivered', 
                    delivered_at = CURRENT_TIMESTAMP,
                    http_status_code = ?
                WHERE id = ?";
        
        $this->db->execute($sql, [$httpStatus, $queueId]);
    }
    
    /**
     * Update subscription delivery metrics
     */
    private function updateSubscriptionMetrics($subscriptionId, $count)
    {
        $sql = "UPDATE booking_webhook_subscriptions 
                SET last_notification_at = CURRENT_TIMESTAMP,
                    notification_count = notification_count + ?
                WHERE subscription_id = ?";
        
        $this->db->execute($sql, [$count, $subscriptionId]);
    }
}
```

### Phase 5: Integration with OutlookBookingSync (Week 4)

#### **Update BookingSystemBridge**

Add subscription support to `src/Bridge/BookingSystemBridge.php`:

```php
/**
 * Subscribe to changes for a calendar/resource.
 *
 * @param string $calendarId Resource ID to monitor
 * @param string $webhookUrl URL to receive notifications
 * @param int $expirationMinutes Subscription duration
 * @return array Subscription details
 */
public function subscribeToChanges($calendarId, $webhookUrl, $expirationMinutes = 4230): array
{
    $endpoint = $this->config['api_endpoints']['create_subscription'] ?? '/api/webhooks/subscriptions';
    
    $payload = [
        'resourceId' => $calendarId,
        'notificationUrl' => $webhookUrl,
        'changeTypes' => ['created', 'updated', 'deleted'],
        'clientState' => $this->generateClientState(),
        'expirationMinutes' => $expirationMinutes
    ];
    
    $response = $this->makeApiRequest('POST', $endpoint, [], $payload);
    
    if (!empty($response['subscription']))
    {
        // Store subscription in bridge_subscriptions table
        $this->storeSubscription(
            $response['subscription']['subscriptionId'],
            $calendarId,
            $webhookUrl,
            $response['subscription']['expirationDateTime']
        );
        
        return $response['subscription'];
    }
    
    throw new \Exception('Failed to create webhook subscription');
}

/**
 * Renew an existing subscription.
 *
 * @param string $subscriptionId Subscription to renew
 * @return array Updated subscription details
 */
public function renewSubscription($subscriptionId): array
{
    $endpoint = $this->config['api_endpoints']['renew_subscription'] 
        ?? "/api/webhooks/subscriptions/{$subscriptionId}";
    
    $payload = [
        'expirationMinutes' => 4230
    ];
    
    $response = $this->makeApiRequest('PATCH', $endpoint, [], $payload);
    
    return $response['subscription'] ?? [];
}

/**
 * Unsubscribe from changes.
 *
 * @param string $subscriptionId Subscription to cancel
 * @return bool Success status
 */
public function unsubscribeFromChanges($subscriptionId): bool
{
    $endpoint = $this->config['api_endpoints']['delete_subscription']
        ?? "/api/webhooks/subscriptions/{$subscriptionId}";
    
    try
    {
        $this->makeApiRequest('DELETE', $endpoint);
        return true;
    }
    catch (\Exception $e)
    {
        $this->logger->error('Failed to unsubscribe', [
            'subscription_id' => $subscriptionId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Generate client state token for validation.
 */
private function generateClientState(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Store subscription in database.
 */
private function storeSubscription($subscriptionId, $calendarId, $webhookUrl, $expiresAt)
{
    $sql = "INSERT INTO bridge_subscriptions 
            (subscription_id, calendar_id, bridge_type, webhook_url, expires_at, is_active)
            VALUES (?, ?, 'booking_system', ?, ?, TRUE)
            ON CONFLICT (subscription_id) 
            DO UPDATE SET expires_at = EXCLUDED.expires_at, is_active = TRUE";
    
    $this->db->prepare($sql)->execute([
        $subscriptionId,
        $calendarId,
        $webhookUrl,
        $expiresAt
    ]);
}
```

#### **Webhook Transformation**

Update `BridgeController::handleWebhook()` to handle booking system format:

```php
/**
 * Transform booking system notification to internal format.
 *
 * @param array $notification Raw booking system notification
 * @param string|null $tenantId Tenant identifier
 * @return array|null Transformed payload or null if invalid
 */
private function transformBookingSystemNotification($notification, $tenantId)
{
    try
    {
        $changeType = $notification['changeType'] ?? null;
        $resourceUrl = $notification['resource'] ?? null;
        $eventId = $notification['resourceData']['id'] ?? null;
        
        if (!$resourceUrl || !$eventId)
        {
            $this->logger->warning('Invalid booking system notification', [
                'notification' => $notification
            ]);
            return null;
        }
        
        // Extract resource ID from resource URL
        // Format: "resources/{resourceId}/events/{eventId}"
        if (preg_match('/resources\/([^\/]+)\/events/i', $resourceUrl, $matches))
        {
            $resourceId = $matches[1];
            
            $transformedPayload = [
                'subscription_id' => $notification['subscriptionId'] ?? null,
                'change_type' => $changeType,
                'resource_id' => $resourceId,
                'event_id' => $eventId,
                'client_state' => $notification['clientState'] ?? null,
                'timestamp' => $notification['timestamp'] ?? date('c'),
                'raw_data' => $notification
            ];
            
            $this->logger->info('Transformed booking system notification', [
                'change_type' => $changeType,
                'resource_id' => $resourceId,
                'event_id' => $eventId
            ]);
            
            return $transformedPayload;
        }
        else
        {
            $this->logger->warning('Could not parse booking system resource URL', [
                'resource_url' => $resourceUrl
            ]);
            return null;
        }
    }
    catch (\Exception $e)
    {
        $this->logger->error('Failed to transform booking system notification', [
            'notification' => $notification,
            'error' => $e->getMessage(),
            'tenant_id' => $tenantId
        ]);
        return null;
    }
}
```

---

## 5. API Specifications

### 5.1 Notification Payload Format

```json
{
  "value": [
    {
      "subscriptionId": "bs-sub-550e8400-e29b-41d4-a716-446655440000",
      "changeType": "created",
      "resource": "resources/room_101/events/evt_12345",
      "resourceData": {
        "id": "evt_12345",
        "type": "booking_event",
        "data": {
          "subject": "Team Meeting",
          "start": "2025-10-15T10:00:00Z",
          "end": "2025-10-15T11:00:00Z",
          "organizer": "john.doe@example.com",
          "attendees": ["jane.smith@example.com"]
        }
      },
      "clientState": "secretToken123",
      "timestamp": "2025-10-10T14:30:00Z"
    }
  ]
}
```

### 5.2 HTTP Requirements

#### **Request Headers**
```
Content-Type: application/json
User-Agent: BookingSystem-Webhook/1.0
X-Booking-Signature: sha256=abc123... (optional, for security)
```

#### **Response Requirements**
- **200-299**: Notification accepted and processed
- **400-499**: Permanent failure, stop retrying
- **500-599**: Temporary failure, retry with backoff

#### **Retry Policy**
```
Attempt 1: Immediate
Attempt 2: 30 seconds delay
Attempt 3: 5 minutes delay
Attempt 4: 30 minutes delay
Attempt 5: 2 hours delay
After 5 failures: Mark subscription as unhealthy, alert admin
```

---

## 6. Security Requirements

### 6.1 Authentication Methods

#### **Option 1: Client State Validation**
```php
// Booking system sends
$payload = [
    'value' => [...],
    'clientState' => 'secretToken123'
];

// OutlookBookingSync validates
$subscription = $this->getSubscription($payload['value'][0]['subscriptionId']);
if ($payload['clientState'] !== $subscription['client_state'])
{
    throw new \Exception('Invalid client state');
}
```

#### **Option 2: HMAC Signature** (Recommended)
```php
// Booking system generates signature
$secret = 'shared_secret_key';
$payload = json_encode($notificationData);
$signature = hash_hmac('sha256', $payload, $secret);

// Send header
header('X-Booking-Signature: sha256=' . $signature);

// OutlookBookingSync validates
$receivedSignature = $_SERVER['HTTP_X_BOOKING_SIGNATURE'];
$computedSignature = 'sha256=' . hash_hmac('sha256', $requestBody, $secret);

if (!hash_equals($computedSignature, $receivedSignature))
{
    throw new \Exception('Invalid signature');
}
```

### 6.2 HTTPS Requirements

- All webhook URLs MUST use HTTPS
- Validate SSL certificates
- Support TLS 1.2+

### 6.3 Rate Limiting

```php
// Implement per-subscription rate limiting
$maxNotificationsPerMinute = 60;
$maxNotificationsPerHour = 1000;

function checkRateLimit($subscriptionId)
{
    $lastMinute = $this->redis->get("webhook_rate:{$subscriptionId}:minute");
    $lastHour = $this->redis->get("webhook_rate:{$subscriptionId}:hour");
    
    if ($lastMinute >= $maxNotificationsPerMinute)
    {
        throw new \Exception('Rate limit exceeded (per minute)');
    }
    
    if ($lastHour >= $maxNotificationsPerHour)
    {
        throw new \Exception('Rate limit exceeded (per hour)');
    }
    
    // Increment counters
    $this->redis->incr("webhook_rate:{$subscriptionId}:minute");
    $this->redis->expire("webhook_rate:{$subscriptionId}:minute", 60);
    
    $this->redis->incr("webhook_rate:{$subscriptionId}:hour");
    $this->redis->expire("webhook_rate:{$subscriptionId}:hour", 3600);
}
```

---

## 7. Testing Strategy

### 7.1 Unit Tests

```php
class BookingWebhookTest extends TestCase
{
    public function testSubscriptionCreation()
    {
        $service = new BookingWebhookService($this->db, $this->logger);
        
        $subscription = $service->createWebhookSubscription([
            'resourceId' => 'test_resource',
            'notificationUrl' => 'https://test.com/webhook',
            'changeTypes' => ['created', 'updated'],
            'clientState' => 'test_token'
        ]);
        
        $this->assertNotEmpty($subscription['subscriptionId']);
        $this->assertEquals('test_resource', $subscription['resourceId']);
    }
    
    public function testNotificationDelivery()
    {
        $service = new BookingWebhookDeliveryService($this->db, $this->httpClient, $this->logger);
        
        // Mock HTTP client
        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturn(new Response(200));
        
        $result = $service->deliverNotification([
            'subscriptionId' => 'test_sub',
            'changeType' => 'created',
            'eventId' => 'evt_123',
            'resourceId' => 'resource_1'
        ]);
        
        $this->assertTrue($result['success']);
    }
    
    public function testChangeDetection()
    {
        $detector = new BookingChangeDetector($this->db);
        
        // Create baseline snapshot
        $detector->saveSnapshot('resource_1', [
            ['id' => 'evt_1', 'subject' => 'Meeting 1'],
            ['id' => 'evt_2', 'subject' => 'Meeting 2']
        ]);
        
        // Simulate changes
        $changes = $detector->detectChanges('resource_1', [
            ['id' => 'evt_1', 'subject' => 'Meeting 1 Updated'], // Updated
            ['id' => 'evt_3', 'subject' => 'Meeting 3'] // Created
            // evt_2 deleted
        ]);
        
        $this->assertCount(1, $changes['created']);
        $this->assertCount(1, $changes['updated']);
        $this->assertCount(1, $changes['deleted']);
    }
}
```

### 7.2 Integration Tests

```bash
# Test subscription creation
curl -X POST http://booking-system.local/api/webhooks/subscriptions \
  -H "Content-Type: application/json" \
  -d '{
    "resourceId": "room_101",
    "notificationUrl": "https://sync.example.com/bridges/webhook/booking_system",
    "changeTypes": ["created", "updated", "deleted"],
    "clientState": "test123",
    "expirationMinutes": 60
  }'

# Test validation handshake
curl "http://booking-system.local/api/webhooks/validate?validationToken=test123"

# Test notification delivery (simulate)
curl -X POST https://sync.example.com/bridges/webhook/booking_system \
  -H "Content-Type: application/json" \
  -d '{
    "value": [{
      "subscriptionId": "bs-sub-123",
      "changeType": "created",
      "resource": "resources/room_101/events/evt_456",
      "resourceData": {
        "id": "evt_456",
        "type": "booking_event"
      },
      "clientState": "test123",
      "timestamp": "2025-10-10T14:30:00Z"
    }]
  }'
```

### 7.3 Load Tests

```bash
# Test webhook delivery performance
ab -n 1000 -c 10 -p notification.json -T application/json \
  https://sync.example.com/bridges/webhook/booking_system

# Monitor queue processing
watch -n 1 'psql -c "SELECT status, COUNT(*) FROM booking_webhook_queue GROUP BY status"'
```

---

## 8. Monitoring and Troubleshooting

### 8.1 Key Metrics to Track

```sql
-- Active subscriptions
SELECT COUNT(*) as active_subscriptions
FROM booking_webhook_subscriptions
WHERE is_active = TRUE AND expires_at > NOW();

-- Subscription health (delivery success rate)
SELECT 
    ws.subscription_id,
    ws.resource_id,
    ws.notification_count,
    ws.failure_count,
    ROUND((ws.notification_count - ws.failure_count)::numeric / 
          NULLIF(ws.notification_count, 0) * 100, 2) as success_rate
FROM booking_webhook_subscriptions ws
WHERE ws.is_active = TRUE
ORDER BY success_rate ASC;

-- Pending notifications
SELECT COUNT(*) as pending_count,
       MIN(created_at) as oldest_pending
FROM booking_webhook_queue
WHERE status = 'pending';

-- Failed deliveries (last 24h)
SELECT wd.subscription_id,
       COUNT(*) as failure_count,
       array_agg(DISTINCT wd.error_message) as errors
FROM booking_webhook_deliveries wd
WHERE wd.delivery_status = 'failed'
  AND wd.created_at > NOW() - INTERVAL '24 hours'
GROUP BY wd.subscription_id;

-- Expiring subscriptions (next 24h)
SELECT subscription_id, resource_id, expires_at
FROM booking_webhook_subscriptions
WHERE is_active = TRUE
  AND expires_at BETWEEN NOW() AND NOW() + INTERVAL '24 hours'
ORDER BY expires_at ASC;
```

### 8.2 Alerting Rules

```php
// Alert if pending queue grows too large
if ($pendingCount > 1000)
{
    $alertService->send('Webhook queue backup: ' . $pendingCount . ' pending');
}

// Alert if subscription expiring soon
if ($expiringIn24h > 0)
{
    $alertService->send($expiringIn24h . ' subscriptions expiring in 24h');
}

// Alert if delivery failure rate > 10%
if ($failureRate > 10)
{
    $alertService->send('High webhook failure rate: ' . $failureRate . '%');
}
```

### 8.3 Debugging Tools

```bash
# Tail webhook logs
tail -f /var/log/booking-system/webhooks.log | grep "subscription_id"

# Check subscription details
curl http://booking-system.local/api/webhooks/subscriptions/bs-sub-123

# Manually trigger notification for testing
curl -X POST http://booking-system.local/api/webhooks/test \
  -d '{"subscriptionId": "bs-sub-123", "eventId": "evt_test"}'

# Reprocess failed notifications
curl -X POST http://booking-system.local/api/webhooks/retry-failed \
  -d '{"subscriptionId": "bs-sub-123", "limit": 10}'
```

---

## 9. Configuration Example

### booking_system_config.json

```json
{
  "webhook": {
    "enabled": true,
    "max_subscriptions_per_resource": 10,
    "default_expiration_minutes": 4230,
    "max_expiration_minutes": 10080,
    "validation_timeout_seconds": 5,
    "delivery_timeout_seconds": 10,
    "max_retry_attempts": 5,
    "retry_delays_seconds": [0, 30, 300, 1800, 7200],
    "batch_size": 50,
    "queue_processing_interval_seconds": 60,
    "change_detection_method": "triggers",
    "signature_algorithm": "sha256",
    "require_https": true,
    "rate_limit": {
      "per_minute": 60,
      "per_hour": 1000
    }
  },
  "api_endpoints": {
    "create_subscription": "/api/webhooks/subscriptions",
    "renew_subscription": "/api/webhooks/subscriptions/{subscriptionId}",
    "delete_subscription": "/api/webhooks/subscriptions/{subscriptionId}",
    "list_subscriptions": "/api/webhooks/subscriptions",
    "validate": "/api/webhooks/validate"
  }
}
```

---

## 10. Deployment Checklist

- [ ] Database schema deployed
- [ ] Webhook API endpoints implemented
- [ ] Change detection system configured (triggers or polling)
- [ ] Notification delivery service deployed
- [ ] Queue processor cron job configured
- [ ] Subscription renewal cron job configured
- [ ] BookingSystemBridge updated with webhook support
- [ ] BridgeController updated with transformation logic
- [ ] HTTPS certificates configured
- [ ] Monitoring dashboards created
- [ ] Alert rules configured
- [ ] Integration tests passing
- [ ] Load tests completed
- [ ] Documentation updated
- [ ] Team training completed

---

## 11. Timeline Summary

| Phase | Duration | Deliverables |
|-------|----------|-------------|
| Phase 1: Database Schema | Week 1 | Tables created, migrations tested |
| Phase 2: Subscription API | Week 1-2 | CRUD endpoints, validation handshake |
| Phase 3: Change Detection | Week 2-3 | Triggers or polling system |
| Phase 4: Delivery System | Week 3-4 | Queue processor, retry logic |
| Phase 5: Integration | Week 4 | Bridge updates, testing |
| **Total** | **4 weeks** | **Production-ready webhook system** |

---

## 12. Success Criteria

✅ **Functional Requirements:**
- Subscriptions can be created, renewed, and deleted
- Changes are detected within 60 seconds
- Notifications delivered with 99% success rate
- Retry logic handles temporary failures
- Supports all change types (created, updated, deleted)

✅ **Non-Functional Requirements:**
- System handles 1000+ notifications per hour
- Queue processing latency < 5 minutes
- Database queries optimized (< 100ms)
- Memory usage < 512MB
- CPU usage < 25% under normal load

✅ **Integration Requirements:**
- Works seamlessly with OutlookBookingSync
- Follows same patterns as Outlook webhooks
- Maintains multi-tenancy support
- Respects ownership rules (sync_direction)
- Compatible with existing FastCGI architecture

---

## Next Steps

1. **Review this document** with your team
2. **Set up development environment** for booking system
3. **Create database migrations** (Phase 1)
4. **Implement subscription API** (Phase 2)
5. **Choose change detection method** and implement (Phase 3)
6. **Build delivery system** (Phase 4)
7. **Integrate with OutlookBookingSync** (Phase 5)
8. **Test thoroughly** before production deployment

---

*Document Version: 1.0*  
*Last Updated: October 10, 2025*  
*Author: GitHub Copilot*
