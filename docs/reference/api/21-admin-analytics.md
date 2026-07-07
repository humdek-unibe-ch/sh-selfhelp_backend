# Admin Analytics

Audience: Developers and integrators.
Status: active.
Applies to: SelfHelp2 backend `0.1.33+`.
Last verified: 2026-07-07.
Source of truth: `src/Controller/Api/V1/Admin/AdminAnalyticsController.php`, `src/Service/CMS/Admin/AdminAnalyticsService.php`, `src/Service/CMS/Frontend/PageViewTrackerService.php`, schemas under `config/schemas/api/v1/responses/admin/analytics/`, migration `Version20260706220759`.

Read-only aggregates that power the `/admin` dashboard: anonymous page-view
analytics (split by web/mobile) and a "today's operations" snapshot. Both
endpoints require the `admin.analytics.read` permission (seeded to the admin
role).

## Privacy model (how views are tracked)

`PageController` records one view per successfully delivered page through
`PageViewTrackerService` (live-preview requests are never counted; tracking
failures are logged and never break page delivery).

- Data lands in two daily-aggregate tables: `page_views`
  (`view_date` x `id_pages` x `platform` x `visitor_hash`, `views` counter)
  and `page_view_referrers` (`view_date` x `referrer_host`).
- The visitor key is a **daily rotating HMAC-SHA256**: authenticated users
  hash their user id, guests hash IP + User-Agent, both keyed with the app
  secret **and the UTC date**. No IP address, user agent, or user id is
  stored, and a hash cannot be correlated across days.
- "Unique visitors" therefore means **visitor-days**: multi-day ranges sum
  each day's uniques rather than deduplicating returning visitors.
- External referrers come from the validated `X-Referrer-Host` header the web
  client sends on first navigation; self-referrals and invalid hosts are
  dropped.
- Platform is the resolved page-access mode: `mobile` for mobile-app
  deliveries, everything else counts as `web`.

## GET /cms-api/v1/admin/analytics/summary

Page-view series, totals, top pages, and referrers for a date range.

Query parameters (all optional):

| Parameter | Values | Default |
|-----------|--------|---------|
| `from` | `Y-m-d` | `to` minus 29 days |
| `to` | `Y-m-d` | today (UTC) |
| `granularity` | `day` \| `month` | `day` |
| `platform` | `all` \| `web` \| `mobile` | `all` |

Response `data` (schema `responses/admin/analytics/analytics_summary.json`):

```json
{
  "range": { "from": "2026-06-07", "to": "2026-07-06", "granularity": "day", "platform": "all" },
  "totals": {
    "views": 22, "unique_visitors": 3,
    "web": { "views": 16, "unique_visitors": 2 },
    "mobile": { "views": 6, "unique_visitors": 1 }
  },
  "series": [
    { "period": "2026-07-06", "web_views": 16, "mobile_views": 6, "web_uniques": 2, "mobile_uniques": 1 }
  ],
  "top_pages": [
    { "page_id": 1, "keyword": "home", "url": "/home", "views": 9, "unique_visitors": 2 }
  ],
  "referrers": [
    { "host": "www.google.com", "views": 4 }
  ]
}
```

`series` periods are `Y-m-d` for `day` and `Y-m` for `month` granularity.
`top_pages` and `referrers` are capped at 10 rows. The referrer table is not
platform-split, so the `platform` filter applies to series/totals/top pages
only.

## GET /cms-api/v1/admin/analytics/today

Operations snapshot for the current UTC day.

Response `data` (schema `responses/admin/analytics/analytics_today.json`):

```json
{
  "date": "2026-07-06",
  "scheduled_jobs": {
    "due_today": 3,
    "executed_today": 2,
    "by_status": { "done": 2, "queued": 1 }
  },
  "data": {
    "entries_today": 5,
    "users_submitted_today": 2,
    "total_tables": 4,
    "total_rows": 120
  },
  "visits": {
    "views_today": 22,
    "unique_visitors_today": 3,
    "web_views_today": 16,
    "mobile_views_today": 6
  }
}
```

`scheduled_jobs.by_status` keys are the `scheduledJobsStatus` lookup codes of
jobs whose `date_to_be_executed` falls today; `executed_today` counts jobs
that reached `done` today.

## Permissions and routes

Both routes are DB-backed (`api_routes` rows `admin_analytics_summary` and
`admin_analytics_today`, seeded by migration `Version20260706220759`) and
linked to the `admin.analytics.read` permission. Users without the permission
receive `403`; the frontend hides the dashboard widgets via
`canReadAnalytics()`.

## Consumers

- Frontend `/admin` dashboard (`sh-selfhelp_frontend`
  `src/app/components/cms/dashboard/`, hooks `useAdminAnalytics.ts`).

## Tests

- `tests/Integration/CMS/Admin/AdminAnalyticsTest.php` — permission matrix,
  tracked-view aggregation, platform split, referrer validation.
- `tests/Integration/Migrations/Version20260706220759RoundTripTest.php` —
  migration up/down round trip.
