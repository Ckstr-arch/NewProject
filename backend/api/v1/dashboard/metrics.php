<?php
declare(strict_types=1);

function metricsQuery(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function handleMetrics(): never
{
    $user = requirePermission('dashboard.read');
    $admin = in_array($user['role'], ['sports_admin','super_admin'], true);
    $bookingWhere = $admin ? '' : ' WHERE booked_by = :userId';
    $bookingParams = $admin ? [] : ['userId' => (int) $user['id']];
    $activeMembers = (int) metricsQuery('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $clubs = (int) metricsQuery("SELECT COUNT(*) FROM clubs WHERE status = 'active'")->fetchColumn();
    $bookings = (int) metricsQuery('SELECT COUNT(*) FROM bookings' . $bookingWhere, $bookingParams)->fetchColumn();
    $upcomingWhere = " WHERE status IN ('scheduled','live') AND TIMESTAMP(event_date,start_time) >= UTC_TIMESTAMP()";
    $eventCount = (int) metricsQuery('SELECT COUNT(*) FROM schedules' . $upcomingWhere)->fetchColumn();
    $events = metricsQuery('SELECT id,sport,event_name,event_date,start_time,end_time,location,status FROM schedules' . $upcomingWhere . ' ORDER BY event_date,start_time,id LIMIT 6')->fetchAll();
    foreach ($events as &$event) $event['id'] = (int) $event['id'];
    unset($event);

    $counts = metricsQuery("SELECT event_date,COUNT(*) AS amount FROM schedules WHERE event_date BETWEEN DATE_SUB(UTC_DATE(), INTERVAL 6 DAY) AND UTC_DATE() AND status <> 'cancelled' GROUP BY event_date")->fetchAll();
    $byDate = array_column($counts, 'amount', 'event_date');
    $weekly = [];
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    for ($days = 6; $days >= 0; $days--) {
        $day = $today->modify('-' . $days . ' days');
        $date = $day->format('Y-m-d');
        $weekly[] = ['label' => $day->format('D'), 'date' => $date, 'count' => (int) ($byDate[$date] ?? 0)];
    }
    $recent = [];
    $sources = [
        ['clubs', 'club', 'name', 'sport'],
        ['schedules', 'schedule', 'event_name', 'sport'],
        ['tournaments', 'tournament', 'name', 'sport'],
        ['bookings', 'booking', 'facility', 'status'],
    ];
    foreach ($sources as [$table, $type, $title, $description]) {
        // Identifiers come only from the fixed source map above, never from request parameters.
        $where = $table === 'bookings' ? $bookingWhere : '';
        $params = $table === 'bookings' ? $bookingParams : [];
        $rows = metricsQuery('SELECT id,' . $title . ' AS title,' . $description . ' AS description,created_at FROM ' . $table . $where . ' ORDER BY created_at DESC,id DESC LIMIT 6', $params)->fetchAll();
        foreach ($rows as $row) $recent[] = ['id' => (int) $row['id'], 'resource' => $type, 'title' => $row['title'], 'description' => $row['description'], 'created_at' => $row['created_at']];
    }
    usort($recent, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']);
    jsonResponse(['active_members' => $activeMembers, 'clubs' => $clubs, 'upcoming_events_count' => $eventCount,
        'bookings' => $bookings, 'weekly_activity' => $weekly, 'recent_activity' => array_slice($recent, 0, 8), 'upcoming_events' => $events]);
}
