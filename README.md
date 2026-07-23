# MAMCET Placement & Learning Portal (Stage 1)

A complete, high-fidelity, and production-ready college placement management system and Learning Management System (LMS) designed for final-year students, placement officers, and administrators of **MAMCET (M.A.M. College of Engineering and Technology)**.

---

## 🚀 Key Features

### 1. Unified Automated Installer
- **Self-Diagnostic Installer**: Accessible via `install.php`. Verifies PHP version, checks database extensions (PDO), ensures folder write access, and allows dynamic schema generation.
- **Auto-Detection & Excel Scanning**: Scans files inside the `data/` folder, detects department templates, maps raw data, and populates student academic profiles automatically.

### 2. Administrator & Placement Officer Suite
- **Interactive Dashboards**: Features numeric metrics widgets and Chart.js graphical distributions (for student categories, CGPA classifications, and placement statuses).
- **Interactive Roster**: Leverages DataTables with multi-column filters (by department, batch, CGPA, arrears status).
- **Syllabus Planner**: Handles modular LMS structures, YouTube lessons validation, and pdf note attachments.
- **Placement Campaigns Manager**: Creates job openings, sets target restrictions, uploads JD documents, and embeds registration forms.
- **Roster Exporter**: Custom query center filtering on parameters (including CGPA thresholds, backlogs, locations, and skills) to generate CSV report sheets.

### 3. Student Hub
- **Learning Hub**: Displays active training paths, embeds responsive YouTube video players, provides note downloads, and tracks lesson progress.
- **Milestones Scorecard**: Tracks profile completion gauges, standing backlogs warnings, and lists completed chapters.
- **Portfolio & Profile Customization**: Locks institutional variables (CGPA, arrears counts) while enabling students to upload resumes, declare willing status, and add links (LinkedIn, GitHub).
- **Placement Dashboard**: Lists campaigns, checks eligibility rules (CGPA, backlogs, and willingness), and displays apply controls.

---

## 🛠️ Technology Stack

- **Backend**: PHP 8.0+, MySQL (PDO driver)
- **Frontend**: HTML5, CSS3, Vanilla JavaScript, Bootstrap 5, Bootstrap Icons, Font Awesome 6
- **Asset Plugins**: DataTables, SweetAlert2, Chart.js, jQuery
- **Excel Processor**: PHP native `ZipArchive` & XML reader (with `PhpSpreadsheet` fallback)

---

## 📂 Project Architecture

```text
├── admin/                     # Admin & Officer screens
│   ├── dashboard.php          # Visual graphs and metrics
│   ├── students.php           # DataTables roster list
│   ├── student-view.php       # Detail profile inspection & timeline
│   ├── announcements.php      # Placements drive manager
│   ├── courses.php            # LMS courses roster
│   ├── course-builder.php     # Modules & YouTube lessons manager
│   ├── reports.php            # Custom query reporter and CSV exporter
│   ├── settings.php           # Global criteria settings
│   ├── dataset-manager.php    # Scans Excel sheets inside data/
│   └── import-preview.php     # Excel columns mappings preview
├── api/                       # API router endpoints
│   ├── change-session.php     # Active academic year toggles
│   ├── scan-datasets.php      # Scans data/ directories
│   ├── validate-dataset.php   # Pre-check mappings and reports errors
│   ├── import-excel.php       # Processes database insertions
│   ├── update-profile.php     # Student profile updates handler
│   └── mark-lesson-complete.php # LMS completion updates
├── assets/                    # Asset folders
│   ├── css/
│   │   └── style.css          # Core custom stylesheets (premium themes)
│   └── uploads/               # Writeable directories
│       ├── resumes/           # Uploaded PDF resumes
│       ├── thumbnails/        # LMS course images
│       └── attachments/       # Job description files
├── auth/                      # Authentication routes
│   ├── student-login.php      # Student login
│   ├── officer-login.php      # Admin login
│   ├── first-login.php        # First-time account activations
│   ├── reset-password.php     # Password recoveries
│   └── logout.php             # Session destructions
├── config/                    # Config settings
│   ├── database.php           # PDO connection wrapper
│   ├── app.php                # Error reporting & upload limits
│   └── constants.php          # Category identifiers and maps
├── data/                      # Folder containing raw Excel datasets
├── database/                  # SQL scripts
│   ├── schema.sql             # Relational schemas
│   └── seed.sql               # Baseline system seeds
├── includes/                  # Global templates
│   ├── header.php             # Site header & session selector
│   ├── sidebar.php            # Side navigation layout
│   ├── footer.php             # Core libraries bundle
│   ├── auth.php               # Gatekeepers and permissions helper
│   ├── csrf.php               # Anti-CSRF protection tokens
│   ├── functions.php          # XSS sanitization and logs audits
│   └── excel-helper.php       # Excel & CSV data extractor
├── index.php                  # Landing gate page
├── install.php                # Diagnostic setup wizard
└── composer.json              # Advanced Excel composer configurations
```

---

## ⚙️ Installation & Setup

1. **Prerequisites**: Ensure you have a running local server stack like **XAMPP**, **WAMP**, or **Laragon** (configured with PHP 8.0+ and MySQL).
2. **Move Codebase**: Place the project directory inside the document root (e.g. `C:\xampp\htdocs\mamcet-portal\`).
3. **Database Setup**: Open phpMyAdmin or your terminal and create a new database:
   ```sql
   CREATE DATABASE mamcet_placement_db;
   ```
4. **Trigger Installer**: Open your web browser and navigate to:
   ```text
   http://localhost/mamcet-portal/install.php
   ```
5. **Run Setup**:
   - Verify environment compatibility checks.
   - Supply database credentials (e.g. Host: `localhost`, Name: `mamcet_placement_db`, User: `root`, Password: ``).
   - Create a Super Admin account credentials.
   - Run dataset scanner: The system will read files inside `data/`, load initial departments, batches, sections, and populate student records and academic grades.
6. **Deploy**: Start using the application gateway at `index.php`.

---

## 🛡️ Security Profiles

- **SQL Injection Prevention**: All database commands utilize PDO prepared statements with parameter binds.
- **CSRF Mitigations**: All form submittals require a verification token (`csrf_token`) matched inside the active session.
- **Cross-Site Scripting (XSS) Prevention**: All printed variables are processed through the `esc()` function utilizing `htmlspecialchars()`.
- **Session Protections**: Implements `session_regenerate_id(true)` to avoid session fixation vectors. Resumes directories are placed in dynamic paths.
