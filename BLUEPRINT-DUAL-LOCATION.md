# Blueprint.json - Dual Location Fix

## Issue
The blueprint.json file was only in `trunk/assets/blueprints/` but WordPress.org also needs it in the root `/assets/blueprints/` folder.

## Solution
✅ Published blueprint.json to **both locations**:

### Location 1: Root Assets Folder
```
https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/blueprints/blueprint.json
```
**Purpose:** WordPress.org looks here for the live preview feature

### Location 2: Trunk Assets Folder  
```
https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json
```
**Purpose:** Development version blueprint

## SVN Structure

```
wedding-party-rsvp/
├── assets/                    ← Root level (for WordPress.org detection)
│   └── blueprints/
│       └── blueprint.json     ← ✅ Published here
├── trunk/
│   ├── assets/
│   │   └── blueprints/
│   │       └── blueprint.json ← ✅ Also here
│   └── readme.txt
└── tags/
```

## Why Both Locations?

WordPress.org plugin repository structure:
- **Root `/assets/`** - Used by WordPress.org for plugin directory features (banner, icon, blueprint)
- **`/trunk/assets/`** - Development version assets

The live preview feature checks the root `/assets/blueprints/` folder first.

## Verification

### Check Root Location:
```bash
curl -I https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/blueprints/blueprint.json
```
Expected: `HTTP/2 200`

### Check Trunk Location:
```bash
curl -I https://plugins.svn.wordpress.org/wedding-party-rsvp/trunk/assets/blueprints/blueprint.json
```
Expected: `HTTP/2 200`

### Validate JSON:
```bash
curl -s https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/blueprints/blueprint.json | python3 -m json.tool > /dev/null && echo "✅ Valid" || echo "❌ Invalid"
```

## Status
✅ Blueprint.json published to root `/assets/blueprints/` folder
✅ Blueprint.json also in `/trunk/assets/blueprints/` folder
✅ Both files are identical and valid JSON
✅ WordPress.org should now detect the blueprint

---

**Last Updated:** $(date)
**Next Step:** Wait 1-4 hours, then check WordPress.org Advanced view for "Toggle Live Preview"
