<?php

namespace App\Support\Portal;

use App\Modules\Portal\Models\EmailBlock;
use App\Modules\Portal\Models\Employee;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Administration / Send Email: the block library the composer draws from.
 *
 * One table holds both kinds because their shape and life cycle are the same. A template
 * carries a category and a subject and pre-fills the composer; a footer is a signature or
 * disclaimer appended under the body. A send stores its own copy of both, so editing or
 * deleting a block never rewrites what already went out.
 */
final class PortalEmail
{
    public const CACHE_TTL_SECONDS = 30;

    public const DEFAULT_PAGE_SIZE = 25;

    /**
     * @var list<int>
     */
    public const PAGE_SIZES = [10, 25, 50, 100];

    public const KIND_TEMPLATE = 'template';

    public const KIND_FOOTER = 'footer';

    /**
     * What an email is about. The composer offers exactly these.
     *
     * @var list<string>
     */
    public const CATEGORIES = ['notice', 'event', 'complaint', 'other'];

    public const CORE_RESOURCE = 'administration.email';

    private const CACHE_VERSION_KEY = 'portal:administration:email:version';

    private const MAX_NAME = 191;

    private const MAX_SUBJECT = 255;

    private const MAX_BODY = 20000;

    /**
     * Table column key => SQL columns. Unknown keys fall back to newest first.
     *
     * @var array<string, list<string>>
     */
    private const SORTABLE = [
        'name' => ['name', 'id'],
        'category' => ['category', 'id'],
        'updated' => ['date_created', 'id'],
    ];

    public function __construct(
        private readonly PortalTimezone $timezone,
        private readonly PortalAudit $audit,
    ) {}

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{total: int, templates: int, footers: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function list(Request $request): array
    {
        $kind = self::kind($request->query('kind'));
        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $search = trim((string) $request->query('q', ''));
        $sort = trim((string) $request->query('sort', ''));
        $dir = strtolower(trim((string) $request->query('dir', 'desc'))) === 'asc' ? 'asc' : 'desc';

        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        $key = 'portal:administration:email:blocks:'.$version.':'.($kind ?? 'all').':'.$page.':'.$perPage.':'.md5($search).':'.$sort.':'.$dir;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use (
            $kind,
            $perPage,
            $page,
            $search,
            $sort,
            $dir,
        ): array {
            return $this->build($kind, $perPage, $page, $search, $sort, $dir);
        });
    }

    /**
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function create(Employee $actor, Request $request): array
    {
        $kind = self::kind($request->input('kind'));
        if ($kind === null) {
            abort(422, 'Choose whether this is a template or a footer.');
        }

        $name = $this->validatedName($request);
        $category = $kind === self::KIND_TEMPLATE ? $this->validatedCategory($request, true) : null;
        $subject = $kind === self::KIND_TEMPLATE ? $this->validatedSubject($request, true) : null;
        $body = $this->validatedBody($request);
        $createdAt = $this->timezone->now();

        $id = (int) $this->connection()->table('email_blocks')->insertGetId([
            'kind' => $kind,
            'name' => $name,
            'category' => $category,
            'subject' => $subject,
            'body' => $body,
            'created_by' => (int) $actor->getKey(),
            'date_created' => $createdAt->toDateTimeString(),
            'date_updated' => null,
        ]);

        $this->audit->record(
            $actor,
            PortalLogAction::INSERT,
            self::CORE_RESOURCE,
            PortalActivityCopy::createdEmailBlock($name, $kind),
            (string) $id,
            $request,
            ['kind' => $kind, 'name' => $name, 'category' => $category, 'subject' => $subject],
        );
        $this->bumpCache();

        return [
            'section' => 'administration',
            'resource' => 'email-blocks',
            'data' => PortalEmailPresenter::block($this->blockOrFail($id)),
        ];
    }

    /**
     * A block keeps the kind it was created with: a footer has no subject to become a template with.
     *
     * @return array{section: string, resource: string, data: array<string, mixed>}
     */
    public function update(Employee $actor, Request $request, int $id): array
    {
        $row = $this->blockOrFail($id);
        $kind = self::kind($row->kind) ?? self::KIND_TEMPLATE;
        $name = $this->validatedName($request);
        $category = $kind === self::KIND_TEMPLATE ? $this->validatedCategory($request, true) : null;
        $subject = $kind === self::KIND_TEMPLATE ? $this->validatedSubject($request, true) : null;
        $body = $this->validatedBody($request);

        $this->connection()->table('email_blocks')->where('id', $id)->update([
            'name' => $name,
            'category' => $category,
            'subject' => $subject,
            'body' => $body,
            'date_updated' => $this->timezone->now()->toDateTimeString(),
        ]);

        $this->audit->record(
            $actor,
            PortalLogAction::PATCH,
            self::CORE_RESOURCE,
            PortalActivityCopy::updatedEmailBlock($name, $kind),
            (string) $id,
            $request,
            ['kind' => $kind, 'name' => $name, 'category' => $category, 'subject' => $subject],
        );
        $this->bumpCache();

        return [
            'section' => 'administration',
            'resource' => 'email-blocks',
            'data' => PortalEmailPresenter::block($this->blockOrFail($id)),
        ];
    }

