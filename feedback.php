<?php
/*
 * PeekDrive website: receives the uninstall survey of uninstall.html and sends
 * it as ONE plain-text e-mail to contact@peekdrive.com.
 *
 * Accepts only: POST, Content-Type application/json, a body of 8 KB at most.
 * Answers JSON: {"ok":true} or {"ok":false,"error":"<short code>"}.
 * Stores nothing, except the rate-limit file described in pd_rate_limited().
 * No CORS header on purpose: only the pages of this site may call it.
 * Written to run on any PHP from 5.6 to 8.x (no typed signatures, no ??).
 */

error_reporting(0);
@ini_set('display_errors', '0');
@header_remove('X-Powered-By');

define('PD_TO', 'contact@peekdrive.com');
define('PD_FROM', 'PeekDrive site <contact@peekdrive.com>');
define('PD_SUBJECT', 'PeekDrive uninstall feedback');
define('PD_MAX_BODY', 8192);        // bytes
define('PD_MAX_MESSAGE', 2000);     // characters
define('PD_MAX_EMAIL', 254);        // characters
define('PD_MIN_FILL_MS', 3000);     // same threshold as MIN_FILL_MS in assets/site.js
define('PD_RATE_WINDOW', 3600);     // seconds
define('PD_RATE_PER_IP', 5);        // e-mails per window for one sender
define('PD_RATE_GLOBAL', 40);       // e-mails per window for everyone

// The fixed keys of the survey (value of the radio buttons) and their meaning.
$PD_REASONS = array(
    'not_found'       => 'Could not find my files or the right passage',
    'slow_buggy'      => 'Too slow or too many bugs',
    'google_warning'  => "Google's warning screen worried me",
    'one_time'        => 'Only needed it once',
    'too_complicated' => 'Too complicated to use',
    'drive_access'    => 'Prefers not to give access to Drive',
    'other'           => 'Something else',
);

/** Sends the JSON answer and stops. */
function pd_respond($status, $payload)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    echo json_encode($payload);
    exit;
}

function pd_fail($status, $error)
{
    pd_respond($status, array('ok' => false, 'error' => $error));
}

/** Number of characters (not bytes) of a valid UTF-8 string. */
function pd_length($text)
{
    $count = preg_match_all('/./us', $text, $unused);
    return $count === false ? PHP_INT_MAX : $count;
}

/**
 * Where the rate-limit file lives. Preferably the hosting account's own home,
 * one level above the published folder (www/): never served, not shared with
 * other accounts, and the same disk for every web server of the cluster.
 * Only used when this script really sits at the root of the published folder
 * (otherwise its parent could itself be served) and the home is writable;
 * else the system temp directory.
 */
function pd_rate_file()
{
    $name = '.peekdrive-feedback-rate.json';
    $here = realpath(__DIR__);
    $root = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    if (is_string($here) && is_string($root) && $here === $root) {
        $private = dirname($here);
        if ($private !== $here && @is_dir($private) && @is_writable($private)) {
            return $private . '/' . $name;
        }
    }
    return rtrim(sys_get_temp_dir(), '/\\') . '/' . $name;
}

/**
 * Rate limit. Returns true when this sender, or everyone together, has already
 * had the allowed number of e-mails sent during the last hour; otherwise
 * records this one and returns false. Returns null when the file cannot be
 * used (the caller then refuses, so the form can never become a mail cannon).
 *
 * What is kept, in one small file (see pd_rate_file()): a random salt and, per
 * sender, the times of the e-mails of the last hour under the first 4 hex
 * digits of sha256(salt + IP). The IP address itself is never written, and
 * such a short prefix cannot be traced back to one address (65,536 IPv4
 * addresses share each value) while still telling the few senders of one hour
 * apart.
 * Entries older than one hour are dropped at each call, and the salt is
 * renewed whenever the file gets empty, so old values cannot be linked to
 * new ones.
 */
function pd_rate_limited($ip)
{
    $path = pd_rate_file();
    if (is_link($path)) {
        return null; // never follow a link planted in a shared directory
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return null;
    }
    @chmod($path, 0600);
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }

    $state = json_decode((string) stream_get_contents($handle), true);
    $now = time();
    $salt = '';
    $hits = array();
    if (is_array($state) && isset($state['salt'], $state['hits']) && is_string($state['salt']) && is_array($state['hits'])) {
        $salt = $state['salt'];
        foreach ($state['hits'] as $key => $times) {
            if (!is_string($key) || !is_array($times)) {
                continue;
            }
            $recent = array();
            foreach ($times as $time) {
                if (is_int($time) && $time > $now - PD_RATE_WINDOW && $time <= $now) {
                    $recent[] = $time;
                }
            }
            if ($recent) {
                $hits[$key] = $recent;
            }
        }
    }
    if ($salt === '' || !$hits) {
        $random = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $salt = bin2hex($random);
        $hits = array();
    }

    // The "h" keeps the key a string: PHP would turn an all-digit key into an
    // integer, and the is_string() test above would then drop that sender.
    $key = 'h' . substr(hash('sha256', $salt . '|' . $ip), 0, 4);
    $mine = isset($hits[$key]) ? count($hits[$key]) : 0;
    $all = 0;
    foreach ($hits as $times) {
        $all += count($times);
    }
    $limited = ($mine >= PD_RATE_PER_IP || $all >= PD_RATE_GLOBAL);
    if (!$limited) {
        $hits[$key][] = $now;
    }

    $written = false;
    $json = json_encode(array('salt' => $salt, 'hits' => $hits));
    if ($json !== false && ftruncate($handle, 0) && rewind($handle)) {
        $written = (fwrite($handle, $json) === strlen($json));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);

    if (!$written) {
        return null;
    }
    return $limited;
}

