<?php
/**
 * Legacy IMAP Wrapper using native PHP IMAP extension
 * Provides a fallback option if modern wrapper has issues
 * 
 * @author Claude Code Assistant
 * @package email
 */

class LegacyIMAPWrapper
{
    /**
     * Legacy imap_open() - direct passthrough to native function
     */
    public static function imap_open($mailbox, $username, $password, $flags = 0, $retries = 0, $params = [])
    {
        if (!function_exists('imap_open')) {
            throw new Exception('PHP IMAP extension is not installed');
        }
        
        return @imap_open($mailbox, $username, $password, $flags, $retries, $params);
    }
    
    /**
     * Legacy imap_close() - direct passthrough to native function
     */
    public static function imap_close($stream, $flags = 0)
    {
        if (!function_exists('imap_close')) {
            return false;
        }
        
        return @imap_close($stream, $flags);
    }
    
    /**
     * Legacy imap_search() - direct passthrough to native function
     */
    public static function imap_search($stream, $criteria, $flags = 0)
    {
        if (!function_exists('imap_search')) {
            return false;
        }
        
        return @imap_search($stream, $criteria, $flags);
    }
    
    /**
     * Legacy imap_fetchheader() - direct passthrough to native function
     */
    public static function imap_fetchheader($stream, $msgNum, $flags = 0)
    {
        if (!function_exists('imap_fetchheader')) {
            return false;
        }
        
        return @imap_fetchheader($stream, $msgNum, $flags);
    }
    
    /**
     * Legacy imap_fetchbody() - direct passthrough to native function
     */
    public static function imap_fetchbody($stream, $msgNum, $section, $flags = 0)
    {
        if (!function_exists('imap_fetchbody')) {
            return false;
        }
        
        return @imap_fetchbody($stream, $msgNum, $section, $flags);
    }
    
    /**
     * Legacy imap_fetchstructure() - direct passthrough to native function
     */
    public static function imap_fetchstructure($stream, $msgNum, $flags = 0)
    {
        if (!function_exists('imap_fetchstructure')) {
            return false;
        }
        
        return @imap_fetchstructure($stream, $msgNum, $flags);
    }
    
    /**
     * Legacy imap_header() - direct passthrough to native function
     */
    public static function imap_header($stream, $msgNum, $fromlength = 0, $tolength = 0, $defaulthost = '')
    {
        if (!function_exists('imap_header')) {
            return false;
        }
        
        return @imap_header($stream, $msgNum, $fromlength, $tolength, $defaulthost);
    }
    
    /**
     * Legacy imap_headerinfo() - direct passthrough to native function
     */
    public static function imap_headerinfo($stream, $msgNum, $fromlength = 0, $tolength = 0, $defaulthost = '')
    {
        if (!function_exists('imap_headerinfo')) {
            return false;
        }
        
        return @imap_headerinfo($stream, $msgNum, $fromlength, $tolength, $defaulthost);
    }
    
    /**
     * Legacy imap_delete() - direct passthrough to native function
     */
    public static function imap_delete($stream, $msgNums, $flags = 0)
    {
        if (!function_exists('imap_delete')) {
            return false;
        }
        
        return @imap_delete($stream, $msgNums, $flags);
    }
    
    /**
     * Legacy imap_expunge() - direct passthrough to native function
     */
    public static function imap_expunge($stream)
    {
        if (!function_exists('imap_expunge')) {
            return false;
        }
        
        return @imap_expunge($stream);
    }
    
    /**
     * Legacy imap_num_msg() - direct passthrough to native function
     */
    public static function imap_num_msg($stream)
    {
        if (!function_exists('imap_num_msg')) {
            return false;
        }
        
        return @imap_num_msg($stream);
    }
    
    /**
     * Legacy imap_list() - direct passthrough to native function
     */
    public static function imap_list($stream, $ref, $pattern)
    {
        if (!function_exists('imap_list')) {
            return false;
        }
        
        return @imap_list($stream, $ref, $pattern);
    }
    
    /**
     * Legacy imap_listmailbox() - direct passthrough to native function
     */
    public static function imap_listmailbox($stream, $ref, $pattern)
    {
        if (!function_exists('imap_listmailbox')) {
            return false;
        }
        
        return @imap_listmailbox($stream, $ref, $pattern);
    }
    
    /**
     * Legacy imap_utf7_encode() - direct passthrough to native function
     */
    public static function imap_utf7_encode($data)
    {
        if (!function_exists('imap_utf7_encode')) {
            return mb_convert_encoding($data, 'UTF7-IMAP', 'UTF-8');
        }
        
        return @imap_utf7_encode($data);
    }
    
