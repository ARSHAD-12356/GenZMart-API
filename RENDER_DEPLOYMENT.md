# GenZMart API - Render Deployment Guide

This guide details how to build, test, and deploy the **GenZMart API** (Core PHP + MySQL backend) on **Render Web Services** using Docker & Apache.

---

## 📁 1. Files Added & Changed

| File | Status | Description |
| :--- | :--- | :--- |
| [`Dockerfile`](file:///c:/xampp/htdocs/GenZMart-API/Dockerfile) | **[NEW]** | Production Docker container definition (`php:8.2-apache`), enabling `pdo_mysql`, `mysqli`, `gd`, `mod_rewrite`, `mod_headers`, and dynamic `$PORT` binding. |
| [`.dockerignore`](file:///c:/xampp/htdocs/GenZMart-API/.dockerignore) | **[NEW]** | Prevents `.git`, local test files, schema dumps, and debug logs from inflating the container build image context. |
| [`health.php`](file:///c:/xampp/htdocs/GenZMart-API/health.php) | **[NEW]** | Lightweight root health check endpoint (`GET /health.php`) returning `HTTP 200 OK` JSON for Render HTTP health probes without database connection dependency. |
| [`config/config.php`](file:///c:/xampp/htdocs/GenZMart-API/config/config.php) | **[MODIFIED]** | Updated to load environment variables (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, etc.) via `getenv()` while preserving current fallbacks for local XAMPP execution. |
| [`RENDER_DEPLOYMENT.md`](file:///c:/xampp/htdocs/GenZMart-API/RENDER_DEPLOYMENT.md) | **[NEW]** | Comprehensive step-by-step Render deployment documentation. |

---

## 🛠️ 2. How to Build & Run the Docker Container Locally

### Prerequisites
- Install **Docker Desktop** on your machine.

### Build the Image
```bash
docker build -t genzmart-api .
```

### Run Container Locally
```bash
docker run -d -p 8080:80 --name genzmart-container genzmart-api
```

### Test Local Container
- **Health Check:** Open `http://localhost:8080/health.php`
- **Root API Index:** Open `http://localhost:8080/`
- **Database Health Check:** Open `http://localhost:8080/api/health/database.php`

### Stop & Cleanup Local Container
```bash
docker stop genzmart-container
docker rm genzmart-container
```

---

## 🔑 3. Required Render Environment Variables

Set these environment variables in your **Render Web Service Dashboard** under **Environment Settings**:

| Variable Name | Required | Example / Recommended Value | Description |
| :--- | :--- | :--- | :--- |
| `DB_HOST` | **Yes** | `your-db-host.aivencloud.com` | Hostname/IP of your remote MySQL database. |
| `DB_PORT` | No | `3306` | Port of your MySQL database (default: `3306`). |
| `DB_NAME` | **Yes** | `genzmart_db` | Name of your MySQL database. |
| `DB_USER` | **Yes** | `genzmart_user` | MySQL database username. |
| `DB_PASS` | **Yes** | `secure_db_password` | MySQL database password. |
| `DB_CHARSET` | No | `utf8mb4` | Character encoding (default: `utf8mb4`). |
| `APP_ENV` | No | `production` | Application environment identifier. |
| `APP_BASE_URL` | **Yes** | `https://genzmart-api.onrender.com` | Your deployed Render Web Service URL. |
| `UPLOADS_URL` | No | `https://genzmart-api.onrender.com/uploads/` | Base URL for uploaded images/media. |
| `CORS_ALLOWED_ORIGINS` | **Yes** | `https://genzmart.vercel.app,http://localhost:3000` | Comma-separated list of allowed frontend origins. |

---

## 🚀 4. Render Deployment Requirements & Setup

1. **Connect GitHub / GitLab Repository**:
   - Push your updated code to your Git repository.
   - Go to [Render Dashboard](https://dashboard.render.com/) -> **New** -> **Web Service**.
   - Connect your repository.

2. **Configure Web Service Settings**:
   - **Name**: `genzmart-api` (or preferred name)
   - **Language / Environment**: `Docker`
   - **Region**: Choose the region closest to your users / database.
   - **Branch**: `main` (or active branch)
   - **Dockerfile Path**: `./Dockerfile`
   - **Health Check Path**: `/health.php`

3. **Add Environment Variables**:
   - Enter all required environment variables listed in Section 3 above.

4. **Deploy**:
   - Click **Create Web Service**. Render will automatically build the Docker image and start the service.

---

## 🧪 5. How to Test the Deployed API

After deployment finishes, test the endpoints using your Render URL (e.g. `https://genzmart-api.onrender.com`):

1. **Health Check Endpoint**:
   ```bash
   curl -i https://genzmart-api.onrender.com/health.php
   ```
   *Expected Response:* `HTTP 200 OK` JSON `{ "status": "OK", "service": "GenZMart API", ... }`

2. **Root API Index**:
   ```bash
   curl -i https://genzmart-api.onrender.com/
   ```
   *Expected Response:* `HTTP 200 OK` JSON detailing API version and available endpoints.

3. **Authentication Endpoints**:
   - `POST https://genzmart-api.onrender.com/api/auth/login.php`
   - `POST https://genzmart-api.onrender.com/api/auth/register.php`

---

## 🗄️ 6. How to Verify the Database Connection Later

Once your MySQL database is set up and environment variables are populated on Render:

1. **Invoke Database Health Endpoint**:
   ```bash
   curl -i https://genzmart-api.onrender.com/api/health/database.php
   ```
   *Expected Response (When Connected):*
   ```json
   {
       "success": true,
       "database_connected": true,
       "database": "genzmart_db",
       "message": "Database connection successful",
       "details": {
           "host": "...",
           "port": 3306,
           "charset": "utf8mb4",
           "tables_count": 15,
           "server_version": "8.0.33"
       }
   }
   ```

2. **Troubleshooting Connection Issues**:
   - If `database_connected` is `false`, check your Render logs (**Logs** tab in Render Dashboard).
   - Ensure the database host is accessible externally and firewall rules allow incoming traffic from `0.0.0.0/0`.
   - Verify `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` environment variables in Render Dashboard.
