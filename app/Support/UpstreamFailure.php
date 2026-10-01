<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Exceptions\UpstreamInvalidResponseException;
use App\Exceptions\UpstreamUnavailableException;
use App\Services\MailConfigurationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Maps a failed call to a provider (AI, job board, mail server) onto the API error codes
 * of SPEC decision 12: unreachable, timed out or not usable -> 503; the provider answered
 * with an error -> 502. The message is for the log and the stored last_error, never the response.
 */
final class UpstreamFailure
{
    public static function exception(Throwable $error, string $redactedReason): ApiException
    {
        return self::unreachable($error)
            ? new UpstreamUnavailableException($redactedReason, $error)
            : new UpstreamInvalidResponseException($redactedReason, $error);
    }

    private static function unreachable(Throwable $error): bool
    {
        for ($depth = 0; $error && $depth < 10; $depth++, $error = $error->getPrevious()) {
            if ($error instanceof ConnectionException || $error instanceof MailConfigurationException || $error instanceof DecryptException) {
                return true;
            }
            if (preg_match('/timed out|timeout|could not be established|could not resolve|resolve host|getaddrinfo|connection refused|unreachable|name or service not known/i', $error->getMessage())) {
                return true;
            }
        }

        return false;
    }
}
