# Contributing

## Jurisdiction reference packs

Jurisdictions change displayed legal references and source links, not the EU analysis rules. EmpCo applies in the EU; it does not apply in Switzerland, for example. A display pack is not evidence of compliance or a legal opinion.

The default `de` pack shows EU references followed by German UWG references; `eu` shows the EU references alone. Choose the global pack in CP settings, where the **Legal basis** tab lists its official sources.

To propose a jurisdiction:

- Use official sources only: the EU Official Journal, a national gazette or authority pages. Include complete URLs and the relevant effective dates.
- Map each detection category to the directive article or Annex I point and, where applicable, its national implementation. Explain differences in numbering or grouping, especially durability and repair provisions. Do not infer a national provision from another country's numbering.
- Add a definition under `jurisdictions` in `config/greenwashing-checker.php`: `name`, `references` keyed by category, and `sources` with `label` and `url`. Each reference may be a string or a list of strings; lists are joined with ` · `. EU references come first.
- Use translation keys for names, references and source labels. German and English translations live in `lang/de` and `lang/en`; contributions may add languages. UI language follows the CP user, independently of jurisdiction and entry language.
- Add tests for the mapping and source links. Check the settings page and a finding in both UI languages. Jurisdiction selection must not change the prompt or analysis cache hash.

Developers can add or override definitions in their published config. Definitions merge by jurisdiction and reference category; a supplied `sources` list replaces that jurisdiction's list. The CP-only `jurisdiction_sites` setting maps site handles to jurisdiction keys and overrides displayed references on that site's findings, independently of the CP language (for example, key `french`, value `eu`). It has no PHP config fallback. Unknown selections fail at boot or settings save.

Maintainers check the sources, mappings and submission format. They do not provide legal advice or certify national implementation status. Prompt changes require a separate legal-core review and a prompt-version bump.

## Development

```sh
composer install
npm ci
vendor/bin/pest
vendor/bin/pint --test
npm run check
npm test
npm run build
```

The normal suite uses Prism fakes and blanks provider keys.

Real-provider evaluation uses 40 independently checked German snippets with per-category precision/recall. The small, hand-labelled corpus is not evidence of production accuracy. Supply credentials through environment variables, never arguments or a committed file:

```sh
GREENWASHING_EVAL=1 GREENWASHING_CHECKER_MODEL=openrouter/openai/gpt-6-luna \
  vendor/bin/pest --no-configuration --bootstrap vendor/autoload.php tests/Eval/ClaimsEvalTest.php
```

`OPENROUTER_API_KEY` must already be exported. The evaluation has no quality threshold; it prints counts and mistakes, and provider/configuration failures fail the run.

CI covers PHP 8.3/8.4 with Statamic 5.74 and 6.20.

Run the checks above before submitting changes.
