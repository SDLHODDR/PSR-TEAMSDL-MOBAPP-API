<?php

declare(strict_types=1);

namespace SDL\Framework;

use Throwable;

final class ErrorHandler
{
    private static string $logDirectory = '';
    private static bool $debug = false;

    public static function register(string $logDirectory, bool $debug = false): void
    {
        self::$logDirectory = $logDirectory;
        self::$debug = $debug;

        set_exception_handler(static function (Throwable $exception): void {
            self::log($exception);
            self::response($exception)->send();
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function response(Throwable $exception): Response
    {
        if ($exception instanceof HttpException) {
            $body = [
                'success' => false,
                'message' => $exception->getMessage(),
                'error' => ['code' => $exception->errorCode],
            ];
            if ($exception->details() !== []) {
                $body['error']['details'] = $exception->details();
            }
            return Response::json($body, $exception->statusCode);
        }

        $body = [
            'success' => false,
            'message' => self::$debug ? $exception->getMessage() : 'An unexpected server error occurred',
            'error' => ['code' => 'internal_server_error'],
        ];
        if (self::$debug) {
            $body['debug'] = [
                'type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }
        return Response::json($body, 500);
    }

    private static function log(Throwable $exception): void
    {
        if (self::$logDirectory === '') {
            return;
        }
        if (!is_dir(self::$logDirectory) && !mkdir(self::$logDirectory, 0770, true) && !is_dir(self::$logDirectory)) {
            error_log('SDL API could not create its log directory.');
            return;
        }

        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n",
            date(DATE_ATOM),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
        error_log($line, 3, rtrim(self::$logDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'app.log');
    }
}
