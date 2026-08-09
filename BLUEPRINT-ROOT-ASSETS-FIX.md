# Blueprint.json - Root Assets Folder Fix

## Issue
The blueprint.json file was not accessible at:
```
https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/blueprints/blueprint.json
```

## Action Taken
✅ Committed blueprint.json to the root `/assets/blueprints/` folder in SVN.

## SVN Commands Executed

1. **Checked out repository:**
   ```bash
   svn checkout https://plugins.svn.wordpress.org/wedding-party-rsvp wedding-party-rsvp-svn
   ```

2. **Updated assets folder:**
   ```bash
   svn update assets
   ```

3. **Created blueprints folder and copied file:**
   ```bash
   mkdir -p assets/blueprints
   cp ../wedding-party-rsvp/assets/blueprints/blueprint.json assets/blueprints/blueprint.json
   ```

4. **Added to SVN:**
   ```bash
   svn add assets/blueprints assets/blueprints/blueprint.json
   ```

5. **Committed:**
   ```bash
   svn commit -m "Add blueprint.json to root assets/blueprints/ for WordPress.org live preview detection"
   ```

## Verification Steps

### Option 1: Check via Browser
Open in your browser:
```
https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/blueprints/blueprint.json
```

You should see the JSON content.

### Option 2: Check via Command Line
```bash
curl -I https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/blueprints/blueprint.json
```

Expected: `HTTP/2 200`

### Option 3: Validate JSON
```bash
curl -s https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/blueprints/blueprint.json | python3 -m json.tool
```

Should output formatted JSON without errors.

### Option 4: Check SVN Directory Listing
```bash
svn list https://plugins.svn.wordpress.org/wedding-party-rsvp/assets/ --username brelandr
```

You should see `blueprints/` in the listing.

## If File Still Not Visible

### Possible Reasons:
1. **Propagation Delay** - SVN changes can take 5-15 minutes to be visible via HTTP
2. **Cache** - Browser or CDN cache may need to clear
3. **Path Issue** - Verify you're checking the correct URL

### Manual Verification via SVN:
```bash
cd /tmp
svn checkout https://plugins.svn.wordpress.org/wedding-party-rsvp wedding-check --username brelandr --password svn_B9qnF5lzVBHp1KcKCAJsqbAwSSXco2SNe5cc1d1b
cd wedding-check
ls -la assets/blueprints/
cat assets/blueprints/blueprint.json | head -5
```

If the file exists in the SVN checkout, it's published correctly and just needs time to propagate.

## Expected SVN Structure

```
wedding-party-rsvp/
├── assets/                    ← Root level
│   └── blueprints/
│       └── blueprint.json     ← Should be here
├── trunk/
│   ├── assets/
│   │   └── blueprints/
│   │       └── blueprint.json ← Also here
│   └── readme.txt
└── tags/
```

## Status
✅ File committed to SVN root `/assets/blueprints/blueprint.json`
⏳ Waiting for SVN propagation (5-15 minutes)
⏳ WordPress.org detection (1-4 hours after propagation)

---

**Commit Message:** "Add blueprint.json to root assets/blueprints/ for WordPress.org live preview detection"
**Date:** $(date)
