<?php
//
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2003 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 2.02 of the PHP license,      |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | https://www.php.net/license/2_02.txt.                                 |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Author: Damian Alejandro Fernandez Sosa <damlists@cnba.uba.ar>       |
// +----------------------------------------------------------------------+
require_once XHELP_PEAR_PATH . '/Net/Socket.php';

/**
 * Provides an implementation of the IMAP protocol using PEAR's
 * Net_Socket:: class.
 *
 * @author  Damian Alejandro Fernandez Sosa <damlists@cnba.uba.ar>
 */
class Net_IMAPProtocol
{
    /**
     * The auth methods this class support
     * @var array
     */
    public $supportedAuthMethods = ['DIGEST-MD5', 'CRAM-MD5', 'LOGIN'];
    /**
     * The auth methods this class support
     * @var array
     */
    public $supportedSASLAuthMethods = ['DIGEST-MD5', 'CRAM-MD5'];
    /**
     * _serverAuthMethods
     * @var bool
     */
    public $_serverAuthMethods = null;
    /**
     * The the current mailbox
     * @var string
     */
    public $currentMailbox = 'INBOX';
    /**
     * The socket resource being used to connect to the IMAP server.
     * @var resource
     */
    public $_socket = null;
    /**
     * To allow class debuging
     * @var bool
     */
    public $_debug    = false;
    public $dbgDialog = '';
    /**
     * Command Number
     * @var int
     */
    public $_cmd_counter = 1;
    /**
     * Command Number for IMAP commands
     * @var int
     */
    public $_lastCmdID = 1;
    /**
     * Command Number
     * @var bool
     */
    public $_unParsedReturn = false;
    /**
     * _connected: checks if there is a connection made to a imap server or not
     * @var bool
     */
    public $_connected = false;
    /**
     * Capabilities
     * @var bool
     */
    public $_serverSupportedCapabilities = null;
    /**
     * Use UTF-7 funcionallity
     * @var bool
     */
    //var $_useUTF_7 = false;
    public $_useUTF_7 = true;

    /**
     * Constructor
     *
     * Instantiates a new Net_IMAP object.
     *
     * @since  1.0
     */
    public function __construct()
    {
        $this->_socket = new Net_Socket();

        /*
         * Include the Auth_SASL package.  If the package is not available,
         * we disable the authentication methods that depend upon it.
         */

        if (true !== (@require_once __DIR__ . '/Auth/SASL.php')) {
            foreach ($this->supportedSASLAuthMethods as $SASLMethod) {
                $pos = array_search($SASLMethod, $this->supportedAuthMethods, true);
                unset($this->supportedAuthMethods[$pos]);
            }
        }
    }

    /**
     * Attempt to connect to the IMAP server.
     *
     * @param string $host
     * @param int    $port
     * @return bool|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdConnect(string $host = 'localhost', int $port = 143)
    {
        if ($this->_connected) {
            return new PEAR_Error('already connected, logout first!');
        }
        if (PEAR::isError($this->_socket->connect($host, $port))) {
            return new PEAR_Error('unable to open socket');
        }
        if (PEAR::isError($this->_getRawResponse())) {
            return new PEAR_Error('unable to open socket');
        }
        $this->_connected = true;

        return true;
    }

    /**
     * get the cmd ID
     *
     * @return string Returns the CmdID and increment the counter
     *
     * @since  1.0
     */
    public function _getCmdId()
    {
        $this->_lastCmdID = 'A000' . $this->_cmd_counter;
        $this->_cmd_counter++;

        return $this->_lastCmdID;
    }

    /**
     * get the last cmd ID
     *
     * @return int Returns the last cmdId
     *
     * @since  1.0
     */
    public function getLastCmdId(): int
    {
        return $this->_lastCmdID;
    }

    /**
     * get current mailbox name
     *
     * @return string Returns the current mailbox
     *
     * @since  1.0
     */
    public function getCurrentMailbox(): string
    {
        return $this->currentMailbox;
    }

    /**
     * Sets the debuging information on or off
     *
     * @param mixed $debug
     *
     * @since  1.0
     */
    public function setDebug($debug = true): void
    {
        $this->_debug = $debug;
    }

    /**
     * @return string
     */
    public function getDebugDialog(): string
    {
        return $this->dbgDialog;
    }

    /**
     * Send the given string of data to the server.
     *
     * @param string $data The string of data to send.
     *
     * @return bool|\PEAR_Error True on success or a PEAR_Error object on failure.
     *
     * @since   1.0
     */
    public function _send(string $data)
    {
        if ($this->_socket->eof()) {
            return new PEAR_Error('Failed to write to socket: (connection lost!) ');
        }
        if (PEAR::isError($error = $this->_socket->write($data))) {
            return new PEAR_Error('Failed to write to socket: ' . $error->getMessage());
        }

        if ($this->_debug) {
            // C: means this data was sent by  the client (this class)
            echo "C: $data";
            $this->dbgDialog .= "C: $data";
        }

        return true;
    }

    /**
     * Receive the given string of data from the server.
     *
     * @return mixed a line of response on success or a PEAR_Error object on failure.
     *
     * @since   1.0
     */
    public function _recvLn()
    {
        if (PEAR::isError($this->lastline = $this->_socket->gets(8192))) {
            return new PEAR_Error('Failed to write to socket: ' . $this->lastline->getMessage());
        }
        if ($this->_debug) {
            // S: means this data was sent by  the IMAP Server
            echo 'S: ' . $this->lastline . '';
            $this->dbgDialog .= 'S: ' . $this->lastline . '';
        }
        if ('' === $this->lastline) {
            return new PEAR_Error('Failed to receive from the  socket: ');
        }

        return $this->lastline;
    }

    /**
     * Send a command to the server with an optional string of arguments.
     * A carriage return / linefeed (CRLF) sequence will be appended to each
     * command string before it is sent to the IMAP server.
     *
     * @param string $commandId The IMAP cmdID to send to the server.
     * @param string $command   The IMAP command to send to the server.
     * @param string $args      A string of optional arguments to append
     *                          to the command.
     *
     * @return bool|\PEAR_Error The result of the _send() call.
     *
     * @since   1.0
     */
    public function _putCMD(string $commandId, string $command, string $args = '')
    {
        if (!empty($args)) {
            return $this->_send($commandId . ' ' . $command . ' ' . $args . "\r\n");
        }

        return $this->_send($commandId . ' ' . $command . "\r\n");
    }

    /**
     * Get a response from the server with an optional string of commandID.
     * A carriage return / linefeed (CRLF) sequence will be appended to each
     * command string before it is sent to the IMAP server.
     *
     * @param string $commandId
     * @return string The result response.
     */
    public function _getRawResponse(string $commandId = '*'): string
    {
        $arguments = '';
        while (!PEAR::isError($this->_recvLn())) {
            $reply_code = strtok($this->lastline, ' ');
            $arguments  .= $this->lastline;
            if (!strcmp($commandId, $reply_code)) {
                return $arguments;
            }
        }

        return $arguments;
    }

    /**
     * get the "returning of the unparsed response" feature status
     *
     * @return bool return if the unparsed response is returned or not
     *
     * @since  1.0
     */
    public function getUnparsedResponse(): bool
    {
        return $this->_unParsedReturn;
    }

    /**
     * set the "returning of the unparsed response" feature on or off
     *
     * @param bool $status : true: feature is on
     *
     * @since  1.0
     */
    public function setUnparsedResponse(bool $status): void
    {
        $this->_unParsedReturn = $status;
    }

    /**
     * Attempt to login to the iMAP server.
     *
     * @param mixed $uid
     * @param mixed $pwd
     *
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdLogin($uid, $pwd)
    {
        $param = "\"$uid\" \"$pwd\"";

        return $this->_genericCommand('LOGIN', $param);
    }

    /**
     * Attempt to authenticate to the iMAP server.
     * @param mixed      $uid
     * @param mixed      $pwd
     * @param null|mixed $userMethod
     *
     * @return array|\PEAR_Error Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdAuthenticate($uid, $pwd, $userMethod = null)
    {
        if (!$this->_connected) {
            return new PEAR_Error('not connected!');
        }

        $cmdid = $this->_getCmdId();

        if (PEAR::isError($method = $this->_getBestAuthMethod($userMethod))) {
            return $method;
        }

        switch ($method) {
            case 'DIGEST-MD5':
                $result = $this->_authDigest_MD5($uid, $pwd, $cmdid);
                break;
            case 'CRAM-MD5':
                $result = $this->_authCRAM_MD5($uid, $pwd, $cmdid);
                break;
            case 'LOGIN':
                $result = $this->_authLOGIN($uid, $pwd, $cmdid);
                break;
            default:
                $result = new PEAR_Error("$method is not a supported authentication method");
                break;
        }

        $args = $this->_getRawResponse($cmdid);

        return $this->_genericImapResponseParser($args, $cmdid);
    }

    /* Authenticates the user using the DIGEST-MD5 method.
     *
     * @param string $uid The userid to authenticate as.
     * @param string $pwd The password to authenticate with.
     * @param string $cmdid The cmdID.
     *
     * @return array Returns an array containing the response
     *
     * @access private
     * @since  1.0
     */

