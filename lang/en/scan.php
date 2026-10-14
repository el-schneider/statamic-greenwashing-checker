<?php

declare(strict_types=1);

return [
    'saved_content' => 'Saved content',
    'results' => 'Findings by risk',
    'no_findings' => 'No findings',
    'permission' => 'Scan existing content for greenwashing',
    'description' => 'Check saved entries and view open, ignored and approved findings ordered by risk.',
    'collection' => 'Collection', 'all_collections' => 'All enabled collections',
    'start' => 'Start scan', 'refresh' => 'Refresh', 'loading' => 'Loading …',
    'entries' => '{0} 0 entries|{1} 1 entry|[2,*] :count entries',
    'units' => '{0} 0 text units|{1} 1 text unit|[2,*] :count text units',
    'uncached' => '{0} 0 uncached|{1} 1 uncached|[2,*] :count uncached',
    'characters' => '{0} 0 uncached characters|{1} 1 uncached character|[2,*] :count uncached characters',
    'cost_help' => 'The preview shows unchecked text without AI calls, not a price.',
    'queue_help' => 'The default sync queue runs scans inline; configure an asynchronous queue and worker for background processing. Parallel jobs may check identical text more than once.',
    'progress' => ':done of :total entries finished',
    'failures' => '{0} No failed entries|{1} 1 failed entry|[2,*] :count failed entries',
    'already_running' => 'Already in progress',
    'skipped' => '{0} No skipped entries|{1} 1 skipped entry (cached or in progress)|[2,*] :count skipped entries (cached or in progress)',
    'empty' => 'No findings for this selection. Start a scan to check saved entries.',
    'entry' => 'Entry', 'site' => 'Site', 'open' => 'Open', 'high' => 'High', 'ignored' => 'Ignored', 'approved' => 'Approved',
    'analysed_at' => 'Last checked', 'stale' => 'stale',
];
