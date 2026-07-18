# Changelog

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
