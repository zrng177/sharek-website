# Database Migrations

This directory contains SQL migration files for the Sharek application.

## Current Schema

The base database schema is in `sharek_db.sql` at the repository root (now v1.6).

**For a fresh install**: run `sharek_db.sql` directly — it now includes all columns, ENUM types, CHECK constraints, and composite indexes.

**For an existing database**: run migrations in sequence (see below).

---

## Available Migrations

| File | Purpose | Priority |
|---|---|---|
| `001_fix_schema_missing_columns.sql` | Adds 5 missing columns (`user_ref_id`, `email_verified`, `vehicle_status`, `car_model`, `car_color`) | 🔴 CRITICAL |
| `002_add_composite_indexes.sql` | Adds 8 composite/covering indexes; removes redundant ones | 🟠 HIGH |
| `003_add_enum_and_check_constraints.sql` | Converts status columns to ENUM; adds CHECK constraints | 🟠 HIGH |

---

## Running Migrations (Existing Database)

> ⚠️ **Always backup your database before running migrations.**

### Option 1: phpMyAdmin
1. Open phpMyAdmin → select `sharek_db`
2. Click the **SQL** tab
3. Paste and run each migration file **in order**: 001 → 002 → 003

### Option 2: MySQL CLI
```bash
mysql -u your_username -p sharek_db < migrations/001_fix_schema_missing_columns.sql
mysql -u your_username -p sharek_db < migrations/002_add_composite_indexes.sql
mysql -u your_username -p sharek_db < migrations/003_add_enum_and_check_constraints.sql
```

### Option 3: XAMPP (local development)
```bash
cd C:\xampp\mysql\bin
mysql.exe -u root sharek_db < C:\xampp\htdocs\sharek_fixed\migrations\001_fix_schema_missing_columns.sql
mysql.exe -u root sharek_db < C:\xampp\htdocs\sharek_fixed\migrations\002_add_composite_indexes.sql
mysql.exe -u root sharek_db < C:\xampp\htdocs\sharek_fixed\migrations\003_add_enum_and_check_constraints.sql
```

All migration files use `IF NOT EXISTS` / `IF EXISTS` guards and are **safe to re-run**.

---

## Running Initial Installation (Fresh Database)

### Option 1: Web Installer
```
https://yourdomain.com/install_db.php?confirm=yes_i_know
```

### Option 2: Direct SQL
```bash
mysql -u your_username -p your_database < sharek_db.sql
```

### Option 3: phpMyAdmin
1. Open phpMyAdmin → select your database → SQL tab
2. Paste `sharek_db.sql` contents → click Go

---

## Future Migrations

Name new files sequentially: `004_feature_name.sql`, etc.

## Important Notes

- Always backup before running migrations
- Test on staging first
- Run in sequential order
- Delete `install_db.php` from production after successful installation

