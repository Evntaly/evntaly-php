<?php

// src/Evntaly/Exception/EvntalyException.php

namespace Evntaly\Exception;

class EvntalyException extends \Exception
{
    /**
     * Create a new Evntaly exception instance.
     *
     * @param string          $message  The exception message
     * @param int             $code     The exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message, int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    // Can be extended with specific error codes and methods as needed
}
