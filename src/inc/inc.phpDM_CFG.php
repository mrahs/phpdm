<?php
if (!defined('phpDM_BASE_DIR')) {
	throw new Exception('Fatal: phpDM base directory not defined');
}

$phpDM_CFG = array(
	'db-name' 				=> phpDM_BASE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'phpDM.db',
	'db-user' 				=> 'php_dm',
	'db-pass'				=> 'k%=.HyER+$r4%/S',
	'files-dir'				=> phpDM_BASE_DIR . DIRECTORY_SEPARATOR . 'files',
	// Disable all downloads
	'disabled'				=> false,
	// Global quota (0 = unlimited)
	'quota'					=> 0,
	// Interval between two download requests for the same user (in seconds)
	'interval'				=> 0,
	// Global speed limit (in bytes)
	'speed'					=> 0,
	// Default referrers
	'refs'					=> array(),
	// Default number of simultaneous downloads per file
	'sim' 					=> 3,
	// Redirection pages for error codes
	'page-400'				=> '',
	'page-403'				=> '',
	'page-404'				=> '',
	'page-500'				=> '',
	'page-503'				=> '',
	// Default redirection page in case no specific page is provided
	'page'					=> '',
	'piwik-url'				=> '',
	'piwik-id-site'			=> '',
	'piwik-id-goal'			=> '',
	'piwik-goal-revenue'	=> 0,
	'debug' 				=> true,
	'log-file' 				=> phpDM_BASE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'phpDM.log'
);