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
 * Autodiscover Service class for Mozilla Thunderbird
 */
class AutodiscoverMozilla extends Autodiscover
{

    public function handle_request()
    {
        $this->email = $_GET['emailaddress'];

        Log::debug('Request [mozilla]: ' . json_encode($_GET));
    }

    /**
     * Generates XML response
     */
    protected function handle_response()
    {
        $xml = new DOMDocument('1.0', Autodiscover::CHARSET);

        // create main elements
        $doc = $xml->createElement('clientConfig');
        $doc = $xml->appendChild($doc);
        $doc->setAttribute('version', '1.1');

        $provider = $xml->createElement('emailProvider');
        $provider = $doc->appendChild($provider);
        $provider->setAttribute('id', $this->config['domain']);

        // provider description tags
        foreach (array('domain', 'displayName', 'displayShortName') as $tag_name) {
            if (!empty($this->config[$tag_name])) {
                $element = $xml->createElement($tag_name);
                $element->appendChild($xml->createTextNode($this->config[$tag_name]));
                $provider->appendChild($element);
            }
        }

        foreach (array('imap', 'pop3', 'smtp') as $type) {
            if (!empty($this->config[$type])) {
                $server = $this->add_server_element($xml, $type, $this->config[$type]);
                $provider->appendChild($server);
            }
        }

        $xml->formatOutput = true;

        $response = $xml->saveXML();

        Log::debug('Response [mozilla]: ' . $response);

        header('Content-Type: application/xml; charset=' . Autodiscover::CHARSET);
        echo $response;
        exit;
    }

    /**
     * Creates server element for XML response
     */
    private function add_server_element($xml, $type, $config)
    {
        $server = $xml->createElement($type == 'smtp' ? 'outgoingServer' : 'incomingServer');
        $server->setAttribute('type', $type);

        // server attributes
        $server_attributes = array(
            'hostname',
            'port',
            'socketType',     // SSL or STARTTLS or plain
            'username',
            'authentication', // 'password-cleartext', 'password-encrypted'
        );

        foreach ($server_attributes as $tag_name) {
            if (!empty($this->config[$type][$tag_name])) {
                $element = $xml->createElement($tag_name);
                $element->appendChild($xml->createTextNode($this->config[$type][$tag_name]));
                $server->appendChild($element);
            }
        }

        return $server;
    }
}
