---
name: scaffold-plugin
description: Generates the initial file structure for the booking-plugin WordPress plugin (main plugin file, readme.txt, includes/, assets/, package.json with @wordpress/scripts). Use when the project is empty or when asked to bootstrap/scaffold the plugin from scratch.
---

Create the initial structure for the `booking-plugin` WordPress plugin at the project root. Follow the stack decisions in CLAUDE.md: PHP, no Composer, `@wordpress/scripts` for JS/CSS build, slug/text-domain `booking-plugin`.

Only run this if the relevant files don't already exist — check first and skip anything already present rather than overwriting it.

Create:

1. **`booking-plugin.php`** — main plugin file with a standard WordPress plugin header comment block (Plugin Name: Booking Plugin, Text Domain: booking-plugin, etc.), an ABSPATH guard, and a minimal bootstrap that requires files from `includes/`.
2. **`readme.txt`** — standard WordPress.org-style plugin readme (Contributors, Tags, Requires at least, Tested up to, Stable tag, License, Description, Installation, Changelog sections). Leave version fields as placeholders since none are fixed yet.
3. **`includes/`** — empty directory (with a `.gitkeep` or a first class file if the user specifies functionality) for PHP classes/logic.
4. **`assets/`** — directory with `src/` (source JS/CSS) and `build/` (compiled output, gitignored) subdirectories for `@wordpress/scripts`.
5. **`package.json`** — with `@wordpress/scripts` as a devDependency and standard `start`/`build`/`format`/`lint:js`/`lint:css` scripts wired to `wp-scripts`.
6. **`.gitignore`** — ignore `node_modules/`, `assets/build/`, and common OS/editor files.

After scaffolding, tell the user what was created and that CLAUDE.md should be revisited once real functionality is added (build commands, testing setup, coding conventions).
