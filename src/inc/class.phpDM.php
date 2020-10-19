<?php
// TODO: OTD
class phpDM {

	public function __construct($phpDM_CFG) {
		if (@!$phpDM_CFG) {
			throw new Exception('Fatal: Configurations not found');
		}

		if (!class_exists('phpDM_DAO')) {
			throw new Exception('Fatal: phpDM_DAO class not found');
		}

		$this->cfg = $phpDM_CFG;
		$this->check_env();
		$this->send_file($this->get_req_file_name());
	}

	private function check_env() {
		$files_dir = $this->cfg['files-dir'];
		$db_file = $this->cfg['db-name'];
		$msg = array();

		if (version_compare(PHP_VERSION, '5.4.0', '<')) {
		    $msg['php'] = 'Unsupported PHP version [' . PHP_VERSION . '].';
		}

		if (!extension_loaded('pdo_sqlite')) {
			$msg['pdo_sqlite'] = 'PDO SQLite extension is not available.';
		}

		if (!is_dir($files_dir)) {
			$msg['files'] = 'Files directory does not exist [' . $files_dir . '].';
		}

		/*if ('640' !== decoct(fileperms($files_dir) & 0777)) {
			$msg['perms'] = 'Files directory has incorrect permissions [' . decoct(fileperms( __DIR__ . '/files') & 0777) . '].';
		}*/

		// DB
		$this->dao = new phpDM_DAO(
			$this->cfg['db-name'],
			$this->cfg['db-user'],
			$this->cfg['db-pass']
		);

		try {
			$this->dao->init();
		} catch (Exception $e) {
			$msg['db'] = $e->getMessage();
		}

		if (@!$msg) {
			return;
		}

		$this->fail('Error Initializing phpDM:<br/>' . join('<br/>', $msg));
	}

	private function get_req_file_name() {
		// Get file name from URL
		$req_file = str_replace(dirname($_SERVER['PHP_SELF']).'/', '', $_SERVER['REQUEST_URI']);
		return $req_file;
	} 

	public function send_file($file_name) {
		$file_name 		= trim(urldecode($file_name));
		$file_path 		= $this->cfg['files-dir'] . DIRECTORY_SEPARATOR . $file_name;
		$file_size 		= @filesize($file_path);

		// No file
		if (@!$file_name) {
			$this->log_miss_redir(400, 'FILE_NAME_MISSING', '');
		}

		// Bad file
		if (preg_match('/\/+/', $file_name)) {
			// found slashes
			$this->log_miss_redir(400, 'FILE_NAME_BAD', $file_name);
		}

		// File not found
		if (!is_file($file_path)) {
			$this->log_miss_redir(404, 'FILE_NOT_FOUND', $file_name);
		}

		// File size
		if ($file_size === false) {
			$this->log_miss_redir(500, 'FILE_SIZE_READ', $file_name);
		}

		// Download disabled
		if ($this->cfg['disabled']) {
			$this->log_miss_redir(503, 'DISABLED_GLOBALLY', $file_name);
		}

		// Check/Add file record
		$file_record = $this->dao->get_file_by_name($file_name);
		if ($file_record === null) {
			// Add
			$file_record = $this->dao->add_file($file_name);
			if ($file_record === null) {
				$this->log_miss_redir(500, 'DB_INSERT_FILE', $file_name);
			}
		}

		// File disabled
		if ($file_record['disabled'] === '1') {
			$this->log_miss_redir(503, 'FILE_DISABLED', $file_name);
		}

		// Bad referrer
		$valid_refs = @!$file_record['refs'] ? $this->cfg['refs'] : preg_split('/\s+/', $file_record['refs']);
		if ($this->is_bad_ref($valid_refs)) {
			$this->log_miss_redir(403, 'REFERRER_NOT_ALLOWED', $file_name);
		}

		// Quota exceeded
		if ($this->is_quota_exceeded($file_record['id'], $file_size, (int)$file_record['quota'])) {
			$this->log_miss_redir(503, 'QUOTA_EXCEEDED', $file_name);
		}

		// TODO: Resume & Accelerate
		// TODO: Throttle (Sim & Interval)
		// TODO: Limit speed

		// Stream file
		$file = @fopen($file_path, 'rb');
		if ($file === false) {
			$this->log_miss_redir(500, 'FILE_OPEN', $file_name);
		}
		$ts_start = microtime(true);
		$bytes = 0;
		$chunkLength = 1024;
		$log_interval = 3;
		ignore_user_abort(true);
		set_time_limit(0);
		header('Content-Description: File Transfer');
	    header('Content-Type: application/octet-stream');
	    header('Content-Transfer-Encoding: binary');
	    header('Content-Disposition: attachment; filename="' . $file_name . '"');
	    header('Expires: 0');
	    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	    header('Pragma: public');
	    header('Content-Length: ' . $file_size);
		ob_clean();
		flush();
		$down_record = $this->dao->log_download(array(
			'file_id' 	=> $file_record['id'],
			'completed' => 0,
			'ts_start' 	=> $ts_start,
			'ts_end' 	=> 0,
			'bytes' 	=> 0,
			'useragent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
			'ip' 		=> $_SERVER['REMOTE_ADDR'],
			'ref' 		=> isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
			'url' 		=> $_SERVER['REQUEST_URI']
			)
		);
		$last_log_ts = 0;
		while(!feof($file)) {
			if (connection_status() !== 0) {
				break;
			}
			$data = fread($file, $chunkLength);
			if ($data === false) {
				break;
			}
			echo $data;
			ob_flush();
			flush();
			$bytes += mb_strlen($data, '8bit');
			if(microtime(true) - $last_log_ts >= 3) {
				$last_log_ts = microtime(true);
				$this->dao->update_download(array(
					'id' 		=> $down_record['id'],
					'completed' => 0,
					'ts_end' 	=> 0,
					'bytes' 	=> $bytes
					)
				);
			}
		}
		fclose($file);
		$ts_end = microtime(true);
		$down_record = $this->dao->update_download(array(
			'id' 		=> $down_record['id'],
			'completed' => $bytes === $file_size,
			'ts_end' 	=> $ts_end,
			'bytes' 	=> $bytes
			)
		);
		exit();
	}

