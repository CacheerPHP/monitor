<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Application;

/**
 * The event feed of a snapshot: the newest matching events by timestamp, kept
 * within a bounded buffer while the log streams past.
 *
 * Reporters write in batches, so file order is only ordered within a process;
 * the window orders by timestamp and keeps file order for ties.
 */
final class FeedWindow
{
    private const TRIM_FACTOR = 8;

    /** @var list<array{0: float, 1: int, 2: array<string,mixed>}> */
    private array $buffer = [];

    private int $matching = 0;

    private int $sequence = 0;

    public function __construct(
        private readonly int $limit,
        private readonly string $keyFilter = '',
        private readonly string $eventType = '',
    ) {
    }

    /**
     * @param array<string,mixed> $event
     */
    public function offer(array $event): void
    {
        if ($this->eventType !== '' && ($event['type'] ?? '') !== $this->eventType) {
            return;
        }
        if ($this->keyFilter !== '' && stripos((string) ($event['payload']['key'] ?? ''), $this->keyFilter) === false) {
            return;
        }

        $this->matching++;
        $this->buffer[] = [is_numeric($event['ts'] ?? null) ? (float) $event['ts'] : 0.0, $this->sequence++, $event];

        // Sorting is the costly part, so the buffer is allowed to grow to a few
        // times the limit before it is trimmed; memory stays bounded by the limit.
        if ($this->limit > 0 && count($this->buffer) >= self::TRIM_FACTOR * $this->limit) {
            $this->trim();
        }
    }

    /**
     * Matching events, oldest first — the newest $limit of them when limited.
     *
     * @return list<array<string,mixed>>
     */
    public function events(): array
    {
        $this->trim();

        return array_column($this->buffer, 2);
    }

    public function matching(): int
    {
        return $this->matching;
    }

    private function trim(): void
    {
        usort($this->buffer, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        if ($this->limit > 0) {
            $this->buffer = array_slice($this->buffer, -$this->limit);
        }
    }
}
