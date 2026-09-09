<?php

/**
 * Minimal dependency-free SMTP client (no PHPMailer/composer needed) for sending
 * mail from the "Email Kantor" module. Supports implicit SSL (port 465, cPanel default).
 */
class SmtpMailer
{
    private string $host;
    private int $port;
    private string $encryption;
    private string $user;
    private string $pass;

    public function __construct(string $host, int $port, string $encryption, string $user, string $pass)
    {
        $this->host = $host;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->user = $user;
        $this->pass = $pass;
    }

    /**
     * @param string $to
     * @param string $subject
     * @param string $bodyHtml Rendered as the email body (converted from plain text with nl2br if needed).
     * @param string $fromName Display name used in the From header.
     * @param array<int,array{name:string,type:string,data:string}> $attachments Raw binary content per file.
     * @return string The raw RFC822 message that was sent (for filing a copy into the Sent folder).
     */
    public function send(string $to, string $subject, string $bodyHtml, string $fromName = 'Karimunjawa Explore', array $attachments = []): string
    {
        $transport = $this->encryption === 'ssl' ? 'ssl://' : '';
        $sock = @fsockopen($transport . $this->host, $this->port, $errno, $errstr, 15);
        if (!$sock) {
            throw new RuntimeException("Gagal konek ke SMTP {$this->host}:{$this->port} - {$errstr}");
        }
        stream_set_timeout($sock, 15);

        $this->expect($sock, 220);
        $this->command($sock, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);

        if ($this->encryption === 'tls') {
            $this->command($sock, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Gagal mengaktifkan TLS ke server SMTP.');
            }
            $this->command($sock, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
        }

        $this->command($sock, 'AUTH LOGIN', 334);
        $this->command($sock, base64_encode($this->user), 334);
        $this->command($sock, base64_encode($this->pass), 235);

        $this->command($sock, 'MAIL FROM:<' . $this->user . '>', 250);
        $this->command($sock, 'RCPT TO:<' . $to . '>', [250, 251]);
        $this->command($sock, 'DATA', 354);

        $headers = [];
        $headers[] = 'From: ' . $this->encodeHeader($fromName) . ' <' . $this->user . '>';
        $headers[] = 'Reply-To: ' . $this->user;
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . $this->encodeHeader($subject);
        $headers[] = 'Date: ' . date('r');
        $domain = substr(strrchr($this->user, '@'), 1) ?: 'localhost';
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        $headers[] = 'MIME-Version: 1.0';

        if (empty($attachments)) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($bodyHtml) . "\r\n.";
        } else {
            $boundary = 'b' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            $parts = "--{$boundary}\r\n";
            $parts .= "Content-Type: text/html; charset=UTF-8\r\n";
            $parts .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $parts .= $bodyHtml . "\r\n";

            foreach ($attachments as $file) {
                $parts .= "--{$boundary}\r\n";
                $parts .= 'Content-Type: ' . ($file['type'] ?: 'application/octet-stream') . '; name="' . $this->encodeHeader($file['name']) . "\"\r\n";
                $parts .= 'Content-Disposition: attachment; filename="' . $this->encodeHeader($file['name']) . "\"\r\n";
                $parts .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $parts .= chunk_split(base64_encode($file['data']));
            }
            $parts .= "--{$boundary}--";

            $data = implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($parts) . "\r\n.";
        }

        $rawMessage = implode("\r\n", $headers) . "\r\n\r\n" . ($attachments ? $parts : $bodyHtml);

        fwrite($sock, $data . "\r\n");
        $this->expect($sock, 250);

        fwrite($sock, "QUIT\r\n");
        fclose($sock);

        return $rawMessage;
    }

    private function dotStuff(string $body): string
    {
        return preg_replace('/^\./m', '..', $body);
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /** @param resource $sock */
    private function command($sock, string $line, $expectCode): void
    {
        fwrite($sock, $line . "\r\n");
        $this->expect($sock, $expectCode);
    }

    /** @param resource $sock */
    private function expect($sock, $expectCode): void
    {
        $codes = is_array($expectCode) ? $expectCode : [$expectCode];
        $response = '';
        while ($line = fgets($sock, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            $target = "{$this->host}:{$this->port} ({$this->encryption})";
            if (stripos($response, 'dovecot') !== false || stripos($response, 'IMAP4') !== false) {
                throw new RuntimeException("Port/Enkripsi SMTP salah - {$target} membalas sebagai IMAP (Dovecot), bukan SMTP. Cek \"Outgoing Server Port\" di Pengaturan Email (gunakan 465+SSL atau 587+TLS), lalu klik Simpan Pengaturan lagi.");
            }
            throw new RuntimeException("SMTP error dari {$target}: " . trim($response));
        }
    }
}
