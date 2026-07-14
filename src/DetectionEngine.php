<?php
declare(strict_types=1);

namespace PitchBack\Engine;

/**
 * Cœur de Pitch Back : somme les poids des règles déclenchées et rend
 * un verdict explicable. Aucune IA, aucun réseau, aucun état.
 */
final class DetectionEngine
{
    /**
     * @param Rule[] $rules
     * @param string[] $disabledRuleIds Règles désactivées localement par l'utilisateur.
     */
    public function __construct(
        private readonly array $rules,
        private readonly int $prospectingThreshold = 90,
        private readonly int $unsureThreshold = 60,
        private readonly array $disabledRuleIds = [],
    ) {
        if ($this->unsureThreshold >= $this->prospectingThreshold) {
            throw new \InvalidArgumentException('unsureThreshold must be below prospectingThreshold');
        }
    }

    public function analyze(NormalizedEmail $email): Verdict
    {
        $reasons = [];
        $score = 0;

        foreach ($this->rules as $rule) {
            if (in_array($rule->id, $this->disabledRuleIds, true)) {
                continue;
            }
            if ($rule->matches($email)) {
                $reasons[] = ['ruleId' => $rule->id, 'label' => $rule->label, 'weight' => $rule->score];
                $score += $rule->score;
            }
        }

        usort($reasons, fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $score = max(0, min(100, $score));

        $classification = match (true) {
            $score >= $this->prospectingThreshold => Verdict::PROSPECTING,
            $score >= $this->unsureThreshold => Verdict::UNSURE,
            default => Verdict::LEGIT,
        };

        return new Verdict($classification, $score, $reasons);
    }
}
