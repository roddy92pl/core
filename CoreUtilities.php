<?php
class CoreUtilities {
	public static $db = null;
	public static $redis = null;
	public static $rRequest = array();
	public static $rConfig = array();
	public static $rSettings = array();
	public static $rBouquets = array();
	public static $rServers = array();
	public static $rSegmentSettings = array();
	public static $rBlockedUA = array();
	public static $rBlockedISP = array();
	public static $rBlockedIPs = array();
	public static $rBlockedServers = array();
	public static $rAllowedIPs = array();
	public static $rProxies = array();
	public static $rAllowedDomains = array();
	public static $rCategories = array();
	public static $rFFMPEG_CPU = null;
	public static $rFFMPEG_GPU = null;
	public static $rFFPROBE = null;
	public static $rCached = null;
	public static function init($rUseCache = false) {
		if (!empty($_GET)) {
			self::cleanGlobals($_GET);
		}
		if (!empty($_POST)) {
			self::cleanGlobals($_POST);
		}
		if (!empty($_SESSION)) {
			self::cleanGlobals($_SESSION);
		}
		if (!empty($_COOKIE)) {
			self::cleanGlobals($_COOKIE);
		}
		$rInput = @self::parseIncomingRecursively($_GET, array());
		self::$rRequest = @self::parseIncomingRecursively($_POST, $rInput);
		self::$rConfig = parse_ini_file(CONFIG_PATH . 'config.ini');
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', intval(self::$rConfig['server_id']));
		}
		if ($rUseCache) {
			self::$rSettings = self::getCache('settings');
		} else {
			self::$rSettings = self::getSettings();
		}
		if (empty(self::$rSettings['default_timezone'])) {
		} else {
			date_default_timezone_set(self::$rSettings['default_timezone']);
		}
		if (self::$rSettings['on_demand_wait_time'] != 0) {
		} else {
			self::$rSettings['on_demand_wait_time'] = 15;
		}
		self::$rSegmentSettings = array('seg_time' => intval(self::$rSettings['seg_time']), 'seg_list_size' => intval(self::$rSettings['seg_list_size']), 'seg_delete_threshold' => intval(self::$rSettings['seg_delete_threshold']));
		switch (self::$rSettings['ffmpeg_cpu']) {
			case '8.0':
				self::$rFFMPEG_CPU = FFMPEG_BIN_80;
				self::$rFFPROBE = FFPROBE_BIN_80;
				self::$rFFMPEG_GPU = FFMPEG_BIN_80;
				break;
			case '7.1':
				self::$rFFMPEG_CPU = FFMPEG_BIN_71;
				self::$rFFPROBE = FFPROBE_BIN_71;
				self::$rFFMPEG_GPU = FFMPEG_BIN_71;
				break;
			case '5.1':
				self::$rFFMPEG_CPU = FFMPEG_BIN_51;
				self::$rFFPROBE = FFPROBE_BIN_51;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.4':
				self::$rFFMPEG_CPU = FFMPEG_BIN_44;
				self::$rFFPROBE = FFPROBE_BIN_44;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			case '4.3':
				self::$rFFMPEG_CPU = FFMPEG_BIN_43;
				self::$rFFPROBE = FFPROBE_BIN_43;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
			default:
				self::$rFFMPEG_CPU = FFMPEG_BIN_40;
				self::$rFFPROBE = FFPROBE_BIN_40;
				self::$rFFMPEG_GPU = FFMPEG_BIN_40;
				break;
		}

