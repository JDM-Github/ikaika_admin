<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Email signature templates. A template is a brand's fixed fields (company, website, logo, accent,
 * disclaimer); the signature itself is rendered here from those plus the employee's details, so the
 * format stays locked and nothing an employee types reaches it. Edited behind the signature.template
 * power; the default template is used until a person is assigned another.
 */
final class PortalSignature
{
    public const RULE = 'signature.template';

    public function __construct(
        private readonly PortalAudit $audit,
        private readonly PortalEmployeeSignature $employees,
    ) {}

    /**
     * The employee's rendered signature, from the default template; null when none is set.
     *
     * @return array{brand: string, templateId: string, html: string}|null
     */
    public function render(Employee $employee): ?array
    {
        $template = $this->defaultTemplate();
        if ($template === null) {
            return null;
        }

        return [
            'brand' => (string) $template->brand,
            'templateId' => (string) (int) $template->id,
            'html' => $this->html($template, $this->employees->values($employee)),
        ];
    }

    /**
     * The employee's signature as a standalone .htm document for Outlook, with the logo inlined as a
     * data URI so a mail client that blocks remote images still shows it. Null when no template is set.
     *
     * @return array{fileName: string, html: string}|null
     */
    public function document(Employee $employee): ?array
    {
        $template = $this->defaultTemplate();
        if ($template === null) {
            return null;
        }
        $values = $this->employees->values($employee);
        $body = $this->html($template, $values, true);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $values['name'])), '-');
        $fileName = 'signature'.($slug === '' ? '' : '-'.$slug).'.htm';
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars((string) $values['name'], ENT_QUOTES, 'UTF-8').'</title></head>'
            .'<body style="margin:0;padding:12px;background:#ffffff;">'.$body.'</body></html>';

        return ['fileName' => $fileName, 'html' => $html];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function templates(): array
    {
        $rows = [];
        foreach ($this->connection()->table('signature_templates')->orderByDesc('is_default')->orderBy('brand')->get() as $row) {
            $rows[] = $this->item($row);
        }

        return $rows;
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function addTemplate(Employee $actor, Request $request): array
    {
        $data = $this->validate($request);
        if ($this->brandTaken($data['brand'])) {
            abort(422, 'A template for that brand already exists.');
        }
        if ($this->connection()->table('signature_templates')->count() === 0) {
            $data['is_default'] = true;
        }
        $id = (int) $this->connection()->table('signature_templates')->insertGetId($data);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::INSERT, 'signature.template', '{UserName|You} added the '.$data['brand'].' signature template', (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function updateTemplate(Employee $actor, Request $request, int $id): array
    {
        if (! $this->connection()->table('signature_templates')->where('id', $id)->exists()) {
            abort(404, 'That template was not found.');
        }
        $data = $this->validate($request);
        if ($this->brandTaken($data['brand'], $id)) {
            abort(422, 'A template for that brand already exists.');
        }
        $this->connection()->table('signature_templates')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->bump();
        $this->audit->record($actor, PortalLogAction::PATCH, 'signature.template', '{UserName|You} changed the '.$data['brand'].' signature template', (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function removeTemplate(Employee $actor, Request $request, int $id): array
    {
        $row = $this->connection()->table('signature_templates')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That template was not found.');
        }
        $this->connection()->table('signature_templates')->where('id', $id)->delete();
        // A removed default leaves the first remaining template as the default, so one always exists.
        if ((bool) $row->is_default) {
            $next = (int) $this->connection()->table('signature_templates')->orderBy('id')->value('id');
            if ($next !== 0) {
                $this->connection()->table('signature_templates')->where('id', $next)->update(['is_default' => true]);
            }
        }
        $this->bump();
        $this->audit->record($actor, PortalLogAction::DELETE, 'signature.template', '{UserName|You} removed the '.trim((string) $row->brand).' signature template', (string) $id, $request);

        return $this->index();
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function index(): array
    {
        return ['section' => 'manage', 'resource' => 'signature-templates', 'data' => ['templates' => $this->templates()]];
    }

    private function defaultTemplate(): ?object
    {
        return $this->connection()->table('signature_templates')->orderByDesc('is_default')->orderBy('id')->first();
    }

    /**
     * @param  array{name: ?string, title: ?string, email: ?string, phoneNumber: ?string, department: ?string, location: ?string}  $values
     */
    private function html(object $template, array $values, bool $inlineLogo = false): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $join = static function (array $parts): string {
            $kept = array_values(array_filter($parts, static fn (?string $part): bool => $part !== null && trim($part) !== ''));

            return implode(' · ', $kept);
        };

        $accent = $e($template->accent_color ?? '#2563eb');
        $logo = $this->logoSrc($template, $inlineLogo);
        $logoCell = $logo === null
            ? ''
            : '<td style="padding-right:16px;border-right:3px solid '.$accent.';vertical-align:top;">'
                .'<img alt="'.$e($template->brand).'" src="'.$e($logo).'" style="display:block;max-width:132px;height:auto;border:0;"></td>';
        $title = $e($join([$values['title'], $values['department']]));
        $contact = $e($join([$values['email'], $values['phoneNumber']]));
        $place = $e($join([$template->website, $values['location']]));
        $company = $e($template->company_name);
        $disclaimer = trim((string) ($template->disclaimer ?? ''));

        $rows = '<div style="font-size:15px;font-weight:700;color:#111827;">'.$e($values['name']).'</div>';
        if ($title !== '') {
            $rows .= '<div style="font-size:12px;color:#4b5563;">'.$title.'</div>';
        }
        if ($contact !== '') {
            $rows .= '<div style="font-size:12px;color:#111827;">'.$contact.'</div>';
        }
        if ($place !== '') {
            $rows .= '<div style="font-size:12px;color:#4b5563;">'.$place.'</div>';
        }
        if ($disclaimer !== '') {
            $rows .= '<div style="margin-top:6px;font-size:10px;color:#9ca3af;">'.$e($disclaimer).'</div>';
        }

        return '<table cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;font-family:Segoe UI,Arial,sans-serif;">'
            .'<tr>'.$logoCell.'<td style="padding-left:'.($logo === null ? '0' : '16px').';vertical-align:top;">'.$rows.'</td></tr>'
            .'<tr><td colspan="2" style="padding-top:8px;font-size:11px;color:#6b7280;">'.$company.'</td></tr>'
            .'</table>';
    }

    // The logo as given, or fetched and inlined as a data URI so a mail client that blocks remote
    // images still shows it. A fetch that fails falls back to the URL.
    private function logoSrc(object $template, bool $inline): ?string
    {
        $url = trim((string) ($template->logo_url ?? ''));
        if ($url === '') {
            return null;
        }
        if (! $inline) {
            return $url;
        }
        try {
            $response = Http::timeout(5)->get($url);
            if ($response->successful() && $response->body() !== '') {
                $type = trim((string) $response->header('Content-Type')) ?: 'image/png';

                return 'data:'.$type.';base64,'.base64_encode($response->body());
            }
        } catch (\Throwable) {
            // Fall back to the hosted URL.
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(object $row): array
    {
        return [
            'id' => (string) (int) $row->id,
            'brand' => (string) $row->brand,
            'companyName' => (string) $row->company_name,
            'website' => $this->text($row->website),
            'address' => $this->text($row->address),
            'logoUrl' => $this->text($row->logo_url),
            'accentColor' => $this->text($row->accent_color),
            'disclaimer' => $this->text($row->disclaimer),
            'isDefault' => (bool) $row->is_default,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validate(Request $request): array
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'max:100'],
            'companyName' => ['required', 'string', 'max:191'],
            'website' => ['nullable', 'string', 'max:191'],
            'address' => ['nullable', 'string', 'max:255'],
            'logoUrl' => ['nullable', 'url', 'max:500'],
            'accentColor' => ['nullable', 'string', 'max:20'],
            'disclaimer' => ['nullable', 'string', 'max:1000'],
        ]);

        return [
            'brand' => trim($data['brand']),
            'company_name' => trim($data['companyName']),
            'website' => $this->text($data['website'] ?? null),
            'address' => $this->text($data['address'] ?? null),
            'logo_url' => $this->text($data['logoUrl'] ?? null),
            'accent_color' => $this->text($data['accentColor'] ?? null),
            'disclaimer' => $this->text($data['disclaimer'] ?? null),
        ];
    }

    private function brandTaken(string $brand, ?int $exceptId = null): bool
    {
        $query = $this->connection()->table('signature_templates')->whereRaw('LOWER(TRIM(brand)) = ?', [mb_strtolower(trim($brand))]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function bump(): void
    {
        PortalCache::bump();
    }

    private function connection(): Connection
    {
        return Model::getConnectionResolver()->connection('portal');
    }
}
