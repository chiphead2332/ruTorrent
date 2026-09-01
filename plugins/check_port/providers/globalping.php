<?php
/**
 * Checks port status using Globalping.
 *
 * Returns 0 = unknown, 1 = closed, 2 = open.
 */
function check_port_globalping($ip, $port, $timeout) {
        $client = new Snoopy();
        $client->read_timeout = max(5, min((int)$timeout, 10));
        $client->proxy_host = "";

        $payload = json_encode([
                "target" => $ip,
                "type" => "ping",
                "measurementOptions" => [
                        "protocol" => "TCP",
                        "port" => (int)$port,
                ],
        ]);

        @$client->fetch(
                "https://api.globalping.io/v1/measurements",
                "POST",
                "application/json",
                $payload
        );

        if ($client->status < 200 || $client->status >= 300) {
                error_log(
                        "check_port: Globalping create failed for {$ip}. " .
                        "Status: {$client->status}, Error: {$client->error}"
                );
                return 0;
        }

        $created = json_decode($client->results, true);
        if (!is_array($created) || empty($created["id"])) {
                error_log(
                        "check_port: Invalid Globalping create response for {$ip}. " .
                        "Response: " . substr($client->results, 0, 500)
                );
                return 0;
        }

        $id = $created["id"];
        $deadline = microtime(true) + $client->read_timeout;

        for ($poll = 0; $poll < 3; $poll++) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                        break;
                }

                usleep((int)(min(2, $remaining) * 1000000));

                if (microtime(true) >= $deadline) {
                        break;
                }

                @$client->fetch(
                        "https://api.globalping.io/v1/measurements/" .
                        rawurlencode($id)
                );

                if ($client->status != 200) {
                        error_log(
                                "check_port: Globalping poll failed for {$id}. " .
                                "Status: {$client->status}, Error: {$client->error}"
                        );
                        return 0;
                }

                $result = json_decode($client->results, true);
                if (!is_array($result)) {
                        return 0;
                }

                if (($result["status"] ?? "") === "in-progress") {
                        continue;
                }

                if (($result["status"] ?? "") !== "finished") {
                        return 0;
                }

                $raw = $result["results"][0]["result"]["rawOutput"] ?? "";
                if (!is_string($raw) || $raw === "") {
                        return 0;
                }

                if (preg_match('/connection refused|tcp_conn=.*refused/i', $raw)) {
                        return 1;
                }

                if (preg_match('/tcp_conn=\d+/i', $raw)) {
                        return 2;
                }

                return 0;
        }

        return 0;
}
