# Booking System Webhook API Usage Guide

## Overview

The booking system now supports webhooks for real-time event, allocation, and booking notifications. This allows external systems (like calendar bridges) to receive immediate notifications when entities change.

## Configuration

Configuration is managed through the same location as Outlook integration:
- Location: `booking.run`
- Section: `Outlook`

### Configuration Parameters

```php
'Outlook' => [
    'api_key' => 'your-api-key-here',           // API key for webhook authentication
    'webhook_secret' => 'your-secret-key-here', // Secret for HMAC signature
    'webhook_enabled' => true                    // Enable/disable webhooks
]
```

## REST API Endpoints

All webhook management endpoints require authentication via session.

### Base URL
```
/booking/api/webhooks
```

### 1. Create Subscription

Create a new webhook subscription to receive notifications.

**Endpoint:** `POST /booking/api/webhooks/subscriptions`

**Request Body:**
```json
{
  "resourceType": "event",           // Required: 'event', 'allocation', 'booking', or 'all'
  "resourceId": 123,                 // Optional: specific resource ID
  "notificationUrl": "https://example.com/webhook", // Required: your webhook endpoint
  "changeTypes": ["created", "updated", "deleted"], // Optional: defaults to all
  "clientState": "custom-data",      // Optional: any string you want echoed back
  "secretKey": "your-secret",        // Optional: for HMAC signature verification
  "expirationMinutes": 43200         // Optional: defaults to 30 days (43200 min), max 30 days
}
```

**Response:**
```json
{
  "success": true,
  "subscription": {
    "subscriptionId": "sub_abc123...",
    "resourceType": "event",
    "resourceId": 123,
    "notificationUrl": "https://example.com/webhook",
    "changeTypes": ["created", "updated", "deleted"],
    "expirationDateTime": "2024-02-15 10:30:00",
    "createdDateTime": "2024-01-15 10:30:00"
  }
}
```

### 2. List Subscriptions

Get all webhook subscriptions with optional filtering.

**Endpoint:** `GET /booking/api/webhooks/subscriptions`

**Query Parameters:**
- `resourceType` (optional): Filter by resource type
- `resourceId` (optional): Filter by resource ID
- `isActive` (optional): Filter by active status (true/false)

**Response:**
```json
{
  "subscriptions": [
    {
      "subscriptionId": "sub_abc123...",
      "resourceType": "event",
      "resourceId": 123,
      "notificationUrl": "https://example.com/webhook",
      "changeTypes": ["created", "updated", "deleted"],
      "isActive": true,
      "expiresAt": "2024-02-15 10:30:00",
      "createdAt": "2024-01-15 10:30:00",
      "notificationCount": 42,
      "failureCount": 0
    }
  ]
}
```

### 3. Get Subscription

Get details of a specific subscription.

**Endpoint:** `GET /booking/api/webhooks/subscriptions/{subscriptionId}`

**Response:**
```json
{
  "subscription": {
    "subscriptionId": "sub_abc123...",
    "resourceType": "event",
    "resourceId": 123,
    "notificationUrl": "https://example.com/webhook",
    "changeTypes": ["created", "updated", "deleted"],
    "clientState": "custom-data",
    "isActive": true,
    "expiresAt": "2024-02-15 10:30:00",
    "createdBy": 1,
    "createdAt": "2024-01-15 10:30:00",
    "lastNotificationAt": "2024-01-16 14:22:00",
    "notificationCount": 42,
    "failureCount": 0
  }
}
```

### 4. Renew Subscription

Extend the expiration date of a subscription.

**Endpoint:** `PATCH /booking/api/webhooks/subscriptions/{subscriptionId}`

**Request Body:**
```json
{
  "expirationMinutes": 43200  // Optional: defaults to 30 days
}
```

**Response:**
```json
{
  "success": true,
  "subscription": { /* updated subscription object */ }
}
```

### 5. Delete Subscription

Permanently delete a subscription.

**Endpoint:** `DELETE /booking/api/webhooks/subscriptions/{subscriptionId}`

**Response:**
```json
{
  "success": true
}
```

### 6. Get Delivery Log

Get the delivery log for a subscription to see webhook delivery history.

**Endpoint:** `GET /booking/api/webhooks/subscriptions/{subscriptionId}/log`

**Query Parameters:**
- `limit` (optional): Maximum number of log entries (default: 100, max: 100)

**Response:**
```json
{
  "deliveryLog": [
    {
      "id": 1,
      "changeType": "created",
      "entityType": "event",
      "entityId": 456,
      "resourceId": 123,
      "httpStatusCode": 200,
      "responseTimeMs": 125,
      "errorMessage": null,
      "createdAt": "2024-01-16 14:22:00"
    }
  ]
}
```

### 7. Validation Endpoint

Used for webhook endpoint validation (handshake). No authentication required.

**Endpoint:** `GET /booking/api/webhooks/validate?validationToken={token}`

**Response:** Echoes back the validationToken

## Webhook Notification Format

When an entity changes, the webhook will receive a POST request with this payload:

```json
{
  "value": [
    {
      "subscriptionId": "sub_abc123...",
      "changeType": "created",
      "resourceType": "event",
      "resourceId": 123,
      "entityType": "event",
      "entityId": 456,
      "entityData": {
        "id": 456,
        "name": "Team Meeting",
        "from_": "2024-01-20 10:00:00",
        "to_": "2024-01-20 11:00:00",
        /* ... full entity data ... */
      },
      "clientState": "custom-data",
      "timestamp": "2024-01-16T14:22:00+00:00",
      "resources": [123]
    }
  ]
}
```

