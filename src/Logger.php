<?php

namespace WhatsappBridge;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;

class Logger
{
    private static $logger = null;

    public static function getInstance()
    {
        if (self::$logger === null) {
            self::$logger = new MonologLogger('whatsapp-bridge');
            self::$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', MonologLogger::DEBUG));
        }
        return self::$logger;
    }

    public static function log($level, $message, array $context = [])
    {
        self::getInstance()->log($level, $message, $context);
    }
}
