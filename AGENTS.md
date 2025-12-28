# Repository Guidelines

## Project Structure & Module Organization
This repo contains a WordPress + WooCommerce plugin. Core plugin code lives in `eva-course-bookings/`.
- `eva-course-bookings/eva-course-bookings.php`: plugin bootstrap.
- `eva-course-bookings/includes/`: PHP classes for admin, frontend, Woo integration, and slot storage.
- `eva-course-bookings/assets/`: CSS/JS for admin and frontend.
- `eva-course-bookings/templates/`: reserved for future templates.
- `bin/`: Docker helper scripts for local development.

## Build, Test, and Development Commands
Use the Docker scripts for a local WordPress/WooCommerce environment:
```bash
./bin/up.sh    # start containers + auto-setup WordPress/WooCommerce
./bin/down.sh  # stop containers (keep data)
./bin/reset.sh # full reset (delete data)
./bin/logs.sh  # tail logs (add service name to filter)
```
Create a distributable plugin zip:
```bash
./build.sh
```
There is no automated test suite in this repo.

## Coding Style & Naming Conventions
Match the existing PHP and JS style in `eva-course-bookings/includes/` and `eva-course-bookings/assets/`.
- PHP: class-per-file, `class-*.php` naming, WordPress-style hooks and functions.
- JS/CSS: keep selectors and behavior scoped to plugin assets.
- Indentation follows the existing files (4 spaces in PHP, 2 spaces in JS/CSS).
Follow WordPress Coding Standards for PHP and the WordPress JS/CSS conventions unless a file already deviates. No formatter or linter is configured, so keep diffs minimal and consistent.

## Testing Guidelines
No unit/integration test tooling is present. Validate changes manually in the local Docker environment:
- Enable a product as a course.
- Create slots and ensure booking flow and checkout validation work.
- Check admin screens under WooCommerce menus.

## Architecture Overview
The plugin converts WooCommerce products into bookable courses with dated slots.
- Slot data is stored as post meta and accessed via the slot repository class.
- Frontend date/slot selection loads via AJAX to stay cache-friendly.
- Cart and checkout validation run in WooCommerce hooks for both Classic and Block checkout.
- Admin tools live under WooCommerce menus for slot management and bulk enablement.

## Commit & Pull Request Guidelines
Recent commits use short, lowercase, imperative messages (e.g., "fix get slots", "bump").
- Keep commits focused and descriptive without prefixes.
- In PRs, include a concise summary, testing notes, and screenshots for UI changes.
- Link related issues when applicable.

## Configuration & Environment Notes
Local development uses Docker Compose (see `docker-compose.yml`). Default credentials and URLs are listed in `README.md`. Avoid committing secrets or environment-specific data.
