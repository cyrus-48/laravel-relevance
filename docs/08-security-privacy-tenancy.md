# 8. Security, Multi-Tenancy & GDPR Privacy

Data privacy, auditability, and tenant isolation are first-class primitives in Laravel Relevance.

---

## 8.1 Multi-Tenancy (Fail-Closed)

In multi-tenant SaaS environments, accidental data leaks between tenants are unacceptable. Laravel Relevance implements **fail-closed scope injection**:

### Enabling Mandatory Tenancy

In `.env`:

```env
RELEVANCE_TENANCY_REQUIRED=true
```

Or in `config/relevance.php`:

```php
'tenancy' => [
    'required' => true,
    'field'    => 'tenant_id',
],
```

### Search Behavior Under Strict Tenancy

When `tenancy.required = true`:

1. Any search query executed without a tenant scope will immediately throw `MissingTenantScopeException`:
   ```php
   // Throws MissingTenantScopeException!
   Relevance::index('orders')->search('invoice #100')->get();
   ```

2. Providing a tenant ensures complete database and driver isolation:
   ```php
   // Safely isolated to tenant_99
   $results = Relevance::index('orders')
       ->tenant('tenant_99')
       ->search('invoice #100')
       ->get();
   ```

### Admin Bypass Escape Hatch

For background cron jobs, cross-tenant migrations, or compliance audits, you can explicitly bypass tenant scopes with an audited reason:

```php
$allOrders = Relevance::index('orders')
    ->withoutScopes('Global quarterly VAT reconciliation audit')
    ->search('invoice')
    ->get();
```

---

## 8.2 GDPR Subject Rights (Erasure & Portability)

Comply with GDPR Article 17 (Right to Erasure / "Right to be Forgotten") and Article 20 (Right to Data Portability).

### Permanent Subject Erasure

Permanently delete all analytics, interactions, search logs, and factors associated with a user:

```php
use Relevance\Facades\Relevance;

$report = Relevance::privacy()->erase($user);

echo "Subject: {$report->userRef}\n";
echo "Total Records Erased: {$report->totalDeleted}\n";
print_r($report->deletedCounts);
// [
//     'relevance_interactions' => 45,
//     'relevance_search_logs'  => 12,
//     'relevance_events'       => 0,
//     'relevance_user_factors' => 1,
// ]
```

When an erasure is completed, the `UserPrivacyErased` event is dispatched for audit logging.

### Artisan CLI Erasure

Admins and DPOs can erase subject data directly via Artisan:

```bash
php artisan relevance:privacy:erase user_12345 --force
```

### Data Portability Export

Export all user data for GDPR subject access requests (SAR):

```php
use Relevance\Facades\Relevance;

$data = Relevance::privacy()->export($user);

// Returns structured array:
// [
//     'interactions' => [...],
//     'search_logs'  => [...],
//     'user_factors' => [...],
// ]
```

---

## 8.3 Data Retention & Automated Pruning

Configure maximum data retention windows in `config/relevance.php`:

```php
'retention' => [
    'interactions_days' => 395, // 13 months
    'search_logs_days'  => 90,  // 90 days
    'impressions_days'  => 30,  // 30 days
],
```

### Pruning Command

Run the pruning command to delete records that have exceeded their retention thresholds:

```bash
php artisan relevance:prune
```

Override retention thresholds on demand:

```bash
php artisan relevance:prune --interactions-days=180 --search-logs-days=30
```

Schedule this in `app/Console/Kernel.php` or `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('relevance:prune')->daily();
```
