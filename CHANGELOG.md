# Changelog

Release notes, written by hand: add them under Unreleased, or under a planned release such as "## 1.5.0",
here or with .\script.ps1 changelog. A build of that version files them under its full version, and
CHANGELOG.json, the copy the app reads, is written from this file.

## Unreleased

### Added

- Every answer names the server version and channel that sent it.
- Production and staging share one Jira project and each server follows only its own issues.

## 1.0.0+2 - 2026-10-19

### Added

- Staging and production are checked before a build - check-targets says what either lacks and -Fix fills in what the deploy config and the server tell it; build-release offers that fix and -FixTarget applies it first
- Issues & Requests - each report is a bug or a feature request or feedback and becomes a Jira issue as it comes in (MIS can switch that off or send one by hand) and Jira's progress comes back by webhook and every fifteen minutes

### Other

- Release builds carry their version, as the estimator's do: dist/ikaika-portal-backend-<version>[-debug].zip with its .sha256.
- build-release tags and uploads when asked, as the estimator's does - -Tag, -Deploy, -DryRun - and -Clean, the Build release form's clean, removes the server's older backups and a failed deploy's leftovers.
- build-release -Gui opens the project estimator's Build release dialog.
- Releases and the build dialog name the backend IkaIka Portal Backend - as the estimator names its own
