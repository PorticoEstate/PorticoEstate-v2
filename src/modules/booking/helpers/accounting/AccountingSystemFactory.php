<?php

namespace App\modules\booking\helpers\accounting;

use App\modules\booking\interfaces\AccountingSystemInterface;
use App\modules\booking\helpers\accounting\VismaEnterpriseAccounting;
use Exception;

/**
 * Factory for creating accounting system instances
 * 
 * This factory creates the appropriate accounting system integration
 * based on configuration settings.
 */
class AccountingSystemFactory
{
    /**
     * Create an accounting system integration instance
     *
     * @param string $system_type The type of accounting system to create
     * @param array $config Configuration for the accounting system
     * @param bool $debug Whether to enable debug mode
     * @return AccountingSystemInterface
     * @throws Exception If the accounting system type is not supported
     */
    public static function create($system_type, $config, $debug = false): AccountingSystemInterface
    {
        switch ($system_type) {
            case 'visma_enterprise':
                return new VismaEnterpriseAccounting($config, $debug);
            case 'visma_business':
                // For future implementation
                throw new Exception("Visma Business accounting not implemented yet");
            case 'tripletex':
                // For future implementation
                throw new Exception("Tripletex accounting not implemented yet");
            default:
                throw new Exception("Unknown accounting system: {$system_type}");
        }
    }
}