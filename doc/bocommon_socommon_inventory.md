# Caller Inventory Snapshot: bocommon/socommon

Generated: 2026-05-08
Scope: `src/modules/property/inc`

## Summary

- `CreateObject('property.bocommon')` call sites: 86
- `CreateObject('property.socommon')` call sites: 14

These counts include both `CreateObject(...)` and `createObject(...)` variants.

## Initial Risk Notes

High-risk migration areas to prioritize for parity checks:

- BO save and transaction flows (`bo*` classes)
- ACL-sensitive paths and lookups
- Hooks and cron usage (`hook_settings.inc.php`, `inc/cron/*`)

Lower-risk first-wave candidates:

- Read/list helper methods in `property_socommon`
- Pure mapping/format helpers with no transaction side effects

## bocommon Stateful Behavior Classification

Stateful fields observed in `property_bocommon`:

- Runtime config/context: `flags`, `accounts`, `socommon`, `join`, `left_join`, `like`
- Query-building and generated state: `type_id`, `uicols`, `cols_return`, `cols_extra`, `cols_return_lookup`
- Access-sensitive state: `acl_read`

High-risk methods (retain in adapter for now):

- `generate_sql`
- `collect_locationdata`
- `download`, `phpspreadsheet_out`, `xslx_out`, `csv_out`
- `get_menu`, `no_access`
- user/ACL list builders (`get_user_list*`)

Low-risk methods (good extraction candidates):

- `msgbox_data`
- `select_list`
- datatype translators (`translate_datatype*`)
- `add_leading_zero`
- `select2String`

## Completed Migration Batches

Batch 1 (socommon -> CommonDataHelper):

- `read_single_tenant`
- `select_part_of_town`
- `select_district_list`
- `get_lookup_entity`
- `get_start_entity`
- `get_max_location_level`
- `get_location_list`
- `get_order_type`

Batch 1b (socommon -> CommonDataHelper):

- `fm_cache`
- `reset_fm_cache`
- `reset_fm_cache_userlist`
- `check_location`
- `next_id`
- `increment_id`

Batch 1c (socommon -> CommonDataHelper):

- `unquote`
- `create_preferences`

Batch 1d (socommon -> CommonDataHelper):

- `new_db`

Batch 2 (bocommon -> CommonBusinessHelper):

- `msgbox_data`
- `select_list`
- `translate_datatype`
- `translate_datatype_insert`
- `translate_datatype_precision`
- `translate_datatype_format`
- `add_leading_zero`
- `select2String`

Batch 2b (bocommon -> CommonBusinessHelper):

- `select_multi_list`
- `select_multi_list_2`

Batch 2c (bocommon -> CommonBusinessHelper):

- `get_origin_link`
- `utf2ascii`
- `ascii2utf`
- `make_menu_date`
- `make_menu_user`
- `choose_select`

Batch 2d (bocommon -> CommonBusinessHelper):

- `check_perms`
- `check_perms2`
- `date_to_timestamp`
- `select_datatype`
- `select_nullable`

Batch 2e (bocommon thin-wrapper delegation via CommonBusinessHelper):

- `create_preferences`
- `get_lookup_entity`
- `get_start_entity`
- `read_single_tenant`
- `check_location`
- `fm_cache`
- `reset_fm_cache`
- `reset_fm_cache_userlist`
- `next_id`
- `increment_id`
- `new_db`
- `get_max_location_level`
- `get_location_list`
- `set_pending_action`

## Sample bocommon Callers

- `src/modules/property/inc/class.borequest.inc.php`
- `src/modules/property/inc/class.bodocument.inc.php`
- `src/modules/property/inc/class.boinvoice.inc.php`
- `src/modules/property/inc/class.soworkorder.inc.php`
- `src/modules/property/inc/class.uiworkorder.inc.php`

## Sample socommon Callers

- `src/modules/property/inc/class.bocommon.inc.php`
- `src/modules/property/inc/class.boasync.inc.php`
- `src/modules/property/inc/class.boevent.inc.php`
- `src/modules/property/inc/hook_settings.inc.php`
- `src/modules/property/inc/cron/default/oppdater_betalte_faktura_BK.php`

## Next Inventory Steps

1. Add full call-site list grouped by risk (high/medium/low).
2. Add explicit high-risk module list for parity testing order.
3. Select first caller migration batch from low-risk consumers.
