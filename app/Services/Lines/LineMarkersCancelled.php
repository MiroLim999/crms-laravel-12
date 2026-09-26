<?php

namespace App\Services\Lines;

use RuntimeException;

/**
 * A detector run was stopped because Staff cancelled the page it was working on.
 */
class LineMarkersCancelled extends RuntimeException {}
