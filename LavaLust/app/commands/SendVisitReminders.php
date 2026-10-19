<?php

class SendVisitReminders
{
    public static $command = 'booking:send-reminders';
    public static $description = 'Send reminder emails one day before check-in';
    public static $arguments = [];

    public function handle()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_NAME') ?: 'esped_resort_db',
            getenv('DB_CHARSET') ?: 'utf8mb4'
        );

        $pdo = new PDO(
            $dsn,
            getenv('DB_USER') ?: 'root',
            getenv('DB_PASSWORD') ?: '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $targetDate = (new DateTime('tomorrow'))->format('Y-m-d');
        $stmt = $pdo->prepare("
            SELECT b.id, b.reference_no, b.check_in_date, b.check_out_date, b.status, b.user_id, u.email, u.username
            FROM bookings b
            INNER JOIN users u ON u.id = b.user_id
            WHERE b.status = 'Confirmed' AND b.check_in_date = ?
        ");
        $stmt->execute([$targetDate]);
        $bookings = $stmt->fetchAll();

        foreach ($bookings as $booking) {
            $subject = 'Visit reminder';
            $body = "Hello {$booking['username']},\n\nThis is a reminder for your stay tomorrow.\nBooking: {$booking['reference_no']}\nCheck-in: {$booking['check_in_date']}\nCheck-out: {$booking['check_out_date']}\n\nThank you,\nESPED Resort";

            $insert = $pdo->prepare("
                INSERT INTO email_notifications (booking_id, user_id, recipient_email, subject, body, email_type, send_status, created_at)
                VALUES (?, ?, ?, ?, ?, 'reminder', 'pending', NOW())
            ");
            $insert->execute([
                $booking['id'],
                $booking['user_id'],
                $booking['email'],
                $subject,
                $body,
            ]);
            $notificationId = (int) $pdo->lastInsertId();

            try {
                $this->sendWithPhpMailer($booking['email'], $subject, $body);
                $update = $pdo->prepare("UPDATE email_notifications SET send_status='sent', sent_at=NOW(), error_message=NULL WHERE id = ?");
                $update->execute([$notificationId]);
                echo "Reminder sent for booking {$booking['reference_no']}" . PHP_EOL;
            } catch (Throwable $exception) {
                $update = $pdo->prepare("UPDATE email_notifications SET send_status='failed', error_message=? WHERE id = ?");
                $update->execute([$exception->getMessage(), $notificationId]);
                echo "Failed reminder for booking {$booking['reference_no']}: {$exception->getMessage()}" . PHP_EOL;
            }
        }

        if ($bookings === []) {
            echo "No reminders due for {$targetDate}" . PHP_EOL;
        }
    }

    private function sendWithPhpMailer($to, $subject, $body)
    {
        $vendorAutoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!file_exists($vendorAutoload)) {
            throw new RuntimeException('PHPMailer is not installed. Run composer require phpmailer/phpmailer.');
        }
        require_once $vendorAutoload;

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: '';
        $mail->Port = (int) (getenv('SMTP_PORT') ?: 587);
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USERNAME') ?: '';
        $mail->Password = getenv('SMTP_PASSWORD') ?: '';
        $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: 'tls';
        $mail->setFrom(getenv('SMTP_FROM_EMAIL') ?: 'no-reply@espedresort.com', getenv('SMTP_FROM_NAME') ?: 'ESPED Resort');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = nl2br($body);
        $mail->AltBody = $body;
        $mail->send();
    }
}
