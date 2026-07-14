<?php
declare(strict_types=1);

namespace PitchBack\Engine;

use Symfony\Component\Yaml\Yaml;

final class RuleLoader
{
    /**
     * Charge toutes les règles *.yaml d'un dossier (une règle par fichier).
     *
     * @return Rule[]
     */
    public static function fromDirectory(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.yaml');
        if ($files === false || $files === []) {
            throw new \RuntimeException("No rule files found in {$dir}");
        }

        $rules = [];
        foreach ($files as $file) {
            $rule = self::fromFile($file);
            if (isset($rules[$rule->id])) {
                throw new \RuntimeException("Duplicate rule id '{$rule->id}' in {$file}");
            }
            $rules[$rule->id] = $rule;
        }

        return array_values($rules);
    }

    public static function fromFile(string $file): Rule
    {
        $data = Yaml::parseFile($file);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid rule file: {$file}");
        }

        foreach (['id', 'label', 'score', 'match'] as $required) {
            if (!isset($data[$required])) {
                throw new \RuntimeException("Rule file {$file}: missing '{$required}'");
            }
        }

        return new Rule(
            id: (string)$data['id'],
            label: (string)$data['label'],
            score: (int)$data['score'],
            match: (array)$data['match'],
        );
    }
}
