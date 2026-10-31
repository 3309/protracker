# Protracker — Wheel Strategy Tracker

Protracker is a self-hosted web app for keeping track of stock trades, options and the
**wheel strategy** (selling puts, taking assignment, selling covered calls) across one or more
brokerage accounts. You run it on your own computer and open it in your web browser. Your
records stay in a single database file on that computer.

> **One person per installation.** Protracker is built for a single user. Everyone who logs in
> to an installation sees all of its records. If several people want to use it, each person
> should install their own copy, with their own database and their own API keys. Don't run one
> shared copy for several people.

> **Not financial or tax advice.** Protracker is a record-keeping tool. Its profit and loss
> figures follow the tracking rules described below, and they aren't a substitute for your
> broker's tax documents.

## Features

- **Accounts** for Fidelity, Robinhood or any other broker (taxable, IRA or other), each with
  its own fee settings.
- **Opening balances**, so that cash, shares and open options you already held when you started
  tracking aren't counted again as new income.
- **Stock trades** using first-in, first-out (FIFO) lots per account and ticker, with realized
  profit or loss for each sale.
- **Options:** sell or buy to open, buy back or sell to close (all or some contracts), expiry,
  assignment (which creates the stock trade at the strike) and rolls linked into a chain.
- **Dividends**, with the number of eligible shares worked out from the ex-date and a warning
  when the amount the broker paid is different.
- **Cash ledger and reconciliation** against the balance your broker reports.
- **Dashboard:** realized profit or loss, premium collected, wheel-cycle results, open
  positions, upcoming expirations, fees, data-quality alerts and charts.
