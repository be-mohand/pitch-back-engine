<?php
declare(strict_types=1);

namespace PitchBack\Engine;

/**
 * Email normalisé, indépendant du provider (Gmail, .eml, fixture de test).
 * Le moteur ne consomme que cette structure — jamais l'API Gmail directement.
 */
final class NormalizedEmail
{
    /**
     * @param array<string, string> $headers Clés forcées en minuscules.
     * @param string[] $to
     */
    public function __construct(
        public readonly string $messageId,
        public readonly string $fromName,
        public readonly string $fromEmail,
        public readonly string $fromDomain,
        public readonly array $to,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly ?string $bodyHtml,
        public readonly array $headers,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly bool $isFirstContact,
        public readonly bool $userHasRepliedBefore,
        public readonly ?int $senderDomainAgeDays = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $fromEmail = strtolower($data['from']['email'] ?? '');
        $domain = $data['from']['domain'] ?? (str_contains($fromEmail, '@') ? explode('@', $fromEmail, 2)[1] : '');

        $headers = [];
        foreach (($data['headers'] ?? []) as $name => $value) {
            $headers[strtolower((string)$name)] = (string)$value;
        }

        return new self(
            messageId: (string)($data['messageId'] ?? ''),
            fromName: (string)($data['from']['name'] ?? ''),
            fromEmail: $fromEmail,
            fromDomain: strtolower($domain),
            to: array_map(strval(...), $data['to'] ?? []),
            subject: (string)($data['subject'] ?? ''),
            bodyText: (string)($data['bodyText'] ?? ''),
            bodyHtml: isset($data['bodyHtml']) ? (string)$data['bodyHtml'] : null,
            headers: $headers,
            receivedAt: new \DateTimeImmutable($data['receivedAt'] ?? 'now'),
            isFirstContact: (bool)($data['context']['isFirstContact'] ?? true),
            userHasRepliedBefore: (bool)($data['context']['userHasRepliedBefore'] ?? false),
            senderDomainAgeDays: isset($data['context']['senderDomainAgeDays'])
                ? (int)$data['context']['senderDomainAgeDays'] : null,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
