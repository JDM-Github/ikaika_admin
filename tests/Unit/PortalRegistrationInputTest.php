<?php

namespace Tests\Unit;

use App\Support\Portal\PortalRegistrationInput;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PortalRegistrationInputTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // The reported ask (2026-10-12): markup, control and invisible characters go, and spaces collapse.
    public function test_details_are_cleaned_to_plain_text(): void
    {
        $clean = (new PortalRegistrationInput)->cleaned([
            'firstName' => "  <b>Liza</b>\u{200B}  Mae ",
            'lastName' => "Dela\tCruz-Reyes",
            'address' => "1 Test Street,\n\tManila <i>City</i>",
            'phoneNumber' => '+63 (917) 123-4567',
            'sssNo' => '12-3456789-0',
            'bankSwiftCode' => 'bnor phmm',
            'nickname' => "   \u{FEFF} ",
            'dateOfBirth' => '1998-04-12',
            'unknown' => '<b>kept</b>',
        ]);

        $this->assertSame([
            'firstName' => 'Liza Mae',
            'lastName' => 'Dela Cruz-Reyes',
            'address' => '1 Test Street, Manila City',
            'phoneNumber' => '+63 (917) 123-4567',
            'sssNo' => '12-3456789-0',
            'bankSwiftCode' => 'BNORPHMM',
            'nickname' => null,
            'dateOfBirth' => '1998-04-12',
            'unknown' => '<b>kept</b>',
        ], $clean);
    }

    public function test_a_detail_out_of_its_shape_is_refused_with_why(): void
    {
        Carbon::setTestNow('2026-10-12 09:00:00');
        $refused = [
            ['firstName', 'Liza2', 'First name can only have letters, spaces, periods, apostrophes and hyphens.'],
            ['lastName', "-'.", 'Last name can only have letters, spaces, periods, apostrophes and hyphens.'],
            ['address', 'Manila {}', 'Home address can only have letters, numbers, spaces and . , # \' - / ( ) & :'],
            ['phoneNumber', '0917', 'Enter a phone number with 10 to 13 digits.'],
            ['emergencyContactNumber', '0917-ABC-4567', 'Enter a contact number with 10 to 13 digits.'],
            ['sssNo', 'SS-0001', 'Enter your SSS No.: 10 digits, numbers and dashes only.'],
            ['philhealthNo', '12-3456', 'Enter your PhilHealth No.: 12 digits, numbers and dashes only.'],
            ['taxIdentificationNo', '123-456', 'Enter your TIN: 9 to 14 digits, numbers and dashes only.'],
            ['bankAccountNumber', '12345', 'Enter your account number: 6 to 20 digits, numbers and dashes only.'],
            ['bankSwiftCode', 'BNOR', 'Enter an 8 or 11 character SWIFT code, such as BNORPHMM.'],
            ['startDate', '2026-02-30', 'Choose a real start date.'],
            ['dateOfBirth', '2026-10-13', 'Your date of birth cannot be in the future.'],
        ];

        foreach ($refused as [$field, $value, $message]) {
            try {
                (new PortalRegistrationInput)->cleaned([$field => $value]);
                $this->fail($field.' should have been refused.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_a_candidate_name_is_plain_letters(): void
    {
        $input = new PortalRegistrationInput;

        $this->assertSame('Liza Reyes', $input->candidateName(' <b>Liza</b>  Reyes '));
        $this->assertSame('', $input->candidateName('  '));
        $this->expectExceptionMessage('The candidate name can only have letters, spaces, periods, apostrophes and hyphens.');
        $input->candidateName('Liza 3');
    }
}
