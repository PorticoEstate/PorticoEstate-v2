# Location Hierarchy Analyzer - Validation Report
**Date:** January 23, 2026  
**Validator:** Code Review against analyser_prompt.txt rules

## Executive Summary
The LocationHierarchyAnalyzer implementation has **3 critical issues** that violate the documented rules, potentially causing incorrect data organization and unnecessary duplication of loc2/loc3 entries.

---

## Issues Found

### 🔴 Critical Issue #1: Not Reusing Existing loc2/loc3 Entries

**Rule (analyser_prompt.txt line 85):**
> "if the correct loc2 or loc3 is not referenced correctly in loc4 level, but they can be found in the database: use it before adding a new one"

**Current Behavior:**
- The code assigns loc2 values sequentially ('01', '02', '03'...) based purely on the order bygningsnr appears in the array
- It does NOT check if a loc2 with the matching bygningsnr already exists in fm_location2
- It does NOT parse existing loc2_name to find matching bygningsnr

**Impact:**
- Creates duplicate loc2 entries for the same bygningsnr
- Moves loc4 entries unnecessarily when they could reference an existing correct loc2
- Violates the principle of reusing existing hierarchical structure

**Example:**
```
Existing: loc2='05' with loc2_name='Bygningsnr:300756443'
Current: Creates new loc2='01' for bygningsnr=300756443 instead of reusing '05'
```

**Fix Required:**
1. Load existing loc2/loc3 entries with their names
2. Parse bygningsnr from loc2_name (format: "Bygningsnr:XXXXX")
3. Build a reverse map: bygningsnr → existing loc2
4. When assigning loc2, first check if one exists for this bygningsnr
5. Only create new sequential loc2 if no match found

---

### 🔴 Critical Issue #2: No Street Pattern Matching for loc3

**Rule (analyser_prompt.txt line 85):**
> "if the correct loc2 or loc3 is not referenced correctly in loc4 level, but they can be found in the database: use it before adding a new one"

**Current Behavior:**
- Similar to loc2, assigns loc3 sequentially without checking existing entries
- Does not parse loc3_name to find matching street_id/street_number combinations

**Fix Required:**
1. Load existing loc3 entries with names
2. Extract street info from loc3_name or query for matches
3. Build map: (loc2, street_id, street_number) → existing loc3
4. Reuse before creating new

---

### 🟡 Medium Issue #3: Static Cache Prevents Per-Analysis Statement Generation

**Location:** `createUpdateStatements()` method, line 256

**Problem:**
```php
private function createUpdateStatements()
{
    static $sqlStatements = [];
    if (!empty($sqlStatements))
    {
        return $sqlStatements;  // Returns cached from first call
    }
    // ...
}
```

**Impact:**
- When analyzing individual loc1 values, update statements from the first analysis are returned for all subsequent analyses
- Only works correctly when analyzing all loc1 values together

**Fix Required:**
Remove static caching or reset it in `resetState()`:
```php
private function createUpdateStatements()
{
    $sqlStatements = [];  // Remove 'static'
    // ... rest of code
}
```

---

### 🟢 Minor Issue #4: Missing Return Statement

**Location:** `createUpdateStatements()`, line 286

**Problem:**
The method doesn't explicitly return $sqlStatements at the end, relying on the assignment to `$this->sqlStatements['update_location_from_mapping']`.

**Fix:**
```php
$this->sqlStatements['update_location_from_mapping'] = $sqlStatements;
return $sqlStatements;  // Add explicit return
```

---

## Correctly Implemented Rules

✅ **Synthetic bygningsnr** - Properly assigns negative synthetic IDs for missing bygningsnr  
✅ **Sequential loc2/loc3 numbering** - Uses '01', '02', '03'... format  
✅ **Location_mapping tracking** - Records all loc4 moves  
✅ **Per-loc1 analysis** - Supports filtering by loc1  
✅ **ON CONFLICT handling** - Uses DO NOTHING to avoid duplicate inserts  
✅ **4-level hierarchy understanding** - Correctly models Property→Building→Entrance→Apartment

---

## Priority Recommendations

