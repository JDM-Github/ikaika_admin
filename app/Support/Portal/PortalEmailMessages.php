<?php

namespace App\Support\Portal;

use App\Jobs\DeliverPortalEmail;
use App\Mail\PortalEmailMail;
use App\Modules\Portal\Models\EmailBlock;
use App\Modules\Portal\Models\EmailMessage;
use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Administration / Send Email: the outbox.
 *
 * The row is written first and reads Sending; the send itself goes to the mail queue as one copy per
 * recipient (DeliverPortalEmail), so the Send button answers at once however big the audience. Each
 * copy counts itself onto the row as it lands, a failure against that member alone, and the row
 * settles on sent, partial or failed once everyone is counted. A delivered notice also drops an inbox
 * row, because the portal bell is where a member looks. A queued copy holds its addresses only until it
 * leaves; failures and the synced ledger name people, never addresses.
 *
 * Attachments are staged before the send (PortalEmailAttachments) and claimed by its row in the same
 * transaction that opens it, so a file goes out with one email only and the outbox keeps what it was.
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
        private readonly PortalEmailAttachments $attachments,
        private readonly PortalSignature $signature,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{total: int, sent: int, partial: int, failed: int, sending: int},
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
                'data' => PortalEmailPresenter::detail(
                    $row,
                    $this->senders($senderId === null ? [] : [$senderId]),
                    $this->attachments->forMessage((int) $row->getKey()),
                ),
            ];
        });
    }

    /**
     * One file a sent email carried, for All Emails to hand back. Reading it is logged, as reading
     * anything a send carried out of the company is.
     */
    public function attachment(Employee $actor, Request $request, int $id, string $token): StreamedResponse
    {
        $row = $this->rowOrFail($id);
        $file = $this->attachments->download((int) $row->getKey(), $token);
        $this->audit->record(
            $actor,
            PortalLogAction::VIEW,
            PortalEmail::CORE_RESOURCE,
            PortalActivityCopy::downloadedEmailAttachment($file['name'], (string) $row->subject),
            (string) $row->getKey(),
            $request,
            ['attachment' => strtolower(trim($token))],
        );

        return $file['response'];
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
        // A footer carrying signature placeholders reads for whoever is composing, like a signature.
        $sender = $request->attributes->get('portalEmployee');
        if ($sender instanceof Employee) {
            $footerBody = $this->fillFooter($footerBody, $footerFormat, $sender);
        }

        return [
            'section' => 'administration',
            'resource' => 'emails',
            // A letter shows its category and subject above the message, so the preview takes them too.
            'data' => ['html' => PortalEmailMarkup::document(
                trim($body),
                $footerBody,
                $format,
                $footerFormat,
                PortalEmail::category($request->input('category')),
                trim((string) $request->input('subject', '')),
            )],
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
        $attachments = $this->attachments->staged($actor, $request->input('attachmentIds', []));
        $footer = $footerId === null ? null : $this->blocks->footer($footerId);
        $footerBody = $footer === null ? null : trim((string) $footer->body);
        $footerFormat = $footer === null ? null : $this->blockFormat($footer);
        // A footer carrying signature placeholders reads for whoever is sending, like a signature.
        $footerBody = $this->fillFooter($footerBody, $footerFormat, $actor);

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
            $attachments,
        );
    }

    /**
     * A fixed notice to chosen members, as the portal itself: the system letter with the Company footer,
     * no copies. Missing Reports' reminders go this way, so they land in the outbox and the bell exactly
     * as a sent email does.
     *
     * @param  list<int>  $memberIds
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function sendToMembers(Employee $actor, Request $request, array $memberIds, string $subject, string $body, string $linkPath): array
    {
        [$footerBody, $footerFormat] = PortalNotificationEmails::companyFooter();

        return $this->deliver(
            $actor,
            $request,
            'notice',
            $subject,
            PortalLetters::body(null, $body),
            PortalEmailMarkup::FORMAT_LETTER,
            null,
            $footerBody,
            $footerFormat,
            PortalEmailAudience::MEMBERS,
            ['departments' => [], 'roles' => [], 'memberIds' => array_values($memberIds), 'emails' => []],
            [],
            [],
            PortalNotificationEmails::FROM_NAME,
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
     * @param  list<object>  $attachments  staged rows, checked by PortalEmailAttachments::staged
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
        array $attachments = [],
    ): array {
        $recipients = $this->audience->recipients($audience, $filter);
        if ($recipients === []) {
            abort(422, 'That audience reaches nobody.');
        }

        $employeeId = (int) $actor->getKey();
        // The row and its files commit together: a file another send claimed first refuses this one whole.
        $messageId = (int) $this->connection()->transaction(fn (): int => $this->openWithAttachments(
            $attachments,
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
        ));
        $copies = $this->copies($messageId, $audience, $recipients, $cc, $bcc, [
            'title' => 'New email',
            'message' => PortalActivityCopy::notifyEmailArrived($subject, PortalActivityCopy::actorName($actor, $request, true)),
            'linkPath' => $linkPath,
            'linkLabel' => $linkPath === null ? null : 'Open',
            'payload' => ['emailId' => (string) $messageId, 'category' => $category],
            'actorId' => $employeeId,
        ]);
        try {
            Queue::bulk($copies);
        } catch (Throwable $error) {
            // Nothing will deliver it, so the row says so instead of reading Sending for ever.
            $this->closeRow($messageId, 0, count($recipients), $this->failuresFor($recipients, 'The email could not be queued for sending.'));
            $this->bumpCache();
            Log::warning('portal.email.queue.failed', ['message_id' => $messageId, 'reason' => $error->getMessage()]);
            abort(503, 'The email could not be queued. Try again in a moment.');
        }

        $this->audit->record(
            $actor,
            PortalLogAction::POST,
            PortalEmail::CORE_RESOURCE,
            PortalActivityCopy::sentEmail($subject, count($recipients)),
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
                // Queued, not yet delivered: how each copy fared is written onto the outbox row as it lands.
                'copies' => count($copies),
                // How many files and how large, never their names: the ledger syncs out.
                'attachments' => count($attachments),
                'attachmentBytes' => PortalEmailAttachments::totalBytes($attachments),
            ],
        );
        $this->bumpCache();

        return [
            'section' => 'administration',
            'resource' => 'emails',
            'data' => PortalEmailPresenter::detail(
                $this->rowOrFail($messageId),
                $this->senders([$employeeId]),
                $this->attachments->forMessage($messageId),
            ),
        ];
    }

    /**
     * Sends one queued copy of an outbox row, rebuilt from what the row kept, with the files it claimed.
     * False when the row is gone, so a copy outliving its email sends nothing.
     *
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    public function deliverCopy(int $messageId, array $to, array $cc, array $bcc): bool
    {
        $row = $this->connection()->table('email_messages')->where('id', $messageId)
            ->first(['category', 'subject', 'body', 'format', 'footer_body', 'footer_format', 'from_name', 'reply_to']);
        if ($row === null) {
            return false;
        }

        $mail = self::mailFromRow($row, $this->attachments->mailFilesFor($messageId));
        ($to === [] ? Mail::bcc($bcc) : Mail::to($to)->cc($cc)->bcc($bcc))->send($mail);

        return true;
    }

    /**
     * Writes how one copy fared onto its row, under a lock since copies land in any order. The row
     * reads Sending until every recipient is counted. The CC and BCC copy counts for nobody, so its
     * failure is named on the row but never changes the status.
     *
     * @param  list<array{id: ?int, name: string}>  $recipients
     * @param  array{title: string, message: string, linkPath: ?string, linkLabel: ?string, payload: array<string, string>, actorId: int}|null  $notice
     */
    public function settleCopy(int $messageId, array $recipients, ?Throwable $error, ?array $notice = null): void
    {
        $reason = $error === null ? null : $this->reason($error);
        $settled = $this->connection()->transaction(function () use ($messageId, $recipients, $reason): bool {
            $row = $this->connection()->table('email_messages')->where('id', $messageId)->lockForUpdate()->first(['recipient_count', 'sent_count', 'failed_count', 'failures']);
            if ($row === null) {
                return false;
            }

            $total = (int) $row->recipient_count;
            $sent = (int) $row->sent_count;
            $failed = (int) $row->failed_count;
            $failures = json_decode((string) $row->failures, true);
            $failures = is_array($failures) ? $failures : [];
            // A copy the queue ran twice cannot count anyone past the total.
            $counted = min(count($recipients), max(0, $total - $sent - $failed));

            if ($reason === null) {
                $sent += $counted;
            } else {
                $failed += $counted;
                if ($recipients === []) {
                    $failures[] = ['employeeId' => null, 'name' => self::COPY_NAME, 'reason' => $reason];
                }
                foreach (array_slice($recipients, 0, $counted) as $recipient) {
                    if (count($failures) >= self::MAX_FAILURES) {
                        break;
                    }
                    $failures[] = ['employeeId' => $recipient['id'], 'name' => $recipient['name'], 'reason' => $reason];
                }
            }

            $changes = [
                'sent_count' => $sent,
                'failed_count' => $failed,
                'failures' => $failures === [] ? null : json_encode($failures),
            ];
            if ($counted > 0 && $sent + $failed >= $total) {
                $changes['status'] = self::finalStatus($sent, $failed);
                $changes['date_sent'] = $this->timezone->now()->toDateTimeString();
            }
            $this->connection()->table('email_messages')->where('id', $messageId)->update($changes);

            return true;
        });
        if (! $settled) {
            return;
        }

        // The copy is out and counted; a bell or cache hiccup after that must not send it again.
        try {
            $reached = array_values(array_filter(array_map(static fn (array $recipient): int => (int) $recipient['id'], $recipients)));
            if ($reason === null && $notice !== null && $reached !== []) {
                $this->audit->notifyEach(
                    $reached,
                    PortalNotificationType::EMAIL_SENT,
                    $notice['title'],
                    $notice['message'],
                    $notice['linkPath'],
                    $notice['linkLabel'],
                    $notice['payload'],
                    $notice['actorId'],
                );
            }
            $this->bumpCache();
        } catch (Throwable $followUp) {
            Log::warning('portal.email.settle.followup.failed', ['message_id' => $messageId, 'reason' => $followUp->getMessage()]);
        }
    }

    // The fixed test mail `php artisan portal:mail-test` sends: the same letter every system email is.
    public static function testMail(): PortalEmailMail
    {
        return PortalLetters::letter(
            PortalEmailMarkup::CATEGORY_NOTICE,
            'IKAIKA Portal mail test',
            PortalLetters::body(null, "This is a **test** from the portal.\nIf you can read this, sending works."),
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

    /**
     * One stored email as the mail it went out as: a queued copy, and the mail test's --message, send this.
     *
     * @param  list<array{path: string, name: string, mime: string}>  $files
     */
    public static function mailFromRow(object $row, array $files = []): PortalEmailMail
    {
        return new PortalEmailMail(
            (string) $row->subject,
            (string) $row->body,
            is_string($row->footer_body) ? $row->footer_body : null,
            PortalEmailMarkup::format($row->format) ?? PortalEmailMarkup::FORMAT_MARKUP,
            PortalEmailMarkup::format($row->footer_format) ?? PortalEmailMarkup::FORMAT_MARKUP,
            is_string($row->from_name) ? $row->from_name : null,
            is_string($row->reply_to) ? $row->reply_to : null,
            $files,
            [],
            PortalEmail::category($row->category),
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
     * Opens the outbox row and hands it its files; run inside the transaction deliver() opens.
     *
     * @param  list<object>  $attachments
     * @param  array{departments: list<string>, roles: list<string>, memberIds: list<int>, emails: list<string>}  $filter
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<array{id: ?int, name: string, email: string}>  $recipients
     */
    private function openWithAttachments(
        array $attachments,
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
        $this->attachments->attach($attachments, $messageId);

        return $messageId;
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
            // Until every copy has left; each one that lands is counted onto this row.
            'status' => PortalEmailPresenter::STATUS_SENDING,
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
        $this->connection()->table('email_messages')->where('id', $messageId)->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'failures' => $failures === [] ? null : json_encode($failures),
            'status' => self::finalStatus($sent, $failed),
            'date_sent' => $this->timezone->now()->toDateTimeString(),
        ]);
    }

    private static function finalStatus(int $sent, int $failed): string
    {
        return $failed === 0
            ? PortalEmailPresenter::STATUS_SENT
            : ($sent === 0 ? PortalEmailPresenter::STATUS_FAILED : PortalEmailPresenter::STATUS_PARTIAL);
    }

    /**
     * The same reason against each recipient, as many as a row keeps.
     *
     * @param  list<array{id: ?int, name: string, email: string}>  $recipients
     * @return list<array{employeeId: ?int, name: string, reason: string}>
     */
    private function failuresFor(array $recipients, string $reason): array
    {
        return array_map(
            static fn (array $recipient): array => ['employeeId' => $recipient['id'], 'name' => (string) $recipient['name'], 'reason' => $reason],
            array_slice($recipients, 0, self::MAX_FAILURES),
        );
    }

    /**
     * The queued copies one send becomes. Members each get their own, so a refusal is theirs alone and
     * only those reached get the bell; their CC and BCC share one more. Typed addresses go as one
     * email, as a mail client sends it: To and Cc on show, Bcc hidden.
     *
     * @param  list<array{id: ?int, name: string, email: string}>  $recipients
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  array{title: string, message: string, linkPath: ?string, linkLabel: ?string, payload: array<string, string>, actorId: int}  $notice
     * @return list<DeliverPortalEmail>
     */
    private function copies(int $messageId, string $audience, array $recipients, array $cc, array $bcc, array $notice): array
    {
        $counted = static fn (array $recipient): array => ['id' => $recipient['id'], 'name' => (string) $recipient['name']];
        if ($audience === PortalEmailAudience::CUSTOM) {
            return [new DeliverPortalEmail($messageId, array_column($recipients, 'email'), $cc, $bcc, array_map($counted, $recipients))];
        }

        $copies = array_map(
            static fn (array $recipient): DeliverPortalEmail => new DeliverPortalEmail(
                $messageId,
                [(string) $recipient['email']],
                [],
                [],
                [$counted($recipient)],
                $recipient['id'] === null ? null : $notice,
            ),
            $recipients,
        );
        if ($cc !== [] || $bcc !== []) {
            $copies[] = new DeliverPortalEmail($messageId, $cc, [], $bcc, []);
        }

        return $copies;
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{total: int, sent: int, partial: int, failed: int, sending: int},
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
        $query = $this->within(EmailMessage::query()->select(PortalEmailPresenter::messageColumns())->withCount('attachments'), $category, $from, $to);
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

        return in_array($status, PortalEmailPresenter::STATUSES, true)
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
     * @return array{total: int, sent: int, partial: int, failed: int, sending: int}
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
        $sending = (int) ($grouped[PortalEmailPresenter::STATUS_SENDING] ?? 0);

        return [
            'total' => $sent + $partial + $failed + $sending,
            'sent' => $sent,
            'partial' => $partial,
            'failed' => $failed,
            'sending' => $sending,
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

    // A footer with signature placeholders reads for the sender; a plain one is left as it is.
    private function fillFooter(?string $body, ?string $format, ?Employee $sender): ?string
    {
        if ($body === null || $sender === null) {
            return $body;
        }

        return $this->signature->fill($sender, $body, $format ?? PortalEmailMarkup::FORMAT_MARKUP);
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
