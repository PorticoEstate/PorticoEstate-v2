<?php

namespace App\modules\bookingfrontend\services;

use App\modules\bookingfrontend\helpers\WebSocketHelper;

/**
 * Service for managing Next.js cache invalidation
 * Handles communication with the Next.js frontend to clear various cache types
 * Supports both HTTP-based server cache invalidation and WebSocket-based client cache invalidation
 */
class CacheService
{
	private $nextjsServer;
	private $basePath = '/bookingfrontend/client/api/cache-reset';

	public function __construct()
	{
		// Try NEXTJS_SERVER first (includes port), fallback to NEXTJS_HOST with default port
		$this->nextjsServer = getenv('NEXTJS_SERVER');
		if (!$this->nextjsServer)
		{
			$nextjsHost = getenv('NEXTJS_HOST');
			if ($nextjsHost)
			{
				$this->nextjsServer = "{$nextjsHost}:3000";
			}
		}
	}

	/**
	 * Invalidate Next.js image optimization cache
	 * Call this after uploading, updating, or deleting resource/building/organization images
	 * Also invalidates search data cache since it contains resource_pictures metadata
	 *
	 * Uses dual invalidation:
	 * 1. HTTP: Clears Next.js server-side image cache
	 * 2. WebSocket: Broadcasts to all clients to invalidate React Query caches
	 *
	 * @return bool True if at least one method succeeded
	 */
	public function invalidateImages(): bool
	{
		// HTTP: Clear Next.js server-side image cache + search data tags
		$httpResult = $this->sendCacheReset('images=true&tag=search-data&tag=images');

		// WebSocket: Broadcast to all clients to invalidate React Query caches
		// Invalidate searchData because it contains resource_pictures metadata
		$wsResult = $this->sendWebSocketInvalidation([
			['searchData'],
			['organizations'],
			['towns']
		]);

		return $httpResult['success'] || $wsResult;
	}

	/**
	 * Invalidate all Next.js caches (images, pages, data)
	 * Use sparingly - prefer specific cache invalidation
	 *
	 * Uses dual invalidation:
	 * 1. HTTP: Clears all Next.js server-side caches
	 * 2. WebSocket: Broadcasts to all clients to invalidate all major React Query caches
	 *
	 * @return bool True if at least one method succeeded
	 */
	public function invalidateAll(): bool
	{
		// HTTP: Clear all Next.js server-side caches
		$httpResult = $this->sendCacheReset('all=true');

		// WebSocket: Invalidate all major React Query caches
		$wsResult = $this->sendWebSocketInvalidation([
			['searchData'],
			['organizations'],
			['towns'],
			['multiDomains'],
			['upcomingEvents'],
			['partialApplications'],
			['deliveredApplications']
		]);

		return $httpResult['success'] || $wsResult;
	}

	/**
	 * Invalidate cache for a specific path
	 * Example: invalidatePath('/resource/123')
	 *
	 * @param string $path The path to invalidate (e.g., '/resource/123')
	 * @return bool True if request was sent, false if Next.js not configured
	 */
	public function invalidatePath(string $path): bool
	{
		$result = $this->sendCacheReset('path=' . urlencode($path));
		return $result['success'];
	}

	/**
	 * Invalidate cache by tag(s)
	 * Example: invalidateTag('resource_123')
	 * Example: invalidateTag(['search-data', 'images'])
	 *
	 * @param string|array $tags Single tag or array of tags to invalidate
	 * @return bool True if request was sent, false if Next.js not configured
	 */
	public function invalidateTag($tags): bool
	{
		if (is_array($tags))
		{
			$queryParts = array_map(function($tag) {
				return 'tag=' . urlencode($tag);
			}, $tags);
			$result = $this->sendCacheReset(implode('&', $queryParts));
			return $result['success'];
		}

		$result = $this->sendCacheReset('tag=' . urlencode($tags));
		return $result['success'];
	}

