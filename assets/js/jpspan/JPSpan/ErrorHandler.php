<?php declare(strict_types=1);
/**
 * Include this file to have PHP errors displayed as Javascript exceptions
 * the client can interpret
 */

//-----------------------------------------------------------------------------

/**
 * Switch to FALSE to prevent PHP generated error messages
 * from being reported to client. JPSpan error messages
 * will still be displayed. The rest will result in a general
 * "Server unable to respond" message. Applies to both
 * errors and un-caught exceptions
 */
if (!defined('JPSPAN_ERROR_MESSAGES')) {
    define('JPSPAN_ERROR_MESSAGES', true);
}

/**
 * If defined as TRUE, errors transmitted to Javascript will include
 * the PHP filename and line number where the error / exception occurred
 * By default this is switched off as it represents a potential security leak
 */
if (!defined('JPSPAN_ERROR_DEBUG')) {
    define('JPSPAN_ERROR_DEBUG', false);
}

/**
 * Ignore PHP5 strict error messages
 */
if (!defined('JPSPAN_IGNORE_STRICT')) {
    define('JPSPAN_IGNORE_STRICT', true);
}

/**
 * Define E_STICT if it's PHP4
 */
if (!defined('E_STRICT')) {
    define('E_STRICT', 2048);
}

//-----------------------------------------------------------------------------

/**
 * Custom PHP error handler which generates Javascript exceptions
 * Called automatically by PHP on all errors except fatal errors
 * @see https://www.webkreator.com/php/configuration/handling-fatal-and-parse-errors.html
 * @param $level
 * @param $message
 * @param $file
 * @param $line
 */
function JPSpan_ErrorHandler($level, $message, $file, $line): void
{
    $name    = 'Server_Error';
    $message = strip_tags($message);
    $message = wordwrap($message, 60, '\n', 1);
    $file    = addcslashes($file, "\000\042\047\134");

    $replevel = error_reporting();
    if (($level & $replevel) != $level) {
        // ignore error
        return;
    }

    switch ($level) {
        case E_USER_NOTICE:
            $code = 2001;
            break;
        case E_USER_WARNING:
            $code = 2002;
            break;
        case E_USER_ERROR:
            $code = 2003;
            break;
        case E_STRICT:
            if (JPSPAN_IGNORE_STRICT) {
                return;
            }
            $code = 2004;
            // no break
        default:
            if (!JPSPAN_ERROR_MESSAGES) {
                $message = 'Server unable to respond';
            }
            $code = 2000;
            break;
    }

    $error = "var e = new Error('$message');e.name = '$name';e.code = '$code';";
    if (JPSPAN_ERROR_DEBUG) {
        $error .= "e.file = '$file';e.line = '$line';";
    }
    $error .= 'throw e;';
    echo 'new Function("' . addcslashes($error, "\000\042\047\134") . '");';

    if (!defined('JPSPAN')) {
        define('JPSPAN', __DIR__ . '/');
    }
    require_once JPSPAN . 'Monitor.php';
    $M = &(new JPSpan_Monitor())->instance();
    $M->announceError($name, $code, $message, $file, $line);

    // Must exit on any error in case of multiple errors
    // causing Javascript syntax errors
    exit();
}

/**
 * Switch the error handler on
 */
set_error_handler('JPSpan_ErrorHandler');
//-----------------------------------------------------------------------------

/**
 * Custom PHP exception handler which generates Javascript exceptions
 * @param $exception
 * @todo i18n error messages
 */
function JPSpan_ExceptionHandler($exception): void
{
    $name = 'Server_Error';
    $file = addcslashes($exception->getFile(), "\000\042\047\134");

    if (JPSPAN_ERROR_MESSAGES) {
        $message = strip_tags($exception->getMessage());
        $message = wordwrap($message, 60, '\n', 1);
    } else {
        $message = 'Server unable to respond';
    }

    $code = 2005;

    $error = "var e = new Error('$message');e.name = '$name';e.code = '$code';";
    if (JPSPAN_ERROR_DEBUG) {
        $error .= "e.file = '$file';e.line = '" . $exception->getLine() . "';";
    }
    $error .= 'throw e;';
    echo 'new Function("' . addcslashes($error, "\000\042\047\134") . '");';

    if (!defined('JPSPAN')) {
        define('JPSPAN', __DIR__ . '/');
    }
    require_once JPSPAN . 'Monitor.php';
    $M = &(new JPSpan_Monitor())->instance();
    $M->announceError($name, $code, $message, $file, $exception->getLine());

    exit();
}

/**
 * Switch the exception handler on for PHP5
 */
if (version_compare(PHP_VERSION, '5', '>=')) {
    // Spot the seg fault...
    set_exception_handler('JPSpan_ExceptionHandler');
}
