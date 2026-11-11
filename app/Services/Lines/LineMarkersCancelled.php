<?php

namespace App\Services\Lines;

use RuntimeException;

/**
 * A detector run was stopped because the user cancelled the page it was working on.
 */
class LineMarkersCancelled extends RuntimeException {}
