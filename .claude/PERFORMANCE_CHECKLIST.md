# Performance Checklist for New Features

Use this checklist when implementing new features to ensure optimal performance.

## Pre-Development Phase

### Design Review
- [ ] **Feature scope defined** - Clear requirements and boundaries
- [ ] **Performance impact estimated** - How will this affect existing operations?
- [ ] **Data volume considered** - How does it scale with 100, 1000, 10000 products?
- [ ] **API calls estimated** - How many API calls will this make?
- [ ] **Database queries planned** - What queries are needed?
- [ ] **Caching strategy defined** - What data should be cached?
- [ ] **Background processing assessed** - Should this use the queue?

### Architecture Decisions
- [ ] **Batch operations planned** - Can operations be batched?
- [ ] **Async processing considered** - Should this run asynchronously?
- [ ] **Memory limits considered** - Will this fit in typical PHP memory limits?
- [ ] **Indexes planned** - What database indexes are needed?
- [ ] **Hooks/filters planned** - What extension points are needed?

---

## Development Phase

### Code Structure
- [ ] **Methods are small** - Each method < 50 lines
- [ ] **Single responsibility** - Each method does ONE thing
- [ ] **Type hints added** - All parameters and return types specified
- [ ] **Constants used** - No magic numbers
- [ ] **DRY principle** - No duplicate code
- [ ] **Helper methods extracted** - Complex logic broken down

### API Optimization
- [ ] **Batch API calls used** - Multiple items updated in single call
- [ ] **API calls minimized** - No redundant API calls
- [ ] **Retry logic implemented** - Exponential backoff for failures
- [ ] **Timeout configured** - Appropriate timeout values
- [ ] **Rate limiting considered** - Delays added if needed
- [ ] **Conditional requests** - If-Modified-Since headers used

### Database Optimization
- [ ] **Prepared statements used** - All queries use $wpdb->prepare()
- [ ] **Indexes added** - New queries have appropriate indexes
- [ ] **EXISTS instead of COUNT** - For existence checks
- [ ] **LIMIT clauses** - All queries limited appropriately
- [ ] **No N+1 queries** - Queries not in loops
- [ ] **Batch inserts/updates** - Multiple rows in single query

### Caching
- [ ] **Cache keys defined** - Clear, consistent naming
- [ ] **Cache expiration set** - Appropriate TTL values
- [ ] **Cache invalidation planned** - When to clear cache
- [ ] **Multi-layer caching** - Object cache + transients
- [ ] **Cache warming** - Pre-populate cache if needed
- [ ] **Cache size considered** - Won't exceed memory limits

### Memory Management
- [ ] **Large variables unset** - Free memory after use
- [ ] **Pagination implemented** - Process in batches
- [ ] **Object cache cleared** - Clear cache in long loops
- [ ] **Memory limits checked** - Works within PHP limits
- [ ] **Generators used** - For large datasets if applicable

### Logging & Monitoring
- [ ] **Performance tracking added** - Duration, memory, API calls logged
- [ ] **Appropriate log levels** - Debug, info, warning, error, critical
- [ ] **Structured logging** - Consistent log format
- [ ] **Buffer logging used** - For high-frequency logs
- [ ] **Error logging complete** - All exceptions logged
- [ ] **Success logging** - Important operations logged

---

## Testing Phase

### Unit Testing
- [ ] **Helper methods tested** - Individual components tested
- [ ] **Edge cases tested** - Empty arrays, null values, etc.
- [ ] **Type safety tested** - Invalid types handled
- [ ] **Error conditions tested** - Failures handled gracefully

### Integration Testing
- [ ] **Single product sync** - Works for one product
- [ ] **Batch sync tested** - Works for 10, 100, 1000 products
- [ ] **Variable products tested** - Handles variations correctly
- [ ] **Large images tested** - Handles large files
- [ ] **Network errors tested** - Handles API failures

### Performance Testing
- [ ] **Response time measured** - Acceptable for typical use
- [ ] **Memory usage profiled** - Stays within limits
- [ ] **API call count verified** - Matches expectations
- [ ] **Database queries counted** - No excessive queries
- [ ] **Cache hit rate checked** - Cache is effective

### Load Testing
- [ ] **Tested with 100 products** - Acceptable performance
- [ ] **Tested with 1000 products** - Acceptable performance
- [ ] **Tested with 10 variations** - Variable products handled
- [ ] **Concurrent operations tested** - Multiple syncs don't conflict
- [ ] **Peak hour simulation** - Works during high load

---

## Code Review Checklist

### Performance Review
- [ ] **No obvious bottlenecks** - Code reviewed for performance issues
- [ ] **Algorithms efficient** - O(n) vs O(n²) complexity checked
- [ ] **No premature optimization** - Optimizations are justified
- [ ] **Profiling data available** - Performance metrics documented

### Security Review
- [ ] **Nonce verification** - All forms have nonce checks
- [ ] **Capability checks** - User permissions verified
- [ ] **Input sanitized** - All input sanitized appropriately
- [ ] **Output escaped** - All output escaped
- [ ] **SQL injection safe** - Prepared statements used
- [ ] **XSS prevention** - No raw HTML output

### Code Quality
- [ ] **WordPress standards** - Follows WordPress coding standards
- [ ] **PHPDoc complete** - All methods documented
- [ ] **Variable names clear** - Self-documenting code
- [ ] **Error handling robust** - All errors caught and logged
- [ ] **Backward compatible** - Doesn't break existing features

