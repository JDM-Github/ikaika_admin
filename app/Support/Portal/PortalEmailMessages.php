<?php

namespace App\Support\Portal;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailBlock;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Administration / Send Email: the outbox.
 *
 * The row is written before the first message leaves, so a transport that dies halfway still
 * leaves an account of what went out. Every recipient is delivered on its own and a failure is
 * recorded against that member alone. A delivered notice also drops an inbox row, because the
 * portal bell is where a member looks. Addresses are used to send and are never stored: the
 * outbox records who was reached, by name.
 */
final class PortalEmailMessages
{
    public const CACHE_TTL_SECONDS = 30;

    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * @var list<int>
     */
    public const PAGE_SIZES = [10, 25, 50, 100];

    private const CACHE_VERSION_KEY = PortalCache::DATA_VERSION_KEY;

    private const MAX_SUBJECT = 255;

    // A send that failed everywhere should not carry a thousand lines of transport noise.
    private const MAX_FAILURES = 25;

    // What a failed CC and BCC copy is filed under; it has no one member to name.
    private const COPY_NAME = 'CC and BCC copy';

    private const MAX_REASON = 191;

    /**
     * Table column key => SQL columns. Unknown keys fall back to newest first.
     *
     * @var array<string, list<string>>
     */
    private const SORTABLE = [
        'sent' => ['date_created', 'id'],
        'subject' => ['subject', 'id'],
        'recipients' => ['recipient_count', 'id'],
        'status' => ['status', 'id'],
    ];

