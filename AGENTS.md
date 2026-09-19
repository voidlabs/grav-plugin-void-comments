# Agent instructions

This repository contains the public standalone `void-comments` plugin for Grav
2.

Keep the `void-comments` slug and its flat-file storage contract stable. The
plugin requires Grav plus the `api`, `form` and `email` plugins. The consuming
site owns template lists, moderator configuration, themes, runtime data,
rate-limit files and comment records; none of those belong in this repository.

Keep public documentation focused on installing and using this plugin. Do not
add site-specific operational material or moderation data to the repository.

Before changing behavior, run the isolated test suite and PHP syntax checks.
Keep Composer metadata, continuous integration and the clean-Grav integration
fixture in sync with the supported Grav and PHP versions. The release checks
must cover CAPTCHA, moderation, replies, API permissions, notifications,
deletion and rollback.

Never commit private moderation records, email addresses, IP-derived data,
runtime storage or secrets.
