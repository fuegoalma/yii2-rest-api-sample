# 15. Email verification is recorded, not enforced

**Status:** accepted

## Context

`POST /auth/register` is open, and the address it is given is unproven. The
standard answer is to mail a link and refuse the account until it is clicked.

This API has no mail server. `MailerInterface` is bound to
`components/mail/LogMailer`, which writes the message to the structured log —
honest for a sample, and the only thing that makes the flow runnable end to end
without provisioning infrastructure nobody asked for.

Gating on verification in that setting means a queued message standing between a
user and the thing they signed up for, where the message goes to a log file.

## Decision

Verification is **recorded**, not enforced.

- Registration succeeds and the account works immediately.
- `user.email_verified_at` stays null until the address is proven.
- `email_verified` is on the user shape, so a client can prompt.
- `POST /auth/verify-email` spends the token; `POST /users/me/resend-verification`
  issues another, and is a no-op once verified so it cannot spray mail at a
  confirmed address.

The token machinery is the password reset's, scoped by `purpose` — see
[ADR 14](0014-one-table-for-every-single-use-token.md).

**`verify-email` is public.** The token *is* the proof, and demanding a session
as well breaks the ordinary case of opening the link in whatever browser the mail
client hands it to.

## Consequences

- **An operator who wants a gate has the flag and one place to check it.** An
  operator who does not gets a working API. Building the gate and shipping it
  switched off would have been two decisions where one will do, and a disabled
  gate is a thing nobody can tell is deliberate.
- **This is a decision about the deployment, not about the feature.** In front of
  real users, on a real transport, the calculation changes: an unverified address
  can receive a password reset, so leaving it unproven indefinitely is a way to
  hold an account somebody else will later claim. The flag is the seam that makes
  turning the gate on a small change.
- **`LogMailer` must not survive that move.** The body is logged, so a reset link
  in a log is a reset link anyone with log access can spend. Its own docblock says
  so, because the class is otherwise an inviting thing to leave in place. Swapping
  it for `yii\symfonymailer\Mailer` is one binding in `config/di.php`.
- Mail is sent through the queue (`SendEmailJob`), not inline: an SMTP
  conversation is a third-party network call inside a request the user is waiting
  on, and a transient failure should be retried rather than become a 500. See
  [ADR 7](0007-db-queue-instead-of-yii2-queue.md).
- The verification link lives longer than a password reset
  (`EMAIL_VERIFICATION_TTL`, 24h, against `PASSWORD_RESET_TTL`, 1h). Nobody is
  waiting on it under pressure, and a link that expires before the message is
  read costs a support ticket.
