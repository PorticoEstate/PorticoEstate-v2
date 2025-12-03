<?php

namespace App\WebSocket;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Simple file-based PSR-3 logger implementation for WebSocket server
 */
class SimpleLogger extends AbstractLogger
{
    /**
     * @var string Path to the log file
     */
    protected $logFile;
    
    /**
     * @var string Minimum log level to record
     */
    protected $minLevel;
    
    /**
     * Level priorities (lower = more severe)
     */
    protected const LEVEL_PRIORITIES = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT     => 1,
        LogLevel::CRITICAL  => 2,
        LogLevel::ERROR     => 3,
        LogLevel::WARNING   => 4,
        LogLevel::NOTICE    => 5,
        LogLevel::INFO      => 6,
        LogLevel::DEBUG     => 7,
    ];
    
    /**
     * Constructor
     * 
     * @param string $logFile Path to log file
     * @param string $minLevel Minimum log level to record
     */
    public function __construct(string $logFile = '/var/log/apache2/websocket.log', string $minLevel = LogLevel::INFO)
    {
        $this->logFile = $logFile;
        $this->minLevel = $minLevel;
    }
    
    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = array()): void
    {
        // Check if this level should be logged
        if (!$this->shouldLog($level)) {
            return;
        }
        
        // Format the log message
        $timestamp = date('Y-m-d H:i:s');
        $formattedMessage = sprintf(
            "[%s] [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $this->interpolate($message, $context),
            !empty($context) ? json_encode($context) : ''
        );
        
        // Write to log file
        error_log($formattedMessage, 3, $this->logFile);
    }
    
    /**
     * Interpolates context values into the message placeholders
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    protected function interpolate(string $message, array $context = []): string
    {
        // Build a replacement array with braces around the context keys
        $replace = [];
        foreach ($context as $key => $val) {
            // Skip non-string context values
            if (!is_string($val) && !method_exists($val, '__toString')) {
                continue;
            }
            
            $replace['{' . $key . '}'] = $val;
        }
        
        // Interpolate replacement values into the message and return
        return strtr($message, $replace);
    }
    
    /**
     * Determine if this log level should be recorded
     * 
     * @param string $level
     * @return bool
     */
    protected function shouldLog(string $level): bool
    {
        return static::LEVEL_PRIORITIES[$level] <= static::LEVEL_PRIORITIES[$this->minLevel];
    }
}