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

        $match = (array)$data['match'];

        // Matcher data-driven : `from_domain_file` pointe vers une liste de domaines
        // (un par ligne, # pour les commentaires), résolue relativement au fichier
        // de règle. Expansé en `from_domain` au chargement — le moteur reste pur.
        if (isset($match['from_domain_file'])) {
            $listFile = dirname($file) . DIRECTORY_SEPARATOR . (string)$match['from_domain_file'];
            if (!is_readable($listFile)) {
                throw new \RuntimeException("Rule file {$file}: domain list not readable: {$listFile}");
            }
            $domains = array_values(array_filter(
                array_map('trim', file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)),
                fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')
            ));
            unset($match['from_domain_file']);
            $match['from_domain'] = array_merge((array)($match['from_domain'] ?? []), $domains);
        }

        return new Rule(
            id: (string)$data['id'],
            label: (string)$data['label'],
            score: (int)$data['score'],
            match: $match,
        );
    }
}
