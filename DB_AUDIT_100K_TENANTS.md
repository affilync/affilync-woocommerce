# Affilync Database Audit: Production Readiness at 100k+ Tenants

**Date:** 2026-02-28
**Database:** `affilync_db` on Neon PostgreSQL (project: `empty-field-28153544`)
**Current Size:** 70 MB | **Total Rows:** ~6,930 | **Total Tables:** 522 (72 non-empty, 451 empty)

---

## 1. Schema Overview

| Metric | Value |
|--------|-------|
| Total tables | 522 |
| Non-empty tables | 72 |
| Empty tables | 451 (86% empty) |
| Total indexes | 2,860 |
| Total foreign keys | 612 |
| Views | 5 (3 custom + 2 pg_stat) |
| Materialized views | 1 (`mv_campaign_stats`) |
| Partitioned tables | 1 (`clicks_partitioned`, 30 monthly partitions) |
| Application-level shards | 7 (`links_shard_0..17`, sparse) |
| CHECK constraints | 50+ |
| updated_at triggers | 185 tables have them, 388 do NOT |
| RLS-enabled tables | 25 out of 522 |

**Top tables by row count:**

| Table | Approx Rows |
|-------|-------------|
| `session_tokens` | 2,189 |
| `usage_tracking` | 1,911 |
| `ai_conversation_messages` | 979 |
| `ai_conversations` | 386 |
| `users` | 212 |
| `notifications` | 192 |
| `affiliate_links` | 112 |
| `payment_intents` | 92 |

**Top tables by disk size:**

| Table | Total Size | Table Size | Index Size |
|-------|-----------|------------|------------|
| `session_tokens` | 4,128 kB | 2,200 kB | 1,888 kB |
| `social_posts` | 1,864 kB | 24 kB | 96 kB |
| `ai_conversation_messages` | 1,408 kB | 1,024 kB | 280 kB |
| `users` | 1,072 kB | 408 kB | 624 kB |

---

## 2. RLS & Tenancy Analysis

### Tables WITH RLS (25 of 522)

The 25 tables with RLS policies use a consistent pattern based on `app.current_tenant_id` with `app.current_tenant_bypass` escape hatch:

| Table | Policies | Commands | Tenant Column(s) |
|-------|----------|----------|-------------------|
| `affiliate_contracts` | 4 | SELECT, INSERT, UPDATE, DELETE | brand_id, affiliate_id |
| `affiliate_links` | 4 | SELECT, INSERT, UPDATE, DELETE | user_id |
| `affiliate_profiles` | 1 | ALL | user_id |
| `affiliate_programs` | 4 | SELECT, INSERT, UPDATE, DELETE | brand_id |
| `ai_conversations` | 4 | SELECT, INSERT, UPDATE, DELETE | user_id |
| `api_keys` | 4 | SELECT, INSERT, UPDATE, DELETE | user_id |
| `brand_products` | 4 | SELECT, INSERT, UPDATE, DELETE | brand_id |
| `campaign_affiliates` | 1 | ALL | brand_id, affiliate_id |
| `campaigns` | 4 | SELECT, INSERT, UPDATE, DELETE | brand_id |
| `clicks` | 4 | SELECT, INSERT, UPDATE, DELETE | link_id-based |
| `clicks_partitioned` | 4 | SELECT, INSERT, UPDATE, DELETE | link_id-based |
| `commissions` | 4 | SELECT, INSERT, UPDATE, DELETE | affiliate_id, brand_id |
| `conversions` | 4 | SELECT, INSERT, UPDATE, DELETE | affiliate_id |
| `credit_transactions` | 4 | SELECT, INSERT, UPDATE, DELETE | user_id |
| `customer_subscriptions` | 4 | SELECT, INSERT, UPDATE, DELETE | user_id |
| `invoices` | 4 | SELECT, INSERT, UPDATE, DELETE | brand_id |
| `payment_methods` | 4 | SELECT, INSERT, UPDATE, DELETE | user_id |
| `payments` | 4 | SELECT, INSERT, UPDATE, DELETE | user_id |
| `payout_settings` | 4 | SELECT, INSERT, UPDATE, DELETE | user_id |
| `payouts` | 4 | SELECT, INSERT, UPDATE, DELETE | affiliate_id |
| `refresh_tokens` | 1 | ALL | user_id |
| `session_tokens` | 1 | ALL | user_id |
| `stripe_connect_accounts` | 1 | ALL | user_id |
| `tax_information` | 1 | ALL | user_id |
| `users` | 1 | ALL | id |

