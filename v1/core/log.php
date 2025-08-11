<?php
class Logger
{
    private const LOG_FILE = __DIR__ . '/../logs/v1_error.log';
    private static ?Logger $_instance = null;

    private bool $logAsJson = true;
    private ?string $logFile = null;
    public static function getInstance(): Logger {
        if (!self::$_instance) {
            self::$_instance = new Logger();
        }
        return self::$_instance;
    }
    public function useJson(bool $flag = true): void
    {
        $this->logAsJson = $flag;
    }

    public function setLogFile(string $path): void
    {
        $this->logFile = $path;
    }

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

    public static function exception(Throwable $e, ?string $context = null): void
    {
        $entry = [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];

        if ($context) {
            $entry['context'] = $context;
        }

        self::getInstance()->writeLog("EXCEPTION", $entry);
    }

    public static function externalException(array $payload): void
    {
        self::getInstance()->writeLog("EXTERNAL", $payload);
    }

    public static function getRecentLogs(int $count = 50): array
    {
        $instance = self::getInstance();
        if (!$instance->logFile) {
            $instance->logFile = self::LOG_FILE;
        }

        if (!file_exists($instance->logFile)) {
            return [];
        }

        $lines = @file($instance->logFile);
        $logs = array_slice($lines, -$count);
        return array_filter(array_map(fn ($l) => json_decode($l, true), $logs));
    }

    private function writeLog(string $level, $message, array $context = []): void
    {
        if (!$this->logFile) {
            $this->logFile = self::LOG_FILE;
            //return;
        }

        $dir = dirname($this->logFile);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        $timestamp = date('c');

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
