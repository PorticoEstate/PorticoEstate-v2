<?php

namespace App\WebSocket\Services;

use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

class RedisService
{
    private $redis;
    private $enabled = false;
    private $logger;
    private $host;
    private $port;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->host = getenv('REDIS_HOST') ?: 'redis';
        $this->port = getenv('REDIS_PORT') ?: 6379;
        
        $this->connect();
    }

    /**
     * Connect to Redis server
     *
     * @return bool Success status
     */
    public function connect(): bool
    {
        try {
            $this->logger->info("Attempting to connect to Redis", [
                'host' => $this->host,
                'port' => $this->port
            ]);
            
            $this->redis = new RedisClient([
                'scheme' => 'tcp',
                'host' => $this->host,
                'port' => $this->port,
                'read_write_timeout' => 0
            ]);
            
            // Test the connection
            $this->redis->ping();
            $this->enabled = true;
            $this->logger->info("Redis connection established successfully");
            
            return true;
        } catch (\Exception $e) {
            $this->enabled = false;
            $this->logger->warning("Redis connection failed", [
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Check if Redis is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the Redis client
     *
     * @return RedisClient|null
     */
    public function getClient()
    {
        return $this->enabled ? $this->redis : null;
    }

    /**
     * Publish a message to a Redis channel
     *
     * @param string $channel Channel name
     * @param string|array $message Message to publish (will be converted to JSON if array)
     * @return bool Success status
     */
    public function publish(string $channel, $message): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            // Convert array to JSON if needed
            if (is_array($message)) {
                $message = json_encode($message);
            }
            
            $this->redis->publish($channel, $message);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Redis publish error", [
                'channel' => $channel,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Subscribe to a Redis channel
     * Note: This is generally handled in server.php with Clue\React\Redis
     * for non-blocking operation with ReactPHP
     *
     * @param string $channel Channel name
     * @return bool Success status
     */
    public function subscribe(string $channel): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $this->redis->subscribe($channel);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Redis subscribe error", [
                'channel' => $channel,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Static method to send a notification using Redis pub/sub
     *
     * @param array|string $data Notification data to send
     * @param string $channel Redis channel to use (default: notifications)
     * @return bool Success status
     */
    public static function sendNotification($data, string $channel = 'notifications'): bool
    {
        try {
            // Convert to JSON if it's an array
            if (is_array($data)) {
                $data = json_encode($data);
            }
            
            // Connect to Redis
            $host = getenv('REDIS_HOST') ?: 'redis';
            $port = getenv('REDIS_PORT') ?: 6379;
            
            $redis = new RedisClient([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port
            ]);
            
            // Publish the message
            $redis->publish($channel, $data);
            
            return true;
        } catch (\Exception $e) {
            // Log the error
            error_log("Redis notification error: " . $e->getMessage());
            
            // Try the file-based fallback
            try {
                $timestamp = microtime(true);
                $filename = "/tmp/websocket_notification_{$timestamp}.json";
                file_put_contents($filename, $data);
                return true;
            } catch (\Exception $e2) {
                error_log("File-based notification fallback failed: " . $e2->getMessage());
                return false;
            }
        }
    }
}