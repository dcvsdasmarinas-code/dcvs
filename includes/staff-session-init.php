<?php
/**
 * Unified staff session initialisation.
 * Include at the very top of every staff page BEFORE any output.
 */
session_name('DCVS_STAFF_SESSION');
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params(30 * 24 * 60 * 60, '/', '', false, true);
session_start();