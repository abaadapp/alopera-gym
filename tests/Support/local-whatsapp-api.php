<?php

/*
 * A stand-in for WhatsApp's API on 127.0.0.1, for the send-once tests (#203). It takes each request in full and
 * records it (so a test knows the request reached the "provider"), then answers as told:
 *   silent — never answers: the client's own timeout runs out after the request arrived (cURL 28);
 *   close  — closes without a word: an empty reply after the request arrived (cURL 52);
 *   ok     — accepts it, as WhatsApp does.
 * Usage: php local-whatsapp-api.php <mode> <log file>. Prints "127.0.0.1:<port>" once listening.
 */

[, $mode, $log] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, "cannot listen: {$error}\n");
    exit(1);
}
echo stream_socket_get_name($server, false), "\n";

while ($connection = @stream_socket_accept($server, 120)) {
    $request = '';
    while (! str_contains($request, "\r\n\r\n") && ($chunk = fread($connection, 8192)) !== false && $chunk !== '') {
        $request .= $chunk;
    }
    [$head, $body] = explode("\r\n\r\n", $request, 2) + [1 => ''];
    if (preg_match('/^content-length:\s*(\d+)/im', $head, $m)) {
        while (strlen($body) < (int) $m[1] && ($chunk = fread($connection, 8192)) !== false && $chunk !== '') {
            $body .= $chunk;
        }
    }
    file_put_contents($log, json_encode(['line' => strtok($head, "\r\n"), 'body' => $body])."\n", FILE_APPEND);

    if ($mode === 'silent') {
        sleep(60);
    } elseif ($mode === 'ok') {
        $json = '{"messaging_product":"whatsapp","messages":[{"id":"wamid.LOCAL-'.bin2hex(random_bytes(4)).'"}]}';
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($json)."\r\nConnection: close\r\n\r\n".$json);
    }
    fclose($connection);
}
