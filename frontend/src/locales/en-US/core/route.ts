/**
 * Static route titles.
 *
 * Only routes declared statically on the frontend (sign-in, dashboard, profile, error pages).
 * Dynamic route titles come from the backend menu tree, already localized by the backend for
 * the current request locale (see `menu.php`), so the frontend does not duplicate them —
 * which is why there is no `menu` namespace here.
 */
export default {
  login: 'Sign in',
  dashboard: 'Dashboard',
  profile: 'Profile',
  forbidden: 'Access denied',
  notFound: 'Page not found',
  /** fallback title when a route has no title */
  unknown: 'Untitled page',
}
