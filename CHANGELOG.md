# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.0.1] — 2026-08-14

### Added

- Initial scaffold cloned from `integration_forgejo_gitea`. App id `integration_inspect360`, PHP namespace `OCA\Inspect360`, targets Nextcloud 30–35 and PHP 8.3+. Repository layout, build tooling, dashboard, controller, service and settings structure, OAuth scaffolding and CI workflows in place. Inspect360-specific business logic pending — carried-over controllers and services still call the Forgejo/Gitea API surface and are placeholders to be replaced.