    /**
     * Legacy imap_utf7_decode() - direct passthrough to native function
     */
    public static function imap_utf7_decode($data)
    {
        if (!function_exists('imap_utf7_decode')) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF7-IMAP');
        }
        
        return @imap_utf7_decode($data);
    }
    
    /**
     * Legacy imap_mime_header_decode() - direct passthrough to native function
     */
    public static function imap_mime_header_decode($text)
    {
        if (!function_exists('imap_mime_header_decode')) {
            // Fallback implementation
            $decoded = iconv_mime_decode($text, ICONV_MIME_DECODE_STRICT, 'UTF-8');
            
            $result = [];
            $result[0] = new stdClass();
            $result[0]->charset = 'UTF-8';
            $result[0]->text = $decoded ?: $text;
            
            return $result;
        }
        
        return @imap_mime_header_decode($text);
    }
    
    /**
     * Legacy imap_last_error() - direct passthrough to native function
     */
    public static function imap_last_error()
    {
        if (!function_exists('imap_last_error')) {
            return 'IMAP extension not available';
        }
        
        return @imap_last_error();
    }
    
    /**
     * Legacy imap_ping() - direct passthrough to native function
     */
    public static function imap_ping($stream)
    {
        if (!function_exists('imap_ping')) {
            return false;
        }
        
        return @imap_ping($stream);
    }
    
    /**
     * Legacy imap_append() - direct passthrough to native function
     */
    public static function imap_append($stream, $folder, $message, $flags = '')
    {
        if (!function_exists('imap_append')) {
            return false;
        }
        
        return @imap_append($stream, $folder, $message, $flags);
    }
    
    /**
     * Legacy imap_body() - direct passthrough to native function
     */
    public static function imap_body($stream, $msgNum, $flags = 0)
    {
        if (!function_exists('imap_body')) {
            return false;
        }
        
        return @imap_body($stream, $msgNum, $flags);
    }
    
    /**
     * Legacy imap_createmailbox() - direct passthrough to native function
     */
    public static function imap_createmailbox($stream, $mailbox)
    {
        if (!function_exists('imap_createmailbox')) {
            return false;
        }
        
        return @imap_createmailbox($stream, $mailbox);
    }
    
    /**
     * Legacy imap_deletemailbox() - direct passthrough to native function
     */
    public static function imap_deletemailbox($stream, $mailbox)
    {
        if (!function_exists('imap_deletemailbox')) {
            return false;
        }
        
        return @imap_deletemailbox($stream, $mailbox);
    }
    
    /**
     * Legacy imap_renamemailbox() - direct passthrough to native function
     */
    public static function imap_renamemailbox($stream, $old_name, $new_name)
    {
        if (!function_exists('imap_renamemailbox')) {
            return false;
        }
        
        return @imap_renamemailbox($stream, $old_name, $new_name);
    }
    
    /**
     * Legacy imap_status() - direct passthrough to native function
     */
    public static function imap_status($stream, $mailbox, $options)
    {
        if (!function_exists('imap_status')) {
            return false;
        }
        
        return @imap_status($stream, $mailbox, $options);
    }
    
    /**
     * Legacy imap_mailboxmsginfo() - direct passthrough to native function
     */
    public static function imap_mailboxmsginfo($stream)
    {
        if (!function_exists('imap_mailboxmsginfo')) {
            return false;
        }
        
        return @imap_mailboxmsginfo($stream);
    }
    
    /**
     * Legacy imap_mail_copy() - direct passthrough to native function
     */
    public static function imap_mail_copy($stream, $msgList, $mailbox, $flags = 0)
    {
        if (!function_exists('imap_mail_copy')) {
            return false;
        }
        
        return @imap_mail_copy($stream, $msgList, $mailbox, $flags);
    }
    
    /**
     * Legacy imap_mail_move() - direct passthrough to native function
     */
    public static function imap_mail_move($stream, $msgList, $mailbox, $flags = 0)
    {
        if (!function_exists('imap_mail_move')) {
            return false;
        }
        
        return @imap_mail_move($stream, $msgList, $mailbox, $flags);
    }
    
    /**
     * Legacy imap_setflag_full() - direct passthrough to native function
     */
    public static function imap_setflag_full($stream, $msgNum, $flag, $options = 0)
    {
        if (!function_exists('imap_setflag_full')) {
            return false;
        }
        
        return @imap_setflag_full($stream, $msgNum, $flag, $options);
    }
    
    /**
     * Legacy imap_sort() - direct passthrough to native function
     */
    public static function imap_sort($stream, $criteria, $reverse, $options = 0)
    {
        if (!function_exists('imap_sort')) {
            return false;
        }
        
        return @imap_sort($stream, $criteria, $reverse, $options);
    }
    
    /**
     * Legacy imap_reopen() - direct passthrough to native function
     */
    public static function imap_reopen($stream, $mailbox, $flags = 0)
    {
        if (!function_exists('imap_reopen')) {
            return false;
        }
        
        return @imap_reopen($stream, $mailbox, $flags);
    }
    
    /**
     * Legacy imap_msgno() - direct passthrough to native function
     */
    public static function imap_msgno($stream, $uid)
    {
        if (!function_exists('imap_msgno')) {
            return false;
        }
        
        return @imap_msgno($stream, $uid);
    }
    
    /**
     * Legacy imap_base64() - direct passthrough to native function
     */
    public static function imap_base64($data)
    {
        if (!function_exists('imap_base64')) {
            return base64_decode($data);
        }
        
        return @imap_base64($data);
    }
    
    /**
     * Legacy imap_qprint() - direct passthrough to native function
     */
    public static function imap_qprint($data)
    {
        if (!function_exists('imap_qprint')) {
            return quoted_printable_decode($data);
        }
        
        return @imap_qprint($data);
    }
    
    // Add more legacy functions as needed...
    
    /**
     * Legacy imap_getmailboxes() - direct passthrough to native function
     */
    public static function imap_getmailboxes($stream, $ref, $pattern)
    {
        if (!function_exists('imap_getmailboxes')) {
            return false;
        }
        
        return @imap_getmailboxes($stream, $ref, $pattern);
    }
    
    /**
     * Legacy imap_subscribe() - direct passthrough to native function
     */
    public static function imap_subscribe($stream, $mailbox)
    {
        if (!function_exists('imap_subscribe')) {
            return false;
        }
        
        return @imap_subscribe($stream, $mailbox);
    }
    
    /**
     * Legacy imap_unsubscribe() - direct passthrough to native function
     */
    public static function imap_unsubscribe($stream, $mailbox)
    {
        if (!function_exists('imap_unsubscribe')) {
            return false;
        }
        
        return @imap_unsubscribe($stream, $mailbox);
    }
    
    /**
     * Legacy imap_8bit() - direct passthrough to native function
     */
    public static function imap_8bit($data)
    {
        if (!function_exists('imap_8bit')) {
            // Fallback implementation - converts 8bit to quoted-printable
            return quoted_printable_encode($data);
        }
        
        return @imap_8bit($data);
    }
    
    /**
     * Legacy imap_rfc822_write_address() - direct passthrough to native function
     */
    public static function imap_rfc822_write_address($mailbox, $host, $personal)
    {
        if (!function_exists('imap_rfc822_write_address')) {
            // Fallback implementation
            $address = $mailbox . '@' . $host;
            if ($personal) {
                return '"' . $personal . '" <' . $address . '>';
            }
            return $address;
        }
        
        return @imap_rfc822_write_address($mailbox, $host, $personal);
    }
    
    /**
     * Legacy imap_rfc822_parse_adrlist() - direct passthrough to native function
     */
    public static function imap_rfc822_parse_adrlist($address, $default_host)
    {
        if (!function_exists('imap_rfc822_parse_adrlist')) {
            // Fallback implementation - simple parsing
            $results = [];
            $addresses = explode(',', $address);
            foreach ($addresses as $addr) {
                $addr = trim($addr);
                if (preg_match('/^(.+?)\s*<(.+?)>$/', $addr, $matches)) {
                    $personal = trim($matches[1], '"');
                    $email = $matches[2];
                    if (strpos($email, '@') !== false) {
                        list($mailbox, $host) = explode('@', $email, 2);
                    } else {
                        $mailbox = $email;
                        $host = $default_host;
                    }
                } elseif (strpos($addr, '@') !== false) {
                    list($mailbox, $host) = explode('@', $addr, 2);
                    $personal = '';
                } else {
                    $mailbox = $addr;
                    $host = $default_host;
                    $personal = '';
                }
                
                $obj = new stdClass();
                $obj->mailbox = $mailbox;
                $obj->host = $host;
                $obj->personal = $personal;
                $results[] = $obj;
            }
            return $results;
        }
        
        return @imap_rfc822_parse_adrlist($address, $default_host);
    }
    
    /**
     * Legacy imap_headers() - direct passthrough to native function
     */
    public static function imap_headers($stream)
    {
        if (!function_exists('imap_headers')) {
            return false;
        }
        
        return @imap_headers($stream);
    }
    
    /**
     * Check if native IMAP extension is available
     */
    public static function isAvailable()
    {
        return extension_loaded('imap');
    }
}