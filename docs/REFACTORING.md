# Refactoring Audit

## Before

The main application is a PHP page-oriented system with feature-specific handlers and shared files under `includes/`. The largest application pages identified during the audit were:

- `dashboard-resident.php`: resident data loading, analytics, pets, reports, appointments, adoption, archive views, account settings, and embedded JavaScript.
- `dashboard-staff.php`: staff authentication, profile settings, client and pet data, appointments, reports, programs, analytics, modals, and embedded JavaScript.
- `adoption-pool.php`: adoption-pool data synchronization, filters, publishing actions, rescue intake, batch management, and UI state.
- `dashboard-barangay.php`: barangay dashboard data and UI.
- `availability.php`: schedule editing, date overrides, bulk operations, preview, and server synchronization.

The audit also confirmed that `includes/availability.php` is already an appropriate shared policy module used by the availability endpoints and slot checking code. It was left unchanged.

## Current Refactor

### `includes/appointments/`

Shared appointment and scheduling logic lives in this feature module:

- `availability.php`: clinic slot policy, appointment capacity, program staffing conflicts, and schedule lookups.
- `waitlist.php`: waitlist offers, expiry, notifications, and booking updates.

The previous `includes/availability.php` and `includes/waitlist.php` paths remain as thin compatibility loaders, so existing pages and endpoints continue to work without URL or call-site changes. No database schema, endpoint, or user flow was changed.

### `includes/reports/staff-data.php`

Owns the staff dashboard's report and confirmed-case SQL queries. The dashboard keeps the same result variables and row-to-UI transformations, so existing report views and interactions remain in place.

### `includes/programs/conflicts.php`

Owns program-related appointment conflict checks and report warnings. It uses the shared appointment availability/capacity policy without moving that policy out of the appointments module.

### `assets/js/availability-utils.js`

Owns pure availability presentation utilities:

- `generateTimeOptions`
- `timeValToLabel`
- `format12To24`
- `svgIcon`

`availability.php` loads this module before its existing controller and keeps the original controller call names through local aliases. No endpoints, DOM IDs, SQL, or user flows changed.

### `includes/staff-formatters.php`

Owns staff dashboard display formatters:

- Client and pet formatters: `cpdInitials`, `cpdTypeLabel`, `cpdGenderLabel`, `cpdAgeLabel`, `cpdVaxOk`
- Report formatters: `rptTypeLabel`, `rptReportStatus`, `rptStatusLabel`, `rptUrgencyLabel`

`dashboard-staff.php` loads this include once during bootstrap. The formatter implementations and all call sites remain unchanged.

## Application Folder Layout

Page implementations are grouped by audience under `pages/admin/`, `pages/staff/`, `pages/resident/`, `pages/barangay/`, and `pages/shared/`. Request handlers use the matching `api/` role folders. Root-level compatibility files continue to load the moved implementations so existing bookmarks, forms, AJAX requests, redirects, and email/SMS links retain their established URLs. Filesystem includes and upload paths were adjusted to the moved implementation locations; stored upload URL values remain unchanged.

## Outside the Move-and-Relink Scope

- `dashboard-resident.php`, `dashboard-staff.php`, `adoption-pool.php`, and `dashboard-barangay.php` remain cohesive page implementations under `pages/`; splitting their markup, server data preparation, and inline browser controllers into features is a separate change.
- `includes/availability.php` is already a focused shared module.
- Existing sidebars and notification polling files are already separated by responsibility.
- Generated assets, media, backup snapshots, PHPMailer, and dependency directories were excluded from the application refactor.

## Validation

- PHP syntax checks pass for `includes/staff-formatters.php`, `dashboard-staff.php`, and `availability.php`.
- `node --check assets/js/availability-utils.js` passes.
- `npm run build` passes.

The next recommended slices are the isolated JavaScript controllers in `availability.php`, then the staff dashboard's client/pet and reports data-loading blocks. Each slice should preserve existing endpoint names, DOM contracts, and PHP variable names before moving to the next feature.