    /**
     * @param int $uid
     * @param string $pwd
     * @param int $cmdid
     * @return bool|mixed|\PEAR_Error
     */
    public function _authDigest_MD5(int $uid, string $pwd, int $cmdid)
    {
        if (PEAR::isError($error = $this->_putCMD($cmdid, 'AUTHENTICATE', 'DIGEST-MD5'))) {
            return $error;
        }

        if (PEAR::isError($args = $this->_recvLn())) {
            return $args;
        }

        $this->_getNextToken($args, $plus);

        $this->_getNextToken($args, $space);

        $this->_getNextToken($args, $challenge);

        $challenge = base64_decode($challenge, true);

        $digest = &Auth_SASL::factory('digestmd5');

        $auth_str = base64_encode($digest->getResponse($uid, $pwd, $challenge, 'localhost', 'imap'));

        if (PEAR::isError($error = $this->_send("$auth_str\r\n"))) {
            return $error;
        }

        if (PEAR::isError($args = $this->_recvLn())) {
            return $args;
        }
        /*
         * We don't use the protocol's third step because IMAP doesn't allow
         * subsequent authentication, so we just silently ignore it.
         */
        if (PEAR::isError($error = $this->_send("\r\n"))) {
            return $error;
        }
        return true;
    }

    /* Authenticates the user using the CRAM-MD5 method.
     *
     * @param string $uid The userid to authenticate as.
     * @param string $pwd The password to authenticate with.
     * @param string $cmdid The cmdID.
     *
     * @return array Returns an array containing the response
     *
     * @access private
     * @since  1.0
     */

    /**
     * @param int $uid
     * @param string $pwd
     * @param int $cmdid
     * @return bool|mixed|\PEAR_Error
     */
    public function _authCRAM_MD5(int $uid, string $pwd, int $cmdid)
    {
        if (PEAR::isError($error = $this->_putCMD($cmdid, 'AUTHENTICATE', 'CRAM-MD5'))) {
            return $error;
        }

        if (PEAR::isError($args = $this->_recvLn())) {
            return $args;
        }

        $this->_getNextToken($args, $plus);

        $this->_getNextToken($args, $space);

        $this->_getNextToken($args, $challenge);

        $challenge = base64_decode($challenge, true);

        $cram = &Auth_SASL::factory('crammd5');

        $auth_str = base64_encode($cram->getResponse($uid, $pwd, $challenge));

        if (PEAR::isError($error = $this->_send($auth_str . "\r\n"))) {
            return $error;
        }

        return true;
    }

    /* Authenticates the user using the LOGIN method.
     *
     * @param string $uid The userid to authenticate as.
     * @param string $pwd The password to authenticate with.
     * @param string $cmdid The cmdID.
     *
     * @return array Returns an array containing the response
     *
     * @access private
     * @since  1.0
     */

    /**
     * @param int $uid
     * @param string $pwd
     * @param int $cmdid
     * @return bool|mixed|\PEAR_Error
     */
    public function _authLOGIN(int $uid, string $pwd, int $cmdid)
    {
        if (PEAR::isError($error = $this->_putCMD($cmdid, 'AUTHENTICATE', 'LOGIN'))) {
            return $error;
        }

        if (PEAR::isError($args = $this->_recvLn())) {
            return $args;
        }

        $this->_getNextToken($args, $plus);

        $this->_getNextToken($args, $space);

        $this->_getNextToken($args, $challenge);

        $challenge = base64_decode($challenge, true);

        $auth_str = base64_encode((string)$uid);

        if (PEAR::isError($error = $this->_send($auth_str . "\r\n"))) {
            return $error;
        }

        if (PEAR::isError($args = $this->_recvLn())) {
            return $args;
        }

        $auth_str = base64_encode((string)$pwd);

        if (PEAR::isError($error = $this->_send($auth_str . "\r\n"))) {
            return $error;
        }

        return true;
    }

    /**
     * Returns the name of the best authentication method that the server
     * has advertised.
     *
     * @param string|null $userMethod
     * @return mixed Returns a string containing the name of the best
     *               supported authentication method or a PEAR_Error object
     *               if a failure condition is encountered.
     * @since  1.0
     */
    public function _getBestAuthMethod(string $userMethod = null)
    {
        $this->cmdCapability();

        if (null !== $userMethod) {
            $methods = [];

            $methods[] = $userMethod;
        } else {
            $methods = $this->supportedAuthMethods;
        }

        if ((null !== $methods) && (null !== $this->_serverAuthMethods)) {
            foreach ($methods as $method) {
                if (in_array($method, $this->_serverAuthMethods, true)) {
                    return $method;
                }
            }
            $serverMethods = implode(',', $this->_serverAuthMethods);
            $myMethods     = implode(',', $this->supportedAuthMethods);

            return new PEAR_Error("$method NOT supported authentication method!. This IMAP server " . "supports these methods: $serverMethods, but I support $myMethods");
        }

        return new PEAR_Error("This IMAP server don't support any Auth methods");
    }

