<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use App\Core\Enums\ApiErrorCode;
use RuntimeException;
use Throwable;

/**
 * Domain-level API exception carrying a typed error code and optional detail.
 *
 * Thrown by Core services and translated into the JSON error envelope (S8)
 * by BaseApiController::error() or the global exception handler.
 */
class ApiException extends RuntimeException
{
    /**
     * The typed error code. Named errorCode to avoid clashing with the
     * inherited, non-readonly Exception::$code integer property.
     */
    private readonly ApiErrorCode $errorCode;

    /**
     * @var array<string, mixed> Extra machine-readable context.
     */
    private readonly array $detailData;

    /**
     * @param  array<string, mixed>  $detail  Extra machine-readable context.
     */
    public function __construct(
        ApiErrorCode $code,
        array $detail = [],
        ?Throwable $previous = null,
    ) {
        $this->errorCode = $code;
        $this->detailData = $detail;

        parent::__construct($code->message(), $code->httpStatus(), $previous);
    }

    /**
     * The typed error code for this exception.
     */
    public function code(): ApiErrorCode
    {
        return $this->errorCode;
    }

    /**
     * Extra machine-readable context attached to this exception.
     *
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        return $this->detailData;
    }
}
