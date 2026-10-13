<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Composer\CaBundle\CaBundle;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An employee's ID card (2026-10-14, user): what its front and back print -- their photo, nickname,
 * name, position and ID No.; their signature, the day they were hired, their blood type, a QR code and
 * who to call in an emergency -- for whoever sees their employee information to lay out and download.
 *
 * The photo, the signature and the company mark come as data URIs, so the card draws and exports in
 * the browser without reaching another site; the QR code comes as its rows of modules, and the text
 * it holds is the company's own format. A picture is fetched from Cloudinary with the system's CA
 * bundle, as uploads are, since PHP on Windows has none of its own (2026-10-14); one that cannot be
 * fetched is logged and left blank on the card. The photo comes without its background, Cloudinary's
 * AI taking it out once per photo and keeping the result; until it can, the photo comes as taken.
 */
final class PortalEmployeeIdCards
{
    public const COMPANY = 'IKAIKA OPC';

    // A face-centred 3:4 portrait for the photo, as the card prints it, its background taken out so the
    // person stands on the card's white (2026-10-14, user), and the signature trimmed to its ink so it
    // stands on its line; each sized for print at 300 dpi.
    private const PHOTO_TRANSFORM = 'e_background_removal/c_fill,g_face,w_480,h_642/f_png';

    // The photo as taken, for when its background cannot be taken out yet.
    private const PLAIN_PHOTO_TRANSFORM = 'c_fill,g_face,w_480,h_642,f_jpg,q_90';

    private const SIGNATURE_TRANSFORM = 'e_trim/c_limit,w_900,h_300,f_png';

    private const IMAGE_TIMEOUT_SECONDS = 15;

    // Cloudinary answers 423 while it first takes a background out, so it is asked again before giving up.
    private const PROCESSING_TRIES = 5;

    private const PROCESSING_WAIT_MILLISECONDS = 2000;

    public function __construct(private readonly PortalCloudinary $cloudinary) {}

    /**
     * @return array<string, mixed>
     */
    public function card(Employee $employee): array
    {
        $name = self::cardName($employee->first_name, $employee->middle_name, $employee->last_name);
        $contact = implode(' - ', array_filter(
            [self::text($employee->emergency_contact_person_name), self::text($employee->emergency_contact_number)],
            static fn (?string $part): bool => $part !== null,
        ));
        $qrText = implode("\n", [
            'Name : '.$name,
            'Position : '.(self::text($employee->job_title) ?? ''),
            'ID-No. : '.(self::text($employee->id_no) ?? ''),
            'Company : '.self::COMPANY,
            'Emergency Contact : '.$contact,
        ]);

        return [
            'id' => (string) $employee->getKey(),
            'idNo' => self::text($employee->id_no),
            'name' => $name,
            'nickname' => self::text($employee->nickname),
            'jobTitle' => self::text($employee->job_title),
            'startDate' => $employee->start_date === null || $employee->start_date === '' ? null : substr((string) $employee->start_date, 0, 10),
            'bloodType' => self::text($employee->blood_type),
            'emergencyContactName' => self::text($employee->emergency_contact_person_name),
            'emergencyContactNumber' => self::text($employee->emergency_contact_number),
            'photo' => $this->image($employee, 'photo', self::PHOTO_TRANSFORM, self::PLAIN_PHOTO_TRANSFORM),
            'signature' => $this->image($employee, 'signature', self::SIGNATURE_TRANSFORM),
            'logo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('images/ikaika-logo.png'))),
            'qr' => ['text' => $qrText, 'rows' => self::qrRows($qrText)],
        ];
    }

    // "Jhon Noriel D. Malabanan": the first name, the middle name's initial, and the last name.
    public static function cardName(mixed $first, mixed $middle, mixed $last): string
    {
        $initial = self::text($middle) === null ? null : mb_strtoupper(mb_substr((string) self::text($middle), 0, 1)).'.';

        return implode(' ', array_filter([self::text($first), $initial, self::text($last)], static fn (?string $part): bool => $part !== null));
    }

    /**
     * The QR code's modules, one string of 0s and 1s per row, without its quiet zone.
     *
     * @return list<string>
     */
    private static function qrRows(string $text): array
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        $rows = [];
        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            $row = '';
            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row .= $matrix->get($x, $y) === 1 ? '1' : '0';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    // Their newest file in a slot on Cloudinary, read in as a data URI through the first of the transforms
    // that answers; null when there is none.
    private function image(Employee $employee, string $field, string ...$transforms): ?string
    {
        $url = DB::connection('portal')->table('attachments')
            ->where('table_name', 'employees')
            ->where('record_id', (int) $employee->getKey())
            ->where('field_name', $field)
            ->orderByDesc('id')
            ->value('file_url');
        if (! is_string($url) || ! str_starts_with($url, 'https://') || ! str_contains($url, '/upload/')) {
            return null;
        }

        foreach ($transforms as $transform) {
            $picture = $this->fetched($employee, $field, $transform, $this->cloudinary->transformed($url, $transform));
            if ($picture !== null) {
                return $picture;
            }
        }

        return null;
    }

    private function fetched(Employee $employee, string $field, string $transform, string $url): ?string
    {
        $context = ['employeeId' => (int) $employee->getKey(), 'field' => $field, 'transform' => $transform];
        try {
            $response = Http::timeout(self::IMAGE_TIMEOUT_SECONDS)
                ->withOptions(['verify' => CaBundle::getSystemCaRootBundlePath()])
                ->retry(
                    self::PROCESSING_TRIES,
                    self::PROCESSING_WAIT_MILLISECONDS,
                    static fn (Throwable $error): bool => $error instanceof RequestException && $error->response->status() === 423,
                    throw: false,
                )
                ->get($url);
        } catch (Throwable $error) {
            Log::warning('portal.id_card.picture_unavailable', $context + ['reason' => $error->getMessage()]);

            return null;
        }
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! $response->successful() || ! str_starts_with($mime, 'image/')) {
            Log::warning('portal.id_card.picture_unavailable', $context + ['reason' => 'HTTP '.$response->status().' '.$mime]);

            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($response->body());
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
