<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Send Email's images: the pictures an administrator puts in a message, kept on Cloudinary. Two
 * scopes: "mine" under email-upload/{employee id}/, which each person sees, reuses and deletes alone,
 * and "global" under email-upload/global/, which every Send Email user can pick from (the company
 * logo lives there).
 *
 * A folder always comes from the scope and the signed-in employee, never from a path in the request,
 * and an image is named only by the id inside it -- so no one reaches another person's images.
 * Adding to or deleting from global is the Global images power (email.images.global): executives hold
 * it by preset, developers in Developer mode, and an admin only when given it (see
 * docs/power/email-global-images.md). An email holds just the image's link, so deleting one breaks it
 * in every email already sent; the page warns before it asks for this.
 */
final class PortalEmailImages
{
    public const ROOT = 'email-upload';

    public const SCOPE_MINE = 'mine';

    public const SCOPE_GLOBAL = 'global';

    private const GLOBAL_RULE = 'email.images.global';

    private const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @var list<string>
     */
    private const MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly PortalCloudinary $cloudinary,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{section: string, resource: string, data: list<array<string, mixed>>, canManage: bool}
     */
    public function list(Employee $actor, Request $request): array
    {
        $scope = self::scope($request);
        $images = Cache::remember(
            $this->cacheKey($actor, $scope),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->cloudinary->listImages($this->folder($actor, $scope).'/'),
        );

        return [
            'section' => 'administration',
            'resource' => 'email-images',
            'data' => array_map(fn (array $image): array => $this->present($image), $images),
            // Whether this person may add to or delete from what they are looking at.
            'canManage' => $this->canManage($actor, $request, $scope),
        ];
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function store(Employee $actor, Request $request): array
    {
        $scope = self::scope($request);
        $this->assertCanManage($actor, $request, $scope);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            abort(422, 'Choose an image.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            abort(422, 'An email image cannot be larger than 5 MB.');
        }
        $mime = $file->getMimeType();
        if (! is_string($mime) || ! in_array($mime, self::MIMES, true)) {
            abort(422, 'An email image has to be a JPEG, PNG, GIF or WebP.');
        }

        $id = (string) Str::uuid();
        $image = $this->cloudinary->uploadImage($file, $this->folder($actor, $scope).'/'.$id);
        Cache::forget($this->cacheKey($actor, $scope));
        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            PortalEmail::CORE_RESOURCE,
            PortalActivityCopy::uploadedEmailImage($scope === self::SCOPE_GLOBAL),
            $id,
            $request,
            ['image' => $id, 'scope' => $scope],
        );

        return [
            'section' => 'administration',
            'resource' => 'email-images',
            'data' => $this->present($image),
        ];
    }

    public function destroy(Employee $actor, Request $request, string $id): void
    {
        $scope = self::scope($request);
        $this->assertCanManage($actor, $request, $scope);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id) !== 1) {
            abort(404, 'That image is not one of yours.');
        }
        $this->cloudinary->destroyImage($this->folder($actor, $scope).'/'.$id);
        Cache::forget($this->cacheKey($actor, $scope));
        $this->audit->record(
            $actor,
            PortalLogAction::DELETE,
            PortalEmail::CORE_RESOURCE,
            PortalActivityCopy::deletedEmailImage($scope === self::SCOPE_GLOBAL),
            $id,
            $request,
            ['image' => $id, 'scope' => $scope],
        );
    }

    public function folder(Employee $actor, string $scope = self::SCOPE_MINE): string
    {
        return $scope === self::SCOPE_GLOBAL
            ? self::ROOT.'/'.self::SCOPE_GLOBAL
            : self::ROOT.'/'.(int) $actor->getKey();
    }

    public static function scope(Request $request): string
    {
        return $request->input('scope') === self::SCOPE_GLOBAL ? self::SCOPE_GLOBAL : self::SCOPE_MINE;
    }

    // Everyone manages their own; global follows its power, which Developer mode holds like every rule.
    private function canManage(Employee $actor, Request $request, string $scope): bool
    {
        return $scope === self::SCOPE_MINE || PortalRules::holds($actor, $request, self::GLOBAL_RULE);
    }

    private function assertCanManage(Employee $actor, Request $request, string $scope): void
    {
        if (! $this->canManage($actor, $request, $scope)) {
            abort(403, 'Adding or deleting a global image needs the Global images power.');
        }
    }

    /**
     * @param  array{publicId: string, url: string, width: int, height: int, bytes: int, createdAt: string}  $image
     * @return array{id: string, url: string, thumbUrl: string, width: int, height: int, bytes: int, createdAt: string}
     */
    private function present(array $image): array
    {
        $id = (string) Str::afterLast($image['publicId'], '/');
        $thumb = preg_replace('#/upload/#', '/upload/c_fill,h_160,w_220,f_auto,q_auto/', $image['url'], 1);

        return [
            'id' => $id,
            'url' => $image['url'],
            'thumbUrl' => is_string($thumb) ? $thumb : $image['url'],
            'width' => $image['width'],
            'height' => $image['height'],
            'bytes' => $image['bytes'],
            'createdAt' => $image['createdAt'],
        ];
    }

    private function cacheKey(Employee $actor, string $scope): string
    {
        return 'portal:administration:email:images:'.($scope === self::SCOPE_GLOBAL ? self::SCOPE_GLOBAL : (int) $actor->getKey());
    }
}
