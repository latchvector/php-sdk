<?php

declare(strict_types=1);

namespace LatchVector\Sso;

/**
 * The outcome of a login attempt: either TokenPair or MfaRequired.
 *
 * An interface rather than a token object with an empty accessToken. The
 * service returns either a token pair or {mfaRequired, pendingToken}, and
 * modelling it this way forces the caller to branch — a customer will
 * enable MFA eventually, and the failure mode of the nullable version is
 * an empty token reaching production.
 */
interface LoginResult
{
}
