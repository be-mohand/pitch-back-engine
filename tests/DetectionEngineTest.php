<?php
declare(strict_types=1);

namespace PitchBack\Engine\Tests;

use PitchBack\Engine\DetectionEngine;
use PitchBack\Engine\NormalizedEmail;
use PitchBack\Engine\RuleLoader;
use PitchBack\Engine\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DetectionEngineTest extends TestCase
{
    private DetectionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DetectionEngine(RuleLoader::fromDirectory(dirname(__DIR__) . '/rules'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function email(array $overrides = []): NormalizedEmail
    {
        return NormalizedEmail::fromArray(array_replace_recursive([
            'messageId' => 'msg-1',
            'from' => ['name' => 'Jake Miller', 'email' => 'jake@growthmotion.io'],
            'to' => ['mohand@kreezalid.com'],
            'subject' => 'Quick question',
            'bodyText' => 'Hello,',
            'bodyHtml' => null,
            'headers' => [],
            'receivedAt' => '2026-07-14 09:12:00',
            'context' => ['isFirstContact' => false, 'userHasRepliedBefore' => false],
        ], $overrides));
    }

    public function testClassicColdEmailIsProspecting(): void
    {
        $verdict = $this->engine->analyze(self::email([
            'subject' => 'Quick question about your outbound',
            'bodyText' => "Hi there, are you the right person? I'd love to grab 15 minutes this week.\n"
                . "calendly.com/jake-growthmotion\n\nJake Miller\nSDR @ GrowthMotion",
            'bodyHtml' => '<img src="https://trk.growthmotion.io/open/abc" width="1" height="1">',
            'headers' => ['X-Mailer' => 'Lemlist 5.2'],
            'context' => ['isFirstContact' => true],
        ]));

        $this->assertSame(Verdict::PROSPECTING, $verdict->classification);
        $this->assertGreaterThanOrEqual(90, $verdict->score);
        $ruleIds = array_column($verdict->reasons, 'ruleId');
        foreach (['lemlist', 'calendly', 'first-contact', 'sdr-signature', 'tracking-pixel', 'meeting-ask'] as $expected) {
            $this->assertContains($expected, $ruleIds, "Expected rule '{$expected}' to fire");
        }
        // Les raisons sont triées par poids décroissant pour l'affichage.
        $weights = array_column($verdict->reasons, 'weight');
        $sorted = $weights;
        rsort($sorted);
        $this->assertSame($sorted, $weights);
    }

    public function testKnownContactPlainEmailIsLegit(): void
    {
        $verdict = $this->engine->analyze(self::email([
            'subject' => 'Re: lunch tomorrow?',
            'bodyText' => 'Sure, see you at noon.',
        ]));

        $this->assertSame(Verdict::LEGIT, $verdict->classification);
        $this->assertSame(0, $verdict->score);
        $this->assertSame([], $verdict->reasons);
    }

    public function testNewsletterStaysBelowUnsureThreshold(): void
    {
        // Newsletter : unsubscribe (10) + pixel (13) + premier contact (20) = 43 → legit.
        $verdict = $this->engine->analyze(self::email([
            'subject' => 'Our July product update',
            'bodyText' => 'New features this month...',
            'bodyHtml' => '<img src="https://news.example.com/pixel/open?u=1" width="1" height="1">',
            'headers' => ['List-Unsubscribe' => '<https://news.example.com/unsub>'],
            'context' => ['isFirstContact' => true],
        ]));

        $this->assertSame(Verdict::LEGIT, $verdict->classification);
        $this->assertSame(43, $verdict->score);
    }

    public function testMidScoreEmailIsUnsure(): void
    {
        // unsubscribe (10) + pixel (13) + premier contact (20) + meeting-ask (12) + calendly (15) = 70.
        $verdict = $this->engine->analyze(self::email([
            'bodyText' => "Would you have 15 minutes this week? calendly.com/someone",
            'bodyHtml' => '<img src="x" width="1" height="1">',
            'headers' => ['List-Unsubscribe' => '<mailto:unsub@x.com>'],
            'context' => ['isFirstContact' => true],
        ]));

        $this->assertSame(Verdict::UNSURE, $verdict->classification);
        $this->assertSame(70, $verdict->score);
    }

    public function testScoreIsClampedAt100(): void
    {
        $verdict = $this->engine->analyze(self::email([
            'subject' => 'Quick question — 15 minutes?',
            'bodyText' => "Hi {{first_name}}, quick question. calendly.com/x meetings.hubspot.com/y chilipiper.com/z "
                . "app.apollo.io salesloft.com/t/abc\nJake\nSDR",
            'bodyHtml' => '<img width="1" height="1" src="t">',
            'headers' => [
                'X-Mailer' => 'Lemlist Mailshake',
                'List-Unsubscribe' => '<x>',
                'X-Instantly-Org-Id' => '1',
                'X-Outreach-Id' => '2',
            ],
            'context' => ['isFirstContact' => true, 'senderDomainAgeDays' => 30],
        ]));

        $this->assertSame(100, $verdict->score);
        $this->assertSame(Verdict::PROSPECTING, $verdict->classification);
    }

    public function testDisabledRuleDoesNotFire(): void
    {
        $engine = new DetectionEngine(
            RuleLoader::fromDirectory(dirname(__DIR__) . '/rules'),
            disabledRuleIds: ['lemlist'],
        );
        $verdict = $engine->analyze(self::email([
            'headers' => ['X-Mailer' => 'Lemlist'],
        ]));

        $this->assertNotContains('lemlist', array_column($verdict->reasons, 'ruleId'));
    }

    /**
     * Chaque règle du dossier rules/ doit être déclenchable par au moins une fixture.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function ruleTriggerProvider(): array
    {
        return [
            'lemlist' => ['lemlist', ['headers' => ['X-Mailer' => 'Lemlist']]],
            'instantly' => ['instantly', ['headers' => ['X-Instantly-Org-Id' => 'org_1']]],
            'instantly-abuse-report' => ['instantly-abuse-report', [
                'headers' => ['X-Mail-Abuse-Inquiries' => 'https://app.instantly.ai/privacy/report-abuse/019f'],
            ]],
            'interest-opener' => ['interest-opener', [
                'bodyText' => "Was wondering if you'd be interested in getting your SaaS on page one",
            ]],
            'reply-stop-cta' => ['reply-stop-cta', [
                'bodyText' => 'If you no longer wish to receive my emails, reply with "not interested".',
            ]],
            'cold-admission' => ['cold-admission', [
                'bodyText' => "Apologies for reaching out cold, I wasn't sure who to contact.",
            ]],
            'prospecting-vocab' => ['prospecting-vocab', [
                'bodyText' => "J'ai réussi à capter des prospects qualifiés lors de mes campagnes.",
            ]],
            'domain-registration-pitch' => ['domain-registration-pitch', [
                'bodyText' => "Bravo pour l'achat de exemple.fr ! Nous transformons un nom de domaine en site.",
            ]],
            'bulk-esp' => ['bulk-esp', ['headers' => ['X-Sib-Id' => 'abc123']]],
            'follow-up-bump' => ['follow-up-bump', [
                'bodyText' => "Je reviens vers vous sur le programme dont je vous ai évoqué le principe.",
            ]],
            'burner-domain' => ['burner-domain', [
                'from' => ['name' => 'Walter', 'email' => 'walter@tryfunnelpulse.co'],
            ]],
            'known-prospector-domain' => ['known-prospector-domain', [
                'from' => ['name' => 'Bjion', 'email' => 'bjion.henry@gtmnavreo.com'],
            ]],
            'tagged-domain' => ['tagged-domain', [
                'context' => ['senderDomainTagged' => true],
            ]],
            'apollo' => ['apollo', ['bodyText' => 'see app.apollo.io/link']],
            'outreach-io' => ['outreach-io', ['headers' => ['X-Outreach-Id' => '42']]],
            'salesloft' => ['salesloft', ['bodyText' => 'https://x.salesloft.com/t/abc']],
            'mailshake' => ['mailshake', ['headers' => ['X-Mailer' => 'Mailshake v2']]],
            'calendly' => ['calendly', ['bodyText' => 'book me: calendly.com/jake']],
            'hubspot-meetings' => ['hubspot-meetings', ['bodyText' => 'meetings.hubspot.com/jake']],
            'chili-piper' => ['chili-piper', ['bodyText' => 'https://go.chilipiper.com/book']],
            'tracking-pixel' => ['tracking-pixel', ['bodyHtml' => '<img src="t" width="1" height="1">']],
            'unsubscribe-header' => ['unsubscribe-header', ['headers' => ['List-Unsubscribe' => '<x>']]],
            'first-contact' => ['first-contact', ['context' => ['isFirstContact' => true]]],
            'sdr-signature' => ['sdr-signature', ['bodyText' => "Best,\nJake\nSDR at GrowthMotion"]],
            'spintax-artifacts' => ['spintax-artifacts', ['bodyText' => 'Hi {{first_name}}, nice site!']],
            'meeting-ask' => ['meeting-ask', ['bodyText' => 'do you have 15 minutes this week?']],
            'recent-domain' => ['recent-domain', ['context' => ['senderDomainAgeDays' => 12]]],
        ];
    }

    /**
     * @param array<string, mixed> $emailOverrides
     */
    #[DataProvider('ruleTriggerProvider')]
    public function testEveryRuleHasATriggeringFixture(string $ruleId, array $emailOverrides): void
    {
        $verdict = $this->engine->analyze(self::email($emailOverrides));
        $this->assertContains(
            $ruleId,
            array_column($verdict->reasons, 'ruleId'),
            "Rule '{$ruleId}' did not fire on its fixture"
        );
    }

    /**
     * Corpus réel anonymisé : premiers emails de vrais fils de prospection reçus
     * dans la nature. Chacun doit au minimum être retenu pour revue (score >= 60).
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function realWorldCorpusProvider(): array
    {
        $first = ['context' => ['isFirstContact' => true, 'userHasRepliedBefore' => false]];

        return [
            'domain-squat via Brevo (FR)' => [$first + [
                'from' => ['name' => 'Agence Web', 'email' => 'bonjour@agence-exemple.com'],
                'subject' => 'Votre nom de domaine "exemple.fr"',
                'bodyText' => "Bonjour, Vous venez d'enregistrer exemple.fr — avez-vous déjà un projet de site ? "
                    . "Réservez directement un créneau de 15 min ici : https://calendly.com/agence/15min "
                    . "Pour ne plus recevoir ces messages, répondez simplement \"stop\" à cet email.",
                'bodyHtml' => '<img width="1" height="1" src="https://esp.example/tr/op/x">',
                'headers' => ['X-Sib-Id' => 'x', 'List-Unsubscribe' => '<https://x>'],
            ]],
            'reply-CTA specialist brag (FR)' => [$first + [
                'from' => ['name' => 'Walter', 'email' => 'walter@trygrowthpulse.co'],
                'bodyText' => "Je suis Walter, nous sommes le spécialiste français de la conformité. "
                    . "Répondez \"je veux en savoir plus\" à ce mail et je vous envoie notre diagnostic.",
            ]],
            'cold admission pay-per-lead (EN)' => [$first + [
                'from' => ['name' => 'B. H.', 'email' => 'bh@gtmexample.com'],
                'bodyText' => "Apologies for reaching out cold. We run it for you on a pay-per-lead basis. "
                    . "Driving \$15M+ in pipeline and booking calls. "
                    . 'If you no longer wish to receive my emails, reply with "not interested".',
            ]],
            'je me permets + leads (FR)' => [$first + [
                'from' => ['name' => 'M. M.', 'email' => 'mm@solutions-exemple.com'],
                'bodyText' => "Je me permets de vous écrire car j'ai réussi à capter des prospects qualifiés "
                    . "lors de mes campagnes précédentes. N'hésitez pas à me dire si cela pourrait vous intéresser.",
            ]],
            'domain-squat mockup offer (FR)' => [$first + [
                'from' => ['name' => 'O. Z.', 'email' => 'oz@webtech-exemple.com'],
                'bodyText' => "Bravo pour l'achat de exemple.fr ! Pourquoi ne pas en parler lors d'un court appel "
                    . "(15 minutes) ? Un créneau cette semaine vous conviendrait ? "
                    . "Si vous ne souhaitez plus recevoir nos messages, répondez STOP.",
            ]],
            'agence de prospection (FR)' => [$first + [
                'from' => ['name' => 'L. D.', 'email' => 'ld@tryagence.com'],
                'bodyText' => "Cette équipe est pilotée par notre agence de prospection. Je veux juste savoir si "
                    . "notre méthode de génération de rendez-vous est adaptée. "
                    . "Je vous envoie mes disponibilités pour qu'on en juge ensemble.",
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $emailOverrides
     */
    #[DataProvider('realWorldCorpusProvider')]
    public function testRealWorldCorpusIsCaught(array $emailOverrides): void
    {
        $verdict = $this->engine->analyze(self::email(array_replace_recursive([
            'from' => ['name' => 'X', 'email' => 'x@cold-sender-example.com'],
        ], $emailOverrides)));

        $this->assertGreaterThanOrEqual(
            60,
            $verdict->score,
            'Real-world cold email should at least be held for review'
        );
        $this->assertNotSame(Verdict::LEGIT, $verdict->classification);
    }

    public function testEveryRuleFileIsCoveredByTriggerProvider(): void
    {
        $ruleIds = array_map(
            fn($rule) => $rule->id,
            RuleLoader::fromDirectory(dirname(__DIR__) . '/rules')
        );
        $covered = array_keys(self::ruleTriggerProvider());
        sort($ruleIds);
        sort($covered);
        $this->assertSame($ruleIds, $covered, 'Every rules/*.yaml needs a trigger fixture in this test');
    }
}
