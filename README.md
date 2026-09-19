# Void Comments

Shared flat-file comments for Grav 2 with moderation, replies, rate limiting,
CAPTCHA and an Admin API panel.

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
