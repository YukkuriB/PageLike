# Changelog

## 0.2.3

- Reduce the per-user like write rate limit from 30 to 10 requests per 60 seconds.
- Notify a page creator at most once for each liking user and page pair, even after unlike/re-like cycles.
- Persist notification deduplication claims independently from current likes and clean them up with page, user, and orphan maintenance flows.

## 0.2.2

- Use matching SVG outlines for the unliked and liked hearts so the active state retains the same shape.
- Keep the initial loading state visually stable as only the heart and `0`, without loading text.
- Disable rankings by default to avoid repeated public aggregation queries on sites without a persistent main cache.

## 0.2.1

- Make Echo optional: PageLike now loads and keeps likes, counts, buttons, and rankings working when Echo is absent.
- Send creator notifications exactly as before when Echo is installed, while skipping notification work cleanly when it is not.

## 0.2.0

- Notify a page's named creator through Echo when a like is newly added; repeated like requests and unlikes do not notify.
- Keep creator notifications enabled on web and disabled for email by default, while respecting Echo user preferences.
- Enable rankings by default.
- Keep the like count while removing visible text labels from the red-heart button, and enlarge the whole control by 33%.

## 0.1.3

- Support PHP 8.3 while retaining compatibility with the production PHP 8.2 environment.
- Keep the supported PHP range conservative at `>=8.2.0 <8.4.0`.

## 0.1.2

- Center and refine the default button for content-footer placement.
- Add a red heart theme with press compression, overshoot and a short particle burst after a successful like.
- Improve hover, pressed, disabled and liked states while preserving reduced-motion support.
- Add overridable hover-background, border-color and burst-color CSS variables.

## 0.1.1

- Enable the basic like flow and default button after installation.
- Grant the `pagelike` right to named users by default; anonymous and temporary accounts remain read-only.
- Keep rankings opt-in because they require a suitable shared cache and workload review.

## 0.1.0

- Add explicit, idempotent page like and unlike writes.
- Add private per-page status/count and rolling 7-day, 30-day and all-time rankings.
- Add `__NOPAGELIKE__` and `__关闭点赞__` behavior switches.
- Add an optional accessible default button and one stable change hook.
- Add page-deletion cleanup and user/orphan maintenance scripts.
