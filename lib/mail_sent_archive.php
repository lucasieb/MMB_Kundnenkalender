<?php
declare(strict_types=1);

/** Archiving never submits a message to SMTP. Keep pending MIME outside the web root. */
interface MMBSentMailbox
{
    public function contains(string $messageId): bool;
    public function append(string $mime): void;
    public function close(): void;
}

final class MMBImapSentMailbox implements MMBSentMailbox
{
    private $connection;
    private string $mailbox;

    public function __construct(array $config)
    {
        if (!function_exists('imap_open')) throw new RuntimeException('IMAP extension unavailable');
        $host = (string)($config['imap_host'] ?? 'imap.ionos.de');
        $port = (int)($config['imap_port'] ?? 993);
        if (!preg_match('/^[a-z0-9.-]+$/iD', $host) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid IMAP server');
        }
        // TLS certificate validation stays enabled. Never log protocol/authentication errors.
        imap_timeout(IMAP_OPENTIMEOUT, 8);
        imap_timeout(IMAP_READTIMEOUT, 8);
        imap_timeout(IMAP_WRITETIMEOUT, 8);
        $root = '{'.$host.':'.$port.'/imap/ssl}';
        $this->connection = @imap_open($root, (string)($config['imap_username'] ?? $config['username']), (string)($config['imap_password'] ?? $config['password']), OP_HALFOPEN, 1);
        $this->clearErrors();
        if (!$this->connection) throw new RuntimeException('IMAP connection unavailable');
        try {
            $folder = (string)($config['imap_sent_folder'] ?? 'Gesendete Objekte');
            $folders = @imap_getmailboxes($this->connection, $root, '*');
            $this->clearErrors();
            $this->mailbox = $root.$folder;
            $found = false;
            foreach ($folders ?: [] as $candidate) {
                if ($candidate->name === $this->mailbox && !($candidate->attributes & LATT_NOSELECT)) $found = true;
            }
            if (!$found) throw new RuntimeException('Configured Sent folder unavailable');
            // Read-only selection prevents flag changes to existing messages.
            $ok = @imap_reopen($this->connection, $this->mailbox, OP_READONLY, 1);
            $this->clearErrors();
            if (!$ok) throw new RuntimeException('Sent folder unavailable');
        } catch (Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    private function clearErrors(): void
    {
        imap_errors();
        imap_alerts();
    }

    public function contains(string $messageId): bool
    {
        // Message IDs are validated before queueing; no IMAP search injection.
        $this->clearErrors();
        // PHP c-client does not support the generic HEADER criterion. TEXT narrows
        // candidates; overview headers then verify the exact Message-ID (not forwarded body text).
        $found = @imap_search($this->connection, 'TEXT "'.$messageId.'"', SE_UID);
        $errors = imap_errors();
        imap_alerts();
        if ($errors) throw new RuntimeException('Sent lookup unavailable');
        foreach (array_chunk(is_array($found) ? $found : [], 100) as $uids) {
            $overview = @imap_fetch_overview($this->connection, implode(',', $uids), FT_UID);
            $errors = imap_errors();
            imap_alerts();
            if ($overview === false || $errors) throw new RuntimeException('Sent headers unavailable');
            foreach ($overview as $message) {
                if (trim((string)($message->message_id ?? '')) === $messageId) return true;
            }
        }
        return false;
    }

    public function append(string $mime): void
    {
        $date = null;
        if (preg_match('/^Date: ([^\r\n]+)\r?$/mi', $mime, $match)) {
            $timestamp = strtotime($match[1]);
            if ($timestamp !== false) $date = date('d-M-Y H:i:s O', $timestamp);
        }
        $ok = @imap_append($this->connection, $this->mailbox, $mime, '\\Seen', $date);
        $this->clearErrors();
        if (!$ok) throw new RuntimeException('Sent append unavailable');
    }

    public function close(): void
    {
        if ($this->connection) {
            @imap_close($this->connection);
            $this->connection = null;
            $this->clearErrors();
        }
    }
}

function mmb_sent_message_id(string $mime): string
{
    if (!preg_match('/^Message-ID: (<[a-z0-9.!#$%&\x27*+\/=?^_`{|}~@-]+>)\r?$/mi', $mime, $match)) {
        throw new RuntimeException('Missing or unsafe Message-ID');
    }
    return $match[1];
}

function mmb_sent_queue_directory(array $config): string
{
    $directory = (string)($config['sent_queue_dir'] ?? dirname(__DIR__, 2).'/mail-sent-queue');
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Sent queue unavailable');
    }
    $resolved = realpath($directory);
    $webRoot = realpath(dirname(__DIR__));
    if ($resolved === false || $resolved === $webRoot || str_starts_with($resolved, $webRoot.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Sent queue must be outside the web root');
    }
    @chmod($directory, 0700);
    return $directory;
}

/** Queue only AFTER SMTP acceptance. A retry archives the MIME; it never re-sends it. */
function mmb_archive_sent_message(string $mime, array $config, ?callable $factory = null): array
{
    if (($config['sent_archive_enabled'] ?? true) !== true) return ['disabled'=>true, 'archived'=>0, 'pending'=>0];
    $id = hash('sha256', mmb_sent_message_id($mime));
    $directory = mmb_sent_queue_directory($config);
    $temporary = tempnam($directory, '.pending-');
    if ($temporary === false) throw new RuntimeException('Sent queue write unavailable');
    try {
        @chmod($temporary, 0600);
        if (file_put_contents($temporary, $mime) !== strlen($mime) || !rename($temporary, $directory.'/'.$id.'.eml')) {
            throw new RuntimeException('Sent queue write failed');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
    return mmb_flush_sent_archive($config, 1, $factory, $id);
}

/** Shared lock and Message-ID lookup also handle a disconnect after a successful APPEND. */
function mmb_flush_sent_archive(array $config, int $limit = 20, ?callable $factory = null, ?string $onlyId = null): array
{
    $report = ['archived'=>0, 'pending'=>0, 'failed'=>false];
    if (($config['sent_archive_enabled'] ?? true) !== true) return $report + ['disabled'=>true];
    $directory = mmb_sent_queue_directory($config);
    $files = glob($directory.'/*.eml') ?: [];
    $report['pending'] = count($files);
    if (!$files) return $report;
    $lock = fopen($directory.'/.archive.lock', 'c');
    if (!$lock) throw new RuntimeException('Sent queue lock unavailable');
    @chmod($directory.'/.archive.lock', 0600);
    $mailbox = null;
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) return $report;
        $processed = 0;
        foreach ($files as $file) {
            if ($onlyId !== null && basename($file) !== $onlyId.'.eml') continue;
            if ($processed++ >= $limit) break;
            if (!is_file($file)) continue;
            try {
                $mime = file_get_contents($file);
                if ($mime === false) throw new RuntimeException('Sent queue read failed');
                $messageId = mmb_sent_message_id($mime);
                $mailbox ??= $factory ? $factory($config) : new MMBImapSentMailbox($config);
                if (!$mailbox->contains($messageId)) $mailbox->append($mime);
                if (!unlink($file)) throw new RuntimeException('Sent queue cleanup failed');
                $report['archived']++;
            } catch (Throwable $e) {
                $report['failed'] = true;
                error_log('Sent archive pending; SMTP delivery is unaffected.');
                break;
            }
        }
        $report['pending'] = count(glob($directory.'/*.eml') ?: []);
    } finally {
        if ($mailbox !== null) $mailbox->close();
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $report;
}
