<?php

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function beadWorker(string $database, string $mode, ?int $reservation = null): Process
{
    return new Process(array_values(array_filter([PHP_BINARY, '-d', 'memory_limit=512M',
        base_path('tests/Support/bead-concurrency-worker.php'), $database, $mode,
        $reservation === null ? null : (string) $reservation])), base_path(), timeout: 60);
}

it('serializes duplicate and mixed-family-member workers using one shared physical pool', function () {
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $database = realpath($dir).DIRECTORY_SEPARATOR.'bead-race-'.Str::uuid().'.sqlite';
    touch($database);
    try {
        $setup = beadWorker($database, 'setup');
        $setup->mustRun();
        $ids = json_decode($setup->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $workers = [beadWorker($database, 'execute', $ids[4]), beadWorker($database, 'execute', $ids[4]), beadWorker($database, 'execute', $ids[3])];
        foreach ($workers as $worker) {
            $worker->start();
        }
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful(), $worker->getErrorOutput())->toBeTrue();
        }
        $inspect = beadWorker($database, 'inspect');
        $inspect->mustRun();
        expect(array_sum(json_decode($inspect->getOutput(), true)))->toBe(18)
            ->and(array_sum(json_decode(file_get_contents($database.'.remote.json'), true)))->toBe(18);
    } finally {
        foreach (glob($database.'*') as $artifact) {
            unlink($artifact);
        }
    }
});

it('reuses the remote operation after Shopify success and local transaction failure', function () {
    $dir = storage_path('framework/testing');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $database = realpath($dir).DIRECTORY_SEPARATOR.'bead-race-'.Str::uuid().'.sqlite';
    touch($database);
    try {
        $setup = beadWorker($database, 'setup');
        $setup->mustRun();
        $ids = json_decode($setup->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $failed = beadWorker($database, 'fail-local', $ids[4]);
        $failed->run();
        expect($failed->getExitCode())->toBe(2);
        $retry = beadWorker($database, 'execute', $ids[4]);
        $retry->mustRun();
        $inspect = beadWorker($database, 'inspect');
        $inspect->mustRun();
        expect(array_sum(json_decode($inspect->getOutput(), true)))->toBe(12)
            ->and(array_sum(json_decode(file_get_contents($database.'.remote.json'), true)))->toBe(12);
    } finally {
        foreach (glob($database.'*') as $artifact) {
            unlink($artifact);
        }
    }
});
