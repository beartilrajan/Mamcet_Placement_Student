# Hostinger Deployment Guide - MAMCET Placement & Learning Portal

This guide outlines step-by-step instructions for deploying and configuring the MAMCET Placement & Learning Portal on **Hostinger Shared Hosting** using Hostinger's hPanel.

---

## 📋 Prerequisites
Before you start, make sure you have:
1. A Hostinger hosting plan (Shared, Cloud, or WordPress hosting).
2. A domain name pointed to your Hostinger account.

---

## 🛠️ Step 1: Create a MySQL Database on Hostinger

1. Log in to your **Hostinger hPanel**.
2. Navigate to **Databases** > **MySQL Databases**.
3. Create a new database using the exact credentials from your settings:
   - **MySQL Database name**: `u111341342_mamcet` (the prefix `u111341342_` is automatically appended by Hostinger).
   - **MySQL Username**: `u111341342_mamcet`.
   - **Password**: `F5coders@2025`.
4. Click **Create**. Note down the **MySQL Host** (usually `localhost` or `mysql.hostinger.com` - check the Hostinger page details).

---

## 📤 Step 2: Upload Project Files to Hostinger

There are two primary ways to upload your files:

### Method A: Using Hostinger File Manager (Recommended & Fastest)
1. In Hostinger hPanel, go to **Files** > **File Manager**.
2. Select your domain and enter the `public_html` directory (or directory of your choice).
3. Zip your local project directory (excluding heavy folders like `node_modules` or local log files if any).
4. Click the **Upload** button in the File Manager top bar, select your zip file, and upload it.
5. Right-click the uploaded zip file inside the hPanel File Manager and choose **Extract**. Extract the files directly to the root of `public_html`.
6. Delete the zip archive once extracted to clean up space.

### Method B: Using FTP (FileZilla)
1. Go to **Files** > **FTP Accounts** in hPanel to view your FTP credentials.
2. Open FileZilla, input Host, Username, Password, Port `21` and connect.
3. Drag and drop all local project files into the remote `public_html` directory.

---

## 🔒 Step 3: Configure Folder Permissions

Since students and officers will upload CVs, course thumbnails, and offer letters, you must make sure the upload directory has write permissions:
1. In **File Manager**, navigate to the `assets/` folder.
2. If `uploads/` folder is not there, it will automatically be created on the first upload. To set it up manually:
   - Create a folder named `uploads` inside `assets/`.
   - Inside `uploads/`, create five subdirectories: `resumes`, `thumbnails`, `attachments`, `offers`, and `certifications`.
3. Right-click the `uploads/` folder and choose **Permissions** (or **Chmod**).
4. Set the permissions value to `755` (or `775` if required by the server) and check the box **Apply recursively to all subdirectories**. Click **Update**.

---

## ⚙️ Step 4: Configure Database Settings

You can either run the installer web wizard or configure the database settings manually.

### Option A: Run the Web Installer (Recommended)
1. Open a browser and visit your site: `http://yourdomain.com/install.php` (replace with your actual domain).
2. The page checks extensions and folders structure. Click **Next**.
3. In the Database Settings form, input:
   - **Host**: `localhost` (Hostinger MySQL databases usually reside on `localhost`).
   - **Database Name**: `u111341342_mamcet`
   - **Username**: `u111341342_mamcet`
   - **Password**: `F5coders@2025`
   - **Port**: `3306`
4. Click **Test Connection**. Once successful, set up your Super Admin login and click **Run Setup**.
5. The installer will automatically run the schema and seed scripts and check the `data/` folder for any initial department spreadsheets.

### Option B: Manual Configuration
If you prefer to link the configurations manually without the installer page:
1. Navigate to the `config/` folder in File Manager.
2. Create a new file named `db_config.php` and copy the following configuration block into it:
   ```php
   <?php
   // MAMCET Placement & Learning Portal - Hostinger Database Settings
   return [
       'host' => 'localhost',
       'port' => '3306',
       'dbname' => 'u111341342_mamcet',
       'user' => 'u111341342_mamcet',
       'pass' => 'F5coders@2025',
       'charset' => 'utf8mb4'
   ];
   ```
3. Save the file.
4. Next, go to **Databases** > **phpMyAdmin** in hPanel, open `u111341342_mamcet`, click **Import**, select `database/schema.sql`, and upload. Repeat the import for `database/seed.sql` to populate default roles and configurations.

---

## 🎯 Verification
Once the setup is done:
1. Navigate to `http://yourdomain.com/index.php` to access the gateway.
2. Delete the `install.php` file from your server for security safety.
