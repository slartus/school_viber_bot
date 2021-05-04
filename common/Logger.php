<?php

class Logger
{
    private const LOG_LEVEL_DEBUG = 0;
    private const LOG_LEVEL_INFO = 1;
    private const LOG_LEVEL_WARNING = 2;
    private const LOG_LEVEL_ERROR = 3;
    private const LOG_LEVEL_SYSTEM = 4;
    private const LOG_LEVEL = 1; // минимальный уровень логирования, чем выше, тем меньше логов

    private static function log($text, $level = self::LOG_LEVEL_INFO)
    {
        if (self::LOG_LEVEL > $level) {
            return;
        }

        $levelText = "info";

        switch ($level) {
            case self::LOG_LEVEL_INFO:
                $levelText = "info";
                break;
            case self::LOG_LEVEL_WARNING:
                $levelText = "warning";
                break;
            case self::LOG_LEVEL_ERROR:
                $levelText = "error";
                break;
            case self::LOG_LEVEL_DEBUG:
                $levelText = "debug";
                break;
            case self::LOG_LEVEL_SYSTEM:
                $levelText = "system";
                break;
        }

        $log = '';

        $log .= '[' . date('D M d H:i:s Y', time()) . '] ';
        $log .= $levelText . " ";
        $log .= $text;
        $log .= "\n";

        Logger::write("viberbot.log", $log);
        if ($level == self::LOG_LEVEL_ERROR) {
            Logger::write("viberbot_errors.log", $log);
        }
        if ($level == self::LOG_LEVEL_SYSTEM) {
            Logger::write("viberbot_system.log", $log);
        }
    }

    private static function write($fileName, $text)
    {
        $fp = fopen(dirname(__DIR__) . "/logs/$fileName", 'a+');
        fwrite($fp, $text);
        fclose($fp);
    }

    static function logInfo($text)
    {
        Logger::log($text, self::LOG_LEVEL_INFO);
    }

    static function logDebug($text)
    {
        Logger::log($text, self::LOG_LEVEL_DEBUG);
    }

    static function logWarning($text)
    {
        Logger::log($text, self::LOG_LEVEL_WARNING);
    }

    static function logError($text)
    {
        Logger::log($text, self::LOG_LEVEL_ERROR);
    }

    static function logSystem($text)
    {
        Logger::log($text, self::LOG_LEVEL_SYSTEM);
    }

    static function logException(Throwable $ex)
    {
        $message = $ex->getMessage();
        $file = $ex->getFile();
        $line = $ex->getLine();
        $trace = $ex->getTraceAsString();
        Logger::log("$message $file:$line\n$trace", self::LOG_LEVEL_ERROR);
    }
}