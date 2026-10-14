<?php

declare(strict_types=1);

$euSources = [
    ['label' => 'greenwashing-checker::settings.sources.eurlex', 'url' => 'https://eur-lex.europa.eu/legal-content/DE/TXT/?uri=OJ:L_202400825'],
    ['label' => 'greenwashing-checker::settings.sources.commission', 'url' => 'https://commission.europa.eu/document/download/3c257883-bb2a-4dd9-a6dc-501d587bb34f_en?filename=faq-empowerting-consumers-gtd.pdf'],
];

return [
    // Display only: neither selection nor reference overrides change the analysis cache.
    'jurisdiction' => 'de',
    'jurisdictions' => [
        'eu' => [
            'name' => 'greenwashing-checker::settings.jurisdictions.eu',
            'references' => [
                'generic_claim' => ['greenwashing-checker::references.eu.generic_claim'],
                'offset_neutrality' => ['greenwashing-checker::references.eu.offset_neutrality'],
                'scope_overreach' => ['greenwashing-checker::references.eu.scope_overreach'],
                'unofficial_label' => ['greenwashing-checker::references.eu.unofficial_label'],
                'legal_requirement_as_feature' => ['greenwashing-checker::references.eu.legal_requirement_as_feature'],
                'future_performance' => ['greenwashing-checker::references.eu.future_performance'],
                'irrelevant_benefit' => ['greenwashing-checker::references.eu.irrelevant_benefit'],
                'misleading_environmental' => ['greenwashing-checker::references.eu.misleading_environmental'],
                'durability_repair' => ['greenwashing-checker::references.eu.durability_repair'],
            ],
            'sources' => $euSources,
        ],
        'de' => [
            'name' => 'greenwashing-checker::settings.jurisdictions.de',
            'references' => [
                'generic_claim' => ['greenwashing-checker::references.eu.generic_claim', 'greenwashing-checker::references.de.generic_claim'],
                'offset_neutrality' => ['greenwashing-checker::references.eu.offset_neutrality', 'greenwashing-checker::references.de.offset_neutrality'],
                'scope_overreach' => ['greenwashing-checker::references.eu.scope_overreach', 'greenwashing-checker::references.de.scope_overreach'],
                'unofficial_label' => ['greenwashing-checker::references.eu.unofficial_label', 'greenwashing-checker::references.de.unofficial_label'],
                'legal_requirement_as_feature' => ['greenwashing-checker::references.eu.legal_requirement_as_feature', 'greenwashing-checker::references.de.legal_requirement_as_feature'],
                'future_performance' => ['greenwashing-checker::references.eu.future_performance', 'greenwashing-checker::references.de.future_performance'],
                'irrelevant_benefit' => ['greenwashing-checker::references.eu.irrelevant_benefit', 'greenwashing-checker::references.de.irrelevant_benefit'],
                'misleading_environmental' => ['greenwashing-checker::references.eu.misleading_environmental', 'greenwashing-checker::references.de.misleading_environmental'],
                'durability_repair' => ['greenwashing-checker::references.eu.durability_repair', 'greenwashing-checker::references.de.durability_repair'],
            ],
            'sources' => [
                ...$euSources,
                ['label' => 'greenwashing-checker::settings.sources.gazette', 'url' => 'https://www.recht.bund.de/bgbl/1/2026/43/VO.html'],
                ['label' => 'greenwashing-checker::settings.sources.uba', 'url' => 'https://www.umweltbundesamt.de/themen/neue-regeln-schutz-vor-greenwashing'],
            ],
        ],
    ],
    'model' => env('GREENWASHING_CHECKER_MODEL', 'openai/gpt-6-luna'),
    // Client timeout is clamped to 1–110 seconds, below the job's 120-second stale threshold.
    'timeout' => 60,
    // '*' or a list of collection handles.
    'collections' => '*',
    // Schedule a whole-entry check one second after leaving a changed field.
    'check_on_blur' => true,
    'placement' => 'sidebar', // sidebar, tab, or none (manual blueprint field).
    'on_publish' => 'warn', // off or warn; never blocks on provider failure.
    'threshold' => 'all', // Display-only: all or high.
    // Restricts both the prompt and returned findings.
    'categories' => array_column(ElSchneider\GreenwashingChecker\Analysis\Category::cases(), 'value'),
    // Caps context text, preferring the title and short fields; target text is never truncated.
    'max_context_chars' => 8000,
];
