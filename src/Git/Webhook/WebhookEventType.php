<?php

declare(strict_types=1);

namespace App\Git\Webhook;

enum WebhookEventType: string
{
    /** Something was pushed to a branch; a push to the default branch triggers a synchronisation. */
    case Push = 'push';
    /** A pull/merge request was opened, updated, merged or closed. */
    case ChangeRequest = 'change_request';
}
