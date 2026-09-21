<?php

namespace App\Services\GoogleSheets;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleServiceAccountTokenProvider
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    public function token(): string
    {
        $credentials = $this->credentials();
        $email = trim((string) ($credentials['client_email'] ?? ''));

        return Cache::remember(
            'google-sheets-access-token-'.md5($email),
            now()->addMinutes(55),
            fn (): string => $this->requestToken($credentials),
        );
    }

    /** @return array<string,mixed> */
    private function credentials(): array
    {
        $json = trim((string) config('google_sheets.service_account_json'));
        if ($json === '') {
            $encoded = trim((string) config('google_sheets.service_account_json_base64'));
            if ($encoded !== '') {
                $json = base64_decode($encoded, true) ?: '';
            }
        }
        if ($json === '') {
            $path = trim((string) config('google_sheets.service_account_json_path'));
            if ($path !== '' && is_file($path)) {
                $json = (string) file_get_contents($path);
            }
        }
        $credentials = json_decode($json, true);
        if (! is_array($credentials)) {
            throw new \RuntimeException('Google Sheets service-account JSON is not configured or is invalid.');
        }

        return $credentials;
    }

    /** @param array<string,mixed> $credentials */
    private function requestToken(array $credentials): string
    {
        $email = trim((string) ($credentials['client_email'] ?? ''));
        $privateKey = str_replace('\\n', "\n", trim((string) ($credentials['private_key'] ?? '')));
        if ($email === '' || $privateKey === '') {
            throw new \RuntimeException('Google credentials must include client_email and private_key.');
        }
        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $email, 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL,
            'iat' => $now, 'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $signature = '';
        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign the Google Sheets service-account assertion.');
        }
        $response = Http::asForm()->acceptJson()->timeout($this->timeout())->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$this->base64Url($signature),
        ]);
        if ($response->failed() || trim((string) $response->json('access_token')) === '') {
            throw new \RuntimeException('Google Sheets token request failed: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    private function timeout(): int
    {
        return max(5, (int) config('google_sheets.timeout_seconds', 60));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
