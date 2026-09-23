<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\EmailBlock;
use App\Modules\Portal\Models\EmailMessage;
use Illuminate\Support\Carbon;

/**
 * Skinny Send Email rows. The outbox list never returns a body; one message returns the
 * body and footer it went out with, so editing a block later cannot rewrite what was sent.
 */
final class PortalEmailPresenter
{
    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function blockColumns(): array
    {
        return ['id', 'kind', 'name', 'category', 'subject', 'body', 'created_by', 'date_created', 'date_updated'];
    }

    /**
     * @return list<string>
     */
    public static function messageColumns(): array
    {
        return [
            'id',
            'category',
            'subject',
            'audience',
            'audience_filter',
            'recipient_count',
            'sent_count',
            'failed_count',
            'status',
            'sent_by',
            'date_sent',
            'date_created',
        ];
    }

    /**
     * @return list<string>
     */
    public static function messageDetailColumns(): array
    {
        return [...self::messageColumns(), 'body', 'footer_id', 'footer_body', 'failures'];
    }

    /**
     * @return list<string>
     */
    public static function employeeColumns(): array
    {
        return ['id', 'first_name', 'last_name', 'id_no'];
    }

    /**
     * @return array{
     *     id: string,
     *     kind: string,
     *     name: string,
     *     category: ?string,
     *     subject: ?string,
     *     body: string,
     *     createdBy: ?int,
     *     createdAt: string,
     *     updatedAt: ?string
     * }
     */
    public static function block(EmailBlock $row): array
    {
        return [
            'id' => (string) $row->getKey(),
            'kind' => PortalEmail::kind($row->kind) ?? PortalEmail::KIND_TEMPLATE,
            'name' => trim((string) $row->name),
            'category' => self::text($row->category),
            'subject' => self::text($row->subject),
            'body' => (string) $row->body,
            'createdBy' => is_numeric($row->created_by) ? (int) $row->created_by : null,
            'createdAt' => self::instant($row->date_created),
            'updatedAt' => $row->date_updated === null ? null : self::instant($row->date_updated),
        ];
    }

    /**
     * @param  array<int, array{name: string, idNo: ?string}>  $senders
     * @return array{
     *     id: string,
     *     category: string,
     *     subject: string,
     *     audience: string,
     *     audienceFilter: array{departments: list<string>, roles: list<string>, memberIds: list<int>},
     *     recipientCount: int,
     *     sentCount: int,
     *     failedCount: int,
     *     status: string,
     *     sentById: ?int,
     *     sentByIdNo: ?string,
     *     sentByName: string,
     *     sentAt: string
     * }
     */
    public static function message(EmailMessage $row, array $senders): array
    {
        $senderId = is_numeric($row->sent_by) ? (int) $row->sent_by : null;
        $known = $senderId !== null ? ($senders[$senderId] ?? null) : null;
        $sentCount = (int) $row->sent_count;
        $failedCount = (int) $row->failed_count;

        return [
            'id' => (string) $row->getKey(),
            'category' => trim((string) $row->category),
            'subject' => trim((string) $row->subject),
            'audience' => trim((string) $row->audience),
            'audienceFilter' => self::audienceFilter($row->audience_filter),
            'recipientCount' => (int) $row->recipient_count,
            'sentCount' => $sentCount,
            'failedCount' => $failedCount,
            'status' => self::status($row->status, $sentCount, $failedCount),
            'sentById' => $senderId,
            'sentByIdNo' => $known['idNo'] ?? null,
            'sentByName' => $known['name'] ?? 'Administrator',
            'sentAt' => self::instant($row->date_sent ?? $row->date_created),
        ];
    }

    /**
     * @param  array<int, array{name: string, idNo: ?string}>  $senders
     * @return array<string, mixed>
     */
    public static function detail(EmailMessage $row, array $senders): array
    {
        return [
            ...self::message($row, $senders),
            'body' => (string) $row->body,
            'footerId' => is_numeric($row->footer_id) ? (int) $row->footer_id : null,
            'footerBody' => self::text($row->footer_body),
            'failures' => self::failures($row->failures),
        ];
    }

    /**
     * @return array{total: int, templates: int, footers: int}
     */
    public static function blockCounts(int $templates, int $footers): array
    {
        return [
            'total' => $templates + $footers,
            'templates' => $templates,
            'footers' => $footers,
        ];
    }

    /**
     * @return array{departments: list<string>, roles: list<string>, memberIds: list<int>}
     */
    public static function audienceFilter(mixed $value): array
    {
        $filter = is_array($value) ? $value : [];

        return [
            'departments' => self::textList($filter['departments'] ?? null),
            'roles' => self::textList($filter['roles'] ?? null),
            'memberIds' => self::idList($filter['memberIds'] ?? null),
        ];
    }

    /**
     * Who a send could not reach, by name rather than by address: the outbox is not a
     * place to leave a member's email sitting.
     *
     * @return list<array{employeeId: ?int, name: string, reason: string}>
     */
    public static function failures(mixed $value): array
    {
        $rows = is_array($value) ? $value : [];
        $failures = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            if ($name === '' && $reason === '') {
                continue;
            }
            $failures[] = [
                'employeeId' => is_numeric($row['employeeId'] ?? null) ? (int) $row['employeeId'] : null,
                'name' => $name,
                'reason' => $reason,
            ];
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private static function textList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            $text = trim((string) $entry);
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private static function idList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_numeric($entry) && (int) $entry > 0) {
                $out[] = (int) $entry;
            }
        }

        return $out;
    }

    /**
     * A stored status the UI does not recognise is read from the counts instead, so a row
     * sent by an older build never shows as delivered when it was not.
     */
    private static function status(mixed $stored, int $sentCount, int $failedCount): string
    {
        $status = trim((string) $stored);
        if (in_array($status, [self::STATUS_SENT, self::STATUS_PARTIAL, self::STATUS_FAILED], true)) {
            return $status;
        }

        if ($sentCount === 0 && $failedCount > 0) {
            return self::STATUS_FAILED;
        }

        return $failedCount > 0 ? self::STATUS_PARTIAL : self::STATUS_SENT;
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function instant(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->clone()->utc()->toIso8601String();
        }

        return Carbon::parse((string) $value)->utc()->toIso8601String();
    }
}
