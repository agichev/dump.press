<?php

$messages = [];
$status_rows = [];
$uid = 1;

for ($i = 0; $i < 1000; $i++) {
    $messages[] = ['id' => $i, 'sender_id' => ($i % 2 === 0 ? 1 : 2)];
    // Every message has 2 statuses
    $status_rows[] = ['message_id' => $i, 'user_id' => 1, 'status' => 'read'];
    $status_rows[] = ['message_id' => $i, 'user_id' => 2, 'status' => 'delivered'];
}

$start = microtime(true);
for ($k = 0; $k < 100; $k++) {
    $test_messages = $messages;
    foreach ($test_messages as &$msg) {
        $other = null;
        $my = null;
        foreach ($status_rows as $sr) {
            if ((int)$sr['message_id'] === (int)$msg['id']) {
                if ((int)$sr['user_id'] === $uid) $my = $sr['status'];
                else $other = $sr['status'];
            }
        }
        if ((int)$msg['sender_id'] === $uid) {
            $msg['my_status'] = $other ?: 'sent';
        } else {
            $msg['my_status'] = $my ?: 'sent';
        }
    }
}
$time_old = microtime(true) - $start;
echo "Old time: " . $time_old . " seconds\n";

$start = microtime(true);
for ($k = 0; $k < 100; $k++) {
    $test_messages = $messages;

    $status_lookup = [];
    foreach ($status_rows as $sr) {
        $msg_id = (int)$sr['message_id'];
        $user_id = (int)$sr['user_id'];
        if ($user_id === $uid) {
            $status_lookup[$msg_id]['my'] = $sr['status'];
        } else {
            $status_lookup[$msg_id]['other'] = $sr['status'];
        }
    }

    foreach ($test_messages as &$msg) {
        $msg_id = (int)$msg['id'];

        $my = $status_lookup[$msg_id]['my'] ?? null;
        $other = $status_lookup[$msg_id]['other'] ?? null;

        if ((int)$msg['sender_id'] === $uid) {
            $msg['my_status'] = $other ?: 'sent';
        } else {
            $msg['my_status'] = $my ?: 'sent';
        }
    }
}
$time_new = microtime(true) - $start;
echo "New time: " . $time_new . " seconds\n";
