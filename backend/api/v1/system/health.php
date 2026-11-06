<?php
declare(strict_types=1);

function handleHealth(): never
{
    $started = hrtime(true);
    try {
        $statement = db()->prepare('SELECT 1');
        $statement->execute();
        if ((int) $statement->fetchColumn() !== 1) throw new RuntimeException('Unavailable database.');
    } catch (Throwable) {
        errorResponse('SERVICE_UNAVAILABLE', 'The database service is unavailable.', [], 503);
    }
    jsonResponse(['status' => 'operational', 'database' => 'connected', 'version' => '1.0.0',
        'latency_ms' => round((hrtime(true) - $started) / 1000000, 2)]);
}
