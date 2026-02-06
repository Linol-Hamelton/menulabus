<?php
/**
 * Система очередей для асинхронной обработки (Фаза 2 roadmap)
 * File-based queue с locking для простоты на shared hosting
 */

class Queue {
    private $queueFile;
    
    public function __construct($queueName = 'default') {
        $dir = __DIR__ . '/data/queues';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->queueFile = $dir . '/' . $queueName . '.queue';
    }
    
    public function push($job, $data) {
        $item = [
            'id' => uniqid('job_', true),
            'job' => $job,
            'data' => $data,
            'created_at' => time(),
            'attempts' => 0
        ];
        
        return file_put_contents(
            $this->queueFile, 
            json_encode($item, JSON_UNESCAPED_UNICODE) . PHP_EOL, 
            FILE_APPEND | LOCK_EX
        ) !== false ? $item['id'] : false;
    }
    
    public function pop() {
        if (!file_exists($this->queueFile)) {
            return null;
        }
        
        $fp = fopen($this->queueFile, 'r+');
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return null;
        }
        
        $lines = [];
        $job = null;
        
        while (($line = fgets($fp)) !== false) {
            if ($job === null && trim($line) !== '') {
                $job = json_decode(trim($line), true);
            } else {
                $lines[] = $line;
            }
        }
        
        // Перезаписываем файл без первой задачи
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, implode('', $lines));
        
        flock($fp, LOCK_UN);
        fclose($fp);
        
        return $job;
    }
    
    public function size() {
        if (!file_exists($this->queueFile)) {
            return 0;
        }
        return count(file($this->queueFile));
    }
}

// Worker скрипт (worker.php)
if (php_sapi_name() === 'cli') {
    $queueName = $argv[1] ?? 'default';
    $queue = new Queue($queueName);
    
    while (true) {
        $job = $queue->pop();
        
        if ($job !== null) {
            try {
                switch ($job['job']) {
                    case 'send_email':
                        // mailer.php
                        require_once __DIR__ . '/mailer.php';
                        sendEmail($job['data']['to'], $job['data']['subject'], $job['data']['body']);
                        break;
                    case 'send_verification_email':
                        require_once __DIR__ . '/mailer.php';
                        $mailer = new Mailer();
                        $mailer->sendVerificationEmail(
                            $job['data']['email'],
                            $job['data']['name'],
                            $job['data']['token']
                        );
                        break;
                    case 'send_password_reset_email':
                        require_once __DIR__ . '/mailer.php';
                        $mailer = new Mailer();
                        $mailer->sendPasswordResetEmail(
                            $job['data']['email'],
                            $job['data']['name'],
                            $job['data']['token']
                        );
                        break;
                    case 'clear_cache':
                        require_once __DIR__ . '/clear-cache.php';
                        clearNginxCache();
                        break;
                    default:
                        error_log("Unknown job: " . $job['job']);
                }
                echo "Processed job: " . $job['id'] . "\n";
            } catch (Exception $e) {
                error_log("Job failed: " . $e->getMessage());
            }
        } else {
            sleep(1);
        }
    }
}
?>
