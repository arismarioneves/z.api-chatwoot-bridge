<?php

namespace WhatsappBridge;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    private static ?MonologLogger $logger = null;

    private static function getInstance(): MonologLogger
    {
        $log_file = 'app';

        if (self::$logger === null) {
            self::$logger = new MonologLogger('whatsapp-bridge');
            $formatter = new LineFormatter(null, null, true, true);
            $handler = new StreamHandler(__DIR__ . '/logs/' . $log_file . '.log', MonologLogger::DEBUG);
            $handler->setFormatter($formatter);
            self::$logger->pushHandler($handler);
        }
        return self::$logger;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $levelConstant = match (strtolower($level)) {
            'debug' => MonologLogger::DEBUG,
            'warning' => MonologLogger::WARNING,
            'error' => MonologLogger::ERROR,
            default => MonologLogger::INFO,
        };

        self::getInstance()->log($levelConstant, $message, $context);
    }
}
