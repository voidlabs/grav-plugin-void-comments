# 0.4.0
## 2026-09-23

1. [](#new)
   * Added pagination and route filtering to the Grav administration panel.
   * Added direct links from approved comments to their public page.
   * Added deterministic newest-first ordering and focus support for administrative comment lists.
   * Expanded isolated coverage for pagination, filtering and legacy storage behavior.
   * Added configurable moderator notification bodies with moderation and public-page URL placeholders.

# 0.2.0
## 2026-09-19

1. [](#improved)
   * Rate limiting now tracks IP and email independently, preventing rotation
     of either identity from bypassing the comment submission limit.
   * Existing 0.1.x pair-key entries remain effective for the exact pair while
     new attempts are stored in the independent counter format.
   * Corrupted rate-limit JSON fails closed and short writes are rejected.

# 0.1.0
## 2026-09-19

1. [](#new)
   * Initial public release of Void Comments for Grav 2.
   * Frontend comment and reply form with CAPTCHA validation.
   * Flat-file moderation storage with rate limiting and retention cleanup.
   * Admin API endpoints for moderation, editing and deletion.
