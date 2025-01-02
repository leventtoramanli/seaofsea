<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LoggerHandler {
    private $logger;

    public function __construct($channel = 'app') {
        $this->logger = new Logger($channel);
        $this->logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/app.log', Logger::DEBUG));
    }

    public function log($level, $message, $context = []) {
        $this->logger->log($level, $message, $context);
    }
}