    /**
     * Attempt to disconnect from the iMAP server.
     *
     * @return array|string Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdLogout()
    {
        if (!$this->_connected) {
            return new PEAR_Error('not connected!');
        }

        $cmdid = $this->_getCmdId();
        if (PEAR::isError($error = $this->_putCMD($cmdid, 'LOGOUT'))) {
            return $error;
        }
        if (PEAR::isError($args = $this->_getRawResponse())) {
            return $args;
        }
        if (PEAR::isError($this->_socket->disconnect())) {
            return new PEAR_Error('socket disconnect failed');
        }

        return $args;
        // not for now
        //return $this->_genericImapResponseParser($args,$cmdid);
    }

    /**
     * Send the NOOP command.
     *
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdNoop()
    {
        return $this->_genericCommand('NOOP');
    }

    /**
     * Send the CHECK command.
     *
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdCheck()
    {
        return $this->_genericCommand('CHECK');
    }

    /**
     * Send the  Select Mailbox Command
     *
     * @param mixed $mailbox
     *
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdSelect($mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));
        if (!PEAR::isError($ret = $this->_genericCommand('SELECT', $mailbox_name))) {
            $this->currentMailbox = $mailbox;
        }

        return $ret;
    }

    /**
     * Send the  EXAMINE  Mailbox Command
     *
     * @param mixed $mailbox
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdExamine($mailbox): array
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));
        $ret          = $this->_genericCommand('EXAMINE', $mailbox_name);
        $parsed       = '';
        if (isset($ret['PARSED'])) {
            foreach ($ret['PARSED'] as $i => $iValue) {
                $command               = $ret['PARSED'][$i]['EXT'];
                $parsed[key($command)] = $command[key($command)];
            }
        }

        return ['PARSED' => $parsed, 'RESPONSE' => $ret['RESPONSE']];
    }

    /**
     * Send the  CREATE Mailbox Command
     *
     * @param mixed $mailbox
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdCreate($mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));

        return $this->_genericCommand('CREATE', $mailbox_name);
    }

    /**
     * Send the  RENAME Mailbox Command
     *
     * @param mixed $mailbox
     * @param mixed $new_mailbox
     *
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdRename($mailbox, $new_mailbox)
    {
        $mailbox_name     = sprintf('"%s"', $this->utf_7_encode($mailbox));
        $new_mailbox_name = sprintf('"%s"', $this->utf_7_encode($new_mailbox));

        return $this->_genericCommand('RENAME', "$mailbox_name $new_mailbox_name");
    }

    /**
     * Send the  DELETE Mailbox Command
     *
     * @param mixed $mailbox
     *
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdDelete($mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));

        return $this->_genericCommand('DELETE', $mailbox_name);
    }

    /**
     * Send the  SUSCRIBE  Mailbox Command
     *
     * @param mixed $mailbox
     *
     * @return array Returns an array containing the response
     *
     * @since  1.0
     */
    public function cmdSubscribe($mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));

        return $this->_genericCommand('SUBSCRIBE', $mailbox_name);
    }

    /**
     * Send the  UNSUSCRIBE  Mailbox Command
     *
     * @param string $mailbox
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdUnsubscribe(string $mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));

        return $this->_genericCommand('UNSUBSCRIBE', $mailbox_name);
    }

    /**
     * Send the  FETCH Command
     *
     * @param mixed $msgset
     * @param mixed $fetchparam
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdFetch($msgset, $fetchparam)
    {
        return $this->_genericCommand('FETCH', "$msgset $fetchparam");
    }

    /**
     * Send the  CAPABILITY Command
     *
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdCapability()
    {
        $ret = $this->_genericCommand('CAPABILITY');

        if (isset($ret['PARSED'])) {
            $ret['PARSED'] = $ret['PARSED'][0]['EXT']['CAPABILITY'];
            //fill the $this->_serverAuthMethods and $this->_serverSupportedCapabilities arrays
            foreach ($ret['PARSED']['CAPABILITIES'] as $auth_method) {
                if (0 === mb_stripos($auth_method, 'AUTH=')) {
                    $this->_serverAuthMethods[] = mb_substr($auth_method, 5);
                }
            }
            // Keep the capabilities response to use ir later
            $this->_serverSupportedCapabilities = $ret['PARSED']['CAPABILITIES'];
        }

        return $ret;
    }

    /**
     * Send the  STATUS Mailbox Command
     *
     * @param string $mailbox  the mailbox name
     * @param string $request  the request status it could be:
     *                         MESSAGES | RECENT | UIDNEXT
     *                         UIDVALIDITY | UNSEEN
     * @return array  Returns a Parsed Response
     *
     * @since  1.0
     */
    public function cmdStatus(string $mailbox, string $request)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));

        if ('MESSAGES' !== $request && 'RECENT' !== $request && 'UIDNEXT' !== $request
            && 'UIDVALIDITY' !== $request
            && 'UNSEEN' !== $request) {
            // TODO:  fix this error!
            $this->_prot_error("request '$request' is invalid! see RFC2060!!!!", __LINE__, __FILE__, false);
        }
        $ret = $this->_genericCommand('STATUS', "$mailbox_name ($request)");
        if (isset($ret['PARSED'])) {
            $ret['PARSED'] = $ret['PARSED'][count($ret['PARSED']) - 1]['EXT'];
        }

        return $ret;
    }

    /**
     * Send the  LIST  Command
     *
     * @param string $mailbox_base
     * @param string $mailbox
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdList(string $mailbox_base, string $mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));
        $mailbox_base = sprintf('"%s"', $this->utf_7_encode($mailbox_base));

        return $this->_genericCommand('LIST', "$mailbox_base $mailbox_name");
    }

    /**
     * Send the  LSUB  Command
     *
     * @param string $mailbox_base
     * @param string $mailbox
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdLsub(string $mailbox_base, string $mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));
        $mailbox_base = sprintf('"%s"', $this->utf_7_encode($mailbox_base));

        return $this->_genericCommand('LSUB', "$mailbox_base $mailbox_name");
    }

    /**
     * Send the  APPEND  Command
     *
     * @param string $mailbox
     * @param string $msg
     * @param string $flags_list
     * @param string $time
     * @return mixed Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdAppend(string $mailbox, string $msg, string $flags_list = '', string $time = '')
    {
        if (!$this->_connected) {
            return new PEAR_Error('not connected!');
        }

        $cmdid    = $this->_getCmdId();
        $msg_size = mb_strlen($msg);

        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));
        // TODO:
        // Falta el codigo para que flags list y time hagan algo!!
        if (true === $this->hasCapability('LITERAL+')) {
            $param = sprintf("%s %s%s{%s+}\r\n%s", $mailbox_name, $flags_list, $time, $msg_size, $msg);
            if (PEAR::isError($error = $this->_putCMD($cmdid, 'APPEND', $param))) {
                return $error;
            }
        } else {
            $param = sprintf("%s %s%s{%s}\r\n", $mailbox_name, $flags_list, $time, $msg_size);
            if (PEAR::isError($error = $this->_putCMD($cmdid, 'APPEND', $param))) {
                return $error;
            }
            if (PEAR::isError($error = $this->_recvLn())) {
                return $error;
            }

            if (PEAR::isError($error = $this->_send($msg))) {
                return $error;
            }
        }

        $args = $this->_getRawResponse($cmdid);
        $ret  = $this->_genericImapResponseParser($args, $cmdid);

        return $ret;
    }

    /**
     * Send the CLOSE command.
     *
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdClose()
    {
        return $this->_genericCommand('CLOSE');
    }

    /**
     * Send the EXPUNGE command.
     *
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdExpunge()
    {
        $ret = $this->_genericCommand('EXPUNGE');

        if (isset($ret['PARSED'])) {
            $parsed = $ret['PARSED'];
            unset($ret['PARSED']);
            foreach ($parsed as $command) {
                if ('EXPUNGE' === \mb_strtoupper($command['COMMAND'])) {
                    $ret['PARSED'][$command['COMMAND']][] = $command['NRO'];
                } else {
                    $ret['PARSED'][$command['COMMAND']] = $command['NRO'];
                }
            }
        }

        return $ret;
    }

    /**
     * Send the SEARCH command.
     *
     * @param string $search_cmd
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdSearch($search_cmd)
    {
        /*        if($_charset != '' )
         $_charset = "[$_charset] ";
         $param=sprintf("%s%s",$charset,$search_cmd);
         */
        $ret = $this->_genericCommand('SEARCH', $search_cmd);
        if (isset($ret['PARSED'])) {
            $ret['PARSED'] = $ret['PARSED'][0]['EXT'];
        }

        return $ret;
    }

    /**
     * Send the STORE command.
     *
     * @param string $message_set  the sessage_set
     * @param string $dataitem     :   the way we store the flags
     *                             FLAGS: replace the flags whith $value
     *                             FLAGS.SILENT: replace the flags whith $value but don't return untagged responses
     *
     *          +FLAGS: Add the flags whith $value
     *          +FLAGS.SILENT: Add the flags whith $value but don't return untagged responses
     *
     *          -FLAGS: Remove the flags whith $value
     *          -FLAGS.SILENT: Remove the flags whith $value but don't return untagged responses
     *
     * @param string $value
     * @return array|\PEAR_Error  Returns a PEAR_Error with an error message on any
     *                             kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdStore(string $message_set, string $dataitem, string $value)
    {
        /* As said in RFC2060...
         C: A003 STORE 2:4 +FLAGS (\Deleted)
         S: * 2 FETCH FLAGS (\Deleted \Seen)
         S: * 3 FETCH FLAGS (\Deleted)
         S: * 4 FETCH FLAGS (\Deleted \Flagged \Seen)
         S: A003 OK STORE completed
         */
        if ('FLAGS' !== $dataitem && 'FLAGS.SILENT' !== $dataitem && '+FLAGS' !== $dataitem
            && '+FLAGS.SILENT' !== $dataitem
            && '-FLAGS' !== $dataitem
            && '-FLAGS.SILENT' !== $dataitem) {
            $this->_prot_error("dataitem '$dataitem' is invalid! see RFC2060!!!!", __LINE__, __FILE__);
        }
        $param = sprintf('%s %s (%s)', $message_set, $dataitem, $value);

        return $this->_genericCommand('STORE', $param);
    }

    /**
     * Send the COPY command.
     *
     * @param string $message_set
     * @param string $mailbox
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdCopy(string $message_set, string $mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));

        return $this->_genericCommand('COPY', sprintf('%s %s', $message_set, $mailbox_name));
    }

    /**
     * @param string $msgset
     * @param string $fetchparam
     * @return array|\PEAR_Error
     */
    public function cmdUidFetch($msgset, $fetchparam)
    {
        return $this->_genericCommand('UID FETCH', sprintf('%s %s', $msgset, $fetchparam));
    }

    /**
     * @param string $message_set
     * @param string $mailbox
     * @return array|\PEAR_Error
     */
    public function cmdUidCopy(string $message_set, string $mailbox)
    {
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox));

        return $this->_genericCommand('UID COPY', sprintf('%s %s', $message_set, $mailbox_name));
    }

    /**
     * Send the UID STORE command.
     *
     * @param string $message_set  the sessage_set
     * @param string $dataitem     :   the way we store the flags
     *                             FLAGS: replace the flags whith $value
     *                             FLAGS.SILENT: replace the flags whith $value but don't return untagged responses
     *
     *          +FLAGS: Add the flags whith $value
     *          +FLAGS.SILENT: Add the flags whith $value but don't return untagged responses
     *
     *          -FLAGS: Remove the flags whith $value
     *          -FLAGS.SILENT: Remove the flags whith $value but don't return untagged responses
     *
     * @param string $value
     * @return array|\PEAR_Error  Returns a PEAR_Error with an error message on any
     *                             kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdUidStore(string $message_set, string $dataitem, string $value)
    {
        /* As said in RFC2060...
         C: A003 STORE 2:4 +FLAGS (\Deleted)
         S: * 2 FETCH FLAGS (\Deleted \Seen)
         S: * 3 FETCH FLAGS (\Deleted)
         S: * 4 FETCH FLAGS (\Deleted \Flagged \Seen)
         S: A003 OK STORE completed
         */
        if ('FLAGS' !== $dataitem && 'FLAGS.SILENT' !== $dataitem && '+FLAGS' !== $dataitem
            && '+FLAGS.SILENT' !== $dataitem
            && '-FLAGS' !== $dataitem
            && '-FLAGS.SILENT' !== $dataitem) {
            $this->_prot_error("dataitem '$dataitem' is invalid! see RFC2060!!!!", __LINE__, __FILE__);
        }

        return $this->_genericCommand('UID STORE', sprintf('%s %s (%s)', $message_set, $dataitem, $value));
    }

    /**
     * Send the SEARCH command.
     *
     * @param string $search_cmd
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdUidSearch(string $search_cmd)
    {
        $ret = $this->_genericCommand('UID SEARCH', sprintf('%s', $search_cmd));
        if (isset($ret['PARSED'])) {
            $ret['PARSED'] = $ret['PARSED'][0]['EXT'];
        }

        return $ret;
    }

    /**
     * Send the X command.
     *
     * @param string $atom
     * @param string $parameters
     * @return array|\PEAR_Error Returns a PEAR_Error with an error message on any
     *               kind of failure, or true on success.
     * @since  1.0
     */
    public function cmdX($atom, $parameters)
    {
        return $this->_genericCommand("X$atom", $parameters);
    }

    /********************************************************************
     ***
     ***             HERE ENDS the RFC2060 IMAPS FUNCTIONS
     ***             AND BEGIN THE EXTENSIONS FUNCTIONS
     ***
     ********************************************************************/

    /********************************************************************
     ***             RFC2087 IMAP4 QUOTA extension BEGINS HERE
     ********************************************************************/

    /**
     * Send the GETQUOTA command.
     *
     * @param string $mailbox_name  the mailbox name to query for quota data
     * @return array|\PEAR_Error  Returns a PEAR_Error with an error message on any
     *                              kind of failure, or quota data on success
     * @since  1.0
     */
    public function cmdGetQuota(string $mailbox_name)
    {
        //Check if the IMAP server has QUOTA support
        if (!$this->hasQuotaSupport()) {
            return new PEAR_Error("This IMAP server does not support QUOTA's! ");
        }
        $mailbox_name = sprintf('%s', $this->utf_7_encode($mailbox_name));
        $ret          = $this->_genericCommand('GETQUOTA', $mailbox_name);
        if (isset($ret['PARSED'])) {
            // remove the array index because the quota response returns only 1 line of output
            $ret['PARSED'] = $ret['PARSED'][0];
        }

        return $ret;
    }

    /**
     * Send the GETQUOTAROOT command.
     *
     * @param string $mailbox_name  the mailbox name to query for quota data
     * @return array|\PEAR_Error  Returns a PEAR_Error with an error message on any
     *                              kind of failure, or quota data on success
     * @since  1.0
     */
    public function cmdGetQuotaRoot(string $mailbox_name)
    {
        //Check if the IMAP server has QUOTA support
        if (!$this->hasQuotaSupport()) {
            return new PEAR_Error("This IMAP server does not support QUOTA's! ");
        }
        $mailbox_name = sprintf('%s', $this->utf_7_encode($mailbox_name));
        $ret          = $this->_genericCommand('GETQUOTAROOT', $mailbox_name);

        if (isset($ret['PARSED'])) {
            // remove the array index because the quota response returns only 1 line of output
            $ret['PARSED'] = $ret['PARSED'][0];
        }

        return $ret;
    }

    /**
     * Send the SETQUOTA command.
     *
     * @param string   $mailbox_name  the mailbox name to query for quota data
     * @param int|null $storageQuota  sets the max number of bytes this mailbox can handle
     * @param int|null $messagesQuota sets the max number of messages this mailbox can handle
     * @return array|\PEAR_Error  Returns a PEAR_Error with an error message on any
     *                                kind of failure, or quota data on success
     * @since  1.0
     */
    // TODO:  implement the quota by number of emails!!
    public function cmdSetQuota(string $mailbox_name, int $storageQuota = null, int $messagesQuota = null)
    {
        //Check if the IMAP server has QUOTA support
        if (!$this->hasQuotaSupport()) {
            return new PEAR_Error("This IMAP server does not support QUOTA's! ");
        }

        if ((null === $messagesQuota) && (null === $storageQuota)) {
            return new PEAR_Error('$storageQuota and $messagesQuota parameters can\'t be both null if you want to use quota');
        }
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox_name));
        //Make the command request
        $param = sprintf('%s (', $mailbox_name);
        if (null !== $storageQuota) {
            $param = sprintf('%sSTORAGE %s', $param, $storageQuota);
            if (null !== $messagesQuota) {
                //if we have both types of quota on the same call we must append an space between
                // those parameters
                $param = sprintf('%s ', $param);
            }
        }
        if (null !== $messagesQuota) {
            $param = sprintf('%sMESSAGES %s', $param, $messagesQuota);
        }
        $param = sprintf('%s)', $param);

        return $this->_genericCommand('SETQUOTA', $param);
    }

    /**
     * Send the SETQUOTAROOT command.
     *
     * @param string $mailbox_name   the mailbox name to query for quota data
     * @param null   $storageQuota   sets the max number of bytes this mailbox can handle
     * @param null   $messagesQuota  sets the max number of messages this mailbox can handle
     * @return array|\PEAR_Error  Returns a PEAR_Error with an error message on any
     *                               kind of failure, or quota data on success
     * @since  1.0
     */
    public function cmdSetQuotaRoot(string $mailbox_name, $storageQuota = null, $messagesQuota = null)
    {
        //Check if the IMAP server has QUOTA support
        if (!$this->hasQuotaSupport()) {
            return new PEAR_Error("This IMAP server does not support QUOTA's! ");
        }

        if ((null === $messagesQuota) && (null === $storageQuota)) {
            return new PEAR_Error('$storageQuota and $messagesQuota parameters can\'t be both null if you want to use quota');
        }
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox_name));
        //Make the command request
        $param = sprintf('%s (', $mailbox_name);
        if (null !== $storageQuota) {
            $param = sprintf('%sSTORAGE %s', $param, $storageQuota);
            if (null !== $messagesQuota) {
                //if we have both types of quota on the same call we must append an space between
                // those parameters
                $param = sprintf('%s ', $param);
            }
        }
        if (null !== $messagesQuota) {
            $param = sprintf('%sMESSAGES %s', $param, $messagesQuota);
        }
        $param = sprintf('%s)', $param);

        return $this->_genericCommand('SETQUOTAROOT', $param);
    }

    /********************************************************************
     ***             RFC2087 IMAP4 QUOTA extension ENDS HERE
     ********************************************************************/

    /********************************************************************
     ***             RFC2086 IMAP4 ACL extension BEGINS HERE
     *******************************************************************
     * @param string       $mailbox_name
     * @param string       $user
     * @param array|string $acl
     * @return array|\PEAR_Error
     */

    public function cmdSetACL(string $mailbox_name, string $user, $acl)
    {
        //Check if the IMAP server has ACL support
        if (!$this->hasAclSupport()) {
            return new PEAR_Error("This IMAP server does not support ACL's! ");
        }
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox_name));
        $user_name    = sprintf('"%s"', $this->utf_7_encode($user));
        if (is_array($acl)) {
            $acl = implode('', $acl);
        }

        return $this->_genericCommand('SETACL', sprintf('%s %s "%s"', $mailbox_name, $user_name, $acl));
    }

    /**
     * @param string $mailbox_name
     * @param string $user
     * @return array|\PEAR_Error
     */
    public function cmdDeleteACL(string $mailbox_name, string $user)
    {
        //Check if the IMAP server has ACL support
        if (!$this->hasAclSupport()) {
            return new PEAR_Error("This IMAP server does not support ACL's! ");
        }
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox_name));

        return $this->_genericCommand('DELETEACL', sprintf('%s "%s"', $mailbox_name, $user));
    }

    /**
     * @param string $mailbox_name
     * @return array|\PEAR_Error
     */
    public function cmdGetACL(string $mailbox_name)
    {
        //Check if the IMAP server has ACL support
        if (!$this->hasAclSupport()) {
            return new PEAR_Error("This IMAP server does not support ACL's! ");
        }
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox_name));
        $ret          = $this->_genericCommand('GETACL', sprintf('%s', $mailbox_name));
        if (isset($ret['PARSED'])) {
            $ret['PARSED'] = $ret['PARSED'][0]['EXT'];
        }

        return $ret;
    }

    /**
     * @param string $mailbox_name
     * @param string $user
     * @return array|\PEAR_Error
     */
    public function cmdListRights(string $mailbox_name, string $user)
    {
        //Check if the IMAP server has ACL support
        if (!$this->hasAclSupport()) {
            return new PEAR_Error("This IMAP server does not support ACL's! ");
        }
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox_name));
        $ret          = $this->_genericCommand('LISTRIGHTS', sprintf('%s "%s"', $mailbox_name, $user));
        if (isset($ret['PARSED'])) {
            $ret['PARSED'] = $ret['PARSED'][0]['EXT'];
        }

        return $ret;
    }

    /**
     * @param string $mailbox_name
     * @return array|\PEAR_Error
     */
    public function cmdMyRights(string $mailbox_name)
    {
        //Check if the IMAP server has ACL support
        if (!$this->hasAclSupport()) {
            return new PEAR_Error("This IMAP server does not support ACL's! ");
        }
        $mailbox_name = sprintf('"%s"', $this->utf_7_encode($mailbox_name));
        $ret          = $this->_genericCommand('MYRIGHTS', sprintf('%s', $mailbox_name));
        if (isset($ret['PARSED'])) {
            $ret['PARSED'] = $ret['PARSED'][0]['EXT'];
        }

        return $ret;
    }

    /********************************************************************
     ***             RFC2086 IMAP4 ACL extension ENDs HERE
     ********************************************************************/

    /*******************************************************************************
     ***  draft-daboo-imap-annotatemore-05 IMAP4 ANNOTATEMORE extension BEGINS HERE
     *******************************************************************************
     * @param string $mailbox_name
     * @param string $entry
     * @param array  $values
     * @return array|\PEAR_Error
     */

    public function cmdSetAnnotation(string $mailbox_name, string $entry, array $values)
    {
        // Check if the IMAP server has ANNOTATEMORE support
        if (!$this->hasAnnotateMoreSupport()) {
            return new PEAR_Error('This IMAP server does not support the ANNOTATEMORE extension!');
        }
        if (!is_array($values)) {
            return new PEAR_Error('Invalid $values argument passed to cmdSetAnnotation');
        }

        $vallist = '';
        foreach ($values as $name => $value) {
            $vallist .= "\"$name\" \"$value\" ";
        }
        $vallist = rtrim($vallist);

        return $this->_genericCommand('SETANNOTATION', sprintf('"%s" "%s" (%s)', $mailbox_name, $entry, $vallist));
    }

    /**
     * @param string $mailbox_name
     * @param string $entry
     * @param array  $values
     * @return array|\PEAR_Error
     */
    public function cmdDeleteAnnotation(string $mailbox_name, string $entry, array $values)
    {
        // Check if the IMAP server has ANNOTATEMORE support
        if (!$this->hasAnnotateMoreSupport()) {
            return new PEAR_Error('This IMAP server does not support the ANNOTATEMORE extension!');
        }
        if (!is_array($values)) {
            return new PEAR_Error('Invalid $values argument passed to cmdDeleteAnnotation');
        }

        $vallist = '';
        foreach ($values as $name) {
            $vallist .= "\"$name\" NIL ";
        }
        $vallist = rtrim($vallist);

        return $this->_genericCommand('SETANNOTATION', sprintf('"%s" "%s" (%s)', $mailbox_name, $entry, $vallist));
    }

    /**
     * @param string $mailbox_name
     * @param array  $entries
     * @param array  $values
     * @return array|\PEAR_Error
     */
    public function cmdGetAnnotation(string $mailbox_name, array $entries, array $values)
    {
        // Check if the IMAP server has ANNOTATEMORE support
        if (!$this->hasAnnotateMoreSupport()) {
            return new PEAR_Error('This IMAP server does not support the ANNOTATEMORE extension!');
        }

        $entlist = '';

        if (!is_array($entries)) {
            $entries = [$entries];
        }

        foreach ($entries as $name) {
            $entlist .= "\"$name\" ";
        }
        $entlist = rtrim($entlist);
        if (count($entries) > 1) {
            $entlist = "($entlist)";
        }

        $vallist = '';
        if (!is_array($values)) {
            $values = [$values];
        }

        foreach ($values as $name) {
            $vallist .= "\"$name\" ";
        }
        $vallist = rtrim($vallist);
        if (count($values) > 1) {
            $vallist = "($vallist)";
        }

        return $this->_genericCommand('GETANNOTATION', sprintf('"%s" %s %s', $mailbox_name, $entlist, $vallist));
    }

    /*****************************************************************************
     ***  draft-daboo-imap-annotatemore-05 IMAP4 ANNOTATEMORE extension ENDs HERE
     ******************************************************************************/

    /********************************************************************
     ***
     ***             HERE ENDS THE EXTENSIONS FUNCTIONS
     ***             AND BEGIN THE AUXILIARY FUNCTIONS
     ***
     ********************************************************************/

    /**
     * tell if the server has capability $capability
     *
     * @return true or false
     *
     * @since  1.0
     */
    public function getServerAuthMethods(): ?bool
    {
        if (null === $this->_serverAuthMethods) {
            $this->cmdCapability();

            return $this->_serverAuthMethods;
        }

        return false;
    }

    /**
     * tell if the server has capability $capability
     *
     * @param string $capability
     * @return true or false
     *
     * @since  1.0
     */
    public function hasCapability(string $capability): bool
    {
        if (null === $this->_serverSupportedCapabilities) {
            $this->cmdCapability();
        }
        if (null !== $this->_serverSupportedCapabilities) {
            if (in_array($capability, $this->_serverSupportedCapabilities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * tell if the server has Quota support
     *
     * @return true or false
     *
     * @since  1.0
     */
    public function hasQuotaSupport(): bool
    {
        return $this->hasCapability('QUOTA');
    }

    /**
     * tell if the server has Quota support
     *
     * @return true or false
     *
     * @since  1.0
     */
    public function hasAclSupport(): bool
    {
        return $this->hasCapability('ACL');
    }

    /**
     * tell if the server has support for the ANNOTATEMORE extension
     *
     * @return true or false
     *
     * @since  1.0
     */
    public function hasAnnotateMoreSupport(): bool
    {
        return $this->hasCapability('ANNOTATEMORE');
    }

    /**
     * Parses the responses like RFC822.SIZE and INTERNALDATE
     *
     * @param string $str
     * @param string $line
     * @param string $file
     * @return string containing  the parsed response
     * @since  1.0
     */
    public function _parseOneStringResponse(&$str, $line, $file): string
    {
        $this->_parseSpace($str, $line, $file);
        $size = $this->_getNextToken($str, $uid);

        return $uid;
    }

    /**
     * Parses the FLAG response
     *
     * @param mixed $str
     *
     * @return array containing  the parsed  response
     * @since  1.0
     */
    public function _parseFLAGSresponse(&$str): array
    {
        $this->_parseSpace($str, __LINE__, __FILE__);
        $params_arr[] = $this->_arrayfy_content($str);
        $flags_arr    = [];
        foreach ($params_arr[0] as $iValue) {
            $flags_arr[] = $iValue;
        }

        return $flags_arr;
    }

    /**
     * Parses the BODY response
     *
     * @param string $str
     * @param string $command
     * @return array containing  the parsed  response
     * @since  1.0
     */
    public function _parseBodyResponse(&$str, $command): array
    {
        $this->_parseSpace($str, __LINE__, __FILE__);
        while (')' !== $str[0] && '' != $str) {
            $params_arr[] = $this->_arrayfy_content($str);
        }

        return $params_arr;
    }

    /**
     * Makes the content an Array
     *
     * @param mixed $str
     *
     * @return array containing  the parsed  response
     * @since  1.0
     */
    public function _arrayfy_content(&$str): array
    {
        $params_arr = [];
        $this->_getNextToken($str, $params);
        if ('(' !== $params) {
            return $params;
        }
        $this->_getNextToken($str, $params, false, false);
        while ('' != $str && ')' !== $params) {
            if ('' != $params) {
                if ('(' === $params[0]) {
                    $params = $this->_arrayfy_content($params);
                }
                if (' ' !== $params) {
                    //I don't remove the colons (") to handle the case of retriving " "
                    // If I remove the colons the parser will interpret this field as an imap separator (space)
                    // instead of a valid field so I remove the colons here
                    if ('""' === $params) {
                        $params = '';
                    } else {
                        if ('"' === $params[0]) {
                            $params = mb_substr($params, 1, -2);
                        }
                    }
                    $params_arr[] = $params;
                }
            } else {
                //if params if empty (for example i'm parsing 2 quotes ("")
                // I'll append an array entry to mantain compatibility
                $params_arr[] = $params;
            }
            $this->_getNextToken($str, $params, false, false);
        }

        return $params_arr;
    }

    /**
     * Parses the BODY[],BODY[TEXT],.... responses
     *
     * @param string $str
     * @param string $command
     * @return array containing  the parsed  response
     * @since  1.0
     */
    public function _parseContentresponse(&$str, $command): array
    {
        $content = '';
        $this->_parseSpace($str, __LINE__, __FILE__);
        $size = $this->_getNextToken($str, $content);

        return ['CONTENT' => $content, 'CONTENT_SIZE' => $size];
    }

    /**
     * Parses the ENVELOPE response
     *
     * @param mixed $str
     *
     * @return array containing  the parsed  response
     * @since  1.0
     */
    public function _parseENVELOPEresponse(&$str): array
    {
        $content = '';
        $this->_parseSpace($str, __LINE__, __FILE__);

        $this->_getNextToken($str, $parenthesis);
        if ('(' !== $parenthesis) {
            $this->_prot_error("must be a '(' but is a '$parenthesis' !!!!", __LINE__, __FILE__);
        }
        // Get the email's Date
        $this->_getNextToken($str, $date);

        $this->_parseSpace($str, __LINE__, __FILE__);

        // Get the email's Subject:
        $this->_getNextToken($str, $subject);
        //$subject=$this->decode($subject);

        $this->_parseSpace($str, __LINE__, __FILE__);

        //FROM LIST;
        $from_arr = $this->_getAddressList($str);

        $this->_parseSpace($str, __LINE__, __FILE__);

        //"SENDER LIST\n";
        $sender_arr = $this->_getAddressList($str);

        $this->_parseSpace($str, __LINE__, __FILE__);

        //"REPLY-TO LIST\n";
        $reply_to_arr = $this->_getAddressList($str);

        $this->_parseSpace($str, __LINE__, __FILE__);

        //"TO LIST\n";
        $to_arr = $this->_getAddressList($str);

        $this->_parseSpace($str, __LINE__, __FILE__);

        //"CC LIST\n";
        $cc_arr = $this->_getAddressList($str);

        $this->_parseSpace($str, __LINE__, __FILE__);

        //"BCC LIST|$str|\n";
        $bcc_arr = $this->_getAddressList($str);

        $this->_parseSpace($str, __LINE__, __FILE__);

        $this->_getNextToken($str, $in_reply_to);

        $this->_parseSpace($str, __LINE__, __FILE__);

        $this->_getNextToken($str, $message_id);

        $this->_getNextToken($str, $parenthesis);

        if (')' !== $parenthesis) {
            $this->_prot_error("must be a ')' but is a '$parenthesis' !!!!", __LINE__, __FILE__);
        }

        return [
            'DATE'        => $date,
            'SUBJECT'     => $subject,
            'FROM'        => $from_arr,
            'SENDER'      => $sender_arr,
            'REPLY_TO'    => $reply_to_arr,
            'TO'          => $to_arr,
            'CC'          => $cc_arr,
            'BCC'         => $bcc_arr,
            'IN_REPLY_TO' => $in_reply_to,
            'MESSAGE_ID'  => $message_id,
        ];
    }

    /**
     * Parses the ARRDLIST as defined in RFC
     *
     * @param mixed $str
     *
     * @return array containing  the parsed  response
     * @since  1.0
     */
    public function _getAddressList(&$str): array
    {
        $params_arr = $this->_arrayfy_content($str);
        if (!isset($params_arr)) {
            return $params_arr;
        }

        if (is_array($params_arr)) {
            $personal_name  = $params_arr[0][0];
            $at_domain_list = $params_arr[0][1];
            $mailbox_name   = $params_arr[0][2];
            $host_name      = $params_arr[0][3];
            if ('' != $mailbox_name && '' != $host_name) {
                $email = $mailbox_name . '@' . $host_name;
            } else {
                $email = false;
            }
            if (false !== $email) {
                if (isset($personal_name)) {
                    $rfc822_email = '"' . $personal_name . '" <' . $email . '>';
                } else {
                    $rfc822_email = '<' . $email . '>';
                }
            } else {
                $rfc822_email = false;
            }
            $email_arr[] = [
                'PERSONAL_NAME'  => $personal_name,
                'AT_DOMAIN_LIST' => $at_domain_list,
                'MAILBOX_NAME'   => $this->utf_7_decode($mailbox_name),
                'HOST_NAME'      => $host_name,
                'EMAIL'          => $email,
                'RFC822_EMAIL'   => $rfc822_email,
            ];

            return $email_arr;
        }

        return [];
    }

    /**
     * Utility funcion to find the closing parenthesis ")" Position it takes care of quoted ones
     *
     * @param        $str_line
     * @param string $startDelim
     * @param string $stopDelim
     * @return int containing  the pos of the closing parenthesis ")"
     * @since  1.0
     */
    public function _getClosingBracesPos($str_line, string $startDelim = '(', string $stopDelim = ')')
    {
        $len = mb_strlen($str_line);
        $pos = 0;
        // ignore all extra characters
        // If inside of a string, skip string -- Boundary IDs and other
        // things can have ) in them.
        if ($str_line[$pos] != $startDelim) {
            $this->_prot_error("_getClosingParenthesisPos: must start with a '(' but is a '" . $str_line[$pos] . "'!!!!\n" . "STR_LINE:$str_line|size:$len|POS: $pos\n", __LINE__, __FILE__);

            return $len;
        }
        for ($pos = 1; $pos < $len; $pos++) {
            if ($str_line[$pos] == $stopDelim) {
                break;
            }
            if ('"' === $str_line[$pos]) {
                $pos++;
                while ('"' !== $str_line[$pos] && $pos < $len) {
                    if ('\\' === $str_line[$pos] && '"' === $str_line[$pos + 1]) {
                        $pos++;
                    }
                    if ('\\' === $str_line[$pos] && '\\' === $str_line[$pos + 1]) {
                        $pos++;
                    }
                    $pos++;
                }
            }
            if ($str_line[$pos] == $startDelim) {
                $str_line_aux = mb_substr($str_line, $pos);
                $pos_aux      = $this->_getClosingBracesPos($str_line_aux);
                $pos          += $pos_aux;
            }
        }
        if ($str_line[$pos] != $stopDelim) {
            $this->_prot_error("_getClosingBracesPos: must be a $stopDelim but is a '" . $str_line[$pos] . "'|POS:$pos|STR_LINE:$str_line!!!!", __LINE__, __FILE__);
        }

        if ($pos >= $len) {
            return false;
        }

        return $pos;
    }

    /**
     * Utility funcion to get from here to the end of the line
     *
     * @param mixed $str
     * @param bool  $including
     * @return string containing  the string to the end of the line
     * @since  1.0
     */
    public function _getToEOL(&$str, bool $including = true): string
    {
        $len = mb_strlen($str);
        if ($including) {
            for ($i = 0; $i < $len; ++$i) {
                if ("\n" === $str[$i]) {
                    break;
                }
            }
            $content = mb_substr($str, 0, $i + 1);
            $str     = mb_substr($str, $i + 1);

            return $content;
        }
        for ($i = 0; $i < $len; ++$i) {
            if ("\n" === $str[$i] || "\r" === $str[$i]) {
                break;
            }
        }
        $content = mb_substr($str, 0, $i);
        $str     = mb_substr($str, $i);

        return $content;
    }

    /**
     * Fetches the next IMAP token or parenthesis
     *
     * @param bool  $parenthesisIsToken
     * @param bool  $colonIsToken
     * @param mixed $str
     * @param mixed $content
     * @return int containing  the content size
     * @since  1.0
     */
    public function _getNextToken(&$str, &$content, bool $parenthesisIsToken = true, bool $colonIsToken = true)
    {
        $len          = mb_strlen($str);
        $pos          = 0;
        $content_size = false;
        $content      = false;
        if ('' === $str || $len < 2) {
            $content = $str;

            return $len;
        }
        switch ($str[0]) {
            case '{':
                if (false === ($posClosingBraces = $this->_getClosingBracesPos($str, '{', '}'))) {
                    $this->_prot_error('_getClosingBracesPos() error!!!', __LINE__, __FILE__);
                }
                if (!is_numeric($strBytes = mb_substr($str, 1, $posClosingBraces - 1))) {
                    $this->_prot_error("must be a number but is a '" . $strBytes . "'!!!!", __LINE__, __FILE__);
                }
                if ('}' !== $str[$posClosingBraces]) {
                    $this->_prot_error("must be a '}'  but is a '" . $str[$posClosingBraces] . "'!!!!", __LINE__, __FILE__);
                }
                if ("\r" !== $str[$posClosingBraces + 1]) {
                    $this->_prot_error("must be a '\\r'  but is a '" . $str[$posClosingBraces + 1] . "'!!!!", __LINE__, __FILE__);
                }
                if ("\n" !== $str[$posClosingBraces + 2]) {
                    $this->_prot_error("must be a '\\n'  but is a '" . $str[$posClosingBraces + 2] . "'!!!!", __LINE__, __FILE__);
                }
                $content = mb_substr($str, $posClosingBraces + 3, $strBytes);
                if (mb_strlen($content) != $strBytes) {
                    $this->_prot_error('content size is ' . mb_strlen($content) . " but the string reports a size of $strBytes!!!\n", __LINE__, __FILE__);
                }
                $content_size = $strBytes;
                //Advance the string
                $str = mb_substr($str, $posClosingBraces + $strBytes + 3);
                break;
            case '"':
                if ($colonIsToken) {
                    for ($pos = 1; $pos < $len; $pos++) {
                        if ('"' === $str[$pos]) {
                            break;
                        }
                        if ('\\' === $str[$pos] && '"' === $str[$pos + 1]) {
                            $pos++;
                        }
                        if ('\\' === $str[$pos] && '\\' === $str[$pos + 1]) {
                            $pos++;
                        }
                    }
                    if ('"' !== $str[$pos]) {
                        $this->_prot_error("must be a '\"'  but is a '" . $str[$pos] . "'!!!!", __LINE__, __FILE__);
                    }
                    $content_size = $pos;
                    $content      = mb_substr($str, 1, $pos - 1);
                    //Advance the string
                    $str = mb_substr($str, $pos + 1);
                } else {
                    for ($pos = 1; $pos < $len; $pos++) {
                        if ('"' === $str[$pos]) {
                            break;
                        }
                        if ('\\' === $str[$pos] && '"' === $str[$pos + 1]) {
                            $pos++;
                        }
                        if ('\\' === $str[$pos] && '\\' === $str[$pos + 1]) {
                            $pos++;
                        }
                    }
                    if ('"' !== $str[$pos]) {
                        $this->_prot_error("must be a '\"'  but is a '" . $str[$pos] . "'!!!!", __LINE__, __FILE__);
                    }
                    $content_size = $pos;
                    $content      = mb_substr($str, 0, $pos + 1);
                    //Advance the string
                    $str = mb_substr($str, $pos + 1);
                }
                break;
            case "\r":
                $pos = 1;
                if ("\n" === $str[1]) {
                    $pos++;
                }
                $content_size = $pos;
                $content      = mb_substr($str, 0, $pos);
                $str          = mb_substr($str, $pos);
                break;
            case "\n":
                $pos          = 1;
                $content_size = $pos;
                $content      = mb_substr($str, 0, $pos);
                $str          = mb_substr($str, $pos);
                break;
            case '(':
                if ($parenthesisIsToken) {
                    $pos          = 1;
                    $content_size = $pos;
                    $content      = mb_substr($str, 0, $pos);
                    $str          = mb_substr($str, $pos);
                } else {
                    $pos          = $this->_getClosingBracesPos($str);
                    $content_size = $pos + 1;
                    $content      = mb_substr($str, 0, $pos + 1);
                    $str          = mb_substr($str, $pos + 1);
                }
                break;
            case ')':
                $pos          = 1;
                $content_size = $pos;
                $content      = mb_substr($str, 0, $pos);
                $str          = mb_substr($str, $pos);
                break;
            case ' ':
                $pos          = 1;
                $content_size = $pos;
                $content      = mb_substr($str, 0, $pos);
                $str          = mb_substr($str, $pos);
                break;
            default:
                for ($pos = 0; $pos < $len; $pos++) {
                    if (' ' === $str[$pos] || "\r" === $str[$pos] || ')' === $str[$pos] || '(' === $str[$pos] || "\n" === $str[$pos]) {
                        break;
                    }
                    if ('\\' === $str[$pos] && ' ' === $str[$pos + 1]) {
                        $pos++;
                    }
                    if ('\\' === $str[$pos] && '\\' === $str[$pos + 1]) {
                        $pos++;
                    }
                }
                //Advance the string
                if (0 == $pos) {
                    $content_size = 1;
                    $content      = mb_substr($str, 0, 1);
                    $str          = mb_substr($str, 1);
                } else {
                    $content_size = $pos;
                    $content      = mb_substr($str, 0, $pos);
                    if ($pos < $len) {
                        $str = mb_substr($str, $pos);
                    } else {
                        //if this is the end of the string... exit the switch
                        break;
                    }
                }
                break;
        }

        return $content_size;
    }

    /**
     * Utility funcion to display to console the protocol errors
     *
     * @param      $str
     * @param      $line
     * @param      $file
     * @param bool $printError
     * @return string containing  the error
     * @since  1.0
     */
    public function _prot_error($str, $line, $file, bool $printError = true): string
    {
        if ($printError) {
            echo "$line,$file,PROTOCOL ERROR!:$str\n";
        }

        return '';
    }

    /**
     * @param string $startDelim
     * @param string $stopDelim
     * @param mixed  $str
     * @return array
     */
    public function _getEXTarray(&$str, string $startDelim = '(', string $stopDelim = ')'): array
    {
        /* I let choose the $startDelim  and $stopDelim to allow parsing
         the OK response  so I also can parse a response like this
         * OK [UIDNEXT 150] Predicted next UID
         */
        $this->_getNextToken($str, $parenthesis);
        if ($parenthesis != $startDelim) {
            $this->_prot_error("must be a '$startDelim' but is a '$parenthesis' !!!!", __LINE__, __FILE__);
        }
        $parenthesis = '';
        $struct_arr  = [];
        while ($parenthesis != $stopDelim && '' != $str) {
            // The command
            $this->_getNextToken($str, $token);
            $token = \mb_strtoupper($token);

            if (false !== ($ret = $this->_retrParsedResponse($str, $token))) {
                //$struct_arr[$token] = $ret;
                $struct_arr = array_merge($struct_arr, $ret);
            }

            $parenthesis = $token;
        }//While

        if ($parenthesis != $stopDelim) {
            $this->_prot_error("1_must be a '$stopDelim' but is a '$parenthesis' !!!!", __LINE__, __FILE__);
        }

        return $struct_arr;
    }

    /**
     * @param       $token
     * @param null  $previousToken
     * @param mixed $str
     * @return array|array[]|false|null[]|string|string[]|\string[][]
     */
    public function _retrParsedResponse(&$str, $token, $previousToken = null)
    {
        //echo "\n\nTOKEN:$token\r\n";
        switch ($token) {
            case 'RFC822.SIZE':
                return [$token => $this->_parseOneStringResponse($str, __LINE__, __FILE__)];
            //        case "RFC822.TEXT" :

            //        case "RFC822.HEADER" :

            case 'RFC822':
                return [$token => $this->_parseContentresponse($str, $token)];
            case 'FLAGS':

            case 'PERMANENTFLAGS':
                return [$token => $this->_parseFLAGSresponse($str)];
            case 'ENVELOPE':
                return [$token => $this->_parseENVELOPEresponse($str)];
            case 'EXPUNGE':
                return false;
            case 'UID':

            case 'UIDNEXT':

            case 'UIDVALIDITY':

            case 'UNSEEN':

            case 'MESSAGES':

            case 'UIDNEXT':

            case 'UIDVALIDITY':

            case 'UNSEEN':

            case 'INTERNALDATE':
                return [$token => $this->_parseOneStringResponse($str, __LINE__, __FILE__)];
            case 'BODY':

            case 'BODYSTRUCTURE':
                return [$token => $this->_parseBodyResponse($str, $token)];
            case 'RECENT':
                if (null !== $previousToken) {
                    $aux['RECENT'] = $previousToken;

                    return $aux;
                }

                return [$token => $this->_parseOneStringResponse($str, __LINE__, __FILE__)];
            case 'EXISTS':
                return [$token => $previousToken];
            case 'READ-WRITE':

            case 'READ-ONLY':
                return [$token => $token];
            case 'QUOTA':
                /*
                 A tipical GETQUOTA DIALOG IS AS FOLLOWS

                 C: A0004 GETQUOTA user.damian
                 S: * QUOTA user.damian (STORAGE 1781460 4000000)
                 S: A0004 OK Completed
                 */

                $mailbox = $this->_parseOneStringResponse($str, __LINE__, __FILE__);
                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_parseString($str, '(', __LINE__, __FILE__);

                $ret_aux = ['MAILBOX' => $this->utf_7_decode($mailbox)];
                $this->_getNextToken($str, $quota_resp);
                if (false === ($ext = $this->_retrParsedResponse($str, $quota_resp))) {
                    $this->_prot_error('bogus response!!!!', __LINE__, __FILE__);
                }
                $ret_aux = array_merge($ret_aux, $ext);

                $this->_getNextToken($str, $separator);
                if (')' === $separator) {
                    return [$token => $ret_aux];
                }

                $this->_parseSpace($str, __LINE__, __FILE__);

                $this->_getNextToken($str, $quota_resp);
                if (false === ($ext = $this->_retrParsedResponse($str, $quota_resp))) {
                    $this->_prot_error('bogus response!!!!', __LINE__, __FILE__);
                }
                $ret_aux = array_merge($ret_aux, $ext);

                $this->_parseString($str, ')', __LINE__, __FILE__);

                return [$token => $ret_aux];
            case 'QUOTAROOT':
                /*
                 A tipical GETQUOTA DIALOG IS AS FOLLOWS

                 C: A0004 GETQUOTA user.damian
                 S: * QUOTA user.damian (STORAGE 1781460 4000000)
                 S: A0004 OK Completed
                 */ $mailbox = $this->utf_7_decode($this->_parseOneStringResponse($str, __LINE__, __FILE__));

                $str_line = rtrim(mb_substr($this->_getToEOL($str, false), 0));

                $quotaroot = $this->_parseOneStringResponse($str_line, __LINE__, __FILE__);
                $ret       = @['MAILBOX' => $this->utf_7_decode($mailbox), $token => $quotaroot];

                return [$token => $ret];
            case 'STORAGE':
                $used = $this->_parseOneStringResponse($str, __LINE__, __FILE__);
                $qmax = $this->_parseOneStringResponse($str, __LINE__, __FILE__);

                return [$token => ['USED' => $used, 'QMAX' => $qmax]];
            case 'MESSAGE':
                $mused = $this->_parseOneStringResponse($str, __LINE__, __FILE__);
                $mmax  = $this->_parseOneStringResponse($str, __LINE__, __FILE__);

                return [$token => ['MUSED' => $mused, 'MMAX' => $mmax]];
            case 'FETCH':
                $this->_parseSpace($str, __LINE__, __FILE__);
                // Get the parsed pathenthesis
                $struct_arr = $this->_getEXTarray($str);

                return $struct_arr;
            case 'CAPABILITY':
                $this->_parseSpace($str, __LINE__, __FILE__);
                $str_line                   = rtrim(mb_substr($this->_getToEOL($str, false), 0));
                $struct_arr['CAPABILITIES'] = explode(' ', $str_line);

                return [$token => $struct_arr];
            case 'STATUS':
                $mailbox = $this->_parseOneStringResponse($str, __LINE__, __FILE__);
                $this->_parseSpace($str, __LINE__, __FILE__);
                $ext                      = $this->_getEXTarray($str);
                $struct_arr['MAILBOX']    = $this->utf_7_decode($mailbox);
                $struct_arr['ATTRIBUTES'] = $ext;

                return [$token => $struct_arr];
            case 'LIST':
                $this->_parseSpace($str, __LINE__, __FILE__);
                $params_arr = $this->_arrayfy_content($str);

                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $hierarchydelim);

                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $mailbox_name);

                $result_array = ['NAME_ATTRIBUTES' => $params_arr, 'HIERACHY_DELIMITER' => $hierarchydelim, 'MAILBOX_NAME' => $this->utf_7_decode($mailbox_name)];

                return [$token => $result_array];
            case 'LSUB':
                $this->_parseSpace($str, __LINE__, __FILE__);
                $params_arr = $this->_arrayfy_content($str);

                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $hierarchydelim);

                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $mailbox_name);

                $result_array = ['NAME_ATTRIBUTES' => $params_arr, 'HIERACHY_DELIMITER' => $hierarchydelim, 'MAILBOX_NAME' => $this->utf_7_decode($mailbox_name)];

                return [$token => $result_array];
            case 'SEARCH':
                $str_line                  = rtrim(mb_substr($this->_getToEOL($str, false), 1));
                $struct_arr['SEARCH_LIST'] = explode(' ', $str_line);
                if (1 == count($struct_arr['SEARCH_LIST']) && '' == $struct_arr['SEARCH_LIST'][0]) {
                    $struct_arr['SEARCH_LIST'] = null;
                }

                return [$token => $struct_arr];
            case 'OK':
                /* TODO:
                 parse the [ .... ] part of the response, use the method
                 _getEXTarray(&$str,'[',$stopDelim=']')

                 */ $str_line = rtrim(mb_substr($this->_getToEOL($str, false), 1));
                if ('[' === $str_line[0]) {
                    $braceLen = $this->_getClosingBracesPos($str_line, '[', ']');
                    $str_aux  = '(' . mb_substr($str_line, 1, $braceLen - 1) . ')';
                    $ext_arr  = $this->_getEXTarray($str_aux);
                    //$ext_arr=array($token=>$this->_getEXTarray($str_aux));
                } else {
                    $ext_arr = $str_line;
                    //$ext_arr=array($token=>$str_line);
                }
                $result_array = $ext_arr;

                return $result_array;
            case 'NO':
                /* TODO:
                 parse the [ .... ] part of the response, use the method
                 _getEXTarray(&$str,'[',$stopDelim=']')

                 */

                $str_line       = rtrim(mb_substr($this->_getToEOL($str, false), 1));
                $result_array[] = @['COMMAND' => $token, 'EXT' => $str_line];

                return $result_array;
            case 'BAD':
                /* TODO:
                 parse the [ .... ] part of the response, use the method
                 _getEXTarray(&$str,'[',$stopDelim=']')

                 */

                $str_line       = rtrim(mb_substr($this->_getToEOL($str, false), 1));
                $result_array[] = ['COMMAND' => $token, 'EXT' => $str_line];

                return $result_array;
            case 'BYE':
                /* TODO:
                 parse the [ .... ] part of the response, use the method
                 _getEXTarray(&$str,'[',$stopDelim=']')

                 */

                $str_line       = rtrim(mb_substr($this->_getToEOL($str, false), 1));
                $result_array[] = ['COMMAND' => $command, 'EXT' => $str_line];

                return $result_array;
            case 'LISTRIGHTS':
                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $mailbox);
                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $user);
                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $granted);

                $ungranted = explode(' ', rtrim(mb_substr($this->_getToEOL($str, false), 1)));

                $result_array = @['MAILBOX' => $this->utf_7_decode($mailbox), 'USER' => $user, 'GRANTED' => $granted, 'UNGRANTED' => $ungranted];

                return $result_array;
            case 'MYRIGHTS':
                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $mailbox);
                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $granted);

                $result_array = ['MAILBOX' => $this->utf_7_decode($mailbox), 'GRANTED' => $granted];

                return $result_array;
            case 'ACL':
                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $mailbox);
                $this->_parseSpace($str, __LINE__, __FILE__);
                $acl_arr = explode(' ', rtrim(mb_substr($this->_getToEOL($str, false), 0)));

                for ($i = 0, $iMax = count($acl_arr); $i < $iMax; $i += 2) {
                    $arr[] = ['USER' => $acl_arr[$i], 'RIGHTS' => $acl_arr[$i + 1]];
                }

                $result_array = ['MAILBOX' => $this->utf_7_decode($mailbox), 'USERS' => $arr];

                return $result_array;
            case 'ANNOTATION':
                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $mailbox);

                $this->_parseSpace($str, __LINE__, __FILE__);
                $this->_getNextToken($str, $entry);

                $this->_parseSpace($str, __LINE__, __FILE__);
                $attrs = $this->_arrayfy_content($str);

                $result_array = ['MAILBOX' => $mailbox, 'ENTRY' => $entry, 'ATTRIBUTES' => $attrs];

                return $result_array;
            case '':
                $this->_prot_error('PROTOCOL ERROR!:str empty!!', __LINE__, __FILE__);
                break;
            case '(':
                $this->_prot_error('OPENING PARENTHESIS ERROR!!!!!!!!!!!!!!!!!', __LINE__, __FILE__);
                break;
            case ')':
                //"CLOSING PARENTHESIS BREAK!!!!!!!"
                break;
            case "\r\n":
                $this->_prot_error('BREAK!!!!!!!!!!!!!!!!!', __LINE__, __FILE__);
                break;
            case ' ':
                // this can happen and we just ignore it
                // This happens when - for example - fetch returns more than 1 parammeter
                // for example you ask to get RFC822.SIZE and UID
                //$this->_prot_error("SPACE BREAK!!!!!!!!!!!!!!!!!" , __LINE__ , __FILE__ );
                break;
            default:
                $body_token = \mb_strtoupper(mb_substr($token, 0, 5));
                //echo "BODYYYYYYY: $body_token\n";
                $rfc822_token = \mb_strtoupper(mb_substr($token, 0, 7));
                //echo "BODYYYYYYY: $rfc822_token|$token\n";

                if ('BODY[' === $body_token || 'BODY.' === $body_token || 'RFC822.' === $rfc822_token) {
                    //echo "TOKEN:$token\n";
                    //$this->_getNextToken( $str , $mailbox );
                    return [$token => $this->_parseContentresponse($str, $token)];
                }
                $this->_prot_error("UNIMPLEMMENTED! I don't know the parameter '$token' !!!", __LINE__, __FILE__);

                break;
        }

        return false;
    }

    /*
     * Verifies that the next character IS a space
     */

    /**
     * @param       $line
     * @param       $file
     * @param bool  $printError
     * @param mixed $str
     * @return mixed|string
     */
    public function _parseSpace(&$str, $line, $file, bool $printError = true)
    {
        /*
         This code repeats a lot in this class
         so i make it a function to make all the code shorter
         */
        $this->_getNextToken($str, $space);
        if (' ' !== $space) {
            $this->_prot_error("must be a ' ' but is a '$space' !!!!", $line, $file, $printError);
        }

        return $space;
    }

    /**
     * @param string $str
     * @param string $char
     * @param string $line
     * @param string $file
     * @return mixed
     */
    public function _parseString(&$str, $char, $line, $file)
    {
        /*
         This code repeats a lot in this class
         so i make it a function to make all the code shorter
         */
        $this->_getNextToken($str, $char_aux);
        if (mb_strtoupper($char_aux) != \mb_strtoupper($char)) {
            $this->_prot_error("must be a $char but is a '$char_aux' !!!!", $line, $file);
        }

        return $char_aux;
    }

    /**
     * @param null  $cmdid
     * @param mixed $str
     * @return array
     */
    public function _genericImapResponseParser(&$str, $cmdid = null): array
    {
        $result_array = [];
        if ($this->_unParsedReturn) {
            $unparsed_str = $str;
        }

        $this->_getNextToken($str, $token);

        while ($token != $cmdid && '' != $str) {
            if ('+' == $token) {
                //if the token  is + ignore the line
                // TODO: verify that this is correct!!!
                $this->_getToEOL($str);
                $this->_getNextToken($str, $token);
            }

            $this->_parseString($str, ' ', __LINE__, __FILE__);

            $this->_getNextToken($str, $token);
            if ('+' == $token) {
                $this->_getToEOL($str);
                $this->_getNextToken($str, $token);
            } elseif (is_numeric($token)) {
                // The token is a NUMBER so I store it
                $msg_nro = $token;
                $this->_parseSpace($str, __LINE__, __FILE__);

                // I get the command
                $this->_getNextToken($str, $command);

                if (false === ($ext_arr = $this->_retrParsedResponse($str, $command, $msg_nro))) {
                    //  if this bogus response cis a FLAGS () or EXPUNGE response
                    // the ignore it
                    if ('FLAGS' !== $command && 'EXPUNGE' !== $command) {
                        $this->_prot_error('bogus response!!!!', __LINE__, __FILE__, false);
                    }
                }
                $result_array[] = ['COMMAND' => $command, 'NRO' => $msg_nro, 'EXT' => $ext_arr];
            } else {
                // OK the token is not a NUMBER so it MUST be a COMMAND
                $command = $token;

                /* Call the parser return the array
                 take care of bogus responses!
                 */

                if (false === ($ext_arr = $this->_retrParsedResponse($str, $command))) {
                    $this->_prot_error("bogus response!!!! (COMMAND:$command)", __LINE__, __FILE__);
                }
                $result_array[] = ['COMMAND' => $command, 'EXT' => $ext_arr];
            }

            $this->_getNextToken($str, $token);

            $token = \mb_strtoupper($token);
            if ("\r\n" !== $token && '' != $token) {
                $this->_prot_error("PARSE ERROR!!! must be a '\\r\\n' here  but is a '$token'!!!! (getting the next line)|STR:|$str|", __LINE__, __FILE__);
            }
            $this->_getNextToken($str, $token);

            if ('+' == $token) {
                //if the token  is + ignore the line
                // TODO: verify that this is correct!!!
                $this->_getToEOL($str);
                $this->_getNextToken($str, $token);
            }
        }//While
        // OK we finish the UNTAGGED Response now we must parse the FINAL TAGGED RESPONSE
        //TODO: make this a litle more elegant!

        $this->_parseSpace($str, __LINE__, __FILE__, false);

        $this->_getNextToken($str, $cmd_status);

        $str_line = rtrim(mb_substr($this->_getToEOL($str), 1));

        $response['RESPONSE'] = ['CODE' => $cmd_status, 'STR_CODE' => $str_line, 'CMDID' => $cmdid];

        $ret = $response;
        if (!empty($result_array)) {
            $ret = array_merge($ret, ['PARSED' => $result_array]);
        }

        if ($this->_unParsedReturn) {
            $unparsed['UNPARSED'] = $unparsed_str;
            $ret                  = array_merge($ret, $unparsed);
        }

        if (isset($status_arr)) {
            $status['STATUS'] = $status_arr;
            $ret              = array_merge($ret, $status);
        }

        return $ret;
    }

    /**
     * @param string $command
     * @param string $params
     * @return array|\PEAR_Error
     */
    public function _genericCommand($command, string $params = '')
    {
        if (!$this->_connected) {
            return new PEAR_Error("not connected! (CMD:$command)");
        }
        $cmdid = $this->_getCmdId();
        $this->_putCMD($cmdid, $command, $params);
        $args = $this->_getRawResponse($cmdid);

        return $this->_genericImapResponseParser($args, $cmdid);
    }

    /**
     * @param string $str
     * @return \PEAR_Error|string
     */
    public function utf_7_encode(string $str)
    {
        if (!$this->_useUTF_7) {
            return $str;
        }
        //return imap_utf7_encode($str);

        $encoded_utf7 = '';
        $base64_part  = '';
        if (is_array($str)) {
            return new PEAR_Error('error');
        }

        for ($i = 0, $iMax = mb_strlen($str); $i < $iMax; ++$i) {
            //those chars should be base64 encoded
            if (((ord($str[$i]) >= 39) && (ord($str[$i]) <= 126)) || ((ord($str[$i]) >= 32) && (ord($str[$i]) <= 37))) {
                if ($base64_part) {
                    $encoded_utf7 = sprintf('%s&%s-', $encoded_utf7, str_replace('=', '', base64_encode($base64_part)));
                    $base64_part  = '';
                }
                $encoded_utf7 = sprintf('%s%s', $encoded_utf7, $str[$i]);
            } else {
                //handle &
                if (38 == ord($str[$i])) {
                    if ($base64_part) {
                        $encoded_utf7 = sprintf('%s&%s-', $encoded_utf7, str_replace('=', '', base64_encode($base64_part)));
                        $base64_part  = '';
                    }
                    $encoded_utf7 = sprintf('%s&-', $encoded_utf7);
                } else {
                    $base64_part = sprintf('%s%s', $base64_part, $str[$i]);
                    //$base64_part = sprintf("%s%s%s",$base64_part , chr(0) , $str[$i]);
                }
            }
        }
        if ($base64_part) {
            $encoded_utf7 = sprintf('%s&%s-', $encoded_utf7, str_replace('=', '', base64_encode($base64_part)));
            $base64_part  = '';
        }

        return $encoded_utf7;
    }

    /**
     * @param string $str
     * @return string
     */
    public function utf_7_decode($str): string
    {
        if (!$this->_useUTF_7) {
            return $str;
        }

        //return imap_utf7_decode($str);

        $base64_part  = '';
        $decoded_utf7 = '';

        for ($i = 0, $iMax = mb_strlen($str); $i < $iMax; ++$i) {
            if ('' !== $base64_part) {
                if ('-' == $str[$i]) {
                    if ('&' === $base64_part) {
                        $decoded_utf7 = sprintf('%s&', $decoded_utf7);
                    } else {
                        $next_part_decoded = base64_decode(mb_substr($base64_part, 1), true);
                        $decoded_utf7      = sprintf('%s%s', $decoded_utf7, $next_part_decoded);
                    }
                    $base64_part = '';
                } else {
                    $base64_part = sprintf('%s%s', $base64_part, $str[$i]);
                }
            } else {
                if ('&' === $str[$i]) {
                    $base64_part = '&';
                } else {
                    $decoded_utf7 = sprintf('%s%s', $decoded_utf7, $str[$i]);
                }
            }
        }

        return $decoded_utf7;
    }
}//Class
