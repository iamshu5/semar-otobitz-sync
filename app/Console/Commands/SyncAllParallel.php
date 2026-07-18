<?php
use Symfony\Component\Process\Process;

$tables = array_keys(config('sync.tables'));
$processes = [];

foreach ($tables as $table) {
    $process = new Process(['php', 'artisan', 'sync:data', '--table=' . $table]);
    $process->start();
    $processes[$table] = $process;
}

while (array_filter($processes, fn($p) => $p->isRunning())) {
    usleep(200000);
}

foreach ($processes as $table => $process) {
    // $process->getOutput()
}
?>
