# Changelog

## 1.2.2

- Fix `move` command failing in TYPO3 12 if source and target directory are the same
- Fix an issue where empty folders were not reliably deleted in `cleanup-files` command with `--removeEmptyParentFolder` option set
- Fix `cleanup-missing` command deleting files that are only marked as missing, but actually exist
- Refactor and improve tests

## 1.2.1

- Fix current backend user being logged out if commands are invoked via the backend scheduler module
- Remove composer.lock

## 1.2.0

- Add support for non-writable storages (thanks to Florian Schuhmann)
- Add check if log file directory exists to cleanup commands (thanks to Florian Schuhmann)

## 1.1.1

- Update composer.lock

## 1.1.0

- Add `cleanup-syslog` command

## 1.0.9

- Improve documentation
- Add check if storage is online to cleanup commands
- Add `--removeEmptyParentFolder` option to `cleanup-missing` command

## 1.0.8

- Add output to `consolidate-external-urls` command, if called with `-v` option
- Update dependencies in composer.lock

## 1.0.7

- Improve documentation

## 1.0.6

- Change `consolidate-external-urls` command output log file name to `var/log/housekeeper:consolidate-external-urls_{CURRENT_DATE}.log` (if executed with `--log` option)

## 1.0.5

- Improve log output of `consolidate-external-urls` command
- Fix duplicate result indexes overwriting each other in `consolidate-external-urls` command

## 1.0.4

- Code refactoring in `consolidate-external-urls` command

## 1.0.3

- Fix TCA type `link` not being in `consolidate-external-urls` command with `--all` option set

## 1.0.2

- Fix missing `use` statement

## 1.0.1

- Remove unnecessary requirement in composer.json
- Improve documentation

## 1.0.0

- Initial release
