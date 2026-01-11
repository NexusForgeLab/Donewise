# Group List (PHP + SQLite)

Same app as before, but using SQLite (single file database).

## Run
```bash
docker compose up -d --build
```

Open:
- http://YOUR_SERVER_IP:8088

## One-time install (create tables)
Open once:
- http://YOUR_SERVER_IP:8088/install.php

Then delete install.php.

## Where is the database?
By default:
- ./data/app.db  (mounted into container)

If you get a "unable to open database file" error:
- Make sure ./data folder exists and is writable.

docker exec -it Donewise mkdir -p /var/www/html/uploads
docker exec -it Donewise chown -R www-data:www-data /var/www/html/uploads
Donewise chmod -R 775 /var/www/html/uploads