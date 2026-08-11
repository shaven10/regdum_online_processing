# Registrars Office — Online Documents Processing System

A PHP/MySQL web application for managing online credential and document requests for educational institutions. Built for XAMPP.

## Features

### Student
- User registration, login, and password reset
- **Profile management** with completion tracking (must reach 100% before submitting requests)
- **Dynamic academic information** based on enrollment status:
  - **Enrolled** — current course/program, year level, current academic year, current semester, origin campus
  - **Graduated** — course/program, year graduated, origin campus, employment information
  - **Inactive** — last course/program attended, last school year enrolled, origin campus
- **New credential request** with:
  - Multi-document checklist (select one or more document types with descriptions)
  - Per-document copy count (1–10)
  - Live **payment breakdown** with line-item fees and estimated total
  - Shared purpose and notes across selected documents (each document creates a separate request)
- **Requirements upload** after the Registrar assigns them (no supporting documents on initial submission)
- **Payment submission** with receipt upload after requirements are approved
- **Pickup option** (on-site student pickup or authorized representative) — available **only after the Cashier verifies payment**
- Real-time request tracking with 6-step progress panel
- Claim stub printing, pickup confirmation, and feedback
- In-app notifications
- Document verification via QR/code

### Cashier
- Dashboard and payment queue
- Verify or reject student payment submissions
- Payment reports

### Accounting
- Process **Statement of Account (SOA)** document assignments only
- Mark SOA ready for pickup / completed

### Registrar
- Review submitted requests and assign requirement checklists
- Approve requirements for payment or send back for revision
- Online clearance coordination
- Assign processing staff and set on-site release schedule (after student selects pickup option)
- Compliance tracking and attachment review
- Claim stub generation

### Staff
- Process assigned credential requests
- Mark documents ready for pickup
- Student record lookup

### Clearance Officer
- Sign off on online clearance items for assigned requests

### Administrator
- Dashboard with statistics
- Request and payment oversight
- **Settings:** users, document types, **courses & programs**, **campuses**, requirement defaults
- Student records, reports (CSV export), audit logs

### Security
- Role-based access control (Student, Staff, Registrar, Admin, Cashier, Accounting, Clearance Officer)
- Bcrypt password hashing
- CSRF protection on forms
- Audit logging
- Session timeout (30 minutes)
- Secure file upload validation

## Requirements

- XAMPP (Apache + MySQL/MariaDB + PHP 8.0+)
- PHP extensions: PDO, pdo_mysql, mbstring, json

## Installation

### Fresh install

1. **Copy the project** to your XAMPP `htdocs` folder:
   ```
   C:\xampp3\htdocs\regdum_ol_docs_prcsng
   ```

2. **Start XAMPP** — Apache and MySQL.

3. **Run the installer** (recommended):
   ```
   http://localhost/regdum_ol_docs_prcsng/install.php?step=run
   ```
   This creates the database, imports the schema, runs migrations, and seeds default accounts.

   **Alternatively**, import manually via phpMyAdmin:
   ```
   database/schema.sql
   ```
   Then run:
   ```
   http://localhost/regdum_ol_docs_prcsng/install.php?step=upgrade
   ```

4. **Configure** (if needed) — edit `config/database.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'regdum_credentials');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

5. **Set APP_URL** in `config/app.php` if your path differs:
   ```php
   define('APP_URL', 'http://localhost/regdum_ol_docs_prcsng');
   ```

6. **Access the application**:
   ```
   http://localhost/regdum_ol_docs_prcsng
   ```

### Upgrade existing database

After pulling updates, run migrations once:
```
http://localhost/regdum_ol_docs_prcsng/install.php?step=upgrade
```

Delete `install.php` in production after setup.

## Default Accounts

| Role       | Email                     | Password      |
|------------|---------------------------|---------------|
| Admin      | admin@regdum.edu.ph       | Admin@123     |
| Staff      | staff@regdum.edu.ph       | Staff@123     |
| Registrar  | registrar@regdum.edu.ph   | Registrar@123 |
| Cashier    | cashier@regdum.edu.ph     | Cashier@123   |
| Accounting | accounting@regdum.edu.ph  | Accounting@123 |

Students register via the registration page.

## Project Structure

```
regdum_ol_docs_prcsng/
├── admin/              # Administrator module (users, reports, programs, campuses)
├── accounting/         # Accounting module (SOA document assignments only)
├── auth/               # Login, register, password reset
├── cashier/            # Payment verification
├── clearance/          # Clearance officer module
├── config/             # App and database configuration
├── database/           # SQL schema and migrations
├── includes/           # Shared PHP (auth, compliance, student, payments, UI)
├── registrar/          # Registrar compliance and verification
├── staff/              # Staff processing module
├── student/            # Student dashboard, profile, requests, payment
├── assets/             # CSS and JavaScript
├── uploads/            # Uploaded files (documents, receipts)
├── install.php         # Fresh install and database upgrade
├── index.php           # Landing page
├── verify.php          # Public document verification
├── faq.php             # FAQ
└── help.php            # Help desk
```

## Request Workflow

Students see a **6-step progress tracker**:

| Step | Label                  | What happens |
|------|------------------------|--------------|
| 1    | Request Submitted      | Student selects documents, purpose, and copies; Registrar reviews |
| 2    | Requirements Set       | Registrar assigns requirements; student uploads documents |
| 3    | Requirements Approved  | Registrar verifies submissions (revision loop if needed) |
| 4    | Payment                | Student pays; **Cashier verifies** payment |
| 5    | Document Processing    | Registrar assigns staff; student selects pickup option; document is prepared |
| 6    | Document Release       | Document is ready for on-site pickup and transaction completion |

### Status flow (internal)

```
submitted → under_review → awaiting_requirements → requirements_submitted
    → requirements_verified → payment_verified → processing → ready_for_pickup → completed
```

Side paths: `needs_revision`, `rejected`

### Key business rules

- Profile must be **100% complete** before submitting a request
- **Multiple documents** in one form create **separate requests** (one request number each)
- **No file uploads** on initial request — uploads happen when requirements are assigned
- **Pickup option** is chosen on the request detail page **after the Registrar assigns staff for processing**
- Documents are released through **on-site pickup** only (student or authorized representative)
- Fee = base fee + (per-copy fee × extra copies) per document

## Available Document Types

Default types (admin-configurable): TOR, Diploma, COE, COG, Good Moral Certificate, CTC, Other Academic Records.

Admin can manage document types, fees, processing days, and requirement defaults under **Settings**.

## Payment Methods

- GCash / PayMaya
- Credit / Debit Card
- Bank Transfer (receipt upload supported)

Payments require **Cashier verification** before processing continues.

## Admin Settings

| Setting              | Purpose |
|----------------------|---------|
| Document Types       | Fees, processing days, requirement flags |
| Courses & Programs   | Dropdown options for student academic profiles |
| Campuses             | Origin campus options (Main, North, South by default) |
| Requirement Defaults | Auto-assign requirements per document type |
| Release Rules        | Allowed credentials and max copies per enrollment status |

## Production Notes

- Configure SMTP in `config/app.php` for email notifications
- Integrate PayMongo/GCash API for live payment processing
- Add TCPDF or DomPDF for PDF document generation
- Enable HTTPS
- Change all default passwords immediately
- Remove or restrict access to `install.php` and migration scripts
- Set up automated database backups

## License

For educational and institutional use.
