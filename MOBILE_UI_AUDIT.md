# Mobile UI and Dark Mode Audit

## Scope and protection

The repository is a PHP/HTML/CSS/JavaScript platform with separate public, student, instructor, administrator, student-detail, certificate-verification, and offline-center views. Public and dashboard data is supplied by existing API calls. This work is restricted to frontend presentation and preserves those calls and their contracts.

Protected from modification: `api/`, `auth/`, `database/`, SQL/migration files, PHP business logic, authentication/session files, uploads, and existing field names/event handlers used by the UI.

## Frontend entry points reviewed

| View | Entry point | Existing responsive/theme layers |
| --- | --- | --- |
| Public home, catalog, details, cart, checkout, contact | `index.html` | `responsive.css`, `home-apple.css`, `mobile-ux.css`, `landing-dark.css`, theme and appearance styles |
| Administrator portal and settings | `admin.html` | `responsive.css`, `mobile-ux.css`, `admin.css`, theme and appearance styles |
| Student portal | `student.html` | `responsive.css`, `mobile-ux.css`, `student.css`, unified dashboard and appearance styles |
| Instructor portal | `instructor.html` | `responsive.css`, `mobile-ux.css`, instructor and assessment styles |
| Student details | `student-details.html` | `responsive.css`, detail styles, existing local theme toggle |
| Certificate verification | `verify-certificate.html` | responsive base; page-specific inline presentation |
| Login | `login.html` redirects to `auth/login.php`; authentication view is protected and was not changed |

## Findings and frontend work

- The existing public and dashboard pages already had responsive rules, but they were spread across page-specific stylesheets and did not cover the standalone certificate page consistently.
- Dark-mode styles were present in the main portals; high-specificity light theme rules could still leave homepage sections and cards light. A final shared presentation layer now normalizes dark surfaces, text, controls, tables, modals, navigation surfaces, and footer colors.
- Mobile safe-area spacing and modal/table bounds are normalized in the shared layer. Existing menu and responsive-table JavaScript is preserved.
- Arabic homepage copy is right-aligned across the hero, section headings, course/book cards, features, and instructor cards; English copy remains left-aligned. Hero type scale and responsive widths are controlled on desktop and mobile.

## Protected files

No API, authentication, database, migration, business-logic, or upload files were modified. No data was replaced with mock records.

## Validation status

Static checks completed: each listed page resolves its local asset references; all six pages have exactly one viewport declaration with safe-area coverage where needed; the new shared stylesheet and homepage stylesheet have balanced rule braces. The homepage was manually reloaded and inspected in the existing Edge mobile preview (812 × 375). A follow-up CSS alignment change was checked with static brace and page-reference checks; the active Edge preview currently exposes captured phone frames rather than a directly inspectable viewport.

Automated device-matrix tests and interactive regression checks across all pages have not been run: this checkout has no Playwright/Puppeteer test dependency, and the preview session only exposed a single mobile viewport. No result is claimed for the remaining requested sizes or role flows.
