<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsPortalEmployee;
use Tests\TestCase;

/**
 * Send Email's images live in Cloudinary under email-upload/{employee id}/, and the shared ones under
 * email-upload/global/. What is pinned here is that the folder always comes from the signed-in person
 * or the scope, so no one lists, uploads into or deletes from another person's folder; that only a
 * developer in Developer mode changes global for now; and that the secret never leaves the server.
 */
class PortalEmailImagesTest extends TestCase
{
    use ActsAsPortalEmployee;
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    private const BASE = '/api/development/portal/administration/email-images';

    private const CLOUD = 'https://api.cloudinary.com/v1_1/demo-cloud';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.cloudinary.cloud_name' => 'demo-cloud',
            'services.cloudinary.api_key' => 'key123',
            'services.cloudinary.api_secret' => 'secret456',
        ]);
    }

    // Signing in also looks up the caller's location over HTTP, so only Cloudinary calls are counted.
    private function cloudinaryCalls(): int
    {
        return Http::recorded(fn (HttpRequest $request): bool => str_starts_with($request->url(), self::CLOUD))->count();
    }

    // A developer signed in with Developer mode on, the only one who may change global for now.
    private function developerHeaders(): array
    {
        $admin = $this->administrator();
        $admin->forceFill(['is_developer' => true])->save();
        $headers = $this->headersFor($admin);
        $this->developerModeOn(substr($headers['Authorization'], strlen('Bearer ')));

        return $headers;
    }

    private function image(string $publicId, string $createdAt): array
    {
        return [
            'public_id' => $publicId,
            'secure_url' => 'https://res.cloudinary.com/demo-cloud/image/upload/v1/'.$publicId.'.png',
            'width' => 600,
            'height' => 300,
            'bytes' => 1234,
            'created_at' => $createdAt,
        ];
    }

    public function test_it_lists_only_the_signed_in_person_folder_newest_first(): void
    {
        $admin = $this->administrator();
        $folder = 'email-upload/'.$admin->getKey();
        $older = '11111111-1111-4111-8111-111111111111';
        $newer = '22222222-2222-4222-8222-222222222222';
        Http::fake([self::CLOUD.'/resources/image/upload*' => Http::response(['resources' => [
            $this->image($folder.'/'.$older, '2026-09-01T00:00:00Z'),
            $this->image($folder.'/'.$newer, '2026-09-20T00:00:00Z'),
            // Anything not on this cloud is dropped, never shown as the person's.
            ['public_id' => $folder.'/x', 'secure_url' => 'https://evil.test/x.png', 'created_at' => '2026-09-25T00:00:00Z'],
        ]])]);

        $this->getJson(self::BASE, $this->headersFor($admin))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer)
            ->assertJsonPath('data.1.id', $older)
            ->assertJsonPath('data.0.width', 600)
            ->assertJsonPath('canManage', true);

        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'prefix='.urlencode($folder.'/'))
            && $request->hasHeader('Authorization'));
        $this->assertStringContainsString('/upload/c_fill,h_160,w_220,f_auto,q_auto/', (string) $this->getJson(self::BASE, $this->headersFor($admin))->json('data.0.thumbUrl'));
    }

    public function test_it_uploads_into_the_person_folder_with_a_signed_request_and_logs_it(): void
    {
        $admin = $this->administrator();
        Http::fake([self::CLOUD.'/image/upload' => function (HttpRequest $request) {
            $publicId = collect($request->data())->firstWhere('name', 'public_id')['contents'] ?? '';

            return Http::response($this->image((string) $publicId, '2026-09-29T00:00:00Z'));
        }]);

        $sent = $this->post(self::BASE, ['file' => UploadedFile::fake()->create('banner.png', 20, 'image/png')], $this->headersFor($admin))
            ->assertCreated()
            ->json('data');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $sent['id']);
        Http::assertSent(function (HttpRequest $request) use ($admin, $sent): bool {
            if (! str_starts_with($request->url(), self::CLOUD.'/image/upload')) {
                return false;
            }
            $fields = collect($request->data())->mapWithKeys(fn (array $part): array => [$part['name'] => $part['contents']]);

            return $fields['public_id'] === 'email-upload/'.$admin->getKey().'/'.$sent['id']
                && $fields['signature'] === sha1('public_id='.$fields['public_id'].'&timestamp='.$fields['timestamp'].'secret456')
                && ! $fields->contains('secret456');
        });
        $this->assertTrue(PortalLog::query()->where('resource', 'administration.email')->where('record_id', $sent['id'])->exists());
    }

    public function test_it_refuses_what_is_not_an_email_image(): void
    {
        Http::fake();
        $headers = $this->authHeaders();

        $this->post(self::BASE, [], $headers)->assertStatus(422);
        $this->post(self::BASE, ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], $headers)->assertStatus(422);
        $this->post(self::BASE, ['file' => UploadedFile::fake()->create('huge.png', 6000, 'image/png')], $headers)->assertStatus(422);
        $this->assertSame(0, $this->cloudinaryCalls());
    }

    public function test_it_deletes_only_inside_the_person_folder(): void
    {
        $admin = $this->administrator();
        $id = '33333333-3333-4333-8333-333333333333';
        Http::fake([self::CLOUD.'/image/destroy' => Http::response(['result' => 'ok'])]);

        $this->deleteJson(self::BASE.'/'.$id, [], $this->headersFor($admin))->assertNoContent();

        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), self::CLOUD.'/image/destroy')
            && $request['public_id'] === 'email-upload/'.$admin->getKey().'/'.$id
            && $request['signature'] === sha1('public_id='.$request['public_id'].'&timestamp='.$request['timestamp'].'secret456'));
        // A path, another folder or anything that is not an image id never reaches Cloudinary.
        $this->deleteJson(self::BASE.'/..%2F9%2F'.$id, [], $this->headersFor($admin))->assertNotFound();
        $this->deleteJson(self::BASE.'/not-an-id', [], $this->headersFor($admin))->assertNotFound();
        $this->assertSame(1, $this->cloudinaryCalls());
    }

    public function test_an_image_already_gone_deletes_cleanly_and_a_refusal_is_a_502(): void
    {
        $headers = $this->authHeaders();
        $id = '44444444-4444-4444-8444-444444444444';
        Http::fakeSequence(self::CLOUD.'/image/destroy')
            ->push(['result' => 'not found'])
            ->push(['error' => ['message' => 'nope']], 500);

        $this->deleteJson(self::BASE.'/'.$id, [], $headers)->assertNoContent();
        $this->deleteJson(self::BASE.'/'.$id, [], $headers)->assertStatus(502);
    }

    public function test_it_is_administrators_only_and_needs_cloudinary_configured(): void
    {
        Http::fake();
        $this->getJson(self::BASE, $this->headersFor($this->member()))->assertForbidden();

        config(['services.cloudinary.api_secret' => '']);
        $this->getJson(self::BASE, $this->authHeaders())->assertStatus(503);
    }

    public function test_a_failed_listing_or_upload_is_a_502(): void
    {
        $headers = $this->authHeaders();
        Http::fake([
            self::CLOUD.'/resources/image/upload*' => Http::response([], 500),
            self::CLOUD.'/image/upload' => Http::response(['error' => ['message' => 'bad']], 400),
        ]);

        $this->getJson(self::BASE, $headers)->assertStatus(502);
        $this->post(self::BASE, ['file' => UploadedFile::fake()->create('banner.png', 20, 'image/png')], $headers)->assertStatus(502);
    }

    public function test_global_is_shared_and_only_a_developer_in_developer_mode_changes_it(): void
    {
        $admin = $this->administrator();
        $admin->forceFill(['is_developer' => false])->save();
        $id = '55555555-5555-4555-8555-555555555555';
        Http::fake([self::CLOUD.'/resources/image/upload*' => Http::response(['resources' => [
            $this->image('email-upload/global/'.$id, '2026-09-29T00:00:00Z'),
        ]])]);
        $headers = $this->headersFor($admin);

        $this->getJson(self::BASE.'?scope=global', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('canManage', false);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'prefix='.urlencode('email-upload/global/')));

        // Anyone else is refused before Cloudinary is asked anything.
        $calls = $this->cloudinaryCalls();
        $this->post(self::BASE, ['scope' => 'global', 'file' => UploadedFile::fake()->create('logo.png', 20, 'image/png')], $headers)->assertForbidden();
        $this->deleteJson(self::BASE.'/'.$id.'?scope=global', [], $headers)->assertForbidden();
        $this->assertSame($calls, $this->cloudinaryCalls());
    }

    public function test_a_developer_in_developer_mode_uploads_to_and_deletes_from_global(): void
    {
        $headers = $this->developerHeaders();
        $id = '66666666-6666-4666-8666-666666666666';
        Http::fake([
            self::CLOUD.'/resources/image/upload*' => Http::response(['resources' => []]),
            self::CLOUD.'/image/upload' => function (HttpRequest $request) {
                $publicId = collect($request->data())->firstWhere('name', 'public_id')['contents'] ?? '';

                return Http::response($this->image((string) $publicId, '2026-09-29T00:00:00Z'));
            },
            self::CLOUD.'/image/destroy' => Http::response(['result' => 'ok']),
        ]);

        $this->getJson(self::BASE.'?scope=global', $headers)->assertOk()->assertJsonPath('canManage', true);
        $sent = $this->post(self::BASE, ['scope' => 'global', 'file' => UploadedFile::fake()->create('logo.png', 20, 'image/png')], $headers)
            ->assertCreated()
            ->json('data');
        $this->deleteJson(self::BASE.'/'.$id.'?scope=global', [], $headers)->assertNoContent();

        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), self::CLOUD.'/image/upload')
            && collect($request->data())->firstWhere('name', 'public_id')['contents'] === 'email-upload/global/'.$sent['id']);
        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), self::CLOUD.'/image/destroy')
            && $request['public_id'] === 'email-upload/global/'.$id);
        $log = PortalLog::query()->where('resource', 'administration.email')->where('record_id', $sent['id'])->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('a global email image', (string) $log->message);
    }
}
