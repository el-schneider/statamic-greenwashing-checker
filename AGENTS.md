# Greenwashing Checker

Public beta for EU greenwashing risk checks (EmpCo) with German UWG references in Statamic 6 entries and a reduced panel on Statamic 5. Findings flag potential risks; they are not legal advice or evidence of compliance.

## Development Commands

Install Composer dependencies before npm dependencies: `@statamic/cms` is a Composer file dependency.

```sh
vendor/bin/pest
vendor/bin/pint --test
npm run check
npm test
npm run build
```

Normal tests blank provider keys. Real-provider evaluation is explicit opt-in; never commit credentials.

## Contributing

- Keep PRs small and focused. Do not touch unrelated files.
- Describe what you verified and how. Verify UI changes in a real browser with the addon installed in a Statamic app.
- Prefer inline documentation over duplicating implementation details in the README.
- Pre-v1: prefer idiomatic Statamic APIs over preserving accidental compatibility.
- Comments explain why, not what changed. History belongs in commits and changelogs.
- Add nothing you can derive or reuse. No abstraction with a single caller.
- Fix the cause, not the symptom. Let failures surface; map provider errors at job/HTTP boundaries, not individual call sites.
- No new dependencies without discussion.
- Never claim legal compliance in code, UI or docs.

## Manual Testing

Sandboxes and credentials belong in an untracked `AGENTS.local.md` in this directory or a parent. Read it before running the addon in a Statamic app; keep machine-specific details out of shared instructions.

## Off-Limits Files

- `resources/dist/` is generated; do not edit it by hand. Frontend changes include the rebuilt assets (`npm run build`); CI rebuilds and fails if they differ. The build-assets workflow also rebuilds and commits changed assets on `main`.
- `CHANGELOG.md` is maintainer-owned.
- Do not edit `vendor/` or lock files unless dependencies change.

## Project Invariants

- Persistent unit records contain arrays/scalars only, survive cache clearing, and use locked atomic JSON replacement. Validate hashes at the storage boundary.
- Analysis storage is per text, not entry or path. Rebind paths on reads and preserve dismissals on concurrent writes. Ignores persist until text/settings change or prune; retain inline restore controls and include ignored HIGH findings in publish warnings.
- Approvals are permanent content files, not cache. Keep reasons as text, preserve other decisions under a lock, and require both entry access and approval permission at the HTTP seam.
- Only analysis-changing settings enter the unit hash; threshold and jurisdiction are display-only.
- Changing prompt semantics requires bumping `ClaimAnalyzer::PROMPT_VERSION`.
- Render provider strings as text, including suggestions inserted into Bard. Never use `v-html`.

## Gotchas

- Creation blueprint events have a collection parent and no entry; inject the panel in both cases.
- Use complete publish-value paths: `fieldPathPrefix` plus `handle`, including replicator indices. Bard yields one prose unit per field, excluding set text; set fields recurse at `<bardPath>.<topLevelNodeIndex>.attrs.values.<handle>`.
- Use the Tiptap/ProseMirror objects passed to Bard extensions; never import editor runtimes into distribution assets.
- Inline boxes and review jumps depend on core IDs: `field_` plus the complete path with dots replaced by underscores. IDs can be on inputs; inject into the closest `.form-group` or `td.grid-cell`. Recheck these lookups on core upgrades.
- The publish-warning guard compares `payload.values` with `context.values.value` by object identity; recheck this hook contract on core upgrades.
- Publish form discovery must handle `.publish-form`, enclosing `aside` and `.content-card`. MutationObserver reconciliation must not cause repeated layout changes; unmount injected apps on reference/site changes and panel teardown.
- Publish inputs debounce context updates by 150ms. Wait for that commit window before capturing analysis values.
- Never reject `entry.saving`: older core pipelines cannot cancel safely. Without revisions, review saves a draft; revision publication uses the cancellable `entry.publishing` hook.
- Statamic 5 IDs are flat `field_<handle>`; jump/apply only address top-level fields. Use Vuex `publish/<storeName>` (`inject: ['storeName']`, `dispatch('publish/<name>/setFieldValue', {handle, value})`), not `injectPublishContext()`.
- On v5, `$bard` extensions receive no `tiptap` argument, and there is no addon settings API or `@statamic/cms/*` package. Keep the Vue 2.7 v5 bundle free of `@statamic/cms/*` imports; `npm run check:v5` uses plain `tsc` because `vue-tsc` covers v6.
- After building in a path-repository sandbox, republish assets: `php artisan vendor:publish --tag=statamic-greenwashing-checker --force`.
