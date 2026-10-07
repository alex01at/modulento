<?php

declare(strict_types=1);

namespace Modulento\Core\Provider;

use Modulento\Core\App;
use Modulento\Core\Support\Settings;
use PDO;

/**
 * Earned marks on a provider's public profile. Fixed badge types - "top
 * rated" and "fast responder" - whose thresholds an administrator sets
 * (Administration → Settings); nothing here lets an administrator invent a
 * new kind of badge.
 *
 * "Top rated" reads the rating_count/rating_sum a provider already has
 * (Review\Reviews::summary() reads the same two columns). "Fast responder"
 * has no equivalent anywhere else, so its figure is computed here and kept
 * on the provider row, recomputed periodically by the scheduled task
 * core.badges-recompute - never counted live on a page view.
 */
final class Badges
{
    public function __construct(private PDO $db, private Settings $settings)
    {
    }

    /** @param array $provider a provider row (rating_count, rating_sum, avg_response_minutes, response_sample_count) */
    public function active(array $provider): array
    {
        $badges = [];

        $count = (int) ($provider['rating_count'] ?? 0);
        $average = $count > 0 ? ((int) $provider['rating_sum']) / $count : 0.0;
        if ($count >= $this->topRatedMinCount() && $average >= $this->topRatedMinAverage()) {
            $badges[] = 'top_rated';
        }

        $sample = (int) ($provider['response_sample_count'] ?? 0);
        $minutes = $provider['avg_response_minutes'] ?? null;
        if ($minutes !== null && $sample >= $this->fastResponderMinSample() && (int) $minutes <= $this->fastResponderMaxMinutes()) {
            $badges[] = 'fast_responder';
        }

        return $badges;
    }

    public function topRatedMinAverage(): float
    {
        return (float) str_replace(',', '.', $this->settings->get('core.badge.top_rated.min_average', '4.5'));
    }

    public function topRatedMinCount(): int
    {
        return (int) $this->settings->get('core.badge.top_rated.min_count', '5');
    }

    public function fastResponderMaxMinutes(): int
    {
        return (int) $this->settings->get('core.badge.fast_responder.max_minutes', '120');
    }

    public function fastResponderMinSample(): int
    {
        return (int) $this->settings->get('core.badge.fast_responder.min_sample', '5');
    }

    public function fastResponderSampleSize(): int
    {
        return (int) $this->settings->get('core.badge.fast_responder.sample_size', '20');
    }

    /**
     * Recomputes every provider's average first-response time, from the two
     * kinds of messages that exist today: pre-order offer questions and
     * post-order messages. Kept over the most recent threads only, so a
     * provider who used to answer quickly but no longer does is not carried
     * by old history forever.
     */
    public function recomputeResponseTimes(App $app): void
    {
        $limit = $this->fastResponderSampleSize();
        $providerIds = array_map('intval', $this->db->query('SELECT id FROM provider')->fetchAll(PDO::FETCH_COLUMN));

        $update = $this->db->prepare('UPDATE provider SET avg_response_minutes = :avg, response_sample_count = :count WHERE id = :id');
        foreach ($providerIds as $providerId) {
            $gaps = $this->responseGapsMinutes($providerId, $limit);
            $update->execute([
                'avg' => $gaps !== [] ? (int) round(array_sum($gaps) / count($gaps)) : null,
                'count' => count($gaps),
                'id' => $providerId,
            ]);
        }
    }

    /** @return array<int, int> minutes between a first message and the provider's first reply, most recent first, at most $limit entries */
    private function responseGapsMinutes(int $providerId, int $limit): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.offer_id, m.asker_id, m.author_id, m.created_at
             FROM offer_message m JOIN offer o ON o.id = m.offer_id
             WHERE o.provider_id = :provider AND m.author_id IS NOT NULL ORDER BY m.offer_id, m.asker_id, m.id'
        );
        $stmt->execute(['provider' => $providerId]);
        $gaps = $this->threadGaps(
            $stmt->fetchAll(),
            fn (array $row) => $row['offer_id'] . ':' . $row['asker_id'],
            fn (array $row) => (int) $row['author_id'] === (int) $row['asker_id']
        );

        $stmt = $this->db->prepare(
            'SELECT om.order_id, om.author_role, om.created_at FROM order_message om JOIN orders o ON o.id = om.order_id
             WHERE o.provider_id = :provider ORDER BY om.order_id, om.id'
        );
        $stmt->execute(['provider' => $providerId]);
        $gaps = [...$gaps, ...$this->threadGaps(
            $stmt->fetchAll(),
            fn (array $row) => (string) $row['order_id'],
            fn (array $row) => $row['author_role'] === 'buyer'
        )];

        usort($gaps, fn (array $a, array $b) => $b['asked_at'] <=> $a['asked_at']);

        return array_map(fn (array $gap) => $gap['minutes'], array_slice($gaps, 0, $limit));
    }

    /**
     * One row per message, grouped into threads by $groupKey; the gap
     * between a thread's first "ask" (per $isAsk) and its first reply after
     * that. Only the first ask-then-reply pair of a thread counts.
     *
     * @param array<int, array> $rows
     * @param callable(array): string $groupKey
     * @param callable(array): bool $isAsk
     * @return array<int, array{asked_at: string, minutes: int}>
     */
    private function threadGaps(array $rows, callable $groupKey, callable $isAsk): array
    {
        $threads = [];
        foreach ($rows as $row) {
            $threads[$groupKey($row)][] = $row;
        }

        $gaps = [];
        foreach ($threads as $messages) {
            $askedAt = null;
            foreach ($messages as $row) {
                $ask = $isAsk($row);
                if ($askedAt === null && $ask) {
                    $askedAt = $row['created_at'];
                    continue;
                }
                if ($askedAt !== null && !$ask) {
                    $gaps[] = ['asked_at' => $askedAt, 'minutes' => max(0, intdiv(strtotime($row['created_at']) - strtotime($askedAt), 60))];
                    $askedAt = null;
                }
            }
        }

        return $gaps;
    }
}