### RLS Policy Pattern

All policies follow this structure:
```sql
-- Bypass for service-level access
COALESCE(current_setting('app.current_tenant_bypass', true), 'off') IN ('on', 'true', '1')
-- OR match tenant ID against user_id/brand_id/affiliate_id
OR (tenant_column::text = current_setting('app.current_tenant_id', true))
```

### CRITICAL: Tables WITHOUT RLS That Contain Tenant Data (~230 tables)

**230+ tables** have `user_id`, `brand_id`, or `affiliate_id` columns but NO RLS policies. Among the most security-critical:

**Financial tables without RLS (CRITICAL):**
- `affiliate_earnings` (user_id)
- `affiliate_commissions` (user_id)
- `affiliate_payouts` (affiliate_id)
- `affiliate_transactions` (affiliate_id)
- `affiliate_withdrawals` (affiliate_id)
- `payment_transactions` (user_id inferred)
- `payment_history`
- `commission_payouts` (affiliate_id, brand_id)
- `transactions` (user_id)
- `wallet_transactions`
- `billing_records` (user_id)
- `subscription_invoices` (user_id)

**PII tables without RLS (CRITICAL):**
- `affiliate_payment_methods` (financial credentials)
- `affiliate_tax_info` (SSN/TIN)
- `affiliate_w9s` (tax documents)
- `identity_verifications`
- `tax_documents`
- `tax_withholdings`
- `brand_tax_documents`

**Operational tables without RLS (HIGH):**
- `affiliate_analytics`, `affiliate_stats`, `affiliate_stats_daily`
- `notifications`, `notification_preferences`
- `support_tickets`, `support_ticket_messages`
- `media_files`, `media_assets`
- `scripts`, `generated_scripts`
- `storyboards`, `social_posts`
- `orders`, `order_items`

---

## 3. Index Coverage

### Summary
- **2,860 total indexes** across 522 tables
- **0 foreign keys missing indexes** -- all FK columns are indexed
- Heavy indexing on click partition tables (21-25 indexes per partition * 30 partitions = ~630 click indexes alone)

### Index Hotspots (Potential Over-Indexing)

| Table | Index Count | Concern |
|-------|-------------|---------|
| `clicks_y2025_m01` | 25 | Highest -- write amplification |
| `users` | 24 | Many indexes, but justified by query variety |
| `clicks` (legacy) | 23 | Redundant with partitioned clicks |
| `conversions` | 21 | High for table with only 12 rows |
| `notifications` | 18 | High for write-heavy table |
| `ai_user_memories` | 17 | Only 0 live rows |
| `campaigns` | 17 | Only 21 rows |
| `brands` | 16 | Only 5 rows |

### Scaling Concern: Click Partitions

Each of the 30 monthly click partitions carries ~21 indexes. At 100k tenants generating click volume, this means:
- **~630 indexes just for clicks** (current)
- Each INSERT triggers 21 index updates per partition
- Monthly partitions from Jul 2024 through Dec 2026 pre-created

---

## 4. Financial Column Safety

### Money Columns (amount, price, commission, balance, payout, revenue, cost)
| Data Type | Column Count |
|-----------|-------------|
| `numeric` | 260 |
| `double precision` | 74 (mostly rate/percentage columns) |
| `integer` | 32 |
| `varchar` | 30 |

### Truly Dangerous: Float for Money

Only **1 actual money column** uses `double precision`:
- `ai_audit_events.estimated_cost` -- LOW risk (informational, not transactional)

### Rate/Percentage Columns Using Float (7 financially significant)

