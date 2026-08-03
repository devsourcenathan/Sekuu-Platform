<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Audit;

/**
 * Vocabulaire fermé des actions journalisées.
 *
 * Comme les codes d'erreur, ces valeurs sont stables : des rapports de
 * conformité s'appuieront dessus.
 */
final class AuditAction
{
    public const USER_REGISTERED = 'user.registered';

    public const AUTH_LOGIN = 'auth.login';

    public const AUTH_LOGIN_FAILED = 'auth.login_failed';

    public const AUTH_LOGOUT = 'auth.logout';

    public const AUTH_LOGOUT_ALL = 'auth.logout_all';

    public const AUTH_ORGANIZATION_SWITCHED = 'auth.organization_switched';

    public const AUTH_TOKEN_REPLAY_DETECTED = 'auth.token_replay_detected';

    public const PASSWORD_RESET_REQUESTED = 'password.reset_requested';

    public const PASSWORD_RESET = 'password.reset';

    public const EMAIL_VERIFICATION_SENT = 'email.verification_sent';

    public const EMAIL_VERIFIED = 'email.verified';

    public const ORGANIZATION_CREATED = 'organization.created';

    public const INVITATION_SENT = 'invitation.sent';

    public const INVITATION_ACCEPTED = 'invitation.accepted';

    public const INVITATION_REVOKED = 'invitation.revoked';

    public const WORKSPACE_CREATED = 'workspace.created';

    public const WORKSPACE_UPDATED = 'workspace.updated';

    public const WORKSPACE_DELETED = 'workspace.deleted';

    public const WORKSPACE_MEMBER_ADDED = 'workspace.member_added';

    public const WORKSPACE_MEMBER_REMOVED = 'workspace.member_removed';
}
