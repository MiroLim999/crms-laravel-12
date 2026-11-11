<?php

namespace App\Services\Lines;

use RuntimeException;

/**
 * Line detection or cropping failed. The message is safe to show on screen.
 */
class LineMarkersException extends RuntimeException {}