| Table | Column | Risk |
|-------|--------|------|
| `payments` | `tax_rate` | **HIGH** -- used in tax calculations on real payments |
| `currency_rates` | `rate` | **HIGH** -- used in currency conversions for payouts |
| `brand_tax_withholdings` | `backup_withholding_rate` | **HIGH** -- tax withholding calculations |
| `brand_tax_withholdings` | `fatca_withholding_rate` | **HIGH** -- FATCA compliance |
| `brand_tax_withholdings` | `nra_withholding_rate` | **HIGH** -- NRA withholding |
| `tax_withholdings` | `withholding_rate` | **HIGH** -- tax calculation |
| `usage_quotas` | `overage_rate` | **MEDIUM** -- billing overage |

### CHECK Constraints (Good)

Financial integrity constraints are present on key tables:
- `campaigns`: `budget >= 0`, `spent <= budget`, `current_spend <= budget_amount`
- `payments`: `amount >= 0`, `tax_amount >= 0`, `transaction_fee >= 0`
- `payouts`: `amount >= 0`, `net_amount >= 0`, `processing_fee >= 0`
- `conversions`: `amount >= 0`
- `commissions`: `commission_amount >= 0`
- `marketplace_transactions`: `amount >= 0`, `total_amount >= 0`

---

## 5. Missing Foreign Key Indexes

**Result: NONE.** All 612 foreign key columns have corresponding indexes. This is excellent.

---

## 6. Scaling Risks for 100k Tenants

### CRITICAL Risks

**CRIT-01: RLS Coverage Gap -- 95% of Tenant Tables Unprotected**
- 25 of 522 tables (4.8%) have RLS
- 230+ tables with tenant columns (`user_id`/`brand_id`/`affiliate_id`) have NO RLS
- Includes financial tables (earnings, commissions, payouts, withdrawals)
- Includes PII tables (tax info, W9s, identity verifications, payment methods)
- At 100k tenants, any application-level auth bypass exposes ALL tenant data
- **Impact:** Complete cross-tenant data leakage if app-layer auth fails

**CRIT-02: 451 Empty Tables with Full Index Sets**
- 86% of tables have zero rows but carry indexes
- Many have 5-15 indexes each, contributing to the 2,860 total
- Each index consumes connections, memory, and planner time
- At 100k tenants, the catalog bloat alone will slow `pg_stat*` queries
- **Impact:** Connection memory waste, slow planning, DDL lock contention

**CRIT-03: 522 Tables in Single Schema -- Catalog Pressure**
- PostgreSQL catalog operations scale poorly beyond ~200 tables
- `pg_stat_user_tables`, autovacuum scheduling, and planner all degrade
- Neon's auto-scaling compounds this: cold-start must load catalog for 522 tables
- **Impact:** Cold-start latency, planning overhead, connection saturation

**CRIT-04: max_connections = 901, No Application-Level Pooling Visible**
- Neon provides 901 max_connections (generous for current load)
- At 100k tenants with concurrent requests, even with connection pooling, 901 may be insufficient
- `idle_in_transaction_session_timeout` = 30s is good
- `statement_timeout` = 30s is good
- No evidence of PgBouncer or Neon's built-in pooler configuration
- **Impact:** Connection exhaustion under load spikes

### HIGH Risks

**HIGH-01: 388 Tables Missing updated_at Triggers**
- Only 185 of 573 tables/partitions have `updated_at` triggers
- Missing on critical tables: `affiliate_payouts`, `affiliate_earnings`, `affiliate_commissions`, `payment_transactions`, `wallet_transactions`
- Makes cache invalidation, change tracking, and audit trails unreliable
- **Impact:** Stale caches, inability to detect data tampering, ETL breakage

**HIGH-02: Tax/Withholding Rates Using Float (7 columns)**
- `payments.tax_rate`, `currency_rates.rate`, and 5 tax withholding columns use `double precision`
- Float arithmetic: `0.1 + 0.2 != 0.3` in IEEE 754
- Tax calculations and currency conversions require deterministic precision
- At scale, rounding errors compound across millions of transactions
- **Impact:** Tax miscalculation, regulatory non-compliance, penny discrepancies in payouts

