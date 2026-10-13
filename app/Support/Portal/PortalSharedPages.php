<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Shared pages: the guest read-only links a developer makes on Manage / Shared Pages. A share is a
 * code, a generated password, an optional bound email, an expiry and a rate cap, plus the page rules
 * it grants. A guest signs in with the password (and a mailed code when an email is bound) and gets
 * a guest token, which AuthenticatePortalJwt accepts for GETs only. Plan:
 * docs/power/shared-pages-plan.md.
 */
final class PortalSharedPages
{
    // The inline name the password letter's QR is embedded under; the body shows it as cid:<name>.
    private const QR_IMAGE = 'share-qr';

    private const DEFAULT_EXPIRY_DAYS = 7;

    private const MAX_EXPIRY_DAYS = 365;

    private const DEFAULT_RATE_PER_MINUTE = 60;

    private const MIN_RATE_PER_MINUTE = 5;

    private const MAX_RATE_PER_MINUTE = 600;

    private const OTP_TTL_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

    private const OTP_RESEND_SECONDS = 60;

    // A guest session may outlive the portal's own JWT TTL; the share's expiry bounds it, and a
    // never-expiring share still ends here.
    private const MAX_GUEST_TTL_SECONDS = 2592000;

    public function __construct(
        private readonly PortalAudit $audit,
        private readonly PortalJwt $jwt,
    ) {}

    /**
     * The panel: every share, newest first, and the pages a share may grant.
     *
     * @return array{shares: list<array<string, mixed>>, pages: list<array{key: string, label: string, isReady: bool}>}
     */
    public function index(): array
    {
        $rows = $this->connection()->table('shared_pages')->orderByDesc('id')->get();
        $rulesByShare = $this->connection()->table('shared_page_rules')->get()->groupBy('share_id');

        $shares = [];
        foreach ($rows as $row) {
            $rules = $rulesByShare->get($row->id, collect())->pluck('rule_key')->all();
            $shares[] = $this->present($row, $rules);
        }

        return ['shares' => $shares, 'pages' => $this->pages()];
    }

    /**
     * @return array{share: array<string, mixed>, password: string}
     */
    public function create(Employee $actor, Request $request): array
    {
        $data = $this->validated($request);
        $password = self::password();
        $code = $this->codeFor($data['slug']);

        $id = (int) $this->connection()->table('shared_pages')->insertGetId([
            'label' => $data['label'],
            'guest_name' => $data['guestName'],
            'code' => $code,
            'password_hash' => Hash::make($password),
            'password_value' => $password,
            'email' => $data['email'],
            'require_otp' => $data['requireOtp'],
            'expires_at' => $data['expiresAt'],
            'rate_per_minute' => $data['ratePerMinute'],
            'created_by' => (int) $actor->getKey(),
        ]);
        $this->writeRules($id, $data['rules']);
        $this->audit->record($actor, PortalLogAction::INSERT, 'manage.shares', '{UserName|You} created the shared page '.$data['label'], (string) $id, $request);

        if ($data['email'] !== null) {
            $this->mailPassword($code, $data['email'], $data['label'], $data['guestName'] ?? $data['label'], $password, $data['expiresAt'], false);
        }

        return ['share' => $this->present($this->row($id), $data['rules']), 'password' => $password];
    }

    /**
     * @return array{share: array<string, mixed>}
     */
    public function update(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        $data = $this->validated($request);

        $update = [
            'label' => $data['label'],
            'guest_name' => $data['guestName'],
            'email' => $data['email'],
            'require_otp' => $data['requireOtp'],
            'expires_at' => $data['expiresAt'],
            'rate_per_minute' => $data['ratePerMinute'],
        ];
        // A slug left blank keeps the link the share already has; a typed one replaces it.
        if ($data['slug'] !== null) {
            $update['code'] = $this->codeFor($data['slug'], $id);
        }
        $this->connection()->table('shared_pages')->where('id', $id)->update($update);
        $this->writeRules($id, $data['rules']);
        $this->audit->record($actor, PortalLogAction::PATCH, 'manage.shares', '{UserName|You} changed the shared page '.$data['label'], (string) $id, $request);

        return ['share' => $this->present($this->row($id), $data['rules'])];
    }

