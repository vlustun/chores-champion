# Build Plan

> i need a way to create a new user, is there an admin interface to add a new user ? Or at least if i forgot my password, to be able to reset my password

**Stack:** Symfony 7.4 / PHP 8.3, EasyAdmin, Twig, Doctrine migrations. Adds symfony/mailer and symfonycasts/reset-password-bundle. Dev mail goes to Mailpit in docker-compose via MAILER_DSN. Run with `make up`, `make migrate`, `make serve`.

## Files

- `composer.json` — Require symfony/mailer and symfonycasts/reset-password-bundle
- `config/packages/reset_password.yaml` — Reset bundle config: request_password_repository, 1h lifetime, throttle
- `config/packages/mailer.yaml` — Mailer DSN from env; MAILER_DSN and MAILER_FROM added to .env, .env.docker.example and .env.test (null://null)
- `src/Entity/ResetPasswordRequest.php` — Reset token entity (bundle trait) linked to User
- `src/Repository/ResetPasswordRequestRepository.php` — Repository for reset requests
- `migrations/VersionXXXX.php` — Generated migration creating the reset_password_request table
- `src/Controller/ResetPasswordController.php` — Routes /forgot-password, /forgot-password/check-email and /reset-password/reset/{token}. Always shows a generic response so account existence is not leaked. Sends the email, validates the token, hashes the new password, and uses the existing UserChecker so inactive users are blocked
- `src/Form/ResetPasswordRequestType.php` — Email field for requesting a reset
- `src/Form/ChangePasswordType.php` — Repeated password field (min 8, matches the User plainPassword constraint); also used for the profile change with a current-password field
- `templates/reset_password/request.html.twig` — Forgot password form
- `templates/reset_password/check_email.html.twig` — Generic 'if the account exists we sent an email' page
- `templates/reset_password/reset.html.twig` — Set new password form
- `templates/reset_password/email.html.twig` — Email body with the reset link
- `templates/security/login.html.twig` — Add a 'Forgot your password?' link
- `config/packages/security.yaml` — PUBLIC_ACCESS for ^/forgot-password and ^/reset-password, placed before the catch-all ROLE_USER rule
- `src/Controller/ProfileController.php` — Add /profile/password action for a logged-in user to change their own password (needs the current password; CSRF-protected)
- `templates/profile/index.html.twig` — Link to or embed the change-password form
- `src/Controller/Admin/UserCrudController.php` — Add a 'Send password reset' row action. Falls back to showing a one-time reset link in a flash when the mailer is the null transport. Existing edit already lets an admin set a password directly
- `src/Command/ResetPasswordCommand.php` — `app:reset-password EMAIL [PASSWORD]` for when nobody can log in; `make reset-password` target in the Makefile
- `src/Service/Audit/AuditLogger.php` — Log password reset and change events (never the hashes)
- `docker-compose.yml` — Add a Mailpit service (SMTP 1025, UI 8025) for dev email
- `tests/Functional/ResetPasswordTest.php` — Request with known and unknown email gives the same response; token reset works; expired or invalid token is rejected; inactive user cannot reset; the new password can log in
- `tests/Functional/ProfilePasswordTest.php` — Change password requires the correct current password
- `tests/Functional/AdminCrudTest.php` — Add a test for creating a user via /admin/user/new and the admin reset action
- `README.md` — Document how admins add users, the forgot-password flow, the CLI reset and mail configuration

## Tasks

- [x] Confirm with the user: users are already created at /admin → Users → Add User (admin login required); the first admin comes from `make admin EMAIL=… NAME=… PASSWORD=…`. For an immediate lockout, `make admin` or the new CLI reset recovers it.
- [x] composer require symfony/mailer symfonycasts/reset-password-bundle; add MAILER_DSN and MAILER_FROM to the env files (null://null in test) and a Mailpit service to docker-compose.
- [x] Generate the ResetPasswordRequest entity and repository, then run make:migration and migrate.
- [x] Implement ResetPasswordController, its forms and templates. Use a generic response for unknown emails, respect the active flag, hash with UserPasswordHasherInterface, and throttle requests.
- [x] Open the new routes in security.yaml access_control above the catch-all rule and add the 'Forgot your password?' link on the login page.
- [x] Add the change-password action to ProfileController, with the current password, repeated new password and CSRF protection, plus the profile template link.
- [x] Add the EasyAdmin 'Send password reset' action in UserCrudController, with a one-time link flash fallback when mail is not configured.
- [x] Add the app:reset-password console command and Makefile target, and audit-log all password events without logging secrets.
- [x] Write functional tests for the reset flow, profile password change and admin user creation, and run `make test`, phpstan and php-cs-fixer.
- [ ] Update the README, then manually verify with Mailpit: request a reset, click the link, set a new password and log in.
