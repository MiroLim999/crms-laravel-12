<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The correction workflow refused an action, for example a second pending
 * request on one record, or a decision on a request that is already decided.
 * The message is safe to show to the user.
 *
 * Only this exception is shown in the page banner. Anything else, such as a
 * database error, is left to Laravel's error handler.
 */
class ChangeRequestException extends RuntimeException {}
