<?php

class phpDM_DAO {
	public function __construct($db, $user = '', $pass = '') {
		$this->db = $db;
		$this->user = $user;
		$this->pass = $pass;
	}

	public function init() {
		$msg = array();
		$rs = null;
		$count = 0;

		// Connect to DB
		try {
			$this->conn = new PDO('sqlite:' . $this->db, $this->user, $this->pass);
		} catch (Exception $e) {
			throw new Exception('Connection failed: ' . $e->getMessage());
		}
		$this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Validate
		try {
			$rs = $this->conn->query($this->get_query('validate_tables'));
		} catch (Exception $e) {
			throw new Exception('DB is invalid: ' . $e->getMessage());
		}

		$count = (int)($rs->fetch(PDO::FETCH_ASSOC)['COUNT']);
		if ($count === 0) {
			// Init
			$this->conn->exec($this->get_query('create_table_file'));
			$this->conn->exec($this->get_query('create_table_down'));
			$this->conn->exec($this->get_query('create_table_errr'));
		} else if ($count < 3) {
			// Invalid
			$rs->closeCursor();
			throw new Exception('DB is invalid: missing tables');
		} else {
			// Validate Columns
		}
		$rs->closeCursor();
	}

	public function get_file_by_name($name) {
		$stmnt = $this->conn->prepare($this->get_query('select_file_by_name'));
		$stmnt->execute(array(':name' => $name));
		$rs = $stmnt->fetchAll();

		return @!$rs ? null : $rs[0];
	}

	public function get_file_by_id($id) {
		$stmnt = $this->conn->prepare($this->get_query('select_file_by_id'));
		$stmnt->execute(array(':id' => $id));
		$rs = $stmnt->fetchAll();

		return @!$rs ? null : $rs[0];
	}

	public function get_download_by_id($id) {
		$stmnt = $this->conn->prepare($this->get_query('select_down_by_id'));
		$stmnt->execute(array(':id' => $id));
		$rs = $stmnt->fetchAll();

		return @!$rs ? null : $rs[0];
	}

	public function add_file($name) {
		if (@!$name) {
			throw new Exception('Add File: Empty file name');
		}

		if (@$this->get_file_by_name($name)) {
			throw new Exception('Add File: File already exists');
		}

		$stmnt = $this->conn->prepare($this->get_query('insert_file_name'));
		if ($stmnt->execute(array(':name' => $name))) {
			return $this->get_file_by_id($this->conn->lastInsertId());
		} else {
			return null;
		}
	}

	public function get_file_bandwidth($id, $current_month = true) {
		if (@!$id) {
			throw new Exception('Get File Bandwidth: Empty file id');
		}

		$stmnt = $this->conn->prepare(
			$current_month 
			? $this->get_query('select_current_month_file_bandwidth_by_id')
			: $this->get_query('select_file_bandwidth_by_id')
		);
		$stmnt->execute(array(':file_id' => $id));
		$rs = $stmnt->fetchAll();
		
		return @!$rs ? 0 : $rs[0]['bandwidth'];
	}

	public function get_bandwidth($current_month = true) {
		$rs = $this->conn->query(
			$current_month  
			? $this->get_query('select_current_month_bandwidth')
			: $this->get_query('select_bandwidth')
		);
		
		return @!$rs ? 0 : $rs->fetch(PDO::FETCH_ASSOC)['bandwidth'];
	}

	public function get_file_ongoing_downs_count($id) {
		if (@!$id) {
			throw new Exception('Get File Ongoing Downs Count: Empty file id');
		}

		$stmnt = $this->conn->prepare($this->get_query('select_file_ongoing_downs_count'));
		$stmnt->execute(array(':file_id' => $id));
		$rs = $stmnt->fetchAll();
		
		return @!$rs ? 0 : $rs[0]['COUNT'];
	}

	public function get_ongoing_downloads_names() {
		$rs = $this->conn->query($this->get_query('select_ongoing_downs_file_names'));
		$names = array();
		foreach ($rs as $row) {
			$names[] = $row['name'];
		}
		$rs->closeCursor();
		return $names;
	}

