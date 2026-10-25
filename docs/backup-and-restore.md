# Backing up, restoring and exporting Protracker

All of Protracker's records — accounts, trades, options, dividends, cash,
prices, imports and the audit history — live in **one file**:

```
database/database.sqlite
```

Backing up means making a safe copy of that file. Restoring means putting a
copy back. Run the commands below in a terminal opened in the project
folder (the folder that holds the `artisan` file).

---

## 1. Make a backup

```
php artisan app:backup
```

What it does:

1. Copies the database into `storage/app/private/backups/`, named with the
   date and time, for example `protracker-2026-10-25-143000.sqlite`. The
   copy is consistent even if the app is open in your browser.
2. Checks the copy: it must pass SQLite's own integrity check and hold the
   same number of records in every table as the database. If not, the copy
   is deleted and you are told why.
3. Prints the file's location, size, a SHA-256 fingerprint (to confirm later
   that a copy has not changed) and the record counts.

**When to back up:** before importing a file, before restoring, before
updating the app, and regularly (for example weekly, or after each session
of entering trades).

---

## 2. Keep backups private

A backup holds your full trading history. It is **not encrypted**
(encrypting it with the app's key would mean that losing the key loses
every backup), so where you keep it matters:

- **Do** copy backups to a drive protected by **BitLocker** (Windows:
  Settings → Privacy & security → Device encryption, or right-click a USB
  drive → Turn on BitLocker), or to private cloud storage protected by a
  strong password and two-step sign-in.
- **Do** keep at least one copy somewhere other than this computer, so a
  failed disk or lost laptop does not take the backups with it.
- **Don't** put backups in Git, email them, or leave them in shared or
  synced folders that other people can open.
- The backups folder and the database are already excluded from Git
  (`storage/app/private/.gitignore` and `database/.gitignore`), and the web
  server never serves files from `storage/app/private`.
- Old backups can be deleted whenever you no longer need them.

---

## 3. Check that a backup can be restored (test restore)

This restores a backup into a **separate file**, so your real database is
not touched:

```
php artisan app:restore protracker-2026-10-25-143000.sqlite --to=storage/app/private/test-restore.sqlite
```

It checks the backup, copies it into the test file, checks the result again
and prints the record counts, which should match what `app:backup` printed.
Delete the test file afterwards.

---

## 4. Restore a backup over the app's database

Use this only when you want to go back to a backup — for example after a
mistaken import or a damaged database.

```
php artisan app:restore protracker-2026-10-25-143000.sqlite
```

(You can give just the file name for a backup in the backups folder, or a
full path to a file anywhere else.)

What it does, in order:

1. Checks the backup (integrity, and that it is a Protracker database). A
   damaged file, another kind of file, or a backup made by a **newer**
   version of the app is refused and nothing changes.
2. Shows what will be replaced and asks you to confirm.
3. **Backs up the current database first**, as
   `before-restore-<date>-<time>.sqlite`, so the restore itself can be
   undone by restoring that file.
4. Replaces the database with the backup and checks it again.
5. If the backup is from an earlier version of the app, updates its tables
   (`php artisan migrate`).

Afterwards, refresh the app in your browser. You may need to log in again.

### Restoring by hand (if the commands cannot run)

1. Close the app in your browser.
2. Copy `database/database.sqlite` somewhere safe (this is your current
   data).
3. Delete `database/database.sqlite-wal` and `database/database.sqlite-shm`
   if they exist.
4. Copy the backup file to `database/database.sqlite`.
5. Run `php artisan migrate` and open the app.

---

## 5. Export records to CSV

Every list page has a **Download CSV** button that exports exactly what the
page's filters show:

| Page | File |
| --- | --- |
| Stock trades | `protracker-stock-trades-<date>.csv` |
| Positions (open lots) | `protracker-stock-lots-<date>.csv` |
| Options | `protracker-option-positions-<date>.csv` and `protracker-option-events-<date>.csv` |
| Dividends | `protracker-dividends-<date>.csv` |
| Cash | `protracker-cash-ledger-<date>.csv` (starts with the balance brought forward) |
| Realized P/L | one file each for stocks, options and dividends, with totals |
| Dashboard | `protracker-fees-<date>.csv` |

How values are written, in every file:

- Dates as `YYYY-MM-DD`.
- Money as plain numbers with two decimals and a minus sign for money paid
  out (`-4000.65`), without `$` or thousands separators, so spreadsheets
  read them as numbers.
- Prices per share with all their decimals (`26.1972`); shares without
  trailing zeros (`100`).
- Text that a spreadsheet would treat as a formula is prefixed with `'`.

Exports contain your records only — no passwords, keys or settings. Like
backups, they are private financial data: keep them out of shared folders.

---

## 6. Security notes

- Every page requires logging in. Public registration and emailed password
  resets are switched off; users are created with
  `php artisan app:create-user` and passwords changed with
  `php artisan app:reset-password`.
- Five wrong passwords in a minute block further attempts for that email
  for a minute.
- Session cookies are encrypted and cannot be read by page scripts. Forms
  are protected against cross-site request forgery.
- `.env` holds the app key and other secrets. It is excluded from Git; never
  share it. If it is ever exposed, generate a new key with
  `php artisan key:generate` (everyone is logged out) and change your
  password.
- `APP_DEBUG=true` in `.env` shows detailed error pages, which is useful on
  this computer only. It must be `false` anywhere other people could reach
  the app.

### If you ever want to reach Protracker from another device

This is **not set up**, and should not be done without planning. If you do:

- Use **HTTPS** only, and set `SESSION_SECURE_COOKIE=true` and
  `APP_DEBUG=false` in `.env`.
- Keep strong authentication: a long unique password (and, ideally, a
  private network such as a VPN or Tailscale in front of the app rather than
  opening it to the whole internet).
- **Never** expose the database file, the `storage` folder, or Laravel's
  development server (`php artisan serve`) directly to the internet.
