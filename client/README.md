# DoliNews client

Command line client to submit the announcements and project sheets of a
Dolibarr module to [DoliNews](https://dolinews.com). PHP alone is enough:
the client has no Composer dependency.

This repository is a read-only copy of the `client/` directory of
[dolismartmaker/dolinews](https://github.com/dolismartmaker/dolinews).
Issues and pull requests go there.

## Installation

In the module, as a development dependency:

```sh
composer require --dev dolismartmaker/dolinews-client
```

In a CI job that should not install the whole `vendor/` of the module:

```sh
composer global require dolismartmaker/dolinews-client
export PATH="$(composer global config bin-dir --absolute --quiet):$PATH"
```

## Commands

```sh
php vendor/bin/publish-article.php --init docs/dolinews.md --module=.  # skeleton
php vendor/bin/publish-article.php docs/dolinews.md --check            # no network
php vendor/bin/publish-article.php docs/dolinews.md                    # submission
vendor/bin/check-annonce-version.sh docs/dolinews.md 1.4.0             # header matches the tag
php vendor/bin/draft-from-changelog.php ChangeLog.md docs/dolinews.md  # draft from a ChangeLog section
php vendor/bin/publish-project-sheet.php fiche.md                      # project sheet
```

Each PHP command prints its full usage with `--help`.

A token grants the right to submit, never to publish: whatever the client
sends lands in the review queue.

## Environment

- `DOLINEWS_API_TOKEN`: personal token of the contributor account, required
  to submit
- `DOLINEWS_API_BASE`: API root, to aim another instance
  (default `https://dolinews.com/api/v1`)

## CI examples

`examples/ci-gitlab.yml` and `examples/ci-github.yml` announce a release
when it is tagged; `examples/annonce.md` is a complete announcement file.

## License

AGPL-3.0-or-later, see `LICENSE`.