    public function __construct(
        private readonly PortalEmail $blocks,
        private readonly PortalEmailAudience $audience,
        private readonly PortalTimezone $timezone,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{total: int, sent: int, partial: int, failed: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function list(Request $request): array
    {
        $category = PortalEmail::category($request->query('category'));
        $status = $this->status($request->query('status'));
        [$from, $to] = [$this->day($request->query('from')), $this->day($request->query('to'))];
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->query('q', ''));
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:email:messages:'.$version.':'.($category ?? 'all').':'.($status ?? 'all').':'
            .($from ?? '').':'.($to ?? '').':'.$page.':'.$perPage.':'.md5($search).':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $category,
            $status,
            $from,
            $to,
            $perPage,
            $page,
            $search,
            $sort,
            $dir,
        ): array {
            return $this->build($category, $status, $from, $to, $perPage, $page, $search, $sort, $dir);
        });
    }

    /**
     * One sent email, with the body and footer it went out with.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function show(int $id): array
    {
        $row = $this->rowOrFail($id);
        $senderId = is_numeric($row->sent_by) ? (int) $row->sent_by : null;
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:email:messages:'.$version.':item:'.$row->getKey();

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($row, $senderId): array {
            return [
                'section' => 'administration',
                'resource' => 'emails',
                'data' => PortalEmailPresenter::detail($row, $this->senders($senderId === null ? [] : [$senderId])),
            ];
        });
    }

    /**
     * Where the recipients' inbox notice opens, if anywhere: a report reminder opens the form the
     * missing report is filed on. Only a page every member can reach is accepted.
     */
    private function validatedLinkPath(Request $request): ?string
    {
        $value = trim((string) $request->input('linkPath', ''));
        if ($value === '') {
            return null;
        }
        if (! in_array($value, PortalShellPath::EMAIL_LINKS, true)) {
            abort(422, 'That is not a page an email notice can open.');
        }

        return $value;
    }

    /**
     * The email body exactly as a send would build it, for the composer's preview and the Footers
     * editor's. The wording may be empty or unfinished, since it is still being written; only its length
     * is held to a send's limit. A draft footer comes as footerBody/footerFormat instead of a saved id.
     *
     * @return array{section: string, resource: string, data: array{html: string}}
     */
    public function preview(Request $request): array
    {
        $format = PortalEmailMarkup::validated($request->input('format'));
        $body = (string) $request->input('body', '');
        PortalEmailMarkup::holdToLimit($body, $format);
        // A footer still being written in the Footers editor rides in as text; otherwise a saved one by id.
        if ($request->has('footerBody')) {
            $footerFormat = PortalEmailMarkup::validated($request->input('footerFormat'));
            $footerBody = trim((string) $request->input('footerBody', ''));
            PortalEmailMarkup::holdToLimit($footerBody, $footerFormat);
        } else {
            $footerId = $this->validatedFooterId($request);
            $footer = $footerId === null ? null : $this->blocks->footer($footerId);
            $footerBody = $footer === null ? null : trim((string) $footer->body);
            $footerFormat = $this->blockFormat($footer);
        }

        return [
            'section' => 'administration',
            'resource' => 'emails',
            'data' => ['html' => PortalEmailMarkup::document(trim($body), $footerBody, $format, $footerFormat)],
        ];
    }

    /**
     * Send one email to the chosen audience, then answer with the row that records it.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function send(Employee $actor, Request $request): array
    {
        $category = PortalEmail::category($request->input('category'));
        if ($category === null) {
            abort(422, 'Choose what this email is about.');
        }

        $subject = $this->validatedSubject($request);
        $format = PortalEmailMarkup::validated($request->input('format'));
        $body = $this->validatedBody($request, $format);
        $audience = PortalEmailAudience::audience($request->input('audience'));
        if ($audience === null) {
            abort(422, 'Choose who this email is for.');
        }
        $filter = $this->audience->validatedFilter($audience, $request);
        $cc = PortalEmailAudience::validatedAddresses($request->input('cc', []), PortalEmailAudience::MAX_COPIES, 'CC', false);
        $bcc = PortalEmailAudience::validatedAddresses($request->input('bcc', []), PortalEmailAudience::MAX_COPIES, 'BCC', false);
        $footerId = $this->validatedFooterId($request);
        $linkPath = $this->validatedLinkPath($request);
        [$fromName, $replyTo] = $this->sender($actor, $request);
        $footer = $footerId === null ? null : $this->blocks->footer($footerId);
        $footerBody = $footer === null ? null : trim((string) $footer->body);
        $footerFormat = $footer === null ? null : $this->blockFormat($footer);

        return $this->deliver(
            $actor,
            $request,
            $category,
            $subject,
            $body,
            $format,
            $footerId,
            $footerBody,
            $footerFormat,
            $audience,
            $filter,
            $cc,
            $bcc,
            $fromName,
            $replyTo,
            $linkPath,
        );
    }

    /**
     * A fixed notice to chosen members, as the portal itself: no footer, no copies. Missing Reports'
     * reminders go this way, so they land in the outbox and the bell exactly as a sent email does.
     *
     * @param  list<int>  $memberIds
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function sendToMembers(Employee $actor, Request $request, array $memberIds, string $subject, string $body, string $linkPath): array
    {
        return $this->deliver(
            $actor,
            $request,
            'notice',
            $subject,
            $body,
            PortalEmailMarkup::FORMAT_MARKUP,
            null,
            null,
            null,
            PortalEmailAudience::MEMBERS,
            ['departments' => [], 'roles' => [], 'memberIds' => array_values($memberIds), 'emails' => []],
            [],
            [],
            null,
            null,
            $linkPath,
        );
    }

    /**
     * Delivers a validated email and answers with the row that records it.
     *
     * @param  array{departments: list<string>, roles: list<string>, memberIds: list<int>, emails: list<string>}  $filter
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    private function deliver(
        Employee $actor,
        Request $request,
        string $category,
        string $subject,
        string $body,
        string $format,
        ?int $footerId,
        ?string $footerBody,
        ?string $footerFormat,
        string $audience,
        array $filter,
        array $cc,
        array $bcc,
        ?string $fromName,
        ?string $replyTo,
        ?string $linkPath,
    ): array {
        $recipients = $this->audience->recipients($audience, $filter);
        if ($recipients === []) {
            abort(422, 'That audience reaches nobody.');
        }

        $employeeId = (int) $actor->getKey();
        $messageId = $this->openRow(
            $category,
            $subject,
            $body,
            $format,
            $footerId,
            $footerBody,
            $footerFormat,
            $audience,
            $filter,
            $cc,
            $bcc,
            $fromName,
            $replyTo,
            $recipients,
            $employeeId,
        );

        $sent = 0;
        $failures = [];
        $reached = [];
        if ($audience === PortalEmailAudience::CUSTOM) {
            // Typed addresses go as one email, as a mail client sends it: To and Cc on show, Bcc hidden.
            try {
                Mail::to(array_column($recipients, 'email'))->cc($cc)->bcc($bcc)
                    ->send($this->mail($subject, $body, $footerBody, $format, $footerFormat, $fromName, $replyTo));
                $sent = count($recipients);
            } catch (Throwable $error) {
                $reason = $this->reason($error);
                foreach (array_slice($recipients, 0, self::MAX_FAILURES) as $recipient) {
                    $failures[] = ['employeeId' => null, 'name' => $recipient['name'], 'reason' => $reason];
                }
            }
        } else {
            foreach ($recipients as $recipient) {
                try {
                    Mail::to($recipient['email'])->send($this->mail($subject, $body, $footerBody, $format, $footerFormat, $fromName, $replyTo));
                    $sent++;
                    if ($recipient['id'] !== null) {
                        $reached[] = $recipient['id'];
                    }
                } catch (Throwable $error) {
                    if (count($failures) < self::MAX_FAILURES) {
                        $failures[] = [
                            'employeeId' => $recipient['id'],
                            'name' => $recipient['name'],
                            'reason' => $this->reason($error),
                        ];
                    }
                }
            }
        }

        // A member audience keeps its private copies, so CC and BCC share one more: CC people see each other, BCC nobody.
        if ($audience !== PortalEmailAudience::CUSTOM && ($cc !== [] || $bcc !== [])) {
            try {
                $copy = $cc !== [] ? Mail::to($cc)->bcc($bcc) : Mail::bcc($bcc);
                $copy->send($this->mail($subject, $body, $footerBody, $format, $footerFormat, $fromName, $replyTo));
            } catch (Throwable $error) {
                $failures[] = ['employeeId' => null, 'name' => self::COPY_NAME, 'reason' => $this->reason($error)];
            }
        }

        $failed = count($recipients) - $sent;
        $this->closeRow($messageId, $sent, $failed, $failures);

        if ($reached !== []) {
            $this->audit->notifyEach(
                $reached,
                PortalNotificationType::EMAIL_SENT,
                'New email',
                PortalActivityCopy::notifyEmailArrived($subject, PortalActivityCopy::actorName($actor, $request, true)),
                $linkPath,
                $linkPath === null ? null : 'Open',
                ['emailId' => (string) $messageId, 'category' => $category],
                $employeeId,
            );
        }

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            PortalEmail::CORE_RESOURCE,
            PortalActivityCopy::sentEmail($subject, $sent),
            (string) $messageId,
            $request,
            [
                'category' => $category,
                'subject' => $subject,
                'audience' => $audience,
                // Typed addresses stay in the outbox row; the ledger that syncs out only counts them.
                'audienceFilter' => [...$filter, 'emails' => count($filter['emails'])],
                'cc' => count($cc),
                'bcc' => count($bcc),
                'asSender' => $fromName !== null,
                'recipientCount' => count($recipients),
                'sent' => $sent,
                'failed' => $failed,
            ],
        );
        $this->bumpCache();

        return [
            'section' => 'administration',
            'resource' => 'emails',
            'data' => PortalEmailPresenter::detail($this->rowOrFail($messageId), $this->senders([$employeeId])),
        ];
    }

    // The fixed test mail `php artisan portal:mail-test` sends.
    public static function testMail(): PortalEmailMail
    {
        return new PortalEmailMail(
            'IKAIKA Portal mail test',
            "This is a **test** from the portal.\nIf you can read this, sending works.",
            'Sent by the portal\'s mail test.',
        );
    }

    /**
     * The server's own acceptance of a message ("250 OK id=..."), which a host can trace. Only that
     * line leaves: the transcript above it holds the AUTH exchange.
     */
    public static function acceptance(?SentMessage $sent): ?string
    {
        $accepted = null;
        foreach (preg_split('/\R/', (string) $sent?->getDebug()) ?: [] as $line) {
            if (preg_match('/<\s*(250[ -].*(?:queued|id=).*)$/i', $line, $match) === 1) {
                $accepted = trim($match[1]);
            }
        }

        return $accepted;
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    private function mail(
        string $subject,
        string $body,
        ?string $footerBody,
        string $format,
        ?string $footerFormat,
        ?string $fromName,
        ?string $replyTo,
    ): PortalEmailMail {
        return new PortalEmailMail(
            $subject,
            $body,
            $footerBody,
            $format,
            $footerFormat ?? PortalEmailMarkup::FORMAT_MARKUP,
            $fromName,
            $replyTo,
        );
    }

    /**
     * "Send as me": the sender's own name on the portal's address, and replies to their own mailbox.
     * A developer acting in Developer mode goes out as "A developer" with no reply address, so the
     * mail never names who stood behind it. Off, both are null and the mail is the portal's.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function sender(Employee $actor, Request $request): array
    {
        if (! $request->boolean('asSender')) {
            return [null, null];
        }

        $name = PortalActivityCopy::actorName($actor, $request, true);
        $fromName = $name.' via '.(string) config('mail.from.name');
        if ($name === PortalAccessGraph::DEVELOPER_NAME_CAPITAL) {
            return [$fromName, null];
        }

        $email = strtolower(trim((string) ($actor->email ?? '')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Your account has no email address for replies to go to.');
        }

        return [$fromName, $email];
    }

    /**
     * The row exists before anything is delivered, so a run that dies mid-send is still on record.
     *
     * @param  array{departments: list<string>, roles: list<string>, memberIds: list<int>, emails: list<string>}  $filter
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<array{id: ?int, name: string, email: string}>  $recipients
     */
    private function openRow(
        string $category,
        string $subject,
        string $body,
        string $format,
        ?int $footerId,
        ?string $footerBody,
        ?string $footerFormat,
        string $audience,
        array $filter,
        array $cc,
        array $bcc,
        ?string $fromName,
        ?string $replyTo,
        array $recipients,
        int $employeeId,
    ): int {
        return (int) $this->connection()->table('email_messages')->insertGetId([
            'category' => $category,
            'subject' => $subject,
            'body' => $body,
            'format' => $format,
            'footer_id' => $footerId,
            'footer_body' => $footerBody,
            'footer_format' => $footerFormat,
            'audience' => $audience,
            'audience_filter' => json_encode($filter),
            // Kept so All Emails can say who it went to; the ledger that syncs out never carries it.
            'recipients' => json_encode(array_map(
                static fn (array $recipient): array => ['name' => (string) $recipient['name'], 'email' => (string) $recipient['email']],
                $recipients,
            )),
            'cc' => $cc === [] ? null : json_encode($cc),
            'bcc' => $bcc === [] ? null : json_encode($bcc),
            'from_name' => $fromName,
            'reply_to' => $replyTo,
            'recipient_count' => count($recipients),
            'sent_count' => 0,
            'failed_count' => 0,
            'failures' => null,
            'status' => PortalEmailPresenter::STATUS_SENT,
            'sent_by' => $employeeId,
            'date_sent' => null,
            'date_created' => $this->timezone->now()->toDateTimeString(),
        ]);
    }

    /**
     * @param  list<array{employeeId: int, name: string, reason: string}>  $failures
     */
    private function closeRow(int $messageId, int $sent, int $failed, array $failures): void
    {
        $status = $failed === 0
            ? PortalEmailPresenter::STATUS_SENT
            : ($sent === 0 ? PortalEmailPresenter::STATUS_FAILED : PortalEmailPresenter::STATUS_PARTIAL);

        $this->connection()->table('email_messages')->where('id', $messageId)->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'failures' => $failures === [] ? null : json_encode($failures),
            'status' => $status,
            'date_sent' => $this->timezone->now()->toDateTimeString(),
        ]);
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{total: int, sent: int, partial: int, failed: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    private function build(
        ?string $category,
        ?string $status,
        ?string $from,
        ?string $to,
        int $perPage,
        int $page,
        string $search,
        string $sort,
        string $dir,
    ): array {
        $query = $this->within(EmailMessage::query()->select(PortalEmailPresenter::messageColumns()), $category, $from, $to);
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->where('subject', 'like', $needle)
                    ->orWhere('body', 'like', $needle);
            });
        }

        foreach (self::SORTABLE[$sort] ?? ['date_created', 'id'] as $column) {
            $query->orderBy($column, $dir);
        }

        $pageResult = $query->paginate($perPage, ['*'], 'page', $page);

        $senderIds = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof EmailMessage && is_numeric($row->sent_by)) {
                $senderIds[] = (int) $row->sent_by;
            }
        }
        $senders = $this->senders(array_values(array_unique($senderIds)));

        $rows = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof EmailMessage) {
                $rows[] = PortalEmailPresenter::message($row, $senders);
            }
        }

        return [
            'section' => 'administration',
            'resource' => 'emails',
            'data' => $rows,
            'counts' => $this->counts($category, $from, $to),
            'meta' => [
                'current_page' => $pageResult->currentPage(),
                'per_page' => $pageResult->perPage(),
                'total' => $pageResult->total(),
                'last_page' => $pageResult->lastPage(),
            ],
        ];
    }

    /**
     * The category and the days a view is narrowed to. The status filter is left out on purpose: the
     * counts break the view down by status, so they must not be narrowed to one of them.
     *
     * @param  Builder<EmailMessage>  $query
     * @return Builder<EmailMessage>
     */
    private function within(Builder $query, ?string $category, ?string $from, ?string $to): Builder
    {
        if ($category !== null) {
            $query->where('category', $category);
        }
        // date_created is the portal's own wall clock, so a day's bounds are plain strings.
        if ($from !== null) {
            $query->where('date_created', '>=', $from.' 00:00:00');
        }
        if ($to !== null) {
            $query->where('date_created', '<=', $to.' 23:59:59');
        }

        return $query;
    }

    private function status(mixed $value): ?string
    {
        $status = strtolower(trim((string) $value));

        return in_array($status, [PortalEmailPresenter::STATUS_SENT, PortalEmailPresenter::STATUS_PARTIAL, PortalEmailPresenter::STATUS_FAILED], true)
            ? $status
            : null;
    }

    private function day(mixed $value): ?string
    {
        $day = trim((string) $value);
        if ($day === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1 || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            abort(422, 'Dates must use YYYY-MM-DD.');
        }

        return $day;
    }

    /**
     * @return array{total: int, sent: int, partial: int, failed: int}
     */
    private function counts(?string $category, ?string $from, ?string $to): array
    {
        $grouped = $this->within(EmailMessage::query(), $category, $from, $to)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sent = (int) ($grouped[PortalEmailPresenter::STATUS_SENT] ?? 0);
        $partial = (int) ($grouped[PortalEmailPresenter::STATUS_PARTIAL] ?? 0);
        $failed = (int) ($grouped[PortalEmailPresenter::STATUS_FAILED] ?? 0);

        return [
            'total' => $sent + $partial + $failed,
            'sent' => $sent,
            'partial' => $partial,
            'failed' => $failed,
        ];
    }

    private function rowOrFail(int $id): EmailMessage
    {
        if ($id <= 0) {
            abort(404, 'That email was not found.');
        }

        $row = EmailMessage::query()->select(PortalEmailPresenter::messageDetailColumns())->where('id', $id)->first();
        if (! $row instanceof EmailMessage) {
            abort(404, 'That email was not found.');
        }

        return $row;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{name: string, idNo: ?string}>
     */
    private function senders(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $directory = [];
        foreach (
            Employee::query()
                ->select(PortalEmailPresenter::employeeColumns())
                ->whereIn('id', $ids)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get() as $employee
        ) {
            if (! $employee instanceof Employee) {
                continue;
            }
            $directory[(int) $employee->getKey()] = [
                'name' => PortalSubmittedReportPresenter::memberName(
                    is_string($employee->first_name) ? $employee->first_name : null,
                    is_string($employee->last_name) ? $employee->last_name : null,
                ),
                'idNo' => is_string($employee->id_no) && $employee->id_no !== '' ? $employee->id_no : null,
            ];
        }

        return $directory;
    }

    private function validatedSubject(Request $request): string
    {
        $subject = trim((string) $request->input('subject', ''));
        if ($subject === '') {
            abort(422, 'Give the email a subject.');
        }
        if (strlen($subject) > self::MAX_SUBJECT) {
            abort(422, 'The subject cannot exceed 255 characters.');
        }

        return $subject;
    }

    private function validatedBody(Request $request, string $format): string
    {
        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            abort(422, 'The email needs a body.');
        }
        PortalEmailMarkup::holdToLimit($body, $format);

        return $body;
    }

    private function blockFormat(?EmailBlock $block): string
    {
        return PortalEmailMarkup::format($block?->format) ?? PortalEmailMarkup::FORMAT_MARKUP;
    }

    private function validatedFooterId(Request $request): ?int
    {
        $raw = $request->input('footerId');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (int) $raw <= 0) {
            abort(422, 'Choose a footer from the library.');
        }

        $id = (int) $raw;
        if ($this->blocks->footer($id) === null) {
            abort(422, 'Choose a footer from the library.');
        }

        return $id;
    }

    /**
     * What went wrong for one member, with any address in the transport's own words hidden:
     * the outbox is not a place to leave an email sitting.
     */
    private function reason(Throwable $error): string
    {
        $message = trim($error->getMessage());
        if ($message === '') {
            return 'The mail server refused the message.';
        }

        $redacted = preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '[address hidden]', $message) ?? $message;

        return strlen($redacted) > self::MAX_REASON ? substr($redacted, 0, self::MAX_REASON) : $redacted;
    }

    private function perPage(Request $request): int
    {
        $size = $request->integer('per_page', self::DEFAULT_PAGE_SIZE);

        return in_array($size, self::PAGE_SIZES, true) ? $size : self::DEFAULT_PAGE_SIZE;
    }

    private function connection(): Connection
    {
        return Employee::query()->getConnection();
    }
}
