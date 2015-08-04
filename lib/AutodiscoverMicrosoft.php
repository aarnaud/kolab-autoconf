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
 * Autodiscover Service class for Microsoft Outlook and Activesync devices
 */
class AutodiscoverMicrosoft extends Autodiscover
{
    const NS            = "http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006";
    const RESPONSE_NS   = "http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a";
    const MOBILESYNC_NS = "http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006";

    private $type = 'outlook';

    /**
     * Handle request parameters (find email address)
     */
    protected function handle_request()
    {
        $post = $_SERVER['REQUEST_METHOD'] == 'POST' ? file_get_contents('php://input') : null;
//$this->email = 'alec@alec.pl';
//$this->type = 'mobilesync';
//return;
        Log::debug('Request [microsoft]: ' . $post);

        if (empty($post)) {
            $this->error("Invalid input");
        }

        // parse XML
        try {
            $xml = new SimpleXMLElement($post);
	    Log::debug(print_r($xml, true));

	foreach($xml->getDocNamespaces() as $strPrefix => $strNamespace) {
	    if(strlen($strPrefix)==0) {
		$strPrefix="a"; //Assign an arbitrary namespace prefix.
	    }
	    $xml->registerXPathNamespace($strPrefix,$strNamespace);
	}



            if ($email = $xml->xpath('//a:EMailAddress')) {
                $this->email = (string) array_shift($email);
            }

            if ($schema = $xml->xpath('//a:AcceptableResponseSchema')) {
                $schema = (string) array_shift($schema);

                if (strpos($schema, 'mobilesync')) {
                    $this->type = 'mobilesync';
                }
            }
        }
        catch (Exception $e) {
            $this->error("Invalid input");
        }
    }

    /**
     * Handle response
     */
    public function handle_response()
    {
        $method = $this->type . '_response';

        $xml = $this->$method();
        $xml->formatOutput = true;

        $response = $xml->saveXML();

        Log::debug('Response [microsoft]: ' . $response);

        header('Content-type: text/xml; charset=' . Autodiscover::CHARSET);
        echo $response;
        exit;
    }

    /**
     * Generates XML response for Activesync
     */
    protected function mobilesync_response()
    {
        if (empty($this->config['activesync'])) {
            $this->error("Activesync not supported");
        }

        if (!preg_match('/^https?:/i', $this->config['activesync'])) {
            $this->config['activesync'] = 'https://' . $this->config['activesync'] . '/Microsoft-Server-ActiveSync';
        }

        $xml = new DOMDocument('1.0', Autodiscover::CHARSET);

        // create main elements (tree)
        $doc = $xml->createElementNS(self::MOBILESYNC_NS, 'Autodiscover');
        $doc = $xml->appendChild($doc);

        $response = $xml->createElement('Response');
        $response = $doc->appendChild($response);

        $user = $xml->createElement('User');
        $user = $response->appendChild($user);

        $action = $xml->createElement('Action');
        $action = $response->appendChild($action);

        $settings = $xml->createElement('Settings');
        $settings = $action->appendChild($settings);

        $server = $xml->createElement('Server');
        $server = $settings->appendChild($server);

        // configuration
/*
        $dispname = $xml->createElement('DisplayName');
        $dispname = $user->appendChild($dispname);
        $dispname->appendChild($xml->createTextNode($this->config['user']));
*/
        $email = $xml->createElement('EMailAddress');
        $email = $user->appendChild($email);
        $email->appendChild($xml->createTextNode($this->config['email']));

        $element = $xml->createElement('Type');
        $element = $server->appendChild($element);
        $element->appendChild($xml->createTextNode('MobileSync'));

        $element = $xml->createElement('Url');
        $element = $server->appendChild($element);
        $element->appendChild($xml->createTextNode($this->config['activesync']));

        $element = $xml->createElement('Name');
        $element = $server->appendChild($element);
        $element->appendChild($xml->createTextNode($this->config['activesync']));

        return $xml;
    }

    /**
     * Generates XML response for Outlook
     */
    protected function outlook_response()
    {
        $xml = new DOMDocument('1.0', Autodiscover::CHARSET);

        // create main elements (tree)
        $doc = $xml->createElementNS(self::NS, 'Autodiscover');
        $doc = $xml->appendChild($doc);

        $response = $xml->createElementNS(self::RESPONSE_NS, 'Response');
        $response = $doc->appendChild($response);

        $user = $xml->createElement('User');
        $user = $response->appendChild($user);

        $account = $xml->createElement('Account');
        $account = $response->appendChild($account);

        $accountType = $xml->createElement('AccountType');
        $accountType = $account->appendChild($accountType);
        $accountType->appendChild($xml->createTextNode('email'));

        $action = $xml->createElement('Action');
        $action = $account->appendChild($action);
        $action->appendChild($xml->createTextNode('settings'));

        // configuration
/*
        $dispname = $xml->createElement('DisplayName');
        $dispname = $user->appendChild($dispname);
        $dispname->appendChild($xml->createTextNode($this->config['user']));
*/
        $email = $xml->createElement('AutoDiscoverSMTPAddress');
        $email = $user->appendChild($email);
        $email->appendChild($xml->createTextNode($this->config['email']));

        // @TODO: Microsoft supports also DAV protocol here
        foreach (array('imap', 'pop3', 'smtp') as $type) {
            if (!empty($this->config[$type])) {
                $protocol = $this->add_protocol_element($xml, $type, $this->config[$type]);
                $account->appendChild($protocol);
            }
        }

        return $xml;
    }

    /**
     * Creates Protocol element for XML response
     */
    private function add_protocol_element($xml, $type, $config)
    {
        $protocol = $xml->createElement('Protocol');

        $element = $xml->createElement('Type');
        $element = $protocol->appendChild($element);
        $element->appendChild($xml->createTextNode(strtoupper($type)));

        // @TODO: TTL/ExpirationDate tags

        // server attributes map
        $server_attributes = array(
            'Server'    => 'hostname',
            'Port'      => 'port',
            'LoginName' => 'username',
        );

        foreach ($server_attributes as $tag_name => $conf_name) {
            $value = $this->config[$type][$conf_name];
            if (!empty($value)) {
                $element = $xml->createElement($tag_name);
                $element->appendChild($xml->createTextNode($value));
                $protocol->appendChild($element);
            }
        }

        $spa     = $this->config[$type]['authentication'] == 'password-encrypted' ? 'on' : 'off';
        $element = $xml->createElement('SPA');
        $element->appendChild($xml->createTextNode($spa));
        $protocol->appendChild($element);

        $map     = array('STARTTLS' => 'TLS', 'SSL' => 'SSL', 'plain' => 'None');
        $element = $xml->createElement('Encryption');
        $element->appendChild($xml->createTextNode($map[$this->config[$type]['socketType']] ?: 'Auto'));
        $protocol->appendChild($element);

        return $protocol;
    }
}
