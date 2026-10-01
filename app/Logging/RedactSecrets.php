<?php

namespace App\Logging;

use App\Support\Redactor;
use Illuminate\Log\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/**
 * Log channel `tap`: every log line (message, context and exception text) passes through the
 * Redactor, so a provider key inside an exception message never reaches storage/logs.
 */
class RedactSecrets
{
    public function __invoke(Logger|\Monolog\Logger $logger): void
    {
        $monolog = $logger instanceof Logger ? $logger->getLogger() : $logger;

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new class extends LineFormatter
                {
                    public function __construct()
                    {
                        parent::__construct(null, null, true, true);
                    }

                    public function format(LogRecord $record): string
                    {
                        return Redactor::scrub(parent::format($record));
                    }
                });
            }
        }
    }
}
