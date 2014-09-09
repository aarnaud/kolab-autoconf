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

class Conf {
    static private $instance;

    private $_conf = array();

    const CONFIG_FILE = '/etc/kolab/kolab.conf';

    const STRING = 0;
    const BOOL   = 1;
    const INT    = 2;
    const FLOAT  = 3;
    const AUTO   = 4;

    /**
     * This implements the 'singleton' design pattern
     *
     * @return Conf The one and only instance
     */
    static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new Conf();
        }

        return self::$instance;
    }

    public function __construct()
    {
        // Do some magic configuration loading here.
        if (!file_exists(self::CONFIG_FILE)) {
            return;
        }

        $this->read_config();
    }

    public function get($key1, $key2 = null, $type = null)
    {
        $value = $this->expand($this->get_raw($key1, $key2));

        if ($value === null) {
            return $value;
        }

        switch ($type) {
            case self::INT:
                return intval($value);

            case self::FLOAT:
                return floatval($value);

            case self::BOOL:
                return (bool) preg_match('/^(true|1|on|enabled|yes)$/i', $value);

            case self::AUTO:
                return $this->auto($value);
        }

        return (string) $value;
    }

    public function get_list($key1, $key2 = NULL)
    {
        $list  = array();
        $value = $this->get($key1, $key2);
        $value_components = explode(',', $value);

        foreach ($value_components as $component) {
            $component = trim($component);
            if (!empty($component)) {
                $list[] = $component;
            }
        }

        return $list;
    }

    public function get_raw($key1, $key2 = NULL)
    {
        if (isset($this->_conf[$key1])) {
            if ($key2) {
                if (isset($this->_conf[$key1][$key2])) {
                    return $this->_conf[$key1][$key2];
                }
                else if (isset($this->_conf['kolab'][$key2])) {
                    return $this->_conf['kolab'][$key2];
                }
            }
            else {
                return $this->_conf[$key1];
            }
        }

        // If section is specified, but requested setting doesn't exist there
        // we fall back to (global) 'kolab' section
        if ($key2) {
            if (isset($this->_conf['kolab'][$key2])) {
                return $this->_conf['kolab'][$key2];
            }

            return null;
        }

        // Fall back to whatever is the equivalent of auth_mechanism as the
        // section (i.e. 'ldap', or 'sql')
        $auth_mech = $this->_conf['kolab']['auth_mechanism'];
        if (isset($this->_conf[$auth_mech])) {
            if (isset($this->_conf[$auth_mech][$key1])) {
                return $this->_conf[$auth_mech][$key1];
            }
        }

        // Fall back to global settings in the 'kolab' section.
        if (isset($this->_conf['kolab'][$key1])) {
            return $this->_conf['kolab'][$key1];
        }

        return null;
    }

    public function expand($str, $custom = FALSE)
    {
        if (preg_match_all('/%\((?P<variable>\w+)\)s/', $str, $matches)) {
            if (isset($matches['variable']) && !empty($matches['variable'])) {
                if (is_array($matches['variable'])) {
                    foreach ($matches['variable'] as $key => $value) {
                        if (is_array($custom) && array_key_exists($value, $custom)) {
                            $str = str_replace("%(" . $value . ")s", $custom[$value], $str);
                        }

                        $str = str_replace("%(" . $value . ")s", $this->get($value), $str);
                    }

                    return $str;
                }
                else {
                    return str_replace("%(" . $matches['variable'] . ")s", $this->get($matches['variable']), $str);
                }
            }

            return $str;
        }
        else {
            return $str;
        }
    }

    private function read_config()
    {
        $_ini_raw = file(self::CONFIG_FILE);

        $this->_conf = array();

        foreach ($_ini_raw as $_line) {
            if (preg_match('/^\[([a-z0-9-_\.]+)\]/', $_line, $matches)) {
                $_cur_section = $matches[1];
                $this->_conf[$_cur_section] = array();
                unset($_cur_key);
            }

            if (preg_match('/^;/', $_line, $matches)) {
            }

            if (preg_match('/^([a-z0-9\.-_]+)\s*=\s*(.*)/', $_line, $matches)) {
                if (isset($_cur_section) && !empty($_cur_section)) {
                    $_cur_key = $matches[1];
                    $this->_conf[$_cur_section][$matches[1]] = isset($matches[2]) ? $matches[2] : '';
                }
            }

            if (preg_match('/^\s+(.*)$/', $_line, $matches)) {
                if (isset($_cur_key) && !empty($_cur_key)) {
                    $this->_conf[$_cur_section][$_cur_key] .= $matches[1];
                }
            }
        }
    }

    /**
     * Auto-detection and conversion of config value
     */
    private function auto($value)
    {
        if (preg_match('/^(true|on|enabled|yes|false|off|disabled|no)$/i', $value)) {
            return (bool) preg_match('/^(true|on|enabled|yes)$/i', $value);
        }

        if (is_numeric($value)) {
            if (strpos($value, '.')) {
                return floatval($value);
            }
            else {
                return intval($value);
            }
        }

        // array in JSON format
        if ($value[0] == '{' || $value[0] == '[') {
             return json_decode($value, true);
        }

        return (string) $value;
    }
}
