<?php

namespace App\Support\Portal;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Signed uploads to Cloudinary for reimbursement receipts, and the images Send Email puts in a
 * message (listed and deleted by folder, since an email only holds their link).
 *
 * The API secret stays here. The portal only ever sees a HTTPS URL and a file name -- putting the
 * secret in the app would leak it to every member who opened a bundle.
 *
 * Windows PHP often ships without curl.cainfo / openssl.cafile. Verify against Composer's CA
 * bundle so Cloudinary HTTPS works without a machine-wide php.ini edit.
 */
final class PortalCloudinary
{
    public const FOLDER = 'ikaika-portal/reimbursements';

    /**
     * @return array{fileUrl: string, fileName: string, sizeBytes: int, mimeType: string}
     */
    public function uploadReceipt(UploadedFile $file, string $folder = self::FOLDER, ?string $asName = null): array
    {
        $cloud = $this->cloudName();
        $key = $this->apiKey();
        $secret = $this->apiSecret();
        if ($cloud === null || $key === null || $secret === null) {
            abort(503, 'Receipt upload is not configured.');
        }

        $timestamp = time();
        $signature = sha1('folder='.$folder.'&timestamp='.$timestamp.$secret);
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            abort(422, 'Choose an image or PDF receipt.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            abort(422, 'Choose an image or PDF receipt.');
        }

        // A name the caller gives instead of the device's: a bug report's screenshot must not carry its sender's.
        $name = $asName ?? $file->getClientOriginalName();
        $uploadName = $name !== '' ? $name : 'receipt';

        try {
            $response = Http::timeout(60)
                ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                ->attach('file', $contents, $uploadName)
                ->post('https://api.cloudinary.com/v1_1/'.$cloud.'/auto/upload', [
                    'api_key' => $key,
                    'timestamp' => $timestamp,
                    'signature' => $signature,
                    'folder' => $folder,
                ]);
        } catch (Throwable $error) {
            Log::warning('portal.cloudinary.upload_failed', [
                'reason' => $error->getMessage(),
            ]);
            abort(502, 'The receipt could not be stored.');
        }

        if (! $response->successful()) {
            Log::warning('portal.cloudinary.upload_rejected', [
                'status' => $response->status(),
                'error' => $response->json('error.message') ?? $response->body(),
            ]);
            abort(502, 'The receipt could not be stored.');
        }

        $url = $response->json('secure_url');
        if (! is_string($url) || $url === '' || ! $this->isOwnedUrl($url)) {
            Log::warning('portal.cloudinary.upload_rejected', [
                'status' => $response->status(),
                'error' => 'Missing or foreign secure_url.',
            ]);
            abort(502, 'The receipt could not be stored.');
        }

        $mime = $file->getMimeType();

        return [
            'fileUrl' => $url,
            'fileName' => $uploadName,
            'sizeBytes' => (int) $file->getSize(),
            'mimeType' => is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
        ];
    }

    /**
     * One image under a public id the caller chose (its folder is part of it), so it can be listed
     * and deleted by that folder later.
     *
     * @return array{publicId: string, url: string, width: int, height: int, bytes: int, createdAt: string}
     */
    public function uploadImage(UploadedFile $file, string $publicId): array
    {
        [$cloud, $key, $secret] = $this->credentials('Image upload is not configured.');
        $path = $file->getRealPath();
        $contents = is_string($path) && $path !== '' ? file_get_contents($path) : false;
        if ($contents === false) {
            abort(422, 'Choose an image.');
        }

        $timestamp = time();
        try {
            $response = $this->client()
                ->attach('file', $contents, $file->getClientOriginalName() !== '' ? $file->getClientOriginalName() : 'image')
                ->post('https://api.cloudinary.com/v1_1/'.$cloud.'/image/upload', [
                    'api_key' => $key,
                    'timestamp' => $timestamp,
                    'public_id' => $publicId,
                    'signature' => $this->sign(['public_id' => $publicId, 'timestamp' => $timestamp], $secret),
                ]);
        } catch (Throwable $error) {
            Log::warning('portal.cloudinary.image_upload_failed', ['reason' => $error->getMessage()]);
            abort(502, 'The image could not be stored.');
        }

        $url = $response->json('secure_url');
        if (! $response->successful() || ! is_string($url) || ! $this->isOwnedUrl($url)) {
            Log::warning('portal.cloudinary.image_upload_rejected', [
                'status' => $response->status(),
                'error' => $response->json('error.message') ?? 'Missing or foreign secure_url.',
            ]);
            abort(502, 'The image could not be stored.');
        }

        return self::image((array) $response->json());
    }

    /**
     * Every image whose public id starts with $prefix, newest first. One page of 100 is the whole
     * of a person's email images in practice.
     *
     * @return list<array{publicId: string, url: string, width: int, height: int, bytes: int, createdAt: string}>
     */
    public function listImages(string $prefix): array
    {
        [$cloud, $key, $secret] = $this->credentials('Image upload is not configured.');
        try {
            $response = $this->client()
                ->withBasicAuth($key, $secret)
                ->get('https://api.cloudinary.com/v1_1/'.$cloud.'/resources/image/upload', [
                    'prefix' => $prefix,
                    'max_results' => 100,
                ]);
        } catch (Throwable $error) {
            Log::warning('portal.cloudinary.image_list_failed', ['reason' => $error->getMessage()]);
            abort(502, 'The images could not be loaded.');
        }
        if (! $response->successful()) {
            Log::warning('portal.cloudinary.image_list_rejected', ['status' => $response->status()]);
            abort(502, 'The images could not be loaded.');
        }

        $images = [];
        foreach ((array) $response->json('resources', []) as $resource) {
            if (is_array($resource) && is_string($resource['secure_url'] ?? null) && $this->isOwnedUrl($resource['secure_url'])) {
                $images[] = self::image($resource);
            }
        }
        usort($images, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));

