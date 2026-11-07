<?php

declare(strict_types=1);

namespace App\Logging;

use App\Entity\User;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Adds request_id, user_id (numeric only - no email/name = no PII), route and method to every log record.
 */
#[AsMonologProcessor]
final class RequestContextProcessor
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requests->getMainRequest();
        $extra = [];
        if (null !== $request) {
            $extra['request_id'] = $request->attributes->get('request_id');
            $extra['route'] = $request->attributes->get('_route');
            $extra['method'] = $request->getMethod();
        }
        $user = $this->tokens->getToken()?->getUser();
        if ($user instanceof User) {
            $extra['user_id'] = $user->getId();
        }

        return $record->with(extra: [...$record->extra, ...$extra]);
    }
}
