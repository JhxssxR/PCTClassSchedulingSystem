# PostgreSQL Migration Guide

## Step 1: Create PostgreSQL Database on Render

1. Go to **https://dashboard.render.com**
2. Click **+ New** → **PostgreSQL**
3. Fill in:
   - **Name**: `pctclass-db`
   - **Database**: `pctclass`
   - **User**: `pctclass_user`
   - **Region**: Same as your app (recommended)
4. Click **Create Database**
5. Wait for it to be ready (green checkmark)
6. Copy the **Connection String** (looks like: `postgresql://...`)

---

## Step 2: Export Data from MySQL (Already Done!)

You have: `buwyfvp2ejdwjgoxsbcw.sql`

---

## Step 3: Convert MySQL to PostgreSQL

The SQL dump needs conversion. Changes needed:
1. Remove MySQL-specific statements: `/*!40101...*/`
2. Convert `AUTO_INCREMENT` to `SERIAL`
3. Convert `COLLATE utf8mb4_general_ci` (not needed in PostgreSQL)
4. Convert `ENGINE=InnoDB` (not needed)
5. Convert `enum()` types to proper syntax

---

## Step 4: Update PHP Config

Change your `config/database.php`:

```php
// OLD (MySQL)
$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME');

// NEW (PostgreSQL)
$dsn = 'pgsql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME');
```

---

## Step 5: Set Render Environment Variables

In Render Dashboard → Settings → Environment Variables:

```
DB_HOST=<from PostgreSQL connection string>
DB_PORT=5432
DB_NAME=pctclass
DB_USER=pctclass_user
DB_PASSWORD=<from PostgreSQL connection string>
```

---

## Step 6: Deploy & Test

1. Commit config changes
2. Push to GitHub
3. Render auto-deploys
4. Visit admin dashboard
5. Check `/admin/performance_diagnostic.php`

---

## Conversion Will Be Done By:

I'll create a PostgreSQL-compatible SQL file and handle the import for you!

Just confirm Step 1 is complete (database created on Render), and I'll handle the rest! ✅
