<?php

namespace App\Support\Portal;

use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
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

    private const CACHE_VERSION_KEY = 'portal:administration:email:messages:version';

    private const MAX_SUBJECT = 255;

    private const MAX_BODY = 20000;

    // A send that failed everywhere should not carry a thousand lines of transport noise.
    private const MAX_FAILURES = 25;

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
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->query('q', ''));
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:email:messages:'.$version.':'.($category ?? 'all').':'.$page.':'.$perPage.':'.md5($search).':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $category,
            $perPage,
            $page,
            $search,
            $sort,
            $dir,
        ): array {
            return $this->build($category, $perPage, $page, $search, $sort, $dir);
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
        $body = $this->validatedBody($request);
        $audience = PortalEmailAudience::audience($request->input('audience'));
        if ($audience === null) {
            abort(422, 'Choose who this email is for.');
        }
        $filter = $this->audience->validatedFilter($audience, $request);
        $footerId = $this->validatedFooterId($request);
        $footer = $footerId === null ? null : $this->blocks->footer($footerId);
        $footerBody = $footer === null ? null : trim((string) $footer->body);

        $recipients = $this->audience->recipients($audience, $filter);
        if ($recipients === []) {
            abort(422, 'That audience reaches nobody.');
        }

        $employeeId = (int) $actor->getKey();
        $messageId = $this->openRow(
            $category,
            $subject,
            $body,
            $footerId,
            $footerBody,
            $audience,
            $filter,
            count($recipients),
            $employeeId,
        );

        $sent = 0;
        $failures = [];
        $reached = [];
        foreach ($recipients as $recipient) {
            try {
                Mail::to($recipient['email'])->send(new PortalEmailMail($subject, $body, $footerBody));
                $sent++;
                $reached[] = $recipient['id'];
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

        $failed = count($recipients) - $sent;
        $this->closeRow($messageId, $sent, $failed, $failures);

        if ($reached !== []) {
            $this->audit->notifyEach(
                $reached,
                PortalNotificationType::EMAIL_SENT,
                'New email',
                PortalActivityCopy::notifyEmailArrived($subject, PortalActivityCopy::displayName($actor)),
                null,
                null,
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
                'audienceFilter' => $filter,
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

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    /**
     * The row exists before anything is delivered, so a run that dies mid-send is still on record.
     *
     * @param  array{departments: list<string>, roles: list<string>, memberIds: list<int>}  $filter
     */
    private function openRow(
        string $category,
        string $subject,
        string $body,
        ?int $footerId,
        ?string $footerBody,
        string $audience,
        array $filter,
        int $recipientCount,
        int $employeeId,
    ): int {
        return (int) $this->connection()->table('email_messages')->insertGetId([
            'category' => $category,
            'subject' => $subject,
            'body' => $body,
            'footer_id' => $footerId,
            'footer_body' => $footerBody,
            'audience' => $audience,
            'audience_filter' => json_encode($filter),
            'recipient_count' => $recipientCount,
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
    private function build(?string $category, int $perPage, int $page, string $search, string $sort, string $dir): array
    {
        $query = EmailMessage::query()->select(PortalEmailPresenter::messageColumns());
        if ($category !== null) {
            $query->where('category', $category);
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
            'counts' => $this->counts(),
            'meta' => [
                'current_page' => $pageResult->currentPage(),
                'per_page' => $pageResult->perPage(),
                'total' => $pageResult->total(),
                'last_page' => $pageResult->lastPage(),
            ],
        ];
    }

    /**
     * @return array{total: int, sent: int, partial: int, failed: int}
     */
    private function counts(): array
    {
        $grouped = EmailMessage::query()
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

    private function validatedBody(Request $request): string
    {
        $body = trim((string) $request->input('body', ''));
        if ($body === '') {
            abort(422, 'The email needs a body.');
        }
        if (strlen($body) > self::MAX_BODY) {
            abort(422, 'The body cannot exceed 20000 characters.');
        }

        return $body;
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