- **Prices** entered by hand, or optionally fetched automatically (see
  [Automatic stock prices](#optional-automatic-stock-prices-tiingo)).
- **CSV import** of Fidelity and Robinhood activity files, or the app's own template. You
  preview the rows before importing, and duplicates are detected.
- **Audit history** of every change to a financial record. Corrections are made by editing or
  voiding a record, never by silently deleting it.
- **CSV exports** and **backup and restore** commands.
- **AI Trade Assistant** (optional and paid): describe a trade in plain English, check the
  details the AI read, and save it through the normal entry form.
- Light, dark or system theme, and a sidebar that collapses to icons.

### How the numbers are worked out

- **Option premium is option profit.** It isn't subtracted from the cost of shares you get
  through assignment. Shares from an assigned put cost the strike price.
- **Shares are sold first in, first out**, separately for each account and ticker, and each
  sale's profit is worked out per lot.
- **Fees are kept separate.** Every trade has its own fee, and results are shown both before
  and after fees.
- **Dividends count only shares held before the ex-date.** They're separate income and don't
  change share cost or option profit.
- **Share lots and dividend eligibility go by trade date; the cash ledger goes by settlement
  date.**

Each of these rules is checked by automated tests built from worked examples. For example, a
full wheel cycle with fees must net exactly $448.66.

## Requirements

| What | Version | Notes |
| --- | --- | --- |
| PHP | 8.5 | Tested with PHP 8.5.12. `composer.json` allows 8.3 and newer, but only 8.5 has been tested. |
| PHP extensions | `pdo_sqlite`, `openssl`, `mbstring`, `fileinfo`, `tokenizer`, `dom`, `ctype`, `filter` | Usually included with PHP. Laravel Herd includes them all. |
| Composer | 2.x | The PHP package installer. |
| Node.js and npm | Node 22 or newer | Only needed to build the page styles. |
| Git | any recent version | To download the code and get updates. |

No separate database server is needed. Protracker uses SQLite, which keeps everything in a
single file.

**What has been tested:** installing from a fresh download and running the full test suite on
**Windows 11 with Laravel Herd**, PHP 8.5 and Node 26. macOS and Linux should work with the
same steps, but they haven't been tested yet.

## Installation on Windows (with Laravel Herd)

[Laravel Herd](https://herd.laravel.com/windows) is a free app that installs PHP and Composer
and serves PHP sites on your computer. It's the easiest way to run Protracker on Windows.

1. **Install the tools**
   - Laravel Herd: <https://herd.laravel.com/windows>. Open it once after installing.
   - Git for Windows: <https://git-scm.com/download/win>.
   - Node.js (the LTS version): <https://nodejs.org/>.

   Open a **new** PowerShell window and check that each tool works:

   ```powershell
   php -v
   composer -V
   node -v
   git --version
   ```

2. **Download Protracker into Herd's sites folder.** Herd serves every folder inside
   `%USERPROFILE%\Herd` as `http://<folder-name>.test`.

   ```powershell
   cd $HOME\Herd
   git clone https://github.com/<owner>/protracker.git protracker
   cd protracker
   ```

   Replace `<owner>` with the GitHub account the project is published under.

3. **Run the one-step setup** (this takes a few minutes the first time):

   ```powershell
   composer setup
   ```

   This installs the PHP and JavaScript packages, creates your settings file `.env` from
   `.env.example`, generates a secret app key, creates the database file
   `database/database.sqlite` with all its tables, and builds the page styles.

   > Run `composer setup` **only once**. It generates a new app key every time it runs, which
   > logs you out. To update an existing installation, follow [Updating](#updating) instead.

4. **Set the address.** Open `.env` in a text editor and change `APP_URL` to:

   ```
   APP_URL=http://protracker.test
   ```

5. **Create your login.** There's no public sign-up page. The one user is created here:

   ```powershell
   php artisan app:create-user
   ```

   Enter a name, an email address and a password of at least 12 characters. The command
   refuses to create a second user.

6. **Open the app** at <http://protracker.test> and log in.

## Installation on macOS or Linux (not yet tested)

Install PHP 8.5 with the extensions listed above, Composer, Node.js 22 or newer and Git. Laravel
Herd is also available for macOS. Then:

```bash
git clone https://github.com/<owner>/protracker.git protracker
cd protracker
composer setup
php artisan app:create-user
php artisan serve
```

Open <http://localhost:8000>. If you use Herd on macOS, put the folder in Herd's sites folder,
set `APP_URL=http://protracker.test` and skip `php artisan serve`.

## Running the app

- **With Herd:** nothing to start. Just open <http://protracker.test>.
- **Without Herd:** run `php artisan serve` in the project folder, open <http://localhost:8000>,
  and press `Ctrl+C` in that window to stop the app.

## Trying it with demo data (optional)

To explore the app before entering real trades, you can load **fictional** demo data. It adds
two made-up accounts trading the made-up tickers `DEMOA` and `DEMOB`: a full wheel cycle, a
roll, a dividend, and some options that are still open.

```powershell
php artisan db:seed --class=DemoDataSeeder
```

- It **only loads into an empty database.** If the database already holds any accounts,
  trades, options, dividends, cash entries, prices or imports, it stops and changes nothing, so
  it can't mix with real records.
- It doesn't create a login. Use `php artisan app:create-user` as usual.
- To start again with an empty database, first move or back up `database/database.sqlite`, then
  run `php artisan migrate` to create a fresh, empty one. Running `php artisan migrate:fresh`
  would also do this, but it **erases every record** in the database, so only use it on a
  database you're sure is demo data.

## Settings (`.env`)

All settings live in the `.env` file in the project folder. It holds your app key and API keys,
so **never share it or add it to Git** (it's already excluded). `.env.example` is the safe
template it's copied from.

| Setting | What it does |
| --- | --- |
| `APP_NAME` | The name shown in the app. |
| `APP_URL` | The address you open the app at, for example `http://protracker.test` or `http://localhost:8000`. |
| `APP_KEY` | A secret key created by `composer setup`. If it's lost or changed, everyone is logged out. |
| `APP_ENV`, `APP_DEBUG` | `local` and `true` show detailed error pages, which is fine on your own computer. Use `production` and `false` anywhere other people could reach the app. |
| `DB_CONNECTION` | `sqlite`. The database is `database/database.sqlite` unless you set `DB_DATABASE` to another file path. |
| `SESSION_LIFETIME`, `SESSION_ENCRYPT` | How long a login lasts (in minutes), and session encryption, which is on by default. |
| `AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL` | The optional AI Trade Assistant. See below. |
| `MARKET_DATA_PROVIDER`, `MARKET_DATA_API_KEY` | Optional automatic stock prices. See below. |

After changing `.env`, run `php artisan config:clear` if the change doesn't seem to apply.

## Optional: AI Trade Assistant (paid)

The **AI Trade Assistant** page lets you type something like *"Sold 2 XYZ puts at the $40
strike for $1.10, expiring March 20"*. The AI reads the details, you check them, and you save
the trade through the normal form. Nothing is saved without your review.

- It uses **Anthropic's Claude API**. You need an API account at <https://console.anthropic.com>
  with credit on it. API use is **paid per request** and is billed separately: a Claude.ai chat
  subscription doesn't include API use.
- **To turn it on**, create an API key in the Anthropic console and add it to `.env`:

  ```
  AI_PROVIDER=anthropic
  AI_API_KEY=your-key-here
  AI_MODEL=claude-opus-5-5
  ```

  `AI_MODEL` is optional; `claude-opus-5-5` is the default.
- **To turn it off**, leave `AI_PROVIDER` empty. The page then explains that the assistant
  isn't set up, and the rest of the app works normally.
- **What's sent to Anthropic:** only the text you type, today's date, and the names of your
  active accounts, so it can work out which account you mean. Balances, positions and account
  numbers are **not** sent.

## Optional: automatic stock prices (Tiingo)

The **Refresh stock prices** button on the dashboard can fetch recent stock prices for the
tickers you hold, so unrealized profit or loss is up to date. Prices fetched this way are
labelled separately from prices you enter by hand.

- It uses [Tiingo](https://www.tiingo.com/). Create a free account and copy your API token
  from **Account → API**. Tiingo's free plan has usage limits, and paid plans exist. Check
  Tiingo's site for the current terms.
- **To turn it on**, add to `.env`:

  ```
  MARKET_DATA_PROVIDER=tiingo
  MARKET_DATA_API_KEY=your-token-here
  ```

- **To turn it off**, leave `MARKET_DATA_PROVIDER` empty. The button then explains that
  automatic prices aren't set up, and you can still enter prices by hand.
- **What's sent to Tiingo:** only the ticker symbols being priced.
- These prices aren't live quotes and never change realized profit or loss.

## Backing up and restoring

Everything you enter is stored in one file, `database/database.sqlite`. Back it up regularly,
and always before importing a file, restoring, or updating the app.

```powershell
php artisan app:backup                      # saves a checked copy in storage/app/private/backups
php artisan app:restore <backup-file-name>  # restores a backup (your current data is backed up first)
```

[docs/backup-and-restore.md](docs/backup-and-restore.md) explains how to test-restore a backup
without touching your data, restore by hand, keep backups private, and export records to CSV.

Backups and exports contain your full trading history. Keep them out of Git, email and shared
folders.

## Updating

Back up first (`php artisan app:backup`), then in the project folder run:

```powershell
git pull
composer install
npm install
npm run build
php artisan migrate
```

`php artisan migrate` only adds or changes tables, and it keeps your records.

## Running the automated tests

```powershell
php artisan test
```

The tests use their own temporary in-memory database, so they **never touch your data**. They
also always run with the AI and price keys switched off, use fake replies, and fail if any test
tries to reach the internet. Your API keys are never used and running the tests costs nothing. `composer test` also runs the code-style and type checks.

## Common problems

| Problem | Fix |
| --- | --- |
| `php`, `composer` or `node` is "not recognized" | Close and reopen PowerShell after installing. Check that Herd is installed and has been opened once. |
| "Unable to locate file in Vite manifest", or the page has no styling | Run `npm install` and then `npm run build`. |
| "could not find driver" or other SQLite errors | Enable the `pdo_sqlite` extension in your PHP installation. Herd has it enabled already. |
| "No application encryption key has been specified" | Run `php artisan key:generate`. |
| "419 Page Expired" after logging in or submitting a form | The page was open too long. Refresh it and try again. |
| <http://protracker.test> doesn't open | Check that the folder is inside `%USERPROFILE%\Herd` and named `protracker`, and that Herd is running. |
| Locked out, or forgot your password | Run `php artisan app:reset-password you@example.com`. |
| AI or price button says "not set up yet" | Add the settings described above to `.env`, then run `php artisan config:clear`. |
| AI says the Anthropic account is out of credit | Add credit at <https://console.anthropic.com>. |

## Security

Protracker is meant to run on your own computer. Don't expose it to the internet without
HTTPS, `APP_DEBUG=false` and careful planning. See
[docs/backup-and-restore.md](docs/backup-and-restore.md#6-security-notes), and see
[SECURITY.md](SECURITY.md) for how to report a security problem privately.

## License

Protracker is open-source software released under the [MIT License](LICENSE).
