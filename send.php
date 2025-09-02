<?php
/**
 * send.php — processes contact form submission from contact.html
 * Character set: UTF-8
 * PHP 7.4+ recommended
 */

// -------- BASIC SETTINGS --------
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=UTF-8');

// 1) Destination email (taken from the page content)
// If you want to change it later, just edit this value.
$TO_EMAIL = 'one.ca.uz@gmail.com'; // primary email shown on the page

// 2) Fallback: if you prefer the other address that appears in the sidebar, swap:
// $TO_EMAIL = 'one.ca@gmail.com';

// 3) Optional: subject prefix to help with inbox filters
$SUBJECT_PREFIX = 'ONECA Contact Form';

// 4) Simple rate limit (per IP) to reduce spam bursts
$RATE_LIMIT_SECONDS = 30;

// -------- HELPER FUNCTIONS --------
function respond($ok, $message, $extra = []) {
  http_response_code($ok ? 200 : 400);
  echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

function clean_text($v) {
  // Trim, remove control chars, collapse spaces
  $v = trim($v ?? '');
  $v = preg_replace('/[^\P{C}\n\t]/u', '', $v); // remove control characters
  $v = preg_replace('/\s{2,}/u', ' ', $v);
  return $v;
}

// -------- RATE LIMIT --------
session_start();
$now = time();
if (isset($_SESSION['last_submit_at'])) {
  $delta = $now - (int)$_SESSION['last_submit_at'];
  if ($delta < $RATE_LIMIT_SECONDS) {
    respond(false, 'Please wait a bit before submitting again.');
  }
}

// -------- REQUEST METHOD CHECK --------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(false, 'Invalid request method.');
}

// -------- CSRF / ORIGIN CHECK (relaxed) --------
if (!empty($_SERVER['HTTP_ORIGIN'])) {
  // Allow same-origin only (adjust your domain if needed)
  $origin = $_SERVER['HTTP_ORIGIN'];
  $host = $_SERVER['HTTP_HOST'];
  if (parse_url($origin, PHP_URL_HOST) !== $host) {
    respond(false, 'Cross-origin requests are not allowed.');
  }
}

// -------- HONEYPOT (bot trap) --------
$honeypot = clean_text($_POST['website'] ?? '');
if ($honeypot !== '') {
  respond(true, 'Thanks!'); // pretend success for bots
}

// -------- INPUTS --------
$firstName = clean_text($_POST['firstName'] ?? '');
$lastName  = clean_text($_POST['lastName'] ?? '');
$email     = trim($_POST['email'] ?? '');
$phone     = clean_text($_POST['phone'] ?? '');
$message   = clean_text($_POST['message'] ?? '');

// -------- VALIDATION --------
$errors = [];

if ($firstName === '') { $errors['firstName'] = 'Введите имя.'; }
if ($lastName  === '') { $errors['lastName']  = 'Введите фамилию.'; }
if ($message   === '') { $errors['message']   = 'Введите сообщение.'; }
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  $errors['email'] = 'Введите корректный email.';
}
if (!empty($phone) && !preg_match('/^\+?[0-9()\-\s]{6,}$/u', $phone)) {
  $errors['phone'] = 'Введите корректный номер телефона.';
}

if ($errors) {
  respond(false, 'Пожалуйста, исправьте ошибки и попробуйте снова.', ['errors' => $errors]);
}

// -------- EMAIL COMPOSITION --------
$subject = $SUBJECT_PREFIX . ' — ' . $firstName . ' ' . $lastName;
$site    = $_SERVER['HTTP_HOST'] ?? 'site';
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ua      = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$ref     = $_SERVER['HTTP_REFERER'] ?? 'direct';

$bodyText = "New contact form submission from {$site}\n\n"
          . "Name: {$firstName} {$lastName}\n"
          . "Email: {$email}\n"
          . "Phone: {$phone}\n\n"
          . "Message:\n{$message}\n\n"
          . "Meta:\nIP: {$ip}\nUser-Agent: {$ua}\nReferrer: {$ref}\nTime: " . date('Y-m-d H:i:s');

// Headers (UTF-8 safe)
$encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "From: {$encoded_subject} <no-reply@{$site}>\r\n";
$headers .= "Reply-To: {$email}\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

// -------- SEND (mail()) --------
$mailOk = @mail($TO_EMAIL, $encoded_subject, $bodyText, $headers);

if ($mailOk) {
  $_SESSION['last_submit_at'] = $now;
  respond(true, 'Спасибо! Ваше сообщение отправлено.');
}

// If mail() failed, you can log it or try SMTP with PHPMailer:
/*
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
  $mail = new PHPMailer(true);
  $mail->CharSet = 'UTF-8';
  $mail->isSMTP();
  $mail->Host       = 'smtp.gmail.com';
  $mail->SMTPAuth   = true;
  $mail->Username   = 'YOUR_SMTP_USERNAME';
  $mail->Password   = 'YOUR_SMTP_PASSWORD_OR_APP_PASSWORD';
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  $mail->Port       = 465;

  $mail->setFrom('no-reply@' . $site, 'ONECA Contact');
  $mail->addAddress($TO_EMAIL);
  $mail->addReplyTo($email, $firstName . ' ' . $lastName);

  $mail->Subject = $subject;
  $mail->Body    = $bodyText;

  $mail->send();
  $_SESSION['last_submit_at'] = $now;
  respond(true, 'Спасибо! Ваше сообщение отправлено (SMTP).');
} catch (Exception $e) {
  respond(false, 'Не удалось отправить письмо: ' . $mail->ErrorInfo);
}
*/

respond(false, 'Не удалось отправить письмо. Свяжитесь с нами напрямую: ' . $TO_EMAIL);
