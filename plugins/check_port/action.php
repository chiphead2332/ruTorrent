<?php
require_once(dirname(__FILE__) . "/../../php/settings.php");
require_once(dirname(__FILE__) . "/../../php/Snoopy.class.inc");
require_once(dirname(__FILE__) . "/parse.php");
require_once(dirname(__FILE__) . "/providers.php");

// Load the plugin's configuration settings from conf.php
eval(FileUtil::getPluginConf('check_port'));

// Default values for configuration, used if not set in conf.php
$currentCheckPortTimeout = isset($checkPortTimeout) ? (int)$checkPortTimeout : 15;
$currentUseWebsiteIPv4 = isset($useWebsiteIPv4) ? $useWebsiteIPv4 : "yougetsignal";
$currentUseWebsiteIPv6 = isset($useWebsiteIPv6) ? $useWebsiteIPv6 : "portchecker";

$currentFailoverProvidersIPv4 = isset($failoverProvidersIPv4)
        ? $failoverProvidersIPv4
        : array("globalping", "portchecker");

$currentFailoverProvidersIPv6 = isset($failoverProvidersIPv6)
        ? $failoverProvidersIPv6
        : array("globalping");

/**
 * Gets the public IP address (IPv4 or IPv6) from ipify.org
 * It uses Snoopy (a curl wrapper) to make the request
 *
 * @param string $version '4' for IPv4, '6' for IPv6
 * @param int $timeout Request timeout
 * @return string|null The public IP address or null on failure
 */
function get_public_ip($version, $timeout) {
	if (!Utility::getExternal('curl')) {
		error_log("check_port plugin: 'curl' executable not found");
		return null;
	}
	// Initialize the Snoopy client
	$snoopy = new Snoopy();
	$snoopy->agent = "ruTorrent CheckPort Plugin/IP Check";
	// Set a timeout for the request, with a minimum of 5 seconds
	$snoopy->read_timeout = max(5, (int)($timeout / 2));
	$snoopy->proxy_host = ""; // Do not use a proxy for this external IP check

	// Select the correct ipify API URL based on the requested IP version
	$url = ($version == '6') ? "https://api64.ipify.org/" : "https://api4.ipify.org/";
	@$snoopy->fetch($url); // Fetch the URL

	// Check if the request was successful and returned content
	if ($snoopy->status == 200 && !empty($snoopy->results)) {
		$ip = trim($snoopy->results);
		// Validate the returned IP address to ensure it's a valid IPv4 or IPv6
		$flag = ($version == '6') ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
		if (filter_var($ip, FILTER_VALIDATE_IP, $flag)) {
			return $ip; // Return the valid IP
		}
	} else {
	}
	return null; // Return null on failure
}







/**
 * Main logic to get an IP and check its port status for a given IP version
 *
 * @param string $ip_version '4' or '6', for IPv4 or IPv6
 * @param string $use_website The checking service to use ('yougetsignal' or 'portchecker')
 * @param string $rtorrent_ip The IP address configured in rTorrent (if any)
 * @param int $rtorrent_port The listening port configured in rTorrent
 * @param int $timeout The request timeout in seconds
 * @return array An associative array with 'ip' and 'status' keys
 */
function get_and_check_ip($ip_version, $use_website, $rtorrent_ip, $rtorrent_port, $timeout) {
	global $checkPortProviders, $currentFailoverProvidersIPv4, $currentFailoverProvidersIPv6;
	$ip_to_check = null;
	$flag = ($ip_version == '6') ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;

	if (!empty($rtorrent_ip) && filter_var($rtorrent_ip, FILTER_VALIDATE_IP, $flag)) {
		$ip_to_check = $rtorrent_ip;
		// If rTorrent's IP is not set or invalid for the version, fetch the public IP
	} else {
		$ip_to_check = get_public_ip($ip_version, $timeout);
	}

	// If an IP was determined, try the selected provider first,
	// followed by the configured secondary providers.
	if ($ip_to_check) {
                $version = ($ip_version == '4') ? 'ipv4' : 'ipv6';
                $failover = ($ip_version == '4')
                        ? $currentFailoverProvidersIPv4
                        : $currentFailoverProvidersIPv6;
                $providers = array_unique(array_merge([$use_website], $failover));

                foreach ($providers as $provider) {
                        if (
                                !isset($checkPortProviders[$provider]) ||
                                empty($checkPortProviders[$provider][$version])
                        ) {
                                continue;
                        }

                        $status = call_user_func(
                                $checkPortProviders[$provider]["function"],
                                $ip_to_check,
                                $rtorrent_port,
                                $timeout
                        );

                        if ($status === 1 || $status === 2) {
                                return [
                                        "ip" => $ip_to_check,
                                        "status" => $status
                                ];
                        }
                }

                return [
                        "ip" => $ip_to_check,
                        "status" => 0
                ];
        }
	// Return a default "not available" state if no IP could be determined
	return ["ip" => "-", "status" => -1];
}

// --- Main Execution ---
// Get rTorrent's listening port and configured IP from settings
$port = rTorrentSettings::get()->port;
$ip_glob = rTorrentSettings::get()->ip;

// Optional: force a specific listening port before checking it. rtorrent
// >= 0.16.18 exposes network.listen.port.set, which changes the live listening
// port with no restart. Requires a trusted connection (the default here). The
// port check below then runs against the new port so the user can immediately
// confirm reachability.
if (isset($_REQUEST['setport'])) {
	$newport = (int)$_REQUEST['setport'];
	if ($newport >= 1 && $newport <= 65535) {
		$sreq = new rXMLRPCRequest(new rXMLRPCCommand("network.listen.port.set", array("", $newport)));
		if ($sreq->success())
			$port = $newport;
	}
}

// Initialize the response structure that will be sent to the client
$response = [
	"ipv4" => "-", "ipv4_port" => (int)$port, "ipv4_status" => -1,
	"ipv6" => "-", "ipv6_port" => (int)$port, "ipv6_status" => -1,
];

// Perform the IPv4 check if it's enabled in conf.php
if ($currentUseWebsiteIPv4 !== false) {
	$ipv4_result = get_and_check_ip('4', $currentUseWebsiteIPv4, $ip_glob, $port, $currentCheckPortTimeout);
	$response["ipv4"] = $ipv4_result["ip"];
	$response["ipv4_status"] = $ipv4_result["status"];
}

// Perform the IPv6 check if it's enabled in conf.php
if ($currentUseWebsiteIPv6 !== false) {
	$ipv6_result = get_and_check_ip('6', $currentUseWebsiteIPv6, $ip_glob, $port, $currentCheckPortTimeout);
	$response["ipv6"] = $ipv6_result["ip"];
	$response["ipv6_status"] = $ipv6_result["status"];
}

// Send the final JSON response to the client
CachedEcho::send(JSON::safeEncode($response), "application/json");
