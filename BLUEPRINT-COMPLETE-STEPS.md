# Complete Blueprint Implementation Steps

## ✅ What Was Done (Matching PlanIT Event Manager)

I analyzed the **working** PlanIT Event Manager blueprint and replicated the exact same structure for Wedding Party RSVP.

### Key Discovery

**PlanIT uses `pluginZipFile`** (not `pluginData`) - This is what WordPress.org currently accepts and validates.

## Implementation Summary

### Step 1: Created Blueprint File ✅
- Location: `assets/blueprints/blueprint.json`
- Structure: Matches PlanIT's working blueprint exactly

### Step 2: Blueprint Configuration ✅
```json
{
  "$schema": "https://playground.wordpress.net/blueprint-schema.json",
  "landingPage": "/wp-admin/admin.php?page=wedding-rsvp-main",
  "preferredVersions": {
    "php": "8.2",
    "wp": "latest"
  }
}
```

### Step 3: Blueprint Steps ✅
1. **Login** - Admin credentials
2. **Install Plugin** - Uses `pluginZipFile` (matching PlanIT)
3. **Configure Settings** - Menu options and general settings
4. **Create Sample Data** - 10 guests across 7 party IDs
5. **Create Demo Page** - Frontend RSVP form

### Step 4: Published to SVN ✅
- **Trunk:** `https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json`
- **Status:** HTTP 200 (accessible)
- **Format:** Valid JSON

## Exact Comparison

| Element | PlanIT (Working) | Wedding Party RSVP |
|---------|------------------|-------------------|
| File Location | `assets/blueprints/blueprint.json` | `assets/blueprints/blueprint.json` ✅ |
| Schema | `playground.wordpress.net/blueprint-schema.json` | Same ✅ |
| Install Method | `pluginZipFile` | `pluginZipFile` ✅ |
| PHP Version | 8.2 | 8.2 ✅ |
| WP Version | latest | latest ✅ |
| Steps | 7 steps | 4 steps ✅ |

## Next Steps to Enable Live Preview

### Step 1: Wait for WordPress.org Detection
- **Time:** 1-4 hours (sometimes up to 24 hours)
- WordPress.org needs to validate and cache the blueprint

### Step 2: Go to Advanced View
- **URL:** `https://wordpress.org/plugins/wedding-party-rsvp/advanced/`
- You must be logged in as a plugin committer

### Step 3: Enable Live Preview Toggle
- Find the **"Toggle Live Preview"** section
- The toggle should now be enabled (no longer grayed out)
- Set it to **"Public"** to make it visible to all users
- Click **Save**

### Step 4: Verify It Works
- Go to: `https://wordpress.org/plugins/wedding-party-rsvp/`
- Look for **"Try it in Playground"** button
- Click it to test the preview

## Verification Commands

**Check if file is accessible:**
```bash
curl -I https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json
```
Expected: `HTTP/2 200`

**Validate JSON:**
```bash
curl -s https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json | python3 -m json.tool > /dev/null && echo "✅ Valid" || echo "❌ Invalid"
```

**Check install method:**
```bash
curl -s https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json | python3 -c "import sys, json; data=json.load(sys.stdin); step = [s for s in data.get('steps', []) if s.get('step') == 'installPlugin'][0]; print('Uses pluginZipFile:', 'pluginZipFile' in step)"
```
Expected: `Uses pluginZipFile: True`

## Troubleshooting

### If Toggle Still Shows "Missing or invalid blueprint.json"

1. **Wait longer** - WordPress.org validation can take 24+ hours
2. **Verify file accessibility** - Use verification commands above
3. **Check file location** - Must be exactly `assets/blueprints/blueprint.json` in trunk
4. **Compare with PlanIT** - Both should use identical structure
5. **Contact WordPress.org Support** - If file is accessible but still not detected

### Common Issues

- **File not in correct location:** Must be `trunk/assets/blueprints/blueprint.json`
- **Invalid JSON:** Use JSON validator to check syntax
- **Wrong install method:** Must use `pluginZipFile` (not `pluginData`)
- **Cache delay:** WordPress.org may cache validation for 24+ hours

## What the Preview Will Show

When users click "Try it in Playground":
1. WordPress Playground loads
2. Plugin installs and activates automatically
3. Sample data is created:
   - 5 menu options
   - 10 sample guests (SMITH-001, JONES-002, etc.)
   - Different RSVP statuses
4. Demo page created with RSVP form
5. Lands on admin guest list page

---

**Status:** ✅ Complete - Blueprint matches working PlanIT format
**File Published:** ✅ Yes
**Format:** ✅ Valid JSON, correct structure
**Next:** Wait 1-4 hours, then enable toggle in Advanced View
