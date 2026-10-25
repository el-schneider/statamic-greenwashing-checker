# Changelog

## v0.1.1 - 2026-10-25

### What's fixed

- CP pages no longer fail with a missing Vite manifest after a fresh install. The addon now publishes its assets when `statamic:install` runs after `composer install`. Existing v0.1.0 installs can run `php artisan vendor:publish --tag=statamic-greenwashing-checker --force` instead of upgrading.

## v0.1.0 - 2026-10-25

First public beta. Flags greenwashing risks in Statamic entries under the EU rules on environmental claims (Directive 2024/825, “EmpCo”), which apply from 27 September 2026. Findings are risk hints, not legal advice.

### What's new

- Checks unsaved entry text with any [Prism](https://prismphp.com) provider and shows findings below each affected field, including fields nested in Bard, Replicator, Grid and Group
- One to three suggested rewrites per finding; **Apply** replaces only the quoted phrase and keeps the editor's undo history
- Nine risk categories mapped to EmpCo/UCPD articles, with optional German UWG references per site
- Substantiations, house rules and a B2B audience setting per collection reduce false positives
- Ignore findings, or approve them with a documented reason; approvals are content files you can commit
- Publish warning for open high-risk findings that never blocks publishing
- `greenwashing:scan` command and a CP utility to scan saved content, with a dry run that counts unchecked text
- Results cached per text, so unchanged copy is never sent twice
- German and English UI
- Statamic 6.20+; Statamic 5.74+ on Laravel 11/12 with a reduced panel

## 0.1.0 - unreleased

- AI-assisted marketing-copy checks under EU greenwashing rules (EmpCo), with German UWG references, explanations and suggested rewrites. Public beta, not legal advice.
- Statamic 6 editor panel, grouped review stack, nested-field callouts, Bard highlights and configurable publish warnings. Statamic 5.74 support (Laravel 11/12): sidebar panel with a review sheet in Statamic 5's own stack, and a read-only scan utility.
- German and English UI, native Statamic 6 addon settings, configurable legal categories, collection audience and substantiations.
- Persistent text-level caching, ignored findings and permission-protected approvals with documented reasons and git-trackable records.
- Saved-content CLI scans and a collection scan utility with cost previews, cache reuse, queued scans, progress polling and per-entry summaries.