	private function is_bad_ref($valid_refs) {
		$ref_host = null;

		// No refs defined for this file
		if (count($valid_refs) === 0) {
			return false;
		}
		// No ref
		if (!isset($_SERVER['HTTP_REFERER'])) {
			return true;
		}

		$ref_host = parse_url($_SERVER['HTTP_REFERER'])['host'];
		foreach ($valid_refs as $ref) {
			if (stripos($ref, '/') === false) {
				// domain ref
				if ($ref_host !== $ref) {
					return true;
				}
			} else {
				// url ref
				if ($_SERVER['HTTP_REFERER'] !== $ref) {
					return true;
				}
			}
		}

		return false;
	}

	private function is_quota_exceeded($file_id, $file_size, $file_quota) {

		$global_quota = $this->cfg['quota'];

		if ($global_quota === 0 && $file_quota === 0) {
			return false;
		}

		// if we get here, we have global OR file limit

		$file_estimated_bandwidth =
			$this->dao->get_file_bandwidth($file_id) +
			(($this->dao->get_file_ongoing_downs_count($file_id) + 1) * $file_size);

		if ($global_quota === 0) {
			// unlimited global bandwidth, check file limits
			return $file_estimated_bandwidth >= $file_quota;
		}

		$global_estimated_bandwidth = $this->dao->get_bandwidth();
		foreach ($this->dao->get_ongoing_downloads_names() as $down_name) {
			$global_estimated_bandwidth += @filesize($this->cfg['files-dir'] . DIRECTORY_SEPARATOR . $down_name);
		}
		$global_estimated_bandwidth += $file_size;

		if ($file_quota === 0) {
			// unlimited file bandwidth, check global limit
			return $global_estimated_bandwidth >= $global_quota;
		}

		// if we get here, we have both limits

		// if global quota is exceeded, we're done
		if ($global_estimated_bandwidth >= $global_quota) {
			return true;
		}
		
		// global quota is not exceeded, check file quota
		return $file_estimated_bandwidth >= $file_quota;
	}

	private function log_miss_redir($code, $msg, $file_name) {
		$this->dao->log_error(array(
			'code' 		=> $code,
			'msg' 		=> $msg,
			'file_name' => $file_name,
			'useragent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
			'ip' 		=> $_SERVER['REMOTE_ADDR'],
			'ref' 		=> isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
			'url' 		=> $_SERVER['REQUEST_URI'],
			'ts' 		=> microtime(true)
			)
		);
		// Redirect
		$url = $this->cfg['page-'.$code] !== '' ? $this->cfg['page-'.$code] : $this->cfg['page'];
		if (strpos($url, '{msg}') !== false) {
			$url = str_replace('{msg}', $msg, $url);
		}
		if ($url !== '') {
			header('Location: ' . $url, true, 302);
		} else {
			header(' ', true, $code);
		}
		exit();
	}

	private function fail($msg = '') {
		if ($this->cfg['debug']) {
			exit($msg);
		} else {
			$this->log_error($msg);
			header($_SERVER['SERVER_PROTOCOL'].' 500 Something is not right!', true, 500);
			exit();
		}
	}

	private function log_error($msg = '') {
		$msg = date('Y-m-d h:i:s A ') . str_replace('<br/>', ' ', $msg) . PHP_EOL;

		$log = fopen($this->cfg['log-file'], 'at');
		fwrite($log, $msg);
		fclose($log);
	}

	private $cfg;
	private $dao;
}