<?php
$strXml= <<<XML
<?xml version="1.0" encoding="utf-8"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/outlook/requestschema/2006"><Request><EMailAddress>test.test@test.net</EMailAddress><AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a</AcceptableResponseSchema></Request></Autodiscover>
XML;

$xmlDoc=new \SimpleXMLElement($strXml);

foreach($xmlDoc->getDocNamespaces() as $strPrefix => $strNamespace) {
    if(strlen($strPrefix)==0) {
        $strPrefix="a"; //Assign an arbitrary namespace prefix.
    }
    $xmlDoc->registerXPathNamespace($strPrefix,$strNamespace);
}

print($xmlDoc->xpath("//a:EMailAddress")[0]); //Use the arbitrary namespace prefix in the query.
?>
