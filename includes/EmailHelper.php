<?php

/**
 * Thin wrapper around PHP's ext-imap for reading the office@karimunjawaexplore.com
 * mailbox (Menu "Email Kantor").
 */
class EmailHelper
{
    const SETTINGS_PREFIX = 'email_imap_';

    /** Folders exposed in the "Email Kantor" UI, matching this mail server's real folder names. */
    const FOLDERS = [
        'INBOX' => 'Inbox',
        'INBOX.Sent' => 'Terkirim',
        'INBOX.Drafts' => 'Draft',
        'INBOX.Trash' => 'Sampah',
        'INBOX.Junk' => 'Spam',
    ];

    /** @var resource|\IMAP\Connection|null */
    private $conn = null;
    private string $mailbox;
    private string $folder;

    /**
     * @param array{host:string,port:int,encryption:string,user:string,pass:string}|null $config
     * @param string $folder One of the keys in self::FOLDERS.
     */
    public function __construct(?array $config = null, string $folder = 'INBOX')
    {
        $this->folder = array_key_exists($folder, self::FOLDERS) ? $folder : 'INBOX';

        if (!extension_loaded('imap')) {
            throw new RuntimeException('Ekstensi PHP IMAP belum aktif di server ini. Hubungi developer untuk mengaktifkan ext-imap.');
        }

        if ($config === null) {
            throw new RuntimeException('Pengaturan email belum diisi. Buka menu "Pengaturan Email" untuk mengisi host, user dan password.');
        }

        if (empty($config['host']) || empty($config['user']) || empty($config['pass'])) {
            throw new RuntimeException('Pengaturan email belum lengkap. Buka menu "Pengaturan Email" untuk mengisi host, user dan password.');
        }

        $encryption = $config['encryption'] ?? 'ssl';
        $port = (int)($config['port'] ?? 993);
        $flag = $encryption === 'tls' ? '/imap/tls' : '/imap/ssl';

        $this->mailbox = '{' . $config['host'] . ':' . $port . $flag . '/novalidate-cert}' . $this->folder;

        @imap_timeout(IMAP_OPENTIMEOUT, 6);
        @imap_timeout(IMAP_READTIMEOUT, 12);
        $conn = @imap_open($this->mailbox, $config['user'], $config['pass']);
        if ($conn === false) {
            throw new RuntimeException('Gagal konek ke email: ' . imap_last_error());
        }
        $this->conn = $conn;
    }

    /**
     * Append a raw RFC822 message into a folder (used to save a copy of sent mail into
     * INBOX.Sent, since SMTP sending alone does not file a copy anywhere).
     */
    public static function appendMessageToFolder(array $config, string $folder, string $rawMessage): void
    {
        $encryption = $config['encryption'] ?? 'ssl';
        $port = (int)($config['port'] ?? 993);
        $flag = $encryption === 'tls' ? '/imap/tls' : '/imap/ssl';
        $mailbox = '{' . $config['host'] . ':' . $port . $flag . '/novalidate-cert}' . $folder;

        $conn = @imap_open($mailbox, $config['user'], $config['pass']);
        if ($conn === false) {
            return;
        }
        @imap_append($conn, $mailbox, str_replace("\n", "\r\n", str_replace("\r\n", "\n", $rawMessage)), '\\Seen');
        imap_close($conn);
    }

    /**
     * Load saved IMAP settings from the `settings` table.
     * Returns null if nothing has been saved yet.
     */
    public static function loadDbSettings(Database $db): ?array
    {
        $host = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'email_imap_host'");
        if (!$host || $host['setting_value'] === '') {
            return null;
        }

        $get = function (string $key) use ($db) {
            $row = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :k", ['k' => $key]);
            return $row['setting_value'] ?? '';
        };

        $encPass = $get(self::SETTINGS_PREFIX . 'pass');

        return [
            'host' => $get(self::SETTINGS_PREFIX . 'host'),
            'port' => (int)($get(self::SETTINGS_PREFIX . 'port') ?: 993),
            'encryption' => $get(self::SETTINGS_PREFIX . 'encryption') ?: 'ssl',
            'user' => $get(self::SETTINGS_PREFIX . 'user'),
            'pass' => $encPass !== '' ? self::decryptSecret($encPass) : '',
            'smtp_port' => (int)($get(self::SETTINGS_PREFIX . 'smtp_port') ?: 465),
            'smtp_encryption' => $get(self::SETTINGS_PREFIX . 'smtp_encryption') ?: 'ssl',
        ];
    }