        return $images;
    }

    public function destroyImage(string $publicId): void
    {
        [$cloud, $key, $secret] = $this->credentials('Image upload is not configured.');
        $timestamp = time();
        try {
            $response = $this->client()->asForm()->post('https://api.cloudinary.com/v1_1/'.$cloud.'/image/destroy', [
                'api_key' => $key,
                'timestamp' => $timestamp,
                'public_id' => $publicId,
                'signature' => $this->sign(['public_id' => $publicId, 'timestamp' => $timestamp], $secret),
            ]);
        } catch (Throwable $error) {
            Log::warning('portal.cloudinary.image_delete_failed', ['reason' => $error->getMessage()]);
            abort(502, 'The image could not be deleted.');
        }
        // "not found" means it is already gone, which is what a delete wants.
        $result = $response->json('result');
        if (! $response->successful() || ! in_array($result, ['ok', 'not found'], true)) {
            Log::warning('portal.cloudinary.image_delete_rejected', ['status' => $response->status()]);
            abort(502, 'The image could not be deleted.');
        }
    }

    // A stored file's bytes (2026-10-19: a screenshot handed on to Jira), only from this account's own delivery URLs.
    public function download(string $url): string
    {
        if (! $this->isOwnedUrl($url)) {
            throw new RuntimeException('That file is not one of the portal\'s.');
        }
        try {
            $response = $this->client()->get($url);
        } catch (Throwable) {
            throw new RuntimeException('Cloudinary did not answer.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Cloudinary HTTP '.$response->status());
        }

        return $response->body();
    }

    public function isOwnedUrl(string $url): bool
    {
        $cloud = $this->cloudName();
        if ($cloud === null) {
            return false;
        }

        return str_starts_with($url, 'https://res.cloudinary.com/'.$cloud.'/');
    }

    // A delivery link with a transformation of its own in front of the file (2026-10-14): an ID card's photo, say.
    public function transformed(string $url, string $transform): string
    {
        $changed = preg_replace('#/upload/#', '/upload/'.$transform.'/', $url, 1);

        return is_string($changed) ? $changed : $url;
    }

    // A PDF's first page as a small picture, for a list of documents (2026-10-14).
    public function pdfThumbUrl(string $url): ?string
    {
        return str_contains($url, '/upload/') ? $this->transformed($url, 'pg_1,c_fill,h_96,w_96,f_jpg,q_auto') : null;
    }

    // A PDF's pages as pictures to read in the portal (2026-10-14): {page} stands for the page, counted from 1.
    public function pdfPagesUrl(string $url): ?string
    {
        return str_contains($url, '/upload/') ? $this->transformed($url, 'pg_{page},f_jpg,w_1400,q_auto') : null;
    }

    public function thumbUrl(string $url, string $mimeType): ?string
    {
        if (! str_starts_with($mimeType, 'image/')) {
            return null;
        }
        if (! str_contains($url, '/upload/')) {
            return null;
        }

        $thumb = preg_replace('#/upload/#', '/upload/c_fill,h_96,w_96,f_auto,q_auto/', $url, 1);

        return is_string($thumb) ? $thumb : null;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array{publicId: string, url: string, width: int, height: int, bytes: int, createdAt: string}
     */
    private static function image(array $resource): array
    {
        return [
            'publicId' => (string) ($resource['public_id'] ?? ''),
            'url' => (string) ($resource['secure_url'] ?? ''),
            'width' => (int) ($resource['width'] ?? 0),
            'height' => (int) ($resource['height'] ?? 0),
            'bytes' => (int) ($resource['bytes'] ?? 0),
            'createdAt' => (string) ($resource['created_at'] ?? ''),
        ];
    }

    // Cloudinary's signature: the parameters sorted by name, joined as a query string, then the secret.
    /**
     * @param  array<string, string|int>  $params
     */
    private function sign(array $params, string $secret): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $name.'='.$value;
        }

        return sha1(implode('&', $pairs).$secret);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function credentials(string $missing): array
    {
        $cloud = $this->cloudName();
        $key = $this->apiKey();
        $secret = $this->apiSecret();
        if ($cloud === null || $key === null || $secret === null) {
            abort(503, $missing);
        }

        return [$cloud, $key, $secret];
    }

    private function client(): PendingRequest
    {
        return Http::timeout(60)->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()]);
    }

    private function cloudName(): ?string
    {
        return $this->configString('services.cloudinary.cloud_name');
    }

    private function apiKey(): ?string
    {
        return $this->configString('services.cloudinary.api_key');
    }

    private function apiSecret(): ?string
    {
        return $this->configString('services.cloudinary.api_secret');
    }

    private function configString(string $key): ?string
    {
        $value = config($key);
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