**HIGH-03: Click Partition Over-Indexing (21 indexes x 30 partitions)**
- 630 indexes just for click partitions
- Each click INSERT updates 21 index entries
- At 100k tenants generating click traffic: major write amplification
- Future partitions (2027+) need auto-creation mechanism
- **Impact:** Write throughput ceiling, storage bloat, autovacuum contention

**HIGH-04: Links Sharding is Incomplete and Sparse**
- 7 shard tables exist: `links_shard_0`, `links_shard_1`, `links_shard_3`, `links_shard_5`, `links_shard_7`, `links_shard_8`, `links_shard_17`
- Non-contiguous shard IDs (missing 2, 4, 6, 9-16, 18+)
- `links_meta` exists alongside `links` -- unclear routing
- **Impact:** Data distribution imbalance, query routing confusion

**HIGH-05: No Partitioning on High-Growth Non-Click Tables**
- `ai_conversation_messages` (979 rows, highest-growth table) -- not partitioned
- `usage_tracking` (1,911 rows) -- not partitioned
- `session_tokens` (2,189 rows) -- not partitioned
- `notifications` -- not partitioned
- At 100k tenants these could reach 100M+ rows
- **Impact:** Full table scans, vacuum overhead, index bloat

### MEDIUM Risks

**MED-01: RLS Bypass Pattern is Coarse-Grained**
- `app.current_tenant_bypass` allows ANY connection to skip ALL RLS
- Set at connection level, not per-query
- If any service misconfigures bypass, all tenants exposed
- **Recommendation:** Replace bypass with a dedicated admin role + `USING (true)` admin policy

**MED-02: Duplicate Audit Tables**
- `security_audit_log` (65 rows) AND `security_audit_logs` (78 rows) both exist
- Both have similar schemas and data
- **Recommendation:** Consolidate to single audit table

**MED-03: Materialized View Refresh Strategy Unknown**
- `mv_campaign_stats` exists (21 rows) but no evidence of scheduled refresh
- At 100k tenants, stale materialized views mislead dashboard analytics
- **Recommendation:** Add concurrent refresh in cron/Celery

**MED-04: No Soft-Delete on Click Partitions**
- Click partitions lack `deleted_at` column
- Once GDPR deletion requests come at scale, there's no way to soft-delete click data
- **Recommendation:** Add `deleted_at` or implement a DLQ pattern for click deletion

**MED-05: Backup/Archived Table in Production**
- `affiliate_payment_methods_backup_archived_20260224` is a backup table left in the public schema
- No RLS, no triggers
- **Recommendation:** Move to a separate schema or drop

### LOW Risks

**LOW-01: social_posts Has Outsized Disk Usage**
- 29 rows but 1,864 kB total -- likely TOAST bloat from large JSON/text columns
- Not a scaling issue but indicates schema design concern

**LOW-02: alembic_version Has 6 Rows**
- Should have exactly 1 row; 6 rows suggests migration branch issues
- **Recommendation:** Clean up to single head

---

## 7. Recommendations (Prioritized)

### P0 -- Must Fix Before 100k Tenants (Blocking)

| # | Finding | Action | Effort |
|---|---------|--------|--------|
| P0-01 | CRIT-01: RLS gap on financial tables | Enable RLS + add tenant policies on `affiliate_earnings`, `affiliate_commissions`, `affiliate_payouts`, `affiliate_transactions`, `affiliate_withdrawals`, `commission_payouts`, `transactions`, `wallet_transactions`, `billing_records`, `subscription_invoices` | Medium |
| P0-02 | CRIT-01: RLS gap on PII tables | Enable RLS on `affiliate_payment_methods`, `affiliate_tax_info`, `affiliate_w9s`, `identity_verifications`, `tax_documents`, `tax_withholdings`, `brand_tax_documents`, `brand_tax_withholdings` | Medium |
| P0-03 | HIGH-02: Float tax/rate columns | ALTER COLUMN to `NUMERIC(12,6)` on `payments.tax_rate`, `currency_rates.rate`, `tax_withholdings.withholding_rate`, and 3 `brand_tax_withholdings` rate columns, `usage_quotas.overage_rate` | Low |
| P0-04 | CRIT-04: Connection pooling | Enable Neon's built-in connection pooler OR deploy PgBouncer; set pool_size per service | Low |

