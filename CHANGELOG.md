# Changelog

## 1.0.0 - 2024-02-25

### First release
Synchronise your Office 365 calendar and contacts with Nextcloud Caldav and Carddav so you can view and edit everything from Nextcloud.

## 1.0.0 - 2024-02-26

### Bugfix
- Minor bugfix for image in appstore.

## 1.0.6 - 2024-03-14

### Bugfix
- Minor bugfixes and improvements.

## 1.0.8 - 2024-06-30
### Features
- Support for customers that use an airgapped Nextcloud installation with no internet access.

## 1.0.9 - 2024-07-18

### Bugfix
- Minor bugfixes and improvements.

## 1.0.10 - 2025-02-13

### Support
- Support for Nextcloud version 31

## 1.0.11 - 2025-04-03

### Fix
- Fix improving support for Nextcloud version 31

## 1.0.12 - 2025-04-08

### Fix
- Minor bugfixes and improvements.
## 2.1.0 - 2026-08-01

### Added
- One-time calendar clean-up in the consent flow for users of the legacy sync client: when legacy `X-SENDENT` data is detected in the sync calendar, the user is offered (once) to delete and re-create it. The re-created calendar keeps the same URI, display name, colour and timezone.

### Changed
- New app tokens are named `sendent-synchronization`. Users still holding a token with the old name are asked to go through the consent flow once more — this is what surfaces the calendar clean-up offer to existing users; completing the flow replaces the token. Retracting consent revokes tokens of both names. Deploy together with the new connector: until a user re-consents, the connector still syncs with their legacy token.
