<?php
/**
 * IMAP Manager - Handles switching between Modern and Legacy IMAP
 * Automatically falls back to legacy if modern wrapper fails
 * 
 * @author Claude Code Assistant
 * @package email
 */

require_once __DIR__ . '/ModernIMAPWrapper.php';
require_once __DIR__ . '/LegacyIMAPWrapper.php';

class IMAPManager
{
    const MODE_AUTO = 'auto';
    const MODE_MODERN = 'modern';
    const MODE_LEGACY = 'legacy';
    
    private static $mode = self::MODE_AUTO;
    private static $fallbackOccurred = false;
    private static $currentWrapper = null;
    
    /**
     * Set IMAP mode
     */
    public static function setMode($mode)
    {
        if (in_array($mode, [self::MODE_AUTO, self::MODE_MODERN, self::MODE_LEGACY])) {
            self::$mode = $mode;
            self::$currentWrapper = null; // Reset wrapper selection
        }
    }
    
    /**
     * Get current mode
     */
    public static function getMode()
    {
        return self::$mode;
    }
    
    /**
     * Check if fallback has occurred
     */
    public static function hasFallbackOccurred()
    {
        return self::$fallbackOccurred;
    }
    
    /**
     * Get the appropriate wrapper class
     */
    private static function getWrapper()
    {
        if (self::$currentWrapper !== null) {
            return self::$currentWrapper;
        }
        
        switch (self::$mode) {
            case self::MODE_LEGACY:
                self::$currentWrapper = 'LegacyIMAPWrapper';
                break;
                
            case self::MODE_MODERN:
                self::$currentWrapper = 'ModernIMAPWrapper';
                break;
                
            case self::MODE_AUTO:
            default:
                // Try modern first, fallback to legacy if needed
                self::$currentWrapper = 'ModernIMAPWrapper';
                break;
        }
        
        return self::$currentWrapper;
    }
    
    /**
     * Execute IMAP function with automatic fallback
     */
    private static function executeWithFallback($function, $args)
    {
        $wrapper = self::getWrapper();
        
        try {
            // Try primary wrapper
            $result = call_user_func_array([$wrapper, $function], $args);
            
            // If result is false and we haven't tried legacy yet, try fallback
            if ($result === false && self::$mode === self::MODE_AUTO && $wrapper === 'ModernIMAPWrapper' && !self::$fallbackOccurred) {
                self::$fallbackOccurred = true;
                self::$currentWrapper = 'LegacyIMAPWrapper';
                
                error_log("IMAP: Falling back to legacy wrapper for function: $function");
                
                $result = call_user_func_array([self::$currentWrapper, $function], $args);
            }
            
            return $result;
            
        } catch (Exception $e) {
            // If exception occurs and we're in auto mode, try fallback
            if (self::$mode === self::MODE_AUTO && $wrapper === 'ModernIMAPWrapper' && !self::$fallbackOccurred) {
                self::$fallbackOccurred = true;
                self::$currentWrapper = 'LegacyIMAPWrapper';
                
                error_log("IMAP: Exception in modern wrapper, falling back to legacy: " . $e->getMessage());
                
                try {
                    return call_user_func_array([self::$currentWrapper, $function], $args);
                } catch (Exception $e2) {
                    error_log("IMAP: Legacy wrapper also failed: " . $e2->getMessage());
                    return false;
                }
            }
            
            error_log("IMAP: Error in $wrapper::$function - " . $e->getMessage());
            return false;
        }
    }
    
    // === IMAP Function Wrappers ===
    
    public static function imap_open($mailbox, $username, $password, $flags = 0, $retries = 0, $params = [])
    {
        return self::executeWithFallback('imap_open', [$mailbox, $username, $password, $flags, $retries, $params]);
    }
    
    public static function imap_close($stream, $flags = 0)
    {
        return self::executeWithFallback('imap_close', [$stream, $flags]);
    }
    
    public static function imap_search($stream, $criteria, $flags = 0)
    {
        return self::executeWithFallback('imap_search', [$stream, $criteria, $flags]);
    }
    
    public static function imap_fetchheader($stream, $msgNum, $flags = 0)
    {
        return self::executeWithFallback('imap_fetchheader', [$stream, $msgNum, $flags]);
    }
    
    public static function imap_fetchbody($stream, $msgNum, $section, $flags = 0)
    {
        return self::executeWithFallback('imap_fetchbody', [$stream, $msgNum, $section, $flags]);
    }
    
    public static function imap_fetchstructure($stream, $msgNum, $flags = 0)
    {
        return self::executeWithFallback('imap_fetchstructure', [$stream, $msgNum, $flags]);
    }
    
    public static function imap_header($stream, $msgNum, $fromlength = 0, $tolength = 0, $defaulthost = '')
    {
        return self::executeWithFallback('imap_header', [$stream, $msgNum, $fromlength, $tolength, $defaulthost]);
    }
    
    public static function imap_headerinfo($stream, $msgNum, $fromlength = 0, $tolength = 0, $defaulthost = '')
    {
        return self::executeWithFallback('imap_headerinfo', [$stream, $msgNum, $fromlength, $tolength, $defaulthost]);
    }
    
    public static function imap_delete($stream, $msgNums, $flags = 0)
    {
        return self::executeWithFallback('imap_delete', [$stream, $msgNums, $flags]);
    }
    
    public static function imap_expunge($stream)
    {
        return self::executeWithFallback('imap_expunge', [$stream]);
    }
    
