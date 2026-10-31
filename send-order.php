<?php
/* =============================================================
   Francis Advertising - order form email handler
   -------------------------------------------------------------
   SETTINGS: change these two lines if needed.
   $TO   = where order emails are delivered
   $FROM = an email address ON THIS DOMAIN (create it in cPanel >
           Email Accounts). Using a Gmail address here will make
           orders land in spam or be rejected.
   ============================================================= */
$TO   = 'francispeerez7@gmail.com';
$FROM = 'orders@francisadvertising.lk';
$SITE = 'Francis Advertising';

/* ------------------------------------------------------------- */
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch';

function finish($ok, $msg = '') {
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'error' => $msg]);
    } else {
        header('Location: index.html?' . ($ok ? 'sent=1' : 'sent=0') . '#order');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') finish(false, 'Invalid request.');

/* --- Spam protection --------------------------------------- */
if (!empty($_POST['website'])) finish(true);                       // honeypot filled = bot, pretend success
$ts = (int)($_POST['ts'] ?? 0);
if ($ts > 0 && (time() - (int)($ts / 1000)) < 3) finish(false, 'Please take a moment to fill in the form.');

$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate = sys_get_temp_dir() . '/fa_orders_' . md5($ip);
$hits = array_filter(@json_decode(@file_get_contents($rate), true) ?: [], fn($t) => $t > time() - 3600);
if (count($hits) >= 5) finish(false, 'Too many requests. Please call or WhatsApp us instead.');

/* --- Read and check the fields ------------------------------ */
$clean = fn($k, $max) => mb_substr(trim(str_replace(["\r", "\n"], ' ', $_POST[$k] ?? '')), 0, $max);
$name     = $clean('name', 80);
$phone    = $clean('phone', 25);
$email    = $clean('email', 120);
$service  = $clean('service', 80);
$location = $clean('location', 160);
$message  = mb_substr(trim($_POST['message'] ?? ''), 0, 3000);

if (mb_strlen($name) < 2)                                   finish(false, 'Please enter your name.');
if (!preg_match('/^\+?[0-9 ()-]{9,20}$/', $phone))          finish(false, 'Please enter a valid phone number.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) finish(false, 'Please enter a valid email address.');
if (mb_strlen($message) < 5)                                finish(false, 'Please tell us a little about what you need.');

/* --- Attachments (optional, max 3 files, 5 MB each) ---------- */
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/heic' => 'heic', 'image/heif' => 'heif', 'application/pdf' => 'pdf'];
$files = [];
if (!empty($_FILES['photos']['name'][0])) {
    $count = count($_FILES['photos']['name']);
    if ($count > 3) finish(false, 'Please attach up to 3 files.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) finish(false, 'One of the files could not be uploaded. Please try a smaller file.');
        if ($_FILES['photos']['size'][$i] > 5 * 1024 * 1024)  finish(false, 'Each file must be under 5 MB.');
        $tmp  = $_FILES['photos']['tmp_name'][$i];
        $mime = $finfo->file($tmp);
        if (!isset($allowed[$mime])) finish(false, 'Please attach photos (JPG, PNG, WEBP, HEIC) or a PDF.');
        $files[] = ['name' => 'attachment-' . ($i + 1) . '.' . $allowed[$mime], 'mime' => $mime, 'data' => file_get_contents($tmp)];
    }
}

/* --- Build the email ----------------------------------------- */
$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$row = fn($k, $v) => $v === '' ? '' :
    '<tr><td style="padding:10px 14px;background:#f4f4f2;font-weight:600;width:150px;vertical-align:top">' . $k .
    '</td><td style="padding:10px 14px">' . $v . '</td></tr>';

$html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#202227">'
      . '<h2 style="color:#8E1E35;margin:0 0 16px">New order request from the website</h2>'
      . '<table cellspacing="0" style="border:1px solid #e4e4e1;border-collapse:collapse;width:100%;max-width:640px">'
      . $row('Name', $e($name))
      . $row('Phone', '<a href="tel:' . $e($phone) . '">' . $e($phone) . '</a>')
      . $row('Email', $email ? '<a href="mailto:' . $e($email) . '">' . $e($email) . '</a>' : '')
      . $row('Service', $e($service))
      . $row('Size / location', $e($location))
      . $row('Message', nl2br($e($message)))
      . $row('Attachments', $files ? count($files) . ' file(s) attached' : '')
      . '</table><p style="color:#777;font-size:13px">Sent from the order form on francisadvertising.lk on ' . date('j M Y, g:i a') . '</p></div>';

function mime_message($html, $files, &$headers) {
    $b = 'b_' . bin2hex(random_bytes(8));
    $headers .= "Content-Type: multipart/mixed; boundary=\"$b\"\r\n";
    $body  = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
           . chunk_split(base64_encode($html)) . "\r\n";
    foreach ($files as $f) {
        $body .= "--$b\r\nContent-Type: {$f['mime']}; name=\"{$f['name']}\"\r\nContent-Transfer-Encoding: base64\r\n"
               . "Content-Disposition: attachment; filename=\"{$f['name']}\"\r\n\r\n"
               . chunk_split(base64_encode($f['data'])) . "\r\n";
    }
    return $body . "--$b--";
}
$enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';

$headers  = "From: " . $enc("$SITE Website") . " <$FROM>\r\n";
if ($email) $headers .= "Reply-To: " . $enc($name) . " <$email>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$body = mime_message($html, $files, $headers);
$subject = $enc("New order: " . ($service ?: 'Enquiry') . " - $name");

$sent = @mail($TO, $subject, $body, $headers, '-f' . $FROM);
if (!$sent) finish(false, 'Sorry, the message could not be sent. Please call or WhatsApp us on 0777 391 748.');

/* --- Confirmation copy to the customer (if they gave an email) - */
if ($email) {
    $thanks = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#202227">'
            . '<p>Hi ' . $e($name) . ',</p><p>Thank you for your order request. We have received it and will get back to you with a design and a price.</p>'
            . '<p>For anything urgent, call or WhatsApp us on <a href="tel:+94777391748">0777 391 748</a>.</p>'
            . '<p>Francis Advertising<br>50/58-1/3, 3rd Lane, Hekitta Road, Wattala</p></div>';
    $h2  = "From: " . $enc($SITE) . " <$FROM>\r\nReply-To: <$TO>\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    @mail($email, $enc('We received your order request - Francis Advertising'), $thanks, $h2, '-f' . $FROM);
}

$hits[] = time();
@file_put_contents($rate, json_encode(array_values($hits)));
finish(true);
