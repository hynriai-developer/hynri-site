<?php
// Receives the contact form from index.html and emails it to the Hynri inbox.
declare(strict_types=1);

const TO_ADDRESS   = 'contact@hynri.com';
const FROM_ADDRESS = 'contact@hynri.com';   // must be a mailbox on the domain
const MIN_SECONDS_BETWEEN_SENDS = 30;       // per visitor IP

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(bool $ok, string $message, int $status = 200): never {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Method not allowed.', 405);
}

// Honeypot: real visitors never see or fill this field. Pretend success to bots.
if (!empty($_POST['website'])) {
    respond(true, 'Thanks! Your message has been sent.');
}

// Strip line breaks from single-line fields (blocks email header injection).
function clean_line(string $v): string {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v) ?? '');
}

$name    = clean_line((string)($_POST['name'] ?? ''));
$email   = clean_line((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
    respond(false, 'Please fill in your name, email, and message.', 422);
}
if (mb_strlen($name) > 100 || mb_strlen($email) > 150 || mb_strlen($message) > 5000) {
    respond(false, 'One of your entries is too long.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.', 422);
}

// Simple rate limit per IP.
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lock = sys_get_temp_dir() . '/hynri_contact_' . hash('sha256', $ip);
if (is_file($lock) && (time() - (int)filemtime($lock)) < MIN_SECONDS_BETWEEN_SENDS) {
    respond(false, 'Please wait a moment before sending another message.', 429);
}

$subject = 'New website inquiry from ' . $name;
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

$body  = "Name: {$name}\n";
$body .= "Email: {$email}\n";
$body .= "Sent: " . date('Y-m-d H:i:s T') . "\n\n";
$body .= $message . "\n";

$headers = [
    'From: Hynri Website <' . FROM_ADDRESS . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: Hynri contact form',
];

if (@mail(TO_ADDRESS, $encodedSubject, $body, implode("\r\n", $headers))) {
    @touch($lock);
    respond(true, 'Thanks! Your message has been sent. We\'ll be in touch soon.');
}

respond(false, 'Sorry, something went wrong sending your message. Please email contact@hynri.com directly.', 500);
