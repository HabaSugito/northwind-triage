<?php

declare(strict_types=1);

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\TriageAgentService;

$messagesPath = __DIR__ . '/../data/messages.json';
$benchmarkPath = __DIR__ . '/../data/benchmark.json';
$resultsPath = __DIR__ . '/../data/batch_results.json';

$messages = json_decode(file_get_contents($messagesPath), true);
$benchmark = json_decode(file_get_contents($benchmarkPath), true);

$agent = $app->make(TriageAgentService::class);

$results = [];
$totals = [
    'category' => 0.0,
    'priority' => 0.0,
    'route_to' => 0.0,
    'needs_human_review' => 0.0,
    'strict' => 0,
];

$total = count($messages);
echo "Running batch triage on {$total} messages...\n\n";
echo str_pad('ID', 10) . str_pad('Cat', 5) . str_pad('Pri', 5) . str_pad('Route', 7) . str_pad('Flag', 6) . "Result\n";
echo str_repeat('-', 60) . "\n";

foreach ($messages as $i => $message) {
    $id = $message['id'];
    echo str_pad($id, 10);

    try {
        $agentResult = $agent->triage($message);
        $bench = $benchmark[$id];

        $scores = scoreMessage($agentResult, $bench);

        $totals['category'] += $scores['category'];
        $totals['priority'] += $scores['priority'];
        $totals['route_to'] += $scores['route_to'];
        $totals['needs_human_review'] += $scores['needs_human_review'];
        if ($scores['strict_match']) {
            $totals['strict']++;
        }

        $results[] = [
            'id' => $id,
            'agent' => $agentResult,
            'benchmark' => $bench,
            'scores' => $scores,
        ];

        echo str_pad((string) $scores['category'], 5)
            . str_pad((string) $scores['priority'], 5)
            . str_pad((string) $scores['route_to'], 7)
            . str_pad((string) $scores['needs_human_review'], 6)
            . ($scores['strict_match'] ? 'PASS' : 'FAIL') . "\n";

    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $results[] = [
            'id' => $id,
            'agent' => ['error' => $e->getMessage()],
            'benchmark' => $benchmark[$id] ?? null,
            'scores' => [
                'category' => 0,
                'priority' => 0,
                'route_to' => 0.0,
                'needs_human_review' => 0,
                'strict_match' => false,
            ],
        ];
    }

    if ($i < $total - 1) {
        usleep(500000);
    }
}

echo str_repeat('-', 60) . "\n\n";

$n = $total;
$summary = [
    'strict_accuracy' => round($totals['strict'] / $n, 4),
    'category_accuracy' => round($totals['category'] / $n, 4),
    'priority_accuracy' => round($totals['priority'] / $n, 4),
    'route_accuracy' => round($totals['route_to'] / $n, 4),
    'flag_accuracy' => round($totals['needs_human_review'] / $n, 4),
];

echo "=== Summary ===\n";
printf("Strict accuracy:    %5.1f%%  (%d / %d)\n", $summary['strict_accuracy'] * 100, $totals['strict'], $n);
printf("Category accuracy:  %5.1f%%\n", $summary['category_accuracy'] * 100);
printf("Priority accuracy:  %5.1f%%\n", $summary['priority_accuracy'] * 100);
printf("Route accuracy:     %5.1f%%\n", $summary['route_accuracy'] * 100);
printf("Flag accuracy:      %5.1f%%\n", $summary['flag_accuracy'] * 100);

$output = [
    'summary' => $summary,
    'results' => $results,
];

file_put_contents($resultsPath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nResults written to data/batch_results.json\n";

function scoreMessage(array $agent, array $bench): array
{
    $catScore = (int) ($agent['category'] === $bench['category']);
    $priScore = (int) ($agent['priority'] === $bench['priority']);
    $routeScore = scoreRoute($agent['route_to'], $bench['route_to']);
    $flagScore = (int) ($agent['needs_human_review'] === $bench['needs_human_review']);

    return [
        'category' => $catScore,
        'priority' => $priScore,
        'route_to' => $routeScore,
        'needs_human_review' => $flagScore,
        'strict_match' => $catScore === 1 && $priScore === 1 && $routeScore === 1.0 && $flagScore === 1,
    ];
}

function scoreRoute(string $agent, string $bench): float
{
    if ($agent === $bench) {
        return 1.0;
    }
    // 0.5 only when bench requires CC to a second team but agent routed to primary correctly
    if (str_contains($bench, ' + ') && explode(' + ', $agent)[0] === explode(' + ', $bench)[0]) {
        return 0.5;
    }
    return 0.0;
}
