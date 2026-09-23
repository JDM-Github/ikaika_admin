<?php

namespace App\Support\Portal;

use Composer\CaBundle\CaBundle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signed uploads to Cloudinary for reimbursement receipts.
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
    public function uploadReceipt(UploadedFile $file): array
    {
        $cloud = $this->cloudName();
        $key = $this->apiKey();
        $secret = $this->apiSecret();
        if ($cloud === null || $key === null || $secret === null) {
            abort(503, 'Receipt upload is not configured.');
        }

        $timestamp = time();
        $folder = self::FOLDER;
        $signature = sha1('folder='.$folder.'&timestamp='.$timestamp.$secret);
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            abort(422, 'Choose an image or PDF receipt.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            abort(422, 'Choose an image or PDF receipt.');
        }

        $name = $file->getClientOriginalName();
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

    public function isOwnedUrl(string $url): bool
    {
        $cloud = $this->cloudName();
        if ($cloud === null) {
            return false;
        }

        return str_starts_with($url, 'https://res.cloudinary.com/'.$cloud.'/');
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
