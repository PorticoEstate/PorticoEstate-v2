<?php

namespace App\modules\bookingfrontend\services;

/**
 * Service for managing Next.js cache invalidation
 * Handles communication with the Next.js frontend to clear various cache types
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
	 * @return bool True if request was sent (doesn't guarantee success), false if Next.js not configured
	 */
	public function invalidateImages(): bool
	{
		// Clear image optimization cache AND revalidate search-data/images tags in single request
		return $this->sendCacheReset('images=true&tag=search-data&tag=images');
	}

	/**
	 * Invalidate all Next.js caches (images, pages, data)
	 * Use sparingly - prefer specific cache invalidation
	 *
	 * @return bool True if request was sent, false if Next.js not configured
	 */
	public function invalidateAll(): bool
	{
		return $this->sendCacheReset('all=true');
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
		return $this->sendCacheReset('path=' . urlencode($path));
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
			return $this->sendCacheReset(implode('&', $queryParts));
		}

		return $this->sendCacheReset('tag=' . urlencode($tags));
	}

	/**
	 * Send cache reset request to Next.js
	 * Makes a non-blocking HTTP GET request to the Next.js cache-reset endpoint
	 *
	 * @param string $queryString The query string parameters (e.g., 'images=true')
	 * @return bool True if request was sent, false if Next.js not configured
	 */
	private function sendCacheReset(string $queryString): bool
	{
		// Skip if Next.js server is not configured
		if (!$this->nextjsServer)
		{
			return false;
		}

		$url = "http://{$this->nextjsServer}{$this->basePath}?{$queryString}";

		// Make async HTTP request to clear cache
		// Use stream context to make it non-blocking so we don't wait for response
		$context = stream_context_create([
			'http' => [
				'method' => 'GET',
				'timeout' => 1, // 1 second timeout
				'ignore_errors' => true, // Don't throw errors if request fails
			]
		]);

		// Suppress warnings and make the request
		// We don't care about the response - fire and forget
		@file_get_contents($url, false, $context);

		return true;
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
}
