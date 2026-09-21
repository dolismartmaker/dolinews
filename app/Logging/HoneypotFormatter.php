<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger;

/**
 * One line per honeypot detection, the contractual format fail2ban
 * reads (LARAVEL_HONEYPOT.md).
 */
class HoneypotFormatter
{
    /**
     * @param  \Illuminate\Log\Logger  $logger
     */
    public function __invoke($logger): void
    {
        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message%\n",
            'Y-m-d H:i:s',
            false, // allowInlineLineBreaks : un \n echappe garde une detection sur une ligne
            true   // ignoreEmptyContextAndExtra
        );

        $monolog = $logger->getLogger();

        // Le contrat ne promet qu'un logger PSR : un canal remplace par un
        // logger PSR doit laisser le format tranquille plutot que lever.
        if (! $monolog instanceof Logger) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter($formatter);
            }
        }
    }
}
