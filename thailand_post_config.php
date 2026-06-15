<?php
declare(strict_types=1);

/**
 * Thailand Post Track & Trace API client.
 *
 * Flow:
 * 1. Exchange the API key for a short-lived token.
 * 2. Use that token to call the tracking endpoint.
 */

define('THAILAND_POST_API_KEY', getenv('THAILAND_POST_API_KEY') ?: 'SXRoM%CmPqJ_VQTTB3IRB;HPSDJHRGIXVJG7GYZ=K_TnOVYBE2VTAqSOJBMmFwE*TXCrC7Q%AKAjBnU8UsQBCYRfF4V;T0B7DIUH');
define('THAILAND_POST_AUTH_URL', getenv('THAILAND_POST_AUTH_URL') ?: 'https://trackapi.thailandpost.co.th/post/api/v1/authenticate/token');
define('THAILAND_POST_TRACK_API_URL', getenv('THAILAND_POST_TRACK_API_URL') ?: 'https://trackapi.thailandpost.co.th/post/api/v1/track');
define('THAILAND_POST_TRACK_WEB_URL', getenv('THAILAND_POST_TRACK_WEB_URL') ?: 'https://track.thailandpost.co.th/');

class ThailandPostAPI
{
    private string $apiKey;
    private string $authUrl;
    private string $trackApiUrl;
    private string $trackWebUrl;

    public function __construct()
    {
        $this->apiKey = (string) THAILAND_POST_API_KEY;
        $this->authUrl = (string) THAILAND_POST_AUTH_URL;
        $this->trackApiUrl = (string) THAILAND_POST_TRACK_API_URL;
        $this->trackWebUrl = rtrim((string) THAILAND_POST_TRACK_WEB_URL, '/') . '/';
    }

    public function isValidTrackingNumber(string $trackingNumber): bool
    {
        $trackingNumber = strtoupper(trim($trackingNumber));
        return (bool) preg_match('/^[A-Z]{2}\d{9}TH$/', $trackingNumber);
    }

    public function getTrackingUrl(string $trackingNumber): string
    {
        return $this->trackWebUrl . '?trackNumber=' . urlencode(strtoupper(trim($trackingNumber)));
    }

    public function trackShipment(string $trackingNumber, string $language = 'TH'): array
    {
        $trackingNumber = strtoupper(trim($trackingNumber));

        if (!$this->isValidTrackingNumber($trackingNumber)) {
            return [
                'status' => 'error',
                'message' => 'รูปแบบเลขพัสดุไม่ถูกต้อง ตัวอย่าง: EV123456789TH',
            ];
        }

        $tokenResult = $this->requestToken();
        if ($tokenResult['status'] !== 'success') {
            return $tokenResult;
        }

        $payload = [
            'status' => 'all',
            'language' => strtoupper($language) === 'EN' ? 'EN' : 'TH',
            'barcode' => [$trackingNumber],
        ];

        $result = $this->requestJson($this->trackApiUrl, 'Token ' . $tokenResult['token'], $payload);
        if ($result['status'] !== 'success') {
            return $result;
        }

        return [
            'status' => 'success',
            'tracking_number' => $trackingNumber,
            'data' => $this->normalizeTrackingData($trackingNumber, $result['data']),
            'raw' => $result['data'],
        ];
    }

    private function requestToken(): array
    {
        $result = $this->requestJson($this->authUrl, 'Token ' . $this->apiKey);

        if ($result['status'] !== 'success') {
            return $result;
        }

        $token = $result['data']['token'] ?? null;
        if (!$token) {
            return [
                'status' => 'error',
                'message' => 'ไม่พบ token จาก Thailand Post',
                'raw' => $result['data'],
            ];
        }

        return ['status' => 'success', 'token' => $token];
    }

    private function requestJson(string $url, string $authorization, ?array $payload = null): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 'error', 'message' => 'เชื่อมต่อ Thailand Post ไม่สำเร็จ: ' . $curlError];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [
                'status' => 'error',
                'message' => 'Thailand Post ส่งข้อมูลกลับมาไม่ใช่ JSON',
                'http_code' => $httpCode,
                'raw' => $response,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'status' => 'error',
                'message' => $decoded['message'] ?? 'Thailand Post API error',
                'http_code' => $httpCode,
                'raw' => $decoded,
            ];
        }

        return ['status' => 'success', 'data' => $decoded, 'http_code' => $httpCode];
    }

    private function normalizeTrackingData(string $trackingNumber, array $raw): array
    {
        $response = $raw['response']['items'][$trackingNumber] ?? null;
        $items = is_array($response) ? $response : [];
        $history = [];

        foreach ($items as $item) {
            $date = trim((string) ($item['status_date'] ?? $item['datetime'] ?? ''));
            $time = trim((string) ($item['status_time'] ?? ''));
            $history[] = [
                'date' => trim($date . ' ' . $time),
                'location' => (string) ($item['location'] ?? $item['status_location'] ?? '-'),
                'status' => (string) ($item['status_description'] ?? $item['status'] ?? '-'),
                'postcode' => (string) ($item['postcode'] ?? ''),
            ];
        }

        $latest = $history[0] ?? null;

        return [
            'trackNumber' => $trackingNumber,
            'status' => $latest['status'] ?? 'ไม่พบข้อมูลสถานะ',
            'location' => $latest['location'] ?? '-',
            'date' => $latest['date'] ?? '-',
            'history' => $history,
            'found' => count($history) > 0,
        ];
    }
}

$thailand_post = new ThailandPostAPI();