		self::$rCached = self::$rSettings['enable_cache'];
		if ($rUseCache) {
			self::$rServers = self::getCache('servers');
			self::$rBouquets = self::getCache('bouquets');
			self::$rBlockedUA = self::getCache('blocked_ua');
			self::$rBlockedISP = self::getCache('blocked_isp');
			self::$rBlockedIPs = self::getCache('blocked_ips');
			self::$rProxies = self::getCache('proxy_servers');
			self::$rBlockedServers = self::getCache('blocked_servers');
			self::$rAllowedDomains = self::getCache('allowed_domains');
			self::$rAllowedIPs = self::getCache('allowed_ips');
			self::$rCategories = self::getCache('categories');
		} else {
			self::$rServers = self::getServers();
			self::$rBouquets = self::getBouquets();
			self::$rBlockedUA = self::getBlockedUA();
			self::$rBlockedISP = self::getBlockedISP();
			self::$rBlockedIPs = self::getBlockedIPs();
			self::$rProxies = self::getProxyIPs();
			self::$rBlockedServers = self::getBlockedServers();
			self::$rAllowedDomains = self::getAllowedDomains();
			self::$rAllowedIPs = self::getAllowedIPs();
			self::$rCategories = self::getCategories();
			self::generateCron();
		}
	}
	// ... (file content unchanged)

	/**
	 * Convert various header formats into FFmpeg-compatible -headers string.
	 *
	 * Accepts:
	 * - Normal header lines: "Key: Value\nKey2: Value2"
	 * - JSON object: {"User-Agent":"...","Referer":"..."}
	 * - Legacy/pseudo-json used by some panels: "Mozilla/5.0 ...","Accept":"*/*",...
	 *   (first bare string is treated as User-Agent)
	 */
	private static function normalizeFfmpegHeadersValue(string $raw): string {
		$v = trim($raw);
		if ($v === '') {
			return "\r\n\r\n";
		}

		// Normalize newlines
		$v = str_replace(["\r\n", "\r"], "\n", $v);

		$trimmed = trim($v);
		$asJson = null;

		// Case 1: JSON object string
		if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
			$decoded = json_decode($trimmed, true);
			if (is_array($decoded)) {
				$asJson = $decoded;
			}
		}

		// Case 2: legacy pseudo-json without braces (your example)
		if ($asJson === null && strpos($trimmed, '"Accept"') !== false && strpos($trimmed, '"Referer"') !== false) {
			// Try to wrap into an object: first element may be a bare UA string
			$tmp = $trimmed;
			// If it starts with a quoted string and then comma and a quoted key, convert first to "User-Agent":"..."
			if (preg_match('/^\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*,\s*"[A-Za-z0-9\-]+"\s*:/', $tmp, $m)) {
				$ua = $m[1];
				$tmp = preg_replace('/^\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*,\s*/', '"User-Agent":"' . $ua . '",', $tmp, 1);
			}
			$decoded = json_decode('{' . $tmp . '}', true);
			if (is_array($decoded)) {
				$asJson = $decoded;
			}
		}

		// If we decoded JSON map -> build header lines
		if (is_array($asJson)) {
			$lines = [];
			foreach ($asJson as $k => $val) {
				$k = trim((string)$k);
				if ($k === '') continue;
				$val = is_scalar($val) ? (string)$val : json_encode($val);
				$val = trim((string)$val);
				if ($val === '') continue;
				$lines[] = $k . ': ' . $val;
			}
			$v = implode("\n", $lines);
		}

		// Now handle common "Header:\nvalue" -> "Header: value"
		$v = preg_replace("/:\s*\n\s*/", ": ", $v);
		// Split " ... Referer:" -> new line
		$v = preg_replace("/\s+([A-Za-z0-9\-]+)\s*:/", "\n$1:", $v);

		// Normalize to CRLF and ensure trailing CRLFCRLF
		$v = str_replace("\n", "\r\n", $v);
		$v = rtrim($v, "\r\n") . "\r\n\r\n";
		return $v;
	}

	public static function getArguments($rArguments, $rProtocol, $rType) {
		$rReturn = array();

		// Dodaj reconnect tylko dla fetch i tylko dla HTTP/HTTPS
		$addReconnect = (
			$rType === 'fetch' &&
			is_string($rProtocol) &&
			(stripos($rProtocol, 'http') !== false)
		);

		if ($addReconnect) {
			$rReturn[] = '-reconnect 1';
			$rReturn[] = '-reconnect_streamed 1';
			$rReturn[] = '-reconnect_delay_max 2';
		}

		if (!empty($rArguments)) {
			foreach ($rArguments as $rArgument) {
				if ($rArgument['argument_cat'] != $rType) {
					continue;
				}

				if (!(is_null($rArgument['argument_wprotocol']) || stristr($rProtocol, $rArgument['argument_wprotocol']) || is_null($rProtocol))) {
					continue;
				}

				if ($rArgument['argument_key'] == 'cookie') {
					$rArgument['value'] = self::fixCookie($rArgument['value']);
				}

				if ($rArgument['argument_key'] == 'headers') {
					$rArgument['value'] = self::normalizeFfmpegHeadersValue((string)$rArgument['value']);
				}

				// follow_redirects jako bool/text -> wymuszamy 1
				if ($rArgument['argument_key'] == 'follow_redirects') {
					$rArgument['value'] = '1';
				}

				if ($rArgument['argument_type'] == 'text') {
					$rReturn[] = sprintf($rArgument['argument_cmd'], $rArgument['value']);
				} else {
					$rReturn[] = $rArgument['argument_cmd'];
				}
			}
		}

		return $rReturn;
	}
}
