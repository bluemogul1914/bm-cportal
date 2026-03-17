<?php
class Logger {

    private string $logFile;

    public function __construct(string $logDir) {
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = rtrim($logDir, '/') . '/frontier-asr.log';
    }

    public function info(string $msg): void  { $this->write('INFO',  $msg); }
    public function error(string $msg): void { $this->write('ERROR', $msg); }
    public function debug(string $msg): void { $this->write('DEBUG', $msg); }

    private function write(string $level, string $msg): void {
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public function tail(int $lines = 100): array {
        if (!file_exists($this->logFile)) return [];
        $all = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice(array_reverse($all), 0, $lines);
    }
}
