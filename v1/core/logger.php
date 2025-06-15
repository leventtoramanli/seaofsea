<?php

class Logger
{
    private const LOG_FILE = __DIR__ . '/../logs/v1_error.log';
    private static ?Logger $instance = null;

    private bool $logAsJson = true;
    private string $logFile;

    private function __construct()
    {
        $this->logFile = self::LOG_FILE;
    }

    public static function getInstance(): Logger
    {
        if (!self::$instance) {
            self::$instance = new Logger();
        }
        return self::$instance;
    }

    // İsteğe bağlı: Log JSON formatta mı yazılsın?
    public function useJson(bool $flag = true): void
    {
        $this->logAsJson = $flag;
    }

    // İsteğe bağlı: Log dosya yolu değiştirme
    public function setLogFile(string $path): void
    {
        $this->logFile = $path;
    }

    // Statik kısayollar
    public static function info($message, array $context = []): void
    {
        self::getInstance()->writeLog("INFO", $message, $context);
    }

    public static function warning($message, array $context = []): void
    {
        self::getInstance()->writeLog("WARNING", $message, $context);
    }

    public static function error($message, array $context = []): void
    {
        self::getInstance()->writeLog("ERROR", $message, $context);
    }

    // Gerçek log yazımı
    private function writeLog(string $level, $message, array $context = []): void
    {
        $dir = dirname($this->logFile);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        $timestamp = date('c'); // ISO 8601

        if ($this->logAsJson) {
            $entry = [
                'timestamp' => $timestamp,
                'level' => $level,
                'message' => $this->normalizeMessage($message),
                'context' => $context
            ];
            $logMessage = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            $text = $this->normalizeMessage($message);
            $ctx = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
            $logMessage = "[$timestamp] [$level] $text $ctx\n";
        }

        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    private function normalizeMessage($message): string
    {
        if (is_array($message) || is_object($message)) {
            return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } elseif ($message === null) {
            return 'NULL';
        } elseif (!is_string($message)) {
            return strval($message);
        }
        return $message;
    }
}