    /**
     * Save IMAP settings into the `settings` table (password encrypted at rest).
     */
    public static function saveDbSettings(Database $db, array $data): void
    {
        $values = [
            self::SETTINGS_PREFIX . 'host' => (string)$data['host'],
            self::SETTINGS_PREFIX . 'port' => (string)(int)$data['port'],
            self::SETTINGS_PREFIX . 'encryption' => (string)$data['encryption'],
            self::SETTINGS_PREFIX . 'user' => (string)$data['user'],
            self::SETTINGS_PREFIX . 'smtp_port' => (string)(int)($data['smtp_port'] ?? 465),
            self::SETTINGS_PREFIX . 'smtp_encryption' => (string)($data['smtp_encryption'] ?? 'ssl'),
        ];
        if (!empty($data['pass'])) {
            $values[self::SETTINGS_PREFIX . 'pass'] = self::encryptSecret((string)$data['pass']);
        }

        foreach ($values as $key => $value) {
            $db->query(
                "INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE setting_value = :v2, updated_at = NOW()",
                ['k' => $key, 'v' => $value, 'v2' => $value]
            );
        }
    }

    private static function secretKey(): string
    {
        $seed = defined('DB_PASS') ? DB_PASS : 'karimunjawa-explore-email-secret';
        return hash('sha256', $seed . '|karimunjawa-explore-email', true);
    }

