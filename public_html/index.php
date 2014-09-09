<?php

/**
 +--------------------------------------------------------------------------+
 | Kolab Autodiscover Service                                               |
 |                                                                          |
 | Copyright (C) 2011-2014, Kolab Systems AG <contact@kolabsys.com>         |
 |                                                                          |
 | This program is free software: you can redistribute it and/or modify     |
 | it under the terms of the GNU General Public License as published by     |
 | the Free Software Foundation, either version 3 of the License, or        |
 | (at your option) any later version.                                      |
 |                                                                          |
 | This program is distributed in the hope that it will be useful,          |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the             |
 | GNU General Public License for more details.                             |
 |                                                                          |
 | You should have received a copy of the GNU General Public License        |
 | along with this program. If not, see http://www.gnu.org/licenses/.       |
 +--------------------------------------------------------------------------+
 | Author: Aleksander Machniak <machniak@kolabsys.com>                      |
 +--------------------------------------------------------------------------+
*/

// environment initialization
define('INSTALL_PATH', realpath(dirname(__FILE__) . '/../') . '/');

ini_set('error_reporting', E_ALL &~ E_NOTICE &~ E_STRICT);
ini_set('error_log', INSTALL_PATH . '/logs/errors');

$include_path = INSTALL_PATH . '/lib' . PATH_SEPARATOR;
$include_path .= ini_get('include_path');
if (set_include_path($include_path) === false) {
    die("Fatal error: ini_set/set_include_path does not work.");
}

require_once INSTALL_PATH . '/lib/Autodiscover.php';

// Set internal charset
mb_internal_encoding(Autodiscover::CHARSET);
@mb_regex_encoding(Autodiscover::CHARSET);

Autodiscover::run();
