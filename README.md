# Statamic Greenwashing Checker

> Flags greenwashing risks in Statamic entries while editors write. Covers the EU rules on environmental claims (Directive 2024/825, “EmpCo”), which apply from 27 September 2026.

Phrases like “eco-friendly”, “climate-neutral thanks to offsetting” or “100 % green” become per-se violations across the EU under EmpCo. This addon sends entry text to an LLM of your choice. It marks risky claims in the field where they appear, explains the risk and suggests a rewrite that editors can apply with one click.

The addon flags potential risks. It is not legal advice and does not establish compliance.

![Findings in the entry editor](https://raw.githubusercontent.com/el-schneider/statamic-greenwashing-checker/main/screenshots/inline-expanded.png)

## Features

- **Checks while editing**: a sidebar panel checks unsaved text. Findings appear below each affected field, including fields nested in Bard, Replicator, Grid and Group. Risky phrases in Bard are highlighted.
- **Suggested rewrites**: each finding has one to three replacements. **Apply** replaces only the quoted phrase and keeps the editor’s undo history.
- **Nine risk categories**, mapped to the EmpCo/UCPD articles. German sites can also show the matching UWG references.
- **Context-aware**: your real certificates and licences (“substantiations”) and house rules go into the prompt, so documented claims are not flagged. B2B collections skip the consumer-only per-se rules.
- **Review workflow**: editors can ignore a finding, or approve it with a documented reason (separate permission). Approvals are stored as content files you can commit to Git.
- **Publish warning**: publishing an entry with open high-risk findings asks for confirmation. It never blocks.
- **Bulk scans**: scan saved content from the CLI or from a CP utility that ranks entries by risk.
- **Any Prism provider**: OpenAI, Anthropic, Gemini, Mistral, OpenRouter, Ollama and more, via [Prism](https://prismphp.com). Results are cached per text, so unchanged copy is never sent twice.
- **German and English** UI. Explanations follow the editor’s CP language; suggested rewrites follow the entry’s language.

| Review all findings | Scan saved content |
| :-- | :-- |
| ![Review stack](https://raw.githubusercontent.com/el-schneider/statamic-greenwashing-checker/main/screenshots/stack.png) | ![Scan utility](https://raw.githubusercontent.com/el-schneider/statamic-greenwashing-checker/main/screenshots/utility.png) |

## Requirements

- PHP 8.3+
- Statamic 6.20+, or Statamic 5.74+ on Laravel 11/12 with a reduced feature set (see [Statamic 5](#statamic-5))
- An API key for an LLM provider supported by Prism. Each check is one paid API call.

## Installation

```bash
composer require el-schneider/statamic-greenwashing-checker
```

Set a model and its provider key in `.env`. The model string is `<prism-provider>/<model>`:

```dotenv
GREENWASHING_CHECKER_MODEL=openai/gpt-6-luna
OPENAI_API_KEY=sk-...

# or, for example, through OpenRouter:
# GREENWASHING_CHECKER_MODEL=openrouter/openai/gpt-6-luna
# OPENROUTER_API_KEY=sk-or-...
```

Provider keys come from Prism’s own `config/prism.php`. Until a key is configured, the panel shows a setup notice and nothing is sent.

Open any entry: the **Greenwashing check** panel appears in the sidebar for all collections.

## Configuration

Editors and admins configure most things in the CP under **Addons → Greenwashing Checker → Settings**:

| Setting | Effect |
| :-- | :-- |
| Substantiations | Certificates and licences you hold, with their scope. Claims they cover are not flagged. |
| House rules | Extra editorial guidance for the model. It cannot switch off the legal checks. |
| Audience per collection | B2C (default) or B2B. B2B skips the consumer per-se rules and downgrades findings to medium. |
| Enabled categories | Turn individual risk categories off. |
| Legal references | `eu` shows EmpCo/UCPD references; `de` adds German UWG references. Can differ per site. |
| Collections, placement, check on blur, publish warning | Where and when checks run. |

To change the model, timeout or the defaults for these settings, publish the config:

```bash
php artisan vendor:publish --tag=greenwashing-checker-config
```

The comments in `config/statamic/greenwashing-checker.php` explain each option. CP settings override the config file.

### Exclude fields

Fields with internal or non-marketing text can opt out in the blueprint. On a Replicator, Grid, Group or Bard field this excludes everything inside it:

```yaml
-
  handle: internal_notes
  field:
    type: textarea
    greenwashing_checker: false
```

### Panel placement

`placement` can be `sidebar` (default), `tab` (separate tab) or `none`. With `none`, add the **Greenwashing Checker** fieldtype to a blueprint where you want the panel.

### Customize the prompt

The system and user prompts are Blade views. Publish them to adjust wording or add rules:

```bash
php artisan vendor:publish --tag=greenwashing-checker-prompts
```

Cached results do not notice prompt edits. Delete `storage/statamic/greenwashing-checker/units/` to have all text checked again.

### Permissions

| Permission | Allows |
| :-- | :-- |
| Approve greenwashing findings | Approving findings with a documented reason |
| Scan existing content for greenwashing | Using the scan utility |

Running a check requires permission to edit the entry.

## Scanning existing content

```bash
php please greenwashing:scan                      # all enabled collections and sites
php please greenwashing:scan --collection=products --site=default
php please greenwashing:scan --dry-run            # count unchecked text, no API calls
php please greenwashing:scan --prune              # delete stored results no entry uses
```

The CLI writes explanations in the app locale. The **Utilities → Greenwashing check** page starts the same scan from the CP and lists entries by open high-risk findings. Long scans need a queue worker. The default `sync` queue runs them inline.

## Data, storage and cost

- **What is sent**: the text of changed fields, a capped amount of surrounding entry context (`max_context_chars`), your substantiations and house rules. This can include unpublished copy. Opt out sensitive fields.
- **When**: on **Check**, one second after an editor leaves a changed field (`check_on_blur`), and on scans. Set `check_on_blur` to `false` for manual-only checks.
- **Cost**: one structured LLM call per check, for uncached text only. Results are keyed by text and analysis settings, so unchanged copy reuses its findings.
- **Storage**: results are stored in `storage/statamic/greenwashing-checker/` without expiry. Approvals are stored in `content/greenwashing-checker/approvals/`. Commit and back up approvals like other content.
- No telemetry. Provider retention policies apply.

## Statamic 5

Statamic 5 has no addon settings API and no Inertia CP. Analysis, caching, the scan command and the review sheet work the same way. These parts are Statamic 6 only:

- CP settings. Configure through the config file instead; substantiations, house rules, audience and per-site references stay empty.
- Inline field boxes, Bard highlights, publish warnings and approvals. The Statamic 5 panel lives in the sidebar, and **Apply** works on top-level text, textarea and markdown fields.
- The interactive scan utility. Statamic 5 gets a read-only list; use `greenwashing:scan` to scan.

## Limitations

- Only text is checked. Images, logos and labels in assets are not.
- The model can miss claims or flag harmless ones. Truncated context can hide a qualification made elsewhere on the page.
- Entry text is passed to the model as data, but prompt injection remains possible.
- Legal references cover the EU and Germany. Other national implementations can be contributed, see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. Support through [GitHub issues](https://github.com/el-schneider/statamic-greenwashing-checker/issues), no SLA.
