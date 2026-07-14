# Pitch Back Engine

> Sales teams automated prospecting. This engine automates rejecting it — politely,
> transparently, and without a single LLM.

**pitch-back-engine** detects commercial cold emails using transparent, community-maintained
YAML rules. Every verdict is fully explainable: which rules fired, what each one weighs,
and how the score adds up. No AI, no network calls, no black box.

It powers [Pitch Back](https://pitch-back.com) — the app that replies to cold emails
with *your* pitch and archives the thread — but it is a standalone library you can embed
in any PHP project.

## How it works

One rule = one YAML file in [`rules/`](rules/):

```yaml
id: lemlist
label: Sent through Lemlist
match:
  header:
    x-mailer: Lemlist
score: 40
```

Feed the engine a normalized email, get an explainable verdict:

```php
use PitchBack\Engine\DetectionEngine;
use PitchBack\Engine\NormalizedEmail;
use PitchBack\Engine\RuleLoader;

$engine = new DetectionEngine(RuleLoader::fromDirectory('rules'));

$verdict = $engine->analyze(NormalizedEmail::fromArray([
    'messageId' => 'abc123',
    'from' => ['name' => 'Jake Miller', 'email' => 'jake@growthmotion.io'],
    'to' => ['you@company.com'],
    'subject' => 'Quick question about your outbound',
    'bodyText' => "Grab 15 minutes? calendly.com/jake\n\nJake — SDR at GrowthMotion",
    'headers' => ['X-Mailer' => 'Lemlist 5.2'],
    'context' => ['isFirstContact' => true, 'userHasRepliedBefore' => false],
]));

$verdict->score;          // 97 (0–100, clamped)
$verdict->classification; // 'prospecting' | 'unsure' | 'legit'
$verdict->reasons;        // [['ruleId' => 'lemlist', 'label' => 'Sent through Lemlist', 'weight' => 40], …]
```

Or from the command line:

```
$ bin/analyze email.json

  Score          97%
  Classification PROSPECTING

  Reasons
    ✓ Sent through Lemlist                     (lemlist)            +40
    ✓ First contact ever                       (first-contact)      +20
    ✓ Calendly link in body                    (calendly)           +15
    ✓ Asks for a quick call or meeting         (meeting-ask)        +12
    ✓ SDR-style signature                      (sdr-signature)      +10
```

## Scoring model

- Rule weights are **summed**, clamped to 0–100. There is no machine learning anywhere.
- Default thresholds: **≥ 90** → `prospecting`, **≥ 60** → `unsure`, below → `legit`
  (both configurable via the `DetectionEngine` constructor).
- Inside a rule's `match` block: multiple keys are **AND**, values listed under a key are **OR**.

Available matchers: `header`, `header_present`, `body_contains`, `subject_contains`,
`body_regex`, `html_regex`, `from_domain`, `from_email_regex`, `context`
(see [`src/Rule.php`](src/Rule.php) for exact semantics).

## Install

```
composer require be-mohand/pitch-back-engine
```

Requires PHP ≥ 8.2. Only dependency: `symfony/yaml`.

## Contributing a rule

Found a cold email that slipped through? That's a missing rule — and one PR away
from protecting every user. See [CONTRIBUTING.md](CONTRIBUTING.md); the short version:

1. Add `rules/your-rule.yaml`
2. Add a triggering fixture to `tests/DetectionEngineTest.php` (a test enforces 1:1 coverage — CI fails without it)
3. Open a PR

Like EasyList for ad blocking or YARA rules for malware, this ruleset gets stronger
with every contribution.

## License

[MIT](LICENSE)
