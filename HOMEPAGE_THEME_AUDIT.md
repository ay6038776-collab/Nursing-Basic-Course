# Homepage Theme Systems — Frontend Audit

Audit date: 2026-11-20

## Architecture and entry points

- The production project root uses plain HTML, CSS, and browser JavaScript for its public site and the student, instructor, and administrator portals. A separate `edu-nursing/` directory contains a Next.js application, but the current public homepage is `index.html` and is initialized by `asset/js/main.js`.
- Public navigation is hash based (`#home`, `#courses`, `#books`, and other routes); `navigate()` and `renderAll()` in `asset/js/main.js` own existing SPA routing and rendering.
- Homepage real data is fetched by existing public API calls in `asset/js/main.js` and existing modules. Examples include `api/public/landing.php`, `api/public/home-slider.php`, `api/public/founder-photos.php`, and the existing courses, books, centers, and reviews sources. These contracts must remain unchanged.
- The homepage DOM and all homepage content sections live in `index.html`. The current layout includes the navigation, hero and uploaded hero slider, statistics, features, featured courses, books, offline centers, offers, instructors/founder profile, testimonials, FAQ, contact, and the shared footer/quick contact controls.

## Current theme and settings behavior

- Site-wide light/dark and medical palette behavior is implemented in `asset/js/medical-theme.js` and `asset/medical-themes.css` using CSS variables and local storage keys such as `nbc_theme` and `nbc_user_medical_theme`.
- The administrator already has a platform appearance form in `admin.html` under the existing settings area. Its current preset/font/color/motion controls are loaded and saved by `asset/js/admin.js` through existing settings behavior. That flow is protected from this task; the new homepage design choice will use a separate frontend-only key and will not submit its values to the existing settings API.
- The homepage styling stack is primarily `asset/style.css`, `asset/home-apple.css`, `asset/home-slider.css`, and `asset/medical-themes.css`; global responsive rules are in `asset/responsive.css` and home-specific responsive rules are at the end of `asset/home-apple.css`.

## File classification

### Permitted frontend files for this task

- `index.html` — homepage markup and frontend stylesheet/script includes.
- `admin.html` — add the theme manager UI inside the existing settings area.
- New isolated frontend assets: `asset/homepage-designs.css` and `asset/js/homepage-designs.js`.
- `asset/js/main.js` only if required to expose existing homepage data/render hooks; avoid changing its business logic or data calls.
- `asset/responsive.css` only if needed for homepage responsive behavior; prefer the isolated new stylesheet instead.

### Strictly protected backend/API/authentication files

- Everything under `api/` and `auth/`, plus PHP server files in the project root, `.htaccess`, and existing authentication/session/authorization code.
- Existing route behavior, API payloads, server functions, course/payment/booking/exam logic, and all integrations.

### Strictly protected database and migration files

- `database/`, all SQL files, and all migration/setup/database scripts, including any root `*.sql` and PHP setup/fix/migration scripts.
- No database access or write operation is required for this frontend task.

### Shared files requiring special caution

- `asset/js/main.js`, `asset/style.css`, `asset/medical-theme.js`, `asset/medical-themes.css`, and `asset/responsive.css` are shared or affect cross-route behavior. Keep changes homepage-scoped and preserve existing routes, API calls, data rendering, and student/instructor/admin portal styling.
- `admin.html` and `asset/js/admin.js` contain shared administrator behavior. The manager will be an additive local-storage-only interface and must not alter existing settings submissions.

## Existing functionality to preserve

- Existing homepage navigation, search, cart, account actions, hero video, uploaded slider, course/book cards, booking and WhatsApp actions, centers, offers, instructor/founder content, testimonials, FAQ, contact form, language selection, and responsive/mobile navigation.
- Existing real data loading and empty states. The four visual systems will restyle/rearrange the existing sections and will not insert sample records or replace data.

## Implementation boundary

The theme manager will preview through a URL-scoped temporary choice and apply through a dedicated local-storage key. The active selection is browser/origin-local (not server-synced). The original visual system will remain available as the Medical Premium default/reset. No backend, auth, API, database, or migration files will be modified.
