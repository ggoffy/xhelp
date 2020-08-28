<?php

namespace XoopsModules\Xhelp;

use XoopsModules\Xhelp;

/**
 * Xhelp\Service class
 *
 * Part of the Messaging Subsystem. Provides the base interface for subscribing, unsubcribing from events
 *
 *
 * @author  Brian Wahoff <ackbarr@xoops.org>
 * @access  public
 * @package xhelp
 */
class Service
{
    public $_cookies = [];
    public $_eventSrv;

    /**
     * @param $eventName
     * @param $callback
     */
    public function _attachEvent($eventName, $callback)
    {
        $this->_addCookie($eventName, $this->_eventSrv->advise($eventName, $callback));
    }

    public function init()
    {
        $this->_eventSrv = Xhelp\Utility::createNewEventService();
        $this->_attachEvents();
    }

    public function _attachEvents()
    {
        //Do nothing (must implement this function in subclasses)
    }

    public function detachEvents()
    {
        foreach ($this->_cookies as $event => $cookie) {
            if (\is_array($cookie)) {
                foreach ($cookie as $ele) {
                    $this->_eventSrv->unadvise($event, $ele);
                }
            } else {
                $this->_eventSrv->unadvise($event, $cookie);
            }
        }
        $this->_cookie = [];
    }

    /**
     * @param $eventName
     */
    public function detachFromEvent($eventName)
    {
        if (isset($this->_cookies[$eventName])) {
            $cookie = $this->_cookies[$eventName];
            if (\is_array($cookie)) {
                foreach ($cookie as $ele) {
                    $this->_eventSrv->unadvise($eventName, $ele);
                }
            } else {
                $this->_eventSrv->unadvise($eventName, $cookie);
            }
            unset($this->_cookies[$eventName]);
        }
    }

    /**
     * @param $eventName
     * @param $cookie
     */
    public function _addCookie($eventName, $cookie)
    {
        //Check if the cookie already exist
        if (!isset($this->_cookies[$eventName])) {
            //Cookie doesn't exist
            $this->_cookies[$eventName] = $cookie;
        } else {
            if (\is_array($this->_cookies[$eventName])) {
                //Already an array, just add new cookie to array
                $this->_cookies[$eventName][] = $cookie;
            } else {
                //A single value, take value and replace it with an array
                $oldCookie                  = $this->_cookies[$eventName];
                $this->_cookies[$eventName] = [$oldCookie, $cookie];
            }
        }
    }
}
