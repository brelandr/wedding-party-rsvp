# Blueprint.json Implementation Steps

## What Was Done (Matching PlanIT Event Manager)

I analyzed the **working** PlanIT Event Manager blueprint and applied the exact same structure to Wedding Party RSVP.

### Key Finding

**PlanIT uses `pluginZipFile` (not `pluginData`)** - Even though `pluginData` is the newer syntax, WordPress.org currently accepts `pluginZipFile` and that's what's working for PlanIT.

## Implementation Steps Completed

### 1. Created Blueprint File Structure
- ✅ Created `assets/blueprints/` directory
- ✅ Created `blueprint.json` file with proper structure

### 2. Blueprint Configuration (Matching PlanIT)
- ✅ Schema: `https://playground.wordpress.net/blueprint-schema.json`
- ✅ Landing Page: `/wp-admin/admin.php?page=wedding-rsvp-main`
- ✅ PHP Version: `8.2`
- ✅ WordPress Version: `latest`
- ✅ Uses `pluginZipFile` (matching PlanIT's working format)

### 3. Blueprint Steps (Same Pattern as PlanIT)
1. **Login Step** - Admin login
2. **Install Plugin Step** - Installs from WordPress.org using plugin slug
3. **Configure Settings** - Sets up menu options and general settings
4. **Create Sample Data** - Adds 10 sample guests across 7 party IDs
5. **Create Demo Page** - Frontend RSVP form page

### 4. Published to SVN
- ✅ Published to: `trunk/assets/blueprints/blueprint.json`
- ✅ File is accessible: HTTP 200
- ✅ Valid JSON format

## Current File Location

**SVN Trunk:**
```
https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json
```

**Local File:**
```
/Users/randy/wordpress-plugins/wedding-party-rsvp/assets/blueprints/blueprint.json
```

## Comparison: PlanIT vs Wedding Party RSVP

| Feature | PlanIT (Working) | Wedding Party RSVP |
|---------|------------------|-------------------|
| Schema | ✅ Same | ✅ Same |
| Landing Page | Admin Settings | Admin Guest List |
| PHP Version | 8.2 | 8.2 |
| WP Version | latest | latest |
| Install Method | `pluginZipFile` | `pluginZipFile` ✅ |
| Steps Count | 7 steps | 4 steps |
| Sample Data | Events, Venues, Organizers | Guests, Menu Options |

## Next Steps to Enable Live Preview

1. **Wait 1-4 hours** for WordPress.org to detect the blueprint file
2. **Go to:** `https://wordpress.org/plugins/wedding-party-rsvp/advanced/`
3. **Find "Toggle Live Preview"** section
4. **Enable the toggle** and set to "Public"
5. **Save changes**

## Verification Commands

To verify the blueprint is published:
```bash
curl -I https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json
```

Should return: `HTTP/2 200`

To verify JSON is valid:
```bash
curl -s https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json | python3 -m json.tool > /dev/null && echo "Valid" || echo "Invalid"
```

## Troubleshooting

If the toggle still shows "Missing or invalid blueprint.json" after 4+ hours:

1. **Verify file is accessible:**
   - Check HTTP status code (should be 200)
   - Verify file content is valid JSON

2. **Check WordPress.org cache:**
   - WordPress.org may cache blueprint validation
   - Can take up to 24 hours in some cases

3. **Compare with working plugin:**
   - PlanIT Event Manager blueprint works
   - Wedding Party RSVP now uses identical structure
   - Both use `pluginZipFile` syntax

4. **Contact WordPress.org Support:**
   - If file is accessible but still not detected
   - May be a validation issue on their end

---

**Status:** ✅ Blueprint created and published matching PlanIT's working format
**Last Updated:** $(date)