    private static function encryptSecret(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::secretKey(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    private static function decryptSecret(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::secretKey(), OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }

    /**
     * Resolve the active IMAP config from the `settings` table (input via "Pengaturan Email").
     */
    public static function resolveConfig(Database $db): ?array
    {
        return self::loadDbSettings($db);
    }

    public function __destruct()
    {
        if ($this->conn) {
            @imap_close($this->conn);
        }
    }

    /**
     * Return the newest messages first as an array of overview objects
     * (subject, from, date, seen, uid, msgno, size).
     */
    public function listMessages(int $limit = 30, int $offset = 0): array
    {
        $total = imap_num_msg($this->conn);
        if ($total <= 0) {
            return ['total' => 0, 'messages' => []];
        }

        $start = max(1, $total - $offset - $limit + 1);
        $end = $total - $offset;
        if ($end < 1) {
            return ['total' => $total, 'messages' => []];
        }

        $overview = imap_fetch_overview($this->conn, $start . ':' . $end, 0);
        $messages = [];
        foreach ($overview as $item) {
            $messages[] = [
                'uid' => (int)($item->uid ?? 0),
                'msgno' => (int)($item->msgno ?? 0),
                'subject' => isset($item->subject) ? $this->decodeMimeStr($item->subject) : '(Tanpa Subjek)',
                'from' => isset($item->from) ? $this->decodeMimeStr($item->from) : '',
                'date' => isset($item->date) ? $item->date : '',
                'seen' => !empty($item->seen),
                'size' => (int)($item->size ?? 0),
            ];
        }
        // Newest first
        usort($messages, fn($a, $b) => $b['msgno'] <=> $a['msgno']);

        return ['total' => $total, 'messages' => $messages];
    }

    public function countUnread(): int
    {
        $unseen = @imap_search($this->conn, 'UNSEEN');
        return $unseen ? count($unseen) : 0;
    }

    public function getMessageByUid(int $uid): array
    {
        $msgno = imap_msgno($this->conn, $uid);
        if (!$msgno) {
            throw new RuntimeException('Email tidak ditemukan.');
        }

        $overview = imap_fetch_overview($this->conn, (string)$msgno, 0);
        $header = $overview[0] ?? null;

        $structure = imap_fetchstructure($this->conn, $msgno);
        $body = $this->getBody($msgno, $structure);

        // Mark as seen
        @imap_setflag_full($this->conn, (string)$msgno, '\\Seen');

        return [
            'uid' => $uid,
            'subject' => $header && isset($header->subject) ? $this->decodeMimeStr($header->subject) : '(Tanpa Subjek)',
            'from' => $header && isset($header->from) ? $this->decodeMimeStr($header->from) : '',
            'to' => $header && isset($header->to) ? $this->decodeMimeStr($header->to) : '',
            'date' => $header && isset($header->date) ? $header->date : '',
            'body_html' => $body['html'],
            'body_plain' => $body['plain'],
        ];
    }

    public function deleteMessage(int $uid): void
    {
        $msgno = imap_msgno($this->conn, $uid);
        if (!$msgno) {
            throw new RuntimeException('Email tidak ditemukan.');
        }

        if ($this->folder === 'INBOX.Trash') {
            // Already in Trash: this is a permanent delete.
            imap_delete($this->conn, (string)$msgno);
        } else {
            // Move to Trash instead of hard-deleting, like a normal mail client.
            imap_mail_move($this->conn, (string)$msgno, 'INBOX.Trash');
        }
        imap_expunge($this->conn);
    }

    private function getBody($msgno, $structure): array
    {
        $html = '';
        $plain = '';

        if (!isset($structure->parts) || !is_array($structure->parts)) {
            // Single-part message
            $data = imap_body($this->conn, $msgno);
            if ((int)$structure->encoding === 3) {
                $data = base64_decode($data);
            } elseif ((int)$structure->encoding === 4) {
                $data = quoted_printable_decode($data);
            }
            if (strtoupper($structure->subtype ?? '') === 'HTML') {
                $html = $data;
            } else {
                $plain = $data;
            }
            return ['html' => $html, 'plain' => $plain];
        }

        $this->walkParts($msgno, $structure->parts, '', $html, $plain);
        return ['html' => $html, 'plain' => $plain];
    }

    private function walkParts($msgno, array $parts, string $prefix, string &$html, string &$plain): void
    {
        foreach ($parts as $index => $part) {
            $partNum = $prefix . ($index + 1);
            if (isset($part->parts) && is_array($part->parts) && (int)($part->ifsubtype ?? 0) && strtoupper($part->subtype) !== 'ALTERNATIVE') {
                $this->walkParts($msgno, $part->parts, $partNum . '.', $html, $plain);
                continue;
            }
            if (isset($part->parts) && is_array($part->parts)) {
                $this->walkParts($msgno, $part->parts, $partNum . '.', $html, $plain);
                continue;
            }

            $subtype = strtoupper($part->subtype ?? '');
            if ($subtype !== 'HTML' && $subtype !== 'PLAIN') {
                continue;
            }

            $data = imap_fetchbody($this->conn, $msgno, $partNum);
            if ((int)$part->encoding === 3) {
                $data = base64_decode($data);
            } elseif ((int)$part->encoding === 4) {
                $data = quoted_printable_decode($data);
            }

            if ($subtype === 'HTML' && $html === '') {
                $html = $data;
            } elseif ($subtype === 'PLAIN' && $plain === '') {
                $plain = $data;
            }
        }
    }

    private function decodeMimeStr(string $str): string
    {
        $decoded = @imap_mime_header_decode($str);
        if (!$decoded) {
            return $str;
        }
        $out = '';
        foreach ($decoded as $part) {
            $charset = $part->charset ?? 'default';
            $text = $part->text ?? '';
            if (strtolower($charset) !== 'default' && strtolower($charset) !== 'utf-8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                $text = $converted !== false ? $converted : $text;
            }
            $out .= $text;
        }
        return $out;
    }
}
