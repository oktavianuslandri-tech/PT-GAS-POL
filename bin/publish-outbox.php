<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use Warehouse\Database;

$url = getenv('EVENT_PUBLISH_URL');
if ($url === false || filter_var($url, FILTER_VALIDATE_URL) === false
    || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
    fwrite(STDERR, "EVENT_PUBLISH_URL must be a valid HTTP(S) URL\n");
    exit(1);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "The PHP cURL extension is required\n");
    exit(1);
}

$db = Database::connection();
$published = 0;
$limit = max(1, min(100, (int) (getenv('OUTBOX_BATCH_SIZE') ?: 20)));

while ($published < $limit) {
    $db->beginTransaction();
    try {
        $statement = $db->query(
            "SELECT * FROM outbox_events
             WHERE status = 'PENDING' AND available_at <= CURRENT_TIMESTAMP
               AND (locked_until IS NULL OR locked_until < CURRENT_TIMESTAMP)
             ORDER BY created_at, id LIMIT 1 FOR UPDATE"
        );
        $event = $statement->fetch();
        if (!$event) {
            $db->commit();
            break;
        }
        $claim = $db->prepare(
            'UPDATE outbox_events SET locked_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 60 SECOND),
             attempts = attempts + 1 WHERE id = ?'
        );
        $claim->execute([$event['id']]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $event['payload'] = json_decode($event['payload'], true, 512, JSON_THROW_ON_ERROR);
    $envelope = [
        'event_id' => $event['id'],
        'event_type' => $event['event_type'],
        'occurred_at' => $event['created_at'],
        'payload' => $event['payload'],
    ];
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $token = getenv('EVENT_PUBLISH_TOKEN');
    if ($token !== false && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    $db->beginTransaction();
    try {
        if ($response !== false && $status >= 200 && $status < 300) {
            $done = $db->prepare(
                "UPDATE outbox_events SET status = 'SENT', sent_at = CURRENT_TIMESTAMP,
                 locked_until = NULL, last_error = NULL WHERE id = ?"
            );
            $done->execute([$event['id']]);
            $published++;
        } else {
            $message = $response === false ? $curlError : "Publisher returned HTTP {$status}";
            $retry = $db->prepare(
                'UPDATE outbox_events SET locked_until = NULL, last_error = ?,
                 available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL LEAST(300, POW(2, LEAST(attempts, 8))) SECOND)
                 WHERE id = ?'
            );
            $retry->execute([substr($message, 0, 1000), $event['id']]);
            fwrite(STDERR, "Failed to publish {$event['id']}: {$message}\n");
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

fwrite(STDOUT, "Published {$published} event(s)\n");
