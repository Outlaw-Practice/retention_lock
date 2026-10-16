<?php
// Test-only decorator: attempt real restores after intent commits, before deletion.
$audit = new class($audit, $restoreWorkerTemplate, $owner->getUID(), $trashId, $deletedAt)
    extends Psr\Log\AbstractLogger implements OCA\AdminAudit\IAuditLogger {
    public function __construct(private OCA\AdminAudit\IAuditLogger $delegate,
        private string $template, private string $owner, private int $id, private int $deleted) {}

    public function log($level, string|Stringable $message, array $context = []): void {
        $this->delegate->log($level, $message, $context);
        $fixture = base64_encode(json_encode(['owner' => $this->owner, 'id' => $this->id,
            'deleted' => $this->deleted], JSON_THROW_ON_ERROR));
        $source = str_replace('// FIXTURE', '$fixture = json_decode(base64_decode(\'' . $fixture . '\'), true);', $this->template);
        $process = proc_open(['php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Contender unavailable'); }
        try {
            fwrite($pipes[0], $source);
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 15);
            $result = stream_get_contents($pipes[1]);
            $timedOut = stream_get_meta_data($pipes[1])['timed_out'];
            fclose($pipes[1]);
            if ($timedOut) { proc_terminate($process, 9); }
            $exit = proc_close($process);
            $process = null;
            if ($timedOut || $exit !== 0 || $result !== 'restore-blocked') {
                if (in_array($result, ['failed:bootstrap', 'failed:manager', 'failed:dav', 'failed:bulk', 'failed:expiry'], true)) {
                    $GLOBALS['phase'] = 'contender-' . substr($result, 7);
                }
                throw new RuntimeException('Concurrent restore was not excluded');
            }
        } finally {
            if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
        }
    }
};
