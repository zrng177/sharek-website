<?php
/**
 * Sharek v1.5 - Email Service
 * 
 * @file EmailService.php
 * @date 2026-05-25
 * @description Email service using PHPMailer for sending notifications
 * @version 1.5.0
 * 
 * Security Features:
 * - Input sanitization
 * - SMTP authentication
 * - TLS encryption
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer from the PHPMailer-6.8.1 directory
require_once __DIR__ . '/PHPMailer-6.8.1/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer-6.8.1/src/Exception.php';
require_once __DIR__ . '/PHPMailer-6.8.1/src/SMTP.php';

class EmailService {
    private $mockMode = false;
    private function executeSend() {
        if ($this->mockMode) {
            $to = [];
            foreach ($this->mailer->getToAddresses() as $addr) $to[] = $addr[0];
            $logLine = date('Y-m-d H:i:s') . " | MOCK SEND | To: " . implode(',', $to) . " | Subject: " . $this->mailer->Subject . PHP_EOL;
            @file_put_contents(__DIR__ . '/mock_emails.log', $logLine, FILE_APPEND | LOCK_EX);
            return true;
        }
        $this->executeSend();
        return true;
    }
    private $mailer;
    
    /**
     * Constructor - Initialize PHPMailer with SMTP settings
     * 
     * Loads SMTP configuration from .env file
     */
    public function __construct() {
        $env = parse_ini_file(__DIR__ . '/.env', false, INI_SCANNER_RAW);
        if ($env === false) {
            // Fallback for InfinityFree open_basedir restrictions
            $env = $this->parseEnvFallback(__DIR__ . '/.env');
        }
        if ($env === false) {
            throw new RuntimeException('Configuration unavailable');
        }
        $this->mailer = new PHPMailer(true);
        if (empty($env['SMTP_HOST'])) { $this->mockMode = true; }
        
        // SMTP Configuration
        $this->mailer->isSMTP();
        $this->mailer->Host = $env['SMTP_HOST'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $env['SMTP_USER'];
        $this->mailer->Password = $env['SMTP_PASS'];
        // STARTTLS on port 587 — must match SMTP_PORT in .env
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = (int)$env['SMTP_PORT'];
        $this->mailer->Timeout = 10;
        // SMTP debug output is off (0). Do not set this above 0 in
        // production — verbose debug levels can leak credentials/SMTP
        // transcripts into logs or output.
        $this->mailer->SMTPDebug = 0;
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ];

        // Default sender
        $this->mailer->setFrom(
            $env['SMTP_USER'],
            'شەریک - Sharek'
        );

        // Reply-To header for support
        $this->mailer->addReplyTo('support@sharek.com', 'شەریک پشتگیری');
    }

    /**
     * Escape a value for safe interpolation into HTML email bodies.
     *
     * Centralized here so every email-building method gets the same
     * protection regardless of whether the caller already escaped its
     * input — single point of truth instead of relying on every caller
     * (see audit finding #10).
     *
     * @param mixed $value
     * @return string
     */
    private function esc($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Get centralized email styles
     *
     * @return string CSS styles for email templates
     */
    private function getEmailStyles(): string {
        return "
            body { font-family: 'Vazirmatn', Tahoma, Arial, sans-serif; direction: rtl; background: #f0f4ff; margin: 0; padding: 20px; }
            .wrap { max-width: 540px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 32px rgba(30,58,138,0.12); }
            .header { background: linear-gradient(135deg, #0f2557, #1e3a8a); padding: 28px 24px; text-align: center; }
            .header h1 { color: #ffffff; margin: 0; font-size: 26px; }
            .header p  { color: #bfdbfe; margin: 4px 0 0; font-size: 13px; }
            .body   { padding: 28px 24px; color: #1f2937; line-height: 1.8; }
            .footer { background: #f1f5f9; padding: 16px 24px; text-align: center; color: #9ca3af; font-size: 12px; border-top: 1px solid #e5e7eb; }
            .btn    { display: inline-block; padding: 12px 28px; background: #1e3a8a; color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: bold; margin-top: 16px; }
            .info-box { background: #eff6ff; border-right: 4px solid #3b82f6; padding: 12px 16px; border-radius: 8px; margin: 16px 0; }
            .warn-box { background: #fefce8; border-right: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; margin: 16px 0; color: #92400e; }
            .danger-box { background: #fef2f2; border-right: 4px solid #dc2626; padding: 12px 16px; border-radius: 8px; margin: 16px 0; }
        ";
    }

    /**
     * Fallback parser for .env file when parse_ini_file fails
     * Used for InfinityFree compatibility with open_basedir restrictions
     * 
     * @param string $file Path to .env file
     * @return array|false Parsed configuration or false on failure
     */
    private function parseEnvFallback($file) {
        if (!file_exists($file) || !is_readable($file)) {
            return false;
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env = [];
        
        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (empty($line) || strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE format
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $env[trim($key)] = trim($value);
            }
        }
        
        return $env;
    }

    /**
     * Send email verification
     * 
     * @param string $email Recipient email address
     * @param string $userName Recipient name
     * @param string $verificationLink Verification link
     * @return bool True if email sent successfully
     */
    public function sendVerificationEmail($email, $userName, $verificationLink) {
        try {
            // Escaped copies for HTML interpolation only — the originals are
            // kept untouched for the plain-text AltBody below.
            $safeName = $this->esc($userName);
            $safeLink = $this->esc($verificationLink);

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($email);
            $this->mailer->Subject = 'پشتڕاستکردنەوەی ئیمەیڵ - شەریک';
            
            $body = "
                <html dir='rtl'>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        {$this->getEmailStyles()}
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .content { background: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 20px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='wrap'>
                            <div class='header'>
                                <h1>شەریک</h1>
                                <p>پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان</p>
                            </div>
                            <div class='body'>
                                <p>سڵاو {$safeName}،</p>
                                <p>سوپاس بۆ تۆمارکردن لە شەریک.</p>
                                <p>تکایە لەسەر دوگمەی خوارەوە کرتە بکە بۆ پشتڕاستکردنەوەی ئیمەیڵەکەت:</p>
                                <a href='{$safeLink}' class='btn'>پشتڕاستکردنەوەی ئیمەیڵ</a>
                                <p>ئەگەر ئەمە کارێک نەبێت، تکایە ئەم ئیمەیڵە پشتگوێ بخە.</p>
                                <br>
                                <p>ڕێگەربە،<br>تیمی شەریک</p>
                            </div>
                            <div class='footer'>
                                © 2026 شەریک — هەموو مافەکان پارێزراون
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            $this->mailer->Body = $body;
            $this->mailer->isHTML(true);
            $this->mailer->AltBody = "سڵاو {$userName}، سوپاس بۆ تۆمارکردن لە شەریک. تکایە ئەم لینکە بکەرەوە بۆ پشتڕاستکردنەوەی ئیمەیڵەکەت: {$verificationLink}";
            
            $this->executeSend();
            return true;
            
        } catch (Exception $e) {
            $logLine = date('Y-m-d H:i:s') . ' | MAIL FAIL | ' . $e->getMessage() . PHP_EOL;
            @file_put_contents(__DIR__ . '/email_errors.log', $logLine, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
    
    /**
     * Send subscription status change notification
     * 
     * @param string $email Recipient email address
     * @param string $userName Recipient name
     * @param string $city Subscription city
     * @param string $status New subscription status
     * @return bool True if email sent successfully
     */
    public function sendSubscriptionNotification($email, $userName, $city, $status) {
        try {
            $safeName = $this->esc($userName);
            $safeCity = $this->esc($city);

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($email);
            $this->mailer->Subject = 'گۆڕانکاری لە سابسکرایبشنەکەت - شەریک';
            
            $statusText = $status === 'active' ? 'چالاک کرا' : 'ناچالاک کرا';
            
            $body = "
                <html dir='rtl'>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        {$this->getEmailStyles()}
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .content { background: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 20px; }
                        .status { font-size: 1.2em; font-weight: bold; color: #059669; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='wrap'>
                            <div class='header'>
                                <h1>شەریک</h1>
                                <p>پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان</p>
                            </div>
                            <div class='body'>
                                <p>سڵاو {$safeName}،</p>
                                <p>سابسکرایبشنەکەت بۆ شاری <strong>{$safeCity}</strong> {$statusText}.</p>
                                <p>ئەگەر ئەمە کارێک نەبێت، تکایە پەیوەندی بە پشتگیریەوە بکە.</p>
                                <br>
                                <p>ڕێگەربە،<br>تیمی شەریک</p>
                            </div>
                            <div class='footer'>
                                © 2026 شەریک — هەموو مافەکان پارێزراون
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            $this->mailer->Body = $body;
            $this->mailer->isHTML(true);
            $this->mailer->AltBody = "سڵاو {$userName}، سابسکرایبشنەکەت بۆ شاری {$city} {$statusText}.";
            
            $this->executeSend();
            return true;
            
        } catch (Exception $e) {
            $logLine = date('Y-m-d H:i:s') . ' | MAIL FAIL | ' . $e->getMessage() . PHP_EOL;
            @file_put_contents(__DIR__ . '/email_errors.log', $logLine, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
    
    /**
     * Send trip booking confirmation
     * 
     * @param string $email Recipient email address
     * @param string $userName Recipient name
     * @param string $departureCity Departure city
     * @param string $destinationCity Destination city
     * @param string $dateTime Trip date and time
     * @return bool True if email sent successfully
     */
    public function sendBookingConfirmation($email, $userName, $departureCity, $destinationCity, $dateTime) {
        try {
            $safeName = $this->esc($userName);
            $safeDeparture = $this->esc($departureCity);
            $safeDestination = $this->esc($destinationCity);
            $safeDateTime = $this->esc($dateTime);

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($email);
            $this->mailer->Subject = 'پشتڕاستکردنی داواکاری گەشت - شەریک';
            
            $body = "
                <html dir='rtl'>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        {$this->getEmailStyles()}
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .content { background: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 20px; }
                        .trip-info { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='wrap'>
                            <div class='header'>
                                <h1>شەریک</h1>
                                <p>پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان</p>
                            </div>
                            <div class='body'>
                                <p>سڵاو {$safeName}،</p>
                                <p>داواکاری گەشتەکەت پشتڕاست کرا.</p>
                                <div class='info-box'>
                                    <p><strong>لە:</strong> {$safeDeparture}</p>
                                    <p><strong>بۆ:</strong> {$safeDestination}</p>
                                    <p><strong>بەروار:</strong> {$safeDateTime}</p>
                                </div>
                                <p>تکایە لە کاتی گەیشتن بە شوێنەکە بە.</p>
                                <br>
                                <p>سوپاس بۆ بەکارهێنانی شەریک،<br>تیمی شەریک</p>
                            </div>
                            <div class='footer'>
                                © 2026 شەریک — هەموو مافەکان پارێزراون
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            $this->mailer->Body = $body;
            $this->mailer->isHTML(true);
            $this->mailer->AltBody = "سڵاو {$userName}، داواکاری گەشتەکەت پشتڕاست کرا. لە {$departureCity} بۆ {$destinationCity} لە {$dateTime}.";
            
            $this->executeSend();
            return true;
            
        } catch (Exception $e) {
            $logLine = date('Y-m-d H:i:s') . ' | MAIL FAIL | ' . $e->getMessage() . PHP_EOL;
            @file_put_contents(__DIR__ . '/email_errors.log', $logLine, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
    
    /**
     * Send new booking notification to driver
     * 
     * @param string $email Driver email address
     * @param string $driverName Driver name
     * @param string $passengerName Passenger name
     * @param string $departureCity Departure city
     * @param string $destinationCity Destination city
     * @param string $dateTime Trip date and time
     * @param int $seatsBooked Number of seats booked
     * @return bool True if email sent successfully
     */
    public function sendNewBookingNotification($email, $driverName, $passengerName, $departureCity, $destinationCity, $dateTime, $seatsBooked) {
        try {
            $safeDriverName = $this->esc($driverName);
            $safePassengerName = $this->esc($passengerName);
            $safeDeparture = $this->esc($departureCity);
            $safeDestination = $this->esc($destinationCity);
            $safeDateTime = $this->esc($dateTime);
            $safeSeats = $this->esc($seatsBooked);

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($email);
            $this->mailer->Subject = 'داواکاری نوێ بۆ گەشتەکەت - شەریک';
            
            $body = "
                <html dir='rtl'>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        {$this->getEmailStyles()}
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .content { background: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 20px; }
                        .trip-info { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
                        .highlight { color: #059669; font-weight: bold; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='wrap'>
                            <div class='header'>
                                <h1>شەریک</h1>
                                <p>پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان</p>
                            </div>
                            <div class='body'>
                                <p>سڵاو {$safeDriverName}،</p>
                                <p><span class='highlight'>{$safePassengerName}</span> داواکاری کورسی کرد بۆ گەشتەکەت.</p>
                                <div class='info-box'>
                                    <p><strong>لە:</strong> {$safeDeparture}</p>
                                    <p><strong>بۆ:</strong> {$safeDestination}</p>
                                    <p><strong>بەروار:</strong> {$safeDateTime}</p>
                                    <p><strong>کورسی:</strong> {$safeSeats}</p>
                                </div>
                                <p>تکایە پەیوەندی بە سەرنشینەکە بکە بۆ ڕێکخستنی وردەکارییەکان.</p>
                                <br>
                                <p>سوپاس بۆ بەکارهێنانی شەریک،<br>تیمی شەریک</p>
                            </div>
                            <div class='footer'>
                                © 2026 شەریک — هەموو مافەکان پارێزراون
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            $this->mailer->Body = $body;
            $this->mailer->isHTML(true);
            $this->mailer->AltBody = "سڵاو {$driverName}، {$passengerName} داواکاری کورسی کرد بۆ گەشتەکەت. لە {$departureCity} بۆ {$destinationCity} لە {$dateTime}. کورسی: {$seatsBooked}.";
            
            $this->executeSend();
            return true;
            
        } catch (Exception $e) {
            $logLine = date('Y-m-d H:i:s') . ' | MAIL FAIL | ' . $e->getMessage() . PHP_EOL;
            @file_put_contents(__DIR__ . '/email_errors.log', $logLine, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
    
    /**
     * Send trip cancellation notification
     * 
     * @param string $email Recipient email address
     * @param string $userName Recipient name
     * @param string $departureCity Departure city
     * @param string $destinationCity Destination city
     * @param string $dateTime Trip date and time
     * @param string $reason Cancellation reason (optional)
     * @return bool True if email sent successfully
     */
    public function sendTripCancellationNotification($email, $userName, $departureCity, $destinationCity, $dateTime, $reason = '') {
        try {
            $safeName = $this->esc($userName);
            $safeDeparture = $this->esc($departureCity);
            $safeDestination = $this->esc($destinationCity);
            $safeDateTime = $this->esc($dateTime);

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($email);
            $this->mailer->Subject = 'گەشتەکە هەڵوەشایەوە - شەریک';
            
            $reasonText = $reason ? "<p><strong>هۆکار:</strong> {$this->esc($reason)}</p>" : '';
            
            $body = "
                <html dir='rtl'>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        {$this->getEmailStyles()}
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .content { background: #fef2f2; padding: 20px; border-radius: 8px; margin-top: 20px; }
                        .trip-info { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='wrap'>
                            <div class='header' style='background: linear-gradient(135deg, #dc2626, #ef4444);'>
                                <h1>شەریک</h1>
                                <p>پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان</p>
                            </div>
                            <div class='body'>
                                <p>سڵاو {$safeName}،</p>
                                <p>گەشتەکە هەڵوەشایەوە.</p>
                                <div class='danger-box'>
                                    <p><strong>لە:</strong> {$safeDeparture}</p>
                                    <p><strong>بۆ:</strong> {$safeDestination}</p>
                                    <p><strong>بەروار:</strong> {$safeDateTime}</p>
                                </div>
                                {$reasonText}
                                <p>تکایە گەشتێکی دیکە بگەڕێنەوە.</p>
                                <br>
                                <p>بەڕێزە،<br>تیمی شەریک</p>
                            </div>
                            <div class='footer'>
                                © 2026 شەریک — هەموو مافەکان پارێزراون
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            $this->mailer->Body = $body;
            $this->mailer->isHTML(true);
            $this->mailer->AltBody = "سڵاو {$userName}، گەشتەکە هەڵوەشایەوە. لە {$departureCity} بۆ {$destinationCity} لە {$dateTime}.";
            
            $this->executeSend();
            return true;
            
        } catch (Exception $e) {
            $logLine = date('Y-m-d H:i:s') . ' | MAIL FAIL | ' . $e->getMessage() . PHP_EOL;
            @file_put_contents(__DIR__ . '/email_errors.log', $logLine, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
    
    /**
     * Send trip update notification
     * 
     * @param string $email Recipient email address
     * @param string $userName Recipient name
     * @param string $departureCity Departure city
     * @param string $destinationCity Destination city
     * @param string $oldDateTime Old trip date and time
     * @param string $newDateTime New trip date and time
     * @return bool True if email sent successfully
     */
    public function sendTripUpdateNotification($email, $userName, $departureCity, $destinationCity, $oldDateTime, $newDateTime) {
        try {
            $safeName = $this->esc($userName);
            $safeDeparture = $this->esc($departureCity);
            $safeDestination = $this->esc($destinationCity);
            $safeOldDateTime = $this->esc($oldDateTime);
            $safeNewDateTime = $this->esc($newDateTime);

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($email);
            $this->mailer->Subject = 'گۆڕانکاری لە گەشتەکەت - شەریک';
            
            $body = "
                <html dir='rtl'>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        {$this->getEmailStyles()}
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .content { background: #f8fafc; padding: 20px; border-radius: 8px; margin-top: 20px; }
                        .trip-info { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
                        .change { background: #fef3c7; padding: 10px; border-radius: 6px; margin: 10px 0; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='wrap'>
                            <div class='header'>
                                <h1>شەریک</h1>
                                <p>پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان</p>
                            </div>
                            <div class='body'>
                                <p>سڵاو {$safeName}،</p>
                                <p>گەشتەکە نوێ کراوە.</p>
                                <div class='info-box'>
                                    <p><strong>لە:</strong> {$safeDeparture}</p>
                                    <p><strong>بۆ:</strong> {$safeDestination}</p>
                                </div>
                                <div class='warn-box'>
                                    <p><strong>بەرواری کۆن:</strong> {$safeOldDateTime}</p>
                                    <p><strong>بەرواری نوێ:</strong> {$safeNewDateTime}</p>
                                </div>
                                <p>تکایە بەرواری نوێت بپشکنە.</p>
                                <br>
                                <p>سوپاس بۆ بەکارهێنانی شەریک،<br>تیمی شەریک</p>
                            </div>
                            <div class='footer'>
                                © 2026 شەریک — هەموو مافەکان پارێزراون
                            </div>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            $this->mailer->Body = $body;
            $this->mailer->isHTML(true);
            $this->mailer->AltBody = "سڵاو {$userName}، گەشتەکە نوێ کراوە. لە {$departureCity} بۆ {$destinationCity}. بەرواری کۆن: {$oldDateTime}، بەرواری نوێ: {$newDateTime}.";
            
            $this->executeSend();
            return true;
            
        } catch (Exception $e) {
            $logLine = date('Y-m-d H:i:s') . ' | MAIL FAIL | ' . $e->getMessage() . PHP_EOL;
            @file_put_contents(__DIR__ . '/email_errors.log', $logLine, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
    
    /**
     * Send custom email with a simple message
     * 
     * @param string $email Recipient email
     * @param string $subject Email subject
     * @param string $message Email message
     * @param bool $isHtml Whether $message is HTML (true) or plain text (false, default)
     * @return bool True if email sent successfully
     */
    public function sendCustomEmail($email, $subject, $message, $isHtml = false) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($email);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $message;
            $this->mailer->isHTML($isHtml);
            if ($isHtml) {
                $this->mailer->AltBody = trim(strip_tags($message));
            }
            
            $this->executeSend();
            return true;
            
        } catch (Exception $e) {
            $logLine = date('Y-m-d H:i:s') . ' | MAIL FAIL | ' . $e->getMessage() . PHP_EOL;
            @file_put_contents(__DIR__ . '/email_errors.log', $logLine, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
    
    /**
     * ناردنی کۆدی پشتڕاستکردنی تۆمارکردن (OTP)
     * 
     * @param string $email Recipient email
     * @param string $userName Recipient name
     * @param string $code 6-digit OTP code
     * @return bool True if email sent successfully
     */
    public function sendRegistrationOTP($email, $userName, $code) {
        $this->mailer->clearAddresses();
        $this->mailer->clearAttachments();
        
        try {
            $safeName = $this->esc($userName);
            $safeCode = $this->esc($code);

            $this->mailer->addAddress($email);
            $this->mailer->Subject = '🔐 کۆدی پشتڕاستکردنی تۆمارکردنت — شەریک';
            $this->mailer->isHTML(true);

            $body = "
            <html dir='rtl' lang='ku'>
            <head>
                <meta charset='UTF-8'>
                <style>
                    {$this->getEmailStyles()}
                    .otp-box { background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 2px dashed #3b82f6;
                               border-radius: 12px; text-align: center; padding: 24px 16px; margin: 24px 0; }
                    .otp-label { font-size: 13px; color: #6b7280; margin-bottom: 8px; }
                    .otp-code  { font-size: 44px; font-weight: 900; letter-spacing: 12px; color: #1e3a8a; font-family: monospace; }
                </style>
            </head>
            <body>
              <div class='wrap'>
                <div class='header'>
                  <h1>🚗 شەریک</h1>
                  <p>پلاتفۆرمی هاوبەشکردنی گەشت لە کوردستان</p>
                </div>
                <div class='body'>
                  <p>سڵاو خۆشەویست {$safeName} 👋</p>
                  <p>
                    خۆشحاڵین کە تۆ بەرەو ماڵخێزانی شەریک دەست دەدەیت!<br>
                    بۆ دڵنیابوون لە ئیمەیڵەکەت، تکایە کۆدی خوارەوە بنووسە:
                  </p>
                  <div class='otp-box'>
                    <div class='otp-label'>کۆدی پشتڕاستکردنی تۆمارکردن</div>
                    <div class='otp-code'>{$safeCode}</div>
                  </div>
                  <div class='warn-box'>
                    ⏱️ ئەم کۆدە تەنها <strong>١٥ خولەک</strong> دەوەستێت — بەپێچەوانە بەسەر دەچێت.
                  </div>
                  <p>
                    ئەگەر تۆ تۆمارکردن نەکردووە، تکایە ئەم ئیمەیڵە پشتگوێ بخە
                    و پەیوەندیمان پێوه بکە.
                  </p>
                </div>
                <div class='footer'>
                  © 2026 شەریک — هەموو مافەکان پارێزراون<br>
                  ئەم ئیمەیڵە بەخۆکاری نێردراوە، وەڵامی مەدەیەوە.
                </div>
              </div>
            </body>
            </html>";

            $this->mailer->Body    = $body;
            $this->mailer->AltBody = "سڵاو {$userName}، کۆدی پشتڕاستکردنت: {$code} — تەنها ١٥ خولەک دەوەستێت.";
            $this->executeSend();
            return true;

        } catch (Exception $e) {
            $logLine = date('Y-m-d H:i:s') . ' | MAIL FAIL | ' . $e->getMessage() . PHP_EOL;
            @file_put_contents(__DIR__ . '/email_errors.log', $logLine, FILE_APPEND | LOCK_EX);
            return false;
        }
    }
}
