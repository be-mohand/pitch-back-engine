<?php
declare(strict_types=1);

namespace PitchBack\Engine;

/**
 * Une règle de détection chargée depuis un fichier YAML.
 *
 * Sémantique du bloc `match` :
 *  - plusieurs clés (header, body_contains, ...) = AND
 *  - plusieurs valeurs dans une clé = OR
 */
final class Rule
{
    public const MATCHERS = [
        'header',            // map nom => sous-chaîne attendue (insensible à la casse)
        'header_present',    // liste de noms de headers dont la seule présence suffit
        'body_contains',     // liste de sous-chaînes (texte brut, insensible à la casse)
        'subject_contains',  // liste de sous-chaînes
        'body_regex',        // liste de regex (sans délimiteurs, appliquées en /i)
        'html_regex',        // liste de regex appliquées au HTML
        'from_domain',       // liste de domaines exacts ou suffixes (".io" interdit — trop large)
        'from_email_regex',  // liste de regex sur l'adresse complète
        'context',           // map is_first_contact|user_has_replied_before => bool,
                             //     sender_domain_max_age_days => int
    ];

    /**
     * @param array<string, mixed> $match
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int $score,
        public readonly array $match,
    ) {
        if ($this->match === []) {
            throw new \InvalidArgumentException("Rule '{$id}': empty match block");
        }
        foreach (array_keys($this->match) as $matcher) {
            if (!in_array($matcher, self::MATCHERS, true)) {
                throw new \InvalidArgumentException("Rule '{$id}': unknown matcher '{$matcher}'");
            }
        }
    }

    public function matches(NormalizedEmail $email): bool
    {
        foreach ($this->match as $matcher => $criteria) {
            $ok = match ($matcher) {
                'header' => $this->matchHeader($email, (array)$criteria),
                'header_present' => $this->matchHeaderPresent($email, (array)$criteria),
                'body_contains' => $this->matchContains($email->bodyText, (array)$criteria),
                'subject_contains' => $this->matchContains($email->subject, (array)$criteria),
                'body_regex' => $this->matchRegex($email->bodyText, (array)$criteria),
                'html_regex' => $email->bodyHtml !== null && $this->matchRegex($email->bodyHtml, (array)$criteria),
                'from_domain' => $this->matchFromDomain($email, (array)$criteria),
                'from_email_regex' => $this->matchRegex($email->fromEmail, (array)$criteria),
                'context' => $this->matchContext($email, (array)$criteria),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $criteria
     */
    private function matchHeader(NormalizedEmail $email, array $criteria): bool
    {
        foreach ($criteria as $name => $expected) {
            $value = $email->header((string)$name);
            if ($value !== null && stripos($value, (string)$expected) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $names
     */
    private function matchHeaderPresent(NormalizedEmail $email, array $names): bool
    {
        foreach ($names as $name) {
            if ($email->header((string)$name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $needles
     */
    private function matchContains(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && stripos($haystack, (string)$needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $patterns
     */
    private function matchRegex(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match('/' . str_replace('/', '\/', (string)$pattern) . '/i', $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $domains
     */
    private function matchFromDomain(NormalizedEmail $email, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = strtolower((string)$domain);
            if ($email->fromDomain === $domain || str_ends_with($email->fromDomain, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Contexte : toutes les conditions listées doivent être vraies (AND),
     * contrairement aux autres matchers — ce sont des prédicats, pas des variantes.
     *
     * @param array<string, mixed> $criteria
     */
    private function matchContext(NormalizedEmail $email, array $criteria): bool
    {
        foreach ($criteria as $key => $expected) {
            $ok = match ((string)$key) {
                'is_first_contact' => $email->isFirstContact === (bool)$expected,
                'user_has_replied_before' => $email->userHasRepliedBefore === (bool)$expected,
                'sender_domain_tagged' => $email->senderDomainTagged === (bool)$expected,
                'sender_domain_max_age_days' => $email->senderDomainAgeDays !== null
                    && $email->senderDomainAgeDays <= (int)$expected,
                default => throw new \InvalidArgumentException(
                    "Rule '{$this->id}': unknown context key '{$key}'"
                ),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
