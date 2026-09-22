# Void Comments

Shared flat-file comments for Grav 2 with moderation, replies, rate limiting,
CAPTCHA and an Admin API panel.

## Installation

From the root of a Grav 2 installation:

```sh
bin/gpm install void-comments
```

The plugin requires the Grav `api`, `form` and `email` plugins. GPM will offer
to install declared dependencies when the plugin is installed from the Grav
repository.

## Development

Install the development dependencies and run the isolated checks with:

```sh
composer install
composer test
```

## Runtime dependencies

- Grav 2;
- Grav api, form and email plugins;
- the cap CAPTCHA provider supplied by the Grav Form installation.

## Site configuration

Configure the plugin in the site's own Grav configuration, not in this
repository. At minimum configure enabled, templates and moderator_subject.
Use VOID_COMMENTS_MODERATOR_TO for the moderator address when moderator_to is
not set locally.

Runtime data is stored under user-data://void-comments. It contains private
moderation data and must remain outside version control and public releases.

## Administration

Users with the Grav `api.super` permission can open the Commenti panel from the
Grav administration sidebar. The panel supports searching comments, filtering
by page, pagination, editing, moderation and deletion. Approved comments also
include a link to their public page.

For that link to jump directly to a comment, the consuming theme should render
each comment with an `id` such as `comment-{{ comment.id }}`.

## Theme contract

The theme can use these Twig variables:

- void_approved_comments;
- void_comment_reply_to;
- void_comments_enabled.

The replies enhancement expects a .comments container, an input named
data[parent_id], and the data-comment-reply, data-comment-reply-context,
data-comment-reply-author and data-comment-reply-cancel attributes.

## Security and privacy

Submissions are validated against the current route and an allow-list of page
templates. New comments are written to pending/, while the directory controls
moderation state. Email addresses and rate-limit material are runtime data and
must be protected according to the site's retention policy.

The default comment limit is three submissions per hour for each IP address and
each normalized email address. Both counters must allow a request, so changing
only the IP or only the email does not bypass the limit. The rate-limit file is
stored under the site's `user-data://void-comments/` directory and contains only
SHA-256-derived keys and timestamps. Files written by version 0.1.x, which used
an IP+email pair key, remain effective for that exact pair during the migration.
