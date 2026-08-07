# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

`booking-plugin` (MachMedia) — a WordPress plugin, currently unstarted (no code yet). This file will need updating once real code, build tooling, and conventions exist.

## Intended stack

- **PHP**, following standard WordPress plugin conventions (hooks, shortcodes, custom post types as needed) — no minimum PHP/WP version fixed yet.
- **No Composer** — no PSR-4 autoloading or PHP dependency manager; use classic WordPress plugin file structure and `require`/`include`.
- **JS/CSS build via `@wordpress/scripts`** (`wp-scripts`) once frontend/admin assets exist — do not introduce a separate webpack/vite config.
- Plugin slug and text domain: `booking-plugin`.

## Notes

- This repo is not currently a git repository.
- Update this file as soon as the initial plugin structure, build commands, and coding conventions are established — do not leave it aspirational once real code lands.
