# Donewise - Collaborative Shopping List & Task Manager

**Donewise** is a self-hosted, web-based application for managing shopping lists, daily tasks, and recurring chores. It features real-time updates, group collaboration, file attachments, and a mobile-friendly interface (PWA).

Built with **PHP** and **SQLite**, it is lightweight and easy to deploy using Docker.

![Donewise Logo](assets/icon-512.png)

## Features

* **Group Collaboration:** Create groups (e.g., Family, Office) and invite members via link.
* **Real-time Updates:** Instant notifications and list updates using Server-Sent Events (SSE).
* **Smart Lists:** Auto-suggestions based on history and hashtag support (e.g., `Milk #grocery #urgent`).
* **Recurring Tasks:** Set items to reappear every X days or on specific days of the week.
* **Attachments:** Upload images or documents to specific tasks.
* **Mobile Ready:** Installable as a Progressive Web App (PWA) on iOS and Android.
* **Dark Mode/Theming:** Clean, notebook-style UI.

---

## Prerequisites

* **Docker** and **Docker Compose** installed on your machine.
* (Optional) A reverse proxy (like Nginx or Traefik) if you plan to expose this to the internet with SSL.

---

## 🚀 Quick Start (Docker)

### 1. Project Setup

Create a folder for your project and place the source code inside it. Ensure the directory structure looks like this:

```text
/shopping-list-app
  ├── app/
  ├── api/
  ├── assets/
  ├── data/          <-- Will be created automatically
  ├── sql/
  ├── uploads/       <-- Will be created automatically
  ├── docker-compose.yml
  ├── Dockerfile
  └── ... (other php files)

```

### 2. Build and Run

Open your terminal in the project root and run:

```bash
docker compose up -d --build

```

### 3. Permissions Setup (Crucial)

SQLite requires write permissions on the directory where the database file resides. Run the following commands to ensure the web server (`www-data`) can write to the data and upload folders:

```bash
# Fix permissions for the data folder
docker exec -it Donewise chown -R www-data:www-data /var/www/html/data
docker exec -it Donewise chmod -R 775 /var/www/html/data

# Fix permissions for the uploads folder
docker exec -it Donewise mkdir -p /var/www/html/uploads
docker exec -it Donewise chown -R www-data:www-data /var/www/html/uploads
docker exec -it Donewise chmod -R 775 /var/www/html/uploads

```

### 4. Database Initialization

1. Open your browser and navigate to: `http://localhost:8088/install.php`
* You should see a "✅ Installed" message.


2. **Apply Updates:** To ensure all features (Tags, Attachments, Recurring Tasks) work, visit these URLs in order once:
* `http://localhost:8088/update_db_v4.php` (Adds created_by to groups)
* `http://localhost:8088/update_db_v5.php` (Adds tags table)
* `http://localhost:8088/update_db_v6.php` (Adds attachments table)
* `http://localhost:8088/update_recurring.php` (Adds recurring tasks)



### 5. Create Your First Group

* Go to `http://localhost:8088/register.php`.
* Create a Group Name (e.g., "Home"), Username, and Password.
* You are now logged in!

---

## ⚙️ Configuration

The application uses environment variables defined in `docker-compose.yml`.

| Variable | Default | Description |
| --- | --- | --- |
| `APP_URL` | `http://localhost:8088` | **Change this** to your actual URL or `http://localhost:8088`. Used for invite links. |
| `PHP_TZ` | `Asia/Kolkata` | Sets the timezone for timestamps. Change to your local TZ (e.g., `America/New_York`). |
| `SQLITE_PATH` | `/var/www/html/data/app.db` | Internal path to the database file. |

To change these, edit `docker-compose.yml` and restart the container:

```bash
docker compose down && docker compose up -d

```

---

## 📖 Usage Guide

### Managing Items

* **Add Item:** Type in the main input box.
* **Tags:** Use hashtags to categorize (e.g., `Carrots #veg`).
* **Urgent:** Adding `#urgent` usually highlights the item or bumps priority.


* **Edit:** Click the pencil icon on an item to change text or move it to a different date.
* **Complete:** Click "Done". It moves to the bottom.
* **Undo:** Click "Undo" on a completed item to bring it back.

### Recurring Tasks

1. Click **Recurring** in the top navigation.
2. Add a rule (e.g., "Pay Rent" every "30 days" OR every "Friday").
3. The system checks these rules every time you load a page and automatically adds the task to your "Today" list when due.

### Group Settings & Invites

1. Click **Group** in the top navigation.
2. Copy the **Invite Link** and send it to family members.
3. They can join instantly without creating a separate group.
4. **Switching Groups:** If you belong to multiple groups (e.g., Family and Work), click your name in the top right to switch context.

---

## 🛠 Troubleshooting

**Error: "General error: 14 unable to open database file"**
This is a permission issue. The web server cannot write to the `data/` folder.

* **Solution:** Run the permission commands listed in Step 3 of the "Quick Start" section.

**Images/Attachments not uploading**

* Ensure the `uploads/` folder exists and has write permissions.
* Check the `php.ini` settings. The included Dockerfile sets `upload_max_filesize` to 20M.

**Time is wrong on tasks**

* Update the `PHP_TZ` variable in your `docker-compose.yml` file to match your location.

**Database Updates**

* If you see errors about "no such table: attachments" or "tags", ensure you have visited the `update_*.php` files listed in the installation steps.

---

## 🔒 Security Note

* **Delete Install Files:** After successfully setting up, you should delete `install.php` and the `update_*.php` files from your server to prevent unauthorized database resets.
```bash
docker exec -it Donewise rm /var/www/html/install.php

```


* **SSL:** It is highly recommended to run this behind a reverse proxy with HTTPS (like Nginx Proxy Manager) if accessing over the internet.