    public static function imap_num_msg($stream)
    {
        return self::executeWithFallback('imap_num_msg', [$stream]);
    }
    
    public static function imap_list($stream, $ref, $pattern)
    {
        return self::executeWithFallback('imap_list', [$stream, $ref, $pattern]);
    }
    
    public static function imap_listmailbox($stream, $ref, $pattern)
    {
        return self::executeWithFallback('imap_listmailbox', [$stream, $ref, $pattern]);
    }
    
    public static function imap_utf7_encode($data)
    {
        return self::executeWithFallback('imap_utf7_encode', [$data]);
    }
    
    public static function imap_utf7_decode($data)
    {
        return self::executeWithFallback('imap_utf7_decode', [$data]);
    }
    
    public static function imap_mime_header_decode($text)
    {
        return self::executeWithFallback('imap_mime_header_decode', [$text]);
    }
    
    public static function imap_last_error()
    {
        return self::executeWithFallback('imap_last_error', []);
    }
    
    public static function imap_ping($stream)
    {
        return self::executeWithFallback('imap_ping', [$stream]);
    }
    
    public static function imap_append($stream, $folder, $message, $flags = '')
    {
        return self::executeWithFallback('imap_append', [$stream, $folder, $message, $flags]);
    }
    
    public static function imap_body($stream, $msgNum, $flags = 0)
    {
        return self::executeWithFallback('imap_body', [$stream, $msgNum, $flags]);
    }
    
    public static function imap_createmailbox($stream, $mailbox)
    {
        return self::executeWithFallback('imap_createmailbox', [$stream, $mailbox]);
    }
    
    public static function imap_deletemailbox($stream, $mailbox)
    {
        return self::executeWithFallback('imap_deletemailbox', [$stream, $mailbox]);
    }
    
    public static function imap_renamemailbox($stream, $old_name, $new_name)
    {
        return self::executeWithFallback('imap_renamemailbox', [$stream, $old_name, $new_name]);
    }
    
    public static function imap_status($stream, $mailbox, $options)
    {
        return self::executeWithFallback('imap_status', [$stream, $mailbox, $options]);
    }
    
    public static function imap_mailboxmsginfo($stream)
    {
        return self::executeWithFallback('imap_mailboxmsginfo', [$stream]);
    }
    
    public static function imap_mail_copy($stream, $msgList, $mailbox, $flags = 0)
    {
        return self::executeWithFallback('imap_mail_copy', [$stream, $msgList, $mailbox, $flags]);
    }
    
    public static function imap_mail_move($stream, $msgList, $mailbox, $flags = 0)
    {
        return self::executeWithFallback('imap_mail_move', [$stream, $msgList, $mailbox, $flags]);
    }
    
    public static function imap_setflag_full($stream, $msgNum, $flag, $options = 0)
    {
        return self::executeWithFallback('imap_setflag_full', [$stream, $msgNum, $flag, $options]);
    }
    
    public static function imap_sort($stream, $criteria, $reverse, $options = 0)
    {
        return self::executeWithFallback('imap_sort', [$stream, $criteria, $reverse, $options]);
    }
    
    public static function imap_reopen($stream, $mailbox, $flags = 0)
    {
        return self::executeWithFallback('imap_reopen', [$stream, $mailbox, $flags]);
    }
    
    public static function imap_msgno($stream, $uid)
    {
        return self::executeWithFallback('imap_msgno', [$stream, $uid]);
    }
    
    public static function imap_base64($data)
    {
        return self::executeWithFallback('imap_base64', [$data]);
    }
    
    public static function imap_qprint($data)
    {
        return self::executeWithFallback('imap_qprint', [$data]);
    }
    
    public static function imap_getmailboxes($stream, $ref, $pattern)
    {
        return self::executeWithFallback('imap_getmailboxes', [$stream, $ref, $pattern]);
    }
    
    public static function imap_subscribe($stream, $mailbox)
    {
        return self::executeWithFallback('imap_subscribe', [$stream, $mailbox]);
    }
    
    public static function imap_unsubscribe($stream, $mailbox)
    {
        return self::executeWithFallback('imap_unsubscribe', [$stream, $mailbox]);
    }
    
    public static function imap_8bit($data)
    {
        return self::executeWithFallback('imap_8bit', [$data]);
    }
    
    public static function imap_rfc822_write_address($mailbox, $host, $personal)
    {
        return self::executeWithFallback('imap_rfc822_write_address', [$mailbox, $host, $personal]);
    }
    
    public static function imap_rfc822_parse_adrlist($address, $default_host)
    {
        return self::executeWithFallback('imap_rfc822_parse_adrlist', [$address, $default_host]);
    }
    
    public static function imap_headers($stream)
    {
        return self::executeWithFallback('imap_headers', [$stream]);
    }
    
    // === Utility Methods ===
    
    /**
     * Get current wrapper status
     */
    public static function getStatus()
    {
        return [
            'mode' => self::$mode,
            'current_wrapper' => self::$currentWrapper,
            'fallback_occurred' => self::$fallbackOccurred,
            'modern_available' => class_exists('ModernIMAPWrapper'),
            'legacy_available' => LegacyIMAPWrapper::isAvailable()
        ];
    }
    
    /**
     * Force reset wrapper selection (useful for testing)
     */
    public static function reset()
    {
        self::$currentWrapper = null;
        self::$fallbackOccurred = false;
    }
}