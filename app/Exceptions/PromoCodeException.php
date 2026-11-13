<?php

namespace App\Exceptions;

use RuntimeException;

/** A promo code can't be applied. The message is safe to show to customers. */
class PromoCodeException extends RuntimeException {}
