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
        if (self::$clientManager === null)
        {
            self::$clientManager = new ClientManager();
        }
    }
    
    /**
     * Modern replacement for imap_open()
     */
    public static function imap_open($mailbox, $username, $password, $flags = 0, $retries = 0, $params = [])
    {
        $client = null;
        try
        {
            self::initClientManager();
            
            // Parse mailbox string: {server:port/protocol/options}folder
            if (preg_match('/^\{([^:]+):?(\d+)?\/([^\/]+)?\/?([^}]*)\}(.*)$/', $mailbox, $matches))
            {
                $server = $matches[1];
                $port = $matches[2] ?: (strpos($matches[3], 'ssl') !== false ? 993 : 143);
                $protocol = $matches[3] ?: 'imap';
                $options = $matches[4];
                $folder = $matches[5] ?: 'INBOX';
            }
            else
            {
                throw new Exception("Invalid mailbox format: $mailbox");
            }
            
            // Determine encryption and validation
            $encryption = 'tls';
            $validateCert = true;
            
            if (strpos($options, 'ssl') !== false)
            {
                $encryption = 'ssl';
            }
            if (strpos($options, 'novalidate-cert') !== false)
            {
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
                'authentication' => (string) ($params['authentication'] ?? 'login')
            ];
            
            $client = self::$clientManager->make($config);
            $client->connect();

            $folderObj = $client->getFolderByPath($folder, true);
            if (!$folderObj)
            {
                throw new RuntimeException('Mailbox does not exist');
            }
            $client->openFolder($folderObj->path, true);
            
            // Store connection with unique ID
            $connectionId = 'imap_' . uniqid();
            self::$connections[$connectionId] = [
                'client' => $client,
                'folder' => $folderObj,
                'currentFolder' => $folder
            ];
            
            return $connectionId;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            if ($client !== null)
            {
                try
                {
                    $client->disconnect();
                }
                catch (Exception $cleanupException)
                {
                    self::$lastError .= ' Connection cleanup failed (' . get_class($cleanupException) . ').';
                }
            }
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_close()
     */
    public static function imap_close($stream, $flags = 0)
    {
        if (!isset(self::$connections[$stream]))
        {
            return false;
        }
        
        try
        {
            $connection = self::$connections[$stream];
            // CL_EXPUNGE is unavailable when the native IMAP extension is absent.
            if ($flags & 32768)
            {
                $client = $connection['client'];
                $client->openFolder($connection['folder']->path);
                $client->getConnection()->requestAndResponse('CLOSE', [], true)->validatedData();
                $client->setActiveFolder(null);
                self::clearMessageMetadata($stream);
            }
            if (isset($connection['client']))
            {
                $connection['client']->disconnect();
            }
            unset(self::$connections[$stream]);
            return true;
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    public static function imap_reopen($stream, $mailbox, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['client']))
        {
            return false;
        }

        $client = self::$connections[$stream]['client'];
        try
        {
            $path = preg_replace('/^\{[^}]*\}/', '', $mailbox);
            $folder = $client->getFolderByPath($path !== '' ? $path : 'INBOX', true);
            if (!$folder)
            {
                throw new RuntimeException('Mailbox does not exist');
            }
            $client->openFolder($folder->path, true);
            self::$connections[$stream]['folder'] = $folder;
            self::$connections[$stream]['currentFolder'] = $folder->path;
            self::clearMessageMetadata($stream);
            return true;
        }
        catch (Exception $exception)
        {
            $client->setActiveFolder(null);
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    /**
     * Modern replacement for imap_search()
     */
    public static function imap_search($stream, $criteria, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }
        
        try
        {
            $folder = self::$connections[$stream]['folder'];
            
            // Convert IMAP search criteria to Webklex format
            $query = $folder->query();

            $tokens = [];
            $offset = 0;
            $criteria = trim($criteria);
            while ($offset < strlen($criteria))
            {
                if (!preg_match('/\G\s*(?:"((?:[^"\\\\\r\n]|\\\\["\\\\])*)"|([A-Za-z0-9_.@:+\/-]+))(?=\s|$)/', $criteria, $match, 0, $offset))
                {
                    throw new InvalidArgumentException('Invalid IMAP search criteria');
                }
                $tokens[] = isset($match[2]) ? $match[2] : str_replace(['\\"', '\\\\'], ['"', '\\'], $match[1]);
                $offset += strlen($match[0]);
            }
            if (!$tokens)
            {
                throw new InvalidArgumentException('Empty IMAP search criteria');
            }
            $flagMethods = ['ALL' => 'all', 'UNSEEN' => 'unseen', 'SEEN' => 'seen', 'DELETED' => 'deleted'];
            $otherFlags = ['ANSWERED', 'FLAGGED', 'NEW', 'OLD', 'RECENT', 'UNANSWERED', 'UNDELETED', 'UNFLAGGED'];
            for ($i = 0; $i < count($tokens); $i++)
            {
                $operator = strtoupper($tokens[$i]);
                if (isset($flagMethods[$operator]))
                {
                    $query->{$flagMethods[$operator]}();
                }
                elseif (in_array($operator, $otherFlags, true))
                {
                    $query->where($operator);
                }
                elseif (in_array($operator, ['SUBJECT', 'BODY', 'FROM', 'TO', 'CC', 'BCC', 'KEYWORD', 'ON', 'BEFORE', 'SINCE'], true))
                {
                    if (!isset($tokens[++$i]))
                    {
                        throw new InvalidArgumentException('Missing IMAP search operand');
                    }
                    $value = $tokens[$i];
                    if (in_array($operator, ['ON', 'BEFORE', 'SINCE'], true))
                    {
                        $date = DateTimeImmutable::createFromFormat('!m/d/Y', $value)
                            ?: DateTimeImmutable::createFromFormat('!d-M-Y', $value);
                        if (!$date || !in_array($value, [$date->format('m/d/Y'), $date->format('d-M-Y')], true))
                        {
                            throw new InvalidArgumentException('Invalid IMAP search date');
                        }
                        $value = $date->format('d-M-Y');
                    }
                    $query->where($operator, $value);
                }
                else
                {
                    throw new InvalidArgumentException('Unsupported IMAP search criterion: ' . $operator);
                }
            }
            
            $messages = $query->get();
            $uids = [];
            
            foreach ($messages as $message)
            {
                $uids[] = (int) (($flags & 1) ? $message->getUid() : $message->getMsgn());
            }
            
            return $uids;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    public static function imap_createmailbox($stream, $mailbox)
    {
        if (!isset(self::$connections[$stream]['client']))
        {
            return false;
        }

        try
        {
            $path = preg_replace('/^\{[^}]*\}/', '', $mailbox);
            self::$connections[$stream]['client']->createFolder($path, false, true);
            return true;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_append($stream, $folder, $message, $flags = '')
    {
        if (!isset(self::$connections[$stream]['client']))
        {
        	return false;
        }
        try
        {
            $path = preg_replace('/^\{[^}]*\}/', '', $folder);
            $messageFlags = preg_split('/\s+/', trim((string) $flags), -1, PREG_SPLIT_NO_EMPTY);
            self::$connections[$stream]['client']->getConnection()->appendMessage($path, $message, $messageFlags)->validatedData();
            return true;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_mail_copy($stream, $msgList, $mailbox, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
        	return false;
        }
        try
        {
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            $path = preg_replace('/^\{[^}]*\}/', '', $mailbox);
            $mode = ($flags & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN;
            $client->getConnection()->copyMessage($path, $msgList, null, $mode)->validatedData();
            return true;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_renamemailbox($stream, $oldName, $newName)
    {
        if (!isset(self::$connections[$stream]['client']))
        {
        	return false;
        }
        try
        {
            $client = self::$connections[$stream]['client'];
            $oldPath = preg_replace('/^\{[^}]*\}/', '', $oldName);
            $newPath = preg_replace('/^\{[^}]*\}/', '', $newName);
            $client->getConnection()->renameFolder($oldPath, $newPath)->validatedData();
            self::clearMessageMetadata($stream);
            $client->setActiveFolder(null);
            $current = self::$connections[$stream]['folder'];
            if ($current && ($current->path === $oldPath
                || ($current->delimiter && str_starts_with($current->path, $oldPath . $current->delimiter))))
            {
                $path = $newPath . substr($current->path, strlen($oldPath));
                self::$connections[$stream]['folder'] = $client->getFolderByPath($path, true);
                self::$connections[$stream]['currentFolder'] = $path;
            }
            return true;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_deletemailbox($stream, $mailbox)
    {
        if (!isset(self::$connections[$stream]['client']))
        {
            return false;
        }

        try
        {
            $path = preg_replace('/^\{[^}]*\}/', '', $mailbox);
            $client = self::$connections[$stream]['client'];
            if (!$client->getFolderByPath($path, true))
            {
                throw new RuntimeException('Mailbox does not exist');
            }
            $path = \Webklex\PHPIMAP\EncodingAliases::convert($path, 'UTF7-IMAP', 'UTF-8');
            $client->deleteFolder($path, false);
            return true;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_mail_move($stream, $msgList, $mailbox, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }

        try
        {
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $path = preg_replace('/^\{[^}]*\}/', '', $mailbox);
            $client->openFolder($connection['folder']->path);
            $mode = ($flags & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN;
            $client->getConnection()->moveMessage($path, $msgList, null, $mode)->validatedData();
            self::clearMessageMetadata($stream);
            return true;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_setflag_full($stream, $msgNums, $flag, $options = 0)
    {
        return self::updateFlags($stream, $msgNums, $flag, $options, null);
    }

    public static function imap_clearflag_full($stream, $msgNums, $flag, $options = 0)
    {
        return self::updateFlags($stream, $msgNums, $flag, $options, '-');
    }

    private static function updateFlags($stream, $msgNums, $flag, $options, $operation)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }

        try
        {
            if (!preg_match('/^[1-9][0-9]*(?::[1-9][0-9]*)?(?:,[1-9][0-9]*(?::[1-9][0-9]*)?)*$/', (string) $msgNums))
            {
                throw new InvalidArgumentException('Invalid message set');
            }
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            $mode = ($options & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN;
            $flags = preg_split('/\s+/', trim($flag), -1, PREG_SPLIT_NO_EMPTY);
            self::clearMessageMetadata($stream);
            foreach (explode(',', (string) $msgNums) as $range)
            {
                $bounds = explode(':', $range);
                $client->getConnection()->store($flags, (int) $bounds[0], isset($bounds[1]) ? (int) $bounds[1] : null, $operation, true, $mode)->validatedData();
            }
            return true;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_sort($stream, $criteria, $reverse, $options = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }

        try
        {
            $criteria = filter_var($criteria, FILTER_VALIDATE_INT);
            if (!in_array($criteria, range(0, 6), true))
            {
                throw new InvalidArgumentException('Invalid IMAP sort criterion');
            }

            $reverse = (bool) $reverse;
            $options = (int) $options;

            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            $metadata = $client->getConnection()->fetch(
                ['UID', 'INTERNALDATE', 'RFC822.SIZE', 'BODY.PEEK[HEADER]'],
                1,
                INF,
                \Webklex\PHPIMAP\IMAP::ST_MSGN
            )->validatedData();
            $messages = [];
            foreach ($metadata as $sequence => $attributes)
            {
                $header = new \Webklex\PHPIMAP\Header($attributes['BODY[HEADER]'] ?? '', $client->getConfig());
                $value = '';
                switch ($criteria)
                {
                    case 0:
                        $value = strtotime((string) $header->get('date')) ?: 0;
                        break;
                    case 1:
                        $value = strtotime($attributes['INTERNALDATE'] ?? '') ?: 0;
                        break;
                    case 2:
                    case 4:
                    case 5:
                        $field = [2 => 'from', 4 => 'to', 5 => 'cc'][$criteria];
                        $address = $header->get($field)->first();
                        $value = strtolower($address->mailbox ?? '');
                        if ($value === '')
                        {
                            $value = "\xff";
                        }
                        break;
                    case 3:
                        $value = strtolower(preg_replace('/^(?:(?:re|fw|fwd)\s*:\s*)+/i', '', trim((string) $header->get('subject'))));
                        if ($value === '')
                        {
                            $value = "\xff";
                        }
                        break;
                    case 6:
                        $value = (int) ($attributes['RFC822.SIZE'] ?? 0);
                        break;
                }
                $messages[] = ['id' => (int) (($options & 1) ? $attributes['UID'] : $sequence), 'value' => $value];
            }
            usort($messages, static function ($left, $right) use ($reverse)
            {
                $comparison = is_string($left['value'])
                    ? strcmp($left['value'], $right['value'])
                    : $left['value'] <=> $right['value'];
                return $reverse ? -$comparison : $comparison;
            });
            return array_column($messages, 'id');
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }

    private static function getMessage($stream, $msgNum, $flags = 0, bool $peek = true)
    {
        $query = self::$connections[$stream]['folder']->query();
        $query = $peek ? $query->leaveUnread() : $query->markAsRead();
        return ($flags & 1)
            ? $query->getMessageByUid((int) $msgNum)
            : $query->getMessageByMsgn((int) $msgNum);
    }

    public static function imap_prefetch_message_metadata($stream, array $messageNumbers, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }

        $messageNumbers = array_values(array_unique(array_filter(array_map('intval', $messageNumbers), static fn($number) => $number > 0)));
        if (!$messageNumbers)
        {
            return [];
        }

        try
        {
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            $mode = ($flags & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN;
            $metadata = $client->getConnection()->fetch(
                ['UID', 'INTERNALDATE', 'RFC822.SIZE', 'BODY.PEEK[HEADER]', 'FLAGS'],
                $messageNumbers,
                null,
                $mode
            )->validatedData();

            $foundMessageNumbers = [];
            foreach ($metadata as $messageNumber => $attributes)
            {
                $identifier = (int) (($flags & 1) ? ($attributes['UID'] ?? $messageNumber) : $messageNumber);
                $foundMessageNumbers[] = $identifier;
                if (isset($attributes['BODY[HEADER]']) && is_string($attributes['BODY[HEADER]']) && $attributes['BODY[HEADER]'] !== '')
                {
                    self::$connections[$stream]['messageHeaders'][$connection['folder']->path][$flags & 1][$identifier]
                        = self::convertFetchedHeader($attributes, $identifier, $client);
                }
            }
            return $foundMessageNumbers;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_fetch_sort_metadata($stream, array $messageNumbers, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }

        $messageNumbers = array_values(array_unique(array_filter(array_map('intval', $messageNumbers), static fn($number) => $number > 0)));
        if (!$messageNumbers)
        {
            return [];
        }

        try
        {
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            $mode = ($flags & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN;
            $metadata = $client->getConnection()->fetch(
                ['UID', 'INTERNALDATE', 'RFC822.SIZE', 'BODY.PEEK[HEADER]'],
                $messageNumbers,
                null,
                $mode
            )->validatedData();

            $result = [];
            foreach ($metadata as $sequence => $attributes)
            {
                $uid = (int) ($attributes['UID'] ?? $sequence);
                $header = new \Webklex\PHPIMAP\Header((string) ($attributes['BODY[HEADER]'] ?? ''), $client->getConfig());
                $firstMailbox = static function ($field) use ($header): string
                {
                    $address = $header->get($field)->first();
                    return strtolower((string) ($address->mailbox ?? ''));
                };
                $subject = strtolower(preg_replace('/^(?:(?:re|fw|fwd)\s*:\s*)+/i', '', trim((string) $header->get('subject'))));
                $result[] = [
                    'uid' => $uid,
                    'date_sent' => strtotime((string) $header->get('date')) ?: 0,
                    'date_received' => strtotime((string) ($attributes['INTERNALDATE'] ?? '')) ?: 0,
                    'from_sort' => $firstMailbox('from'),
                    'to_sort' => $firstMailbox('to'),
                    'cc_sort' => $firstMailbox('cc'),
                    'subject_sort' => $subject,
                    'message_size' => (int) ($attributes['RFC822.SIZE'] ?? 0),
                ];
            }
            return $result;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    private static function convertFetchedHeader(array $attributes, int $messageNumber, $client): stdClass
    {
        $source = new \Webklex\PHPIMAP\Header((string) ($attributes['BODY[HEADER]'] ?? ''), $client->getConfig());
        $header = new stdClass();
        foreach (['from', 'to', 'cc', 'bcc', 'reply_to', 'sender', 'return_path'] as $field)
        {
            $addresses = $source->get($field)->toArray();
            $header->$field = array_map(static fn($address) => (object) [
                'personal' => $address->personal,
                'mailbox' => $address->mailbox,
                'host' => $address->host,
            ], $addresses);
            $header->{$field . 'address'} = implode(', ', array_map(static fn($address) => (string) $address, $addresses));
        }
        if (!$header->reply_to)
        {
            $header->reply_to = $header->from;
            $header->reply_toaddress = $header->fromaddress;
        }
        $header->subject = $header->Subject = (string) ($source->get('subject')->first() ?? '');
        $header->date = $header->Date = (string) ($source->get('date')->first() ?? '');
        $header->udate = strtotime((string) ($attributes['INTERNALDATE'] ?? '')) ?: 0;
        $header->message_id = (string) ($source->get('message_id')->first() ?? '');
        $header->size = $header->Size = (string) ($attributes['RFC822.SIZE'] ?? 0);
        $header->Msgno = (string) $messageNumber;

        $flags = array_map(static fn($flag) => strtolower(ltrim((string) $flag, '\\')), (array) ($attributes['FLAGS'] ?? []));
        $hasFlag = static fn($flag) => in_array($flag, $flags, true);
        $isSeen = $hasFlag('seen');
        $header->Unseen = $isSeen ? ' ' : 'U';
        $header->Recent = $hasFlag('recent') ? ($isSeen ? 'R' : 'N') : ' ';
        foreach (['answered' => 'A', 'flagged' => 'F', 'deleted' => 'D', 'draft' => 'X'] as $flag => $marker)
        {
            $header->{ucfirst($flag)} = $hasFlag($flag) ? $marker : ' ';
        }
        return $header;
    }

    /**
     * Modern replacement for imap_fetchheader()
     */
    public static function imap_fetchheader($stream, $msgNum, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }
        
        try
        {
            $message = self::getMessage($stream, $msgNum, $flags);
            
            if ($message)
            {
                return $message->getHeader()->raw;
            }
            return false;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_fetchbody()
     */
    public static function imap_body($stream, $msgNum, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
        	return false;
        }
        try
        {
            $peek = (bool) ($flags & 2);
            if (!$peek)
            {
                self::clearMessageMetadata($stream);
            }
            $message = self::getMessage($stream, $msgNum, $flags, $peek);
            return $message ? $message->getRawBody() : false;
        }
        catch (Exception $exception)
        {
            self::$lastError = $exception->getMessage();
            return false;
        }
    }

    public static function imap_fetchbody($stream, $msgNum, $section, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }
        
        try
        {
            if (!preg_match('/^[1-9][0-9]*(?:\.[1-9][0-9]*)*$/', (string) $section))
            {
                throw new InvalidArgumentException('Invalid MIME section');
            }
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            $mode = ($flags & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN;
            $peek = (bool) ($flags & 2);
            if (!$peek)
            {
                self::clearMessageMetadata($stream);
            }
            $data = $client->getConnection()->fetch([($peek ? 'BODY.PEEK[' : 'BODY[') . $section . ']', 'UID'], [(int) $msgNum], null, $mode)->validatedData();
            $message = $data ? reset($data) : [];
            return $message['BODY[' . $section . ']'] ?? false;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_fetchstructure()
     */
    public static function imap_fetchstructure($stream, $msgNum, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }

        $structure = self::$connections[$stream]['messageStructures'][(int) $msgNum] ?? null;
        if (is_array($structure))
        {
            return self::convertBodyStructure($structure);
        }
        
        try
        {
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            $mode = ($flags & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN;
            // Read raw tokens: the library misparses quoted strings immediately followed by ")".
            $response = $client->getConnection()->requestAndResponse(
                ($mode === \Webklex\PHPIMAP\IMAP::ST_UID ? 'UID FETCH' : 'FETCH'),
                [(int) $msgNum . ':' . (int) $msgNum, '(BODYSTRUCTURE UID)'],
                true
            );
            $response->validate();
            $raw = implode('', $response->getResponse());
            if (preg_match_all('/^\* (\d+) FETCH /mi', $raw, $matches, PREG_OFFSET_CAPTURE))
            {
                foreach ($matches[0] as $index => $match)
                {
                    $offset = $match[1] + strlen($match[0]);
                    $attributes = self::parseStructureResponseValue($raw, $offset);
                    if (!is_array($attributes) || count($attributes) % 2 !== 0)
                    {
                        throw new \RuntimeException('Invalid IMAP structure response attributes.');
                    }
                    $attributes = array_chunk($attributes, 2);
                    $fields = [];
                    foreach ($attributes as [$key, $value])
                    {
                        if (!is_string($key))
                        {
                            throw new \RuntimeException('Invalid IMAP structure response field.');
                        }
                        $fields[strtoupper($key)] = $value;
                    }
                    $identifier = ($flags & 1) ? ($fields['UID'] ?? 0) : $matches[1][$index][0];
                    if ((int) $identifier === (int) $msgNum && is_array($fields['BODYSTRUCTURE'] ?? null))
                    {
                        return self::convertBodyStructure($fields['BODYSTRUCTURE']);
                    }
                }
            }

            throw new \RuntimeException('IMAP server did not return the requested message structure.');
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }

    private static function parseStructureResponseValue(string $raw, int &$offset, int $depth = 0): array|string|null
    {
        if ($depth > 100)
        {
            throw new \RuntimeException('IMAP structure response is nested too deeply.');
        }
        $length = strlen($raw);
        while ($offset < $length && ctype_space($raw[$offset]))
        {
        	$offset++;
        }
        if ($offset >= $length)
        {
            throw new \RuntimeException('Incomplete IMAP structure response.');
        }
        $character = $raw[$offset++];
        if ($character === '(')
        {
            $values = [];
            while ($offset < $length)
            {
                while ($offset < $length && ctype_space($raw[$offset]))
                {
                	$offset++;
                }
                if ($offset < $length && $raw[$offset] === ')')
                {
                    $offset++;
                    return $values;
                }
                $values[] = self::parseStructureResponseValue($raw, $offset, $depth + 1);
            }
            throw new \RuntimeException('Unclosed IMAP structure response list.');
        }
        if ($character === '"')
        {
            $value = '';
            while ($offset < $length)
            {
                $character = $raw[$offset++];
                if ($character === '"')
                {
                	return $value;
                }
                if ($character === '\\')
                {
                    if ($offset >= $length)
                    {
                    	break;
                    }
                    $character = $raw[$offset++];
                }
                $value .= $character;
            }
            throw new \RuntimeException('Unclosed IMAP structure response string.');
        }
        $offset--;
        if ($character === '{' && preg_match('/\G\{(\d+)\+?\}\r\n/', $raw, $literal, 0, $offset))
        {
            $offset += strlen($literal[0]);
            $size = (int) $literal[1];
            if ($size > $length - $offset)
            {
                throw new \RuntimeException('Incomplete IMAP structure response literal.');
            }
            $value = substr($raw, $offset, $size);
            $offset += $size;
            return $value;
        }
        if (preg_match('/\G[^\s(){}"]+/', $raw, $atom, 0, $offset))
        {
            $offset += strlen($atom[0]);
            return strtoupper($atom[0]) === 'NIL' ? null : $atom[0];
        }
        throw new \RuntimeException('Invalid IMAP structure response token.');
    }

    private static function convertBodyStructure(array $bodyStructure): stdClass
    {
        $isMultipart = isset($bodyStructure[0]) && is_array($bodyStructure[0]);
        if ($isMultipart)
        {
            $childCount = 0;
            $parts = [];
            while (isset($bodyStructure[$childCount]) && is_array($bodyStructure[$childCount]))
            {
                $parts[] = self::convertBodyStructure($bodyStructure[$childCount]);
                $childCount++;
            }
            $type = 'MULTIPART';
            $subtype = (string) ($bodyStructure[$childCount] ?? '');
            $parameters = $bodyStructure[$childCount + 1] ?? null;
            $disposition = $bodyStructure[$childCount + 2] ?? null;
        }
        else
        {
            $type = (string) ($bodyStructure[0] ?? 'OTHER');
            $subtype = (string) ($bodyStructure[1] ?? '');
            $parameters = $bodyStructure[2] ?? null;
            $dispositionIndex = strtoupper($type) === 'TEXT' ? 9 : 8;
            if (strtoupper($type) === 'MESSAGE' && strtoupper($subtype) === 'RFC822')
            {
                if (isset($bodyStructure[8]) && is_array($bodyStructure[8]))
                {
                    $parts = [self::convertBodyStructure($bodyStructure[8])];
                }
                else
                {
                    $parts = [];
                }
                $dispositionIndex = 11;
            }
            else
            {
                $parts = [];
            }
            $disposition = $bodyStructure[$dispositionIndex] ?? null;
        }

        $type = strtoupper($type);
        $encoding = strtoupper((string) ($isMultipart ? '' : ($bodyStructure[5] ?? '')));
        $structure = new stdClass();
        $structure->type = ['TEXT' => 0, 'MULTIPART' => 1, 'MESSAGE' => 2, 'APPLICATION' => 3, 'AUDIO' => 4, 'IMAGE' => 5, 'VIDEO' => 6][$type] ?? 7;
        $structure->subtype = strtoupper($subtype);
        $structure->ifsubtype = $subtype !== '' ? 1 : 0;
        $structure->encoding = ['7BIT' => 0, '8BIT' => 1, 'BINARY' => 2, 'BASE64' => 3, 'QUOTED-PRINTABLE' => 4][$encoding] ?? 5;
        $structure->bytes = (int) ($isMultipart ? 0 : ($bodyStructure[6] ?? 0));
        $structure->id = $isMultipart ? '' : (string) ($bodyStructure[3] ?? '');
        $structure->ifid = $structure->id !== '' ? 1 : 0;
        $structure->description = $isMultipart ? '' : (string) ($bodyStructure[4] ?? '');
        $structure->ifdescription = $structure->description !== '' ? 1 : 0;
        $structure->parameters = self::convertBodyParameters($parameters);
        $structure->ifparameters = $structure->parameters ? 1 : 0;
        $dispositionType = is_array($disposition) ? (string) ($disposition[0] ?? '') : '';
        $structure->disposition = strtoupper($dispositionType);
        $structure->ifdisposition = $structure->disposition !== '' ? 1 : 0;
        $structure->dparameters = self::convertBodyParameters(is_array($disposition) ? ($disposition[1] ?? null) : null);
        $structure->ifdparameters = $structure->dparameters ? 1 : 0;
        if ($parts)
        {
            $structure->parts = $parts;
        }
        return $structure;
    }

    private static function convertBodyParameters($parameters): array
    {
        if (!is_array($parameters))
        {
            return [];
        }

        if ($parameters && !is_array(reset($parameters)))
        {
            if (count($parameters) % 2 !== 0)
            {
                throw new \RuntimeException('Invalid IMAP body parameter pairs.');
            }
            $parameters = array_chunk($parameters, 2);
        }

        $result = [];
        foreach ($parameters as $parameter)
        {
            if (is_array($parameter) && isset($parameter[0]))
            {
                $result[] = (object) [
                    'attribute' => strtoupper((string) $parameter[0]),
                    'value' => (string) ($parameter[1] ?? ''),
                ];
            }
        }
        return $result;
    }
    
    public static function convertMimeStructure(\ZBateson\MailMimeParser\Message\IMimePart $part): stdClass
    {
        $structure = new stdClass();
        [$type, $subtype] = explode('/', strtoupper($part->getContentType()), 2);
        $structure->type = ['TEXT' => 0, 'MULTIPART' => 1, 'MESSAGE' => 2, 'APPLICATION' => 3, 'AUDIO' => 4, 'IMAGE' => 5, 'VIDEO' => 6][$type] ?? 7;
        $structure->subtype = $subtype;
        $structure->ifsubtype = 1;
        $structure->encoding = ['7BIT' => 0, '8BIT' => 1, 'BINARY' => 2, 'BASE64' => 3, 'QUOTED-PRINTABLE' => 4][strtoupper($part->getContentTransferEncoding())] ?? 5;
        $structure->bytes = strlen($part->getBinaryContentStream()?->getContents() ?? '');
        $structure->id = $part->getContentId() ?? '';
        $structure->ifid = $structure->id !== '' ? 1 : 0;
        $structure->description = $part->getHeaderValue('Content-Description', '');
        $structure->ifdescription = $structure->description !== '' ? 1 : 0;
        $structure->parameters = [];
        foreach (['charset', 'name', 'boundary'] as $attribute)
        {
            $value = $part->getHeaderParameter('Content-Type', $attribute);
            if ($value !== null)
            {
            	$structure->parameters[] = (object) ['attribute' => strtoupper($attribute), 'value' => $value];
            }
        }
        $structure->ifparameters = $structure->parameters ? 1 : 0;
        $structure->disposition = strtoupper($part->getContentDisposition(''));
        $structure->ifdisposition = $structure->disposition !== '' ? 1 : 0;
        $filename = $part->getFilename();
        $structure->dparameters = $filename === null ? [] : [(object) ['attribute' => 'FILENAME', 'value' => $filename]];
        $structure->ifdparameters = $structure->dparameters ? 1 : 0;
        $children = $part->getChildParts();
        if ($children)
        {
        	$structure->parts = array_map([self::class, 'convertMimeStructure'], $children);
        }
        return $structure;
    }

    /**
     * Modern replacement for imap_header() / imap_headerinfo()
     */
    public static function imap_header($stream, $msgNum, $fromlength = 0, $tolength = 0, $defaulthost = '', $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }

        $cachedHeader = self::$connections[$stream]['messageHeaders'][self::$connections[$stream]['folder']->path][$flags & 1][(int) $msgNum] ?? null;
        if ($cachedHeader instanceof stdClass)
        {
            return $cachedHeader;
        }
        
        try
        {
            $message = self::getMessage($stream, $msgNum, $flags);
            
            if ($message)
            {
                $header = new stdClass();
                foreach (['from', 'to', 'cc', 'bcc', 'reply_to', 'sender', 'return_path'] as $field)
                {
                    $addresses = $message->get($field)->toArray();
                    $header->$field = array_map(static fn($address) => (object) [
                        'personal' => $address->personal,
                        'mailbox' => $address->mailbox,
                        'host' => $address->host,
                    ], $addresses);
                    $addressField = $field . 'address';
                    $header->$addressField = implode(', ', array_map(static fn($address) => (string) $address, $addresses));
                }
                if (!$header->reply_to)
                {
                    $header->reply_to = $header->from;
                    $header->reply_toaddress = $header->fromaddress;
                }
                $header->subject = $header->Subject = (string) $message->getSubject();
                $date = $message->getDate()->first();
                $header->date = $header->Date = $date ? (string) $date : '';
                $mode = ($flags & 1) ? \Webklex\PHPIMAP\IMAP::ST_UID : \Webklex\PHPIMAP\IMAP::ST_MSGN;
                $internalDates = $message->getClient()->getConnection()->fetch(
                    ['UID', 'INTERNALDATE'], [(int) $msgNum], null, $mode
                )->validatedData();
                $internalDate = $internalDates[(int) $msgNum]['INTERNALDATE'] ?? '';
                $header->udate = strtotime($internalDate) ?: 0;
                $header->message_id = (string) $message->getMessageId();
                $header->size = $header->Size = (string) $message->getSize();
                $header->Msgno = (string) $msgNum;
                $flags = $message->getFlags();
                $header->Unseen = $flags->has('seen') ? ' ' : 'U';
                $header->Recent = $flags->has('recent') ? ($flags->has('seen') ? 'R' : 'N') : ' ';
                foreach (['answered' => 'A', 'flagged' => 'F', 'deleted' => 'D', 'draft' => 'X'] as $flag => $marker)
                {
                    $field = ucfirst($flag);
                    $header->$field = $flags->has($flag) ? $marker : ' ';
                }
                
                return $header;
            }
            
            return false;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_delete()
     */
    public static function imap_delete($stream, $msgNums, $flags = 0)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }
        
        try
        {
            $set = is_array($msgNums) ? implode(',', $msgNums) : (string) $msgNums;
            if (!preg_match('/^(?:[1-9][0-9]*|\*)(?::(?:[1-9][0-9]*|\*))?(?:,(?:[1-9][0-9]*|\*)(?::(?:[1-9][0-9]*|\*))?)*$/D', $set))
            {
                throw new InvalidArgumentException('Invalid message set');
            }
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            self::clearMessageMetadata($stream);
            // Webklex store() only accepts integer bounds, so pass the validated set intact.
            $client->getConnection()->requestAndResponse(
                ($flags & 1) ? 'UID STORE' : 'STORE',
                [$set, '+FLAGS.SILENT', '(\\Deleted)'],
                true
            )->validatedData();
            
            return true;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_expunge()
     */
    public static function imap_expunge($stream)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }
        
        try
        {
            $connection = self::$connections[$stream];
            $connection['client']->openFolder($connection['folder']->path);
            self::clearMessageMetadata($stream);
            $connection['client']->getConnection()->expunge()->validatedData();
            return true;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }

    }

    private static function clearMessageMetadata($stream): void
    {
        unset(self::$connections[$stream]['messageHeaders'], self::$connections[$stream]['messageStructures']);
    }
    
    public static function imap_status($stream, $mailbox, $options)
    {
        if (!isset(self::$connections[$stream]['client']))
        {
            return false;
        }

        try
        {
            $folder = preg_replace('/^\{[^}]+\}/', '', $mailbox);
            $fields = [1 => 'messages', 2 => 'recent', 4 => 'unseen', 8 => 'uidnext', 16 => 'uidvalidity'];
            $arguments = [];
            foreach ($fields as $flag => $field)
            {
                if ($options & $flag)
                {
                    $arguments[] = strtoupper($field);
                }
            }
            $client = self::$connections[$stream]['client'];
            $data = $client->getConnection()->folderStatus($folder, $arguments)->validatedData();
            $data = array_change_key_case($data, CASE_LOWER);
            $status = new stdClass();
            $status->flags = 0;
            foreach ($fields as $flag => $field)
            {
                if (($options & $flag) && isset($data[$field]))
                {
                    $status->$field = (int) $data[$field];
                    $status->flags |= $flag;
                }
            }
            return $status;
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Modern replacement for imap_num_msg()
     */
    public static function imap_num_msg($stream)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }
        
        try
        {
            $folder = self::$connections[$stream]['folder'];
            $status = self::imap_status($stream, $folder->path, 1);
            if ($status === false)
            {
                return false;
            }
            if (!isset($status->messages))
            {
                throw new RuntimeException('IMAP STATUS response did not include a message count.');
            }
            return $status->messages;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    public static function imap_msgno($stream, $uid)
    {
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }

        try
        {
            $connection = self::$connections[$stream];
            $client = $connection['client'];
            $client->openFolder($connection['folder']->path);
            return $client->getConnection()->getMessageNumber((string) $uid)->validate()->integer();
        }
        catch (\Webklex\PHPIMAP\Exceptions\MessageNotFoundException $e)
        {
            self::$lastError = $e->getMessage();
            return 0;
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Modern replacement for imap_list()
     */
    public static function imap_list($stream, $ref, $pattern)
    {
        $mailboxes = self::imap_getmailboxes($stream, $ref, $pattern);
        return $mailboxes === false ? false : array_column($mailboxes, 'name');
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
        if (!isset(self::$connections[$stream]['client']))
        {
            return false;
        }
        
        try
        {
            $client = self::$connections[$stream]['client'];
            return $client->isConnected();
            
        }
        catch (Exception $e)
        {
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
        if (!isset(self::$connections[$stream]['folder']))
        {
            return false;
        }
        
        try
        {
            $folder = self::$connections[$stream]['folder'];
            $messages = $folder->messages()->all()->limit(1000); // Limit for performance
            
            $headers = [];
            foreach ($messages as $message)
            {
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
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_getmailboxes()
     */
    public static function imap_getmailboxes($stream, $ref, $pattern)
    {
        if (!isset(self::$connections[$stream]['client']))
        {
            return false;
        }
        
        try
        {
            $client = self::$connections[$stream]['client'];
            $prefix = '';
            $reference = $ref;
            if (preg_match('/^(\{[^}]+\})(.*)$/s', $ref, $matches))
            {
                $prefix = $matches[1];
                $reference = $matches[2];
            }
            $folders = $client->getConnection()->folders($reference, $pattern)->validatedData();
            
            $mailboxes = [];
            $attributeFlags = [
                '\\noinferiors' => 1, '\\noselect' => 2, '\\marked' => 4, '\\unmarked' => 8,
                '\\referral' => 16, '\\haschildren' => 32, '\\hasnochildren' => 64,
            ];
            foreach ($folders as $path => $folder)
            {
                $mailbox = new stdClass();
                $mailbox->name = $prefix . $path;
                $mailbox->delimiter = $folder['delimiter'];
                $mailbox->attributes = 0;
                foreach ($folder['flags'] as $flag)
                {
                    $mailbox->attributes |= $attributeFlags[strtolower($flag)] ?? 0;
                }
                $mailboxes[] = $mailbox;
            }
            
            return $mailboxes;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_subscribe()
     */
    public static function imap_subscribe($stream, $mailbox)
    {
        if (!isset(self::$connections[$stream]['client']))
        {
            return false;
        }
        
        try
        {
            $client = self::$connections[$stream]['client'];
            $path = preg_replace('/^\{[^}]+\}/', '', $mailbox);
            $client->getConnection()->subscribeFolder($path)->validatedData();
            return true;
            
        }
        catch (Exception $e)
        {
            self::$lastError = $e->getMessage();
            return false;
        }
    }
    
    /**
     * Modern replacement for imap_unsubscribe()
     */
    public static function imap_unsubscribe($stream, $mailbox)
    {
        if (!isset(self::$connections[$stream]['client']))
        {
            return false;
        }
        
        try
        {
            $path = preg_replace('/^\{[^}]+\}/', '', $mailbox);
            self::$connections[$stream]['client']->getConnection()->unsubscribeFolder($path)->validatedData();
            return true;
            
        }
        catch (Exception $e)
        {
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
        if ($personal)
        {
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
        foreach ($addresses as $addr)
        {
            $addr = trim($addr);
            if (preg_match('/^(.+?)\s*<(.+?)>$/', $addr, $matches))
            {
                $personal = trim($matches[1], '"');
                $email = $matches[2];
                if (strpos($email, '@') !== false)
                {
                    list($mailbox, $host) = explode('@', $email, 2);
                }
                else
                {
                    $mailbox = $email;
                    $host = $default_host;
                }
            }
            elseif (strpos($addr, '@') !== false)
            {
                list($mailbox, $host) = explode('@', $addr, 2);
                $personal = '';
            }
            else
            {
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