// ── 1. The request itself ──────────────────────────────────────────────────
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
if ($method !== 'POST') {
    header('Allow: POST');
    pd_fail(405, 'method');
}

$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE']
    : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? $_SERVER['HTTP_CONTENT_TYPE'] : '');
$parts = explode(';', (string) $contentType);
if (strtolower(trim($parts[0])) !== 'application/json') {
    pd_fail(415, 'content_type');
}

// A browser sends Origin with a cross-site POST: refuse any origin but ours.
if (isset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST'])) {
    $originHost = parse_url((string) $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
    $ownHost = preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']);
    if (!is_string($originHost) || strcasecmp($originHost, $ownHost) !== 0) {
        pd_fail(403, 'origin');
    }
}

if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > PD_MAX_BODY) {
    pd_fail(413, 'too_large');
}
$input = @fopen('php://input', 'rb');
$raw = $input === false ? false : stream_get_contents($input, PD_MAX_BODY + 1);
if ($input !== false) {
    fclose($input);
}
if (!is_string($raw) || $raw === '') {
    pd_fail(400, 'invalid_json');
}
if (strlen($raw) > PD_MAX_BODY) {
    pd_fail(413, 'too_large');
}

// json_decode also refuses invalid UTF-8, so every string below is valid UTF-8.
$data = json_decode($raw, true, 4);
if (!is_array($data)) {
    pd_fail(400, 'invalid_json');
}

// ── 2. The fields ──────────────────────────────────────────────────────────
// Honeypot: a field that people never see, so it must be absent or empty.
if (isset($data['trap']) && $data['trap'] !== '') {
    pd_fail(400, 'rejected');
}
// Time spent on the page before sending, measured by the page (milliseconds).
$elapsed = isset($data['elapsed']) ? $data['elapsed'] : null;
if (!(is_int($elapsed) || is_float($elapsed)) || $elapsed < PD_MIN_FILL_MS) {
    pd_fail(400, 'rejected');
}

$reason = isset($data['reason']) ? $data['reason'] : null;
if (!is_string($reason) || !array_key_exists($reason, $PD_REASONS)) {
    pd_fail(422, 'reason');
}

$message = isset($data['message']) ? $data['message'] : '';
if (!is_string($message)) {
    pd_fail(422, 'message');
}
// One kind of line break, then no control character except line break and tab.
$message = str_replace(array("\r\n", "\r"), "\n", $message);
$message = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{0080}-\x{009F}\x{2028}\x{2029}]/u', '', $message);
if (!is_string($message)) {
    pd_fail(422, 'message');
}
$message = trim($message);
if (pd_length($message) > PD_MAX_MESSAGE) {
    pd_fail(422, 'message');
}

$email = isset($data['email']) ? $data['email'] : '';
if (!is_string($email)) {
    pd_fail(422, 'email');
}
$email = trim($email, ' ');
if ($email !== '') {
    // It goes into a mail header (Reply-To): no line break or other control
    // character, a plain addr-spec only, then PHP's own validation.
    if (strlen($email) > PD_MAX_EMAIL
        || preg_match('/[\r\n\x00-\x1F\x7F]/', $email)
        || !preg_match('/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/D', $email)
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        pd_fail(422, 'email');
    }
}

// The box "contact me again to help improve PeekDrive": only counts with an
// address, and only when the page sent it as a real true (ticked by the visitor).
$recontact = ($email !== '' && isset($data['recontact']) && $data['recontact'] === true);

$lang = (isset($data['lang']) && $data['lang'] === 'fr') ? 'fr' : 'en';

// ── 3. Rate limit (only requests that would send an e-mail are counted) ────
$ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$limited = pd_rate_limited($ip);
if ($limited === null) {
    pd_fail(503, 'unavailable');
}
if ($limited) {
    header('Retry-After: ' . PD_RATE_WINDOW);
    pd_fail(429, 'rate_limited');
}

// ── 4. The e-mail ──────────────────────────────────────────────────────────
$lines = array(
    'Uninstall survey sent from the PeekDrive website.',
    '',
    'Reason:   ' . $reason . ' (' . $PD_REASONS[$reason] . ')',
    'Language: ' . $lang,
    'E-mail:   ' . ($email !== '' ? $email : '(not given)'),
    'Contact again to help improve PeekDrive: ' . ($recontact ? 'YES, box ticked' : 'no'),
    '',
    'Message:',
    $message !== '' ? $message : '(none)',
    '',
);
// Base64 keeps the accents and the long lines intact whatever the mail server.
$body = chunk_split(base64_encode(implode("\n", $lines)), 76, "\r\n");

// Subject and From are constants; $reason is one of the fixed keys above.
$subject = PD_SUBJECT . ' [' . $reason . ']';
$headers = array(
    'From: ' . PD_FROM,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: base64',
);
if ($email !== '') {
    // The only place where something typed by the visitor enters a header.
    $headers[] = 'Reply-To: ' . $email;
}

$sent = @mail(PD_TO, $subject, $body, implode("\r\n", $headers));
if (!$sent) {
    pd_fail(502, 'send_failed');
}
pd_respond(200, array('ok' => true));
