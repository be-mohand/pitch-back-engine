<?php
declare(strict_types=1);

namespace PitchBack\Engine;

final class Verdict
{
    public const PROSPECTING = 'prospecting';
    public const UNSURE = 'unsure';
    public const LEGIT = 'legit';

    /**
     * @param array<int, array{ruleId: string, label: string, weight: int}> $reasons
     */
    public function __construct(
        public readonly string $classification,
        public readonly int $score,
        public readonly array $reasons,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'classification' => $this->classification,
            'score' => $this->score,
            'reasons' => $this->reasons,
        ];
    }
}
