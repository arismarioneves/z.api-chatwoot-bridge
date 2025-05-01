<?php

namespace WhatsappBridge;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    private static ?MonologLogger $logger = null;

    public static function getInstance(): MonologLogger
    {
        if (self::$logger === null) {
            self::$logger = new MonologLogger('whatsapp-bridge');
            // Formato do log: [timestamp] channel.LEVEL: message context extra
            $formatter = new LineFormatter(null, null, true, true); // Permite newlines, ignora context/extra vazios
            $logPath = BASE_PATH . '/logs/app.log'; // Usa BASE_PATH definido em webhook.php
            $handler = new StreamHandler($logPath, MonologLogger::DEBUG); // Loga tudo a partir de DEBUG
            $handler->setFormatter($formatter);
            self::$logger->pushHandler($handler);
        }
        return self::$logger;
    }

    /**
     * Log a message using Monolog.
     *
     * @param string $level   The log level (e.g., 'info', 'error', 'debug')
     * @param string $message The message to log
     * @param array  $context Optional context data
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        // Mapeia string de nível para constante Monolog (se necessário)
        $levelConstant = match (strtolower($level)) {
            'debug' => MonologLogger::DEBUG,
            'info' => MonologLogger::INFO,
            'notice' => MonologLogger::NOTICE,
            'warning', 'warn' => MonologLogger::WARNING,
            'error', 'err' => MonologLogger::ERROR,
            'critical', 'crit' => MonologLogger::CRITICAL,
            'alert' => MonologLogger::ALERT,
            'emergency', 'emerg' => MonologLogger::EMERGENCY,
            default => MonologLogger::INFO, // Default para INFO se nível inválido
        };

        self::getInstance()->log($levelConstant, $message, $context);
    }
}
