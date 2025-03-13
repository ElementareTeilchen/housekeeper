# TYPO3 Housekeeper Commands

This extension provides various maintenance and housekeeping commands for TYPO3
installations.

All commands support the Symfony Console command line interface and can be
executed via the TYPO3 CLI.

#### Useful Symfony Options

| Option                  | Description              | Required |
|-------------------------|--------------------------|----------|
| `--no-interaction / -n` | disables interaction     | No       |
| `--quiet`               | suppresses output        | No       |
| `--silent`              | no interaction or output | No       |
| `-v / -vv / -vvv`       | Verbosity level          | No       |

## FAL Management Commands

### Move Command

Move or rename a file or folder. Works similar to the bash mv command.
This could also be done in the Backend, but there is the risk of timeouts on big move operations,
which would result in potential chaos.

```
typo3 housekeeper:move <source> <target>
```

#### Parameters

| Parameter        | Description                                     | Required |
|------------------|-------------------------------------------------|----------|
| `<source>`       | (Combined) identifier of the source folder/file | Yes      |
| `<target>`       | (Combined) identifier of the target folder/file | Yes      |

The combined identifier begins with the storage ID, see examples.
```<storageId>:<path>```. The default is ```1```.

#### Examples

```
# Move a folder to a new location
typo3 housekeeper:move old/path other-path/

# Rename a file
typo3 housekeeper:move old.pdf new.pdf

# Move and rename a folder
typo3 housekeeper:move 1:old/path 1:new-path

# Move and rename a file
typo3 housekeeper:move 1:old.pdf 2:other-path/new.pdf
```

#### Known limitations
- Folders can not be moved between storages. Single files do work.

## Cleanup Commands

These commands find either missing files or files whose identifier contain a specific string and
delete them. Files which are still in use are omitted and shown in the output.
This will also cleanup leftover references (like in sys_file_metadata).

### Files Cleanup Command

Cleanup files via a given identifier. All files matching the given string are deleted via the
system's API delete command.
Files which are still in use are omitted and shown in the output.

```
typo3 housekeeper:files-cleanup <identifier> [options]
```

#### Parameters

| Parameter      | Description                                                              | Required |
|----------------|--------------------------------------------------------------------------|----------|
| `<identifier>` | Identifiers containing this string should be deleted (e.g., ".jpg.webp") | Yes      |

#### Options

| Option              | Short | Description                               | Default       |
|---------------------|-------|-------------------------------------------|---------------|
| `--storageId`       | `-s`  | Storage id                                | 1 (fileadmin) |
| `--dry-run`         | -     | Only pretend deletion                     | false         |
| `--update-refindex` | -     | Automatically updates the reference index | false         |

### Missing Files Cleanup Command

Cleanup missing files. Files marked as missing are touched and marked as not
missing before deletion.

```
typo3 housekeeper:cleanup-missing [options]
```

#### Options

| Option              | Short | Description                               | Default       |
|---------------------|-------|-------------------------------------------|---------------|
| `--storageId`       | `-s`  | Storage id                                | 1 (fileadmin) |
| `--dry-run`         | -     | Only pretend deletion                     | false         |
| `--update-refindex` | -     | Automatically updates the reference index | false         |

### Consolidate External URLs Command

This command searches for external URLs in the database and converts them to
internal TYPO3 links when possible. It can find links (href) and images (src)
with a specific path or URL pattern and convert them to internal links (t3:
//file?uid= or t3://page?uid=) if the corresponding files or pages can be found.

```
typo3 housekeeper:consolidate-external-urls <site> [options]
```

#### Parameters

| Parameter | Description                | Required |
|-----------|----------------------------|----------|
| `<site>`  | The identifier of the site | Yes      |

#### Options

| Option      | Short | Description                                      | Default   |
|-------------|-------|--------------------------------------------------|-----------|
| `--table`   | `-t`  | The database table to search in                  | -         |
| `--field`   | `-f`  | The database field to search in                  | -         |
| `--domain`  | `-d`  | The domain to match (e.g., www.your-website.com) | -         |
| `--path`    | `-p`  | The path to match                                | fileadmin |
| `--all`     | `-a`  | Run on all fields defined in $GLOBALS['TCA']     | false     |
| `--log`     | `-l`  | Write output to log file                         | false     |
| `--dry-run` | -     | Only simulate changes without saving them        | false     |

The log file is written to `var/log/consolidateExternalUrlsCommand_DATE.log`.

#### Examples

```
# Convert all external links in the tt_content table, bodytext field
typo3 housekeeper:consolidate-external-urls sitename -t tt_content -f bodytext -d www.your-website.com

# Convert all external links in all relevant tables and fields
typo3 housekeeper:consolidate-external-urls sitename -a -d www.your-website.com

# Perform a dry run without making any changes
typo3 housekeeper:consolidate-external-urls sitename -t tt_content -f bodytext -d www.your-website.com --dry-run