	public function log_download($data) {
		if (@!$data) {
			throw new Exception('Log download: No data provided');
		}
		if (@!$data['file_id']) {
			throw new Exception('Log download: Empty file id');
		}

		$stmnt = $this->conn->prepare($this->get_query('insert_down'));
		if ($stmnt->execute(array(
			':file_id' 		=> $data['file_id'],
			':completed' 	=> @!$data['completed'] ? 0 : $data['completed'],
			':ts_start' 	=> @!$data['ts_start']  ? microtime(true) : $data['ts_start'],
			':ts_end' 		=> @!$data['ts_end']    ? 0 : $data['ts_end'],
			':bytes' 		=> @!$data['bytes']     ? 0 : $data['bytes'],
			':useragent' 	=> @!$data['useragent'] ? '' : $data['useragent'],
			':ip' 			=> @!$data['ip']        ? '' : $data['ip'],
			':ref' 			=> @!$data['ref']       ? '' : $data['ref'],
			':url' 			=> @!$data['url']       ? '' : $data['url']
			))) {
			return $this->get_download_by_id($this->conn->lastInsertId());
		} else {
			return null;
		}
	}

	public function update_download($data) {
		if (@!$data) {
			throw new Exception('Update download: No data provided');
		}
		if (@!$data['id']) {
			throw new Exception('Update download: Empty id');
		}

		$stmnt = $this->conn->prepare($this->get_query('update_down'));
		$stmnt->execute(array(
			':id' 		 => $data['id'],
			':completed' => @!$data['completed'] ? 0 : $data['completed'],
			':ts_end' 	 => @!$data['ts_end'] 	  ? 0 : $data['ts_end'],
			':bytes' 	 => @!$data['bytes'] 	  ? 0 : $data['bytes'],
			':ts_update' => @!$data['ts_update'] ? microtime(true) : $data['ts_update']
			)
		);
		return $this->get_download_by_id($data['id']);
	}

	public function log_error($data) {
		if (@!$data) {
			throw new Exception('Log error: No data provided');
		}
		if (@!$data['code']) {
			throw new Exception('Log error: Empty error code');
		}
		if (@!$data['url']) {
			throw new Exception('Log error: Empty url');
		}

		$stmnt = $this->conn->prepare($this->get_query('insert_errr'));
		return $stmnt->execute(array(
			':code' 		=> $data['code'],
			':msg' 			=> @!$data['msg'] 		 ? '' : $data['msg'],
			':file_name' 	=> $data['file_name'],
			':useragent' 	=> @!$data['useragent'] ? '' : $data['useragent'],
			':ip' 			=> @!$data['ip'] 		 ? '' : $data['ip'],
			':ref' 			=> @!$data['ref'] 		 ? '' : $data['ref'],
			':url' 			=> $data['url'],
			':ts' 			=> @!$data['ts'] 		 ? microtime(true) : $data['ts']
			));
	}

