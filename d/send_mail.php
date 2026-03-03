<?php
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error']);
    exit;
}

/* ================= INPUT ================= */

$name     = trim($_POST['FirstName'] ?? '');
$email    = filter_var($_POST['EmailAddress'] ?? '', FILTER_VALIDATE_EMAIL);
$phone    = trim($_POST['Phone'] ?? '');
$countryCode = trim($_POST['COUNTRYCODE'] ?? '');
$project  = trim($_POST['mx_Project_Name'] ?? '');
$location = trim($_POST['mx_City'] ?? '');
$client   = trim($_POST['CLIENT'] ?? '');
$domain   = trim($_POST['DOMAIN'] ?? '');

/* ===== FULL PHONE ===== */
if ($countryCode !== '') {
    $countryCode = ltrim($countryCode, '+');
    $fullPhone = $countryCode . '-' . $phone;
} else {
    $fullPhone = $phone;
}

/* ===== VALIDATION ===== */
if (!$name || !$email || !$phone) {
    echo json_encode(['status' => 'error']);
    exit;
}

/* ================= AUTO PROJECT URL ================= */




/* ================= CRM ================= */

$crmSuccess = false;

$crmUrl = 'https://api-in21.leadsquared.com/v2/LeadManagement.svc/Lead.Capture?accessKey=u$r809e24bb805afa1a050331c6cf61b994&secretKey=b09aa150b3e011b4589e29704d3ce9d85b28b7fb';

$crmData = [
    ["Attribute" => "FirstName",        "Value" => $name],
    ["Attribute" => "EmailAddress",     "Value" => $email],
    ["Attribute" => "Phone",            "Value" => $fullPhone],
    ["Attribute" => "mx_Project_Name",  "Value" => $project],
    ["Attribute" => "mx_City",          "Value" => $location]
];

$ch = curl_init($crmUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($crmData),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    CURLOPT_TIMEOUT        => 5
]);

curl_exec($ch);
$crmHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$crmSuccess = ($crmHttp >= 200 && $crmHttp < 300);

/* ================= IP + GEO ================= */

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ip = explode(',', $ip)[0];

$geo = ['country' => 'Unknown', 'region' => 'Unknown', 'city' => 'Unknown'];

if (!in_array($ip, ['127.0.0.1', '::1'], true)) {

    $geoCurl = curl_init("https://ipwho.is/{$ip}");
    curl_setopt_array($geoCurl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3
    ]);

    $geoResponse = curl_exec($geoCurl);
    curl_close($geoCurl);

    if ($geoResponse) {
        $g = json_decode($geoResponse, true);
        if (!empty($g['success'])) {
            $geo['country'] = $g['country'] ?? 'Unknown';
            $geo['region']  = $g['region'] ?? 'Unknown';
            $geo['city']    = $g['city'] ?? 'Unknown';
        }
    }
}

/* ================= GOOGLE SHEET ================= */

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function create_jwt($payload, $privateKey) {
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $segments = [];
    $segments[] = base64url_encode(json_encode($header));
    $segments[] = base64url_encode(json_encode($payload));
    $signingInput = implode('.', $segments);
    openssl_sign($signingInput, $signature, $privateKey, 'SHA256');
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

/*
Excel Columns:
Date | Name | Email | Phone | Project | Location | IP | Country | Region | City | Client | CRM Status | URL
*/

$sheetRow = [
    date('Y-m-d H:i:s'),
    $name,
    $email,
    $fullPhone,
    $project,
    $location,
    $ip,
    $geo['country'],
    $geo['region'],
    $geo['city'],
    $client,
    $crmSuccess ? 'SUCCESS' : 'FAILED',
    $domain
];

$spreadsheetId = "1_3xJfI4wh-Zx3liNjSC3oRl157qSp99J6-fKDfuoRZ8";
$sheetName     = "Leads";
$serviceEmail  = "fdr-939@fdrserver.iam.gserviceaccount.com";
$privateKey    = file_get_contents(__DIR__ . "/key.pem");

$now = time();
$payload = [
    "iss"   => $serviceEmail,
    "scope" => "https://www.googleapis.com/auth/spreadsheets",
    "aud"   => "https://oauth2.googleapis.com/token",
    "exp"   => $now + 3600,
    "iat"   => $now
];

$jwt = create_jwt($payload, $privateKey);

/* ===== GET ACCESS TOKEN ===== */

$tokenCurl = curl_init("https://oauth2.googleapis.com/token");
curl_setopt_array($tokenCurl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
        "assertion"  => $jwt
    ]),
    CURLOPT_TIMEOUT        => 5
]);

$tokenResponse = json_decode(curl_exec($tokenCurl), true);
curl_close($tokenCurl);

/* ===== APPEND TO SHEET ===== */

if (!empty($tokenResponse['access_token'])) {

    $appendUrl = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$sheetName}!A1:append?valueInputOption=RAW";

    $sheetCurl = curl_init($appendUrl);
    curl_setopt_array($sheetCurl, [
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$tokenResponse['access_token']}",
            "Content-Type: application/json"
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['values' => [$sheetRow]]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5
    ]);

    curl_exec($sheetCurl);
    curl_close($sheetCurl);
}

/* ================= LOG FILE ================= */

$log_file = __DIR__ . '/leads.txt';

$log_entry = "[" . date('Y-m-d H:i:s') . "] "
    . "Name: $name | "
    . "Email: $email | "
    . "Phone: $fullPhone | "
    . "Project: $project | "
    . "URL: $projectUrl\n";

file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

/* ================= FINAL RESPONSE ================= */

echo json_encode(['status' => 'success']);
exit;
