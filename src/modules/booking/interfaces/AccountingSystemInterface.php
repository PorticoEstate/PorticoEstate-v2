<?php

namespace App\modules\booking\interfaces;

/**
 * Interface for accounting system integrations
 * 
 * Defines the contract that all accounting system integrations must follow.
 */
interface AccountingSystemInterface
{
    /**
     * Initialize the accounting system with configuration
     *
     * @param array $config The configuration for the accounting system
     * @param bool $debug Whether to enable debug mode
     * @return void
     */
    public function __construct($config, $debug = false);
    
	/**
	 * Post a transaction to the accounting system
	 *
	 * @param float $amount The amount of the transaction
	 * @param string $description Description of the transaction
	 * @param string $date Date of the transaction
	 * @param string $remote_order_id Original order ID from payment system
	 * @param array|null $attachment Optional attachment data (base64 encoded file)
	 * @param array|null $customer_info Optional customer information for invoice details
	 * @return bool True if posting was successful, false otherwise
	 */
	public function postTransaction($amount, $description, $date, $remote_order_id, $attachment = null, $customer_info = null): bool;    
 
	/**
	 * Post a refund transaction to the accounting system
	 *
	 * @param float $amount The refund amount
	 * @param string $description Description of the refund
	 * @param string $date Date of the refund
	 * @param string $remote_order_id Original order ID
	 * @param string $original_transaction_id ID of the original transaction
	 * @return bool True if posting was successful, false otherwise
	 */
	public function postRefundTransaction($amount, $description, $date, $remote_order_id, $original_transaction_id = null): bool;
	/**
     * Check if the accounting system is properly configured
     *
     * @return bool True if properly configured, false otherwise
     */
    public function isConfigured(): bool;
    
    /**
     * Get last error message if a transaction failed
     *
     * @return string|null Error message or null if no error
     */
    public function getLastError(): ?string;
}