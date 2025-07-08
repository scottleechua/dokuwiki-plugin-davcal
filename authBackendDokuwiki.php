<?php

/**
 * DokuWiki SabreDAV Auth Backend
 * 
 * Check a user ID / password combo against DokuWiki's auth system
 */

class DokuWikiSabreAuthBackend extends Sabre\DAV\Auth\Backend\AbstractBasic
{    
    protected function validateUserPass($username, $password)
    {
        global $auth;
        global $conf;
        $ret = $auth->checkPass($username, $password);
        \dokuwiki\Logger::debug('DAVCAL', 'Auth backend initialized', __FILE__, __LINE__);
        \dokuwiki\Logger::debug('DAVCAL', 'checkPass called for username '.$username.' with result '.$ret, __FILE__, __LINE__);
        return $ret;
    }
}
