# Render Deployment Guide - PCT Class Scheduling System

## Overview
This guide walks you through deploying the PCT Class Scheduling System on Render with a MySQL database.

## Prerequisites
- A [Render.com](https://render.com) account
- Repository access to your GitHub project
- The deployment branch checked out (agents-deploy-system-on-render)

## Step 1: Push Changes to GitHub

First, commit and push the deployment configuration:

```bash
git add render.yaml build.sh config/database.php
git commit -m "Configure Render deployment with environment variables"
git push origin agents-deploy-system-on-render
```

## Step 2: Create a New Render Service

1. Go to [https://dashboard.render.com](https://dashboard.render.com)
2. Click **"New +"** → **"Web Service"**
3. Connect your GitHub repository (JhxssxR/PCTClassSchedulingSystem)
4. Select the **agents-deploy-system-on-render** branch
5. Configure the service:
   - **Name:** pct-class-scheduling
   - **Root Directory:** Leave blank (or set to root)
   - **Runtime:** PHP (auto-detected)
   - **Build Command:** bash ./build.sh
   - **Start Command:** Leave as default
   - **Plan:** Standard ($7/month) or Free tier if available

## Step 3: Add Environment Variables

The `render.yaml` file automatically configures these. However, if you need manual configuration:

| Variable | Value |
|----------|-------|
| DB_HOST | Set by mysql service |
| DB_PORT | Set by mysql service |
| DB_NAME | class_scheduling |
| DB_USER | Set by mysql service |
| DB_PASS | Set by mysql service |
| APP_URL | $RENDER_EXTERNAL_URL |
| APP_ENV | production |

## Step 4: Deploy MySQL Database

The `render.yaml` includes a MySQL service configuration:

1. Click **"Create"** to deploy the web service
2. Render will automatically:
   - Create the MySQL database (class_scheduling)
   - Link it to your web service
   - Configure environment variables automatically

## Step 5: Verify Deployment

After deployment completes:

1. Visit your service URL (shown in Render dashboard)
2. Try logging in with default credentials:
   - **Username:** admin
   - **Password:** admin123
   - OR
   - **Username:** registrar
   - **Password:** registrar123

## Step 6: Configure Application URL

In the application settings/database, the `APP_URL` environment variable should be set to your Render URL for proper redirects.

## Troubleshooting

### Database Connection Issues
- Check the database credentials in Render dashboard
- Verify environment variables are correctly linked
- Check error logs in Render dashboard under "Logs"

### PHP Extensions Missing
- The `build.sh` script installs `php-mysql`
- If you need additional extensions, update `build.sh`

### Session/File Permissions
- Ensure the `logs/` directory exists and is writable
- The `build.sh` sets proper permissions automatically

### Static Files Not Loading
- Make sure the web root is correctly set
- Verify asset paths in `config/database.php` are using `app_url()` function

## Manual Configuration Alternative

If you prefer not to use `render.yaml`, you can manually:

1. Create a Web Service with PHP runtime
2. Set these environment variables manually:
   ```
   DB_HOST=mysql-service-host
   DB_NAME=class_scheduling
   DB_USER=your-user
   DB_PASS=your-password
   APP_ENV=production
   APP_URL=https://your-render-url
   ```
3. Create a MySQL service
4. Set start command to your PHP built-in server or leave default

## Post-Deployment Checklist

- [ ] Database is accessible
- [ ] Default users created successfully
- [ ] Login page loads
- [ ] Can log in with admin/registrar credentials
- [ ] Dashboard displays for logged-in users
- [ ] Logs directory has write permissions
- [ ] Email notifications configured (if needed)

## Next Steps

1. **Backup Database:** Set up regular backups in Render
2. **Configure Email:** Update notification settings for production
3. **SSL/HTTPS:** Render provides free SSL automatically
4. **Custom Domain:** Add your school's domain in Render settings
5. **Monitoring:** Enable error tracking and monitoring

## Support

For issues:
- Check Render documentation: https://render.com/docs
- Review application error logs in `logs/php_errors.log`
- Check Render dashboard logs

## Rollback Procedure

If you need to rollback:
1. Go to Render dashboard
2. View deployment history
3. Click a previous deployment to restore
4. Verify database integrity before going live

---

**Last Updated:** 2026-05-29
**Deployment Branch:** agents-deploy-system-on-render
