<?php

namespace App\Support;

use RuntimeException;
use UnexpectedValueException;

class PortalJwt
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function issue(array $claims): string
    {
        $now = now()->getTimestamp();
        $ttl = $this->ttlSeconds();
        $payload = array_merge($claims, [
            'iss' => 'ikaika-platform',
            'aud' => 'portal',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ]);

        $header = $this->encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = $this->encode($payload);
        $signature = $this->sign("{$header}.{$body}");

        return "{$header}.{$body}.{$signature}";
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new UnexpectedValueException('Malformed token.');
        }

        [$header, $body, $signature] = $parts;
        if (! hash_equals($this->sign("{$header}.{$body}"), $signature)) {
            throw new UnexpectedValueException('Invalid token signature.');
        }

        $payload = json_decode($this->decode($body), true);
        if (! is_array($payload)) {
            throw new UnexpectedValueException('Malformed token payload.');
        }

        $expiresAt = $payload['exp'] ?? null;
        if (! is_int($expiresAt) && ! is_float($expiresAt)) {
            throw new UnexpectedValueException('Token is missing an expiry.');
        }
        if ((int) $expiresAt < now()->getTimestamp()) {
            throw new UnexpectedValueException('Token has expired.');
        }

        return $payload;
    }

    public function ttlSeconds(): int
    {
        return max(60, (int) config('products.jwt_ttl', 28800));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode JWT segment.');
        }

        return $this->toBase64Url($json);
    }

    private function sign(string $data): string
    {
        return $this->toBase64Url(hash_hmac('sha256', $data, $this->secret(), true));
    }

    private function decode(string $segment): string
    {
        $remainder = strlen($segment) % 4;
        if ($remainder > 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if (! is_string($decoded)) {
            throw new UnexpectedValueException('Malformed token segment.');
        }

        return $decoded;
    }

    private function toBase64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function secret(): string
    {
        $secret = (string) (config('products.jwt_secret') ?: config('app.key'));
        if ($secret === '') {
            throw new RuntimeException('Portal JWT secret is not configured.');
        }

        return $secret;
    }
}
