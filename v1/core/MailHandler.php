<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailHandler {
    private $mailer;
    private static $logger;

    public function __construct() {
        $this->mailer = new PHPMailer(true);

        // Logger yapılandırması
        if (!self::$logger) {
            self::$logger = Logger::getInstance(); // Merkezi logger
        }

        try {
            // SMTP yapılandırması
            $this->configureMailer();

            // Gönderen bilgileri
            $this->mailer->setFrom(
                $_ENV['MAIL_FROM_ADDRESS'],
                $_ENV['MAIL_FROM_NAME']
            );
        } catch (Exception $e) {
            self::$logger->error('Mail configuration failed.', ['exception' => $e]);
            throw new Exception('Mail configuration failed: ' . $e->getMessage());
        }
    }

    private function configureMailer() {
        $config = require __DIR__ . '/../config/config.php'; // merkezi config

        $mailConfig = $config['mail'];

        $this->mailer->isSMTP();
        $this->mailer->Host = $mailConfig['host'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $mailConfig['username'];
        $this->mailer->Password = $mailConfig['password'];
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $this->mailer->Port = $mailConfig['port'];
    }
    public function sendMail($to, $subject, $body, $isHtml = true) {
        try {
            // Alıcı bilgilerini ayarla
            $this->prepareMail($to, $subject, $body, $isHtml);

            // Maili gönder
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            self::$logger->error('Mail sending failed.', ['exception' => $e]);
            return false;
        }
    }

    private function prepareMail($to, $subject, $body, $isHtml) {
        $this->mailer->clearAddresses(); // Önceki adresleri temizle
        $this->mailer->addAddress($to);
        $this->mailer->Subject = $subject;

        if ($isHtml) {
            $this->mailer->isHTML(true);
            $this->mailer->Body = $body;
            $this->mailer->AltBody = strip_tags($body); // HTML olmayan alternatif
        } else {
            $this->mailer->isHTML(false);
            $this->mailer->Body = $body;
        }
    }
}
