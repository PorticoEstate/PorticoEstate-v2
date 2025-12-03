<?php
/**
 * IMAP Configuration File
 * Controls which IMAP implementation to use
 * 
 * @author Claude Code Assistant 
 * @package email
 */

// IMAP Mode Configuration
// Options:
// - 'auto'   : Try modern first, fallback to legacy if needed (RECOMMENDED)
// - 'modern' : Force use of Webklex/php-imap (will fail if not working)
// - 'legacy' : Force use of native PHP IMAP extension (requires php-imap installed)

define('IMAP_MODE', 'auto');

// Enable IMAP debugging (logs fallbacks and errors)
define('IMAP_DEBUG', true);

// Include the IMAP Manager
require_once __DIR__ . '/IMAPManager.php';

// Set the configured mode
IMAPManager::setMode(IMAP_MODE);

// Optional: Create global functions that mirror native imap_* functions
// This allows existing code to work without changes

if (!function_exists('pe_imap_open')) {
    function pe_imap_open($mailbox, $username, $password, $flags = 0, $retries = 0, $params = []) {
        return IMAPManager::imap_open($mailbox, $username, $password, $flags, $retries, $params);
    }
}

if (!function_exists('pe_imap_close')) {
    function pe_imap_close($stream, $flags = 0) {
        return IMAPManager::imap_close($stream, $flags);
    }
}

if (!function_exists('pe_imap_search')) {
    function pe_imap_search($stream, $criteria, $flags = 0) {
        return IMAPManager::imap_search($stream, $criteria, $flags);
    }
}

if (!function_exists('pe_imap_fetchheader')) {
    function pe_imap_fetchheader($stream, $msgNum, $flags = 0) {
        return IMAPManager::imap_fetchheader($stream, $msgNum, $flags);
    }
}

if (!function_exists('pe_imap_fetchbody')) {
    function pe_imap_fetchbody($stream, $msgNum, $section, $flags = 0) {
        return IMAPManager::imap_fetchbody($stream, $msgNum, $section, $flags);
    }
}

if (!function_exists('pe_imap_fetchstructure')) {
    function pe_imap_fetchstructure($stream, $msgNum, $flags = 0) {
        return IMAPManager::imap_fetchstructure($stream, $msgNum, $flags);
    }
}

if (!function_exists('pe_imap_header')) {
    function pe_imap_header($stream, $msgNum, $fromlength = 0, $tolength = 0, $defaulthost = '') {
        return IMAPManager::imap_header($stream, $msgNum, $fromlength, $tolength, $defaulthost);
    }
}

if (!function_exists('pe_imap_headerinfo')) {
    function pe_imap_headerinfo($stream, $msgNum, $fromlength = 0, $tolength = 0, $defaulthost = '') {
        return IMAPManager::imap_headerinfo($stream, $msgNum, $fromlength, $tolength, $defaulthost);
    }
}

if (!function_exists('pe_imap_delete')) {
    function pe_imap_delete($stream, $msgNums, $flags = 0) {
        return IMAPManager::imap_delete($stream, $msgNums, $flags);
    }
}

if (!function_exists('pe_imap_expunge')) {
    function pe_imap_expunge($stream) {
        return IMAPManager::imap_expunge($stream);
    }
}

if (!function_exists('pe_imap_num_msg')) {
    function pe_imap_num_msg($stream) {
        return IMAPManager::imap_num_msg($stream);
    }
}

if (!function_exists('pe_imap_list')) {
    function pe_imap_list($stream, $ref, $pattern) {
        return IMAPManager::imap_list($stream, $ref, $pattern);
    }
}

if (!function_exists('pe_imap_utf7_encode')) {
    function pe_imap_utf7_encode($data) {
        return IMAPManager::imap_utf7_encode($data);
    }
}

if (!function_exists('pe_imap_utf7_decode')) {
    function pe_imap_utf7_decode($data) {
        return IMAPManager::imap_utf7_decode($data);
    }
}

if (!function_exists('pe_imap_mime_header_decode')) {
    function pe_imap_mime_header_decode($text) {
        return IMAPManager::imap_mime_header_decode($text);
    }
}

if (!function_exists('pe_imap_last_error')) {
    function pe_imap_last_error() {
        return IMAPManager::imap_last_error();
    }
}

if (!function_exists('pe_imap_ping')) {
    function pe_imap_ping($stream) {
        return IMAPManager::imap_ping($stream);
    }
}

// Add more pe_imap_* functions as needed...

/**
 * Get IMAP status information
 */
function get_imap_status() {
    return IMAPManager::getStatus();
}

/**
 * Reset IMAP manager (useful for testing)
 */
function reset_imap_manager() {
    return IMAPManager::reset();
}