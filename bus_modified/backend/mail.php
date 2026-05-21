<?php

function getMailConfig() {
    return [
        'host' => getenv('SMTP_HOST') ?: ini_get('SMTP') ?: '127.0.0.1',
        'port' => getenv('SMTP_PORT') ?: 25,
        'username' => getenv('SMTP_USERNAME') ?: '',
        'password' => getenv('SMTP_PASSWORD') ?: '',
        'secure' => strtolower(getenv('SMTP_SECURE') ?: ''),
        'from_email' => getenv('MAIL_FROM') ?: 'noreply@bus-system.com',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'BUS System',
        'reply_to' => getenv('MAIL_REPLY_TO') ?: 'support@bus-system.com',
        'log_path' => __DIR__ . '/email_errors.log',
    ];
}

function sendEmail($to, $subject, $message) {
    $config = getMailConfig();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        logEmailError($to, $subject, $message, 'Adresse email invalide');
        return false;
    }

    $success = false;
    if ($config['host'] && $config['port']) {
        $success = sendEmailViaSmtp($to, $subject, $message, $config);
    }

    if (!$success) {
        // If SMTP fails, fall back to PHP mail() when available.
        ini_set('SMTP', $config['host']);
        ini_set('smtp_port', $config['port']);
        ini_set('sendmail_from', $config['from_email']);

        $headers = [];
        $headers[] = "From: {$config['from_name']} <{$config['from_email']}>";
        $headers[] = "Reply-To: {$config['reply_to']}";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: text/html; charset=UTF-8";
        $headers[] = 'X-Mailer: PHP/' . phpversion();
        $headersString = implode("\r\n", $headers);

        $success = @mail($to, $subject, $message, $headersString);
        if (!$success) {
            $error = error_get_last();
            $reason = 'Échec de l’envoi';
            if ($error && isset($error['message'])) {
                $reason .= ': ' . $error['message'];
            }
            logEmailError($to, $subject, $message, $reason);
        }
    }

    return $success;
}

function sendEmailViaSmtp($to, $subject, $message, $config) {
    $host = $config['host'];
    $port = intval($config['port']) ?: 25;
    $secure = $config['secure'];
    $timeout = 3;

    if (!$host || !$port) return false;

    $fp = @fsockopen($host, $port, $errno, $errstr, 1);
    if (!$fp) {
        logEmailError($to, $subject, $message, "Hôte SMTP $host:$port injoignable: $errstr ($errno)");
        return false;
    }
    fclose($fp);
    $timeout = 5;

    $socket = @fsockopen(($secure === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        logEmailError($to, $subject, $message, "Connexion SMTP impossible: $errstr ($errno)");
        return false;
    }

    stream_set_timeout($socket, $timeout);

    $server = smtpRead($socket);
    if (!smtpOk($server)) {
        fclose($socket);
        logEmailError($to, $subject, $message, 'Réponse SMTP initiale invalide: ' . trim($server));
        return false;
    }

    smtpSend($socket, "EHLO " . gethostname() . "\r\n");
    $server = smtpRead($socket);
    if (preg_match('/^2/', $server) && stripos($server, 'STARTTLS') !== false && $secure === 'tls') {
        smtpSend($socket, "STARTTLS\r\n");
        $server = smtpRead($socket);
        if (!smtpOk($server)) {
            fclose($socket);
            logEmailError($to, $subject, $message, 'Échec STARTTLS: ' . trim($server));
            return false;
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        smtpSend($socket, "EHLO " . gethostname() . "\r\n");
        $server = smtpRead($socket);
    }

    if ($config['username'] && $config['password']) {
        smtpSend($socket, "AUTH LOGIN\r\n");
        $server = smtpRead($socket);
        if (!smtpOk($server)) {
            fclose($socket);
            logEmailError($to, $subject, $message, 'Échec AUTH LOGIN: ' . trim($server));
            return false;
        }
        smtpSend($socket, base64_encode($config['username']) . "\r\n");
        smtpRead($socket);
        smtpSend($socket, base64_encode($config['password']) . "\r\n");
        $server = smtpRead($socket);
        if (!smtpOk($server)) {
            fclose($socket);
            logEmailError($to, $subject, $message, 'Échec de l’authentification SMTP: ' . trim($server));
            return false;
        }
    }

    smtpSend($socket, "MAIL FROM:<{$config['from_email']}>\r\n");
    $server = smtpRead($socket);
    if (!smtpOk($server)) {
        fclose($socket);
        logEmailError($to, $subject, $message, 'MAIL FROM rejeté: ' . trim($server));
        return false;
    }

    smtpSend($socket, "RCPT TO:<{$to}>\r\n");
    $server = smtpRead($socket);
    if (!smtpOk($server)) {
        fclose($socket);
        logEmailError($to, $subject, $message, 'RCPT TO rejeté: ' . trim($server));
        return false;
    }

    smtpSend($socket, "DATA\r\n");
    $server = smtpRead($socket);
    if (!smtpOk($server)) {
        fclose($socket);
        logEmailError($to, $subject, $message, 'Échec DATA: ' . trim($server));
        return false;
    }

    $headers = [];
    $headers[] = "From: {$config['from_name']} <{$config['from_email']}>";
    $headers[] = "Reply-To: {$config['reply_to']}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=UTF-8";
    $headers[] = "Subject: {$subject}";
    $headers[] = "To: {$to}";
    $data = implode("\r\n", $headers) . "\r\n\r\n" . $message . "\r\n.\r\n";
    smtpSend($socket, $data);
    $server = smtpRead($socket);
    if (!smtpOk($server)) {
        fclose($socket);
        logEmailError($to, $subject, $message, 'Échec envoi message: ' . trim($server));
        return false;
    }

    smtpSend($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}

function smtpSend($socket, $command) {
    fputs($socket, $command);
}

function smtpRead($socket) {
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtpOk($response) {
    return preg_match('/^(2|3)/', trim($response));
}

function logEmailError($to, $subject, $message, $reason) {
    $config = getMailConfig();
    $log = sprintf("[%s] TO=%s SUBJECT=%s REASON=%s\n", date('Y-m-d H:i:s'), $to, $subject, $reason);
    $log .= "--- MESSAGE START ---\n" . strip_tags($message) . "\n--- MESSAGE END ---\n\n";
    @file_put_contents($config['log_path'], $log, FILE_APPEND | LOCK_EX);
}