    /**
     * @return array{share: array<string, mixed>}
     */
    public function revoke(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        $this->connection()->table('shared_pages')->where('id', $id)->update(['revoked_at' => now()]);
        // A pending code is spent with the share.
        $this->connection()->table('shared_page_otps')->where('share_id', $id)->delete();
        $this->audit->record($actor, PortalLogAction::DELETE, 'manage.shares', '{UserName|You} revoked the shared page '.trim((string) $row->label), (string) $id, $request);

        return ['share' => $this->present($this->row($id), $this->rulesFor($id))];
    }

    /**
     * @return array{password: string}
     */
    public function regenerate(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        $password = self::password();
        $this->connection()->table('shared_pages')->where('id', $id)->update([
            'password_hash' => Hash::make($password),
            'password_value' => $password,
        ]);
        $this->audit->record($actor, PortalLogAction::PATCH, 'manage.shares', '{UserName|You} changed the password of '.trim((string) $row->label), (string) $id, $request);

        if ($row->email !== null && trim((string) $row->email) !== '') {
            $this->mailPassword((string) $row->code, trim((string) $row->email), (string) $row->label, $this->displayName($row), $password, $row->expires_at === null ? null : (string) $row->expires_at, true);
        }

        return ['password' => $password];
    }

    public function qr(int $id): string
    {
        $row = $this->row($id);
        $renderer = new ImageRenderer(new RendererStyle(360, 1), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($this->link((string) $row->code));
    }

    /**
     * What the sign-in screen needs before a password is typed. Never the email itself.
     *
     * @return array{label: string, hasEmail: bool}
     */
    public function info(string $code): array
    {
        $share = $this->shareByCode($code);

        return ['label' => $this->displayName($share), 'hasEmail' => $this->hasEmail($share)];
    }

    /**
     * The password step: right password, then a mailed code when the share asks for one, or the
     * token straight away when it does not.
     *
     * @return array<string, mixed>
     */
    public function access(Request $request, string $code): array
    {
        $share = $this->shareByCode($code);
        $this->checkPassword($share, (string) $request->input('password', ''));

        if (! $this->needsOtp($share)) {
            return $this->issued($request, $share);
        }

        $this->sendOtp($share);

        return ['otpRequired' => true];
    }

    /**
     * The code step, or the whole sign-in when the share asks for no code.
     *
     * @return array<string, mixed>
     */
    public function verify(Request $request, string $code): array
    {
        $share = $this->shareByCode($code);
        $this->checkPassword($share, (string) $request->input('password', ''));

        if ($this->needsOtp($share)) {
            $this->checkOtp($share, (string) $request->input('otp', ''));
        }

        return $this->issued($request, $share);
    }

    /**
     * @return array{sent: true}
     */
    public function resend(Request $request, string $code): array
    {
        $share = $this->shareByCode($code);
        $this->checkPassword($share, (string) $request->input('password', ''));
        if (! $this->needsOtp($share)) {
            abort(422, 'This shared page does not ask for a code.');
        }
        $this->sendOtp($share, true);

        return ['sent' => true];
    }

    /**
     * The stored guest session, re-read so the portal can drop a revoked or expired share.
     *
     * @return array{label: string, rules: list<string>}
     */
    public function session(PortalGuest $guest): array
    {
        return ['label' => $this->displayName($this->row($guest->shareId)), 'rules' => array_values($guest->rules)];
    }

    /**
     * @return array{label: string, guestName: ?string, slug: ?string, email: ?string, requireOtp: bool, expiresAt: ?string, ratePerMinute: int, rules: list<string>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'guestName' => ['nullable', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'requireOtp' => ['nullable', 'boolean'],
            'expiresInDays' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_EXPIRY_DAYS],
            'expiresAt' => ['nullable', 'date'],
            'neverExpires' => ['nullable', 'boolean'],
            'ratePerMinute' => ['nullable', 'integer', 'min:'.self::MIN_RATE_PER_MINUTE, 'max:'.self::MAX_RATE_PER_MINUTE],
            'rules' => ['required', 'array', 'min:1'],
            'rules.*' => ['string', 'max:100'],
        ]);

        $label = trim($data['label']);
        if ($label === '') {
            abort(422, 'Give the shared page a name.');
        }
        $email = isset($data['email']) && trim((string) $data['email']) !== '' ? trim((string) $data['email']) : null;
        $guestName = isset($data['guestName']) && trim((string) $data['guestName']) !== '' ? trim((string) $data['guestName']) : null;
        $slug = PortalCredentials::slug($data['slug'] ?? null);

        // Only pages that have been verified guest-safe can be granted.
        $ready = $this->readyRules();
        $rules = array_values(array_unique(array_map(static fn (mixed $key): string => (string) $key, $data['rules'])));
        foreach ($rules as $key) {
            if (! in_array($key, $ready, true)) {
                abort(422, 'That page is not ready to share yet.');
            }
        }
        if ($rules === []) {
            abort(422, 'Choose at least one page.');
        }

        return [
            'label' => $label,
            'guestName' => $guestName,
            'slug' => $slug,
            'email' => $email,
            // Absent means ask, as every share did before the switch existed.
            'requireOtp' => (bool) ($data['requireOtp'] ?? true),
            'expiresAt' => $this->expiresAt($data),
            'ratePerMinute' => (int) ($data['ratePerMinute'] ?? self::DEFAULT_RATE_PER_MINUTE),
            'rules' => $rules,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function expiresAt(array $data): ?string
    {
        if (($data['neverExpires'] ?? false) === true) {
            return null;
        }
        $expiresAt = $data['expiresAt'] ?? null;
        $days = (int) ($data['expiresInDays'] ?? self::DEFAULT_EXPIRY_DAYS);

        return $expiresAt !== null
            ? date('Y-m-d H:i:s', strtotime((string) $expiresAt))
            : now()->addDays($days > 0 ? $days : self::DEFAULT_EXPIRY_DAYS)->toDateTimeString();
    }

    /**
     * @return list<array{key: string, label: string, isReady: bool}>
     */
    private function pages(): array
    {
        $catalog = PortalAccessGraph::load()->catalog();
        $pages = [];
        foreach ($this->connection()->table('shared_page_shareable')->get() as $row) {
            $key = (string) $row->rule_key;
            $entry = $catalog[$key] ?? null;
            $pages[] = [
                'key' => $key,
                'label' => $entry?->label ?? $key,
                'isReady' => (bool) $row->is_ready,
                'sortOrder' => (int) ($entry?->sort_order ?? 0),
            ];
        }
        usort($pages, static fn (array $left, array $right): int => $left['sortOrder'] <=> $right['sortOrder']);

        return array_map(
            static fn (array $page): array => ['key' => $page['key'], 'label' => $page['label'], 'isReady' => $page['isReady']],
            $pages,
        );
    }

    /**
     * @return list<string>
     */
    private function readyRules(): array
    {
        return $this->connection()->table('shared_page_shareable')->where('is_ready', 1)->pluck('rule_key')->all();
    }

    /**
     * @return list<string>
     */
    private function rulesFor(int $shareId): array
    {
        return $this->connection()->table('shared_page_rules')->where('share_id', $shareId)->orderBy('rule_key')->pluck('rule_key')->all();
    }

    /**
     * @param  list<string>  $rules
     */
    private function writeRules(int $shareId, array $rules): void
    {
        $this->connection()->table('shared_page_rules')->where('share_id', $shareId)->delete();
        foreach ($rules as $key) {
            $this->connection()->table('shared_page_rules')->insert(['share_id' => $shareId, 'rule_key' => $key]);
        }
    }

    /**
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private function present(object $row, array $rules): array
    {
        return [
            'id' => (string) (int) $row->id,
            'label' => (string) $row->label,
            'guestName' => $row->guest_name === null ? null : (string) $row->guest_name,
            'code' => (string) $row->code,
            'link' => $this->link((string) $row->code),
            // The readable password, so the developer can send it again. Null for a share made before
            // the value was kept, until its password is regenerated.
            'password' => $row->password_value === null ? null : (string) $row->password_value,
            'email' => $row->email === null ? null : (string) $row->email,
            'requireOtp' => (bool) $row->require_otp,
            'expiresAt' => $row->expires_at === null ? null : (string) $row->expires_at,
            'ratePerMinute' => (int) $row->rate_per_minute,
            'isRevoked' => $row->revoked_at !== null,
            'lastUsedAt' => $row->last_used_at === null ? null : (string) $row->last_used_at,
            'rules' => array_values($rules),
        ];
    }

    private function row(int $id): object
    {
        $row = $this->connection()->table('shared_pages')->where('id', $id)->first();
        if ($row === null) {
            abort(404, 'That shared page was not found.');
        }

        return $row;
    }

    // The share a link names. Unknown, revoked and expired all answer the same 404, so a link cannot be
    // probed for whether it ever existed.
    private function shareByCode(string $code): object
    {
        $share = $this->connection()->table('shared_pages')->where('code', $code)->first();
        if ($share === null || $share->revoked_at !== null) {
            abort(404, 'This link is not active.');
        }
        if ($share->expires_at !== null && strtotime((string) $share->expires_at) <= now()->getTimestamp()) {
            abort(404, 'This link is not active.');
        }

        return $share;
    }

    private function checkPassword(object $share, string $password): void
    {
        if (! Hash::check($password, (string) $share->password_hash)) {
            abort(422, 'That password is not right.');
        }
    }

    private function hasEmail(object $share): bool
    {
        return $share->email !== null && trim((string) $share->email) !== '';
    }

    // A code needs somewhere to go, and the developer can choose to mail only the password.
    private function needsOtp(object $share): bool
    {
        return $this->hasEmail($share) && (bool) $share->require_otp;
    }

    private function checkOtp(object $share, string $otp): void
    {
        $row = $this->connection()->table('shared_page_otps')->where('share_id', $share->id)->first();
        if ($row === null || strtotime((string) $row->expires_at) <= now()->getTimestamp()) {
            abort(422, 'Ask for a new code.');
        }
        if ((int) $row->attempts >= self::OTP_MAX_ATTEMPTS) {
            abort(422, 'Too many tries. Ask for a new code.');
        }
        if (! Hash::check($otp, (string) $row->code_hash)) {
            $this->connection()->table('shared_page_otps')->where('share_id', $share->id)->increment('attempts');
            abort(422, 'That code is not right.');
        }
        $this->connection()->table('shared_page_otps')->where('share_id', $share->id)->delete();
    }

    private function sendOtp(object $share, bool $isResend = false): void
    {
        $existing = $this->connection()->table('shared_page_otps')->where('share_id', $share->id)->first();
        if ($existing !== null && strtotime((string) $existing->issued_at) > now()->getTimestamp() - self::OTP_RESEND_SECONDS && ! $isResend) {
            return; // the first code just went out; a second press cannot mint another
        }
        if ($existing !== null && $isResend && strtotime((string) $existing->issued_at) > now()->getTimestamp() - self::OTP_RESEND_SECONDS) {
            abort(429, 'Wait a moment before asking for another code.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->connection()->table('shared_page_otps')->updateOrInsert(
            ['share_id' => $share->id],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'issued_at' => now(), 'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES)],
        );
        $link = $this->link((string) $share->code);
        PortalLetters::sendAfterResponse(
            trim((string) $share->email),
            'Your code for '.trim((string) $share->label),
            'Hi '.$this->displayName($share).','
                ."\n\nYour code is **".$code.'**. It is good for '.self::OTP_TTL_MINUTES.' minutes.'
                ."\n\nOpen the shared page: [".$link.']('.$link.')'
                ."\n\nBest Regards,\n".PortalNotificationEmails::FROM_NAME,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function issued(Request $request, object $share): array
    {
        $rules = $this->rulesFor((int) $share->id);
        $ttl = $this->guestTtl($share);
        $token = $this->jwt->issue([
            'guest' => true,
            'share' => (int) $share->id,
            'label' => $this->displayName($share),
            'rules' => $rules,
        ], $ttl);
        $this->connection()->table('shared_page_visits')->insert([
            'share_id' => (int) $share->id,
            'ip' => $request->ip(),
        ]);
        $this->connection()->table('shared_pages')->where('id', $share->id)->update(['last_used_at' => now()]);

        return [
            'token' => $token,
            'expiresAt' => now()->addSeconds($ttl)->toIso8601String(),
            'label' => $this->displayName($share),
            'rules' => $rules,
        ];
    }

    private function guestTtl(object $share): int
    {
        if ($share->expires_at === null) {
            return self::MAX_GUEST_TTL_SECONDS;
        }

        return max(60, min(strtotime((string) $share->expires_at) - now()->getTimestamp(), self::MAX_GUEST_TTL_SECONDS));
    }

    private function newCode(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = PortalCredentials::code();
            if (! $this->connection()->table('shared_pages')->where('code', $code)->exists()) {
                return $code;
            }
        }

        abort(500, 'Could not make a link code.');
    }

    // The link's code: the typed slug when there is one, a generated one otherwise.
    private function codeFor(?string $slug, ?int $exceptId = null): string
    {
        if ($slug === null) {
            return $this->newCode();
        }

        $taken = $this->connection()->table('shared_pages')->where('code', $slug)
            ->when($exceptId !== null, static fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();
        if ($taken) {
            abort(422, 'That link slug is already taken.');
        }

        return $slug;
    }

    // What the guest is called on their own screens: their name when the developer set one.
    private function displayName(object $share): string
    {
        $name = trim((string) ($share->guest_name ?? ''));

        return $name !== '' ? $name : (string) $share->label;
    }

    // The link and password letter, on creation and when the password is replaced.
    private function mailPassword(string $code, string $email, string $label, string $name, string $password, ?string $expiresAt, bool $isNewPassword): void
    {
        $expiry = $expiresAt === null
            ? 'It does not expire until it is revoked.'
            : 'It is good until '.date('j M Y, g:i a', strtotime($expiresAt)).'.';
        $link = $this->link($code);

        PortalLetters::send(
            $email,
            ($isNewPassword ? 'Your new password for ' : 'Your link and password for ').$label,
            'Hi '.$name.','
                ."\n\n".($isNewPassword
                    ? 'The password for your read-only shared page, **'.$label.'**, was changed. The old one no longer works.'
                    : 'A read-only shared page is ready for you: **'.$label.'**.')
                ."\n\nLink: [".$link.']('.$link.')'
                ."\nPassword: **".$password.'**'
                ."\n\nOr scan this code with your phone to open the link:"
                ."\n\n![QR code for the link](cid:".self::QR_IMAGE.')'
                ."\n\n".$expiry.' It is read-only: you can look, not change anything.'
                ."\n\nBest Regards,\n".PortalNotificationEmails::FROM_NAME,
            [['name' => self::QR_IMAGE, 'data' => base64_encode(PortalQrPng::png($link)), 'mime' => 'image/png']],
        );
    }

    public function link(string $code): string
    {
        $base = rtrim((string) config('products.catalog.portal.public_url', ''), '/');

        return ($base === '' ? '' : $base).'/share/'.$code;
    }

    private static function password(): string
    {
        return PortalCredentials::password();
    }

    private function connection(): Connection
    {
        return DB::connection('portal');
    }
}