### P1 -- Fix Within 30 Days

| # | Finding | Action | Effort |
|---|---------|--------|--------|
| P1-01 | HIGH-01: Missing updated_at triggers | Script to add `updated_at` trigger to all 388 tables missing it | Low |
| P1-02 | HIGH-05: Partition high-growth tables | Partition `ai_conversation_messages` by month, `session_tokens` by month, `usage_tracking` by month | Medium |
| P1-03 | HIGH-03: Click index reduction | Audit 21 click indexes per partition; drop redundant/unused indexes (target: 8-10 per partition) | Medium |
| P1-04 | CRIT-01: RLS on remaining operational tables | Batch-enable RLS on remaining 200+ tenant tables using automated migration script | High |
| P1-05 | MED-01: Replace bypass with admin role | Create `affilync_admin` role with unrestricted policies; remove `app.current_tenant_bypass` | Medium |

### P2 -- Fix Within 90 Days

| # | Finding | Action | Effort |
|---|---------|--------|--------|
| P2-01 | CRIT-02/03: Table consolidation | Audit 451 empty tables; drop unused tables, merge overlapping ones | High |
| P2-02 | HIGH-04: Links sharding cleanup | Either complete the sharding strategy (0-31 shards) or remove shards and use partitioning | Medium |
| P2-03 | MED-02: Consolidate audit tables | Merge `security_audit_log` + `security_audit_logs` into single table | Low |
| P2-04 | MED-03: Materialized view refresh | Add Celery beat task for `REFRESH MATERIALIZED VIEW CONCURRENTLY mv_campaign_stats` | Low |
| P2-05 | MED-05: Clean up backup table | Drop `affiliate_payment_methods_backup_archived_20260224` | Low |

### P3 -- Ongoing / Long-Term

| # | Finding | Action | Effort |
|---|---------|--------|--------|
| P3-01 | Future click partitions | Implement auto-partition creation for 2027+ months | Medium |
| P3-02 | Schema splitting | Move reference/config tables to `config` schema, audit tables to `audit` schema | High |
| P3-03 | Read replicas | At 100k tenants, analytics queries should hit read replicas (Neon branching) | Medium |
| P3-04 | LOW-02: Fix alembic_version | Clean up to single migration head | Low |

---

## Summary Scorecard

| Category | Grade | Notes |
|----------|-------|-------|
| **RLS / Tenancy** | **D** | Only 25/522 tables protected; financial/PII tables exposed |
| **Index Coverage** | **A-** | All FKs indexed; slight over-indexing on clicks/empty tables |
| **Financial Column Safety** | **B+** | 260 NUMERIC good; 7 float rate columns need migration |
| **FK Integrity** | **A** | 612 FKs, all indexed, CHECK constraints on key tables |
| **Partitioning** | **B** | Clicks well-partitioned; high-growth tables need partitioning |
| **Schema Hygiene** | **D** | 451 empty tables, duplicate audit tables, orphan backup |
| **Scaling Readiness** | **D+** | Connection config ok; schema bloat, RLS gaps, no pooler evidence |
| **Overall** | **C-** | Functional at current scale; major work needed for 100k tenants |

---

## Appendix: Database Configuration

| Parameter | Value | Assessment |
|-----------|-------|------------|
| `max_connections` | 901 | Adequate with pooler; insufficient without |
| `shared_buffers` | 230 MB | Low for 100k tenant load |
| `work_mem` | 4 MB | Standard; may need increase for complex analytics |
| `effective_cache_size` | 6.4 GB | Reasonable |
| `maintenance_work_mem` | 64 MB | May need increase for large VACUUM/INDEX operations |
| `statement_timeout` | 30s | Good |
| `idle_in_transaction_session_timeout` | 30s | Good |
| `max_locks_per_transaction` | 64 | May need increase with 522 tables + DDL migrations |