    /**
     * Removing a block never touches the outbox: a sent message holds its own copy.
     */
    public function delete(Employee $actor, Request $request, int $id): void
    {
        $row = $this->blockOrFail($id);
        $name = trim((string) $row->name);
        $kind = self::kind($row->kind) ?? self::KIND_TEMPLATE;

        $this->connection()->table('email_blocks')->where('id', $id)->delete();

        $this->audit->record(
            $actor,
            PortalLogAction::DELETE,
            self::CORE_RESOURCE,
            PortalActivityCopy::deletedEmailBlock($name, $kind),
            (string) $id,
            $request,
            ['kind' => $kind, 'name' => $name],
        );
        $this->bumpCache();
    }

    public function bumpCache(): void
    {
        $current = (int) Cache::get(self::CACHE_VERSION_KEY, 1);
        Cache::forever(self::CACHE_VERSION_KEY, $current + 1);
    }

    public function blockOrFail(int $id): EmailBlock
    {
        if ($id <= 0) {
            abort(404, 'That block was not found.');
        }

        $row = EmailBlock::query()->select(PortalEmailPresenter::blockColumns())->where('id', $id)->first();
        if (! $row instanceof EmailBlock) {
            abort(404, 'That block was not found.');
        }

        return $row;
    }

    /**
     * One saved footer by id, or null when nothing of that kind carries that id.
     */
    public function footer(int $id): ?EmailBlock
    {
        if ($id <= 0) {
            return null;
        }

        $row = EmailBlock::query()
            ->select(PortalEmailPresenter::blockColumns())
            ->where('id', $id)
            ->where('kind', self::KIND_FOOTER)
            ->first();

        return $row instanceof EmailBlock ? $row : null;
    }

    /**
     * @return array{
     *     section: string,
     *     resource: string,
     *     data: list<array<string, mixed>>,
     *     counts: array{total: int, templates: int, footers: int},
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    private function build(?string $kind, int $perPage, int $page, string $search, string $sort, string $dir): array
    {
        $query = EmailBlock::query()->select(PortalEmailPresenter::blockColumns());
        if ($kind !== null) {
            $query->where('kind', $kind);
        }
        if ($search !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function ($builder) use ($needle): void {
                $builder->where('name', 'like', $needle)
                    ->orWhere('subject', 'like', $needle)
                    ->orWhere('body', 'like', $needle);
            });
        }

        foreach (self::SORTABLE[$sort] ?? ['date_created', 'id'] as $column) {
            $query->orderBy($column, $dir);
        }

        $pageResult = $query->paginate($perPage, ['*'], 'page', $page);

        $rows = [];
        foreach ($pageResult->items() as $row) {
            if ($row instanceof EmailBlock) {
                $rows[] = PortalEmailPresenter::block($row);
            }
        }

        return [
            'section' => 'administration',
            'resource' => 'email-blocks',
            'data' => $rows,
            'counts' => PortalEmailPresenter::blockCounts($this->countOf(self::KIND_TEMPLATE), $this->countOf(self::KIND_FOOTER)),
            'meta' => [
                'current_page' => $pageResult->currentPage(),
                'per_page' => $pageResult->perPage(),
                'total' => $pageResult->total(),
                'last_page' => $pageResult->lastPage(),
            ],
        ];
    }

    private function countOf(string $kind): int
    {
        return (int) EmailBlock::query()->where('kind', $kind)->toBase()->count();
    }

    public static function kind(mixed $value): ?string
    {
        $kind = strtolower(trim((string) $value));

        return in_array($kind, [self::KIND_TEMPLATE, self::KIND_FOOTER], true) ? $kind : null;
    }

    public static function category(mixed $value): ?string
    {
        $category = strtolower(trim((string) $value));

        return in_array($category, self::CATEGORIES, true) ? $category : null;
    }

    private function validatedName(Request $request): string
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            abort(422, 'Give the block a name.');
        }
        if (strlen($name) > self::MAX_NAME) {
            abort(422, 'The name cannot exceed 191 characters.');
        }

        return $name;
    }

    private function validatedCategory(Request $request, bool $required): ?string
    {
        $raw = trim((string) $request->input('category', ''));
        if ($raw === '') {
            if ($required) {
                abort(422, 'Choose what this email is about.');
            }

            return null;
        }

        $category = self::category($raw);
        if ($category === null) {
            abort(422, 'Choose a category the form offers.');
        }

        return $category;
    }

    private function validatedSubject(Request $request, bool $required): ?string
    {
        $subject = trim((string) $request->input('subject', ''));
        if ($subject === '') {
            if ($required) {
                abort(422, 'Give the email a subject.');
            }

            return null;
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
