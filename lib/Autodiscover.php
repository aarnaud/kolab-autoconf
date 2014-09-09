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

/**
 * Main application class
 */
class Autodiscover
{
    const CHARSET = 'UTF-8';

    protected $conf;
    protected $config = array();

    /**
     * Autodiscover main execution path
     */
    public static function run()
    {
        $uris = array($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME']);
        $type = '';

        // Detect request type
        foreach ($uris as $uri) {
            // Outlook/Activesync
            if (stripos($uri, 'autodiscover.xml') !== false) {
                $type = 'Microsoft';
                break;
            }
            // Mozilla Thunderbird (Kmail/Kontact/Evolution)
            else if (strpos($uri, 'config-v1.1.xml') !== false) {
                $type = 'Mozilla';
                break;
            }
        }

        if (!$type) {
            header("HTTP/1.0 404 Not Found");
            exit;
        }

        $class = "Autodiscover$type";

        require_once __DIR__ . '/' . $class . '.php';

        $engine = new $class;
        $engine->handle();

        // fallback to 404
        header("HTTP/1.0 404 Not Found");
        exit;
    }

    /**
     * Initialization of class instance
     */
    public function __construct()
    {
        require_once __DIR__ . '/Conf.php';
        require_once __DIR__ . '/Log.php';

        $this->conf = Conf::get_instance();
    }

    /**
     * Handle request
     */
    public function handle()
    {
        // read request parameters
        $this->handle_request();

        // validate requested email address
        if (empty($this->email)) {
            $this->error("Email address not provided");
        }

        if (!strpos($this->email, '@')) {
            $this->error("Invalid email address");
        }

        // find/set services parameters
        $this->configure();

        // send response
        $this->handle_response();
    }

    /**
     * Send error to the client and exit
     */
    protected function error($msg)
    {
        header("HTTP/1.0 500 $msg");
        exit;
    }

    /**
     * Get services configuration
     */
    protected function configure()
    {
        $pos = strrpos($this->email, '@');

        $this->config = array(
            'email'            => $this->email,
            'domain'           => strtolower(substr($this->email, $pos + 1)),
            'displayName'      => $this->conf->get('autodiscover', 'service_name'),
            'displayShortName' => $this->conf->get('autodiscover', 'service_short'),
        );

        // get user form LDAP, set domain/login/user in $this->config
        $user = $this->get_user($this->email, $this->config['domain']);

        $proto_map = array('tls' => 'STARTTLS', 'ssl' => 'SSL');

        foreach (array('imap', 'pop3', 'smtp') as $type) {
            if ($value = $this->conf->get('autodiscover', $type)) {
                $params = explode(';', $value);

                $pass_secure = in_array($params[1], array('CRAM-MD5', 'DIGEST-MD5'));

                $host = $params[0];
                $host = str_replace('%d', $this->config['domain'], $host);
                $url  = parse_url($host);

                $this->config[$type] = array(
                    'hostname'        => $url['host'],
                    'port'            => $url['port'],
                    'socketType'      => $proto_map[$url['scheme']] ?: 'plain',
                    'username'        => $this->config['login'] ?: $this->config['email'],
                    'authentication'  => 'password-' . ($pass_secure ? 'encrypted' : 'cleartext'),
                );
            }
        }

        if ($host = $this->conf->get('autodiscover', 'activesync')) {
            $host = str_replace('%d', $this->config['domain'], $host);
            $this->config['activesync'] = $host;
        }

        // Log::debug(print_r($this->config, true));
    }

    /**
     * Get user record from LDAP
     */
    protected function get_user($email, $domain)
    {
        // initialize LDAP connection
        $this->ldap();

        // find domain
        if (!($domain = $this->find_domain($domain))) {
            $this->error("Unknown domain");
        }

        // find user
        $user = $this->find_user($email, $domain);

        // update config
        $this->config = array_merge($this->config, (array)$user, array('domain' => $domain));
    }

    /**
     * Initialize LDAP connection
     */
    protected function ldap()
    {
        $uri = parse_url($this->conf->get('ldap_uri'));

        $this->_ldap_server  = $uri['host'];
        $this->_ldap_port    = $uri['port'];
        $this->_ldap_scheme  = $uri['scheme'];
        $this->_ldap_bind_dn = $this->conf->get('ldap', 'service_bind_dn');
        $this->_ldap_bind_pw = $this->conf->get('ldap', 'service_bind_pw');

        // Catch cases in which the ldap server port has not been explicitely defined
        if (!$this->_ldap_port) {
            $this->_ldap_port = $this->_ldap_scheme == 'ldaps' ? 636 : 389;
        }

        require_once 'Net/LDAP3.php';

        $this->ldap = new Net_LDAP3(array(
            'debug'           => in_array(strtolower($this->conf->get('autodiscover', 'debug_mode')), array('trace', 'debug')),
            'log_hook'        => array($this, 'ldap_log'),
            'vlv'             => $this->conf->get('ldap', 'vlv', Conf::AUTO),
            'config_root_dn'  => "cn=config",
            'hosts'           => array($this->_ldap_server),
            'port'            => $this->_ldap_port,
            'use_tls'         => $this->_ldap_scheme == 'tls',
        ));

        $this->_ldap_domain = $this->conf->get('primary_domain');

        // connect to LDAP
        if (!$this->ldap->connect()) {
            $this->error("Storage connection failed");
        }

        // bind as the service user
        if (!$this->ldap->bind($this->_ldap_bind_dn, $this->_ldap_bind_pw)) {
            $this->error("Storage connection failed");
        }
    }

    /**
     * Find domain by name
     */
    private function find_domain($domain)
    {
        $ckey = 'domain::' . $domain;
/*
        // use memcache
        if ($domain = $this->get_cache_data($ckey)) {
            return $domain;
        }
*/
        $domain_base_dn        = $this->conf->get('ldap', 'domain_base_dn');
        $domain_filter         = $this->conf->get('ldap', 'domain_filter');
        $domain_name_attribute = $this->conf->get('ldap', 'domain_name_attribute');

        if (empty($domain_name_attribute)) {
            $domain_name_attribute = 'associateddomain';
        }

        $name_filter   = $domain_name_attribute . "=" . Net_LDAP3::quote_string($domain);
        $domain_filter = "(&" . $domain_filter . "(" . $name_filter . "))";

        if ($result = $this->ldap->search($domain_base_dn, $domain_filter, 'sub', array($domain_name_attribute))) {
            $result = $result->entries(true);

            // root domain
            $domain = current($result);
            $domain = current((array)$domain[$domain_name_attribute]);
/*
            // cache domain DN
            $this->set_cache_data($ckey, $domain);
*/
            return $domain;
        }
    }

    /**
     * Find user in LDAP
     */
    private function find_user($email, $domain)
    {
        $filter = $this->conf->get('login_filter');

        if (empty($filter)) {
            $filter = $this->conf->get('filter');
        }

        if (empty($filter)) {
            $filter = "(&(|(mail=%s)(mail=%U@%d)(alias=%s)(alias=%U@%d)(uid=%s))(objectclass=inetorgperson))";
        }

        $_parts           = explode('@', $email);
        $localpart        = $_parts[0];
        $replace_patterns = array(
            '/%s/' => $email,
            '/%d/' => $domain,
            '/%U/' => $localpart,
            '/%r/' => $domain,
        );
        $attributes = array(
            'login'    => $this->conf->get('autodiscover', 'login_attribute') ?: 'mail',
            'username' => $this->conf->get('autodiscover', 'name_attribute') ?: 'cn',
        );

        $filter  = preg_replace(array_keys($replace_patterns), array_values($replace_patterns), $filter);
        $base_dn = 'dc=' . implode(',dc=', explode('.', $domain));

        $result = $this->ldap->search($base_dn, $filter, 'sub', array_values($attributes));

        if (!$result) {
            Log::debug("Could not search $base_dn with $filter");
            return;
        }

        if ($result->count() > 1) {
            Log::debug("Multiple entries found.");
            return;
        }
        else if ($result->count() < 1) {
            Log::debug("No entries found.");
            return;
        }

        // parse result
        $entries = $result->entries(true);
        $dn      = key($entries);
        $entry   = $entries[$dn];
        $result  = array();

        foreach ($attributes as $idx => $attr) {
            $result[$idx] = is_array($entry[$attr]) ? current($entry[$attr]) : $entry[$attr];
        }

        return $result;
    }

    /**
     * LDAP logging handler
     */
    public function ldap_log($level, $msg)
    {
        if (is_array($msg)) {
            $msg = implode("\n", $msg);
        }

        switch ($level) {
            case LOG_DEBUG:
                Log::debug($str . $msg);
                break;
            case LOG_ERR:
                Log::error($str . $msg);
                break;
            case LOG_INFO:
                Log::info($str . $msg);
                break;
            case LOG_WARNING:
                Log::warning($str . $msg);
                break;
            case LOG_ALERT:
            case LOG_CRIT:
            case LOG_EMERG:
            case LOG_NOTICE:
            default:
                Log::trace($str . $msg);
                break;
        }
    }
}
