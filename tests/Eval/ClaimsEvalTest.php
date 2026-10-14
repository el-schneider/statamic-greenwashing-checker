<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Analysis\Category;
use ElSchneider\GreenwashingChecker\Analysis\ClaimAnalyzer;
use ElSchneider\GreenwashingChecker\Content\TextUnit;

it('reports per-category precision and recall on independent German entries', function () {
    config(['statamic.greenwashing-checker.model' => getenv('GREENWASHING_CHECKER_MODEL') ?: 'openrouter/openai/gpt-6-luna']);
    $counts = array_fill_keys(array_column(Category::cases(), 'value'), ['tp' => 0, 'fp' => 0, 'fn' => 0]);
    $corpus = require __DIR__.'/claims.php';
    $mistakes = [];
    $totalFindings = $findingsWithSuggestions = 0;
    foreach ($corpus as $row) {
        $rules = ElSchneider\GreenwashingChecker\Settings::analysis();
        $rules['substantiations'] = $row['substantiations'] ?? [];
        $unit = new TextUnit('body', 'textarea', $row['text'], 'Body');
        $findings = app(ClaimAnalyzer::class)->analyze([$unit], [$unit], 'de', 'de', $rules)['body'];
        $totalFindings += count($findings);
        $findingsWithSuggestions += count(array_filter($findings, fn ($finding) => $finding->suggestions !== []));
        $actual = array_values(array_unique(array_map(fn ($finding) => $finding->category->value, $findings)));
        foreach ($counts as $category => &$count) {
            $expected = in_array($category, $row['categories'], true);
            $found = in_array($category, $actual, true);
            if ($expected && $found) {
                $count['tp']++;
            } elseif ($found) {
                $count['fp']++;
            } elseif ($expected) {
                $count['fn']++;
            }
        }
        unset($count);
        if (array_diff($actual, $row['categories']) || array_diff($row['categories'], $actual)) {
            $mistakes[] = $row['id'].' expected='.json_encode($row['categories']).' actual='.json_encode($actual);
        }
        fwrite(STDOUT, '.');
    }
    fwrite(STDOUT, "\ncategory                         TP FP FN precision recall\n");
    foreach ($counts as $category => $count) {
        $precision = $count['tp'] + $count['fp'] === 0 ? 'n/a' : sprintf('%.3f', $count['tp'] / ($count['tp'] + $count['fp']));
        $recall = $count['tp'] + $count['fn'] === 0 ? 'n/a' : sprintf('%.3f', $count['tp'] / ($count['tp'] + $count['fn']));
        fwrite(STDOUT, sprintf("%-32s %2d %2d %2d %9s %6s\n", $category, $count['tp'], $count['fp'], $count['fn'], $precision, $recall));
    }
    fwrite(STDOUT, implode("\n", $mistakes)."\n");
    $suggestionShare = $totalFindings === 0 ? 'n/a' : sprintf('%.3f', $findingsWithSuggestions / $totalFindings);
    fwrite(STDOUT, "Findings with ≥1 suggestion: {$findingsWithSuggestions}/{$totalFindings} (share {$suggestionShare})\n");
    expect($corpus)->toHaveCount(40);
    expect($totalFindings)->toBeGreaterThan(0);
    expect($findingsWithSuggestions / $totalFindings)->toBeGreaterThanOrEqual(0.95);
})->skip(getenv('GREENWASHING_EVAL') !== '1', 'Opt-in real-provider evaluation; see CONTRIBUTING.md.');