### HTTP Headers

The webhook POST request includes:

```
Content-Type: application/json
Accept: application/json
User-Agent: PorticoEstate-Webhook/1.0
X-API-Key: {your-api-key}                      // If configured
X-Booking-Signature: sha256={hmac-signature}   // If secretKey was provided
```

## Security

### HMAC Signature Verification

If you provide a `secretKey` when creating a subscription, all webhook deliveries will include an HMAC signature in the `X-Booking-Signature` header.

To verify the signature:

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_BOOKING_SIGNATURE'];

// Extract the hash from the header
list($algo, $hash) = explode('=', $signature, 2);

// Calculate expected hash
$expectedHash = hash_hmac($algo, $payload, $secretKey);

// Compare
if (hash_equals($expectedHash, $hash)) {
    // Signature is valid
    $data = json_decode($payload, true);
    // Process webhook...
}
```

### HTTPS Requirement

For production, webhook URLs should use HTTPS to ensure secure transmission of data.

## Async Processing

Webhook notifications are sent asynchronously using `fastcgi_finish_request()` to avoid blocking the main application response. This ensures that:
- Users don't experience delays when creating/updating entities
- Webhook delivery failures don't affect the main operation
- Response times remain fast

## Error Handling

- Webhook delivery failures are logged but don't affect the main operation
- Failed deliveries increment the `failure_count` on the subscription
- Delivery logs track HTTP status codes and error messages
- Subscriptions remain active even after failures (no automatic deactivation)

## Example Usage

### Create a Subscription for All Events

```bash
curl -X POST http://localhost/booking/api/webhooks/subscriptions \
  -H "Content-Type: application/json" \
  -H "Cookie: sessionidsessid=your-session-id" \
  -d '{
    "resourceType": "event",
    "notificationUrl": "https://bridge.example.com/webhook",
    "secretKey": "my-secret-key-123"
  }'
```

### Create a Subscription for Specific Resource

```bash
curl -X POST http://localhost/booking/api/webhooks/subscriptions \
  -H "Content-Type: application/json" \
  -H "Cookie: sessionidsessid=your-session-id" \
  -d '{
    "resourceType": "event",
    "resourceId": 123,
    "notificationUrl": "https://bridge.example.com/webhook/resource/123",
    "changeTypes": ["created", "updated"],
    "clientState": "resource-123",
    "secretKey": "my-secret-key-123"
  }'
```

### List Active Subscriptions

```bash
curl -X GET "http://localhost/booking/api/webhooks/subscriptions?isActive=true" \
  -H "Cookie: sessionidsessid=your-session-id"
```

### Delete a Subscription

```bash
curl -X DELETE http://localhost/booking/api/webhooks/subscriptions/sub_abc123... \
  -H "Cookie: sessionidsessid=your-session-id"
```

## Database Schema

### bb_webhook_subscriptions

Stores webhook subscription information.

| Column | Type | Description |
|--------|------|-------------|
| id | auto | Primary key |
| subscription_id | varchar(255) | Unique subscription identifier |
| resource_type | varchar(50) | Type: event, allocation, booking, or all |
| resource_id | int | Optional specific resource ID |
| notification_url | text | Webhook endpoint URL |
| change_types | varchar(255) | Comma-separated: created,updated,deleted |
| client_state | varchar(255) | Optional client data |
| secret_key | varchar(255) | Optional HMAC secret |
| is_active | int | 1 if active, 0 if deactivated |
| expires_at | timestamp | Expiration date/time |
| created_by | int | Account ID of creator |
| created_at | timestamp | Creation date/time |
| last_notification_at | timestamp | Last successful delivery |
| notification_count | int | Total deliveries |
| failure_count | int | Failed delivery count |

### bb_webhook_delivery_log

Tracks webhook delivery attempts.

| Column | Type | Description |
|--------|------|-------------|
| id | auto | Primary key |
| subscription_id | varchar(255) | Related subscription |
| change_type | varchar(50) | created, updated, or deleted |
| entity_type | varchar(50) | event, allocation, or booking |
| entity_id | int | ID of the changed entity |
| resource_id | int | Associated resource ID |
| http_status_code | int | HTTP response code |
| response_time_ms | int | Response time in milliseconds |
| error_message | text | Error details if failed |
| created_at | timestamp | Delivery attempt time |

## Troubleshooting

### Webhooks Not Being Sent

1. Check if webhooks are enabled in configuration:
   ```php
   $custom_config_data['Outlook']['webhook_enabled'] = true;
   ```

2. Verify subscription is active and not expired:
   ```bash
   curl -X GET http://localhost/booking/api/webhooks/subscriptions/{id}
   ```

3. Check logs for errors:
   - Application logs for webhook delivery failures
   - Database `bb_webhook_delivery_log` table

### Webhook Delivery Failures

1. Verify the notification URL is accessible
2. Check if your webhook endpoint responds within 10 seconds (timeout)
3. Review delivery log for specific error messages
4. Ensure your endpoint returns HTTP 2xx status codes

### HMAC Signature Issues

1. Verify you're using the correct secret key
2. Ensure you're computing the hash on the raw request body (not parsed JSON)
3. Use `hash_equals()` for comparison to prevent timing attacks

## Support

For issues or questions about the webhook system:
1. Check the delivery log for error details
2. Review application logs
3. Verify configuration settings
4. Test with the validation endpoint first
