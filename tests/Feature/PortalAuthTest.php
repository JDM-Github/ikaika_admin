<?php

namespace Tests\Feature;

use App\Modules\Portal\Models\Employee;
use App\Modules\Portal\Models\PortalLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalAuthTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['portal'];

    public function test_login_issues_a_jwt_for_an_active_employee_id_number(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);
        $this->assertNotEmpty($employee->id_no);

        $response = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.id_no', $employee->id_no)
            ->assertJsonPath('employee.role', $employee->role)
            ->assertJsonMissingPath('employee.bank_account_number');

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertCount(3, explode('.', $token));
        $this->assertSame((int) config('products.jwt_ttl'), $response->json('expires_in'));

        $payloadJson = base64_decode(strtr(explode('.', $token)[1], '-_', '+/'));
        $payload = json_decode((string) $payloadJson, true);
        $this->assertIsArray($payload);
        $this->assertSame((string) $employee->getKey(), $payload['sub']);
        $this->assertSame($employee->id_no, $payload['id_no']);
        $this->assertSame($employee->role, $payload['role']);

        $log = PortalLog::query()
            ->where('employee_id', $employee->getKey())
            ->where('action', 'POST')
            ->where('resource', 'auth.login')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('{UserName|You} signed in to the portal', $log->message);
    }

    public function test_login_rejects_an_unknown_id_number(): void
    {
        $this->postJson('/api/development/portal/auth/login', [
            'id_no' => '000000-0000',
        ])->assertUnauthorized();
    }

    public function test_login_requires_an_id_number(): void
    {
        $this->postJson('/api/development/portal/auth/login', [])
            ->assertUnprocessable();
    }

    public function test_me_and_employees_use_the_bearer_token_instead_of_an_id_query(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->getJson('/api/development/portal/auth/me', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.role', $employee->role);

        $this->getJson('/api/development/portal/employees?per_page=1', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('resource', 'employees');
    }

    public function test_employees_without_a_token_are_unauthorized(): void
    {
        $this->getJson('/api/development/portal/employees')
            ->assertUnauthorized();
    }

    public function test_login_rejects_an_inactive_employee(): void
    {
        $employee = Employee::query()
            ->whereRaw('LOWER(status) != ?', ['active'])
            ->first();
        if ($employee === null) {
            $this->markTestSkipped('No inactive employee in the portal database.');
        }

        $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->assertUnauthorized();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $this->freezeTime();
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->travel(((int) config('products.jwt_ttl')) + 1)->seconds();

        $this->getJson('/api/development/portal/auth/me', [
            'Authorization' => "Bearer {$token}",
        ])->assertUnauthorized();
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);

        $this->getJson('/api/development/portal/auth/me', [
            'Authorization' => 'Bearer '.implode('.', $parts),
        ])->assertUnauthorized();
    }

    public function test_health_reports_a_valid_session_from_the_bearer_token(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->getJson('/api/development/portal/health', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('health.ok', true)
            ->assertJsonPath('session.ok', true);
    }

    public function test_health_without_a_token_does_not_include_a_session(): void
    {
        $this->getJson('/api/development/portal/health')
            ->assertOk()
            ->assertJsonPath('health.ok', true)
            ->assertJsonMissingPath('session');
    }

    public function test_health_marks_an_expired_token_so_the_portal_can_sign_out(): void
    {
        $employee = Employee::query()->where('status', 'Active')->first();
        $this->assertNotNull($employee);

        $this->freezeTime();
        $token = $this->postJson('/api/development/portal/auth/login', [
            'id_no' => $employee->id_no,
        ])->json('token');

        $this->travel(((int) config('products.jwt_ttl')) + 1)->seconds();

        $this->getJson('/api/development/portal/health', [
            'Authorization' => "Bearer {$token}",
        ])->assertOk()
            ->assertJsonPath('health.ok', true)
            ->assertJsonPath('session.ok', false);
    }

    public function test_microsoft_login_issues_the_same_jwt_for_a_matching_name(): void
    {
        $employee = $this->activeEmployeeWithUniqueName();
        $this->assertNotNull($employee);
        $this->configureAzure();
        $oid = '8f97d4d9-f8c7-4a7b-841f-edd810effe4d';
        [$idToken, $jwk] = $this->mintAzureIdToken([
            'preferred_username' => $employee->email,
            'oid' => $oid,
            ...$this->microsoftNameClaims($employee),
        ]);
        $this->fakeAzureJwks($jwk);

        $response = $this->postJson('/api/development/portal/auth/login/microsoft', [
            'id_token' => $idToken,
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.email', $employee->email)
            ->assertJsonMissingPath('employee.bank_account_number');

        $token = $response->json('token');
        $this->assertIsString($token);
        $payload = $this->decodeJwtPayload($token);
        $this->assertSame((string) $employee->getKey(), $payload['sub']);
        $this->assertSame($employee->id_no, $payload['id_no']);
        $this->assertSame($oid, $payload['oid']);

        $log = PortalLog::query()
            ->where('employee_id', $employee->getKey())
            ->where('action', 'POST')
            ->where('resource', 'auth.login')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('{UserName|You} signed in to the portal', $log->message);
        Http::assertSentCount(1);
    }

    public function test_microsoft_login_matches_name_case_insensitively(): void
    {
        $employee = $this->activeEmployeeWithUniqueName();
        $this->assertNotNull($employee);
        $this->configureAzure();
        [$idToken, $jwk] = $this->mintAzureIdToken([
            'given_name' => strtoupper(trim((string) $employee->first_name)),
            'family_name' => strtoupper(trim((string) $employee->last_name)),
        ]);
        $this->fakeAzureJwks($jwk);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'id_token' => $idToken,
        ])->assertOk()
            ->assertJsonPath('employee.id', $employee->id);
    }

    public function test_microsoft_login_matches_a_display_name_when_given_and_family_are_absent(): void
    {
        $employee = $this->activeEmployeeWithUniqueName();
        $this->assertNotNull($employee);
        $this->configureAzure();
        [$idToken, $jwk] = $this->mintAzureIdToken([
            'name' => trim($employee->first_name.' '.$employee->last_name),
        ]);
        $this->fakeAzureJwks($jwk);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'id_token' => $idToken,
        ])->assertOk()
            ->assertJsonPath('employee.id', $employee->id);
    }

    public function test_microsoft_login_matches_when_azure_splits_a_multi_word_first_name(): void
    {
        $employee = Employee::query()
            ->where('status', 'Active')
            ->where('first_name', 'like', '% %')
            ->whereNotNull('last_name')
            ->where('last_name', '!=', '')
            ->first();
        if ($employee === null) {
            $this->markTestSkipped('No active employee with a multi-word first name in the portal database.');
        }

        $tokens = preg_split('/\s+/u', trim($employee->first_name.' '.$employee->last_name));
        $this->assertIsArray($tokens);
        $this->assertGreaterThanOrEqual(2, count($tokens));

        $this->configureAzure();
        [$idToken, $jwk] = $this->mintAzureIdToken([
            'given_name' => $tokens[0],
            'family_name' => implode(' ', array_slice($tokens, 1)),
        ]);
        $this->fakeAzureJwks($jwk);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'id_token' => $idToken,
        ])->assertOk()
            ->assertJsonPath('employee.id', $employee->id);
    }

    public function test_microsoft_login_rejects_an_unknown_name(): void
    {
        $this->configureAzure();
        $family = 'Ikaika'.uniqid();
        [$idToken, $jwk] = $this->mintAzureIdToken([
            'given_name' => 'Nobody',
            'family_name' => $family,
        ]);
        $this->fakeAzureJwks($jwk);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'id_token' => $idToken,
        ])->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'No portal account matches that Microsoft account. Checked first/last: Nobody / '.$family.'.',
            );
    }

    public function test_microsoft_login_rejects_an_inactive_employee(): void
    {
        $employee = Employee::query()
            ->whereRaw('LOWER(status) != ?', ['active'])
            ->whereNotNull('first_name')
            ->whereNotNull('last_name')
            ->where('first_name', '!=', '')
            ->where('last_name', '!=', '')
            ->first();
        if ($employee === null) {
            $this->markTestSkipped('No inactive employee with a name in the portal database.');
        }

        $this->configureAzure();
        [$idToken, $jwk] = $this->mintAzureIdToken($this->microsoftNameClaims($employee));
        $this->fakeAzureJwks($jwk);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'id_token' => $idToken,
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'This account is not active.');
    }

    public function test_microsoft_login_rejects_a_tampered_id_token(): void
    {
        $employee = $this->activeEmployeeWithEmail();
        $this->assertNotNull($employee);
        $this->configureAzure();
        [$idToken, $jwk] = $this->mintAzureIdToken([
            'preferred_username' => $employee->email,
        ]);
        $this->fakeAzureJwks($jwk);
        $parts = explode('.', $idToken);
        $parts[2] = strrev($parts[2]);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'id_token' => implode('.', $parts),
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Microsoft sign-in failed.');
    }

    public function test_microsoft_login_requires_an_id_token(): void
    {
        $this->configureAzure();
        Http::preventStrayRequests();

        $this->postJson('/api/development/portal/auth/login/microsoft', [])
            ->assertUnprocessable();
    }

    public function test_microsoft_login_is_unavailable_when_azure_is_not_configured(): void
    {
        config([
            'services.portal_azure.tenant_id' => '',
            'services.portal_azure.client_id' => '',
        ]);
        Http::preventStrayRequests();

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'id_token' => 'header.payload.signature',
        ])->assertStatus(503)
            ->assertJsonPath('message', 'Microsoft sign-in is not configured.');
        Http::assertNothingSent();
    }

    public function test_microsoft_login_exchanges_an_authorization_code(): void
    {
        $employee = $this->activeEmployeeWithUniqueName();
        $this->assertNotNull($employee);
        $this->configureAzure();
        [$idToken, $jwk] = $this->mintAzureIdToken($this->microsoftNameClaims($employee));
        $this->fakeAzureCodeExchange($idToken, $jwk);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'code' => 'authorization-code',
            'code_verifier' => str_repeat('a', 43),
            'redirect_uri' => 'http://localhost:8081',
        ])->assertOk()
            ->assertJsonPath('employee.id', $employee->id);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/oauth2/v2.0/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['redirect_uri'] === 'http://localhost:8081'
                && $request['client_id'] === 'f995e827-5f8b-49ee-8cd5-b05b99017558'
                && $request->hasHeader('Origin', 'http://localhost:8081');
        });
    }

    public function test_microsoft_login_retries_token_redemption_without_origin_after_spa_cors_rejection(): void
    {
        $employee = $this->activeEmployeeWithUniqueName();
        $this->assertNotNull($employee);
        $this->configureAzure();
        [$idToken, $jwk] = $this->mintAzureIdToken($this->microsoftNameClaims($employee));
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/oauth2/v2.0/token' => Http::sequence()
                ->push([
                    'error' => 'invalid_request',
                    'error_codes' => [9002326],
                ], 400)
                ->push([
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                    'id_token' => $idToken,
                ]),
            'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/discovery/v2.0/keys' => Http::response([
                'keys' => [$jwk],
            ]),
        ]);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'code' => 'authorization-code',
            'code_verifier' => str_repeat('a', 43),
            'redirect_uri' => 'http://localhost:8081',
        ])->assertOk()
            ->assertJsonPath('employee.id', $employee->id);

        $tokenRequests = Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_contains($request->url(), '/oauth2/v2.0/token'))
            ->values();
        $this->assertCount(2, $tokenRequests);
        $this->assertTrue($tokenRequests[0]->hasHeader('Origin', 'http://localhost:8081'));
        $this->assertFalse($tokenRequests[1]->hasHeader('Origin'));
    }

    public function test_microsoft_login_rejects_an_unregistered_redirect_uri(): void
    {
        $this->configureAzure();
        Http::preventStrayRequests();

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'code' => 'authorization-code',
            'code_verifier' => str_repeat('a', 43),
            'redirect_uri' => 'https://evil.example',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Microsoft sign-in failed.');
        Http::assertNothingSent();
    }

    public function test_microsoft_login_rejects_when_azure_refuses_the_code(): void
    {
        $this->configureAzure();
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/oauth2/v2.0/token' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'code' => 'authorization-code',
            'code_verifier' => str_repeat('a', 43),
            'redirect_uri' => 'http://localhost:8081',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Microsoft sign-in failed.');
    }

    public function test_microsoft_login_rejects_when_azure_cannot_be_reached(): void
    {
        $this->configureAzure();
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/oauth2/v2.0/token' => Http::failedConnection(),
        ]);

        $this->postJson('/api/development/portal/auth/login/microsoft', [
            'code' => 'authorization-code',
            'code_verifier' => str_repeat('a', 43),
            'redirect_uri' => 'http://localhost:8081',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Microsoft sign-in failed.');
    }

    private function configureAzure(): void
    {
        config([
            'services.portal_azure.tenant_id' => '70c0daac-fc8d-46de-b3eb-db0ce575ae83',
            'services.portal_azure.client_id' => 'f995e827-5f8b-49ee-8cd5-b05b99017558',
            'services.portal_azure.redirect_uri' => 'http://localhost:8081',
        ]);
        Cache::forget('portal:azure:jwks:70c0daac-fc8d-46de-b3eb-db0ce575ae83');
    }

    private function fakeAzureJwks(array $jwk): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/discovery/v2.0/keys' => Http::response([
                'keys' => [$jwk],
            ]),
        ]);
    }

    /**
     * @param  array<string, string>  $jwk
     */
    private function fakeAzureCodeExchange(string $idToken, array $jwk): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/oauth2/v2.0/token' => Http::response([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'id_token' => $idToken,
            ]),
            'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/discovery/v2.0/keys' => Http::response([
                'keys' => [$jwk],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array{0: string, 1: array<string, string>}
     */
    private function mintAzureIdToken(array $claims): array
    {
        $configPath = $this->opensslConfigPath();
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $configPath,
        ]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->assertIsArray($details['rsa'] ?? null);

        $jwk = [
            'kty' => 'RSA',
            'kid' => 'portal-test-key',
            'use' => 'sig',
            'n' => $this->base64Url((string) $details['rsa']['n']),
            'e' => $this->base64Url((string) $details['rsa']['e']),
        ];

        $now = now()->getTimestamp();
        $payload = array_merge([
            'aud' => 'f995e827-5f8b-49ee-8cd5-b05b99017558',
            'iss' => 'https://login.microsoftonline.com/70c0daac-fc8d-46de-b3eb-db0ce575ae83/v2.0',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'tid' => '70c0daac-fc8d-46de-b3eb-db0ce575ae83',
        ], $claims);

        $header = $this->base64Url((string) json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => 'portal-test-key',
        ], JSON_UNESCAPED_SLASHES));
        $body = $this->base64Url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $this->assertTrue(openssl_sign($header.'.'.$body, $signature, $key, OPENSSL_ALGO_SHA256));

        return [$header.'.'.$body.'.'.$this->base64Url($signature), $jwk];
    }

    private function activeEmployeeWithEmail(): ?Employee
    {
        return Employee::query()
            ->where('status', 'Active')
            ->whereNotNull('email')
            ->where('email', 'like', '%@%')
            ->first();
    }

    private function activeEmployeeWithUniqueName(): ?Employee
    {
        $employees = Employee::query()
            ->where('status', 'Active')
            ->whereNotNull('first_name')
            ->whereNotNull('last_name')
            ->where('first_name', '!=', '')
            ->where('last_name', '!=', '')
            ->get();

        foreach ($employees as $employee) {
            $count = Employee::query()
                ->whereRaw('LOWER(TRIM(first_name)) = ?', [strtolower(trim((string) $employee->first_name))])
                ->whereRaw('LOWER(TRIM(last_name)) = ?', [strtolower(trim((string) $employee->last_name))])
                ->count();
            if ($count === 1) {
                return $employee;
            }
        }

        return null;
    }

    /**
     * @return array{given_name: string, family_name: string, name: string}
     */
    private function microsoftNameClaims(Employee $employee): array
    {
        $given = trim((string) $employee->first_name);
        $family = trim((string) $employee->last_name);

        return [
            'given_name' => $given,
            'family_name' => $family,
            'name' => trim($given.' '.$family),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $token): array
    {
        $payloadJson = base64_decode(strtr(explode('.', $token)[1], '-_', '+/'));
        $payload = json_decode((string) $payloadJson, true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function opensslConfigPath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ikaika-portal-openssl.cnf';
        if (! is_file($path)) {
            file_put_contents($path, "HOME = .\nRANDFILE = {$path}.rnd\n[req]\ndistinguished_name = dn\n[dn]\nCN = test\n");
        }

        putenv('OPENSSL_CONF='.$path);

        return $path;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