	private function get_query($query_name) {
		switch ($query_name) {
			case 'validate_tables':
				return 
				"SELECT count(*) COUNT 
				FROM sqlite_master 
				WHERE type = 'table' AND name IN ('file', 'down', 'errr');";
			case 'create_table_file':
				return 
				"CREATE TABLE file (
					id 			INTEGER PRIMARY KEY, 
					name 		TEXT 		NOT NULL UNIQUE, 
					disabled 	INTEGER 	NOT NULL DEFAULT (0), 
					refs 		TEXT 		NOT NULL DEFAULT (''), 
					quota 		INTEGER 	NOT NULL DEFAULT (0), 
					sim 		INTEGER 	NOT NULL DEFAULT (0), 
					ts_add 		REAL 		NOT NULL DEFAULT (strftime('%s','now')), 
					ts_update 	REAL 		NOT NULL DEFAULT (strftime('%s','now')) 
				);";
			case 'create_table_down':
				return 
				"CREATE TABLE down (
					id 			INTEGER PRIMARY KEY, 
					file_id 	INTEGER, 
					completed 	INTEGER 	NOT NULL DEFAULT (0), 
					ts_start 	REAL 		NOT NULL DEFAULT (strftime('%s','now')),  
					ts_end 		REAL 		NOT NULL DEFAULT (0), 
					bytes 		INTEGER 	NOT NULL DEFAULT (0), 
					useragent 	TEXT 		NOT NULL DEFAULT (''), 
					ip 			TEXT 		NOT NULL DEFAULT (''), 
					ref 		TEXT 		NOT NULL DEFAULT (''), 
					url 		TEXT 		NOT NULL DEFAULT (''), 
					ts_update 	REAL 		NOT NULL DEFAULT (strftime('%s','now')), 
					FOREIGN KEY (file_id) REFERENCES file(id)
				);";
			case 'create_table_errr':
				return 
				"CREATE TABLE errr (
					id 			INTEGER PRIMARY KEY, 
					code 		INTEGER NOT NULL, 
					msg 		TEXT 	NOT NULL DEFAULT (''), 
					file_name 	TEXT 	NOT NULL, 
					useragent 	TEXT 	NOT NULL DEFAULT (''), 
					ip 			TEXT 	NOT NULL DEFAULT (''), 
					ref 		TEXT 	NOT NULL DEFAULT (''), 
					url 		TEXT 	NOT NULL, 
					ts 			REAL 	NOT NULL DEFAULT (strftime('%s','now'))
				);";
			case 'select_file_by_name':
				return 'SELECT * FROM file WHERE name = :name';
			case 'select_file_by_id':
				return 'SELECT * FROM file WHERE id = :id';
			case 'insert_file_name':
				return 'INSERT INTO file (name) values(:name)';
			case 'select_file_bandwidth_by_id':
				return 'SELECT SUM(bytes) bandwidth FROM down WHERE file_id = :file_id';
			case 'select_current_month_file_bandwidth_by_id':
				return "SELECT SUM(bytes) bandwidth FROM down WHERE file_id = :file_id AND date(ts_end, 'unixepoch') BETWEEN date('now', 'start of month') AND date('now')";
			case 'select_current_month_bandwidth':
				return "SELECT SUM(bytes) bandwidth FROM down WHERE date(ts_end, 'unixepoch') BETWEEN date('now', 'start of month') AND date('now')";
			case 'select_bandwidth':
				return 'SELECT SUM(bytes) bandwidth FROM down';
			case 'select_file_ongoing_downs_count':
				return 'SELECT COUNT(*) COUNT FROM down WHERE file_id = :file_id AND ts_end = 0';
			case 'select_ongoing_downs_file_names':
				return 'SELECT file.name FROM down INNER JOIN file ON down.file_id = file.id WHERE down.ts_end = 0';
			case 'select_down_by_id':
				return 'SELECT * FROM down WHERE id = :id';
			case 'insert_down':
				return 
				"INSERT INTO down 
				(file_id, completed, ts_start, ts_end, bytes, useragent, ip, ref, url) 
				VALUES (:file_id, :completed, :ts_start, :ts_end, :bytes, :useragent, :ip, :ref, :url)";
			case 'insert_errr':
				return
				"INSERT INTO errr 
				(code, msg, file_name, useragent, ip, ref, url, ts) 
				VALUES (:code, :msg, :file_name, :useragent, :ip, :ref, :url, :ts);";
			case 'update_down':
				return
				"UPDATE down SET 
				completed 	= :completed, 
				ts_end 		= :ts_end, 
				bytes 		= :bytes, 
				ts_update 	= :ts_update 
				WHERE id 	= :id";
			default:
				throw new Exception('Unkown query: ' . $query_name);
		}
	}

	function __destruct() {
		$this->conn = null;
	}

	private $db;
	private $user;
	private $pass;
	private $conn = null;
}