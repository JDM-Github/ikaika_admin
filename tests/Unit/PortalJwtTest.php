<?php

namespace Tests\Unit;

use App\Support\Portal\PortalJwt;
use Tests\TestCase;
use UnexpectedValueException;

class PortalJwtTest extends TestCase
{
    public function test_issue_and_parse_round_trip_the_employee_claims(): void
    {
        $jwt = app(PortalJwt::class);
        $token = $jwt->issue([
            'sub' => '4',
            'id_no' => '220101-0001',
            'role' => 'Admin',
        ]);

        $payload = $jwt->parse($token);

        $this->assertSame('4', $payload['sub']);
        $this->assertSame('220101-0001', $payload['id_no']);
        $this->assertSame('Admin', $payload['role']);
        $this->assertSame('portal', $payload['aud']);
        $this->assertSame('ikaika-platform', $payload['iss']);
    }

    public function test_parse_rejects_an_expired_token(): void
    {
        $this->freezeTime();
        $jwt = app(PortalJwt::class);
        $token = $jwt->issue(['sub' => '4']);

        $this->travel(((int) config('products.jwt_ttl')) + 1)->seconds();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Token has expired.');
        $jwt->parse($token);
    }

    public function test_parse_rejects_a_tampered_signature(): void
    {
        $jwt = app(PortalJwt::class);
        $token = $jwt->issue(['sub' => '4']);
        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);

        $this->expectException(UnexpectedValueException::class);
        $jwt->parse(implode('.', $parts));
    }
}
