# Security policy

## Reporting a problem

If you find a security problem in Protracker, please **don't open a public issue**. Report it
privately through GitHub instead: open the repository's **Security** tab and choose **Report a
vulnerability**.

Please include what you found, the steps to reproduce it, and which version (commit) you used.
This is a personal project maintained in spare time, so there's no guaranteed response time,
but every report will be read.

## What's in scope

Protracker is a **single-user, self-hosted** app that's meant to run on the owner's own
computer:

- Every login can see every record in that installation. One user per installation is the
  intended design, not a vulnerability.
- Problems that only arise when the app is deliberately exposed to the internet without the
  precautions in [docs/backup-and-restore.md](docs/backup-and-restore.md#6-security-notes) (such
  as HTTPS and `APP_DEBUG=false`) are configuration issues rather than flaws in the app, but
  reports that help make those precautions clearer are welcome.

## Keeping your own installation safe

- Never share or commit your `.env` file. It holds the app key and any AI or market-data API
  keys.
- Never commit or share `database/database.sqlite`, backups, broker CSV files or exports. They
  contain your financial records.
- If a key is ever exposed, revoke it with the provider (Anthropic or Tiingo) and create a new
  one. For the app key, run `php artisan key:generate`, which logs everyone out.
