<?php

namespace App\Services\Payments;

use App\Enums\PayableType;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * PayableType → PayableHandler (D190). Filled from AppServiceProvider; S9b appends its store-order handler there.
 */
class PayableRegistry
{
    /** @var array<string, class-string<PayableHandler>> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<PayableHandler>  $handler
     */
    public function register(PayableType $type, string $handler): void
    {
        $this->handlers[$type->value] = $handler;
    }

    public function for(PayableType $type): PayableHandler
    {
        $class = $this->handlers[$type->value] ?? throw new LogicException("No payable handler for [{$type->value}].");

        return $this->container->make($class);
    }
}
