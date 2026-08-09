# Blueprint.json Publish Verification

## Issue
The `blueprints` folder was not visible at `https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/`

## Root Cause
The blueprint.json file was previously committed, but the `blueprints` folder structure may not have been properly created in SVN, or the file was in the wrong location.

## Fix Applied
✅ Created the proper folder structure and published blueprint.json:
1. Created `assets/blueprints/` directory in SVN
2. Added `blueprint.json` file to the folder
3. Committed to SVN

## Correct File Location
The blueprint.json file should be at:
```
trunk/assets/blueprints/blueprint.json
```

**Full SVN URL:**
```
https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json
```

## Verification Steps

### 1. Check if file is accessible:
```bash
curl -I https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json
```
Expected: `HTTP/2 200`

### 2. Verify JSON is valid:
```bash
curl -s https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json | python3 -m json.tool > /dev/null && echo "✅ Valid JSON" || echo "❌ Invalid"
```

### 3. Check file content:
```bash
curl -s https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json | python3 -c "import sys, json; data=json.load(sys.stdin); print('Schema:', data.get('$schema')); print('Uses pluginZipFile:', 'pluginZipFile' in [s for s in data.get('steps', []) if s.get('step') == 'installPlugin'][0])"
```

## SVN Directory Structure
```
wedding-party-rsvp/
├── trunk/
│   ├── assets/
│   │   ├── blueprints/
│   │   │   └── blueprint.json  ← Should be here
│   │   ├── icon-128x128.png
│   │   ├── icon-256x256.png
│   │   └── screenshot-*.png
│   └── readme.txt
└── tags/
```

## Important Notes

1. **Path is `trunk/assets/blueprints/blueprint.json`** - Not just `assets/blueprints/blueprint.json`
2. The `assets` folder at the root level (`/assets/`) is different from `trunk/assets/`
3. WordPress.org looks for blueprints in `trunk/assets/blueprints/blueprint.json`
4. The file must be committed to SVN trunk (not tags)

## Status
✅ Blueprint.json has been published to the correct location
✅ File structure matches PlanIT Event Manager (working example)
✅ Using `pluginZipFile` syntax (matching PlanIT)

---

**Last Updated:** $(date)
**File Location:** `trunk/assets/blueprints/blueprint.json`
