# Contributing

Thanks for making everyone's inbox quieter. The most valuable contribution is a **new
detection rule** — typically a cold-email tool's header signature, a booking-link domain,
or an SDR pattern you spotted in the wild.

## Adding a rule

**1. Create `rules/<id>.yaml`** — one rule per file, the file name matches the `id`:

```yaml
id: acme-outreach          # kebab-case, unique
label: Sent through Acme   # human-readable, shown to end users
match:
  header:
    x-mailer: Acme         # substring match, case-insensitive
score: 40
```

Match semantics: multiple keys under `match` are **AND**; values listed under one key
are **OR**. Available matchers: `header`, `header_present`, `body_contains`,
`subject_contains`, `body_regex`, `html_regex`, `from_domain`, `from_email_regex`,
`context`.

**2. Pick an honest weight.** Reference scale used by the existing ruleset:

| Signal type | Weight |
|---|---|
| Definitive tool fingerprint (header set by Lemlist, Outreach…) | 40 |
| Unrendered merge tag (`{{first_name}}`) | 25 |
| First contact ever (context) | 20 |
| Booking link (Calendly, Chili Piper…), recent domain | 15 |
| Tracking pixel, meeting ask, unsubscribe header, SDR signature | 10–13 |

A single weak signal must never reach the `prospecting` threshold (90) on its own —
false positives hurt real correspondence, which is worse than a missed pitch.

**3. Add a triggering fixture** in `tests/DetectionEngineTest.php`, inside
`ruleTriggerProvider()`:

```php
'acme-outreach' => ['acme-outreach', ['headers' => ['X-Mailer' => 'Acme']]],
```

A dedicated test asserts **1:1 coverage between rule files and fixtures** — the suite
fails if either side is missing.

**4. Run the tests, open a PR:**

```
composer install
composer test
```

In the PR description, tell us where you saw the pattern (a redacted screenshot or
header dump helps review a lot — strip anything personal).

## What we won't merge

- Rules targeting a specific person or legitimate company rather than a tool/pattern
- Weights engineered so one signal alone flags `prospecting`
- Anything requiring network calls or non-deterministic behavior — the engine stays
  pure, offline and explainable

## Code changes

Bug fixes and new matcher types are welcome. Keep the engine dependency-free
(symfony/yaml only), keep verdicts explainable, and cover changes with tests.
