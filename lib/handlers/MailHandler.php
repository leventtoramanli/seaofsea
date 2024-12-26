<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../../vendor/autoload.php'; // PHPMailer autoload

class MailHandler {
    private $mailer;

    public function __construct() {
        $this->mailer = new PHPMailer(true);

        // SMTP yapılandırması
        $this->mailer->isSMTP();
        $this->mailer->Host = 'mail.seaofsea.com';
        $this->mailer->SMTPAuth = true;
        $this->mailer->SMTPDebug = 2; // Debug seviyesi
        $this->mailer->Debugoutput = function ($str, $level) {
            file_put_contents('smtp_debug.log', date('Y-m-d H:i:s') . " - $str\n", FILE_APPEND);
        };
        $this->mailer->Username = 'no-reply@seaofsea.com';
        $this->mailer->Password = 'r*X4N*U}]W~c';
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $this->mailer->Port = 465;

        // Gönderen bilgileri
        $this->mailer->setFrom('no-reply@seaofsea.com', 'Sea of Sea');
    }

    public function sendMail($to, $subject, $body, $isHtml = true) {
        try {
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

            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Mail gönderme hatası: {$this->mailer->ErrorInfo}");
            return false;
        }
    }
}
