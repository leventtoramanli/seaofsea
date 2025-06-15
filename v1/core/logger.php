<?php

class Logger
{
    private const LOG_FILE = __DIR__ . '/../logs/v1_error.log';
    private static ?Logger $instance = null;

    private function __construct() {
        // constructor gizli, dışarıdan new Logger() engellenir
    }

    public static function getInstance(): Logger
    {
        if (!self::$instance) {
            self::$instance = new Logger();
        }
        return self::$instance;
    }

    // Static kullanımlar (Logger::info(...)) bu fonksiyonlarla çalışır
    public static function info($message): void
    {
        self::getInstance()->writeLog("INFO", $message);
    }

    public static function warning($message): void
    {
        self::getInstance()->writeLog("WARNING", $message);
    }

    public static function error($message): void
    {
        self::getInstance()->writeLog("ERROR", $message);
    }

    // Gerçek log yazımı burada yapılır (instance içinde)
    private function writeLog(string $level, $message): void
    {
        if (!file_exists(dirname(self::LOG_FILE))) {
            mkdir(dirname(self::LOG_FILE), 0777, true);
        }

        if (is_array($message) || is_object($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } elseif ($message === null) {
            $message = 'NULL';
        } elseif (!is_string($message)) {
            $message = strval($message);
        }

        $date = date('Y-m-d H:i:s');
        $logMessage = "[$date] [$level] $message\n";
        file_put_contents(self::LOG_FILE, $logMessage, FILE_APPEND);
    }
}
