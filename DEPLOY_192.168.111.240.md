# Deploy to 192.168.111.240

## Current release

- Version: `v2026.06.15.6`
- Repository: `https://github.com/pharmacisttom/PDHTELEMED.git`

## Pre-deploy checklist

1. Confirm latest code is pushed to GitHub.
2. Backup current application directory on server.
3. Confirm MySQL access from server:
   - App DB: local MySQL on `192.168.111.240`
   - HIS DB: `192.168.111.251`
4. Confirm PHP can write session files and temporary files.
5. Confirm required tables already exist:
   - `telemed_delivery`
   - `telemed_tracking`
   - `telemed_patient_status`
   - `role_permissions`

## Deploy steps on server

1. Open project path on server, for example:
   - `D:\xampp\htdocs\pdhtelemed`
2. Pull latest code:
   - `git pull origin main`
3. If this is first install:
   - copy or clone project into XAMPP htdocs
4. Verify important PHP pages:
   - `php -l config.php`
   - `php -l dashboard.php`
   - `php -l clinic_dashboard.php`
   - `php -l clinic_manage.php`
   - `php -l tracking.php`
   - `php -l service_quality.php`
5. Open the system in browser and verify:
   - Login page shows latest version
   - Sidebar shows latest version
   - Dashboard loads
   - Clinic statistics loads
   - Tracking loads
   - Service quality loads and clinic filter works

## Notes

- `config.php` already switches APP DB connection automatically when running on `192.168.111.240`.
- Do not upload session files, `logs/`, or `tmp_sessions/`.
- If a new clinic appears in HIS, sidebar clinic list now updates automatically from live data.
