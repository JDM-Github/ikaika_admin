<?php

namespace App\Support\Portal;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Redeems an Azure AD authorization code on the server and validates the id_token against tenant JWKS.
 */
class PortalMicrosoftIdToken
{
    private const JWKS_TTL_SECONDS = 3600;

    private const CLOCK_SKEW_SECONDS = 60;

    public function isConfigured(): bool
    {
        return $this->tenantId() !== '' && $this->clientId() !== '';
    }

    /**
     * @return array{email: ?string, emails: list<string>, given_name: string, family_name: string, name_pairs: list<array{given: string, family: string}>, oid: ?string, name: ?string}|null
     */
    public function parse(string $idToken): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerSegment, $payloadSegment, $signatureSegment] = $parts;
        $header = $this->decodeJsonObject($headerSegment);
        $payload = $this->decodeJsonObject($payloadSegment);
        if ($header === null || $payload === null) {
            return null;
        }

        $alg = $header['alg'] ?? null;
        $kid = $header['kid'] ?? null;
        if ($alg !== 'RS256' || ! is_string($kid) || $kid === '') {
            return null;
        }

        $jwk = $this->jwkFor($kid);
        if ($jwk === null) {
            return null;
        }

        $pem = $this->publicKeyPem($jwk);
        if ($pem === null) {
            return null;
        }

        $signature = $this->base64UrlDecode($signatureSegment);
        if ($signature === null) {
            return null;
        }

        $verified = openssl_verify(
            $headerSegment.'.'.$payloadSegment,
            $signature,
            $pem,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            return null;
        }

        if (! $this->claimsAreValid($payload)) {
            return null;
        }

        $pairs = $this->namePairsFromClaims($payload);
        if ($pairs === []) {
            return null;
        }

        $emails = $this->emailsFromClaims($payload);
        $oid = $payload['oid'] ?? null;
        $displayName = $this->claimString($payload['name'] ?? null);

        return [
            'email' => $emails[0] ?? null,
            'emails' => $emails,
            'given_name' => $pairs[0]['given'],
            'family_name' => $pairs[0]['family'],
            'name_pairs' => $pairs,
            'oid' => is_string($oid) && trim($oid) !== '' ? trim($oid) : null,
            'name' => $displayName,
        ];
    }

    public function exchangeCode(string $code, string $verifier, string $redirectUri): ?string
    {
        if (! $this->isConfigured() || ! $this->redirectUriIsAllowed($redirectUri)) {
            return null;
        }

        $useOrigin = $this->clientSecret() === '';
        $response = $this->requestToken($code, $verifier, $redirectUri, $useOrigin);
        if ($useOrigin && $response !== null && $this->isSpaOriginRejected($response)) {
            $response = $this->requestToken($code, $verifier, $redirectUri, false);
        }

        if ($response === null) {
            return null;
        }

        if (! $response->successful()) {
            $this->logTokenFailure($response);

            return null;
        }

        $idToken = $response->json('id_token');
        if (! is_string($idToken) || $idToken === '') {
            Log::warning('portal.azure.token_failed', ['reason' => 'missing_id_token']);

            return null;
        }

        return $idToken;
    }

    private function requestToken(string $code, string $verifier, string $redirectUri, bool $sendOrigin): ?Response
    {
        $body = [
            'client_id' => $this->clientId(),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
            'scope' => 'openid profile email',
        ];
        $secret = $this->clientSecret();
        if ($secret !== '') {
            $body['client_secret'] = $secret;
        }

        try {
            $request = $this->azureHttp()
                ->asForm();
            if ($sendOrigin) {
                $request = $request->withHeaders(['Origin' => $redirectUri]);
            }

            return $request->post(
                'https://login.microsoftonline.com/'.$this->tenantId().'/oauth2/v2.0/token',
                $body,
            );
        } catch (ConnectionException $error) {
            Log::warning('portal.azure.token_failed', [
                'reason' => 'connection',
                'detail' => $error->getMessage(),
            ]);

            return null;
        }
    }

    private function isSpaOriginRejected(Response $response): bool
    {
        if ($response->successful()) {
            return false;
        }

        $codes = $response->json('error_codes');
        if (! is_array($codes)) {
            return false;
        }

        foreach ($codes as $code) {
            if ((int) $code === 9002326) {
                return true;
            }
        }

        return false;
    }

    private function logTokenFailure(Response $response): void
    {
        $error = $response->json('error');
        $codes = $response->json('error_codes');
        $safeCodes = [];
        if (is_array($codes)) {
            foreach ($codes as $code) {
                if (is_int($code) || is_float($code) || (is_string($code) && is_numeric($code))) {
                    $safeCodes[] = (int) $code;
                }
            }
        }

        Log::warning('portal.azure.token_failed', [
            'status' => $response->status(),
            'error' => is_string($error) ? $error : null,
            'error_codes' => $safeCodes,
        ]);
    }

    private function numericClaim(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function redirectUriIsAllowed(string $uri): bool
    {
        $configured = $this->normalizeRedirectUri($this->configuredRedirectUri());
        $incoming = $this->normalizeRedirectUri($uri);
        if ($configured === '' || $incoming === '') {
            return false;
        }

        return hash_equals(strtolower($configured), strtolower($incoming));
    }

    private function configuredRedirectUri(): string
    {
        return trim((string) config('services.portal_azure.redirect_uri'));
    }

    private function normalizeRedirectUri(string $uri): string
    {
        return rtrim(trim($uri), '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function claimsAreValid(array $payload): bool
    {
        $now = now()->getTimestamp();
        $exp = $this->numericClaim($payload['exp'] ?? null);
        if ($exp === null || $exp < ($now - self::CLOCK_SKEW_SECONDS)) {
            return false;
        }

        $nbf = $this->numericClaim($payload['nbf'] ?? null);
        if ($nbf !== null && $nbf > ($now + self::CLOCK_SKEW_SECONDS)) {
            return false;
        }

        if (! $this->audienceMatches($payload['aud'] ?? null)) {
            return false;
        }

        $issuer = $payload['iss'] ?? null;
        if (! is_string($issuer) || $issuer !== $this->issuer()) {
            return false;
        }

        $tid = $payload['tid'] ?? null;
        if (is_string($tid) && $tid !== '' && strcasecmp($tid, $this->tenantId()) !== 0) {
            return false;
        }

        return true;
    }

    private function audienceMatches(mixed $aud): bool
    {
        $clientId = $this->clientId();
        if (is_string($aud)) {
            return hash_equals($clientId, $aud);
        }
        if (! is_array($aud)) {
            return false;
        }

        foreach ($aud as $value) {
            if (is_string($value) && hash_equals($clientId, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<array{given: string, family: string}>
     */
    private function namePairsFromClaims(array $claims): array
    {
        $pairs = [];
        $seen = [];

        $given = $this->claimString($claims['given_name'] ?? null);
        $family = $this->claimString($claims['family_name'] ?? null);
        if ($given !== null && $family !== null) {
            $this->addNamePair($pairs, $seen, $given, $family);
            $this->addNameSplits($pairs, $seen, $given.' '.$family);
        }

        $display = $this->claimString($claims['name'] ?? null);
        if ($display !== null) {
            $this->addNameSplits($pairs, $seen, $display);
            if (str_contains($display, ',')) {
                $parts = explode(',', $display, 2);
                $this->addNamePair($pairs, $seen, $parts[1] ?? null, $parts[0] ?? null);
            }
        }

        return $pairs;
    }

    /**
     * @param  list<array{given: string, family: string}>  $pairs
     * @param  array<string, true>  $seen
     */
    private function addNameSplits(array &$pairs, array &$seen, string $display): void
    {
        $tokens = preg_split('/\s+/u', $display);
        if (! is_array($tokens)) {
            return;
        }
        $tokens = array_values(array_filter($tokens, fn (string $token): bool => $token !== ''));
        $count = count($tokens);
        if ($count < 2) {
            return;
        }

        for ($index = 1; $index < $count; $index++) {
            $this->addNamePair(
                $pairs,
                $seen,
                implode(' ', array_slice($tokens, 0, $index)),
                implode(' ', array_slice($tokens, $index)),
            );
        }
    }

    /**
     * @param  list<array{given: string, family: string}>  $pairs
     * @param  array<string, true>  $seen
     */
    private function addNamePair(array &$pairs, array &$seen, mixed $given, mixed $family): void
    {
        $givenName = $this->claimString(is_string($given) ? $given : null);
        $familyName = $this->claimString(is_string($family) ? $family : null);
        if ($givenName === null || $familyName === null) {
            return;
        }

        $key = strtolower($givenName)."\n".strtolower($familyName);
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $pairs[] = ['given' => $givenName, 'family' => $familyName];
    }

    private function claimString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $collapsed === '' ? null : $collapsed;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    private function emailsFromClaims(array $claims): array
    {
        $emails = [];
        $seen = [];
        foreach (['email', 'preferred_username', 'upn', 'unique_name'] as $key) {
            $value = $claims[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }
            foreach ($this->emailsFromValue($value) as $email) {
                $lower = strtolower($email);
                if (isset($seen[$lower])) {
                    continue;
                }
                $seen[$lower] = true;
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * @return list<string>
     */
    private function emailsFromValue(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $emails = [];
        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $trimmed;
        }

        $guest = $this->emailFromGuestUpn($trimmed);
        if ($guest !== null) {
            $emails[] = $guest;
        }

        return $emails;
    }

    private function emailFromGuestUpn(string $value): ?string
    {
        $marker = stripos($value, '#EXT#@');
        if ($marker === false) {
            return null;
        }

        $local = substr($value, 0, $marker);
        $separator = strrpos($local, '_');
        if ($separator === false) {
            return null;
        }

        $email = substr($local, 0, $separator).'@'.substr($local, $separator + 1);
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jwkFor(string $kid): ?array
    {
        $jwks = $this->jwks();
        if ($jwks === null) {
            return null;
        }

        foreach ($jwks as $key) {
            if (! is_array($key)) {
                continue;
            }
            if (($key['kid'] ?? null) === $kid && ($key['kty'] ?? null) === 'RSA') {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function jwks(): ?array
    {
        $tenant = $this->tenantId();
        $cacheKey = 'portal:azure:jwks:'.$tenant;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return array_values(array_filter($cached, is_array(...)));
        }

        try {
            $response = $this->azureHttp()
                ->get('https://login.microsoftonline.com/'.$tenant.'/discovery/v2.0/keys');
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $keys = $response->json('keys');
        if (! is_array($keys) || $keys === []) {
            return null;
        }

        $jwks = array_values(array_filter($keys, is_array(...)));
        Cache::put($cacheKey, $jwks, self::JWKS_TTL_SECONDS);

        return $jwks;
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    private function publicKeyPem(array $jwk): ?string
    {
        $n = $jwk['n'] ?? null;
        $e = $jwk['e'] ?? null;
        if (! is_string($n) || ! is_string($e) || $n === '' || $e === '') {
            return null;
        }

        $modulus = $this->base64UrlDecode($n);
        $exponent = $this->base64UrlDecode($e);
        if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
            return null;
        }

        $rsaKey = $this->asn1Sequence(
            $this->asn1UnsignedInteger($modulus).$this->asn1UnsignedInteger($exponent),
        );
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        if ($algorithm === false) {
            return null;
        }
        $spki = $this->asn1Sequence($algorithm.$this->asn1(0x03, "\x00".$rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private function asn1UnsignedInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return $this->asn1(0x02, $bytes);
    }

    private function asn1Sequence(string $contents): string
    {
        return $this->asn1(0x30, $contents);
    }

    private function asn1(int $tag, string $contents): string
    {
        $length = strlen($contents);
        if ($length < 0x80) {
            return chr($tag).chr($length).$contents;
        }

        $lengthBytes = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($lengthBytes)).$lengthBytes.$contents;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $segment): ?array
    {
        $json = $this->base64UrlDecode($segment);
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    private function azureHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(10)
            ->timeout(15)
            ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()]);
    }

    private function tenantId(): string
    {
        return trim((string) config('services.portal_azure.tenant_id'));
    }

    private function clientId(): string
    {
        return trim((string) config('services.portal_azure.client_id'));
    }

    private function clientSecret(): string
    {
        return trim((string) config('services.portal_azure.client_secret'));
    }

    private function issuer(): string
    {
        return 'https://login.microsoftonline.com/'.$this->tenantId().'/v2.0';
    }
}
