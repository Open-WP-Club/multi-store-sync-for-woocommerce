# Centralized Master Stock Synchronization

## Overview

This plugin uses a **centralized master** approach to prevent race conditions when synchronizing stock quantities across multiple WooCommerce stores.

## How It Works

### Architecture

```
┌─────────────┐        ┌─────────────┐        ┌─────────────┐
│  Store 2    │        │  Store 1    │        │  Store 3    │
│ (Secondary) │        │  (Master)   │        │ (Secondary) │
└─────┬───────┘        └──────┬──────┘        └─────┬───────┘
      │                       │                      │
      │  Webhook: Order for   │                      │
      │  3 units (SKU-123)    │                      │
      ├──────────────────────►│                      │
      │                       │                      │
      │                  Deducts 3                   │
      │                  (100 → 97)                  │
      │                       │                      │
      │                  Records update              │
      │                  with timestamp              │
      │                       │                      │
      │         Sync: stock_quantity=97              │
      │         (HIGH PRIORITY)                      │
      │◄──────────────────────┼─────────────────────►│
      │                       │                      │
      │     Updates to 97     │     Updates to 97    │
      └───────────────────────┴──────────────────────┘
```

### Key Principles

1. **Store 1 is the Source of Truth**: The master store maintains the canonical stock quantity
2. **Webhook-based Updates**: Secondary stores send order webhooks to the master
3. **Master Deducts and Syncs**: Master deducts stock and syncs the absolute quantity back
4. **High Priority Syncs**: Master store syncs use priority 1 for immediate processing
5. **Timestamp Tracking**: All stock updates are timestamped to detect race conditions

## Race Condition Prevention

### The Problem (Without Centralized Master)

```
Initial: Both stores have 100 units

T1: Store 2 gets order for 3 units
    → Store 2: 100 → 97 (local deduction)
    → Webhook sent: "remove 3"

T2: Store 1 receives webhook
    → Store 1: 100 → 97 (deducts 3)
    → Queues LOW priority sync: "set to 97"

T3: Store 2 gets ANOTHER order for 2 units (before sync!)
    → Store 2: 97 → 95 (local deduction)
    → Webhook sent: "remove 2"

T4: Delayed sync from T2 arrives
    → Store 2: 95 → 97 ❌ WRONG! (overwrites correct value)

T5: Store 1 receives second webhook
    → Store 1: 97 → 95
    → Queues sync: "set to 95"

T6: Sync arrives
    → Store 2: 97 → 95 ✓ (finally correct, but was wrong for 5+ minutes)
```

### The Solution (With Centralized Master)

```
Initial: Both stores have 100 units

T1: Store 2 gets order for 3 units
    → Store 2: 100 → 97 (local deduction)
    → Webhook sent: "remove 3"

T2: Store 1 receives webhook
    → Store 1: 100 → 97 (deducts 3)
    → Records timestamp & version
    → Queues HIGH priority sync: "set to 97" (priority=1)

T3: Store 2 gets ANOTHER order for 2 units
    → Store 2: 97 → 95 (local deduction)
    → Webhook sent: "remove 2"

T4: HIGH priority sync from T2 processed immediately
    → Store 2: 95 → 97 (temporary sync)

T5: Store 1 receives second webhook (quickly)
    → Store 1: 97 → 95 (deducts 2)
    → Records new timestamp & version
    → Queues HIGH priority sync: "set to 95" (priority=1)

T6: HIGH priority sync processed immediately
    → Store 2: 97 → 95 ✓ (correct, minimal time wrong)
```

## Configuration

### Master Store (Store 1)

1. **Enable Webhook Receiver**:
   - Navigate to WooCommerce → Multi-Store Sync → Webhook Settings
   - Enable "Order-Based Stock Sync"
   - Set webhook secret
   - Configure trigger statuses (processing, completed)

2. **Configure Stores**:
   - Add all secondary stores with their API credentials
   - Enable stock synchronization

### Secondary Stores (Store 2, 3, etc.)

1. **Create Webhooks** pointing to Master Store:
   - Topic: `order.updated`
   - Delivery URL: `https://store1.com/wp-json/wc-multi-store-sync/v1/webhook/order?store_url=https://store2.com`
   - Secret: Use the webhook secret from Master Store
   - Statuses: Match the trigger statuses configured on Master

2. **Disable Stock Sync to Other Stores** (Important!):
   - Secondary stores should NOT have the Multi-Store Sync plugin sending updates
   - Only the master should sync TO secondaries
   - Secondaries only send webhooks TO the master

## Implementation Details

### Stock Update Tracking

The plugin tracks stock updates using metadata:

- `_wc_mss_stock_last_update`: Timestamp of last update
- `_wc_mss_stock_update_source`: Source of update (webhook, master_sync, local)
- `_wc_mss_stock_sync_version`: Incrementing version number

### Priority System

Queue priorities ensure master syncs happen first:

- **Priority 1**: Master store stock syncs (from webhooks) - Immediate
- **Priority 2**: Price changes
- **Priority 3**: Manual product saves
- **Priority 10**: Regular automated syncs

### API Client

The sync engine includes metadata in all API calls to remote stores:
- Sync version number
- Timestamp
- Source indicator ("master")

## Benefits

1. **Eliminates Race Conditions**: Master store is always the source of truth
2. **Fast Propagation**: High priority ensures quick sync (within next 5-min cron)
3. **Audit Trail**: All stock changes are timestamped and logged
4. **Conflict Resolution**: Version numbers detect when syncs arrive out of order

## Limitations

1. **Single Master Only**: Only one store can be the master
2. **Cron Dependency**: Still requires cron to run every 5 minutes
3. **Network Delays**: Webhooks must reach master for stock to be accurate
4. **Local Deductions**: Secondary stores still show local stock changes immediately, but they will be overwritten by master sync

## Best Practices

1. **Monitor Webhook Delivery**: Ensure webhooks from secondary stores are reaching the master
2. **Enable Auto-Verification**: Use stock verification feature to detect discrepancies
3. **Set Up Logging**: Monitor sync logs to catch issues early
4. **Test Thoroughly**: Simulate concurrent orders before going live
5. **Consider WP-Cron Alternatives**: Use server cron for more reliable scheduling

## Troubleshooting

### Stocks Don't Match

- Check webhook delivery logs on secondary stores
- Verify webhook secret matches on all stores
- Check master store's webhook receiver logs
- Run stock verification manually

### Slow Sync

- Ensure WP-Cron is running every 5 minutes
- Check queue processing logs
- Verify high priority items are processed first
- Consider switching to server cron

### Webhooks Rejected

- Verify webhook secret is correct
- Check that secondary store URL is registered on master
- Review webhook receiver security logs
- Test with the test webhook endpoint first

## Migration from Previous Version

If you were using the plugin without centralized master:

1. **Designate Master**: Choose which store will be the master (usually main warehouse)
2. **Update Webhooks**: Point all secondary stores' webhooks to master only
3. **Disable Bi-directional Sync**: Remove webhooks that go between secondaries
4. **Clear Queue**: Clear all pending queue items before switching
5. **Monitor First Day**: Watch logs closely for any issues

## See Also

- [Webhook Configuration Guide](CONFIGURATION.md#webhook-settings)
- [Stock Verification](USER_MANUAL.md#stock-verification)
- [Troubleshooting](USER_MANUAL.md#troubleshooting)