### Priority 1: Fix loc2/loc3 Reuse Logic (Critical)
Implement proper lookup of existing loc2/loc3 entries before creating new ones.

**Estimated effort:** 3-4 hours  
**Files affected:** LocationHierarchyAnalyzer.php (lines 40-110)

### Priority 2: Remove Static Caching (Medium)
Fix the static variable issue in `createUpdateStatements()`.

**Estimated effort:** 15 minutes  
**Files affected:** LocationHierarchyAnalyzer.php (line 256)

### Priority 3: Add Validation Tests (High)
Create unit tests to validate rules from analyser_prompt.txt are followed.

**Estimated effort:** 4-6 hours  
**Files affected:** tests/controllers/ (new test file)

---

## Proposed Implementation Plan

### Step 1: Add bygningsnr extraction from loc2_name
```php
private function extractBygningsnrFromLoc2Name($loc2_name)
{
    if (preg_match('/Bygningsnr:(\d+|synthetic_-\d+)/', $loc2_name, $matches))
    {
        return $matches[1];
    }
    return null;
}
```

### Step 2: Build reverse maps in loadData()
```php
private function loadData($filterLoc1 = null)
{
    // ... existing code ...
    
    // NEW: Build bygningsnr → loc2 map
    $this->bygningsnrToLoc2Map = [];
    $sql = "SELECT loc1, loc2, loc2_name FROM fm_location2";
    if ($filterLoc1) $sql .= " WHERE loc1 = '{$filterLoc1}'";
    $this->db->query($sql, __LINE__, __FILE__);
    while ($this->db->next_record())
    {
        $bygningsnr = $this->extractBygningsnrFromLoc2Name($this->db->f('loc2_name'));
        if ($bygningsnr)
        {
            $loc1 = $this->db->f('loc1');
            $loc2 = $this->db->f('loc2');
            $this->bygningsnrToLoc2Map[$loc1][$bygningsnr] = $loc2;
        }
    }
}
```

### Step 3: Modify loc2 assignment logic
```php
foreach ($bygningsnrIndex as $loc1 => $bygningsnrs)
{
    $nextLoc2Num = 1;
    foreach ($bygningsnrs as $bygningsnr)
    {
        // Check if existing loc2 matches this bygningsnr
        if (isset($this->bygningsnrToLoc2Map[$loc1][$bygningsnr]))
        {
            $loc2 = $this->bygningsnrToLoc2Map[$loc1][$bygningsnr];
        }
        else
        {
            // Find next available loc2 number
            do {
                $loc2 = str_pad($nextLoc2Num, 2, '0', STR_PAD_LEFT);
                $nextLoc2Num++;
            } while (isset($this->loc2Refs[$loc1][$loc2]));
        }
        $requiredLoc2[$loc1][$bygningsnr] = $loc2;
    }
}
```

---

## Testing Recommendations

### Test Case 1: Reuse Existing loc2
**Setup:**
- fm_location2 has: loc1='5000', loc2='03', loc2_name='Bygningsnr:123456'
- fm_location4 has entries with bygningsnr=123456 currently at loc2='01'

**Expected:**
- Analysis should identify loc2='03' as correct for bygningsnr=123456
- Should generate UPDATE to move loc4 from loc2='01' to loc2='03'
- Should NOT generate INSERT for new loc2

### Test Case 2: Create New loc2 When No Match
**Setup:**
- fm_location2 has: loc2='01', '02', '03'
- New bygningsnr=999999 appears in fm_location4

**Expected:**
- Analysis should create new loc2='04' for bygningsnr=999999
- Should generate INSERT statement

### Test Case 3: Multiple loc1 Analysis
**Setup:**
- Analyze loc1='5000' then loc1='5001'

**Expected:**
- Update statements should be generated for both
- Second analysis should not use cached results from first

---

## Conclusion

The current implementation follows **most** of the rules but has critical gaps in reusing existing hierarchical structures. The fixes are straightforward but require careful implementation to avoid breaking existing functionality.

**Next Steps:**
1. Review this report with the team
2. Approve the proposed implementation plan
3. Implement fixes in a feature branch
4. Add comprehensive tests
5. Deploy to staging for validation
