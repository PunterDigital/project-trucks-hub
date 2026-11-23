<?php

namespace App\Support;

use RuntimeException;

/** A TruckersMP lookup that failed (#5228). The message is shown to the driver as is. */
class TruckersMpException extends RuntimeException {}
