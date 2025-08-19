# External User Data Initialization Enhancement

## Overview
This enhancement ensures that first-time users logging in through the Next.js client have their initial data properly initialized from external services (MinID/Fellesdata) and stored in the local database.

## Problem Addressed
Previously, when users authenticated via MinID/external ID for the first time through the Next.js client, their external user data (name, address, etc.) was fetched from external services but not always properly stored in the local database, leading to incomplete user profiles.

## Solution Implementation

### 1. Backend Changes

#### UserHelper.php Enhancements
**File**: `src/modules/bookingfrontend/helpers/UserHelper.php`

- **Enhanced `validate_ssn_login()` method**: Added call to `initialize_user_data()` after external data is fetched
- **New `initialize_user_data()` method**: Handles first-time user data initialization
- **New `create_user_from_external_data()` method**: Creates new user records from external data
- **New `update_user_from_external_data()` method**: Updates existing users with latest external data

Key changes:
```php
// In validate_ssn_login() method
$external_user->get_name_from_external_service($ret);

// Initialize user data in database if this is first-time login
$this->initialize_user_data($ret);
```

### 2. Frontend Changes

#### api-hooks.ts Enhancements
**File**: `src/modules/bookingfrontend/client/src/service/hooks/api-hooks.ts`

- **Enhanced `useBookingUser()` hook**: Added WebSocket subscription to listen for `refresh_bookinguser` messages
- **Fallback detection**: Retained existing logic to detect first-time users with minimal data as a backup

Key changes:
```typescript
// Handle WebSocket messages for booking user refresh
useMessageTypeSubscription('refresh_bookinguser', (message) => {
    console.log('Received booking user refresh WebSocket update');
    
    // Invalidate the booking user query to trigger a refetch
    // This ensures the user gets the latest data from the server
    queryClient.invalidateQueries({queryKey: ['bookingUser']});
});

// Fallback detection for first-time users (serves as backup if WebSocket fails)
if (userData.is_logged_in && (!userData.name || userData.name === '')) {
    setTimeout(() => {
        queryClient.invalidateQueries({queryKey: ['bookingUser']});
    }, 2000); // 2 second delay to allow external data fetch to complete
}
```

#### WebSocket Types Enhancement
**File**: `src/modules/bookingfrontend/client/src/service/websocket/websocket.types.ts`

- **New `IWSRefreshBookingUserMessage` interface**: Defines the structure for booking user refresh messages
- **Updated `WebSocketMessage` union type**: Includes the new refresh message type

```typescript
export interface IWSRefreshBookingUserMessage extends IWebSocketMessageBase {
  type: 'refresh_bookinguser';
  message: string;
  action: 'refresh';
}
```

### 3. WebSocket Integration

#### WebSocketHelper.php Enhancements
**File**: `src/modules/bookingfrontend/helpers/WebSocketHelper.php`

- **New `triggerBookingUserUpdate()` method**: Sends WebSocket notifications to connected clients when user data is updated
- **New `triggerBookingUserUpdateAsync()` method**: Asynchronous version for better performance

Key additions:
```php
public static function triggerBookingUserUpdate(string $sessionId = null): bool
{
    // Send WebSocket message to refresh booking user data
    $updateMessage = [
        'type' => 'refresh_bookinguser',
        'sessionId' => $sessionId,
        'message' => 'User data has been updated',
        'action' => 'refresh',
        'timestamp' => date('c')
    ];
    
    return self::sendRedisNotification($updateMessage, self::$redisSessionChannel);
}
```

#### WebSocket Server Enhancement
**File**: `src/WebSocket/server.php`

- **Added `refresh_bookinguser` message handler**: Processes incoming refresh requests and notifies connected clients

The WebSocket server now handles `refresh_bookinguser` messages and forwards them to the appropriate session room, allowing real-time updates without requiring page refreshes.

## Authentication Flow

### For First-Time Users:

1. **Authentication**: User logs in via MinID/external ID
2. **Session Validation**: `UserHelper::validate_ssn_login()` is called
3. **External Data Fetch**: If configured, external service (e.g., `navn_fra_fiks_folkeregister.php`) fetches user data from Fellesdata
4. **Data Initialization**: `initialize_user_data()` is called to:
   - Check if user exists in local database
   - Create new user record if first-time user
   - Update existing user record with latest external data
   - **Send WebSocket notification** to connected clients via `triggerBookingUserUpdate()`
