# Greenwashing Checker — public beta

AI-assisted checks against EU greenwashing rules for marketing copy in Statamic entries. Directive 2024/825 (EmpCo) amends the Unfair Commercial Practices Directive and applies EU-wide from 27 September 2026. Findings pair EU references with German UWG references and come with explanations and suggested rewrites. The addon flags potential risks; it never establishes compliance, and it is not legal advice.

Requires PHP 8.3+, Statamic 6.20+ (or 5.74, see below) and a Prism-compatible LLM provider, usually a paid API account. UI and prompts support German and English.

## Statamic 5

Statamic 5.74+ works on Laravel 11 or 12 only (Prism requires Laravel 11+; Statamic 5 on Laravel 10 cannot install this addon). Backend analysis, storage, `greenwashing:scan`, and the JSON endpoints are identical. Settings come from the published config file only — Statamic 5 has no addon settings API, so CP-only rules (substantiations, house rules, audience, jurisdiction sites) stay empty. The CP panel is a single sidebar panel with Check/Retry, a status summary, and a Review-all sheet (Statamic 5's own stack component) with the same information design as Statamic 6: open/ignored switcher, compact rows grouped by tab and field, one expanded row at a time with references, suggestions, Apply for top-level text/textarea/markdown fields, Ignore/Show again, and jump-to-field. Bard marks, inline boxes, publish warnings, approvals, and the Inertia utility are Statamic 6 only (a read-only Blade utility lists scan summaries on v5).

## Install

Until the package is on Packagist, add the VCS repository to your application's `composer.json`:

```json
{
  "repositories": [
    { "type": "vcs", "url": "git@github.com:el-schneider/statamic-greenwashing-checker.git" }
  ],
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Private repositories require GitHub SSH access.

```sh
composer require el-schneider/statamic-greenwashing-checker:@dev
php artisan vendor:publish --tag=greenwashing-checker-config
php artisan statamic:install
```

Use a cache shared by HTTP processes and queue workers. The `sync` queue works; a separate worker is optional.

## Configuration

```dotenv
GREENWASHING_CHECKER_MODEL=openrouter/openai/gpt-6-luna
OPENROUTER_API_KEY=your-provider-key
```

The default model is `openai/gpt-6-luna` and needs `OPENAI_API_KEY`. The model string splits on its first `/`, so nested OpenRouter names work; Prism reads provider credentials from its own `prism.providers` config. Nothing is sent until a model and its credentials are configured.

All other options live in the published `config/statamic/greenwashing-checker.php`: collections, automatic checks, panel placement, publish warnings, categories and the context budget. Analysis rules — substantiations, house rules, audience and jurisdictions — are edited in the control panel under Add-ons → Greenwashing Checker → Settings; CP selections override config defaults.

Displayed legal references come from a jurisdiction pack chosen in CP settings. See [CONTRIBUTING.md](CONTRIBUTING.md) for what packs contain and how to propose one.

Disable checks for a field or an entire container in its blueprint:

```yaml
field:
  type: textarea
  greenwashing_checker: false
```

## What editors see

- The sidebar panel checks unsaved entry text. **Prüfen** (Check) runs the analysis; **Alle durchgehen** (Review all) opens a review stack with open, ignored and approved findings, grouped by field.
- Each affected field gets an inline box below the input, nested fields and Bard included; open findings in Bard prose also get severity-tinted highlights.
- **Übernehmen** (Apply) replaces the first matching quote and uses the editor's undo history (Ctrl/Cmd+Z). A changed quote produces an error instead of replacing unrelated text.
- **Ignorieren** (Ignore) sets a finding aside until the text or the analysis settings change. **Freigeben …** (Approve) records a documented decision; it requires a reason and the `approve greenwashing findings` permission.
- Leaving a changed field schedules a check after one second (`check_on_blur`). Publishing with HIGH findings, ignored ones included, warns with an explicit publish-anyway action.

![Findings sidebar](https://raw.githubusercontent.com/el-schneider/statamic-greenwashing-checker/main/screenshots/panel.png)
![Review stack](https://raw.githubusercontent.com/el-schneider/statamic-greenwashing-checker/main/screenshots/stack.png)
![Expanded inline findings](https://raw.githubusercontent.com/el-schneider/statamic-greenwashing-checker/main/screenshots/inline-expanded.png)

## Scan existing content

```sh
php please greenwashing:scan --collection=products
```

Omit filters to scan all enabled collections and sites. `--dry-run` reports uncached text without provider calls; `--prune` deletes stored analyses that no saved entry references. In the CP, **Utilities → Greenwashing-Check** queues the same analysis and lists entries by open HIGH count; it requires `scan greenwashing findings`.

Analysis results are stored in `storage/statamic/greenwashing-checker/` with no expiry; keep the directory out of Git and prune it with `greenwashing:scan --prune`. Approvals are editorial JSON records in `content/greenwashing-checker/approvals/` — include that directory in backups and editorial Git workflows.

## Data flow and cost

- Entry text is sent to the configured LLM provider on manual checks, on leaving a changed field (`check_on_blur`), and for publish warnings. Set `check_on_blur: false` and `on_publish: off` for manual-only use.
- Nothing is sent until a model and its credentials are configured. There is no telemetry.
- The provider receives uncached target text, capped entry context, company substantiations and locale codes. This may include unpublished copy; opt out sensitive fields. Provider retention and processing policies apply.
- Provider error details may appear in the private application log. Credentials are not added to log context; clients receive fixed error messages.
- Findings and dismissal metadata (user ID and timestamp) stay in the application's persistent unit store, with no TTL. Tracked job results expire after one hour. Approvals are stored separately as editorial records.
- Each uncached check makes one structured LLM call. Context incurs tokens too. Costs depend on model and provider pricing; unchanged text reuses cached findings.

## Limitations

- Text fields only. No images, logos, labels or asset text are examined.
- The model can miss or over-flag claims; truncated context can omit a qualification.
- Entry text is treated as data in the prompt, but LLM prompt injection remains a risk.
- No output establishes compliance with EU or national law. The fixed disclaimer is “Automatische Einschätzung, keine Rechtsberatung.” Seek qualified legal advice for publication decisions.

## Support and license

Community support via https://github.com/el-schneider/statamic-greenwashing-checker/issues. No SLA. MIT license, Jonas List.
