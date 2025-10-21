<?php

namespace App\modules\bookingfrontend\controllers;

use App\Database\Db;
use App\modules\bookingfrontend\helpers\ResponseHelper;
use App\modules\bookingfrontend\helpers\UserHelper;
use App\modules\bookingfrontend\models\User;
use App\modules\phpgwapi\services\Cache;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Exception;

/**
 * @OA\Tag(
 *     name="User",
 *     description="API Endpoints for User"
 * )
 */
class BookingUserController
{

	/**
	 * Whitelist of fields that can be updated
	 */
	private const ALLOWED_FIELDS = [
		'name' => true,
		'homepage' => true,
		'phone' => true,
		'email' => true,
		'street' => true,
		'zip_code' => true,
		'city' => true
	];

	private $container;
	private $db;

	public function __construct(ContainerInterface $container)
	{
		$this->container = $container;
		$this->db = Db::getInstance();
	}


	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/user",
	 *     summary="Get authenticated user details",
	 *     tags={"User"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="User details or null if not authenticated",
	 *         @OA\JsonContent(ref="#/components/schemas/User")
	 *     )
	 * )
	 */
	public function index(Request $request, Response $response): Response
	{
		try
		{
			$bouser = new UserHelper();

			// If user is not logged in, return null instead of 401
			if (!$bouser->is_logged_in())
			{
				return ResponseHelper::sendJSONResponse(null, 200);
			}

			// Validate SSN login and get user data
			$external_login_info = $bouser->validate_ssn_login([], true);
			if (empty($external_login_info))
			{
				// Invalid or expired session - return null like unauthenticated
				return ResponseHelper::sendJSONResponse(null, 200);
			}


			$userModel = new User($bouser);
//            if (isset($userModel->ssn)) {
//                $userModel->ssn = $this->maskSSN($userModel->ssn);
//            }
			$serialized = $userModel->serialize();

			// Check if this is a first-time user who needs to complete their profile
			$needsProfileCreation = empty($serialized['name']) || trim($serialized['name']) === '';
			$serialized['needs_profile_creation'] = $needsProfileCreation;

			return ResponseHelper::sendJSONResponse($serialized, 200);

		} catch (Exception $e)
		{
			// Log the error but don't expose internal details
			error_log("Error in user endpoint: " . $e->getMessage());

			return ResponseHelper::sendErrorResponse(
				['error' => 'Internal server error'],
				500
			);
		}
	}

	/**
	 * @OA\Patch(
	 *     path="/bookingfrontend/user",
	 *     summary="Update user details",
	 *     tags={"User"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(
	 *             @OA\Property(property="name", type="string"),
	 *             @OA\Property(property="homepage", type="string"),
	 *             @OA\Property(property="phone", type="string"),
	 *             @OA\Property(property="email", type="string"),
	 *             @OA\Property(property="street", type="string"),
	 *             @OA\Property(property="zip_code", type="string"),
	 *             @OA\Property(property="city", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="User updated successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="message", type="string"),
	 *             @OA\Property(property="user", ref="#/components/schemas/User")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Invalid input"
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="User not authenticated"
	 *     ),
	 *     @OA\Response(
	 *         response=403,
	 *         description="Forbidden - Cannot update other users"
	 *     )
	 * )
	 */
	public function update(Request $request, Response $response): Response
	{
		try
		{
			$bouser = new UserHelper();

			if (!$bouser->is_logged_in())
			{
				return ResponseHelper::sendErrorResponse(['error' => 'User not authenticated'], 401);
			}

			// Get current user's SSN
			$userSsn = $bouser->ssn;
			if (empty($userSsn))
			{
				return ResponseHelper::sendErrorResponse(['error' => 'No SSN found for user'], 400);
			}

			// Get the user ID from SSN
			$userId = $bouser->get_user_id($userSsn);
			if (!$userId)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'User not found'], 404);
			}

			// Get update data from request body
			$data = json_decode($request->getBody()->getContents(), true);
			if (json_last_error() !== JSON_ERROR_NONE)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Invalid JSON data'], 400);
			}

			// Only allow whitelisted fields
			$updateData = [];
			foreach ($data as $field => $value)
			{
				if (isset(self::ALLOWED_FIELDS[$field]))
				{
					$updateData[$field] = $value;
				}
			}

			if (empty($updateData))
			{
				return ResponseHelper::sendErrorResponse(['error' => 'No valid fields to update'], 400);
			}

			// Validate fields
			if (isset($updateData['email']))
			{
				if (!filter_var($updateData['email'], FILTER_VALIDATE_EMAIL))
				{
					return ResponseHelper::sendErrorResponse(['error' => 'Invalid email format'], 400);
				}
			}

			if (isset($updateData['homepage']))
			{
				if (!empty($updateData['homepage']) && !filter_var($updateData['homepage'], FILTER_VALIDATE_URL))
				{
					return ResponseHelper::sendErrorResponse(['error' => 'Invalid homepage URL format'], 400);
				}
			}

			// Build SQL using only whitelisted fields
			$setClauses = [];
			$params = [':id' => $userId];

			foreach ($updateData as $field => $value)
			{
				if (isset(self::ALLOWED_FIELDS[$field]))
				{
					$setClauses[] = $field . ' = :' . $field;
					$params[':' . $field] = $value;
				}
			}

			if (empty($setClauses))
			{
				return ResponseHelper::sendErrorResponse(['error' => 'No valid fields to update'], 400);
			}

			$sql = "UPDATE bb_user SET " . implode(', ', $setClauses) . " WHERE id = :id";

			// Execute update
			$stmt = $this->db->prepare($sql);
			$stmt->execute($params);

			if ($stmt->rowCount() === 0)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'User not found or no changes made'], 404);
			}

			// Get updated user data
			$userModel = new User($bouser);
			$serialized = $userModel->serialize();

			return ResponseHelper::sendJSONResponse([
				'message' => 'User updated successfully',
				'user' => $serialized
			], 200);

		} catch (Exception $e)
		{
			$error = "Error updating user: " . $e->getMessage();
			return ResponseHelper::sendErrorResponse(['error' => $error], 500);
		}
	}

	private function maskSSN(string $ssn): string
	{
		if (empty($ssn))
		{
			return '';
		}
		// Keep first digits, replace last 5 with asterisks
		return substr($ssn, 0, -5) . '*****';
	}


	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/user/messages",
	 *     summary="Get system messages for the current user",
	 *     tags={"User"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="System messages",
	 *         @OA\JsonContent(
	 *             type="array",
	 *             @OA\Items(
	 *                 @OA\Property(property="type", type="string", description="Message type (error, success, info, warning)"),
	 *                 @OA\Property(property="text", type="string", description="Message text"),
	 *                 @OA\Property(property="title", type="string", description="Optional message title"),
	 *                 @OA\Property(property="class", type="string", description="CSS class for styling")
	 *             )
	 *         )
	 *     )
	 * )
	 */
	public function getMessages(Request $request, Response $response): Response
	{
		try
		{
			// Fetch cached messages
			$messages = Cache::session_get('phpgwapi', 'phpgw_messages');

			// Process messages if they exist
			$processed_messages = [];
			if ($messages)
			{
				require_once SRC_ROOT_PATH . '/helpers/LegacyObjectHandler.php';
				$phpgwapi_common = new \phpgwapi_common();
				$msgbox_data = $phpgwapi_common->msgbox_data($messages);
				$msgbox_data = $phpgwapi_common->msgbox($msgbox_data);

				foreach ($msgbox_data as $message)
				{
					// Convert internal message format to API response format
					$type = 'info'; // Default type
					if (strpos($message['msgbox_class'], 'alert-danger') !== false)
					{
						$type = 'error';
					} elseif (strpos($message['msgbox_class'], 'alert-success') !== false)
					{
						$type = 'success';
					} elseif (strpos($message['msgbox_class'], 'alert-warning') !== false)
					{
						$type = 'warning';
					}

					$messageData = [
						'type' => $type,
						'text' => $message['msgbox_text'],
						'class' => $message['msgbox_class']
					];

					// Add the message ID if it exists
					if (isset($message['msgbox_id']))
					{
						$messageData['id'] = $message['msgbox_id'];
					}

					// Add the title if it exists
					if (isset($message['msgbox_title']))
					{
						$messageData['title'] = $message['msgbox_title'];
					}

					$processed_messages[] = $messageData;
				}
			}

			// Return the processed messages
			return ResponseHelper::sendJSONResponse($processed_messages, 200);
		} catch (Exception $e)
		{
			// Log the error but don't expose internal details
			error_log("Error fetching messages: " . $e->getMessage());

			return ResponseHelper::sendErrorResponse(
				['error' => 'Internal server error'],
				500
			);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/user/messages/test",
	 *     summary="Create a test message with a title",
	 *     tags={"User"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Test message created",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="success", type="boolean"),
	 *             @OA\Property(property="message", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function createTestMessage(Request $request, Response $response): Response
	{
		try
		{
			// Clear any existing messages
			Cache::session_clear('phpgwapi', 'phpgw_messages');

			// Create a test message with a title
			$messageText = "This is a test message with a title";
			$messageTitle = 'booking.booking confirmed';

			// Store the message with a title
			Cache::message_set($messageText, 'message', $messageTitle);

			// Return success response
			return ResponseHelper::sendJSONResponse([
				'success' => true,
				'message' => 'Test message created with title: ' . $messageTitle
			], 200);
		} catch (Exception $e)
		{
			// Log the error but don't expose internal details
			error_log("Error creating test message: " . $e->getMessage());

			return ResponseHelper::sendErrorResponse(
				['error' => 'Internal server error'],
				500
			);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/user/session",
	 *     summary="Get current user's session ID",
	 *     tags={"User"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="Session ID successfully retrieved",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="sessionId", type="string", description="The current session ID")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="User not authenticated"
	 *     )
	 * )
	 */
	public function getSessionId(Request $request, Response $response): Response
	{
		try
		{
			// Get the session ID from PHP's session
			$sessionId = session_id();

			if (!$sessionId)
			{
				return ResponseHelper::sendErrorResponse(
					['error' => 'No active session found'],
					401
				);
			}

			// Return the session ID
			return ResponseHelper::sendJSONResponse([
				'sessionId' => $sessionId
			], 200);

		} catch (Exception $e)
		{
			// Log the error but don't expose internal details
			error_log("Error retrieving session ID: " . $e->getMessage());

			return ResponseHelper::sendErrorResponse(
				['error' => 'Internal server error'],
				500
			);
		}
	}

	/**
	 * @OA\Get(
	 *     path="/bookingfrontend/user/external-data",
	 *     summary="Get external user data for pre-filling creation form",
	 *     tags={"User"},
	 *     @OA\Response(
	 *         response=200,
	 *         description="External user data or null if not authenticated/available",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="name", type="string"),
	 *             @OA\Property(property="email", type="string"),
	 *             @OA\Property(property="phone", type="string"),
	 *             @OA\Property(property="street", type="string"),
	 *             @OA\Property(property="zip_code", type="string"),
	 *             @OA\Property(property="city", type="string"),
	 *             @OA\Property(property="ssn", type="string")
	 *         )
	 *     )
	 * )
	 */
	public function getExternalData(Request $request, Response $response): Response
	{
		try
		{
			$bouser = new UserHelper();

			if (!$bouser->is_logged_in())
			{
				// Return null instead of 401 when not authenticated
				return ResponseHelper::sendJSONResponse(null, 200);
			}

			// Get external login info which should contain the external data
			$external_login_info = $bouser->validate_ssn_login([], true);

			if (empty($external_login_info))
			{
				// Return null instead of 404 when no external data available
				return ResponseHelper::sendJSONResponse(null, 200);
			}

			// Return the external data that can be used to pre-fill the form
			$externalData = [
				'name' => $external_login_info['name'] ?? '',
				'email' => $external_login_info['email'] ?? '',
				'phone' => $external_login_info['phone'] ?? '',
				'street' => $external_login_info['street'] ?? '',
				'zip_code' => $external_login_info['zip_code'] ?? '',
				'city' => $external_login_info['city'] ?? '',
				'ssn' => $bouser->ssn ?? ''
			];

			return ResponseHelper::sendJSONResponse($externalData, 200);

		} catch (Exception $e)
		{
			error_log("Error getting external user data: " . $e->getMessage());
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to get external data'], 500);
		}
	}

	/**
	 * @OA\Post(
	 *     path="/bookingfrontend/user/create",
	 *     summary="Create user account with external data",
	 *     tags={"User"},
	 *     @OA\RequestBody(
	 *         required=true,
	 *         @OA\JsonContent(
	 *             @OA\Property(property="name", type="string"),
	 *             @OA\Property(property="phone", type="string"),
	 *             @OA\Property(property="email", type="string"),
	 *             @OA\Property(property="street", type="string"),
	 *             @OA\Property(property="zip_code", type="string"),
	 *             @OA\Property(property="city", type="string"),
	 *             @OA\Property(property="homepage", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=201,
	 *         description="User created successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="message", type="string"),
	 *             @OA\Property(property="user", ref="#/components/schemas/User")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=400,
	 *         description="Invalid input or user already exists"
	 *     ),
	 *     @OA\Response(
	 *         response=401,
	 *         description="User not authenticated"
	 *     )
	 * )
	 */
	public function create(Request $request, Response $response): Response
	{
		try
		{
			$bouser = new UserHelper();

			if (!$bouser->is_logged_in())
			{
				return ResponseHelper::sendErrorResponse(['error' => 'User not authenticated'], 401);
			}

			// Get current user's SSN
			$userSsn = $bouser->ssn;
			if (empty($userSsn))
			{
				return ResponseHelper::sendErrorResponse(['error' => 'No SSN found for user'], 400);
			}

			// Check if user already exists
			$existingUserId = $bouser->get_user_id($userSsn);
			if ($existingUserId)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'User already exists'], 400);
			}

			// Get create data from request body
			$data = json_decode($request->getBody()->getContents(), true);
			if (json_last_error() !== JSON_ERROR_NONE)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Invalid JSON data'], 400);
			}

			// Validate required fields
			if (empty($data['name']))
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Name is required'], 400);
			}

			// Validate optional fields
			if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Invalid email format'], 400);
			}

			if (isset($data['homepage']) && !empty($data['homepage']) && !filter_var($data['homepage'], FILTER_VALIDATE_URL))
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Invalid homepage URL format'], 400);
			}

			// Prepare user data for creation (only use known valid columns)
			$createData = [
				'customer_ssn' => $userSsn,
				'name' => $data['name']
			];

			// Add optional fields only if they have values
			if (!empty($data['email'])) $createData['email'] = $data['email'];
			if (!empty($data['phone'])) $createData['phone'] = $data['phone'];
			if (!empty($data['street'])) $createData['street'] = $data['street'];
			if (!empty($data['zip_code'])) $createData['zip_code'] = $data['zip_code'];
			if (!empty($data['city'])) $createData['city'] = $data['city'];
			if (!empty($data['homepage'])) $createData['homepage'] = $data['homepage'];

			// Create the user directly in the database
			$placeholders = implode(',', array_fill(0, count($createData), '?'));
			$columns = implode(',', array_keys($createData));

			$sql = "INSERT INTO bb_user ({$columns}) VALUES ({$placeholders})";

			$stmt = $this->db->prepare($sql);
			$result = $stmt->execute(array_values($createData));

			if (!$result)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Failed to create user'], 500);
			}

			$userId = $this->db->lastInsertId();

			if (!$userId)
			{
				return ResponseHelper::sendErrorResponse(['error' => 'Failed to create user'], 500);
			}

			// Directly update the session data to include the newly created user
			$external_login_info = $bouser->validate_ssn_login([], true);
			if (!empty($external_login_info)) {
				// Merge external data with the newly created user data
				$session_data = array_merge($external_login_info, [
					'name' => $data['name'],
					'email' => $data['email'] ?? $external_login_info['email'] ?? '',
					'phone' => $data['phone'] ?? $external_login_info['phone'] ?? '',
					'street' => $data['street'] ?? $external_login_info['street'] ?? '',
					'zip_code' => $data['zip_code'] ?? $external_login_info['zip_code'] ?? '',
					'city' => $data['city'] ?? $external_login_info['city'] ?? '',
					'homepage' => $data['homepage'] ?? ''
				]);

				// Set the updated session data
				\App\modules\phpgwapi\services\Cache::session_set($bouser->get_module(), \App\modules\bookingfrontend\helpers\UserHelper::USERARRAY_SESSION_KEY, $session_data);
			}

			// Send WebSocket notification to refresh user data
			try
			{
				if (class_exists('\\App\\modules\\bookingfrontend\\helpers\\WebSocketHelper'))
				{
					$helper = new \App\modules\bookingfrontend\helpers\WebSocketHelper();
					$helper::triggerBookingUserUpdate();
				}
			} catch (\Exception $e)
			{
				error_log("WebSocket notification failed during user creation: " . $e->getMessage());
			}

			// Create a fresh UserHelper instance to get updated user data
			$refreshedBouser = new UserHelper();
			$userModel = new User($refreshedBouser);
			$serialized = $userModel->serialize();

			return ResponseHelper::sendJSONResponse([
				'message' => 'User created successfully',
				'user' => $serialized
			], 201);

		} catch (Exception $e)
		{
			error_log("Error creating user: " . $e->getMessage());
			return ResponseHelper::sendErrorResponse(['error' => 'Failed to create user: ' . $e->getMessage()], 500);
		}
	}

	/**
	 * @OA\Delete(
	 *     path="/bookingfrontend/user/messages/{id}",
	 *     summary="Delete a specific message by ID",
	 *     tags={"User"},
	 *     @OA\Parameter(
	 *         name="id",
	 *         in="path",
	 *         required=true,
	 *         description="ID of the message to delete",
	 *         @OA\Schema(type="string")
	 *     ),
	 *     @OA\Response(
	 *         response=200,
	 *         description="Message deleted successfully",
	 *         @OA\JsonContent(
	 *             @OA\Property(property="success", type="boolean"),
	 *             @OA\Property(property="message", type="string")
	 *         )
	 *     ),
	 *     @OA\Response(
	 *         response=404,
	 *         description="Message not found"
	 *     )
	 * )
	 */
	public function deleteMessage(Request $request, Response $response, array $args): Response
	{
		try
		{
			$messageId = $args['id'];

			if (empty($messageId))
			{
				return ResponseHelper::sendErrorResponse(
					['error' => 'Message ID is required'],
					400
				);
			}

			// Fetch cached messages
			$messages = Cache::session_get('phpgwapi', 'phpgw_messages');

			if (!$messages)
			{
				return ResponseHelper::sendErrorResponse(
					['error' => 'No messages found'],
					404
				);
			}

			$messageFound = false;

			// Loop through message types (error, message)
			foreach (['error', 'message'] as $type)
			{
				if (!isset($messages[$type]) || !is_array($messages[$type]))
				{
					continue;
				}

				// Filter out the message with the specified ID
				$filtered = [];
				foreach ($messages[$type] as $msg)
				{
					if (!isset($msg['id']) || $msg['id'] !== $messageId)
					{
						$filtered[] = $msg;
					} else
					{
						$messageFound = true;
					}
				}

				// Update the messages array with filtered results
				if (count($filtered) !== count($messages[$type]))
				{
					$messages[$type] = $filtered;
				}
			}

			if (!$messageFound)
			{
				return ResponseHelper::sendErrorResponse(
					['error' => 'Message not found with the specified ID'],
					404
				);
			}

			// Save the updated messages back to the session
			Cache::session_set('phpgwapi', 'phpgw_messages', $messages);

			// Send WebSocket notification about the deleted message
			try
			{
				if (class_exists('\\App\\modules\\bookingfrontend\\helpers\\WebSocketHelper'))
				{
					$sessionId = session_id();
					if (!empty($sessionId))
					{
						$helper = new \App\modules\bookingfrontend\helpers\WebSocketHelper();
						$helper::sendToSession(
							$sessionId,
							'server_message',
							[
								'type' => 'server_message',
								'action' => 'deleted',
								'message_ids' => [$messageId] // Send the specific message ID that was deleted
							]
						);
						error_log("WebSocket notification sent for deleted message ID: " . $messageId);
					}
				}
			} catch (\Exception $e)
			{
				error_log("Error sending WebSocket notification for deleted message: " . $e->getMessage());
			}

			// Return success response
			return ResponseHelper::sendJSONResponse([
				'success' => true,
				'message' => 'Message deleted successfully'
			], 200);

		} catch (Exception $e)
		{
			// Log the error but don't expose internal details
			error_log("Error deleting message: " . $e->getMessage());

			return ResponseHelper::sendErrorResponse(
				['error' => 'Internal server error'],
				500
			);
		}
	}
}
