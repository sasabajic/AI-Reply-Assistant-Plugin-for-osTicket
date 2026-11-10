<?php
/**
 * AI Reply Assistant — Logger
 *
 * Central, level-gated logging to the PHP error log.
 * Disabled by default; enable via the plugin's "Log Level" setting
 * (error = errors only, debug = verbose).
 *
 * @package AiReplyAssistant
 */

class AiReplyLogger {

    const OFF   = 0;
    const ERROR = 1;
    const DEBUG = 2;

    private static $level = self::OFF;

    /**
     * Set level from a config value ('off' | 'error' | 'debug').
     * Unknown values disable logging.
     *
     * @param string|null $name
     */
    public static function setLevel($name) {
        $map = array('error' => self::ERROR, 'debug' => self::DEBUG);
        self::$level = isset($map[$name]) ? $map[$name] : self::OFF;
    }

    public static function error($message) {
        self::write(self::ERROR, 'ERROR', $message);
    }

    public static function debug($message) {
        self::write(self::DEBUG, 'DEBUG', $message);
    }

    private static function write($level, $label, $message) {
        if (self::$level >= $level) {
            error_log('[AI Reply Assistant] [' . $label . '] ' . $message);
        }
    }
}
