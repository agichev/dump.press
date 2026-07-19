<?php
declare(strict_types=1);

function r2_config(): array {
    $accessKey = env('R2_ACCESS_KEY', '');
    $secretKey = env('R2_SECRET_KEY', '');
    if ($accessKey === '' || $secretKey === '') {
        throw new RuntimeException(
            'R2 credentials missing. Set R2_ACCESS_KEY and R2_SECRET_KEY in .env.'
        );
    }
    return [
        'endpoint'   => rtrim(env('R2_ENDPOINT', 'https://' . env('R2_ACCOUNT_ID', '') . '.r2.cloudflarestorage.com'), '/'),
        'bucket'     => env('R2_BUCKET', 'dump'),
        'access_key' => $accessKey,
        'secret_key' => $secretKey,
        'region'     => env('R2_REGION', 'auto'),
    ];
}

function r2_signature_key(string $secret, string $date, string $region, string $service): string {
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function r2_presigned_url(string $method, string $key, int $expires = 3600, string $contentType = '', array $extraParams = []): string {
    $cfg = r2_config();
    $endpoint = rtrim($cfg['endpoint'], '/');
    $bucket = $cfg['bucket'];
    $accessKey = $cfg['access_key'];
    $secretKey = $cfg['secret_key'];
    $region = $cfg['region'];
    $service = 's3';

    $host = parse_url($endpoint, PHP_URL_HOST);
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');

    $credential = "$accessKey/$dateStamp/$region/$service/aws4_request";
    $signedHeaders = 'host';

    $queryParams = [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => $credential,
        'X-Amz-Date' => $amzDate,
        'X-Amz-Expires' => (string)$expires,
        'X-Amz-SignedHeaders' => $signedHeaders,
    ];
    foreach ($extraParams as $k => $v) {
        $queryParams[$k] = $v;
    }
    ksort($queryParams);
    $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

    $canonicalHeaders = "host:$host\n";
    $canonicalHeaders .= "\n";

    $canonicalRequest = "$method\n/$bucket/$key\n$canonicalQueryString\n$canonicalHeaders$signedHeaders\nUNSIGNED-PAYLOAD";

    $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$dateStamp/$region/$service/aws4_request\n" . hash('sha256', $canonicalRequest);

    $signingKey = r2_signature_key($secretKey, $dateStamp, $region, $service);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $url = "$endpoint/$bucket/$key?" . $canonicalQueryString . '&X-Amz-Signature=' . $signature;

    return $url;
}

function r2_delete_object(string $key): bool {
    $cfg = r2_config();
    $host = parse_url(rtrim($cfg['endpoint'], '/'), PHP_URL_HOST);
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $accessKey = $cfg['access_key'];
    $secretKey = $cfg['secret_key'];
    $region = $cfg['region'];
    $service = 's3';

    $credential = "$accessKey/$dateStamp/$region/$service/aws4_request";
    $signedHeaders = 'host;x-amz-content-sha256';

    $canonicalRequest = "DELETE\n/{$cfg['bucket']}/$key\n\nhost:$host\nx-amz-content-sha256:UNSIGNED-PAYLOAD\n\n$signedHeaders\nUNSIGNED-PAYLOAD";
    $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$dateStamp/$region/$service/aws4_request\n" . hash('sha256', $canonicalRequest);
    $signingKey = r2_signature_key($secretKey, $dateStamp, $region, $service);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $url = rtrim($cfg['endpoint'], '/') . "/{$cfg['bucket']}/$key";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "Host: $host",
            "X-Amz-Date: $amzDate",
            "X-Amz-Content-Sha256: UNSIGNED-PAYLOAD",
            "Authorization: AWS4-HMAC-SHA256 Credential=$credential,SignedHeaders=$signedHeaders,Signature=$signature",
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function r2_public_url(string $key): string {
    $cfg = r2_config();
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '\\/');
    return $basePath . '/index.php?api=file_download&key=' . urlencode($key);
}
