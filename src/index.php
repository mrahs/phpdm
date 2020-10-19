<?php

define('phpDM_BASE_DIR', __DIR__);
include_once('./inc/inc.phpDM_CFG.php');
include_once('./inc/class.phpDM_DAO.php');
include_once('./inc/class.phpDM.php');

new phpDM($phpDM_CFG);