5. **WebSocket Message Routing**: The WebSocket server processes the `refresh_bookinguser` message and forwards it to the user's session room
6. **Client-Side Reception**: Next.js client's `useBookingUser()` hook receives the WebSocket message via `useMessageTypeSubscription()`
7. **Real-time Update**: Client immediately invalidates the booking user query, triggering a fresh API call to get updated user data
8. **Fallback Detection**: Next.js client still has fallback logic to detect minimal user data and triggers refetch after 2 seconds if WebSocket notification fails
9. **Complete Profile**: User now has complete profile data available immediately through real-time WebSocket updates

### External Data Sources

The system integrates with:
- **MinID**: Norwegian national ID system for authentication
- **Fellesdata/Fiks Folkeregister**: Norwegian public registry for user data
- **Custom external services**: Via configurable external user name services

## Configuration

### Backend Configuration
In `bookingfrontend` config:
- `get_name_from_external`: Specify the external service file name (e.g., `navn_fra_fiks_folkeregister.php`)
- External service configuration files in: `src/modules/bookingfrontend/inc/custom/default/`

### Available External Services
1. **navn_fra_fiks_folkeregister.php**: Fetches user data from Fiks Folkeregister
2. **MinId2.php**: Handles MinID organization data

## Database Schema

The enhancement works with the existing `bb_user` table structure:
- `customer_ssn`: User's social security number (primary identifier)
- `name`: Full name from external service
- `email`: Email address
- `phone`: Phone number
- `street`: Street address
- `zip_code`: Postal code  
- `city`: City name
- `created`: Record creation timestamp
- `updated`: Last update timestamp

## Error Handling

- **External Service Errors**: Logged but don't prevent login
- **Database Errors**: Logged with appropriate error messages
- **Fallback**: System continues to work even if external data fetch fails

## Security Considerations

- SSN is stored securely and partially masked in logs
- External service calls are made server-side only
- No sensitive data exposed to client-side
- Proper validation of external data before storage

## Testing

A debug endpoint has been added to manually trigger booking user WebSocket updates:

**Endpoint**: `GET /bookingfrontend/debug/trigger-bookinguser-update`

**Query Parameters** (optional):
- `sessionId`: Specific session ID to target (falls back to current session if not provided)

**Response**:
```json
{
  "success": true,
  "message": "Booking user update triggered successfully",
  "messageType": "refresh_bookinguser",
  "sessionId": "12345678...",
  "description": "This sent a WebSocket message to refresh booking user data in connected clients",
  "usage": "You can also specify a sessionId: GET /bookingfrontend/debug/trigger-bookinguser-update?sessionId=your-session-id",
  "timestamp": "2025-01-08T12:00:00+00:00"
}
```

**Usage Examples**:
```bash
# Test with current session (can be opened directly in browser)
GET /bookingfrontend/debug/trigger-bookinguser-update

# Test with specific session ID
GET /bookingfrontend/debug/trigger-bookinguser-update?sessionId=your-session-id

# Using cURL
curl '/bookingfrontend/debug/trigger-bookinguser-update'
curl '/bookingfrontend/debug/trigger-bookinguser-update?sessionId=your-session-id'
```

## Benefits

1. **Complete User Profiles**: First-time users get complete profile data immediately
2. **Real-time Updates**: WebSocket integration provides instant user data refresh without page reloads
3. **Seamless UX**: Next.js client users get the same experience as traditional web users
4. **Data Consistency**: User data is kept up-to-date with external sources
5. **Robust Fallbacks**: Multiple layers of fallback ensure system works even if external services or WebSocket are unavailable
6. **Performance**: Asynchronous WebSocket notifications don't block the authentication flow
7. **Logging**: Comprehensive logging for debugging and monitoring both database operations and WebSocket events

## Backward Compatibility

This enhancement is fully backward compatible:
- Existing users are unaffected
- Traditional web authentication continues to work
- No breaking changes to APIs or database schema