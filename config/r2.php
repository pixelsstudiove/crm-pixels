<?php
// config/r2.php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function r2_config(): array {
  $accountId = trim((string) app_config('r2.account_id', ''));
  $endpoint = rtrim(trim((string) app_config('r2.endpoint', '')), '/');
  if ($endpoint === '' && $accountId !== '') {
    $endpoint = 'https://' . $accountId . '.r2.cloudflarestorage.com';
  }
  return [
    'account_id' => $accountId,
    'access_key_id' => trim((string) app_config('r2.access_key_id', '')),
    'secret_access_key' => trim((string) app_config('r2.secret_access_key', '')),
    'bucket' => trim((string) app_config('r2.bucket', '')),
    'endpoint' => $endpoint,
    'region' => trim((string) app_config('r2.region', 'auto')) ?: 'auto',
    'public_base_url' => rtrim(trim((string) app_config('r2.public_base_url', '')), '/'),
  ];
}

function r2_is_configured(): bool {
  $cfg = r2_config();
  return $cfg['access_key_id'] !== '' && $cfg['secret_access_key'] !== '' && $cfg['bucket'] !== '' && $cfg['endpoint'] !== '';
}

function r2_safe_key_part(string $value): string {
  $value = strtolower(trim($value));
  $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
  $value = trim($value, '.-_/');
  return $value !== '' ? $value : 'file';
}

function r2_extension_from_mime(string $mime): string {
  return match (strtolower(trim($mime))) {
    'image/jpeg', 'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'audio/mpeg', 'audio/mp3' => 'mp3',
    'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'video/mp4' => 'm4a',
    'audio/aac' => 'aac',
    'audio/ogg', 'application/ogg' => 'ogg',
    'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave' => 'wav',
    'audio/webm', 'video/webm' => 'webm',
    'audio/3gpp' => '3gp',
    default => 'bin',
  };
}

function r2_random_key(string $prefix, string $mime): string {
  $prefix = trim($prefix, '/');
  $datePath = gmdate('Y/m/d');
  $ext = r2_extension_from_mime($mime);
  return ($prefix !== '' ? $prefix . '/' : '') . $datePath . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
}

function r2_signing_key(string $secret, string $date, string $region): string {
  $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
  $kRegion = hash_hmac('sha256', $region, $kDate, true);
  $kService = hash_hmac('sha256', 's3', $kRegion, true);
  return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function r2_endpoint_parts(array $cfg): array {
  $parts = parse_url((string) $cfg['endpoint']);
  $scheme = (string) ($parts['scheme'] ?? 'https');
  $host = (string) ($parts['host'] ?? '');
  if ($host === '') throw new RuntimeException('Endpoint R2 invalido.');
  return [$scheme, $host];
}

function r2_canonical_uri(array $cfg, string $key): string {
  $segments = array_map('rawurlencode', explode('/', ltrim($key, '/')));
  return '/' . rawurlencode((string) $cfg['bucket']) . '/' . implode('/', $segments);
}

function r2_upload_bytes(string $key, string $bytes, string $mime): array {
  if (!r2_is_configured()) return ['ok' => false, 'error' => 'R2 no esta configurado.'];
  $cfg = r2_config();
  [$scheme, $host] = r2_endpoint_parts($cfg);
  $now = gmdate('Ymd\THis\Z');
  $date = gmdate('Ymd');
  $region = (string) $cfg['region'];
  $payloadHash = hash('sha256', $bytes);
  $canonicalUri = r2_canonical_uri($cfg, $key);
  $headers = [
    'content-type' => $mime,
    'host' => $host,
    'x-amz-content-sha256' => $payloadHash,
    'x-amz-date' => $now,
  ];
  ksort($headers);
  $canonicalHeaders = '';
  foreach ($headers as $name => $value) $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
  $signedHeaders = implode(';', array_keys($headers));
  $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
  $credentialScope = "{$date}/{$region}/s3/aws4_request";
  $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
  $signature = hash_hmac('sha256', $stringToSign, r2_signing_key((string) $cfg['secret_access_key'], $date, $region));
  $authorization = 'AWS4-HMAC-SHA256 Credential=' . $cfg['access_key_id'] . '/' . $credentialScope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

  $url = $scheme . '://' . $host . $canonicalUri;
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $bytes,
    CURLOPT_HTTPHEADER => [
      'Authorization: ' . $authorization,
      'Content-Type: ' . $mime,
      'Host: ' . $host,
      'X-Amz-Content-Sha256: ' . $payloadHash,
      'X-Amz-Date: ' . $now,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45,
  ]);
  $raw = curl_exec($ch);
  $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);
  if ($error !== '' || $http < 200 || $http >= 300) {
    return ['ok' => false, 'http' => $http, 'error' => $error !== '' ? $error : 'R2 rechazo la subida.', 'raw' => $raw];
  }
  return ['ok' => true, 'key' => $key, 'size' => strlen($bytes), 'mime' => $mime];
}

function r2_presigned_url(string $key, int $expires = 600): ?string {
  if (!r2_is_configured()) return null;
  $cfg = r2_config();
  [$scheme, $host] = r2_endpoint_parts($cfg);
  $expires = max(60, min(604800, $expires));
  $now = gmdate('Ymd\THis\Z');
  $date = gmdate('Ymd');
  $region = (string) $cfg['region'];
  $credentialScope = "{$date}/{$region}/s3/aws4_request";
  $credential = (string) $cfg['access_key_id'] . '/' . $credentialScope;
  $canonicalUri = r2_canonical_uri($cfg, $key);
  $query = [
    'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
    'X-Amz-Credential' => $credential,
    'X-Amz-Date' => $now,
    'X-Amz-Expires' => (string) $expires,
    'X-Amz-SignedHeaders' => 'host',
  ];
  ksort($query);
  $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQuery}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
  $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
  $signature = hash_hmac('sha256', $stringToSign, r2_signing_key((string) $cfg['secret_access_key'], $date, $region));
  return $scheme . '://' . $host . $canonicalUri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
}
