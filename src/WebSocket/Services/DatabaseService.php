<?php

namespace App\WebSocket\Services;

use Psr\Log\LoggerInterface;
use App\Database\Db;
use App\modules\bookingfrontend\repositories\ApplicationRepository;

/**
 * Service for accessing Portico database
 * and retrieving application-related data
 */
class DatabaseService
{
    private $logger;
    private $db;
    private $config;
    private $connected = false;
    private $applicationRepository = null;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->initializeDatabase();
    }

    /**
     * Initialize database connection using Slim server's config file directly
     *
     * @return bool True if connected successfully
     */
    private function initializeDatabase(): bool
    {
        try {
            // Directly load config from Slim server's config file
            $configFile = '/var/www/html/config/header.inc.php';

            if (!file_exists($configFile)) {
                $this->logger->error("Slim server config file not found at {$configFile}");
                return false;
            }

            $this->logger->info("Reading database config from Slim server: {$configFile}");

            // Include the header.inc.php file to get the phpgw_domain array
            // Use a closure to isolate variable scope and capture $phpgw_domain
            $getDatabaseConfig = function() use ($configFile) {
                // The array we want to extract
                $phpgw_domain = array();
                $db_persistent = false;

                // Output buffering to prevent any output
                ob_start();
                include $configFile;
                ob_end_clean();

                return array(
                    'phpgw_domain' => $phpgw_domain ?? array(),
                    'db_persistent' => $db_persistent
                );
            };

            // Execute the closure to get config
            $configData = $getDatabaseConfig();

            // Check if we have the phpgw_domain array
            $this->logger->info("Checking if phpgw_domain array is available");
            if (empty($configData['phpgw_domain'])) {
                $this->logger->error("No phpgw_domain array found in config file");
                return false;
            }

            // Get the default domain config
            $domainName = 'default';
            $domainConfig = $configData['phpgw_domain'][$domainName] ?? null;

            // If no default domain, try to get the first domain
            if (!$domainConfig && !empty($configData['phpgw_domain'])) {
                $domainName = array_key_first($configData['phpgw_domain']);
                $domainConfig = $configData['phpgw_domain'][$domainName];
                $this->logger->info("Using first domain: {$domainName}");
            }

            if (!$domainConfig) {
                $this->logger->error("No domain configuration found in phpgw_domain array");
                return false;
            }

            $this->logger->info("Found domain configuration for: {$domainName}");

            // Extract database config
            $dbConfig = [
                'db_type' => $domainConfig['db_type'] ?? 'pgsql',
                'db_host' => $domainConfig['db_host'] ?? 'database',
                'db_port' => $domainConfig['db_port'] ?? '5432',
                'db_name' => $domainConfig['db_name'] ?? 'portico',
                'db_user' => $domainConfig['db_user'] ?? 'portico',
                'db_pass' => $domainConfig['db_pass'] ?? 'portico',
                'domain' => $domainName
            ];

            // In Docker, 'localhost' refers to the container itself
            // If db_host is localhost, change it to 'database' (common service name)
            if ($dbConfig['db_host'] === 'localhost') {
                $this->logger->info("Changed db_host from 'localhost' to 'database' for Docker compatibility");
                $dbConfig['db_host'] = 'database';
            }

            $this->config = $dbConfig;

            // Log the configuration (with password masked)
            $logConfig = $dbConfig;
            $logConfig['db_pass'] = '********';
            $this->logger->info("Database configuration from phpgw_domain", $logConfig);

            // Create DSN and establish connection
            $dsn = Db::CreateDsn($dbConfig);
            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_PERSISTENT => true,
            ];

            $this->db = Db::getInstance($dsn, $dbConfig['db_user'], $dbConfig['db_pass'], $options);
            $this->db->set_domain($dbConfig['domain']);
            $this->db->set_config($dbConfig);

            // Verify connection with a simple query
            $this->db->query("SELECT 1", __LINE__, __FILE__);

            $this->connected = true;
            $this->logger->info("Database connection initialized successfully using Slim config");

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to initialize database connection", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return false;
        }
    }

    /**
     * Check if database is connected
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->db && $this->db->isConnected();
    }

    /**
     * Get or initialize the Application Repository
     *
     * @return ApplicationRepository
     */
    private function getApplicationRepository(): ApplicationRepository
    {
        if ($this->applicationRepository === null) {
            $this->logger->info("Initializing ApplicationRepository");

            // Initialize the repository - will reuse the same DB connection
            $this->applicationRepository = new ApplicationRepository();
        }

        return $this->applicationRepository;
    }

    /**
     * Get partial applications by session ID
     * Directly uses the ApplicationRepository from the Slim codebase for consistent serialization
     *
     * @param string $sessionId The session ID
     * @return array List of serialized partial applications
     */
    public function getPartialApplicationsBySessionId(string $sessionId): array
    {
        if (!$this->isConnected()) {
            $this->logger->error("Cannot get partial applications - database not connected");
            return [];
        }

        try {
            $this->logger->info("Getting partial applications for session: " . substr($sessionId, 0, 8) . '...');

            // Use the ApplicationRepository directly from the Slim codebase
            $applications = $this->getApplicationRepository()->getPartialApplications($sessionId);

            $this->logger->info("Retrieved " . count($applications) . " partial applications for session");

            return $applications;
        } catch (\Exception $e) {
            $this->logger->error("Error retrieving partial applications by session ID", [
                'error' => $e->getMessage(),
                'sessionId' => substr($sessionId, 0, 8) . '...'
            ]);
            return [];
        }
    }

    // We no longer need the fetchResources and fetchDates methods since
    // we're now directly using the ApplicationRepository from the Slim codebase
}