	/**
	 * Send cache reset request to Next.js
	 * Makes a non-blocking HTTP GET request to the Next.js cache-reset endpoint
	 *
	 * @param string $queryString The query string parameters (e.g., 'images=true')
	 * @return array{success: bool, debug: array} Result with debug info
	 */
	private function sendCacheReset(string $queryString): array
	{
		$debug = [
			'timestamp' => date('c'),
			'queryString' => $queryString,
			'url' => null,
			'server' => $this->nextjsServer,
			'responseCode' => null,
			'error' => null,
		];

		// Skip if Next.js server is not configured
		if (!$this->nextjsServer)
		{
			$debug['error'] = 'Next.js server not configured';
			return ['success' => false, 'debug' => $debug];
		}

		$url = "http://{$this->nextjsServer}{$this->basePath}?{$queryString}";
		$debug['url'] = $url;

		// Make HTTP request to clear cache
		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => 2, // 2 second timeout for debugging
				'ignore_errors' => true, // Don't throw errors if request fails
			]
		]);

		// Make the request and capture response
		$response = @file_get_contents($url, false, $context);

		// Extract response code from headers
		if (isset($http_response_header) && is_array($http_response_header) && count($http_response_header) > 0)
		{
			// First header contains status line like "HTTP/1.1 200 OK"
			if (preg_match('/HTTP\/\d+\.?\d*\s+(\d+)/', $http_response_header[0], $matches))
			{
				$debug['responseCode'] = (int)$matches[1];
			}
		}

		if ($response === false)
		{
			$debug['error'] = 'Request failed';
			return ['success' => false, 'debug' => $debug];
		}

		return ['success' => true, 'debug' => $debug];
	}

	/**
	 * Check if cache service is available
	 *
	 * @return bool True if Next.js server is configured
	 */
	public function isAvailable(): bool
	{
		return !empty($this->nextjsServer);
	}

	/**
	 * Get the Next.js server URL (for debugging)
	 *
	 * @return string|null The Next.js server URL or null if not configured
	 */
	public function getServerUrl(): ?string
	{
		return $this->nextjsServer;
	}

	/**
	 * Send cache invalidation message via WebSocket to all connected clients
	 * This triggers React Query cache invalidation in all client browsers
	 *
	 * @param array $queryKeys Array of React Query key arrays to invalidate
	 *                         Example: [['searchData'], ['organizations'], ['resourceArticles', 123]]
	 * @param array|null $debug Optional debug data to include in the message
	 * @return bool True if message was sent successfully
	 */
	private function sendWebSocketInvalidation(array $queryKeys, ?array $debug = null): bool
	{
		// Check if WebSocketHelper is available
		if (!class_exists('\App\modules\bookingfrontend\helpers\WebSocketHelper'))
		{
			return false;
		}

		try
		{
			return WebSocketHelper::sendCacheInvalidation($queryKeys, $debug);
		}
		catch (\Exception $e)
		{
			error_log("CacheService: Failed to send WebSocket cache invalidation: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Invalidate specific React Query cache keys
	 * Useful for targeted cache invalidation when you know the exact keys
	 *
	 * @param array $queryKeys Array of query key arrays
	 *                         Example: [['searchData'], ['resourceArticles', 123]]
	 * @return bool True if message sent successfully
	 */
	public function invalidateQueryKeys(array $queryKeys): bool
	{
		return $this->sendWebSocketInvalidation($queryKeys);
	}

	/**
	 * Invalidate caches when a resource is added or edited
	 * Call this after resource create/update operations
	 *
	 * Uses dual invalidation:
	 * 1. HTTP: Revalidates server-side search-data and resources tags
	 * 2. WebSocket: Invalidates client-side React Query caches
	 *
	 * @param int $resourceId The ID of the resource that was modified
	 * @param int|array|null $buildingIds Single building ID, array of building IDs, or null
	 * @return bool True if at least one invalidation succeeded
	 */
	public function invalidateResource(int $resourceId, $buildingIds = null): bool
	{
		// HTTP: Revalidate server-side Next.js caches by tag
		$httpResult = $this->sendCacheReset('tag=search-data&tag=resources');

		// WebSocket: Invalidate client-side React Query caches
		$queryKeys = [
			['searchData'],              // Search index contains resource names and building_resources junction table
			['resource', (string)$resourceId]  // Individual resource cache
		];

		// Handle building IDs - could be single int, array, or null
		if ($buildingIds !== null)
		{
			// Convert to array if single ID
			$buildingIdsArray = is_array($buildingIds) ? $buildingIds : [$buildingIds];

			// Add buildingResources cache for each building
			foreach ($buildingIdsArray as $buildingId)
			{
				if ($buildingId)
				{
					$queryKeys[] = ['buildingResources', (string)$buildingId];
				}
			}
		}

		// Include HTTP debug info in WebSocket message
		$debug = [
			'source' => 'invalidateResource',
			'resourceId' => $resourceId,
			'buildingIds' => $buildingIds,
			'httpRequest' => $httpResult['debug'],
		];

		$wsResult = $this->sendWebSocketInvalidation($queryKeys, $debug);

		return $httpResult['success'] || $wsResult;
	}

	/**
	 * Invalidate caches when a building is added or edited
	 * Call this after building create/update operations
	 *
	 * Uses dual invalidation:
	 * 1. HTTP: Revalidates server-side search-data and buildings tags
	 * 2. WebSocket: Invalidates client-side React Query caches
	 *
	 * @param int $buildingId The ID of the building that was modified
	 * @return bool True if at least one invalidation succeeded
	 */
	public function invalidateBuilding(int $buildingId): bool
	{
		// HTTP: Revalidate server-side Next.js caches by tag
		$httpResult = $this->sendCacheReset('tag=search-data&tag=buildings');

		// WebSocket: Invalidate client-side React Query caches
		$queryKeys = [
			['searchData'],                        // Search index contains building data and building_resources junction table
			['building', (string)$buildingId]  // Resources for this building
		];

		$wsResult = $this->sendWebSocketInvalidation($queryKeys);

		return $httpResult['success'] || $wsResult;
	}

	/**
	 * Invalidate caches when building documents are added, edited, or deleted
	 * Call this after document operations for a building
	 *
	 * @param int $buildingId The ID of the building whose documents changed
	 * @return bool True if at least one invalidation succeeded
	 */
	public function invalidateBuildingDocuments(int $buildingId): bool
	{
		// WebSocket: Invalidate document caches
		// Note: No HTTP cache for documents (they're not server-cached)
		$queryKeys = [
			['buildingDocuments', (string)$buildingId],  // Documents for this building
			['allRegulationDocuments']                   // Combined regulation documents cache
		];

		return $this->sendWebSocketInvalidation($queryKeys);
	}

	/**
	 * Invalidate caches when resource documents are added, edited, or deleted
	 * Call this after document operations for a resource
	 *
	 * @param int $resourceId The ID of the resource whose documents changed
	 * @return bool True if at least one invalidation succeeded
	 */
	public function invalidateResourceDocuments(int $resourceId): bool
	{
		// WebSocket: Invalidate document caches
		// Note: No HTTP cache for documents (they're not server-cached)
		$queryKeys = [
			['resourceDocuments', (string)$resourceId, 'regulation'],  // Documents for this resource
			['allRegulationDocuments']                                 // Combined regulation documents cache
		];

		return $this->sendWebSocketInvalidation($queryKeys);
	}

	/**
	 * Invalidate caches when a season is added, edited, or boundaries changed
	 * Call this after season operations
	 *
	 * @param int|null $buildingId The ID of the building the season belongs to (optional)
	 * @return bool True if at least one invalidation succeeded
	 */
	public function invalidateSeason(?int $buildingId = null): bool
	{
		// HTTP: Revalidate server-side Next.js caches
		$httpResult = $this->sendCacheReset('tag=search-data');

		// WebSocket: Invalidate client-side React Query caches
		$queryKeys = [
			['searchData'],           // Search index may contain season info
			['building_seasons']      // All seasons cache (no building_id param)
		];

		// Add building-specific seasons cache if building ID provided
		if ($buildingId !== null)
		{
			$queryKeys[] = ['building_seasons', (string)$buildingId];  // Seasons for this building
		}

		$wsResult = $this->sendWebSocketInvalidation($queryKeys);

		return $httpResult['success'] || $wsResult;
	}
}
