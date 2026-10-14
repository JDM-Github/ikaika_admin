<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\Employee;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
     * The panel: every share, newest first, and the pages a share may grant. The actor and request
     * are read only to say, per page, the reach the actor holds for it -- the form offers no more.
     *
     * @return array{shares: list<array<string, mixed>>, pages: list<array{key: string, label: string, group: ?string, isReady: bool, supportsReach: bool, reach: ?string}>}
     */
    public function index(?Employee $actor = null, ?Request $request = null): array
    {
        $rows = $this->connection()->table('shared_pages')->orderByDesc('id')->get();
        $rulesByShare = $this->connection()->table('shared_page_rules')->get()->groupBy('share_id');
        // One lookup for every creator, so each share can name who holds its password.
        $creatorNames = $this->creatorNames($rows->pluck('created_by')->all());
        $viewerId = $actor === null ? null : (int) $actor->getKey();

        $shares = [];
        foreach ($rows as $row) {
            $shareRules = $rulesByShare->get($row->id, collect());
            $shares[] = $this->present($row, $shareRules->pluck('rule_key')->all(), self::reachesFrom($shareRules), $viewerId, $creatorNames[(int) $row->created_by] ?? null);
        }

        return ['shares' => $shares, 'pages' => $this->pages($actor, $request)];
    }

    /**
     * @return array{share: array<string, mixed>, password: string}
     */
    public function create(Employee $actor, Request $request): array
    {
        $data = $this->validated($request, $actor);
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
            'reach_department' => $data['reachDepartment'],
            'created_by' => (int) $actor->getKey(),
        ]);
        $this->writeRules($id, $data['rules'], $data['reaches']);
        $this->audit->record($actor, PortalLogAction::INSERT, 'manage.shares', '{UserName|You} created the shared page '.$data['label'], (string) $id, $request);

        if ($data['email'] !== null) {
            $this->mailPassword($code, $data['email'], $data['label'], $data['guestName'] ?? $data['label'], $password, $data['expiresAt'], false);
        }

        return ['share' => $this->present($this->row($id), $data['rules'], $data['reaches'], (int) $actor->getKey(), $this->creatorNameOf($id)), 'password' => $password];
    }

    /**
     * @return array{share: array<string, mixed>}
     */
    public function update(Employee $actor, Request $request, int $id): array
    {
        $row = $this->row($id);
        $data = $this->validated($request, $actor);

        $update = [
            'label' => $data['label'],
            'guest_name' => $data['guestName'],
            'email' => $data['email'],
            'require_otp' => $data['requireOtp'],
            'expires_at' => $data['expiresAt'],
            'rate_per_minute' => $data['ratePerMinute'],
            'reach_department' => $data['reachDepartment'],
        ];
        // A slug left blank keeps the link the share already has; a typed one replaces it.
        if ($data['slug'] !== null) {
            $update['code'] = $this->codeFor($data['slug'], $id);
        }
        $this->connection()->table('shared_pages')->where('id', $id)->update($update);
        $this->writeRules($id, $data['rules'], $data['reaches']);
        $this->audit->record($actor, PortalLogAction::PATCH, 'manage.shares', '{UserName|You} changed the shared page '.$data['label'], (string) $id, $request);

        return ['share' => $this->present($this->row($id), $data['rules'], $data['reaches'], (int) $actor->getKey(), $this->creatorNameOf($id))];
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

        return ['share' => $this->present($this->row($id), $this->rulesFor($id), $this->reachesFor($id), (int) $actor->getKey(), $this->creatorNameOf($id))];
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
        return ['label' => $this->displayName($this->row($guest->shareId)), 'rules' => array_keys($guest->rules)];
    }

    /**
     * @return array{label: string, guestName: ?string, slug: ?string, email: ?string, requireOtp: bool, expiresAt: ?string, ratePerMinute: int, reachDepartment: ?string, rules: list<string>, reaches: array<string, string>}
     */
    private function validated(Request $request, Employee $actor): array
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
            'reaches' => ['nullable', 'array'],
            'reaches.*' => ['nullable', 'string', 'max:16'],
        ]);

        $label = trim($data['label']);
        if ($label === '') {
            abort(422, 'Give the shared page a name.');
        }
        $email = isset($data['email']) && trim((string) $data['email']) !== '' ? trim((string) $data['email']) : null;
        $guestName = isset($data['guestName']) && trim((string) $data['guestName']) !== '' ? trim((string) $data['guestName']) : null;
        $slug = PortalCredentials::slug($data['slug'] ?? null);

        // Only pages that have been verified guest-safe can be granted; a page's option rides only with its page.
        $ready = $this->readyRules();
        $rules = array_values(array_unique(array_map(static fn (mixed $key): string => (string) $key, $data['rules'])));
        $options = [];
        foreach ($rules as $key) {
            $page = self::optionPage($key);
            if ($page !== null) {
                if (! in_array($page, $rules, true)) {
                    abort(422, 'Turn on the page an option belongs to first.');
                }
                if (! PortalRules::holds($actor, $request, $key)) {
                    abort(422, 'You cannot share a power you do not hold.');
                }
                $options[$key] = $page;

                continue;
            }
            if (! in_array($key, $ready, true)) {
                abort(422, 'That page is not ready to share yet.');
            }
        }
        if ($rules === []) {
            abort(422, 'Choose at least one page.');
        }

        // A reach rule is handed out at a reach the actor holds: never wider than their own, and
        // never at all when they do not hold the page. Other pages carry no reach.
        $catalog = PortalAccessGraph::load()->catalog();
        $provided = is_array($data['reaches'] ?? null) ? $data['reaches'] : [];
        $reaches = [];
        $needsDepartment = false;
        foreach ($rules as $key) {
            if (isset($options[$key]) || ! (bool) ($catalog[$key]->supports_reach ?? false)) {
                continue;
            }
            $held = PortalRules::reach($actor, $request, $key);
            if ($held === null) {
                abort(422, 'You cannot share a page you do not hold.');
            }
            $chosen = isset($provided[$key]) && is_string($provided[$key]) ? $provided[$key] : $held;
            if (! in_array($chosen, self::REACHES, true)) {
                abort(422, 'That reach is not one a shared page may carry.');
            }
            $reach = PortalRules::narrower($held, $chosen) ?? $held;
            // A guest has no records of their own, so a share reaches a department or every department.
            if ($reach !== 'department' && $reach !== 'all') {
                abort(422, 'A shared page reaches a department or every department; it cannot be only the person.');
            }
            $reaches[$key] = $reach;
            if ($reach === 'department') {
                $needsDepartment = true;
            }
        }
        // An option reaches no further than its page, nor than the actor holds it.
        foreach ($options as $key => $page) {
            if ((bool) ($catalog[$key]->supports_reach ?? false)) {
                $reaches[$key] = PortalRules::narrower(PortalRules::reach($actor, $request, $key), $reaches[$page] ?? 'all') ?? 'department';
            }
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
            // The department a department-reach share is scoped to, snapshotted so the guest stands in with it.
            'reachDepartment' => $needsDepartment ? (trim((string) $actor->department) ?: null) : null,
            'rules' => $rules,
            'reaches' => $reaches,
        ];
    }

    // Every reach a shared page may carry, widest last.
    private const REACHES = ['lead', 'own', 'team', 'department', 'all'];

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
     * @return list<array{key: string, label: string, group: ?string, isReady: bool, supportsReach: bool, reach: ?string, options: list<array{key: string, label: string}>}>
     */
    private function pages(?Employee $actor = null, ?Request $request = null): array
    {
        $catalog = PortalAccessGraph::load()->catalog();
        $pages = [];
        foreach ($this->connection()->table('shared_page_shareable')->get() as $row) {
            $key = (string) $row->rule_key;
            $entry = $catalog[$key] ?? null;
            $placement = self::SIDEBAR_PLACEMENT[$key] ?? null;
            $supportsReach = (bool) ($entry?->supports_reach ?? false);
            $pages[] = [
                'key' => $key,
                'label' => $entry?->label ?? $key,
                'group' => $placement['group'] ?? self::groupOf($catalog, $key),
                'isReady' => (bool) $row->is_ready,
                // The reach the actor holds, so the form offers no more than they may hand out.
                'supportsReach' => $supportsReach,
                'reach' => $supportsReach && $actor !== null && $request !== null ? PortalRules::reach($actor, $request, $key) : null,
                'sortOrder' => (int) ($placement['order'] ?? $entry?->sort_order ?? 0),
                // Its options, each only when the actor may hand it out.
                'options' => array_values(array_map(
                    static fn (string $option): array => ['key' => $option, 'label' => $catalog[$option]->label ?? $option],
                    array_filter(
                        self::OPTIONS[$key] ?? [],
                        static fn (string $option): bool => isset($catalog[$option]) && $actor !== null && $request !== null && PortalRules::holds($actor, $request, $option),
                    ),
                )),
            ];
        }
        usort($pages, static fn (array $left, array $right): int => $left['sortOrder'] <=> $right['sortOrder']);

        return array_map(
            static fn (array $page): array => ['key' => $page['key'], 'label' => $page['label'], 'group' => $page['group'], 'isReady' => $page['isReady'], 'supportsReach' => $page['supportsReach'], 'reach' => $page['reach'], 'options' => $page['options']],
            $pages,
        );
    }

    /*
     * Powers a page may lend a guest beside the page itself (2026-10-14, user): Users may also show employee
     * information, so a boss's share can open private details. Stored as rules of the share like pages.
     */
    private const OPTIONS = [
        'manage.users' => ['manage.users.information'],
    ];

    // The page an option belongs to, or null for a key that is not an option.
    private static function optionPage(string $key): ?string
    {
        foreach (self::OPTIONS as $page => $keys) {
            if (in_array($key, $keys, true)) {
                return $page;
            }
        }

        return null;
    }

    // The sidebar's own filing, where the catalog differs: References sits under Manage there, after Users.
    private const SIDEBAR_PLACEMENT = [
        'projects.references' => ['group' => 'Manage', 'order' => 860],
    ];

    // The section a page reads under: its top-level rule's label, unless the page is that rule itself.
    private static function groupOf(array $catalog, string $key): ?string
    {
        $root = $key;
        for ($hops = 0; $hops < 8; $hops += 1) {
            $parent = $catalog[$root]->parent_key ?? null;
            if ($parent === null) {
                break;
            }
            $root = $parent;
        }

        return $root === $key ? null : ($catalog[$root]->label ?? null);
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
     * The reach stored per granted page, for the pages that carry one.
     *
     * @return array<string, string>
     */
    private function reachesFor(int $shareId): array
    {
        $reaches = [];
        foreach ($this->connection()->table('shared_page_rules')->where('share_id', $shareId)->whereNotNull('reach')->get() as $row) {
            $reaches[(string) $row->rule_key] = (string) $row->reach;
        }

        return $reaches;
    }

    /**
     * @param  Collection<int, object>  $ruleRows
     * @return array<string, string>
     */
    private static function reachesFrom(Collection $ruleRows): array
    {
        $reaches = [];
        foreach ($ruleRows as $row) {
            if ($row->reach !== null) {
                $reaches[(string) $row->rule_key] = (string) $row->reach;
            }
        }

        return $reaches;
    }

    /**
     * @param  list<string>  $rules
     * @param  array<string, string>  $reaches
     */
    private function writeRules(int $shareId, array $rules, array $reaches = []): void
    {
        $this->connection()->table('shared_page_rules')->where('share_id', $shareId)->delete();
        foreach ($rules as $key) {
            $this->connection()->table('shared_page_rules')->insert(['share_id' => $shareId, 'rule_key' => $key, 'reach' => $reaches[$key] ?? null]);
        }
    }

    /**
     * @param  list<string>  $rules
     * @param  array<string, string>  $reaches
     * @return array<string, mixed>
     */
    private function present(object $row, array $rules, array $reaches = [], ?int $viewerId = null, ?string $creatorName = null): array
    {
        return [
            'id' => (string) (int) $row->id,
            'label' => (string) $row->label,
            'guestName' => $row->guest_name === null ? null : (string) $row->guest_name,
            'code' => (string) $row->code,
            'link' => $this->link((string) $row->code),
            // The kept password is its creator's alone: anyone else who may open the page reads
            // null and the name of who holds it (2026-10-14, user). Null for a share made before
            // the value was kept, until its password is regenerated.
            'password' => $row->password_value === null || (int) $row->created_by !== $viewerId ? null : (string) $row->password_value,
            'createdByName' => $creatorName,
            'email' => $row->email === null ? null : (string) $row->email,
            'requireOtp' => (bool) $row->require_otp,
            'expiresAt' => $row->expires_at === null ? null : (string) $row->expires_at,
            'ratePerMinute' => (int) $row->rate_per_minute,
            'isRevoked' => $row->revoked_at !== null,
            'lastUsedAt' => $row->last_used_at === null ? null : (string) $row->last_used_at,
            'rules' => array_values($rules),
            // Only the pages that carry a reach; the rest read as they always did.
            'reaches' => (object) $reaches,
        ];
    }

    /**
     * The name of each creator id, in one query.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function creatorNames(array $ids): array
    {
        $wanted = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($wanted === []) {
            return [];
        }

        $names = [];
        foreach ($this->connection()->table('employees')->whereIn('id', $wanted)->get(['id', 'first_name', 'last_name']) as $row) {
            $names[(int) $row->id] = trim((string) $row->first_name.' '.(string) $row->last_name);
        }

        return $names;
    }

    private function creatorNameOf(int $shareId): ?string
    {
        $row = $this->connection()->table('shared_pages')->where('id', $shareId)->first(['created_by']);

        return $row === null ? null : ($this->creatorNames([(int) $row->created_by])[(int) $row->created_by] ?? null);
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
            PortalEmailMarkup::CATEGORY_SECURITY,
            'Your code for '.trim((string) $share->label),
            PortalLetters::body(
                $this->displayName($share),
                'Use this code to open **'.PortalEmailMarkup::escape(trim((string) $share->label)).'**. It is good for '.self::OTP_TTL_MINUTES.' minutes.'
                    ."\n\n::code ".$code
                    ."\n\n::button[Open the shared page](".$link.')',
            ),
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

        $shown = PortalEmailMarkup::escape($label);
        PortalLetters::send(
            $email,
            PortalEmailMarkup::CATEGORY_SECURITY,
            ($isNewPassword ? 'Your new password for ' : 'Your link and password for ').$label,
            PortalLetters::body(
                $name,
                ($isNewPassword
                    ? 'The password for your read-only shared page, **'.$shown.'**, was changed. The old one no longer works.'
                    : 'A read-only shared page is ready for you: **'.$shown.'**.')
                    ."\n\n::button[Open the shared page](".$link.')'
                    ."\n\nYour password:"
                    ."\n\n::code ".$password
                    ."\n\nOr scan this code with your phone to open it:"
                    ."\n\n![QR code for the link](cid:".self::QR_IMAGE.')'
                    ."\n\n".$expiry.' It is read-only: you can look, not change anything.',
            ),
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
