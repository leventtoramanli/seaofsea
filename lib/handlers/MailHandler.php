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
            self::$logger = getLogger(); // Merkezi logger
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
        $this->mailer->isSMTP();
        $this->mailer->Host = $_ENV['MAIL_HOST'] ?? 'mail.seaofsea.com';
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $_ENV['MAIL_USERNAME'] ?? 'no-reply@seaofsea.com';
        $this->mailer->Password = $_ENV['MAIL_PASSWORD'] ?? 'r*X4N*U}]W~c';
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $this->mailer->Port = $_ENV['MAIL_PORT'] ?? 465;
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
