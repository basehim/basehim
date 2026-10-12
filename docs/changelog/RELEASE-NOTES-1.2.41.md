Stronger protection against password guessing.

- A single account is now guarded against guessing spread across many
  networks, not just repeated tries from one: once an account sees enough
  failed sign-ins from anywhere, every attempt to it must answer the security
  question. The owner is only ever asked a question, never locked out.
- Tune it under Settings → Authentication ("Account-wide failures before a
  security question", default 10).
- Minor cleanups: removed an unused internal login routine, and corrected the
  lockout-duration wording (the pause grows by a step each time, it does not
  double).
