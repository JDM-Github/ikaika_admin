<?php

namespace App\Support\Portal;

use Illuminate\Database\Query\Builder;

/**
 * Which server is filing into Jira (2026-10-21, user): production and staging now share one Jira project, because
 * the problems production's people report are fixed on staging. A server is named by its API channel -- production
 * on production, staging on the Test Server, development locally -- and every report records the channel it was sent
 * on. A server follows only its own reports' issues: sending, the retry's search, the read-back, the webhook and a
 * change made in the portal all leave alone a report another server sent, such as the production reports a Test
 * Server copy carries. A report from before reports kept a channel counts as production's.
 *
 * Production files as it always has: the portal's one label, and "(report #N)" in the description. Any other server
 * adds its channel as a second label and in the mark -- "(staging report #N)" -- so each server's retry takes only its
 * own issue, and the board can be filtered by either.
 */
final class PortalJiraEnvironment
{
    private const FALLBACK = 'development';

    public function channel(): string
    {
        $channel = strtolower(trim((string) config('products.channel', '')));

        return $channel === '' ? self::FALLBACK : $channel;
    }

    public function isProduction(): bool
    {
        return $this->channel() === strtolower(trim((string) config('services.jira.production_channel', 'production')));
    }

    // The label this server adds to what it files, and puts in its mark: none on production.
    public function label(): ?string
    {
        if ($this->isProduction()) {
            return null;
        }

        return trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $this->channel()), '-') ?: self::FALLBACK;
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        $label = $this->label();

        return [(string) config('services.jira.label', 'ikaika-portal'), ...($label === null ? [] : [$label])];
    }

    // A report this server sent: its own channel, or none at all on production.
    public function ownsRow(object $row): bool
    {
        $channel = $row->channel ?? null;

        return $channel === null ? $this->isProduction() : strtolower(trim((string) $channel)) === $this->channel();
    }

    // The reports this server sent, as ownsRow() says.
    public function scope(Builder $query): Builder
    {
        if ($this->isProduction()) {
            return $query->where(fn (Builder $inner) => $inner->whereNull('channel')->orWhere('channel', $this->channel()));
        }

        return $query->where('channel', $this->channel());
    }

    // The labels a retry searches by: the portal's own, and this server's when it has one.
    public function searchJql(): string
    {
        $label = $this->label();

        return 'labels = "'.config('services.jira.label', 'ikaika-portal').'"'.($label === null ? '' : ' AND labels = "'.$label.'"');
    }

    // The JQL this server's webhook is registered with; production's is unchanged and ignores staging's deliveries.
    public function webhookJql(?string $projectKey): string
    {
        $label = $this->label();

        return 'project = '.$projectKey.' AND labels = '.config('services.jira.label', 'ikaika-portal').($label === null ? '' : ' AND labels = '.$label);
    }
}
