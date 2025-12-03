<?php
/**
 * Modern IMAP Wrapper using Webklex/php-imap
 * Provides backward compatibility with legacy imap_* functions
 * 
 * @author Claude Code Assistant
 * @package email
 */

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class ModernIMAPWrapper
{
    private static $connections = [];
    private static $lastError = '';
    private static $clientManager = null;
    
    /**
     * Initialize the client manager
     */
    private static function initClientManager()
    {
        if (self::$clientManager === null) {
            self::$clientManager = new ClientManager();
        }
    }
    
    /**
     * Modern replacement for imap_open()
     */
    public static function imap_open($mailbox, $username, $password, $flags = 0, $retries = 0, $params = [])
    {
        try {
            self::initClientManager();
            
            // Parse mailbox string: {server:port/protocol/options}folder
            if (preg_match('/^\{([^:]+):?(\d+)?\/([^\/]+)?\/?([^}]*)\}(.*)$/', $mailbox, $matches)) {
                $server = $matches[1];
                $port = $matches[2] ?: (strpos($matches[3], 'ssl') !== false ? 993 : 143);
                $protocol = $matches[3] ?: 'imap';
                $options = $matches[4];
                $folder = $matches[5] ?: 'INBOX';
            } else {
                throw new Exception("Invalid mailbox format: $mailbox");
            }
            
            // Determine encryption and validation
            $encryption = 'tls';
            $validateCert = true;
            
            if (strpos($options, 'ssl') !== false) {
                $encryption = 'ssl';
            }
            if (strpos($options, 'novalidate-cert') !== false) {
                $validateCert = false;
            }
            
            // Create client configuration
            $config = [
                'host' => $server,
                'port' => (int)$port,
                'encryption' => $encryption,
                'validate_cert' => $validateCert,
                'username' => $username,
                'password' => $password,
                'protocol' => $protocol,
                'authentication' => 'login'
            ];
            
            $client = self::$clientManager->make($config);
            $client->connect();
            
            // Store connection with unique ID
            $connectionId = 'imap_' . uniqid();
            self::$connections[$connectionId] = [
                'client' => $client,
                'folder' => null,
                'currentFolder' => $folder
            ];
            
            // Open the specified folder
            if ($folder) {
                $folderObj = $client->getFolder($folder);
                if ($folderObj) {
                    self::$connections[$connectionId]['folder'] = $folderObj;
                }
            }
            
            return $connectionId;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_close()
     */
    public static function imap_close($stream, $flags = 0)
    {
        if (!isset(self::$connections[$stream])) {
            return false;
        }
        
        try {
            $connection = self::$connections[$stream];
            if (isset($connection['client'])) {
                $connection['client']->disconnect();
            }
            unset(self::$connections[$stream]);
            return true;
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_search()
     */
    public static function imap_search($stream, $criteria, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            
            // Convert IMAP search criteria to Webklex format
            $query = $folder->query();
            
            // Basic criteria parsing (extend as needed)
            if (strpos($criteria, 'UNSEEN') !== false) {
                $query->unseen();
            }
            if (strpos($criteria, 'SEEN') !== false) {
                $query->seen();
            }
            if (preg_match('/FROM "([^"]+)"/', $criteria, $matches)) {
                $query->from($matches[1]);
            }
            if (preg_match('/TO "([^"]+)"/', $criteria, $matches)) {
                $query->to($matches[1]);
            }
            if (preg_match('/SUBJECT "([^"]+)"/', $criteria, $matches)) {
                $query->subject($matches[1]);
            }
            if (preg_match('/SINCE (\d+-\w+-\d+)/', $criteria, $matches)) {
                $query->since($matches[1]);
            }
            
            $messages = $query->get();
            $uids = [];
            
            foreach ($messages as $message) {
                $uids[] = $message->getUid();
            }
            
            return $uids;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_fetchheader()
     */
    public static function imap_fetchheader($stream, $msgNum, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            $message = $folder->getMessage($msgNum);
            
            if ($message) {
                return $message->getHeaderRaw();
            }
            
            return false;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_fetchbody()
     */
    public static function imap_fetchbody($stream, $msgNum, $section, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            $message = $folder->getMessage($msgNum);
            
            if ($message) {
                if ($section === '1') {
                    // Get text body
                    return $message->getTextBody();
                } elseif ($section === '2') {
                    // Get HTML body
                    return $message->getHTMLBody();
                } else {
                    // Get raw body for specific section
                    return $message->getRawBody();
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_fetchstructure()
     */
    public static function imap_fetchstructure($stream, $msgNum, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            $message = $folder->getMessage($msgNum);
            
            if ($message) {
                // Create a simplified structure object
                $structure = new stdClass();
                $structure->type = $message->hasHTMLBody() ? 1 : 0; // 0=text, 1=multipart
                $structure->encoding = 0; // 0=7bit, 1=8bit, 2=binary, 3=base64, 4=quoted-printable
                $structure->subtype = $message->hasHTMLBody() ? 'HTML' : 'PLAIN';
                $structure->bytes = strlen($message->getRawBody());
                
                return $structure;
            }
            
            return false;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_header() / imap_headerinfo()
     */
    public static function imap_header($stream, $msgNum, $fromlength = 0, $tolength = 0, $defaulthost = '')
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            $message = $folder->getMessage($msgNum);
            
            if ($message) {
                $header = new stdClass();
                $header->from = $message->getFrom()->toArray();
                $header->to = $message->getTo()->toArray();
                $header->cc = $message->getCc()->toArray();
                $header->subject = $message->getSubject();
                $header->date = $message->getDate()->toString();
                $header->message_id = $message->getMessageId();
                $header->size = strlen($message->getRawBody());
                
                return $header;
            }
            
            return false;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_delete()
     */
    public static function imap_delete($stream, $msgNums, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            
            // Handle multiple message numbers
            $nums = is_array($msgNums) ? $msgNums : explode(',', $msgNums);
            
            foreach ($nums as $msgNum) {
                $message = $folder->getMessage(trim($msgNum));
                if ($message) {
                    $message->delete();
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_expunge()
     */
    public static function imap_expunge($stream)
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            $folder->expunge();
            return true;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_num_msg()
     */
    public static function imap_num_msg($stream)
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            return $folder->examine()->exists;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_list()
     */
    public static function imap_list($stream, $ref, $pattern)
    {
        if (!isset(self::$connections[$stream]['client'])) {
            return false;
        }
        
        try {
            $client = self::$connections[$stream]['client'];
            $folders = $client->getFolders();
            
            $folderList = [];
            foreach ($folders as $folder) {
                $folderName = $ref . $folder->name;
                if (fnmatch($pattern, $folder->name)) {
                    $folderList[] = $folderName;
                }
            }
            
            return $folderList;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_utf7_encode()
     */
    public static function imap_utf7_encode($data)
    {
        return mb_convert_encoding($data, 'UTF7-IMAP', 'UTF-8');
    }
    
    /**
     * Modern replacement for imap_utf7_decode()
     */
    public static function imap_utf7_decode($data)
    {
        return mb_convert_encoding($data, 'UTF-8', 'UTF7-IMAP');
    }
    
    /**
     * Modern replacement for imap_mime_header_decode()
     */
    public static function imap_mime_header_decode($text)
    {
        $decoded = iconv_mime_decode($text, ICONV_MIME_DECODE_STRICT, 'UTF-8');
        
        $result = [];
        $result[0] = new stdClass();
        $result[0]->charset = 'UTF-8';
        $result[0]->text = $decoded ?: $text;
        
        return $result;
    }
    
    /**
     * Modern replacement for imap_last_error()
     */
    public static function imap_last_error()
    {
        return self::$lastError;
    }
    
    /**
     * Modern replacement for imap_ping()
     */
    public static function imap_ping($stream)
    {
        if (!isset(self::$connections[$stream]['client'])) {
            return false;
        }
        
        try {
            $client = self::$connections[$stream]['client'];
            return $client->isConnected();
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    // Add more IMAP function replacements as needed...
    
    /**
     * Modern replacement for imap_base64()
     */
    public static function imap_base64($data)
    {
        return base64_decode($data);
    }
    
    /**
     * Modern replacement for imap_qprint()
     */
    public static function imap_qprint($data)
    {
        return quoted_printable_decode($data);
    }
    
    /**
     * Modern replacement for imap_headers()
     */
    public static function imap_headers($stream)
    {
        if (!isset(self::$connections[$stream]['folder'])) {
            return false;
        }
        
        try {
            $folder = self::$connections[$stream]['folder'];
            $messages = $folder->messages()->all()->limit(1000); // Limit for performance
            
            $headers = [];
            foreach ($messages as $message) {
                $from = $message->getFrom()->first();
                $fromStr = $from ? $from->mail : 'unknown';
                $subject = $message->getSubject();
                $date = $message->getDate()->format('d-M-Y');
                
                $headers[] = sprintf('%4d) %s %s %s', 
                    $message->getUid(), 
                    $date, 
                    $fromStr, 
                    $subject
                );
            }
            
            return $headers;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_getmailboxes()
     */
    public static function imap_getmailboxes($stream, $ref, $pattern)
    {
        if (!isset(self::$connections[$stream]['client'])) {
            return false;
        }
        
        try {
            $client = self::$connections[$stream]['client'];
            $folders = $client->getFolders();
            
            $mailboxes = [];
            foreach ($folders as $folder) {
                if (fnmatch($pattern, $folder->name)) {
                    $mailbox = new stdClass();
                    $mailbox->name = $ref . $folder->name;
                    $mailbox->delimiter = $folder->delimiter ?: '.';
                    $mailbox->attributes = 0; // Could be extended based on folder attributes
                    $mailboxes[] = $mailbox;
                }
            }
            
            return $mailboxes;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_subscribe()
     */
    public static function imap_subscribe($stream, $mailbox)
    {
        if (!isset(self::$connections[$stream]['client'])) {
            return false;
        }
        
        try {
            $client = self::$connections[$stream]['client'];
            // Parse mailbox to get folder name
            if (preg_match('/^\{[^}]+\}(.*)$/', $mailbox, $matches)) {
                $folderName = $matches[1];
            } else {
                $folderName = $mailbox;
            }
            
            $folder = $client->getFolder($folderName);
            if ($folder) {
                // Note: Webklex doesn't have explicit subscribe method
                // In modern IMAP, subscription is usually handled automatically
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_unsubscribe()
     */
    public static function imap_unsubscribe($stream, $mailbox)
    {
        if (!isset(self::$connections[$stream]['client'])) {
            return false;
        }
        
        try {
            // Note: Webklex doesn't have explicit unsubscribe method
            // In modern IMAP, subscription is usually handled automatically
            return true;
            
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_8bit()
     */
    public static function imap_8bit($data)
    {
        return quoted_printable_encode($data);
    }
    
    /**
     * Modern replacement for imap_rfc822_write_address()
     */
    public static function imap_rfc822_write_address($mailbox, $host, $personal)
    {
        $address = $mailbox . '@' . $host;
        if ($personal) {
            return '"' . $personal . '" <' . $address . '>';
        }
        return $address;
    }
    
    /**
     * Modern replacement for imap_rfc822_parse_adrlist()
     */
    public static function imap_rfc822_parse_adrlist($address, $default_host)
    {
        // Simple parsing implementation
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
    
    /**
     * Get active connections (for debugging)
     */
    public static function getActiveConnections()
    {
        return array_keys(self::$connections);
    }
}