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

- Never share or commit your `.env` file. It holds the app key, any AI or market-data API keys,
  and `ALPACA_SERVICE_TOKEN` if you use the Alpaca section.
- Never commit or share `database/database.sqlite`, backups, broker CSV files or exports. They
  contain your financial records.
- If a key is ever exposed, revoke it with the provider (Anthropic or Tiingo) and create a new
  one. For the app key, run `php artisan key:generate`, which logs everyone out.

## The Alpaca service token

`ALPACA_SERVICE_TOKEN` lets Protracker read from the optional, separate Node.js service over an
Alpaca paper account. It has to match `API_TOKEN` in that service's own `.env` file. Treat it like
a password: anyone holding it, on a computer that can reach the service, can read that account's
balances, positions and orders.

- **Where it's kept:** only in `.env`, on the server side. Protracker sends it in the
  `Authorization` header, never in an address. It isn't sent to the browser or written to the logs.
- **Where it's sent:** only to the computer Protracker runs on. If `ALPACA_SERVICE_URL` points
  anywhere else, Protracker refuses and sends nothing.
- **Your Alpaca keys:** these stay in the Node.js service's `.env`. Protracker never receives them,
  and they don't belong in Protracker's `.env`.
- **If the token is exposed:** create a new one, put it in both `.env` files, restart the service,
  and run `php artisan config:clear` in Protracker. If your Alpaca keys may be exposed too, also
  regenerate them in the Alpaca dashboard.

The service listens on `127.0.0.1` only and has no routes that place, change or cancel orders. It
refuses to start with any Alpaca address other than the paper one. Don't make it reachable from
other computers, and don't point it at a live account.