---

## Deployment Checklist

### Pre-Deployment
- [ ] **Database migration tested** - Schema changes work
- [ ] **Indexes created** - New indexes added
- [ ] **Default settings added** - New options have defaults
- [ ] **Documentation updated** - README and inline docs current
- [ ] **CHANGELOG updated** - Changes documented
- [ ] **.claude.md updated** - Context file current

### Post-Deployment Monitoring
- [ ] **Error logs checked** - No new errors
- [ ] **Performance metrics** - Response times acceptable
- [ ] **Memory usage** - No memory leaks
- [ ] **API rate limiting** - Not hitting limits
- [ ] **Database performance** - Queries still fast
- [ ] **User feedback** - Users report no issues

---

## Specific Feature Checklists

### Adding API Endpoints
- [ ] Batch operations supported
- [ ] Retry logic implemented
- [ ] Rate limiting handled
- [ ] Response cached
- [ ] Errors logged
- [ ] Timeout configured

### Adding Database Tables
- [ ] Indexes on foreign keys
- [ ] Indexes on frequently queried columns
- [ ] Proper column types (INT vs VARCHAR)
- [ ] Created_at/updated_at timestamps
- [ ] Cleanup/archival strategy
- [ ] Migration script tested

### Adding Cron Jobs
- [ ] Uses Action Scheduler (not WP-Cron)
- [ ] Batch size configurable
- [ ] Can be manually triggered
- [ ] Logs progress
- [ ] Handles failures gracefully
- [ ] Cleanup after completion

### Adding Admin Pages
- [ ] Pagination for large datasets
- [ ] Lazy loading for heavy content
- [ ] AJAX for long operations
- [ ] Progress indicators
- [ ] Bulk actions optimized
- [ ] Assets minified

### Adding Background Jobs
- [ ] Uses queue system
- [ ] Priority levels supported
- [ ] Duplicate detection
- [ ] Status tracking
- [ ] Error recovery
- [ ] Progress reporting

---

## Performance Metrics Targets

### Response Times
- **Simple product sync**: < 2 seconds
- **Variable product sync (10 variations)**: < 5 seconds
- **Batch sync (100 products)**: < 5 minutes
- **Admin page load**: < 2 seconds
- **AJAX requests**: < 1 second

### Resource Usage
- **Memory per product sync**: < 10 MB
- **Memory for batch (20 products)**: < 50 MB
- **Peak memory usage**: < 128 MB
- **Database queries per page**: < 20
- **API calls per product**: < 5

### Scalability
- **Products supported**: 10,000+
- **Stores supported**: 50+
- **Concurrent syncs**: 5+
- **Queue size**: 1,000+ items
- **Log file size**: Auto-rotation at 10 MB

---

## Common Performance Issues to Avoid

### Database
- ❌ Queries in loops
- ❌ No LIMIT clause
- ❌ Missing indexes
- ❌ SELECT *
- ❌ COUNT(*) for existence

### API Calls
- ❌ Individual calls in loop
- ❌ No caching
- ❌ No retry logic
- ❌ No timeout
- ❌ Fetching full objects when only ID needed

### Memory
- ❌ Loading all products at once
- ❌ Not unsetting variables
- ❌ Not clearing object cache
- ❌ Storing large data in memory
- ❌ No pagination

### Caching
- ❌ Not using cache
- ❌ Cache never expires
- ❌ Cache never invalidates
- ❌ Caching too much data
- ❌ Not warming cache

---

## Tools and Commands

### Profiling
```bash
# Enable XDebug profiling
php -d xdebug.mode=profile script.php

# Use WordPress Query Monitor plugin
# View at: /wp-admin/admin.php?page=query-monitor
```

### Memory Profiling
```php
// Add to code
echo 'Memory: ' . size_format(memory_get_usage()) . PHP_EOL;
echo 'Peak: ' . size_format(memory_get_peak_usage()) . PHP_EOL;
```

### Database Query Logging
```php
// In wp-config.php
define('SAVEQUERIES', true);

// View queries
global $wpdb;
print_r($wpdb->queries);
```

### Performance Monitoring
```php
// Use built-in performance monitor
$monitor = WC_Multi_Store_Performance_Monitor::instance();
$metrics = $monitor->get_metrics();
print_r($metrics);
```

---

## Performance Optimization Priority

### Priority 1: Critical
- API call reduction
- Database query optimization
- Memory leak fixes
- Cache implementation

### Priority 2: High
- Batch operations
- Background processing
- Code deduplication
- Method size reduction

### Priority 3: Medium
- Logging optimization
- Type hints
- Documentation
- Minor refactoring

### Priority 4: Low
- Code style
- Variable naming
- Comment improvements
- Non-critical refactoring

---

## When to Optimize

### ✅ Optimize Now
- Slow operations (> 5 seconds)
- High memory usage (> 128 MB)
- Many API calls (> 10 per operation)
- Database queries in loops
- No caching for repeated data

### ⚠️ Optimize Soon
- Moderate slowness (2-5 seconds)
- Growing memory usage
- Duplicate code
- Long methods (> 100 lines)
- Missing type hints

### ⏳ Optimize Later
- Already fast operations
- Code style issues
- Theoretical improvements
- Premature optimizations
- Nice-to-have refactoring

---

**Last Updated:** December 6, 2025
**Plugin Version:** 2.0.0

**Remember:** Measure first, optimize second. Don't optimize without data!
